package machines

import (
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"

	"acme/internal/app"
	"acme/internal/php"
)

// The shop-floor entry (ScanController): scan a machine's QR code (or type its code) and land on the machine, ready to check.

func scanHome(c *app.Ctx) {
	if c.Can("machines_checklists", "view") {
		c.Redirect("/machines/checklists")
	}
	c.Redirect("/machines/scan")
}

func scanPage(c *app.Ctx) {
	u := c.MustUser()
	mine := mdb().Select("SELECT m.machine_code, m.machine_name, m.check_status, m.machine_status FROM machine m WHERE m.deleted_at IS NULL AND m.machine_status IN ("+activeIn()+") AND m.responsible_person_id = ? ORDER BY (m.check_status = 'PENDING') DESC, m.machine_code LIMIT 12", u.ID)
	for _, m := range mine {
		m["_url"] = c.URL("/machines/m/" + url.PathEscape(m.Str("machine_code")))
		m["_pill"] = pillH(c, m["check_status"], "")
	}
	c.View("machines/scan", app.M{"title": c.T("Scan QR code"), "mine": mine, "canCheck": c.Can("machines_checklists", "create")})
}

// scanLookup: what did the scanner read? → where to go.
func scanLookup(c *app.Ctx) {
	c.MustUser()
	code := codeFromScan(c.Query("text", ""))
	if code != "" {
		if m := machine(code); m != nil {
			c.JSONRaw(phpJSON(om("ok", true, "code", m["machine_code"], "url", c.URL("/machines/m/"+url.PathEscape(m.Str("machine_code")))), 0))
		}
	}
	c.JSONRaw(phpJSON(om("ok", false, "message", c.T("No machine with this code.")), 0), http.StatusNotFound)
}

// scanFind: type-ahead for the code box: any machine in use.
func scanFind(c *app.Ctx) {
	c.MustUser()
	q := strings.TrimSpace(c.Query("q", ""))
	if php.Len(q) < 2 {
		c.JSONRaw(phpJSON(om("rows", []any{}), 0))
	}
	like := "%" + q + "%"
	rows := mdb().Select("SELECT machine_code, machine_name, brand, model FROM machine WHERE deleted_at IS NULL AND machine_status IN ("+activeIn()+") AND (machine_code ILIKE ? OR machine_name ILIKE ? OR serial_number ILIKE ? OR machine_number ILIKE ?) ORDER BY machine_code LIMIT 12", like, like, like, like)
	out := []any{}
	for _, r := range rows {
		out = append(out, om("code", r["machine_code"], "name", r["machine_name"], "sub", strings.TrimSpace(r.Str("brand")+" "+r.Str("model")), "url", c.URL("/machines/m/"+url.PathEscape(r.Str("machine_code")))))
	}
	c.JSONRaw(phpJSON(om("rows", out), 0))
}

// scanMachine is the page a QR label opens.
func scanMachine(c *app.Ctx) {
	u := c.MustUser()
	m := machine(c.Param("code"))
	if m == nil {
		app.Abort(404, c.T("No machine with this code."))
	}
	act := isActive(m["machine_status"])
	var p *checkPlan
	if act {
		pl := plan(m, u, php.Now())
		p = &pl
	}
	n := names(m["responsible_person_id"], m["supervisor_id"], m["manager_id"])
	checks := mdb().Select("SELECT id, check_type, recheck, checklist_status, machine_status, user_name, created_at FROM checklist_record WHERE machine_code = ? ORDER BY created_at DESC LIMIT 5", m["machine_code"])
	for _, k := range checks {
		k["_pill"] = pillH(c, k["checklist_status"], "")
	}
	today := php.Today()
	var due []app.M
	for _, d := range []struct{ k, label, icon, sql string }{
		{"maintenance", c.T("Next maintenance"), "tools", "SELECT due_date FROM maintenance_record WHERE machine_code = ? AND actual_date IS NULL AND NOT is_canceled ORDER BY due_date LIMIT 1"},
		{"calibration", c.T("Next calibration"), "target", "SELECT due_date FROM calibration_record WHERE machine_code = ? AND certificate_date IS NULL AND NOT is_canceled ORDER BY due_date LIMIT 1"},
	} {
		if r := mdb().First(d.sql, m["machine_code"]); r != nil {
			due = append(due, app.M{"label": d.label, "icon": d.icon, "date": fmtDate(c, r["due_date"]), "late": r.Str("due_date") < today})
		}
	}
	sup := nameOr(n, m["supervisor_id"], nameOr(n, m["manager_id"], nil))
	facts := []app.M{
		{"l": c.T("Type"), "v": strings.Trim(m.Str("machine_group_name")+" / "+m.Str("machine_type_name"), " /")},
		{"l": c.T("Model"), "v": strings.TrimSpace(m.Str("brand") + " " + m.Str("model"))},
		{"l": c.T("Responsible"), "v": php.Str(nameOr(n, m["responsible_person_id"], nil))},
		{"l": c.T("Supervisor"), "v": php.Str(sup)},
	}
	c.View("machines/landing", app.M{"title": m["machine_code"], "m": m, "plan": p, "checks": checks, "due": due, "facts": facts, "active": act,
		"pills":    []any{pillH(c, m["machine_status"], ""), pillH(c, m["check_status"], "")},
		"overdue":  pillH(c, c.T("Overdue"), "bad"),
		"canCheck": p != nil && c.Can("machines_checklists", "create"), "canOpen": c.Can("machines_machines", "view") && canSee(u, m.Str("machine_code")),
		"checkURL":  c.URL("/machines/checklists/new?machine=" + url.PathEscape(m.Str("machine_code"))),
		"statusLow": strings.ToLower(m.Str("machine_status"))})
}

// fileShow opens a stored document for a signed-in person who may see any machine screen (FileController).
func fileShow(c *app.Ctx) {
	c.MustUser()
	f := c.Query("f", "")
	if !(c.Can("machines_machines", "view") || c.Can("machines_register", "view") || c.Can("machines_maintenance", "view") || c.Can("machines_calibration", "view") || c.Can("machines_checklists", "view")) {
		app.Abort(403)
	}
	path := filesPath(f)
	if path == "" {
		app.Abort(404, c.T("File not found."))
	}
	name := ""
	sources := []struct {
		table string
		cols  []string
	}{{"register_request", []string{"attachment", "work_instruction", "warranty_files"}}, {"machine", []string{"work_instruction", "warranty_files"}}, {"maintenance_record", []string{"attachment"}}, {"calibration_record", []string{"attachment"}}}
found:
	for _, s := range sources {
		for _, col := range s.cols {
			j := mdb().Scalar("SELECT "+col+" FROM "+s.table+" WHERE "+col+" LIKE ? LIMIT 1", `%"f":"`+f+`"%`)
			if !php.Empty(j) {
				if name = filesLabel(f, j); name != "" {
					break found
				}
			}
		}
	}
	if name == "" {
		name = f
	}
	data, err := os.ReadFile(path)
	if err != nil {
		app.Abort(404, c.T("File not found."))
	}
	mime := fileMime(data, path)
	disp := "attachment"
	if php.InList(mime, "application/pdf", "image/png", "image/jpeg", "image/webp", "text/plain") {
		disp = "inline"
	}
	h := c.W.Header()
	h.Set("Content-Length", php.Str(len(data)))
	h.Set("X-Content-Type-Options", "nosniff")
	h.Set("Content-Disposition", disp+`; filename="`+rawurlencode(name)+`"; filename*=UTF-8''`+rawurlencode(name))
	h.Set("Content-Type", mime)
	c.W.WriteHeader(http.StatusOK)
	_, _ = c.W.Write(data)
}

// fileMime is finfo's MIME type of a stored document.
func fileMime(data []byte, path string) string {
	ext := strings.ToLower(strings.TrimPrefix(filepath.Ext(path), "."))
	m := http.DetectContentType(data)
	if i := strings.IndexByte(m, ';'); i >= 0 {
		m = m[:i]
	}
	switch {
	case m == "application/zip" || m == "application/x-zip-compressed":
		switch ext {
		case "docx":
			return "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
		case "xlsx":
			return "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
		case "pptx":
			return "application/vnd.openxmlformats-officedocument.presentationml.presentation"
		}
		return "application/zip"
	case len(data) >= 8 && string(data[:8]) == "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1":
		switch ext {
		case "doc":
			return "application/msword"
		case "xls":
			return "application/vnd.ms-excel"
		case "ppt":
			return "application/vnd.ms-powerpoint"
		}
		return "application/CDFV2"
	case m == "text/plain" && ext == "csv":
		return "text/csv"
	case m == "text/plain" && len(data) == 0:
		return "application/x-empty"
	}
	return m
}

// rawurlencode is PHP's rawurlencode (RFC 3986: only A-Z a-z 0-9 - _ . ~ stay).
func rawurlencode(s string) string {
	var b strings.Builder
	for i := 0; i < len(s); i++ {
		ch := s[i]
		if (ch >= 'A' && ch <= 'Z') || (ch >= 'a' && ch <= 'z') || (ch >= '0' && ch <= '9') || ch == '-' || ch == '_' || ch == '.' || ch == '~' {
			b.WriteByte(ch)
		} else {
			b.WriteString("%" + strings.ToUpper(hex2(ch)))
		}
	}
	return b.String()
}

func hex2(b byte) string {
	const h = "0123456789abcdef"
	return string([]byte{h[b>>4], h[b&15]})
}
