package core

import (
	"math"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/php"
)

// Administrator → System logs (SystemLogController): the application error log and the other server logs, with full
// error details. Administrators only.

const logsPerPage = 40

func init() {
	signedIn(func(r app.Router) {
		r.Get("/system-logs", systemLogsIndex)
		r.Get("/system-logs/download", systemLogsDownload)
		r.Post("/system-logs/clear", systemLogsClear)
	})
}

func systemLogsGate(c *app.Ctx) {
	if !c.MustUser().IsAdmin() {
		app.Abort(403, c.T("Only an administrator can open the system logs."))
	}
}

// systemLogFile: the chosen file name and its info.
func systemLogFile(c *app.Ctx, files []LogFile) (string, LogFile) {
	name := c.Query("file", c.Input("file", "php-error.log"))
	find := func(n string) (LogFile, bool) {
		for _, f := range files {
			if f.Name == n {
				return f, true
			}
		}
		return LogFile{}, false
	}
	if f, ok := find(name); ok {
		return name, f
	}
	if f, ok := find("php-error.log"); ok {
		return "php-error.log", f
	}
	if len(files) > 0 {
		return files[0].Name, files[0]
	}
	return "", LogFile{}
}

var nonUpperAZ = regexp.MustCompile(`[^A-Za-z0-9._-]`)

func systemLogsIndex(c *app.Ctx) {
	systemLogsGate(c)
	files := LogFiles()
	name, f := systemLogFile(c, files)
	level := c.Query("level", "")
	q := php.Substr(c.Query("q", ""), 0, 200)
	ref := strings.ToUpper(c.Query("ref", ""))
	var all []LogEntry
	if f.Path != "" {
		all = LogEntries(f.Path)
	}
	since := php.Date("Y-m-d H:i:s", php.Unix(time.Now().Unix()-86400))
	counts := map[string]int{}
	for _, e := range all {
		if e.HasTime && e.Time >= since {
			counts[e.Level]++
		}
	}
	var rows []LogEntry
	lq := strings.ToLower(q)
	for _, e := range all {
		if (level == "" || e.Level == level) && (q == "" || strings.Contains(strings.ToLower(e.Body), lq)) && (ref == "" || strings.Contains(e.Body, "[ref "+ref+"]")) {
			rows = append(rows, e)
		}
	}
	page := max(1, php.Int(c.Query("page", "1")))
	pages := max(1, int(math.Ceil(float64(len(rows))/logsPerPage)))
	page = min(page, pages)
	link := func(over ...any) string {
		vals := []any{"file", name, "level", level, "q", q, "ref", ref, "page", ""}
		for i := 0; i+1 < len(over); i += 2 {
			for j := 0; j+1 < len(vals); j += 2 {
				if vals[j] == over[i] {
					vals[j+1] = over[i+1]
				}
			}
		}
		return c.URL("/system-logs", vals...)
	}
	type card struct {
		Key, Label, URL string
		Count           int
		On              bool
	}
	var cards []card
	for _, l := range LogLevels {
		toggle := l[0]
		if level == l[0] {
			toggle = ""
		}
		cards = append(cards, card{l[0], c.T(l[1]), link("level", toggle, "page", ""), counts[l[0]], level == l[0]})
	}
	type item struct {
		LogEntry
		Short, Long string
		Multi, Open bool
	}
	var list []item
	from := min(len(rows), (page-1)*logsPerPage)
	to := min(len(rows), from+logsPerPage)
	for i, e := range rows[from:to] {
		it := item{LogEntry: e, Multi: strings.Contains(e.Body, "\n"), Open: ref != "" && i == 0}
		if e.Time != "" {
			it.Short = app.FormatDate(c, e.Time, "j M H:i:s")
			it.Long = app.FormatDate(c, e.Time, "j M Y H:i:s")
		}
		list = append(list, it)
	}
	type fileItem struct {
		LogFile
		URL, Size string
		On        bool
	}
	var fl []fileItem
	for _, x := range files {
		fl = append(fl, fileItem{x, c.URL("/system-logs", "file", x.Name), FilesSize(x.Size), x.Name == name})
	}
	newer, older := "", ""
	if page > 1 {
		newer = link("page", page-1)
	}
	if page < pages {
		older = link("page", page+1)
	}
	c.View("admin/system-logs", app.M{"title": c.T("System logs"), "files": fl, "name": name, "file": f, "level": level, "q": q, "ref": ref,
		"rows": list, "total": len(rows), "all": len(all), "page": page, "pages": pages, "cards": cards, "php": ServerInfo(),
		"newer": newer, "older": older, "filtered": q != "" || ref != "" || level != ""})
}

func systemLogsDownload(c *app.Ctx) {
	systemLogsGate(c)
	name, f := systemLogFile(c, LogFiles())
	if f.Path == "" {
		app.Abort(404)
	}
	data, err := os.ReadFile(f.Path)
	if err != nil {
		app.Abort(404)
	}
	app.LogActivity(c, app.ActivityEntry{Action: "download", Type: "system_log", Label: name, Text: "Downloaded the log :name", Params: []any{"name", name}, Props: app.M{}, NoNotify: true})
	c.Download(nonUpperAZ.ReplaceAllString(filepath.Base(f.Path), "_"), "text/plain; charset=utf-8", data)
}

// systemLogsClear empties one of the system's own logs (storage/logs); server logs named in config are read-only.
func systemLogsClear(c *app.Ctx) {
	systemLogsGate(c)
	name, f := systemLogFile(c, LogFiles())
	if f.Path == "" || f.Extra {
		c.Back("error", c.T("This log cannot be cleared from here."))
	}
	_ = os.WriteFile(f.Path, nil, 0o664)
	app.LogActivity(c, app.ActivityEntry{Action: "deleted", Type: "system_log", Label: name, Text: "Cleared the log :name", Params: []any{"name", name}, Props: app.M{}, NoNotify: true})
	c.Flash("success", c.T("The log :name was cleared.", "name", name))
	c.Redirect("/system-logs?" + filesQuery("file", name))
}
