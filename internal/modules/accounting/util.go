package accounting

import (
	"bytes"
	"encoding/json"
	"html/template"
	"math"
	"regexp"
	"sort"
	"strconv"
	"strings"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/php"
)

// C is the database connection of the module (ErpSettings::CONN).
const C = "accounting"

func acc() db.Q { return db.On(C) }

// ---------------------------------------------------------------- Ui (App\Core\Support\Ui)

var tones = map[string]string{
	"neutral": "bg-graphite-900/6 text-graphite-800",
	"success": "bg-emerald-50 text-emerald-800",
	"warn":    "bg-amber-50 text-amber-800",
	"danger":  "bg-signal-50 text-signal-800",
	"info":    "bg-sky-50 text-sky-800",
	"violet":  "bg-violet-50 text-violet-800",
}

// badge is Ui::badge.
func badge(text, tone string) string {
	cls, ok := tones[tone]
	if !ok {
		cls = tones["neutral"]
	}
	return `<span class="badge ` + cls + `">` + app.E(text) + `</span>`
}

// person is Ui::person (no picture: the initials).
func person(c *app.Ctx, name, sub, href string) string {
	pic := `<span class="inline-flex size-9 shrink-0 items-center justify-center rounded-md bg-graphite-900/6 text-xs font-semibold text-graphite-700">` + app.E(php.Initials(name)) + `</span>`
	title := `<span class="block truncate font-medium">` + app.E(name) + `</span>`
	if href != "" {
		title = `<a href="` + app.E(c.URL(href)) + `" class="block truncate font-medium hover:text-signal-700">` + app.E(name) + `</a>`
	}
	s := ""
	if sub != "" {
		s = `<span class="block truncate text-xs text-steel">` + app.E(sub) + `</span>`
	}
	return `<div class="flex min-w-0 max-w-[15rem] items-center gap-3">` + pic + `<div class="min-w-0">` + title + s + `</div></div>`
}

// money is ErpListController::money.
func money(v any, cur string) string {
	if v == nil || php.Str(v) == "" {
		return `<span class="text-graphite-400">—</span>`
	}
	s := php.NF(v, 2)
	if cur != "" {
		s += " " + cur
	}
	return `<span class="tabular-nums">` + app.E(s) + `</span>`
}

// dateCell is ErpListController::date.
func dateCell(c *app.Ctx, v any) string {
	if php.Empty(v) {
		return `<span class="text-graphite-400">—</span>`
	}
	return `<span class="tabular-nums text-steel">` + app.E(app.FormatDate(c, v, "d M Y")) + `</span>`
}

func h(s string) template.HTML { return template.HTML(s) }

func dash() string { return `<span class="text-graphite-400">—</span>` }

// orDash is e($v ?: '—').
func orDash(v any) string {
	if php.Empty(v) {
		return "—"
	}
	return app.E(v)
}

// ---------------------------------------------------------------- PHP behaviours

// phpRound is round($v, $places) (half away from zero, with PHP's pre-rounding to 15 significant digits).
func phpRound(v float64, places int) float64 {
	if v == 0 || math.IsInf(v, 0) || math.IsNaN(v) {
		return v
	}
	helper := func(x float64) float64 {
		if x >= 0 {
			return math.Floor(x + 0.5)
		}
		return math.Ceil(x - 0.5)
	}
	pow := func(n int) float64 { return math.Pow(10, float64(n)) }
	abs := func(n int) int {
		if n < 0 {
			return -n
		}
		return n
	}
	precisionPlaces := 14 - int(math.Floor(math.Log10(math.Abs(v))))
	f1 := pow(abs(places))
	var tmp float64
	if precisionPlaces > places && precisionPlaces-15 < places {
		use := precisionPlaces
		if use >= 0 {
			tmp = v * pow(use)
		} else {
			tmp = v / pow(-use)
		}
		tmp = helper(tmp)
		use = places - use
		if use < -60 {
			use = -60
		}
		tmp = tmp / pow(abs(use))
	} else {
		if places >= 0 {
			tmp = v * f1
		} else {
			tmp = v / f1
		}
		if math.Abs(tmp) >= 1e15 {
			return v
		}
	}
	if math.Abs(tmp-helper(tmp)) >= 1e-15 {
		tmp = helper(tmp)
	}
	if places > 0 {
		tmp = tmp / f1
	} else {
		tmp = tmp * f1
	}
	return tmp
}

// nf is number_format($v, $dec, $point, $sep) with PHP's rounding.
func nf(v float64, dec int, point, sep string) string {
	return php.NumberFormat(phpRound(v, dec), dec, point, sep)
}

// likeEscape is str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).
func likeEscape(q string) string {
	return strings.NewReplacer(`\`, `\\`, `%`, `\%`, `_`, `\_`).Replace(q)
}

var nonDigit = regexp.MustCompile(`\D`)

func digits(s string) string { return nonDigit.ReplaceAllString(s, "") }

var ymdRe = regexp.MustCompile(`^\d{4}-\d{2}-\d{2}$`)

// bstr is a PHP 'true' / 'false' for a boolean parameter.
func bstr(b bool) string {
	if b {
		return "true"
	}
	return "false"
}

// ---------------------------------------------------------------- JSON like PHP's json_encode (unescaped unicode and slashes)

// jsonEnc encodes keeping the key order of *config.Map and without escaping <, > and &.
func jsonEnc(v any, pretty bool) string {
	var b bytes.Buffer
	enc := json.NewEncoder(&b)
	enc.SetEscapeHTML(false)
	if pretty {
		enc.SetIndent("", "    ")
	}
	if err := enc.Encode(plainJSON(v)); err != nil {
		return "null"
	}
	return strings.TrimRight(b.String(), "\n")
}

// ordered is a *config.Map that encodes without HTML escaping.
type ordered struct{ m *config.Map }

func (o ordered) MarshalJSON() ([]byte, error) {
	var b bytes.Buffer
	b.WriteByte('{')
	for i, k := range o.m.Keys {
		if i > 0 {
			b.WriteByte(',')
		}
		kb := encNoEsc(k)
		b.Write(kb)
		b.WriteByte(':')
		b.Write(encNoEsc(plainJSON(o.m.Vals[k])))
	}
	b.WriteByte('}')
	return b.Bytes(), nil
}

func encNoEsc(v any) []byte {
	var b bytes.Buffer
	enc := json.NewEncoder(&b)
	enc.SetEscapeHTML(false)
	_ = enc.Encode(v)
	return bytes.TrimRight(b.Bytes(), "\n")
}

// phpFloat is a float the way PHP's json_encode writes it (1.0, 0.5, 1.0e+25).
type phpFloat float64

func (f phpFloat) MarshalJSON() ([]byte, error) {
	x := float64(f)
	if math.IsInf(x, 0) || math.IsNaN(x) {
		return []byte("0"), nil
	}
	a := math.Abs(x)
	var s string
	if a != 0 && (a >= 1e15 || a < 1e-4) {
		s = strconv.FormatFloat(x, 'e', -1, 64)
		if !strings.Contains(s, ".") {
			s = strings.Replace(s, "e", ".0e", 1)
		}
	} else {
		s = strconv.FormatFloat(x, 'f', -1, 64)
		if !strings.Contains(s, ".") {
			s += ".0"
		}
	}
	return []byte(s), nil
}

func plainJSON(v any) any {
	switch x := v.(type) {
	case *config.Map:
		if x == nil {
			return nil
		}
		return ordered{x}
	case float64:
		return phpFloat(x)
	case []any:
		out := make([]any, len(x))
		for i, e := range x {
			out[i] = plainJSON(e)
		}
		return out
	case map[string]any:
		out := make(map[string]any, len(x))
		for k, e := range x {
			out[k] = plainJSON(e)
		}
		return out
	case db.Row:
		out := make(map[string]any, len(x))
		for k, e := range x {
			out[k] = plainJSON(e)
		}
		return out
	case []db.Row:
		out := make([]any, len(x))
		for i, e := range x {
			out[i] = plainJSON(e)
		}
		return out
	case []app.M:
		out := make([]any, len(x))
		for i, e := range x {
			out[i] = plainJSON(e)
		}
		return out
	}
	return v
}

// om builds an ordered map from name/value pairs.
func om(pairs ...any) *config.Map {
	m := config.NewMap()
	for i := 0; i+1 < len(pairs); i += 2 {
		m.Set(php.Str(pairs[i]), pairs[i+1])
	}
	return m
}

// rowsM turns rows into plain maps (values that cross modules: []M).
func rowsM(rows []db.Row) []app.M {
	out := make([]app.M, len(rows))
	for i, r := range rows {
		out[i] = app.M(r)
	}
	return out
}

// column is array_column($rows, $col).
func column(rows []db.Row, col string) []string {
	out := make([]string, 0, len(rows))
	for _, r := range rows {
		out = append(out, r.Str(col))
	}
	return out
}

func sortedKeys(m map[string]string) []string {
	ks := make([]string, 0, len(m))
	for k := range m {
		ks = append(ks, k)
	}
	sort.Strings(ks)
	return ks
}

func inStrs(v string, list []string) bool {
	for _, x := range list {
		if x == v {
			return true
		}
	}
	return false
}

// cfgMap is config($key) as an ordered map.
func cfgMap(key string) *config.Map { return config.MapAt(key) }

func mget(m *config.Map, k string) any {
	v, _ := m.Get(k)
	return v
}

func mstr(m *config.Map, k string) string { return php.Str(mget(m, k)) }

func mmap(m *config.Map, k string) *config.Map {
	x, _ := mget(m, k).(*config.Map)
	return x
}
