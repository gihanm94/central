package accounting

import (
	"encoding/json"
	"errors"
	"regexp"
	"strconv"
	"strings"

	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/php"
)

// Store (Erp\Store) saves ERP rows into the erp_* tables (insert, or update when the id is already there).

type erpField struct{ col, json, typ string }

type erpSchema struct {
	table  string
	fields []erpField
}

func storeSchema(entity string) erpSchema {
	m, ok := config.Get("erp_schema." + entity).(*config.Map)
	if !ok {
		panic(errors.New("Unknown ERP entity " + entity))
	}
	s := erpSchema{table: mstr(m, "table")}
	fm := mmap(m, "fields")
	for _, col := range fm.Keys {
		f, _ := fm.Vals[col].(*config.Map)
		s.fields = append(s.fields, erpField{col: col, json: mstr(f, "json"), typ: mstr(f, "type")})
	}
	return s
}

// erpRow is one mapped row: columns in schema order.
type erpRow struct {
	cols []string
	vals map[string]any
}

// storeMap turns one ERP JSON object into column values (keys matched without caring about upper / lower case); nil when it has no id.
func storeMap(entity string, raw *config.Map, stamp map[string]any) *erpRow {
	s := storeSchema(entity)
	lower := map[string]any{}
	for _, k := range raw.Keys {
		lk := strings.ToLower(k)
		lower[lk] = raw.Vals[k] // array_change_key_case: the last one wins
	}
	r := &erpRow{vals: map[string]any{}}
	for _, f := range s.fields {
		r.cols = append(r.cols, f.col)
		if f.json == "*" {
			r.vals[f.col] = storeRaw(raw)
		} else {
			r.vals[f.col] = storeValue(f.typ, lower[strings.ToLower(f.json)])
		}
	}
	for col, v := range stamp {
		if _, ok := r.vals[col]; ok {
			r.vals[col] = v
		}
	}
	if r.vals["id"] == nil {
		return nil
	}
	return r
}

// storeRaw: the ERP object as JSON, without the expanded objects inside it (they are saved in their own tables).
func storeRaw(raw *config.Map) string {
	m := config.NewMap()
	for _, k := range raw.Keys {
		switch raw.Vals[k].(type) {
		case *config.Map, []any:
			continue
		}
		m.Set(k, raw.Vals[k])
	}
	if m.Len() == 0 {
		return "[]"
	}
	return jsonEnc(m, false)
}

func isNumeric(v any) bool {
	switch x := v.(type) {
	case json.Number:
		return true
	case int, float64:
		return true
	case string:
		s := strings.Trim(x, " \t\n\r\v\f")
		if s == "" || strings.ContainsAny(s, "xXpP_") || strings.EqualFold(s, "inf") || strings.EqualFold(s, "nan") || strings.HasPrefix(strings.ToLower(strings.TrimLeft(s, "+-")), "inf") {
			return false
		}
		_, err := strconv.ParseFloat(s, 64)
		return err == nil
	}
	return false
}

func storeValue(typ string, v any) any {
	if v == nil {
		return nil
	}
	if s, ok := v.(string); ok && s == "" {
		return nil
	}
	switch v.(type) {
	case *config.Map, []any:
		if typ == "text" {
			b := jsonEnc(v, false)
			return strings.ReplaceAll(b, "/", `\/`) // JSON_UNESCAPED_UNICODE only: slashes are escaped
		}
		return nil
	}
	switch typ {
	case "int":
		if isNumeric(v) {
			return php.Int(numStr(v))
		}
		return nil
	case "num":
		if isNumeric(v) {
			return numStr(v)
		}
		return nil
	case "bool":
		if b, ok := v.(bool); ok {
			return bstr(b)
		}
		if inStrs(strings.ToLower(valStr(v)), []string{"true", "1", "t"}) {
			return "true"
		}
		return "false"
	case "date":
		return storeDate(valStr(v))
	}
	return valStr(v)
}

func numStr(v any) string {
	if n, ok := v.(json.Number); ok {
		return n.String()
	}
	return php.Str(v)
}

// valStr is (string) $v.
func valStr(v any) string {
	switch x := v.(type) {
	case json.Number:
		return x.String()
	case bool:
		if x {
			return "1"
		}
		return ""
	}
	return php.Str(v)
}

var erpDateRe = regexp.MustCompile(`^(\d{4})-\d{2}-\d{2}`)

// storeDate: the ERP uses 0001-01-01 for "no date".
func storeDate(v string) any {
	m := erpDateRe.FindStringSubmatch(v)
	if m == nil || php.Int(m[1]) < 1900 {
		return nil
	}
	if _, ok := php.ParseTime(v); !ok {
		return nil
	}
	return v
}

// storeUpsert writes mapped rows; overwrite false = only new ids are added, existing rows stay as they are.
func storeUpsert(entity string, rows []*erpRow, overwrite bool) int {
	if len(rows) == 0 {
		return 0
	}
	s := storeSchema(entity)
	cols := make([]string, len(s.fields))
	for i, f := range s.fields {
		cols[i] = f.col
	}
	// the same id twice in one statement is an error: the last one wins, in first-seen order
	byID := map[string]int{}
	var uniq []*erpRow
	for _, r := range rows {
		k := php.Str(r.vals["id"])
		if i, ok := byID[k]; ok {
			uniq[i] = r
			continue
		}
		byID[k] = len(uniq)
		uniq = append(uniq, r)
	}
	per := max(1, 30000/len(cols))
	var sets []string
	for _, c := range cols {
		if c != "id" {
			sets = append(sets, c+" = EXCLUDED."+c)
		}
	}
	set := strings.Join(sets, ", ") + ", synced_at = now()"
	n := 0
	acc().Transaction(func(tx db.Q) {
		for i := 0; i < len(uniq); i += per {
			chunk := uniq[i:min(len(uniq), i+per)]
			marks := "(" + strings.TrimSuffix(strings.Repeat("?,", len(cols)), ",") + ")"
			all := make([]string, len(chunk))
			for j := range chunk {
				all[j] = marks
			}
			sql := "INSERT INTO " + s.table + " (" + strings.Join(cols, ",") + ") VALUES " + strings.Join(all, ",")
			if overwrite {
				sql += " ON CONFLICT (id) DO UPDATE SET " + set
			} else {
				sql += " ON CONFLICT (id) DO NOTHING" // overwrite off: rows already there are left as they are
			}
			var par []any
			for _, r := range chunk {
				for _, c := range cols {
					par = append(par, r.vals[c])
				}
			}
			tx.Exec(sql, par...)
			n += len(chunk)
		}
	})
	return n
}
