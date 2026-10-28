package core

import (
	"io"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"sort"
	"strings"
	"syscall"
	"time"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/conv"
)

// Reads the server's log files for Administrator → System logs (App\Core\Support\SystemLogs), so errors can be
// checked without logging in to the server. Files: storage/logs/*.log (the application error log is error.log,
// written in PHP's error_log format by app.WriteErrorLog) and any extra readable files named in config 'logs.extra'
// (e.g. the web server's error log). Only the end of a big file is read.

// LogLevels: key → label, in order.
var LogLevels = [][2]string{{"error", "Errors"}, {"warning", "Warnings"}, {"notice", "Notices"}, {"info", "Other"}}

const logTail = 4 * 1024 * 1024 // read at most the last 4 MB of a file

// LogFile is one log file.
type LogFile struct {
	Name, Path string
	Size       int64
	Modified   int64
	Extra      bool
}

// LogFiles lists the log files, last changed first.
func LogFiles() []LogFile {
	var out []LogFile
	seen := map[string]int{}
	put := func(f LogFile) {
		if i, ok := seen[f.Name]; ok {
			out[i] = f
			return
		}
		seen[f.Name] = len(out)
		out = append(out, f)
	}
	files, _ := filepath.Glob(filepath.Join(app.BasePath, "storage", "logs", "*.log"))
	sort.Strings(files)
	for _, f := range files {
		if st, err := os.Stat(f); err == nil {
			put(LogFile{Name: filepath.Base(f), Path: f, Size: st.Size(), Modified: st.ModTime().Unix()})
		}
	}
	for _, f := range config.Strings("logs.extra") {
		st, err := os.Stat(f)
		if err != nil || !st.Mode().IsRegular() {
			continue
		}
		if fh, err := os.Open(f); err == nil {
			fh.Close()
			put(LogFile{Name: filepath.Base(f) + " (" + filepath.Base(filepath.Dir(f)) + ")", Path: f, Size: st.Size(), Modified: st.ModTime().Unix(), Extra: true})
		}
	}
	sort.SliceStable(out, func(i, j int) bool { return out[i].Modified > out[j].Modified })
	return out
}

var (
	lvError   = regexp.MustCompile(`(?i)PHP (Fatal|Parse|Recoverable fatal) error|Uncaught|Exception|\bError:|\[(error|crit|alert|emerg)\]|SQLSTATE|failed:`)
	lvWarning = regexp.MustCompile(`(?i)PHP Warning|\[warn(ing)?\]|warning`)
	lvNotice  = regexp.MustCompile(`(?i)PHP (Notice|Deprecated|Strict)|\[notice\]|deprecated`)
)

// LogLevel of one entry from its first line.
func LogLevel(head string) string {
	switch {
	case lvError.MatchString(head):
		return "error"
	case lvWarning.MatchString(head):
		return "warning"
	case lvNotice.MatchString(head):
		return "notice"
	}
	return "info"
}

// LogEntry is one entry; Time is "Y-m-d H:i:s" (or the raw stamp when unreadable, "" when none).
type LogEntry struct {
	Time, Level, Head, Body string
	HasTime                 bool
}

var (
	entryStart = regexp.MustCompile(`(?m)^\[[^\]\n]{6,60}\]\s`)
	stampRe    = regexp.MustCompile(`^\[([^\]\n]+)\]\s*`)
	zoneRe     = regexp.MustCompile(`\s+[A-Za-z_]+/[A-Za-z_/]+$`)
)

var stampLayouts = []string{"02-Jan-2006 15:04:05", "Mon Jan 02 15:04:05.000000 2006", "Mon Jan 02 15:04:05 2006", "2006/01/02 15:04:05", "02/Jan/2006:15:04:05 -0700"}

func stampTime(s string) (time.Time, bool) {
	for _, l := range stampLayouts {
		if t, err := time.ParseInLocation(l, s, conv.Loc); err == nil {
			return t, true
		}
	}
	return conv.ParseTime(s)
}

// LogEntries are the entries of a file, newest first. A PHP entry starts with "[19-Oct-2026 12:34:56 Asia/Bangkok]";
// following lines (stack traces) belong to it. Other formats (web server) split per line with their own [date].
func LogEntries(path string) []LogEntry {
	fh, err := os.Open(path)
	if err != nil {
		return nil
	}
	defer fh.Close()
	st, _ := fh.Stat()
	start := max(0, st.Size()-logTail)
	_, _ = fh.Seek(start, io.SeekStart)
	b, _ := io.ReadAll(fh)
	raw := string(b)
	if start > 0 {
		if nl := strings.IndexByte(raw, '\n'); nl >= 0 {
			raw = raw[nl+1:] // drop the cut first line
		}
	}
	var parts []string
	last := 0
	for _, m := range entryStart.FindAllStringIndex(raw, -1) {
		parts = append(parts, raw[last:m[0]])
		last = m[0]
	}
	parts = append(parts, raw[last:])
	var out []LogEntry
	for _, p := range parts {
		p = strings.TrimRight(p, " \t\n\r\x00\x0B")
		if p == "" {
			continue
		}
		e := LogEntry{}
		if m := stampRe.FindStringSubmatch(p); m != nil {
			e.HasTime = true
			if t, ok := stampTime(zoneRe.ReplaceAllString(m[1], "")); ok {
				e.Time = conv.Date("Y-m-d H:i:s", t)
			} else {
				e.Time = m[1]
			}
			p = p[len(m[0]):]
		}
		lines := strings.SplitN(p, "\n", 2)
		// an unexpected error caught by the app starts with "[ref …] METHOD /path": the exception is on the next line
		isRef := strings.HasPrefix(lines[0], "[ref ")
		head := lines[0]
		if isRef && len(lines) > 1 {
			head = strings.SplitN(strings.TrimLeft(lines[1], "\n"), "\n", 2)[0] + "  ·  " + lines[0]
		}
		e.Level = LogLevel(lines[0])
		if isRef {
			e.Level = "error"
		}
		e.Head = conv.Substr(head, 0, 500)
		e.Body = p
		out = append(out, e)
	}
	for i, j := 0, len(out)-1; i < j; i, j = i+1, j-1 {
		out[i], out[j] = out[j], out[i]
	}
	return out
}

var refCode = regexp.MustCompile(`^[A-Z0-9]{6,12}$`)

// LogByRef is one entry by its reference code (written for every unexpected error: "[ref ABC123]").
func LogByRef(ref string) *LogEntry {
	if !refCode.MatchString(ref) {
		return nil
	}
	for _, e := range LogEntries(filepath.Join(app.BasePath, "storage", "logs", "error.log")) {
		if strings.Contains(e.Body, "[ref "+ref+"]") {
			e := e
			return &e
		}
	}
	return nil
}

// ServerInfo: settings of this server that matter when something goes wrong (PHP's phpInfo() list, for the Go server).
func ServerInfo() [][2]string {
	free := "—"
	var fs syscall.Statfs_t
	if syscall.Statfs(filepath.Join(app.BasePath, "storage"), &fs) == nil {
		free = FilesSize(int64(fs.Bavail) * int64(fs.Bsize))
	}
	rel, _ := os.ReadFile("/proc/sys/kernel/osrelease")
	var ms runtime.MemStats
	runtime.ReadMemStats(&ms)
	osName := strings.ToUpper(runtime.GOOS[:1]) + runtime.GOOS[1:]
	return [][2]string{
		{"Go", runtime.Version() + " (" + runtime.GOARCH + ")"}, {"Server", "acme (Go net/http)"}, {"OS", strings.TrimSpace(osName + " " + string(rel))},
		{"Memory in use", FilesSize(int64(ms.Alloc))}, {"Goroutines", conv.Str(runtime.NumGoroutine())}, {"CPUs", conv.Str(runtime.NumCPU())},
		{"Upload limit", "64 MB"}, {"Free disk (storage)", free}, {"Time zone", conv.Loc.String()},
		{"Error log", filepath.Join(app.BasePath, "storage", "logs", "error.log")},
	}
}
