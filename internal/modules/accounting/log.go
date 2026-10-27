package accounting

import (
	"bytes"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"sort"
	"strings"
	"sync"
	"time"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/php"
)

/*
Accounting log (Support\Log) — plain text files, never the database.
One file per day: storage/logs/accounting/YYYY-MM-DD.log, one JSON object per line:

	{"t":"2026-10-06 10:00:01.123","level":"info","ch":"erp","run":"abc","msg":"…","ctx":{…}}

Channels: erp (API calls), sync (sync runs / rows), inet (generate, send, fetch), pdf, app (uncaught errors).
The viewer reads a file from a byte offset, so "live" is just asking again for what is new.
*/

var logLevels = map[string]int{"debug": 0, "info": 1, "warn": 2, "error": 3}

const logKeepDays = 30

var logSecret = regexp.MustCompile(`(?i)pass|secret|token|authorization|session|cookie|key`)

var (
	logMu  sync.Mutex
	logRun string // a "run" groups the lines of one action (one Generate click)
)

var runIDRe = regexp.MustCompile(`^[A-Za-z0-9]{6,32}$`)

func logSetRun(id string) {
	logMu.Lock()
	defer logMu.Unlock()
	if runIDRe.MatchString(id) {
		logRun = id
	} else {
		logRun = ""
	}
}

func logDir() string { return filepath.Join(app.BasePath, "storage", "logs", "accounting") }

func logFile(day string) string {
	if !ymdRe.MatchString(day) {
		day = php.Date("Y-m-d", php.Now())
	}
	return filepath.Join(logDir(), day+".log")
}

func logDebug(ch, msg string, ctx ...*config.Map) { logWrite("debug", ch, msg, ctxOf(ctx)) }
func logInfo(ch, msg string, ctx ...*config.Map)  { logWrite("info", ch, msg, ctxOf(ctx)) }
func logWarn(ch, msg string, ctx ...*config.Map)  { logWrite("warn", ch, msg, ctxOf(ctx)) }
func logError(ch, msg string, ctx ...*config.Map) { logWrite("error", ch, msg, ctxOf(ctx)) }

func ctxOf(c []*config.Map) *config.Map {
	if len(c) > 0 && c[0] != nil {
		return c[0]
	}
	return config.NewMap()
}

// logException is Log::exception: the message, the class and where it happened.
func logException(ch string, err error, what string, ctx ...*config.Map) {
	m := ctxOf(ctx)
	msg := err.Error()
	if what != "" {
		msg = what + ": " + msg
	}
	_, file, line, _ := runtime.Caller(1)
	m.Set("exception", fmt.Sprintf("%T", err))
	m.Set("at", strings.TrimPrefix(file, app.BasePath+"/")+":"+php.Str(line))
	logWrite("error", ch, msg, m)
}

func logWrite(level, ch, msg string, ctx *config.Map) {
	defer func() { _ = recover() }() // logging must never break the work
	dir := logDir()
	_ = os.MkdirAll(dir, 0o775)
	now := php.Now()
	line := config.NewMap()
	line.Set("t", now.Format("2006-01-02 15:04:05")+fmt.Sprintf(".%03d", now.Nanosecond()/1e6))
	line.Set("level", level)
	line.Set("ch", ch)
	logMu.Lock()
	run := logRun
	logMu.Unlock()
	if run != "" {
		line.Set("run", run)
	}
	line.Set("msg", php.Substr(msg, 0, 2000))
	if ctx != nil && ctx.Len() > 0 {
		line.Set("ctx", logClean(ctx))
	}
	file := logFile("")
	_, statErr := os.Stat(file)
	logMu.Lock()
	f, err := os.OpenFile(file, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o664)
	if err == nil {
		_, _ = f.WriteString(jsonEnc(line, false) + "\n")
		f.Close()
	}
	logMu.Unlock()
	if statErr != nil {
		logPrune()
	}
}

func logClean(m *config.Map) *config.Map {
	out := config.NewMap()
	for _, k := range m.Keys {
		v := m.Vals[k]
		switch x := v.(type) {
		case *config.Map:
			if logSecret.MatchString(k) {
				out.Set(k, "••••")
			} else {
				out.Set(k, logClean(x))
			}
			continue
		}
		if logSecret.MatchString(k) {
			out.Set(k, "••••")
		} else if s, ok := v.(string); ok && php.Len(s) > 1500 {
			out.Set(k, php.Substr(s, 0, 1500)+"… ("+php.Str(php.Len(s))+" chars)")
		} else {
			out.Set(k, v)
		}
	}
	return out
}

func logPrune() {
	files, _ := filepath.Glob(filepath.Join(logDir(), "*.log"))
	for _, f := range files {
		if st, err := os.Stat(f); err == nil && st.ModTime().Before(time.Now().Add(-logKeepDays*24*time.Hour)) {
			_ = os.Remove(f)
		}
	}
}

type logDay struct {
	Day   string
	Bytes int64
}

// logDays: days that have a file, newest first.
func logDays() []logDay {
	files, _ := filepath.Glob(filepath.Join(logDir(), "*.log"))
	var out []logDay
	for _, f := range files {
		st, err := os.Stat(f)
		if err != nil {
			continue
		}
		out = append(out, logDay{strings.TrimSuffix(filepath.Base(f), ".log"), st.Size()})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Day > out[j].Day })
	return out
}

// logRead: lines after a byte offset; after < 0 = the last tail lines. Filters: level, ch, run, q.
func logRead(day string, after int64, tail int, f map[string]string) app.M {
	file := logFile(day)
	st, err := os.Stat(file)
	if err != nil {
		return app.M{"entries": []any{}, "offset": 0, "size": 0}
	}
	size := st.Size()
	fh, err := os.Open(file)
	if err != nil {
		return app.M{"entries": []any{}, "offset": 0, "size": 0}
	}
	defer fh.Close()
	filtered := false
	for _, v := range f {
		if v != "" {
			filtered = true
		}
	}
	var buf []byte
	var start int64
	if after < 0 { // last lines: read backwards in blocks until enough
		want := max(1, tail)
		if filtered {
			want *= 20
		}
		pos := size
		for pos > 0 && bytes.Count(buf, []byte("\n")) <= want && len(buf) < 8_000_000 {
			step := min(int64(65536), pos)
			pos -= step
			chunk := make([]byte, step)
			_, _ = fh.ReadAt(chunk, pos)
			buf = append(chunk, buf...)
		}
		start = pos
		if pos > 0 {
			if nl := bytes.IndexByte(buf, '\n'); nl >= 0 {
				buf = buf[nl+1:]
				start = pos + int64(nl) + 1
			}
		}
	} else {
		start = min(after, size)
		if size > start {
			n := min(size-start, 4_000_000)
			buf = make([]byte, n)
			_, _ = fh.ReadAt(buf, start)
		}
	}
	end := bytes.LastIndexByte(buf, '\n') // never return half a line
	if end < 0 {
		off := start
		if after < 0 {
			off = size
		}
		return app.M{"entries": []any{}, "offset": off, "size": size}
	}
	offset := start + int64(end) + 1
	minLevel, ok := logLevels[php.OrStr(f["level"], "debug")]
	if !ok {
		minLevel = 0
	}
	q := strings.ToLower(strings.TrimSpace(f["q"]))
	entries := []any{}
	for _, line := range strings.Split(string(buf[:end]), "\n") {
		v, err := config.Decode([]byte(line))
		e, ok := v.(*config.Map)
		if err != nil || !ok {
			continue
		}
		lv, ok := logLevels[php.OrStr(mstr(e, "level"), "info")]
		if !ok {
			lv = 1
		}
		if lv < minLevel {
			continue
		}
		if f["ch"] != "" && !inStrs(mstr(e, "ch"), strings.Split(f["ch"], ",")) {
			continue
		}
		if f["run"] != "" && mstr(e, "run") != f["run"] {
			continue
		}
		if q != "" && !strings.Contains(strings.ToLower(line), q) {
			continue
		}
		entries = append(entries, e)
	}
	if after < 0 && len(entries) > tail {
		entries = entries[len(entries)-tail:]
	}
	return app.M{"entries": entries, "offset": offset, "size": size}
}

var _ = json.Marshal
