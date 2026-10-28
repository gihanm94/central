// Package i18n translates: keys are the English text, lang/<code>.json maps them to another language.
//
//	i18n.T("th", "Sign in")                              → "เข้าสู่ระบบ"
//	i18n.T("th", "Hello :name", "name", "Gihan")
//
// Missing lines fall back to the English key, so nothing ever shows blank.
package i18n

import (
	"encoding/json"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"

	"acme/internal/config"
	"acme/internal/conv"
)

var (
	mu    sync.RWMutex
	lines = map[string]map[string]string{}
	dir   string
)

// Load sets the folder of the language files (lang/).
func Load(d string) {
	mu.Lock()
	dir = d
	lines = map[string]map[string]string{}
	mu.Unlock()
}

// Available is config core.locales: code → name, in order.
func Available() []config.Pair {
	p := config.Pairs("core.locales")
	if len(p) == 0 {
		return []config.Pair{{Key: "en", Label: "English"}}
	}
	return p
}

// Has is true when the code is an available language.
func Has(code string) bool {
	for _, p := range Available() {
		if p.Key == code {
			return true
		}
	}
	return false
}

func table(locale string) map[string]string {
	mu.RLock()
	t, ok := lines[locale]
	mu.RUnlock()
	if ok {
		return t
	}
	t = map[string]string{}
	if raw, err := os.ReadFile(filepath.Join(dir, filepath.Base(locale)+".json")); err == nil {
		_ = json.Unmarshal(raw, &t)
	}
	mu.Lock()
	lines[locale] = t
	mu.Unlock()
	return t
}

// T translates key into locale. params are name/value pairs: T(l, "Hi :name", "name", n).
func T(locale, key string, params ...any) string {
	line, ok := table(locale)[key]
	if !ok {
		line = key
	}
	return Replace(line, params...)
}

// TMap translates with a parameter map (the replacement order is longest name first, so :name never eats :names).
func TMap(locale, key string, params map[string]any) string {
	line, ok := table(locale)[key]
	if !ok {
		line = key
	}
	return ReplaceMap(line, params)
}

// Replace fills :name placeholders (PHP order: in the order given).
func Replace(line string, params ...any) string {
	for i := 0; i+1 < len(params); i += 2 {
		line = strings.ReplaceAll(line, ":"+conv.Str(params[i]), conv.Str(params[i+1]))
	}
	return line
}

// ReplaceMap fills placeholders from a map.
func ReplaceMap(line string, params map[string]any) string {
	keys := make([]string, 0, len(params))
	for k := range params {
		keys = append(keys, k)
	}
	sort.Slice(keys, func(i, j int) bool { return len(keys[i]) > len(keys[j]) })
	for _, k := range keys {
		line = strings.ReplaceAll(line, ":"+k, conv.Str(params[k]))
	}
	return line
}

// Exists is true when locale has its own line for key.
func Exists(locale, key string) bool {
	_, ok := table(locale)[key]
	return ok
}
