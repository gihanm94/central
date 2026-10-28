package sheets

import (
	"encoding/json"
	"strings"

	"acme/internal/app"
	"acme/internal/conv"
	"acme/internal/db"
	"acme/internal/google"
)

// Administrators (AdminController): every table of every module, which ones may go to Google Sheets and for whom;
// office hours and limits.

func gate(c *app.Ctx) {
	if !c.MustUser().IsAdmin() {
		app.Abort(403, c.T("Only an administrator can open this."))
	}
}

func adminTables(c *app.Ctx) {
	gate(c)
	dbs := databases(c)
	d := ""
	if len(dbs) > 0 {
		d = dbs[0].Key
	}
	if _, ok := dbName(c, c.Query("db")); ok {
		d = c.Query("db")
	}
	q := strings.ToLower(c.Query("q", ""))
	var list []*tableInfo
	var tErr any
	if err := app.Catch(func() { list = tables(d) }); err != nil {
		list = nil
		tErr = err.Error()
	}
	if q != "" {
		var kept []*tableInfo
		for _, t := range list {
			if strings.Contains(t.Name, q) {
				kept = append(kept, t)
			}
		}
		list = kept
	}
	entries := map[string]db.Row{}
	for _, e := range db.Select(`SELECT t.*, (SELECT count(*) FROM gsheet_table_access a WHERE a.table_id = t.id AND a.role_id IS NOT NULL) AS roles,
                (SELECT count(*) FROM gsheet_table_access a WHERE a.table_id = t.id AND a.user_id IS NOT NULL) AS people,
                (SELECT count(*) FROM gsheet_syncs s WHERE s.table_id = t.id) AS syncs FROM gsheet_tables t WHERE t.db = ?`, d) {
		entries[e.Str("table_name")] = e
	}
	type row struct {
		Name, Label, Rows, Who, Edit string
		Columns                      int
		Open                         bool
	}
	var rows []row
	for _, t := range list {
		r := row{Name: t.Name, Rows: conv.NF(t.Rows, 0), Columns: len(t.Columns), Who: "—", Edit: c.URL("/sheets/tables/edit", "db", d, "table", t.Name)}
		if en, ok := entries[t.Name]; ok {
			r.Label = tableLabel(en)
			r.Open = en.Bool("enabled")
			r.Who = c.T(":r roles · :p people · :s syncs", "r", en["roles"], "p", en["people"], "s", en["syncs"])
		} else {
			r.Label = conv.Ucfirst(strings.ReplaceAll(t.Name, "_", " "))
		}
		rows = append(rows, r)
	}
	c.View("sheets/admin-tables", app.M{"title": c.T("Tables for Google Sheets"), "dbs": dbs, "db": d, "q": q, "tables": rows, "error": tErr})
}

// requestTable: db, table and its info from the request, checked against the real tables.
func requestTable(c *app.Ctx) (string, string, *tableInfo) {
	d := c.Input("db", c.Query("db", ""))
	name := c.Input("table", c.Query("table", ""))
	if _, ok := dbName(c, d); !ok {
		app.Abort(404)
	}
	info := findTable(d, name)
	if info == nil {
		app.Abort(404, c.T("That table is not available."))
	}
	return d, name, info
}

func adminEditTable(c *app.Ctx) {
	gate(c)
	d, name, info := requestTable(c)
	t := entry(d, name)
	roles, users := access(t.Int("id"))
	hidden := hiddenColumns(t)
	type colView struct {
		Name, Type    string
		Secret, Shown bool
	}
	var cols []colView
	for _, col := range info.Columns {
		cols = append(cols, colView{col.Name, col.Type, secretColumn(col.Name), !conv.InList(col.Name, hidden...)})
	}
	type pick struct {
		ID        int
		Name, Sub string
		Checked   bool
	}
	var rv, uv []pick
	for _, r := range db.Select("SELECT id, name FROM roles ORDER BY level DESC, name") {
		rv = append(rv, pick{r.Int("id"), r.Str("name"), "", inList(r.Int("id"), roles)})
	}
	for _, p := range db.Select("SELECT u.id, u.name, u.email, r.name AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active ORDER BY u.name") {
		uv = append(uv, pick{p.Int("id"), p.Str("name"), p.Str("email") + " · " + p.Str("role"), inList(p.Int("id"), users)})
	}
	dn, _ := dbName(c, d)
	label := conv.Str(t["label"])
	if o, ok := c.Sess.Old("label"); ok {
		label = conv.Str(o)
	}
	c.View("sheets/admin-table", app.M{"title": tableLabel(t), "t": t, "db": d, "dbName": dn, "rowsAbout": conv.NF(info.Rows, 0), "columns": cols,
		"roles": rv, "users": uv, "open": t.Bool("enabled"), "label": label, "placeholder": conv.Ucfirst(strings.ReplaceAll(t.Str("table_name"), "_", " ")),
		"syncs": db.Select("SELECT s.id, s.sheet_tab, s.spreadsheet_name, s.active, u.name FROM gsheet_syncs s JOIN users u ON u.id = s.user_id WHERE s.table_id = ? ORDER BY u.name", t["id"])})
}

func adminSaveTable(c *app.Ctx) {
	gate(c)
	d, name, info := requestTable(c)
	t := entry(d, name)
	shown := map[string]bool{}
	for _, s := range c.Inputs("columns") {
		shown[s] = true
	}
	hidden := []string{}
	secrets := 0
	for _, col := range info.Columns {
		if secretColumn(col.Name) {
			secrets++
			continue
		}
		if !shown[col.Name] {
			hidden = append(hidden, col.Name)
		}
	}
	enabled := conv.Truthy(c.InputAny("enabled"))
	if enabled && len(hidden)+secrets >= len(info.Columns) {
		app.Invalid("columns", c.T("Leave at least one column to send."))
	}
	var roles, users []int
	for _, r := range c.Inputs("roles") {
		if n := conv.Int(r); !inList(n, roles) {
			roles = append(roles, n)
		}
	}
	for _, r := range c.Inputs("users") {
		if n := conv.Int(r); !inList(n, users) {
			users = append(users, n)
		}
	}
	hj, _ := json.Marshal(hidden)
	var label any
	if l := conv.Substr(strings.TrimSpace(conv.Str(c.InputAny("label"))), 0, 120); l != "" {
		label = l
	}
	db.Core.Transaction(func(tx db.Q) {
		tx.Update("gsheet_tables", db.Cols{{Name: "label", Value: label}, {Name: "enabled", Value: enabled}, {Name: "hidden_columns", Value: string(hj)},
			{Name: "updated_by", Value: c.MustUser().ID}, {Name: "updated_at", Value: conv.NowString()}}, db.Cols{{Name: "id", Value: t["id"]}})
		tx.Exec("DELETE FROM gsheet_table_access WHERE table_id = ?", t["id"])
		for _, r := range roles {
			tx.Exec("INSERT INTO gsheet_table_access (table_id, role_id) SELECT ?, id FROM roles WHERE id = ?", t["id"], r)
		}
		for _, uid := range users {
			tx.Exec("INSERT INTO gsheet_table_access (table_id, user_id) SELECT ?, id FROM users WHERE id = ?", t["id"], uid)
		}
	})
	text := "Closed for Google Sheets"
	if enabled {
		text = "Opened for Google Sheets"
	}
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "sheet table", ID: t.Int("id"), Label: d + "." + name, Text: text, Props: app.M{}, NoNotify: true, Module: "sheets"})
	c.Flash("success", c.T("Saved. People who lost access stop syncing on their next run."))
	c.Redirect(c.URL("/sheets/tables", "db", d))
}

func adminSettings(c *app.Ctx) {
	gate(c)
	o := office()
	var days []int
	if old, ok := c.Sess.Old("office_days"); ok {
		for _, d := range app.InStrings(old) {
			days = append(days, conv.Int(d))
		}
	} else {
		days = o.Days
	}
	type dayOpt struct {
		N       int
		Label   string
		Checked bool
	}
	var dopts []dayOpt
	for n := 1; n <= 7; n++ {
		dopts = append(dopts, dayOpt{n, c.T(dayNames[n]), inList(n, days)})
	}
	c.View("sheets/admin-settings", app.M{"title": c.T("Google Sheets settings"), "office": o, "days": dopts, "maxRows": maxRows(),
		"configured": google.Configured(), "redirect": google.RedirectURI(c)})
}

func adminSaveSettings(c *app.Ctx) {
	gate(c)
	errs := app.NewErrors()
	start, end := conv.Str(c.InputAny("office_start")), conv.Str(c.InputAny("office_end"))
	if !hmRe.MatchString(start) {
		errs.Add("office_start", c.T("Enter a time like 08:30."))
	}
	if !hmRe.MatchString(end) {
		errs.Add("office_end", c.T("Enter a time like 08:30."))
	}
	if errs.Len() == 0 && start >= end {
		errs.Add("office_end", c.T("The end must be after the start."))
	}
	var days []string
	for _, d := range c.Inputs("office_days") {
		if n := conv.Int(d); n >= 1 && n <= 7 {
			days = append(days, conv.Str(n))
		}
	}
	mx := conv.Int(c.InputAny("max_rows"))
	if mx < 100 || mx > 1000000 {
		errs.Add("max_rows", c.T("Between 100 and 1,000,000."))
	}
	if errs.Len() > 0 {
		app.InvalidErrors(errs)
	}
	app.PutSetting("gsheet_office_start", start)
	app.PutSetting("gsheet_office_end", end)
	app.PutSetting("gsheet_office_days", strings.Join(days, ","))
	app.PutSetting("gsheet_max_rows", conv.Str(mx))
	c.Flash("success", c.T("Settings saved. Existing schedules keep their times; syncs never run during the new office hours."))
	c.Redirect("/sheets/settings")
}
