// Package config reads the JSON configuration (config/*.json) and keeps the order of every object,
// like the PHP arrays it was converted from: config.Get("core.employee.statuses") lists the statuses in file order.
// config.json (written by the installer, never in git) sits at the top level: config.Get("app.url"), config.Get("db.host").
package config

import (
	"bytes"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"sync"
)

// Map is an object that remembers its key order.
type Map struct {
	Keys []string
	Vals map[string]any
}

func NewMap() *Map { return &Map{Vals: map[string]any{}} }

func (m *Map) Get(k string) (any, bool) {
	if m == nil {
		return nil, false
	}
	v, ok := m.Vals[k]
	return v, ok
}

func (m *Map) Set(k string, v any) {
	if _, ok := m.Vals[k]; !ok {
		m.Keys = append(m.Keys, k)
	}
	m.Vals[k] = v
}

func (m *Map) Delete(k string) {
	if _, ok := m.Vals[k]; !ok {
		return
	}
	delete(m.Vals, k)
	for i, x := range m.Keys {
		if x == k {
			m.Keys = append(m.Keys[:i], m.Keys[i+1:]...)
			break
		}
	}
}

func (m *Map) Len() int {
	if m == nil {
		return 0
	}
	return len(m.Keys)
}

// MarshalJSON keeps the order.
func (m *Map) MarshalJSON() ([]byte, error) {
	var b bytes.Buffer
	b.WriteByte('{')
	for i, k := range m.Keys {
		if i > 0 {
			b.WriteByte(',')
		}
		kb, _ := json.Marshal(k)
		b.Write(kb)
		b.WriteByte(':')
		vb, err := json.Marshal(m.Vals[k])
		if err != nil {
			return nil, err
		}
		b.Write(vb)
	}
	b.WriteByte('}')
	return b.Bytes(), nil
}

// Decode parses JSON into Map / []any / string / float64 / bool / nil, keeping object order.
func Decode(data []byte) (any, error) {
	d := json.NewDecoder(bytes.NewReader(data))
	d.UseNumber()
	v, err := decodeValue(d)
	if err != nil {
		return nil, err
	}
	return v, nil
}

func decodeValue(d *json.Decoder) (any, error) {
	t, err := d.Token()
	if err != nil {
		return nil, err
	}
	switch x := t.(type) {
	case json.Delim:
		switch x {
		case '{':
			m := NewMap()
			for d.More() {
				kt, err := d.Token()
				if err != nil {
					return nil, err
				}
				v, err := decodeValue(d)
				if err != nil {
					return nil, err
				}
				m.Set(kt.(string), v)
			}
			_, err := d.Token()
			return m, err
		case '[':
			list := []any{}
			for d.More() {
				v, err := decodeValue(d)
				if err != nil {
					return nil, err
				}
				list = append(list, v)
			}
			_, err := d.Token()
			return list, err
		}
	case json.Number:
		if i, err := x.Int64(); err == nil {
			return int(i), nil
		}
		f, _ := x.Float64()
		return f, nil
	}
	return t, nil
}

var (
	mu    sync.RWMutex
	items = NewMap()
	dir   string
)

// Load reads every *.json in dir; config.json's keys go to the top level.
func Load(d string) error {
	mu.Lock()
	defer mu.Unlock()
	dir = d
	items = NewMap()
	files, _ := filepath.Glob(filepath.Join(d, "*.json"))
	sort.Strings(files)
	var top *Map
	for _, f := range files {
		raw, err := os.ReadFile(f)
		if err != nil {
			return err
		}
		v, err := Decode(raw)
		if err != nil {
			return fmt.Errorf("%s: %w", f, err)
		}
		name := strings.TrimSuffix(filepath.Base(f), ".json")
		if name == "config" {
			top, _ = v.(*Map)
			continue
		}
		items.Set(name, v)
	}
	if top != nil {
		for _, k := range top.Keys {
			items.Set(k, top.Vals[k])
		}
	}
	return nil
}

// Dir is the folder Load read.
func Dir() string { return dir }

// Get returns the value at a dotted key, or nil.
func Get(key string) any {
	mu.RLock()
	defer mu.RUnlock()
	return lookup(items, key)
}

func lookup(root any, key string) any {
	v := root
	for _, part := range strings.Split(key, ".") {
		switch x := v.(type) {
		case *Map:
			nv, ok := x.Vals[part]
			if !ok {
				return nil
			}
			v = nv
		case []any:
			i, err := strconv.Atoi(part)
			if err != nil || i < 0 || i >= len(x) {
				return nil
			}
			v = x[i]
		default:
			return nil
		}
	}
	return v
}

// Set replaces the value at a dotted key (missing objects are created).
func Set(key string, value any) {
	mu.Lock()
	defer mu.Unlock()
	parts := strings.Split(key, ".")
	m := items
	for _, p := range parts[:len(parts)-1] {
		next, ok := m.Vals[p].(*Map)
		if !ok {
			next = NewMap()
			m.Set(p, next)
		}
		m = next
	}
	m.Set(parts[len(parts)-1], value)
}

func String(key, def string) string { return AsString(Get(key), def) }
func Int(key string, def int) int     { return AsInt(Get(key), def) }
func Bool(key string, def bool) bool  { return AsBool(Get(key), def) }

// Strings returns a list of strings (a JSON array), or the keys of an object.
func Strings(key string) []string { return AsStrings(Get(key)) }

// Pairs returns an object as ordered key/label pairs (a list gives value = label).
func Pairs(key string) []Pair { return AsPairs(Get(key)) }

func MapAt(key string) *Map {
	m, _ := Get(key).(*Map)
	if m == nil {
		return NewMap()
	}
	return m
}

type Pair struct{ Key, Label string }

func AsString(v any, def string) string {
	switch x := v.(type) {
	case nil:
		return def
	case string:
		return x
	case int:
		return strconv.Itoa(x)
	case float64:
		return strconv.FormatFloat(x, 'f', -1, 64)
	case bool:
		if x {
			return "1"
		}
		return ""
	}
	return fmt.Sprint(v)
}

func AsInt(v any, def int) int {
	switch x := v.(type) {
	case int:
		return x
	case float64:
		return int(x)
	case string:
		if i, err := strconv.Atoi(strings.TrimSpace(x)); err == nil {
			return i
		}
	case bool:
		if x {
			return 1
		}
		return 0
	}
	return def
}

func AsBool(v any, def bool) bool {
	switch x := v.(type) {
	case nil:
		return def
	case bool:
		return x
	case int:
		return x != 0
	case string:
		return x != "" && x != "0"
	}
	return def
}

func AsStrings(v any) []string {
	switch x := v.(type) {
	case []any:
		out := make([]string, 0, len(x))
		for _, e := range x {
			out = append(out, AsString(e, ""))
		}
		return out
	case *Map:
		return append([]string(nil), x.Keys...)
	}
	return nil
}

func AsPairs(v any) []Pair {
	switch x := v.(type) {
	case []any:
		out := make([]Pair, 0, len(x))
		for _, e := range x {
			s := AsString(e, "")
			out = append(out, Pair{s, s})
		}
		return out
	case *Map:
		out := make([]Pair, 0, len(x.Keys))
		for _, k := range x.Keys {
			out = append(out, Pair{k, AsString(x.Vals[k], "")})
		}
		return out
	}
	return nil
}
