package sheets

import (
	"encoding/json"
	"regexp"
	"strconv"
	"strings"

	"acme/internal/app"
	"acme/internal/conv"
	"acme/internal/db"
	"acme/internal/google"
)

// Every table of the databases of core and the enabled modules, which ones the administrator opened (gsheet_tables),
// who may sync them (gsheet_table_access) and reading their rows. Tables and columns that hold passwords, tokens,
// sessions or keys are never offered. (Support\Catalog)

var (
	secretTables  = regexp.MustCompile(`(?i)^(settings|erp_settings|google_connections|user_sessions|login_attempts|passkeys|password_resets|schema_migrations)$|^gsheet_|token|secret|password|session`)
	secretColumns = regexp.MustCompile(`(?i)password|passwd|token|secret|api_?key|private_?key|public_key|credential|cookie|(^|_)session|(^|_)otp($|_)|^key$|_hash$|sign_count|file_key`)
)

// dbArea is one database connection and the name of the area it belongs to.
type dbArea struct{ Key, Name string }

// databases: db connection key → name of the area it belongs to (core and usable modules), in module order.
func databases(c *app.Ctx) []dbArea {
	var out []dbArea
	seen := map[string]bool{}
	for _, m := range app.Modules() {
		d := m.DB
		if d == "" {
			d = m.Key
		}
		if seen[d] || !(m.Key == "core" || app.ModuleUsable(m.Key)) {
			continue
		}
		seen[d] = true
		out = append(out, dbArea{d, c.T(m.Name)})
	}
	return out
}

func dbName(c *app.Ctx, key string) (string, bool) {
	for _, d := range databases(c) {
		if d.Key == key {
			return d.Name, true
		}
	}
	return "", false
}

func secretTable(t string) bool  { return secretTables.MatchString(t) }
func secretColumn(c string) bool { return secretColumns.MatchString(c) }

type column struct{ Name, Type string }

// tableInfo is one table: its columns (in order) and an approximate row count.
type tableInfo struct {
	Name    string
	Columns []column
	Rows    int
}

func (t *tableInfo) has(col string) bool {
	for _, c := range t.Columns {
		if c.Name == col {
			return true
		}
	}
	return false
}

// tables of one database, in name order.
func tables(d string) []*tableInfo {
	var out []*tableInfo
	byName := map[string]*tableInfo{}
	q := db.On(d)
	for _, r := range q.Select(`SELECT c.table_name, c.column_name, c.data_type FROM information_schema.columns c
            JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name
            WHERE c.table_schema = 'public' AND t.table_type = 'BASE TABLE' ORDER BY c.table_name, c.ordinal_position`) {
		name := r.Str("table_name")
		if secretTable(name) {
			continue
		}
		t := byName[name]
		if t == nil {
			t = &tableInfo{Name: name}
			byName[name] = t
			out = append(out, t)
		}
		t.Columns = append(t.Columns, column{r.Str("column_name"), r.Str("data_type")})
	}
	for _, r := range q.Select("SELECT relname, GREATEST(reltuples, 0)::bigint AS n FROM pg_class WHERE relkind = 'r' AND relnamespace = 'public'::regnamespace") {
		if t := byName[r.Str("relname")]; t != nil {
			t.Rows = r.Int("n")
		}
	}
	return out
}

func findTable(d, name string) *tableInfo {
	for _, t := range tables(d) {
		if t.Name == name {
			return t
		}
	}
	return nil
}

// entry is the settings row of a table, created (closed) on first use.
func entry(d, table string) db.Row {
	if row := db.First("SELECT * FROM gsheet_tables WHERE db = ? AND table_name = ?", d, table); row != nil {
		return row
	}
	db.Exec("INSERT INTO gsheet_tables (db, table_name) VALUES (?, ?) ON CONFLICT DO NOTHING", d, table)
	return db.First("SELECT * FROM gsheet_tables WHERE db = ? AND table_name = ?", d, table)
}

func tableLabel(t db.Row) string {
	if l := t.Str("label"); l != "" {
		return l
	}
	return conv.Ucfirst(strings.ReplaceAll(t.Str("table_name"), "_", " "))
}

func hiddenColumns(t db.Row) []string {
	var raw []any
	_ = json.Unmarshal([]byte(t.Str("hidden_columns")), &raw)
	out := []string{}
	for _, v := range raw {
		if s, ok := v.(string); ok {
			out = append(out, s)
		}
	}
	return out
}

// allowed: opened tables this person may sync (administrators: every opened table).
func allowed(c *app.Ctx, u *app.CurrentUser) []db.Row {
	var dbs []any
	for _, d := range databases(c) {
		dbs = append(dbs, d.Key)
	}
	if len(dbs) == 0 {
		return []db.Row{}
	}
	in := strings.TrimSuffix(strings.Repeat("?,", len(dbs)), ",")
	if u.IsAdmin() {
		return db.Select("SELECT * FROM gsheet_tables WHERE enabled AND db IN ("+in+") ORDER BY db, coalesce(label, table_name)", dbs...)
	}
	return db.Select(`SELECT t.* FROM gsheet_tables t WHERE t.enabled AND t.db IN (`+in+`) AND EXISTS (
            SELECT 1 FROM gsheet_table_access a WHERE a.table_id = t.id AND (a.user_id = ? OR a.role_id = ?)) ORDER BY t.db, coalesce(t.label, t.table_name)`,
		append(dbs, u.ID, u.RoleID)...)
}

func mayUse(c *app.Ctx, tableID, userID int) bool {
	u := db.First("SELECT u.id, u.role_id, r.slug FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.is_active", userID)
	t := db.First("SELECT * FROM gsheet_tables WHERE id = ?", tableID)
	if u == nil || t == nil || !t.Bool("enabled") {
		return false
	}
	if _, ok := dbName(c, t.Str("db")); !ok {
		return false
	}
	return u.Str("slug") == "admin" || db.Scalar("SELECT 1 FROM gsheet_table_access WHERE table_id = ? AND (user_id = ? OR role_id = ?)", tableID, userID, u["role_id"]) != nil
}

// access: the roles and people given a table.
func access(tableID int) (roles, users []int) {
	for _, r := range db.Select("SELECT role_id, user_id FROM gsheet_table_access WHERE table_id = ?", tableID) {
		if r.Int("role_id") != 0 {
			roles = append(roles, r.Int("role_id"))
		}
		if r.Int("user_id") != 0 {
			users = append(users, r.Int("user_id"))
		}
	}
	return
}

// sentColumns: the table's columns minus secret and hidden ones.
func sentColumns(c *app.Ctx, t db.Row) ([]column, *tableInfo) {
	info := findTable(t.Str("db"), t.Str("table_name"))
	if info == nil {
		google.Fail(c.T("The table :t does not exist any more.", "t", t["table_name"]))
	}
	hidden := map[string]bool{}
	for _, h := range hiddenColumns(t) {
		hidden[h] = true
	}
	var out []column
	for _, col := range info.Columns {
		if !hidden[col.Name] && !secretColumn(col.Name) {
			out = append(out, col)
		}
	}
	return out, info
}

var numericTypes = []string{"smallint", "integer", "bigint", "numeric", "real", "double precision", "decimal"}

// read: header + rows ready for Sheets (numbers as numbers, booleans as TRUE/FALSE, the rest as text).
// Soft-deleted rows (deleted_at set) are left out.
func read(c *app.Ctx, t db.Row, limit int) (header []string, rows [][]any, truncated bool) {
	cols, info := sentColumns(c, t)
	if len(cols) == 0 {
		google.Fail(c.T("Every column of this table is hidden."))
	}
	q := func(id string) string { return `"` + strings.ReplaceAll(id, `"`, `""`) + `"` }
	names := make([]string, len(cols))
	for i, col := range cols {
		names[i] = q(col.Name)
		header = append(header, col.Name)
	}
	sql := "SELECT " + strings.Join(names, ", ") + " FROM " + q(t.Str("table_name"))
	if info.has("deleted_at") {
		sql += " WHERE deleted_at IS NULL"
	}
	order := "1"
	if info.has("id") {
		order = `"id"`
	}
	sql += " ORDER BY " + order + " LIMIT " + strconv.Itoa(limit+1)
	all := db.On(t.Str("db")).Select(sql)
	truncated = len(all) > limit
	if truncated {
		all = all[:limit]
	}
	rows = [][]any{}
	for _, r := range all {
		line := make([]any, 0, len(cols))
		for _, col := range cols {
			v := r[col.Name]
			switch {
			case v == nil:
				line = append(line, "")
			case col.Type == "boolean":
				line = append(line, conv.Bool(v))
			case conv.InList(col.Type, numericTypes...) && isNumeric(v):
				line = append(line, numberOf(v))
			default:
				line = append(line, conv.Substr(conv.Str(v), 0, 50000)) // Sheets cell limit
			}
		}
		rows = append(rows, line)
	}
	return header, rows, truncated
}

func isNumeric(v any) bool {
	switch x := v.(type) {
	case int, int64, float64, float32:
		return true
	case string:
		_, err := strconv.ParseFloat(strings.TrimSpace(x), 64)
		return err == nil
	}
	return false
}

// numberOf is $v + 0: an int when it has no decimals part, else a float.
func numberOf(v any) any {
	s, ok := v.(string)
	if !ok {
		return v
	}
	s = strings.TrimSpace(s)
	if i, err := strconv.ParseInt(s, 10, 64); err == nil {
		return i
	}
	f, _ := strconv.ParseFloat(s, 64)
	return f
}
