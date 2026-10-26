package app

import (
	"net/url"
	"strings"

	"acme/internal/config"
	"acme/internal/php"
)

// ParseForm turns form values into PHP's nested arrays: "ids[]" → list, "lines[0][qty]" → lines → 0 → qty.
// The result keeps the order the fields were sent in. Values are strings, lists ([]any) or *config.Map.
func ParseForm(values url.Values, order []string) *config.Map {
	root := config.NewMap()
	seen := map[string]bool{}
	keys := order
	if keys == nil {
		for k := range values {
			keys = append(keys, k)
		}
	}
	for _, k := range keys {
		if seen[k] {
			continue
		}
		seen[k] = true
		for _, v := range values[k] {
			setPath(root, k, v)
		}
	}
	return root
}

// formOrder reads the field names in the order they appear in an urlencoded body.
func formOrder(raw string) []string {
	var out []string
	for _, part := range strings.Split(raw, "&") {
		if part == "" {
			continue
		}
		k := part
		if i := strings.IndexByte(part, '='); i >= 0 {
			k = part[:i]
		}
		if dk, err := url.QueryUnescape(k); err == nil {
			out = append(out, dk)
		}
	}
	return out
}

func setPath(root *config.Map, name, value string) {
	base := name
	var parts []string
	if i := strings.IndexByte(name, '['); i > 0 && strings.HasSuffix(name, "]") {
		base = name[:i]
		rest := name[i:]
		for strings.HasPrefix(rest, "[") {
			j := strings.IndexByte(rest, ']')
			if j < 0 {
				break
			}
			parts = append(parts, rest[1:j])
			rest = rest[j+1:]
		}
	}
	if len(parts) == 0 {
		root.Set(base, value)
		return
	}
	var cur any = root
	keys := append([]string{base}, parts...)
	for i, k := range keys {
		last := i == len(keys)-1
		m := cur.(*config.Map)
		if k == "" { // "x[]": append
			k = php.Str(m.Len())
		}
		if last {
			m.Set(k, value)
			return
		}
		next, ok := m.Vals[k].(*config.Map)
		if !ok {
			next = config.NewMap()
			m.Set(k, next)
		}
		cur = next
	}
}

// Value helpers for request input (strings, lists or maps from ParseForm / JSON).

// InStr is a trimmed string ("" for lists and maps).
func InStr(v any) string {
	switch x := v.(type) {
	case string:
		return strings.TrimSpace(x)
	case nil, *config.Map, []any:
		return ""
	}
	return strings.TrimSpace(php.Str(v))
}

// InList is (array) $v: the values of a list or map, or a one-item list.
func InList(v any) []any {
	switch x := v.(type) {
	case nil:
		return []any{}
	case []any:
		return x
	case *config.Map:
		out := make([]any, 0, x.Len())
		for _, k := range x.Keys {
			out = append(out, x.Vals[k])
		}
		return out
	}
	return []any{v}
}

// InStrings is InList as trimmed strings.
func InStrings(v any) []string {
	l := InList(v)
	out := make([]string, 0, len(l))
	for _, x := range l {
		out = append(out, InStr(x))
	}
	return out
}

// InMap is the value as a map (nil-safe, empty when it is not one).
func InMap(v any) *config.Map {
	if m, ok := v.(*config.Map); ok {
		return m
	}
	return config.NewMap()
}
