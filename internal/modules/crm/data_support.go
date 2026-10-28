package crm

import (
	"errors"
	"fmt"
	"math"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
	"unicode"

	"acme/internal/app"
	"acme/internal/conv"
	"acme/internal/db"
)

// ---------------------------------------------------------------- Schema: what the data tools may touch, and what each column looks like

type tableDef struct{ Key, Label, Prefix string }

var dataTables = []tableDef{
	{"leads", "Leads", "LD"}, {"contacts", "Contacts", "CT"}, {"contact_mobiles", "Contact mobile numbers", ""},
	{"opportunities", "Opportunities", "OP"}, {"opportunity_products", "Opportunity products", ""},
	{"opportunity_steps", "Opportunity stage history and next steps", ""}, {"activities", "Activities", "AC"},
	{"campaigns", "Campaigns", "CP"}, {"projects", "Projects", "PJ"}, {"project_members", "Project responsible people", ""},
	{"tasks", "Tasks", "TK"}, {"task_assignees", "Task responsible people", ""}, {"comments", "Comments", ""},
	{"industries", "Industries", ""}, {"lead_sources", "Lead sources", ""}, {"lead_industries", "Lead ↔ industries", ""},
	{"lead_lead_sources", "Lead ↔ lead sources", ""}, {"products", "Products", ""}, {"currencies", "Currencies", ""},
}

func tableByKey(k string) (tableDef, bool) {
	for _, t := range dataTables {
		if t.Key == k {
			return t, true
		}
	}
	return tableDef{}, false
}

type lookupDef struct {
	Table string
	Keys  []string
	Conn  string
}

// column => [table, lookup columns, connection] — a value in such a column may be an id or a name / code / e-mail
var dataLookups = map[string]lookupDef{
	"lead_id": {"leads", []string{"code", "name_en", "name_th"}, "crm"}, "company_id": {"leads", []string{"code", "name_en", "name_th"}, "crm"},
	"contact_id": {"contacts", []string{"code", "name_en"}, "crm"}, "opportunity_id": {"opportunities", []string{"code", "name"}, "crm"},
	"project_id": {"projects", []string{"code", "name"}, "crm"}, "task_id": {"tasks", []string{"code", "name"}, "crm"},
	"industry_id": {"industries", []string{"name"}, "crm"}, "lead_source_id": {"lead_sources", []string{"name"}, "crm"}, "product_id": {"products", []string{"code", "name"}, "crm"},
	"owner_id": {"users", []string{"email", "name"}, "core"}, "created_by": {"users", []string{"email", "name"}, "core"}, "updated_by": {"users", []string{"email", "name"}, "core"},
	"user_id": {"users", []string{"email", "name"}, "core"}, "added_by": {"users", []string{"email", "name"}, "core"}, "done_by": {"users", []string{"email", "name"}, "core"},
	"department_id": {"departments", []string{"name"}, "core"},
}

type colInfo struct {
	Name, Type string
	Length     *int
	Required   bool
	Identity   bool
	HasDefault bool
}

var schemaCache = map[string][]colInfo{}

func dataColumns(table string) []colInfo {
	if c, ok := schemaCache[table]; ok {
		return c
	}
	if _, ok := tableByKey(table); !ok {
		panic(errors.New("Unknown table"))
	}
	var out []colInfo
	for _, c := range db.On("crm").Select(`SELECT column_name, data_type, character_maximum_length, is_nullable, column_default, is_identity FROM information_schema.columns
                              WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position`, table) {
		if c.Str("column_name") == "deleted_at" {
			continue
		}
		identity := c.Str("is_identity") == "YES" || strings.HasPrefix(c.Str("column_default"), "nextval(")
		ci := colInfo{Name: c.Str("column_name"), Type: c.Str("data_type"), Identity: identity, HasDefault: c["column_default"] != nil}
		if c["character_maximum_length"] != nil {
			n := c.Int("character_maximum_length")
			ci.Length = &n
		}
		ci.Required = c.Str("is_nullable") == "NO" && c["column_default"] == nil && !identity
		out = append(out, ci)
	}
	schemaCache[table] = out
	return out
}

func colByName(cols []colInfo, n string) (colInfo, bool) {
	for _, c := range cols {
		if c.Name == n {
			return c, true
		}
	}
	return colInfo{}, false
}

func trPairs(c *app.Ctx, l []pair) []app.Opt { return tr(c, l) }

// dataChoices: code => label for columns that only take a fixed list of values; nil for free columns.
func dataChoices(c *app.Ctx, table, column string) []app.Opt {
	switch table + "." + column {
	case "opportunities.opportunity_stage", "opportunity_steps.stage", "opportunity_steps.from_stage":
		return stageOptions(c)
	case "opportunities.priority":
		return tr(c, priorities)
	case "activities.activity_type":
		return tr(c, activityTypes)
	case "activities.status":
		return tr(c, activityStatuses)
	case "activities.call_direction":
		return tr(c, callDirections)
	case "activities.meeting_type":
		return tr(c, meetingTypes)
	case "campaigns.type":
		return tr(c, campaignTypes)
	case "campaigns.status":
		return tr(c, campaignStatuses)
	case "projects.status":
		return tr(c, projectStatuses)
	case "tasks.status":
		return tr(c, taskStatuses)
	}
	return nil
}

var normRe = regexp.MustCompile(`[^a-z0-9\x{0E00}-\x{0E7F}]+`)

func norm(s string) string { return normRe.ReplaceAllString(strings.ToLower(s), "") }

var dataAlias = map[string][]string{
	"name_en": {"name", "nameenglish", "company", "companyname", "customer", "fullname", "nameen"}, "name_th": {"namethai", "thainame", "nameth"},
	"lead_id": {"lead", "company", "companyname", "customer", "leadname"}, "contact_id": {"contact", "contactname"}, "opportunity_id": {"opportunity", "deal"},
	"project_id": {"project"}, "owner_id": {"owner", "ownername", "assignedto", "responsible", "salesperson", "owneremail"}, "department_id": {"department", "dept"},
	"opportunity_stage": {"stage", "status"}, "phone": {"tel", "telephone", "phonenumber"}, "mobile": {"mobilenumber", "cell"},
	"description": {"details", "notes", "note", "remark", "remarks"}, "topic": {"subject", "title"}, "start_at": {"start", "date", "when"},
	"amount": {"value", "price", "total"}, "tax_id": {"taxno", "taxnumber", "taxid"}, "job_title": {"position", "title"}, "email": {"mail", "emailaddress"},
	"created_at": {"created", "createddate"}, "created_by": {"createdby"}, "number": {"mobile", "phone"},
}

// dataSuggest: which source column (by position) probably belongs to each table column: column => index (-1 = none).
func dataSuggest(table string, header []string) map[string]int {
	heads := map[string]int{}
	for i, h := range header {
		k := norm(h)
		if _, ok := heads[k]; !ok {
			heads[k] = i
		}
	}
	out := map[string]int{}
	for _, c := range dataColumns(table) {
		cands := []string{norm(c.Name)}
		for _, a := range dataAlias[c.Name] {
			cands = append(cands, norm(a))
		}
		hit := -1
		for _, k := range cands {
			if i, ok := heads[k]; ok {
				hit = i
				break
			}
		}
		out[c.Name] = hit
	}
	return out
}

// dataAudit: is it an audit / generated column (ordered last on the mapping screen)?
func dataAudit(column string) bool {
	return conv.InList(column, "id", "created_by", "updated_by", "created_at", "updated_at", "owner_id", "department_id")
}

// ---------------------------------------------------------------- Source: an uploaded file as named sheets of rows

const sourceMaxBytes = 25 * 1024 * 1024

func importDir() string {
	d := filepath.Join(app.BasePath, "storage", "imports")
	_ = os.MkdirAll(d, 0o775)
	return d
}

// sourceSweep: throw away files older than a day.
func sourceSweep() {
	files, _ := filepath.Glob(filepath.Join(importDir(), "*"))
	for _, f := range files {
		if st, err := os.Stat(f); err == nil && !st.IsDir() && time.Since(st.ModTime()) > 24*time.Hour {
			_ = os.Remove(f)
		}
	}
}

var tokenRe = regexp.MustCompile(`^[a-f0-9]{32}$`)

func sourcePath(token string) string {
	if !tokenRe.MatchString(token) {
		return ""
	}
	for _, ext := range []string{"xlsx", "csv", "sql"} {
		p := filepath.Join(importDir(), token+"."+ext)
		if st, err := os.Stat(p); err == nil && !st.IsDir() {
			return p
		}
	}
	return ""
}

func sourceForget(token string) {
	files, _ := filepath.Glob(filepath.Join(importDir(), token+".*"))
	for _, f := range files {
		_ = os.Remove(f)
	}
}

type sheetData struct {
	Name   string
	Header []string
	Rows   [][]string
}

func sourceLetter(c *app.Ctx, i int) string {
	s := ""
	for n := i + 1; n > 0; n = (n - 1) / 26 {
		s = string(rune(65+(n-1)%26)) + s
	}
	return c.T("Column") + " " + s
}

func sourceLoad(c *app.Ctx, path string, firstRowHeader bool) []sheetData {
	ext := strings.ToLower(strings.TrimPrefix(filepath.Ext(path), "."))
	raw, err := os.ReadFile(path)
	if err != nil {
		panic(err)
	}
	if ext == "sql" {
		var out []sheetData
		for _, t := range sqlDumpParse(string(raw), 100000) {
			out = append(out, sheetData{Name: t.Table, Header: t.Columns, Rows: t.Rows})
		}
		return out
	}
	type named struct {
		Name string
		Rows [][]string
	}
	var sheets []named
	if ext == "xlsx" {
		xs, err := app.XlsxRead(raw, 100001)
		if err != nil {
			panic(err)
		}
		for _, s := range xs {
			sheets = append(sheets, named{s.Name, s.Rows})
		}
	} else {
		rows, err := csvRead(string(raw))
		if err != nil {
			panic(err)
		}
		sheets = []named{{"CSV", rows}}
	}
	var out []sheetData
	for _, s := range sheets {
		rows := s.Rows
		if len(rows) == 0 {
			out = append(out, sheetData{Name: s.Name})
			continue
		}
		var header []string
		width := 0
		if firstRowHeader {
			for _, h := range rows[0] {
				header = append(header, strings.TrimSpace(h))
			}
			rows = rows[1:]
			width = len(header)
			for _, r := range rows {
				width = max(width, len(r))
			}
			for i := 0; i < width; i++ {
				if i >= len(header) {
					header = append(header, "")
				}
				if header[i] == "" {
					header[i] = sourceLetter(c, i)
				}
			}
		} else {
			for _, r := range rows {
				width = max(width, len(r))
			}
			for i := 0; i < width; i++ {
				header = append(header, sourceLetter(c, i))
			}
		}
		out = append(out, sheetData{Name: s.Name, Header: header, Rows: rows})
	}
	return out
}

// csvRead: any delimiter (comma, semicolon, tab), UTF-8 or a Thai Windows file.
func csvRead(raw string) ([][]string, error) {
	raw = strings.TrimPrefix(raw, "\xEF\xBB\xBF")
	if !isUTF8(raw) {
		raw = decodeLegacy(raw)
	}
	first := raw
	if i := strings.IndexByte(raw, '\n'); i >= 0 {
		first = raw[:i]
	}
	delim := ','
	best := strings.Count(first, ",")
	for _, d := range []rune{';', '\t'} {
		if n := strings.Count(first, string(d)); n > best {
			best, delim = n, d
		}
	}
	rd := newCSVReader(raw, delim)
	var rows [][]string
	for {
		rec, ok := rd()
		if !ok {
			break
		}
		if len(rec) == 1 && rec[0] == "" {
			continue
		}
		rows = append(rows, rec)
		if len(rows) > 100001 {
			return nil, errors.New("The file has more than 100000 rows. Split it into smaller files.")
		}
	}
	return rows, nil
}

func isUTF8(s string) bool {
	for _, r := range s {
		if r == unicode.ReplacementChar {
			return false
		}
	}
	return true
}

// decodeLegacy converts Windows-874 (Thai) / Latin-1 bytes to UTF-8.
func decodeLegacy(s string) string {
	var b strings.Builder
	for i := 0; i < len(s); i++ {
		c := s[i]
		switch {
		case c < 0x80:
			b.WriteByte(c)
		case c >= 0xA1 && c <= 0xFB:
			b.WriteRune(rune(0x0E00 + int(c) - 0xA0)) // CP874 Thai block
		default:
			b.WriteRune(rune(c))
		}
	}
	return b.String()
}

// newCSVReader is fgetcsv: quoted fields with "" escapes and embedded line breaks.
func newCSVReader(s string, delim rune) func() ([]string, bool) {
	rs := []rune(s)
	i := 0
	return func() ([]string, bool) {
		if i >= len(rs) {
			return nil, false
		}
		var rec []string
		var cur strings.Builder
		inQ := false
		for i < len(rs) {
			ch := rs[i]
			switch {
			case inQ:
				if ch == '"' {
					if i+1 < len(rs) && rs[i+1] == '"' {
						cur.WriteRune('"')
						i++
					} else {
						inQ = false
					}
				} else {
					cur.WriteRune(ch)
				}
			case ch == '"' && cur.Len() == 0:
				inQ = true
			case ch == delim:
				rec = append(rec, cur.String())
				cur.Reset()
			case ch == '\n' || ch == '\r':
				if ch == '\r' && i+1 < len(rs) && rs[i+1] == '\n' {
					i++
				}
				i++
				return append(rec, cur.String()), true
			default:
				cur.WriteRune(ch)
			}
			i++
		}
		return append(rec, cur.String()), true
	}
}

// ---------------------------------------------------------------- SqlDump: the DATA of an SQL file, never executed

type dumpTable struct {
	Table   string
	Columns []string
	Rows    [][]string
}

var (
	reInsert = regexp.MustCompile(`(?i)^INSERT\s+(?:IGNORE\s+)?INTO\s+`)
	reCopy   = regexp.MustCompile(`(?i)^COPY\s+`)
	reValues = regexp.MustCompile(`(?i)^VALUES?\s*`)
	reE      = regexp.MustCompile(`(?i)\bE'`)
	reCast   = regexp.MustCompile(`^::[A-Za-z_ ]+(\(\d+(,\s*\d+)?\))?`)
	reCastTr = regexp.MustCompile(`(?i)::[a-z_ ]+(\(\d+(,\d+)?\))?$`)
	reDollar = regexp.MustCompile(`^\$([A-Za-z_]*)\$`)
	reFrom   = regexp.MustCompile(`(?i)\s*FROM\s+stdin`)
	reEnd    = regexp.MustCompile(`(?m)^\\\.\s*$`)
	reIdent  = regexp.MustCompile(`^[A-Za-z0-9_$]+`)
)

func isSpace(b byte) bool {
	return b == ' ' || b == '\t' || b == '\n' || b == '\r' || b == '\v' || b == '\f'
}

func sqlDumpParse(sql string, maxRows int) []dumpTable {
	sql = strings.TrimPrefix(sql, "\xEF\xBB\xBF")
	backslash := strings.Contains(sql, "`") || reE.MatchString(sql)
	var order []string
	out := map[string]*dumpTable{}
	n := len(sql)
	i, total := 0, 0
	get := func(t string, cols []string) *dumpTable {
		if out[t] == nil {
			out[t] = &dumpTable{Table: t, Columns: cols}
			order = append(order, t)
		}
		return out[t]
	}
	for i < n {
		c := sql[i]
		if isSpace(c) {
			i++
			continue
		}
		if c == '-' && i+1 < n && sql[i+1] == '-' || c == '#' {
			e := strings.IndexByte(sql[i:], '\n')
			if e < 0 {
				i = n
			} else {
				i += e + 1
			}
			continue
		}
		if c == '/' && i+1 < n && sql[i+1] == '*' {
			e := strings.Index(sql[i+2:], "*/")
			if e < 0 {
				i = n
			} else {
				i += e + 4
			}
			continue
		}
		rest := sql[i:]
		if m := reInsert.FindString(rest); m != "" {
			i += len(m)
			table := sqlIdent(sql, &i)
			var cols []string
			sqlSkipWs(sql, &i)
			if i < n && sql[i] == '(' {
				i++
				for i < n {
					sqlSkipWs(sql, &i)
					cols = append(cols, sqlIdent(sql, &i))
					sqlSkipWs(sql, &i)
					if i < n && sql[i] == ',' {
						i++
						continue
					}
					if i < n && sql[i] == ')' {
						i++
					}
					break
				}
			}
			sqlSkipWs(sql, &i)
			vm := reValues.FindString(sql[i:])
			if vm == "" {
				i = sqlSkipStatement(sql, i, backslash)
				continue
			}
			i += len(vm)
			t := get(table, cols)
			if len(t.Columns) == 0 && len(cols) > 0 {
				t.Columns = cols
			}
			for i < n {
				sqlSkipWs(sql, &i)
				if i >= n || sql[i] != '(' {
					break
				}
				i++
				var row []string
				var nulls []bool
				for i < n {
					v, isNull := sqlValue(sql, &i, backslash)
					row = append(row, v)
					nulls = append(nulls, isNull)
					sqlSkipWs(sql, &i)
					ch := byte(0)
					if i < n {
						ch = sql[i]
					}
					if ch == ',' {
						i++
						continue
					}
					if ch == ')' {
						i++
					}
					break
				}
				t.Rows = append(t.Rows, markNulls(row, nulls))
				total++
				if total > maxRows {
					panic(fmt.Errorf("The file has more than %d rows. Split it into smaller files.", maxRows))
				}
				sqlSkipWs(sql, &i)
				if i < n && sql[i] == ',' {
					i++
					continue
				}
				break
			}
			i = sqlSkipStatement(sql, i, backslash)
			continue
		}
		if m := reCopy.FindString(rest); m != "" {
			i += len(m)
			table := sqlIdent(sql, &i)
			var cols []string
			sqlSkipWs(sql, &i)
			if i < n && sql[i] == '(' {
				e := strings.IndexByte(sql[i:], ')')
				if e < 0 {
					e = n - i - 1
				}
				for _, x := range strings.Split(sql[i+1:i+e], ",") {
					cols = append(cols, strings.Trim(x, " \t\"`"))
				}
				i += e + 1
			}
			nl := strings.IndexByte(sql[i:], '\n')
			if nl < 0 || !reFrom.MatchString(sql[i:i+nl]) {
				i = sqlSkipStatement(sql, i, backslash)
				continue
			}
			i += nl + 1
			end := n
			if loc := reEnd.FindStringIndex(sql[i:]); loc != nil {
				end = i + loc[0]
			}
			t := get(table, cols)
			for _, line := range strings.Split(sql[i:end], "\n") {
				if line == "" {
					continue
				}
				var row []string
				var nulls []bool
				for _, cell := range strings.Split(strings.TrimRight(line, "\r"), "\t") {
					if cell == `\N` {
						row, nulls = append(row, ""), append(nulls, true)
					} else {
						row, nulls = append(row, strings.NewReplacer(`\\`, `\`, `\t`, "\t", `\n`, "\n", `\r`, "\r").Replace(cell)), append(nulls, false)
					}
				}
				t.Rows = append(t.Rows, markNulls(row, nulls))
				total++
				if total > maxRows {
					panic(fmt.Errorf("The file has more than %d rows. Split it into smaller files.", maxRows))
				}
			}
			i = end + 2
			continue
		}
		i = sqlSkipStatement(sql, i, backslash)
	}
	var res []dumpTable
	for _, k := range order {
		t := out[k]
		width := 0
		for _, r := range t.Rows {
			width = max(width, len(r))
		}
		if len(t.Columns) == 0 {
			for j := 1; j <= max(1, width); j++ {
				t.Columns = append(t.Columns, "column_"+strconv.Itoa(j))
			}
		}
		for j, r := range t.Rows {
			for len(r) < len(t.Columns) {
				r = append(r, "")
			}
			t.Rows[j] = r
		}
		if len(t.Rows) > 0 {
			res = append(res, *t)
		}
	}
	return res
}

// markNulls: a SQL NULL is an empty cell (the PHP rows held null; the importer reads both as empty).
func markNulls(row []string, nulls []bool) []string {
	for i, n := range nulls {
		if n {
			row[i] = ""
		}
	}
	return row
}

func sqlSkipWs(s string, i *int) {
	for *i < len(s) && isSpace(s[*i]) {
		*i++
	}
}

// sqlIdent: table or column name: optional quotes (", `, [ ]) and a schema prefix, which is dropped.
func sqlIdent(s string, i *int) string {
	name := ""
	for {
		sqlSkipWs(s, i)
		var ch byte
		if *i < len(s) {
			ch = s[*i]
		}
		switch {
		case ch == '"' || ch == '`':
			e := strings.IndexByte(s[*i+1:], ch)
			if e < 0 {
				e = len(s) - *i - 1
			}
			name = s[*i+1 : *i+1+e]
			*i += e + 2
		case ch == '[':
			e := strings.IndexByte(s[*i:], ']')
			if e < 0 {
				e = len(s) - *i
			}
			name = s[*i+1 : *i+e]
			*i += e + 1
		default:
			name = reIdent.FindString(s[*i:])
			*i += len(name)
		}
		if *i < len(s) && s[*i] == '.' {
			*i++
			continue
		}
		return name
	}
}

// sqlValue: one value: 'text', number, NULL, TRUE/FALSE, or something like now() / '2026-01-01'::date (casts are dropped).
func sqlValue(s string, i *int, backslash bool) (string, bool) {
	sqlSkipWs(s, i)
	n := len(s)
	var c byte
	if *i < n {
		c = s[*i]
	}
	if (c == 'E' || c == 'e' || c == 'N') && *i+1 < n && s[*i+1] == '\'' {
		*i++
		c = '\''
	}
	if c == '\'' {
		*i++
		var v strings.Builder
		for *i < n {
			ch := s[*i]
			if ch == '\'' {
				if *i+1 < n && s[*i+1] == '\'' {
					v.WriteByte('\'')
					*i += 2
					continue
				}
				*i++
				break
			}
			if ch == '\\' && backslash && *i+1 < n {
				nx := s[*i+1]
				switch nx {
				case 'n':
					v.WriteByte('\n')
				case 't':
					v.WriteByte('\t')
				case 'r':
					v.WriteByte('\r')
				case '0':
					v.WriteByte(0)
				default:
					v.WriteByte(nx)
				}
				*i += 2
				continue
			}
			v.WriteByte(ch)
			*i++
		}
		if m := reCast.FindString(s[*i:]); m != "" {
			*i += len(m)
		}
		return v.String(), false
	}
	// bare token up to the next comma / closing bracket at this level
	start := *i
	depth := 0
	for *i < n {
		ch := s[*i]
		if ch == '(' {
			depth++
		} else if ch == ')' {
			if depth == 0 {
				break
			}
			depth--
		} else if ch == ',' && depth == 0 {
			break
		} else if ch == '\'' {
			e := strings.IndexByte(s[*i+1:], '\'')
			if e < 0 {
				*i = n
			} else {
				*i += e + 1
			}
		}
		*i++
	}
	tok := strings.TrimSpace(s[start:min(*i, n)])
	if tok == "" || strings.EqualFold(tok, "NULL") || strings.Contains(tok, "(") {
		return "", true
	}
	if strings.EqualFold(tok, "TRUE") {
		return "true", false
	}
	if strings.EqualFold(tok, "FALSE") {
		return "false", false
	}
	return reCastTr.ReplaceAllString(tok, ""), false
}

// sqlSkipStatement: move past the end of the current statement (the next ; outside quotes).
func sqlSkipStatement(s string, i int, backslash bool) int {
	n := len(s)
	for i < n {
		ch := s[i]
		switch {
		case ch == '\'':
			i++
			for i < n {
				if s[i] == '\\' && backslash {
					i += 2
					continue
				}
				if s[i] == '\'' {
					if i+1 < n && s[i+1] == '\'' {
						i += 2
						continue
					}
					break
				}
				i++
			}
		case ch == '$' && reDollar.MatchString(s[i:]): // $$ … $$ function bodies
			m := reDollar.FindString(s[i:])
			e := strings.Index(s[i+len(m):], m)
			if e < 0 {
				i = n
			} else {
				i = i + len(m) + e + len(m) - 1
			}
		case ch == ';':
			return i + 1
		}
		i++
	}
	return n
}

// ---------------------------------------------------------------- Exporter

type exportOpt struct {
	Department int
	From, To   string
	Names      bool
}

func tableHasCol(table, col string) bool {
	return db.On("crm").Scalar("SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?", table, col) != nil
}

// exportTable returns header, rows and column info.
func exportTable(table string, opt exportOpt) ([]string, [][]any, []colInfo) {
	cols := dataColumns(table)
	var where []string
	var par []any
	has := func(n string) bool { _, ok := colByName(cols, n); return ok }
	if tableHasCol(table, "deleted_at") {
		where = append(where, "t.deleted_at IS NULL")
	}
	if opt.Department != 0 && has("department_id") {
		where = append(where, "t.department_id = ?")
		par = append(par, opt.Department)
	}
	if opt.From != "" && has("created_at") {
		where = append(where, "t.created_at >= ?")
		par = append(par, opt.From+" 00:00:00")
	}
	if opt.To != "" && has("created_at") {
		where = append(where, "t.created_at <= ?")
		par = append(par, opt.To+" 23:59:59")
	}
	order := "1"
	if has("id") {
		order = "t.id"
	}
	var names, header []string
	for _, c := range cols {
		names = append(names, "t."+c.Name)
		header = append(header, c.Name)
	}
	sql := "SELECT " + strings.Join(names, ", ") + " FROM " + table + " t"
	if len(where) > 0 {
		sql += " WHERE " + strings.Join(where, " AND ")
	}
	rows := db.On("crm").Select(sql+" ORDER BY "+order, par...)
	maps := map[string]map[int]any{}
	if opt.Names {
		for _, c := range cols {
			lk, ok := dataLookups[c.Name]
			if !ok {
				continue
			}
			show := lk.Keys[0]
			if lk.Table == "users" {
				show = "email"
			} else if inStrs("name_en", lk.Keys) {
				show = "name_en"
			} else if inStrs("name", lk.Keys) {
				show = "name"
			}
			m := map[int]any{}
			for _, r := range db.On(lk.Conn).Select("SELECT id, " + show + " AS v FROM " + lk.Table) {
				m[r.Int("id")] = r["v"]
			}
			maps[c.Name] = m
		}
	}
	var out [][]any
	for _, r := range rows {
		line := make([]any, 0, len(cols))
		for _, c := range cols {
			v := r[c.Name]
			if v != nil {
				if m, ok := maps[c.Name]; ok {
					if x, ok := m[conv.Int(v)]; ok {
						v = x
					}
				}
			}
			if v != nil && c.Type == "boolean" {
				if conv.Bool(v) {
					v = "true"
				} else {
					v = "false"
				}
			}
			line = append(line, v)
		}
		out = append(out, line)
	}
	return header, out, cols
}

var numericTypes = []string{"integer", "smallint", "bigint", "numeric", "double precision", "real"}

// exportForExcel: numbers as numbers in Excel; everything else text.
func exportForExcel(rows [][]any, cols []colInfo) [][]any {
	numeric := map[int]bool{}
	for i, c := range cols {
		if inStrs(c.Type, numericTypes) && c.Name != "id" && !strings.HasSuffix(c.Name, "_id") {
			numeric[i] = true
		}
	}
	for _, r := range rows {
		for i := range numeric {
			if r[i] != nil && conv.Str(r[i]) != "" && app.IsNumeric(conv.Str(r[i])) {
				s := conv.Str(r[i])
				if strings.Contains(s, ".") {
					r[i] = conv.Float(s)
				} else {
					r[i] = conv.Int(s)
				}
			}
		}
	}
	return rows
}

func exportSQL(table string, header []string, rows [][]any, cols []colInfo) string {
	var b strings.Builder
	cl := strings.Join(header, ", ")
	for _, r := range rows {
		var vals []string
		for i, v := range r {
			if v == nil {
				vals = append(vals, "NULL")
				continue
			}
			t := "text"
			if c, ok := colByName(cols, header[i]); ok {
				t = c.Type
			}
			s := conv.Str(v)
			switch {
			case t == "boolean":
				if s == "true" {
					vals = append(vals, "TRUE")
				} else {
					vals = append(vals, "FALSE")
				}
			case inStrs(t, numericTypes) && app.IsNumeric(s):
				vals = append(vals, s)
			default:
				vals = append(vals, "'"+strings.ReplaceAll(s, "'", "''")+"'")
			}
		}
		b.WriteString("INSERT INTO " + table + " (" + cl + ") VALUES (" + strings.Join(vals, ", ") + ");\n")
	}
	return b.String()
}

// ---------------------------------------------------------------- Importer

type mapSpec struct {
	Src   int // -1 = none
	Fixed string
}

type importResult struct {
	Inserted, Updated, Skipped, Total int
	Errors                            [][2]any
}

type importer struct {
	c        *app.Ctx
	adminID  int
	adminDep int
	lookup   map[string]map[string]int
	ids      map[string]map[int]bool
	codes    map[string]map[string]int
	depOf    map[int]int
}

func newImporter(c *app.Ctx, adminID, adminDep int) *importer {
	return &importer{c: c, adminID: adminID, adminDep: adminDep, lookup: map[string]map[string]int{}, ids: map[string]map[int]bool{}, codes: map[string]map[string]int{}}
}

func (im *importer) run(table string, rows [][]string, mp map[string]mapSpec, mode string, dry bool) importResult {
	cols := dataColumns(table)
	td, _ := tableByKey(table)
	prefix := td.Prefix
	_, hasID := colByName(cols, "id")
	res := importResult{Total: len(rows)}
	idGiven := false
	seen := map[string]bool{}
	has := func(n string) bool { _, ok := colByName(cols, n); return ok }
	for n, row := range rows {
		line := n + 2 // row 1 of the sheet is the header
		var errs []string
		data := map[string]any{}
		var keys []string
		for _, col := range cols {
			m, ok := mp[col.Name]
			raw := ""
			if ok && m.Src >= 0 && m.Src < len(row) {
				raw = strings.TrimSpace(row[m.Src])
			}
			if raw == "" && ok && strings.TrimSpace(m.Fixed) != "" {
				raw = strings.TrimSpace(m.Fixed)
			}
			if raw == "" {
				continue
			}
			if v, set := im.convert(table, col, raw, &errs); set {
				data[col.Name] = v
				keys = append(keys, col.Name)
			}
		}
		blank := true
		for _, v := range row {
			if strings.TrimSpace(v) != "" {
				blank = false
			}
		}
		if blank {
			res.Total--
			continue
		}
		// who owns it: the person importing, unless the file says otherwise
		set := func(k string, v any) {
			if _, ok := data[k]; !ok {
				keys = append(keys, k)
			}
			data[k] = v
		}
		if has("owner_id") && data["owner_id"] == nil {
			set("owner_id", im.adminID)
		}
		if has("department_id") && data["department_id"] == nil {
			o := im.adminID
			if v, ok := data["owner_id"]; ok {
				o = conv.Int(v)
			}
			if d := im.departmentOf(o); d != 0 {
				set("department_id", d)
			} else {
				set("department_id", nil)
			}
		}
		if has("created_by") && data["created_by"] == nil && mode == "insert" {
			set("created_by", im.adminID)
		}
		if has("updated_by") && data["updated_by"] == nil {
			set("updated_by", im.adminID)
		}

		existing := 0
		if mode != "insert" {
			key := "code"
			if mode == "update_id" {
				key = "id"
			}
			if v, ok := data[key]; ok {
				existing = im.find(table, key, v)
			} else if len(errs) == 0 {
				errs = append(errs, im.c.T("The :key is empty, so the existing row cannot be found.", "key", key))
			}
		}
		if existing == 0 {
			for _, col := range cols {
				if col.Required && data[col.Name] == nil && col.Name != "created_by" {
					errs = append(errs, im.c.T(":col is required.", "col", col.Name))
				}
			}
			if code, ok := data["code"]; ok && mode == "insert" {
				lc := strings.ToLower(conv.Str(code))
				if seen[lc] || im.find(table, "code", code) != 0 {
					errs = append(errs, im.c.T("The code :code already exists.", "code", conv.Str(code)))
				}
				seen[lc] = true
			}
		}
		if len(errs) > 0 {
			res.Skipped++
			if len(res.Errors) < 200 {
				res.Errors = append(res.Errors, [2]any{line, strings.Join(uniq(errs), " ")})
			}
			continue
		}
		if dry {
			if existing != 0 {
				res.Updated++
			} else {
				res.Inserted++
			}
			continue
		}
		if e := db.Try(func() {
			if existing != 0 {
				var set db.Cols
				for _, k := range keys {
					if k != "id" && k != "created_by" {
						set = append(set, db.Col{Name: k, Value: data[k]})
					}
				}
				if has("updated_at") {
					set = append(set, db.Col{Name: "updated_at", Value: conv.NowString()})
				}
				db.On("crm").Update(table, set, db.Cols{{Name: "id", Value: existing}})
				res.Updated++
			} else {
				var ins db.Cols
				for _, k := range keys {
					ins = append(ins, db.Col{Name: k, Value: data[k]})
				}
				if hasID {
					id := db.On("crm").InsertID(table, ins)
					if prefix != "" && conv.Empty(data["code"]) {
						db.On("crm").Exec("UPDATE "+table+" SET code = ? WHERE id = ? AND code IS NULL", prefix+"-"+padInt(id, 5), id)
					}
					_, given := data["id"]
					idGiven = idGiven || given
				} else {
					db.On("crm").Insert(table, ins, "")
				}
				res.Inserted++
			}
		}); e != nil {
			res.Skipped++
			if len(res.Errors) < 200 {
				res.Errors = append(res.Errors, [2]any{line, im.friendly(e)})
			}
		}
	}
	if !dry && idGiven && hasID { // rows came with their own ids: keep the counter ahead of them
		db.On("crm").Exec("SELECT setval(pg_get_serial_sequence('" + table + "', 'id'), GREATEST((SELECT COALESCE(MAX(id), 1) FROM " + table + "), 1))")
	}
	return res
}

func uniq(l []string) []string {
	seen := map[string]bool{}
	var out []string
	for _, s := range l {
		if !seen[s] {
			seen[s] = true
			out = append(out, s)
		}
	}
	return out
}

var sqlState = regexp.MustCompile(`^SQLSTATE\[[^\]]+\]:[^:]*:\s*`)

func (im *importer) friendly(e error) string {
	msg := e.Error()
	c := im.c
	code := ""
	if de, ok := e.(*db.Error); ok {
		code = de.Code()
	}
	has := func(x string) bool { return code == x || strings.Contains(msg, x) }
	switch {
	case has("23505"):
		return c.T("Duplicate: a row with the same unique value already exists.")
	case has("23503"):
		return c.T("It points to a record that does not exist (check the lead / contact / project it belongs to).")
	case has("23502"):
		return c.T("A required value is missing.")
	case has("23514"):
		return c.T("A value is not allowed for this column.")
	case has("22001"):
		return c.T("A value is too long for its column.")
	}
	return conv.Limit(sqlState.ReplaceAllString(msg, ""), 160)
}

func (im *importer) departmentOf(userID int) int {
	if im.depOf == nil {
		im.depOf = map[int]int{}
		for _, r := range db.Select("SELECT id, department_id FROM users") {
			im.depOf[r.Int("id")] = r.Int("department_id")
		}
	}
	if d, ok := im.depOf[userID]; ok && d != 0 {
		return d
	}
	return im.adminDep
}

func (im *importer) find(table, key string, value any) int {
	if key == "id" {
		s := conv.Str(value)
		if !ctypeDigit(s) {
			return 0
		}
		return conv.Int(db.On("crm").Scalar("SELECT id FROM "+table+" WHERE id = ?", conv.Int(s)))
	}
	if im.codes[table] == nil {
		im.codes[table] = map[string]int{}
		for _, r := range db.On("crm").Select("SELECT id, lower(code) AS c FROM " + table + " WHERE code IS NOT NULL") {
			if _, ok := im.codes[table][r.Str("c")]; !ok {
				im.codes[table][r.Str("c")] = r.Int("id")
			}
		}
	}
	return im.codes[table][strings.ToLower(conv.Str(value))]
}

var (
	wholeRe = regexp.MustCompile(`^-?\d+(\.0+)?$`)
	boolYes = []string{"1", "true", "t", "yes", "y", "x", "ใช่", "จริง"}
	boolNo  = []string{"0", "false", "f", "no", "n", "ไม่", "ไม่ใช่", "เท็จ"}
)

func (im *importer) convert(table string, col colInfo, raw string, errs *[]string) (any, bool) {
	c := im.c
	name, t := col.Name, col.Type
	if _, ok := dataLookups[name]; ok && (t == "bigint" || t == "integer") {
		if v, ok := im.resolve(name, raw, errs); ok {
			return v, true
		}
		return nil, false
	}
	switch {
	case t == "bigint" || t == "integer" || t == "smallint":
		v := strings.NewReplacer(",", "", " ", "").Replace(raw)
		if !wholeRe.MatchString(v) {
			*errs = append(*errs, c.T(`:col must be a whole number (got ":v").`, "col", name, "v", conv.Limit(raw, 30)))
			return nil, false
		}
		return conv.Int(v), true
	case t == "numeric" || t == "double precision" || t == "real":
		v := strings.NewReplacer(",", "", " ", "", "฿", "", "$", "").Replace(raw)
		if !app.IsNumeric(v) {
			*errs = append(*errs, c.T(`:col must be a number (got ":v").`, "col", name, "v", conv.Limit(raw, 30)))
			return nil, false
		}
		return v, true
	case t == "boolean":
		l := strings.ToLower(raw)
		if inStrs(l, boolYes) {
			return true, true
		}
		if inStrs(l, boolNo) {
			return false, true
		}
		*errs = append(*errs, c.T(`:col must be yes or no (got ":v").`, "col", name, "v", conv.Limit(raw, 30)))
		return nil, false
	case t == "date" || strings.HasPrefix(t, "timestamp"):
		v, ok := importDate(raw, t != "date")
		if !ok {
			*errs = append(*errs, c.T(`:col must be a date (got ":v").`, "col", name, "v", conv.Limit(raw, 30)))
			return nil, false
		}
		return v, true
	}
	// text
	if choices := dataChoices(c, table, name); choices != nil {
		if optHas(choices, raw) {
			return raw, true
		}
		up := strings.ToUpper(strings.NewReplacer(" ", "_", "-", "_", "&", "").Replace(raw))
		if optHas(choices, up) {
			return up, true
		}
		for _, o := range choices {
			if strings.ToLower(o.Label) == strings.ToLower(raw) ||
				strings.ToLower(strings.NewReplacer(" ", "", "&", "").Replace(o.Label)) == strings.ToLower(strings.NewReplacer(" ", "", "-", "", "&", "", "_", "").Replace(raw)) {
				return o.Value, true
			}
		}
		var labels []string
		for _, o := range choices {
			labels = append(labels, o.Label)
		}
		*errs = append(*errs, c.T(`:col ":v" is not one of: :list.`, "col", name, "v", conv.Limit(raw, 30), "list", strings.Join(labels, ", ")))
		return nil, false
	}
	if col.Length != nil && conv.Len(raw) > *col.Length {
		*errs = append(*errs, c.T(":col is too long (:n characters, at most :max).", "col", name, "n", conv.Len(raw), "max", *col.Length))
		return nil, false
	}
	return raw, true
}

var softTables = []string{"leads", "contacts", "opportunities", "projects", "tasks"}

// resolve: a number is taken as an id; anything else is looked up by name / code / e-mail.
func (im *importer) resolve(col, raw string, errs *[]string) (int, bool) {
	lk := dataLookups[col]
	ck := lk.Table + "." + lk.Conn
	if im.lookup[ck] == nil {
		m := map[string]int{}
		ids := map[int]bool{}
		var sel []string
		for _, k := range lk.Keys {
			sel = append(sel, k+" AS k_"+k)
		}
		sql := "SELECT id, " + strings.Join(sel, ", ") + " FROM " + lk.Table
		if lk.Table == "users" || inStrs(lk.Table, softTables) {
			sql += " WHERE deleted_at IS NULL"
		}
		for _, r := range db.On(lk.Conn).Select(sql) {
			ids[r.Int("id")] = true
			for _, k := range lk.Keys {
				v := strings.ToLower(strings.TrimSpace(r.Str("k_" + k)))
				if v != "" {
					if _, ok := m[v]; !ok {
						m[v] = r.Int("id")
					}
				}
			}
		}
		im.lookup[ck], im.ids[ck] = m, ids
	}
	if ctypeDigit(raw) && im.ids[ck][conv.Int(raw)] {
		return conv.Int(raw), true
	}
	if hit, ok := im.lookup[ck][strings.ToLower(raw)]; ok {
		return hit, true
	}
	if ctypeDigit(raw) {
		*errs = append(*errs, im.c.T(":col: no record with id :v.", "col", col, "v", raw))
	} else {
		*errs = append(*errs, im.c.T(`:col: ":v" was not found.`, "col", col, "v", conv.Limit(raw, 40)))
	}
	return 0, false
}

var (
	dmy    = regexp.MustCompile(`^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})(?:[ T]+(\d{1,2}):(\d{2})(?::(\d{2}))?)?`)
	ymd2   = regexp.MustCompile(`^(\d{4})[/\-.](\d{1,2})[/\-.](\d{1,2})(?:[ T]+(\d{1,2}):(\d{2})(?::(\d{2}))?)?`)
	serial = regexp.MustCompile(`^\d{5}(\.\d+)?$`)
)

// importDate: 2026-10-06, 06/10/2026, 6.10.2026 14:30, a Buddhist-era year (2569) or an Excel serial number.
func importDate(raw string, withTime bool) (string, bool) {
	raw = strings.TrimSpace(raw)
	var y, mo, d, hh, mi, ss int
	atoi := func(s string) int { n, _ := strconv.Atoi(s); return n }
	if m := dmy.FindStringSubmatch(raw); m != nil {
		d, mo, y, hh, mi, ss = atoi(m[1]), atoi(m[2]), atoi(m[3]), atoi(m[4]), atoi(m[5]), atoi(m[6])
	} else if m := ymd2.FindStringSubmatch(raw); m != nil {
		y, mo, d, hh, mi, ss = atoi(m[1]), atoi(m[2]), atoi(m[3]), atoi(m[4]), atoi(m[5]), atoi(m[6])
	} else if serial.MatchString(raw) {
		f, _ := strconv.ParseFloat(raw, 64)
		t := time.Unix(int64(math.Round((f-25569)*86400)), 0).UTC()
		if withTime {
			return t.Format("2006-01-02 15:04:05"), true
		}
		return t.Format("2006-01-02"), true
	} else {
		ts := conv.StrToTime(raw)
		if ts == 0 {
			return "", false
		}
		t := conv.Unix(ts)
		if withTime {
			return t.Format("2006-01-02 15:04:05"), true
		}
		return t.Format("2006-01-02"), true
	}
	if y > 2400 {
		y -= 543
	}
	t := time.Date(y, time.Month(mo), d, 0, 0, 0, 0, time.UTC)
	if mo < 1 || mo > 12 || t.Day() != d || hh > 23 || mi > 59 {
		return "", false
	}
	if withTime {
		return fmt.Sprintf("%04d-%02d-%02d %02d:%02d:%02d", y, mo, d, hh, mi, ss), true
	}
	return fmt.Sprintf("%04d-%02d-%02d", y, mo, d), true
}

var _ = sort.Strings
