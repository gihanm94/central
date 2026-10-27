package machines

import (
	"bytes"
	"encoding/json"
	"fmt"
	"strings"
	"unicode/utf8"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/php"
)

// om is an ordered JSON object (a PHP array with string keys): om("a", 1, "b", 2).
func om(kv ...any) *config.Map {
	m := config.NewMap()
	for i := 0; i+1 < len(kv); i += 2 {
		m.Set(php.Str(kv[i]), kv[i+1])
	}
	return m
}

// json_encode flags
const (
	flagUnicode = 1 << iota // JSON_UNESCAPED_UNICODE
	flagSlashes             // JSON_UNESCAPED_SLASHES
)

// phpJSON is json_encode($v, flags): "/" becomes "\/" and non-ASCII "\uXXXX" unless the flags say otherwise.
func phpJSON(v any, flags int) string {
	var b bytes.Buffer
	enc := json.NewEncoder(&b)
	enc.SetEscapeHTML(false)
	if err := enc.Encode(v); err != nil {
		return "null"
	}
	s := strings.TrimSuffix(b.String(), "\n")
	if flags&flagSlashes == 0 {
		s = strings.ReplaceAll(s, "/", `\/`)
	}
	if flags&flagUnicode == 0 {
		var o strings.Builder
		for _, r := range s {
			switch {
			case r < utf8.RuneSelf:
				o.WriteRune(r)
			case r > 0xFFFF:
				r -= 0x10000
				fmt.Fprintf(&o, `\u%04x\u%04x`, 0xD800+(r>>10), 0xDC00+(r&0x3FF))
			default:
				fmt.Fprintf(&o, `\u%04x`, r)
			}
		}
		s = o.String()
	}
	return s
}

// jsonObject is json_decode($s, true) of an object (nil when it is not one).
func jsonObject(s string) map[string]any {
	var m map[string]any
	if json.Unmarshal([]byte(s), &m) != nil {
		return nil
	}
	return m
}

// jsonList is json_decode($s, true) ?: [] for a list of objects (a JSON object's values count as the list, like array_values).
func jsonList(s any) []map[string]any {
	str := php.Str(s)
	if str == "" {
		return nil
	}
	var raw any
	d := json.NewDecoder(strings.NewReader(str))
	d.UseNumber()
	if d.Decode(&raw) != nil {
		return nil
	}
	var vals []any
	switch x := raw.(type) {
	case []any:
		vals = x
	case map[string]any:
		for _, k := range sortedKeys(x) {
			vals = append(vals, x[k])
		}
	}
	var out []map[string]any
	for _, v := range vals {
		if m, ok := v.(map[string]any); ok {
			out = append(out, m)
		} else {
			out = append(out, map[string]any{})
		}
	}
	return out
}

// stamp fills the audit columns (MachinesController::stamp).
func stamp(c *app.Ctx, data *app.Clean, existing db.Row) *app.Clean {
	data.Set("updated_by", c.MustUser().ID)
	if existing == nil {
		data.Set("created_by", c.MustUser().ID)
	}
	return data
}

// withPeople: names of the people behind *_id columns, looked up in user_db (as <col>_name).
func withPeople(rows []db.Row, cols ...string) []db.Row {
	var ids []any
	for _, r := range rows {
		for _, col := range cols {
			ids = append(ids, r[col])
		}
	}
	n := names(ids...)
	for _, r := range rows {
		for _, col := range cols {
			if s, ok := n[r.Int(col)]; ok {
				r[col+"_name"] = s
			} else {
				r[col+"_name"] = nil
			}
		}
	}
	return rows
}

// withDept adds department_name.
func withDept(rows []db.Row) []db.Row {
	d := optMap(departments())
	for _, r := range rows {
		if s, ok := d[r.Str("department_id")]; ok && r["department_id"] != nil {
			r["department_name"] = s
		} else {
			r["department_name"] = nil
		}
	}
	return rows
}

// fmtDate is format_date($v) for a value that is set (callers check emptiness first, like the PHP views).
func fmtDate(c *app.Ctx, v any, f ...string) string {
	ff := "d M Y"
	if len(f) > 0 {
		ff = f[0]
	}
	return app.FormatDate(c, v, ff)
}

// orDash is $v ?: '—' for display.
func orDash(v any) string {
	if php.Empty(v) {
		return "—"
	}
	return php.Str(v)
}

func esc(v any) string { return app.E(v) }

func boolPtr(b bool) *bool { return &b }

// postedList is (array) Request::all()[$k]: the rows of a repeated field, in their order.
func postedList(v any) []any {
	switch x := v.(type) {
	case *config.Map:
		out := make([]any, 0, x.Len())
		for _, k := range x.Keys {
			out = append(out, x.Vals[k])
		}
		return out
	case []any:
		return x
	case map[string]any:
		var out []any
		for _, k := range sortedKeys(x) {
			out = append(out, x[k])
		}
		return out
	case nil:
		return nil
	}
	return []any{v}
}

// rowOf reads one posted row (a map) as strings; ok is false when it is not an array.
func rowOf(v any) (map[string]any, bool) {
	switch x := v.(type) {
	case *config.Map:
		m := map[string]any{}
		for _, k := range x.Keys {
			m[k] = x.Vals[k]
		}
		return m, true
	case map[string]any:
		return x, true
	}
	return nil, false
}
