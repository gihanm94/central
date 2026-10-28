package crm

import (
	"archive/zip"
	"bytes"
	"crypto/rand"
	"encoding/csv"
	"encoding/hex"
	"encoding/json"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strings"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/conv"
	"acme/internal/db"
)

// Admin only: move CRM data in and out in bulk (DataController).
//   Export  Excel (.xlsx), CSV or SQL INSERT statements, any tables, optional department / date filter.
//   Import  Excel, CSV or an SQL file → pick the table → map every column of the file to a table column → check → import.
// SQL files are only read for their data (INSERT / COPY); nothing in them is ever executed.

func dataGate(c *app.Ctx) {
	if !c.MustUser().IsAdmin() {
		app.Abort(http.StatusForbidden, c.T("Only an administrator can use the data tools."))
	}
}

func dataIndex(c *app.Ctx) {
	dataGate(c)
	sourceSweep()
	type trow struct {
		Key, Label string
		Count      string
	}
	var tables []trow
	for _, t := range dataTables {
		tables = append(tables, trow{t.Key, c.T(t.Label), conv.NF(conv.Int(db.On("crm").Scalar("SELECT count(*) FROM "+t.Key)), 0)})
	}
	tab := "import"
	if c.Query("tab") == "export" {
		tab = "export"
	}
	c.View("crm/data/index", app.M{"title": c.T("Data tools"), "tables": tables, "departments": departments(), "tab": tab, "tabs": []pair{{"import", c.T("Import")}, {"export", c.T("Export")}},
		"errFile": c.Sess.Errors().Get("file"), "errTables": c.Sess.Errors().Get("tables")})
}

func dayOf(v string) string {
	v = strings.TrimSpace(v)
	if ymd.MatchString(v) {
		return v
	}
	return ""
}

func dataExport(c *app.Ctx) {
	dataGate(c)
	var picked []string
	for _, t := range c.Inputs("tables") {
		if _, ok := tableByKey(t); ok {
			picked = append(picked, t)
		}
	}
	format := c.Input("format", "xlsx")
	if len(picked) == 0 {
		app.Invalid("tables", c.T("Tick at least one table."))
	}
	if !inStrs(format, []string{"xlsx", "csv", "sql"}) {
		format = "xlsx"
	}
	opt := exportOpt{Names: c.Bool("names"), Department: conv.Int(c.Input("department")), From: dayOf(c.Input("from")), To: dayOf(c.Input("to"))}
	type ex struct {
		table  string
		header []string
		rows   [][]any
		cols   []colInfo
	}
	var data []ex
	for _, t := range picked {
		h, r, cols := exportTable(t, opt)
		data = append(data, ex{t, h, r, cols})
	}
	stamp := conv.Date("Ymd-His", conv.Now())
	app.LogActivity(c, app.ActivityEntry{Action: "export", Type: "crm_data", Label: strings.Join(picked, ", "), Text: "Exported CRM data (:tables) as :format",
		Params: []any{"tables", strings.Join(picked, ", "), "format", strings.ToUpper(format)}, Module: "crm"})

	send := func(name, typ string, body []byte) { c.Download(name, typ, body) }
	switch {
	case format == "sql":
		out := "-- " + app.Setting("company_name", config.String("app.name", "")) + " CRM data export, " + conv.Date("Y-m-d H:i", conv.Now()) +
			"\n-- Only INSERT statements: create the tables first (the app does this on install).\n\n"
		for _, d := range data {
			out += "-- " + d.table + " (" + conv.Str(len(d.rows)) + " rows)\n" + exportSQL(d.table, d.header, d.rows, d.cols)
			if _, ok := colByName(d.cols, "id"); ok {
				out += "SELECT setval(pg_get_serial_sequence('" + d.table + "', 'id'), GREATEST((SELECT COALESCE(MAX(id), 1) FROM " + d.table + "), 1));\n"
			}
			out += "\n"
		}
		send("crm-export-"+stamp+".sql", "application/sql; charset=UTF-8", []byte(out))
	case format == "csv" && len(data) == 1:
		d := data[0]
		c.CsvDownload("crm-"+d.table+"-"+stamp+".csv", d.header, d.rows)
	case format == "xlsx":
		var sheets []app.Sheet
		for _, d := range data {
			sheets = append(sheets, app.Sheet{Name: d.table, Header: d.header, Rows: exportForExcel(d.rows, d.cols)})
		}
		send("crm-export-"+stamp+".xlsx", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", app.XlsxBuild(sheets))
	}
	// several CSV files in one zip
	var buf bytes.Buffer
	z := zip.NewWriter(&buf)
	for _, d := range data {
		var cb bytes.Buffer
		cb.WriteString("\xEF\xBB\xBF")
		w := csv.NewWriter(&cb)
		_ = w.Write(d.header)
		for _, r := range d.rows {
			line := make([]string, len(r))
			for i, v := range r {
				s := conv.Str(v)
				if _, ok := v.(string); ok && regexp.MustCompile(`^[=+\-@]`).MatchString(s) {
					s = "'" + s
				}
				line[i] = s
			}
			_ = w.Write(line)
		}
		w.Flush()
		f, _ := z.Create(d.table + ".csv")
		_, _ = f.Write(cb.Bytes())
	}
	_ = z.Close()
	send("crm-export-"+stamp+".zip", "application/zip", buf.Bytes())
}

func dataUpload(c *app.Ctx) {
	dataGate(c)
	fh := c.File("file")
	if fh == nil {
		app.Invalid("file", c.T("Choose a file to upload."))
	}
	ext := strings.ToLower(strings.TrimPrefix(filepath.Ext(fh.Filename), "."))
	if !inStrs(ext, []string{"xlsx", "csv", "txt", "sql"}) {
		app.Invalid("file", c.T("Upload an Excel (.xlsx), CSV or SQL file. (Old .xls files: save them as .xlsx first.)"))
	}
	if fh.Size > sourceMaxBytes {
		app.Invalid("file", c.T("The file is bigger than 25 MB. Split it into smaller files."))
	}
	b := make([]byte, 16)
	_, _ = rand.Read(b)
	token := hex.EncodeToString(b)
	if ext == "txt" {
		ext = "csv"
	}
	f, err := fh.Open()
	if err != nil {
		app.Invalid("file", c.T("The file could not be saved."))
	}
	body, _ := io.ReadAll(f)
	f.Close()
	path := filepath.Join(importDir(), token+"."+ext)
	if err := os.WriteFile(path, body, 0o664); err != nil {
		app.Invalid("file", c.T("The file could not be saved."))
	}
	_ = os.WriteFile(filepath.Join(importDir(), token+".name"), []byte(fh.Filename), 0o664)
	if e := app.Catch(func() { sourceLoad(c, path, true) }); e != nil {
		if _, ok := e.(*app.ValidationError); ok {
			panic(e)
		}
		sourceForget(token)
		msg := e.Error()
		if msg == "" {
			msg = c.T("This file could not be read.")
		}
		app.Invalid("file", msg)
	}
	c.Redirect("/crm/data/map?token=" + token)
}

func dataFile(c *app.Ctx, token string) (string, string) {
	path := sourcePath(token)
	if path == "" {
		app.Abort(http.StatusNotFound, c.T("This upload has expired. Upload the file again."))
	}
	name := filepath.Base(path)
	if raw, err := os.ReadFile(filepath.Join(importDir(), token+".name")); err == nil {
		name = string(raw)
	}
	return path, name
}

func sheetNamed(sheets []sheetData, name string) (sheetData, bool) {
	for _, s := range sheets {
		if s.Name == name {
			return s, true
		}
	}
	return sheetData{}, false
}

func dataMap(c *app.Ctx) {
	dataGate(c)
	token := c.Query("token")
	path, fileName := dataFile(c, token)
	withHeader := c.Query("header", "1") != "0"
	var sheets []sheetData
	if e := app.Catch(func() { sheets = sourceLoad(c, path, withHeader) }); e != nil {
		app.Abort(422, e.Error())
	}
	sheet := c.Query("sheet")
	if _, ok := sheetNamed(sheets, sheet); !ok {
		sheet = ""
		for _, s := range sheets {
			if len(s.Rows) > 0 {
				sheet = s.Name
				break
			}
		}
		if sheet == "" && len(sheets) > 0 {
			sheet = sheets[0].Name
		}
	}
	table := c.Query("table")
	if _, ok := tableByKey(table); !ok {
		table = ""
		for _, t := range dataTables {
			if norm2(t.Key) == norm2(sheet) || norm2(t.Label) == norm2(sheet) {
				table = t.Key
				break
			}
		}
	}
	data, _ := sheetNamed(sheets, sheet)
	var cols []colInfo
	var saved []db.Row
	var suggest map[string]int
	fixed := map[string]string{}
	savedID := conv.Int(c.Query("saved"))
	if table != "" {
		cols = dataColumns(table)
		saved = db.On("crm").Select("SELECT id, name FROM import_mappings WHERE target = ? ORDER BY name", table)
		suggest = dataSuggest(table, data.Header)
		if savedID != 0 {
			if m := db.On("crm").First("SELECT mapping FROM import_mappings WHERE id = ? AND target = ?", savedID, table); m != nil {
				var mp map[string]struct {
					Header *string `json:"header"`
					Fixed  string  `json:"fixed"`
				}
				_ = json.Unmarshal([]byte(m.Str("mapping")), &mp)
				for col, x := range mp {
					if _, ok := colByName(cols, col); !ok {
						continue
					}
					idx := -1
					if x.Header != nil {
						for i, h := range data.Header {
							if h == *x.Header {
								idx = i
								break
							}
						}
					}
					suggest[col] = idx
					if x.Fixed != "" {
						fixed[col] = x.Fixed
					}
				}
			}
		}
	}
	base := app.M{"token": token, "sheet": sheet, "table": nilStr(table), "header": nil}
	if !withHeader {
		base["header"] = "0"
	}
	link := func(x app.M) string {
		q := map[string]any{}
		for k, v := range base {
			q[k] = v
		}
		for k, v := range x {
			q[k] = v
		}
		return c.URL("/crm/data/map", q)
	}
	samples := make([][]string, len(data.Header))
	sample := data.Rows
	if len(sample) > 5 {
		sample = sample[:5]
	}
	for i := range data.Header {
		samples[i] = []string{}
		for _, r := range sample {
			if i < len(r) && r[i] != "" {
				samples[i] = append(samples[i], r[i])
			}
		}
	}
	type mrow struct {
		Name, Type, Fixed string
		Required, Lookup  bool
		Sel               int
	}
	var main, audit []mrow
	for _, col := range cols {
		t := col.Type
		if col.Length != nil {
			t += "(" + conv.Str(*col.Length) + ")"
		}
		sel, ok := suggest[col.Name]
		if !ok {
			sel = -1
		}
		_, lk := dataLookups[col.Name]
		r := mrow{col.Name, t, fixed[col.Name], col.Required, lk, sel}
		if dataAudit(col.Name) {
			audit = append(audit, r)
		} else {
			main = append(main, r)
		}
	}
	type srow struct {
		Name, URL string
		Count     string
		On        bool
	}
	var srows []srow
	for _, s := range sheets {
		srows = append(srows, srow{s.Name, link(app.M{"sheet": s.Name, "table": nil, "saved": nil}), conv.NF(len(s.Rows), 0), s.Name == sheet})
	}
	type svrow struct {
		ID, Name, URL string
		On            bool
	}
	var svs []svrow
	for _, s := range saved {
		svs = append(svs, svrow{s.Str("id"), s.Str("name"), link(app.M{"saved": s.Int("id")}), savedID == s.Int("id")})
	}
	var tablesOpts []app.Opt
	for _, t := range dataTables {
		tablesOpts = append(tablesOpts, app.Opt{Value: t.Key, Label: c.T(t.Label)})
	}
	tlabel, _ := app.OptLabel(tablesOpts, table)
	sj, _ := json.Marshal(samples)
	toggle := link(app.M{"header": "0"})
	if !withHeader {
		toggle = link(app.M{"header": nil})
	}
	c.View("crm/data/map", app.M{"title": c.T("Import data"), "token": token, "fileName": fileName, "sheets": srows, "sheet": sheet, "table": table, "tables": tablesOpts, "tableLabel": tlabel,
		"header": data.Header, "rowCount": conv.NF(len(data.Rows), 0), "withHeader": withHeader, "saved": svs, "isSql": strings.HasSuffix(path, ".sql"),
		"main": main, "audit": audit, "samples": string(sj), "headerToggle": toggle, "errMap": c.Sess.Errors().Get("map"), "errMode": c.Sess.Errors().Get("mode")})
}

var norm2Re = regexp.MustCompile(`[^a-z0-9]+`)

func norm2(s string) string { return norm2Re.ReplaceAllString(strings.ToLower(s), "") }

func dataRun(c *app.Ctx) {
	dataGate(c)
	token := c.Input("token")
	path, fileName := dataFile(c, token)
	table := c.Input("table")
	if _, ok := tableByKey(table); !ok {
		app.Abort(http.StatusNotFound)
	}
	withHeader := c.Input("header", "1") != "0"
	sheets := sourceLoad(c, path, withHeader)
	sheetName := c.Input("sheet")
	data, ok := sheetNamed(sheets, sheetName)
	if !ok {
		app.Abort(http.StatusNotFound)
	}
	mode := c.Input("mode")
	if !inStrs(mode, []string{"insert", "update_code", "update_id"}) {
		mode = "insert"
	}
	cols := dataColumns(table)
	srcs := app.InMap(c.InputAny("map"))
	fix := app.InMap(c.InputAny("fixed"))
	mp := map[string]mapSpec{}
	save := map[string]any{}
	mapped := false
	for _, col := range cols {
		sv, _ := srcs.Get(col.Name)
		s := app.InStr(sv)
		src := -1
		if s != "" && ctypeDigit(s) && conv.Int(s) < len(data.Header) {
			src = conv.Int(s)
		}
		fv, _ := fix.Get(col.Name)
		fixed := strings.TrimSpace(app.InStr(fv))
		mp[col.Name] = mapSpec{src, fixed}
		if src >= 0 || fixed != "" {
			mapped = true
			var hd any
			if src >= 0 {
				hd = data.Header[src]
			}
			save[col.Name] = app.M{"header": hd, "fixed": fixed}
		}
	}
	if !mapped {
		app.Invalid("map", c.T("Map at least one column."))
	}
	if mode != "insert" {
		key := "code"
		if mode == "update_id" {
			key = "id"
		}
		if mp[key].Src < 0 {
			app.Invalid("mode", c.T("To update existing rows, map the :key column.", "key", key))
		}
	}
	if name := strings.TrimSpace(c.Input("save_as")); name != "" {
		mj, _ := json.Marshal(orderedSave(cols, save))
		db.On("crm").Insert("import_mappings", db.Cols{{Name: "name", Value: conv.Substr(name, 0, 120)}, {Name: "target", Value: table}, {Name: "mapping", Value: string(mj)}, {Name: "created_by", Value: c.MustUser().ID}}, "")
	}
	dry := c.Input("action") != "import"
	u := c.MustUser()
	result := newImporter(c, u.ID, u.DepartmentID).run(table, data.Rows, mp, mode, dry)
	if !dry {
		app.LogActivity(c, app.ActivityEntry{Action: "import", Type: "crm_data", Label: table, Text: `Imported :n rows into :table from ":file"`,
			Params: []any{"n", result.Inserted + result.Updated, "table", table, "file", fileName},
			Props:  app.M{"changes": app.M{"inserted": app.M{"from": "", "to": conv.Str(result.Inserted)}, "updated": app.M{"from": "", "to": conv.Str(result.Updated)}, "skipped": app.M{"from": "", "to": conv.Str(result.Skipped)}}},
			Module: "crm"})
	}
	// hidden inputs that re-post the same mapping
	type hid struct{ K, V string }
	hidden := []hid{{"token", token}, {"sheet", sheetName}, {"table", table}, {"header", ternaryStr(withHeader, "1", "0")}, {"mode", mode}}
	for _, col := range cols {
		s := ""
		if mp[col.Name].Src >= 0 {
			s = conv.Str(mp[col.Name].Src)
		}
		hidden = append(hidden, hid{"map[" + col.Name + "]", s})
	}
	for _, col := range cols {
		hidden = append(hidden, hid{"fixed[" + col.Name + "]", mp[col.Name].Fixed})
	}
	type erow struct {
		Line int
		Msg  string
	}
	var errs []erow
	for _, e := range result.Errors {
		errs = append(errs, erow{conv.Int(e[0]), conv.Str(e[1])})
	}
	tl, _ := tableByKey(table)
	title := c.T("Import result")
	if dry {
		title = c.T("Check result")
	}
	ok2 := result.Inserted + result.Updated
	backQ := map[string]any{"token": token, "sheet": sheetName, "table": table, "header": nil}
	if !withHeader {
		backQ["header"] = "0"
	}
	c.View("crm/data/result", app.M{"title": title, "dry": dry, "result": result, "tableLabel": c.T(tl.Label), "fileName": fileName, "hidden": hidden, "errors": errs,
		"ok": ok2, "total": conv.NF(result.Total, 0), "inserted": conv.NF(result.Inserted, 0), "updated": conv.NF(result.Updated, 0), "skipped": conv.NF(result.Skipped, 0),
		"importLabel": c.T("Import :n rows", "n", conv.NF(ok2, 0)), "backURL": c.URL("/crm/data/map", backQ), "token": token, "mapURL": c.URL("/crm/data/map", "token", token),
		"errNote": c.T("Row numbers are the rows of the sheet (the header is row 1). Showing :n.", "n", len(errs))})
}

// orderedSave keeps the columns in table order in the saved JSON (PHP built the array in that order).
func orderedSave(cols []colInfo, save map[string]any) *config.Map {
	m := config.NewMap()
	for _, c := range cols {
		if v, ok := save[c.Name]; ok {
			m.Set(c.Name, v)
		}
	}
	return m
}

func dataDiscard(c *app.Ctx) {
	dataGate(c)
	token := c.Input("token")
	if tokenRe.MatchString(token) {
		sourceForget(token)
	}
	c.Flash("success", c.T("The uploaded file was removed."))
	c.Redirect("/crm/data")
}

func dataDeleteMapping(c *app.Ctx) {
	dataGate(c)
	db.On("crm").Exec("DELETE FROM import_mappings WHERE id = ?", c.ParamInt("id"))
	c.Flash("success", c.T("Saved mapping deleted."))
	c.Back("", "")
}
