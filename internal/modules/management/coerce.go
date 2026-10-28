package management

import (
	"html/template"
	"sort"
	"strconv"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/conv"
	"acme/internal/db"
)

// Values that come from other modules' hooks keep PHP's array keys but may be M, db.Row, *config.Map or lists of
// them; these helpers read them whatever the shape.

type kv struct {
	K string
	V any
}

// asMap reads a PHP associative array.
func asMap(v any) app.M {
	switch x := v.(type) {
	case app.M:
		return x
	case db.Row:
		return app.M(x)
	case map[string]float64:
		out := app.M{}
		for k, f := range x {
			out[k] = f
		}
		return out
	case *config.Map:
		out := app.M{}
		for _, k := range x.Keys {
			out[k] = x.Vals[k]
		}
		return out
	}
	return nil
}

// entries reads a PHP array in its order (lists: keys "0", "1" …; maps without order: keys sorted, numbers by value).
func entries(v any) []kv {
	switch x := v.(type) {
	case *config.Map:
		out := make([]kv, 0, len(x.Keys))
		for _, k := range x.Keys {
			out = append(out, kv{k, x.Vals[k]})
		}
		return out
	case []any:
		out := make([]kv, len(x))
		for i, e := range x {
			out[i] = kv{strconv.Itoa(i), e}
		}
		return out
	case []app.M:
		out := make([]kv, len(x))
		for i, e := range x {
			out[i] = kv{strconv.Itoa(i), e}
		}
		return out
	case []db.Row:
		out := make([]kv, len(x))
		for i, e := range x {
			out[i] = kv{strconv.Itoa(i), app.M(e)}
		}
		return out
	case []float64:
		out := make([]kv, len(x))
		for i, e := range x {
			out[i] = kv{strconv.Itoa(i), e}
		}
		return out
	case []string:
		out := make([]kv, len(x))
		for i, e := range x {
			out[i] = kv{strconv.Itoa(i), e}
		}
		return out
	case []int:
		out := make([]kv, len(x))
		for i, e := range x {
			out[i] = kv{strconv.Itoa(i), e}
		}
		return out
	}
	m := asMap(v)
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.Slice(keys, func(i, j int) bool {
		a, ea := strconv.Atoi(keys[i])
		b, eb := strconv.Atoi(keys[j])
		if ea == nil && eb == nil {
			return a < b
		}
		return keys[i] < keys[j]
	})
	out := make([]kv, len(keys))
	for i, k := range keys {
		out[i] = kv{k, m[k]}
	}
	return out
}

// list reads a list of PHP arrays.
func list(v any) []app.M {
	var out []app.M
	for _, e := range entries(v) {
		if m := asMap(e.V); m != nil {
			out = append(out, m)
		}
	}
	return out
}

// values reads a list of scalars.
func values(v any) []any {
	var out []any
	for _, e := range entries(v) {
		out = append(out, e.V)
	}
	return out
}

func f(v any) float64 { return conv.Float(v) }
func n(v any) int     { return conv.Int(v) }

func rawHTML(s string) template.HTML { return template.HTML(s) }
