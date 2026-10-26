package app

import (
	"bytes"
	"crypto/md5"
	"encoding/json"
	"fmt"
	"html/template"
	"io/fs"
	"os"
	"path/filepath"
	"reflect"
	"regexp"
	"sort"
	"strings"
	"sync"
	"time"

	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/i18n"
	"acme/internal/php"
)

/*
Templates live in web/views/<name>.gohtml, named by their path ("members/index", "partials/select").
Every file is one template in one shared set, so a page can include any other with
{{template "partials/avatar" (dict "name" .user.name "size" "size-9")}}.

A page is rendered first, then its layout (data "layout": "" for none, default "layouts/app") with the
page HTML in .content — the same order as the PHP View::render. A page sets layout variables with
{{set "title" (__ "Members")}} (they are merged into the layout data).

Funcs that need the request (__ can url auth old …) are bound per request: each pooled copy of the template set
has a state holder that points at the current request while it executes.
*/

type renderState struct {
	c      *Ctx
	layout M
}

type tset struct {
	t  *template.Template
	st *renderState
}

var (
	viewMu   sync.Mutex
	viewBase *template.Template
	viewPool *sync.Pool
	viewSum  string
	viewDir  string
)

// ViewsDir is web/views.
func ViewsDir() string {
	if viewDir != "" {
		return viewDir
	}
	return filepath.Join(BasePath, "web", "views")
}

// LoadViews parses every template (again when the files changed and app.env is local).
func LoadViews() error {
	viewMu.Lock()
	defer viewMu.Unlock()
	return loadViewsLocked()
}

func loadViewsLocked() error {
	base := template.New("").Funcs(funcMap(&renderState{}))
	var names []string
	err := filepath.WalkDir(ViewsDir(), func(p string, d fs.DirEntry, err error) error {
		if err != nil || d.IsDir() || !strings.HasSuffix(p, ".gohtml") {
			return err
		}
		rel, _ := filepath.Rel(ViewsDir(), p)
		names = append(names, filepath.ToSlash(strings.TrimSuffix(rel, ".gohtml")))
		return nil
	})
	if err != nil {
		return err
	}
	sort.Strings(names)
	h := md5.New()
	for _, n := range names {
		raw, err := os.ReadFile(filepath.Join(ViewsDir(), n+".gohtml"))
		if err != nil {
			return err
		}
		h.Write([]byte(n))
		h.Write(raw)
		if _, err := base.New(n).Parse(string(raw)); err != nil {
			return fmt.Errorf("view %s: %w", n, err)
		}
	}
	viewBase = base
	viewSum = fmt.Sprintf("%x", h.Sum(nil))
	b := base
	viewPool = &sync.Pool{New: func() any {
		st := &renderState{}
		t := template.Must(b.Clone())
		t.Funcs(funcMap(st))
		return &tset{t: t, st: st}
	}}
	return nil
}

var lastViewCheck time.Time

func pool() *sync.Pool {
	viewMu.Lock()
	defer viewMu.Unlock()
	if viewPool == nil || (config.String("app.env", "production") == "local" && time.Since(lastViewCheck) > time.Second) {
		lastViewCheck = time.Now()
		if viewPool == nil || viewsChanged() {
			if err := loadViewsLocked(); err != nil {
				panic(err)
			}
		}
	}
	return viewPool
}

func viewsChanged() bool {
	h := md5.New()
	var names []string
	_ = filepath.WalkDir(ViewsDir(), func(p string, d fs.DirEntry, err error) error {
		if err == nil && !d.IsDir() && strings.HasSuffix(p, ".gohtml") {
			rel, _ := filepath.Rel(ViewsDir(), p)
			names = append(names, filepath.ToSlash(strings.TrimSuffix(rel, ".gohtml")))
		}
		return nil
	})
	sort.Strings(names)
	for _, n := range names {
		raw, _ := os.ReadFile(filepath.Join(ViewsDir(), n+".gohtml"))
		h.Write([]byte(n))
		h.Write(raw)
	}
	return fmt.Sprintf("%x", h.Sum(nil)) != viewSum
}

func execute(c *Ctx, name string, data M) (string, M) {
	p := pool()
	ts := p.Get().(*tset)
	ts.st.c = c
	ts.st.layout = M{}
	defer func() {
		ts.st.c = nil
		ts.st.layout = nil
		p.Put(ts)
	}()
	var buf bytes.Buffer
	if err := ts.t.ExecuteTemplate(&buf, name, data); err != nil {
		panic(fmt.Errorf("view %s: %w", name, err))
	}
	return buf.String(), ts.st.layout
}

// shared adds what every view sees (View::share).
func shared(c *Ctx, data M) M {
	out := M{}
	for k, v := range c.Shared {
		out[k] = v
	}
	if _, ok := out["branding"]; !ok {
		out["branding"] = Branding(c)
	}
	for k, v := range data {
		out[k] = v
	}
	return out
}

// Render is View::render: the page, then its layout with .content.
func Render(c *Ctx, name string, data M) string {
	d := shared(c, data)
	content, setVars := execute(c, name, d)
	for k, v := range setVars {
		d[k] = v
	}
	layout := "layouts/app"
	if l, ok := d["layout"]; ok {
		layout = php.Str(l)
	}
	if layout == "" {
		return content
	}
	d["content"] = template.HTML(content)
	out, _ := execute(c, layout, d)
	return out
}

// RenderPartial is View::partial: the template only.
func RenderPartial(c *Ctx, name string, data M) string {
	out, _ := execute(c, name, shared(c, data))
	return out
}

// ---------------------------------------------------------------- funcs

// Opt is one choice of a dropdown (select_field): value, label, optional sub-line and picture.
type Opt struct {
	Value string
	Label string
	Sub   string
	Img   string
}

// Opts builds options from value/label pairs: Opts("a", "Apple", "b", "Banana").
func Opts(pairs ...string) []Opt {
	out := make([]Opt, 0, len(pairs)/2)
	for i := 0; i+1 < len(pairs); i += 2 {
		out = append(out, Opt{Value: pairs[i], Label: pairs[i+1]})
	}
	return out
}

// OptsFromPairs turns config pairs into options, translating the labels.
func (c *Ctx) OptsFromPairs(p []config.Pair) []Opt {
	out := make([]Opt, 0, len(p))
	for _, x := range p {
		out = append(out, Opt{Value: x.Key, Label: c.T(x.Label)})
	}
	return out
}

// OptLabel finds the label of a value ("" when missing).
func OptLabel(opts []Opt, v string) (string, bool) {
	for _, o := range opts {
		if o.Value == v {
			return o.Label, true
		}
	}
	return "", false
}

var iconPaths map[string]string
var iconOnce sync.Once

// Icon is icon($name, $class): an outline Heroicon.
func Icon(name, class string) template.HTML {
	iconOnce.Do(func() {
		iconPaths = map[string]string{}
		raw, err := os.ReadFile(filepath.Join(BasePath, "web", "icons.json"))
		if err == nil {
			_ = json.Unmarshal(raw, &iconPaths)
		}
	})
	d, ok := iconPaths[name]
	if !ok {
		d = iconPaths["grid"]
	}
	if class == "" {
		class = "size-5"
	}
	return template.HTML(`<svg class="` + template.HTMLEscapeString(class) + `" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="` + d + `"/></svg>`)
}

func toInt(v any) int       { return php.Int(v) }
func toFloat(v any) float64 { return php.Float(v) }

func funcMap(st *renderState) template.FuncMap {
	c := func() *Ctx { return st.c }
	return template.FuncMap{
		// language
		"__": func(key string, params ...any) string { return c().T(key, params...) },
		"locale": func() string { return c().Locale() },
		"locales": func() []config.Pair { return i18n.Available() },
		// urls
		"url": func(path string, query ...any) string { return c().URL(path, query...) },
		"asset": func(path string) string { return c().Asset(path) },
		"uploadURL": func(p any) string { return c().UploadURL(p) },
		"path": func() string { return c().Path() },
		"query": func(k string) string { return c().Query(k) },
		"queryMap": func() map[string]string { return c().QueryMap() },
		"withQuery": func(set ...any) string { // current query with some values replaced: withQuery "page" 2
			q := c().QueryMap()
			m := map[string]any{}
			for k, v := range q {
				m[k] = v
			}
			for i := 0; i+1 < len(set); i += 2 {
				m[php.Str(set[i])] = set[i+1]
			}
			return buildQuery(m)
		},
		// auth
		"auth": func() *CurrentUser { return c().User() },
		"can": func(res string, act ...string) bool {
			a := "view"
			if len(act) > 0 {
				a = act[0]
			}
			return c().Can(res, a)
		},
		"isAdmin": func() bool { return c().User().IsAdmin() },
		// forms
		"csrfToken": func() string { return c().Sess.CSRF() },
		"csrfField": func() template.HTML {
			return template.HTML(`<input type="hidden" name="_token" value="` + template.HTMLEscapeString(c().Sess.CSRF()) + `">`)
		},
		"old": func(k string, def ...any) any {
			if v, ok := c().Sess.Old(k); ok {
				return v
			}
			if len(def) > 0 {
				return def[0]
			}
			return ""
		},
		"errorFor": func(k string) string { return c().Sess.Errors().Get(k) },
		"errors": func() *Errors { return c().Sess.Errors() },
		"fieldError": func(k string) template.HTML {
			if m := c().Sess.Errors().Get(k); m != "" {
				return template.HTML(`<p class="error">` + template.HTMLEscapeString(m) + `</p>`)
			}
			return ""
		},
		"flash": func(k string) string { return php.Str(c().Sess.FlashGet(k)) },
		"select": func(name string, options []Opt, value any, o ...any) template.HTML {
			return SelectField(c(), name, options, php.Str(value), dictOf(o))
		},
		"partial": func(name string, data ...any) template.HTML {
			return template.HTML(RenderPartial(c(), name, dictOf(data)))
		},
		"set": func(k string, v any) string { st.layout[k] = v; return "" },
		// formatting
		"icon": func(name string, class ...string) template.HTML {
			cl := "size-5"
			if len(class) > 0 {
				cl = class[0]
			}
			return Icon(name, cl)
		},
		"formatDate": func(v any, f ...string) string {
			ff := "d M Y"
			if len(f) > 0 {
				ff = f[0]
			}
			return FormatDate(c(), v, ff)
		},
		"timeAgo": func(v any) string { return TimeAgo(c(), v) },
		"date": func(f string, v ...any) string {
			if len(v) == 0 {
				return php.Date(f, php.Now())
			}
			return php.DateOf(f, v[0])
		},
		"initials": func(s any) string { return php.Initials(php.Str(s)) },
		"strLimit": func(s any, n ...int) string {
			l := 60
			if len(n) > 0 {
				l = n[0]
			}
			return php.Limit(php.Str(s), l)
		},
		"numberClean": func(n any) string { return php.NumberClean(n) },
		"nf": func(n any, dec ...int) string {
			d := 0
			if len(dec) > 0 {
				d = dec[0]
			}
			return php.NF(n, d)
		},
		"ucfirst": func(s any) string { return php.Ucfirst(php.Str(s)) },
		"lower": func(s any) string { return strings.ToLower(php.Str(s)) },
		"upper": func(s any) string { return strings.ToUpper(php.Str(s)) },
		"trim": func(s any) string { return strings.TrimSpace(php.Str(s)) },
		"replace": func(s any, old, new string) string { return strings.ReplaceAll(php.Str(s), old, new) },
		"contains": func(s any, sub string) bool { return strings.Contains(php.Str(s), sub) },
		"hasPrefix": func(s any, p string) bool { return strings.HasPrefix(php.Str(s), p) },
		"hasSuffix": func(s any, p string) bool { return strings.HasSuffix(php.Str(s), p) },
		"join": func(list any, sep string) string { return strings.Join(toStrings(list), sep) },
		"split": func(s any, sep string) []string { return strings.Split(php.Str(s), sep) },
		"substr": func(s any, start int, length ...int) string {
			l := -1
			if len(length) > 0 {
				l = length[0]
			}
			return php.Substr(php.Str(s), start, l)
		},
		"len": func(v any) int { return lenOf(v) },
		"setting": func(k string, def ...string) string {
			d := ""
			if len(def) > 0 {
				d = def[0]
			}
			return Setting(k, d)
		},
		"config": func(k string) any { return config.Get(k) },
		"enumLabel": func(group string, v any) string { return EnumLabel(c(), group, php.Str(v)) },
		"activityText": func(r db.Row) string { return ActivityText(c(), r) },
		"enumOptions": func(group string) []Opt { return EnumOptions(c(), group) },
		// data
		"dict": func(kv ...any) M { return dictOf(kv) },
		"list": func(v ...any) []any { return v },
		"strs": func(v ...string) []string { return v },
		"opts": func(pairs ...string) []Opt { return Opts(pairs...) },
		"json": func(v any) template.JS {
			b, _ := json.Marshal(v)
			return template.JS(b)
		},
		"jsonAttr": func(v any) string {
			b, _ := json.Marshal(v)
			return string(b)
		},
		"raw": func(s any) template.HTML { return template.HTML(php.Str(s)) },
		"attr": func(s any) template.HTMLAttr { return template.HTMLAttr(php.Str(s)) },
		"safeURL": func(s any) template.URL { return template.URL(php.Str(s)) },
		"css": func(s any) template.CSS { return template.CSS(php.Str(s)) },
		"str": func(v any) string { return php.Str(v) },
		"int": toInt,
		"float": toFloat,
		"bool": func(v any) bool { return php.Bool(v) },
		"empty": func(v any) bool { return php.Empty(v) },
		"blank": func(v any) bool { return php.Blank(v) },
		"filled": func(v any) bool { return !php.Blank(v) },
		"default": func(def, v any) any {
			if php.Empty(v) {
				return def
			}
			return v
		},
		"coalesce": func(v ...any) any { return php.Coalesce(v...) },
		"or": func(v ...any) any { return php.Or(v...) },
		"ternary": func(cond bool, a, b any) any {
			if cond {
				return a
			}
			return b
		},
		"eqs": func(a, b any) bool { return php.Str(a) == php.Str(b) },
		"in": func(v any, list ...any) bool {
			s := php.Str(v)
			for _, x := range list {
				for _, y := range toStrings(x) {
					if y == s {
						return true
					}
				}
			}
			return false
		},
		"add": func(a any, b ...any) any {
			if _, ok := a.(float64); ok {
				f := toFloat(a)
				for _, x := range b {
					f += toFloat(x)
				}
				return f
			}
			n := toInt(a)
			for _, x := range b {
				n += toInt(x)
			}
			return n
		},
		"sub": func(a, b any) int { return toInt(a) - toInt(b) },
		"mul": func(a, b any) float64 { return toFloat(a) * toFloat(b) },
		"div": func(a, b any) float64 {
			if toFloat(b) == 0 {
				return 0
			}
			return toFloat(a) / toFloat(b)
		},
		"mod": func(a, b int) int {
			if b == 0 {
				return 0
			}
			return a % b
		},
		"gt": func(a, b any) bool { return toFloat(a) > toFloat(b) },
		"lt": func(a, b any) bool { return toFloat(a) < toFloat(b) },
		"seq": func(from, to int) []int {
			var out []int
			for i := from; i <= to; i++ {
				out = append(out, i)
			}
			return out
		},
		"get": func(m any, k any) any { return getKey(m, k) },
		"has": func(m any, k any) bool { return hasKey(m, k) },
		"nav": func() M { return NavData(c()) },
		"ctx": func() *Ctx { return c() },
		"rangeKeep": func(action string, keep map[string]any, selects []RangeSelect) M { return rangeKeep(c(), action, keep, selects) },
		"mergeDict": func(a, b M) M {
			out := M{}
			for k, v := range a {
				out[k] = v
			}
			for k, v := range b {
				out[k] = v
			}
			return out
		},
		"deref": func(b *bool) bool { return b != nil && *b },
		"withQueryMap": func(set ...any) map[string]any { // current query with some values replaced ("" removes)
			m := map[string]any{}
			for k, v := range c().QueryMap() {
				m[k] = v
			}
			for i := 0; i+1 < len(set); i += 2 {
				m[php.Str(set[i])] = set[i+1]
			}
			return m
		},
		"pager": func(total, perPage, page any) Pager { return pagerFor(c(), php.Int(total), php.Int(perPage), php.Int(page)) },
		"pickerData": func(f Field, picked []string) pickerView { return pickerData(f, picked) },
		"accessRequests": func() bool { return AccessRequestsEnabled() },
		"debugBanner": func() bool {
			return config.String("app.env", "") == "local" && c().User().IsAdmin() && !php.InList(c().Host(), "localhost", "127.0.0.1", "::1")
		},
		"cookie": func(k string) string { return c().Cookie(k) },
		"now": func() time.Time { return php.Now() },
		"year": func() int { return php.Now().Year() },
	}
}

func dictOf(kv []any) M {
	if len(kv) == 1 {
		if m, ok := kv[0].(M); ok {
			return m
		}
	}
	m := M{}
	for i := 0; i+1 < len(kv); i += 2 {
		m[php.Str(kv[i])] = kv[i+1]
	}
	return m
}

func toStrings(v any) []string {
	switch x := v.(type) {
	case []string:
		return x
	case []any:
		out := make([]string, len(x))
		for i, e := range x {
			out[i] = php.Str(e)
		}
		return out
	case nil:
		return nil
	}
	rv := reflect.ValueOf(v)
	if rv.Kind() == reflect.Slice {
		out := make([]string, rv.Len())
		for i := range out {
			out[i] = php.Str(rv.Index(i).Interface())
		}
		return out
	}
	return []string{php.Str(v)}
}

func lenOf(v any) int {
	switch x := v.(type) {
	case nil:
		return 0
	case string:
		return php.Len(x)
	case *Errors:
		return x.Len()
	case *config.Map:
		return x.Len()
	}
	rv := reflect.ValueOf(v)
	switch rv.Kind() {
	case reflect.Slice, reflect.Map, reflect.Array:
		return rv.Len()
	}
	return 0
}

func getKey(m any, k any) any {
	switch x := m.(type) {
	case nil:
		return nil
	case M:
		return x[php.Str(k)]
	case db.Row:
		return x[php.Str(k)]
	case map[string]string:
		return x[php.Str(k)]
	case map[string]bool:
		return x[php.Str(k)]
	case map[int]any:
		return x[php.Int(k)]
	case *config.Map:
		v, _ := x.Get(php.Str(k))
		return v
	case []any:
		i := php.Int(k)
		if i >= 0 && i < len(x) {
			return x[i]
		}
		return nil
	}
	rv := reflect.ValueOf(m)
	switch rv.Kind() {
	case reflect.Map:
		kv := reflect.ValueOf(k)
		if kv.Type().ConvertibleTo(rv.Type().Key()) {
			v := rv.MapIndex(kv.Convert(rv.Type().Key()))
			if v.IsValid() {
				return v.Interface()
			}
		} else if rv.Type().Key().Kind() == reflect.String {
			v := rv.MapIndex(reflect.ValueOf(php.Str(k)).Convert(rv.Type().Key()))
			if v.IsValid() {
				return v.Interface()
			}
		} else if rv.Type().Key().Kind() == reflect.Int {
			v := rv.MapIndex(reflect.ValueOf(php.Int(k)))
			if v.IsValid() {
				return v.Interface()
			}
		}
	case reflect.Slice:
		i := php.Int(k)
		if i >= 0 && i < rv.Len() {
			return rv.Index(i).Interface()
		}
	}
	return nil
}

func hasKey(m any, k any) bool {
	switch x := m.(type) {
	case M:
		_, ok := x[php.Str(k)]
		return ok
	case db.Row:
		_, ok := x[php.Str(k)]
		return ok
	case *config.Map:
		_, ok := x.Get(php.Str(k))
		return ok
	}
	return getKey(m, k) != nil
}

// ---------------------------------------------------------------- helpers shared with Go code

// FormatDate is format_date(): month and day names in the current language; "—" when empty.
func FormatDate(c *Ctx, v any, format string) string {
	if php.Empty(v) {
		return "—"
	}
	out := php.DateOf(format, v)
	if out == "" {
		out = php.Date(format, php.Unix(0))
	}
	if c.Locale() == "en" {
		return out
	}
	return monthDayRe.ReplaceAllStringFunc(out, func(m string) string { return c.T(m) })
}

// TimeAgo is time_ago().
func TimeAgo(c *Ctx, v any) string {
	if php.Empty(v) {
		return c.T("never")
	}
	diff := time.Now().Unix() - php.StrToTime(v)
	if diff < 0 {
		return c.T("in :time", "time", TimeSpan(c, int(-diff)))
	}
	if diff < 45 {
		return c.T("just now")
	}
	return c.T(":time ago", "time", TimeSpan(c, int(diff)))
}

// TimeSpan is time_span().
func TimeSpan(c *Ctx, s int) string {
	for _, u := range []struct {
		sec  int
		unit string
	}{{31536000, "year"}, {2592000, "month"}, {604800, "week"}, {86400, "day"}, {3600, "hour"}, {60, "minute"}} {
		if s >= u.sec {
			n := s / u.sec
			if n > 1 {
				return c.T(":n "+u.unit+"s", "n", n)
			}
			return c.T(":n "+u.unit, "n", n)
		}
	}
	return c.T(":n seconds", "n", s)
}

// EnumLabel is enum_label(): employment data labels.
func EnumLabel(c *Ctx, group, v string) string {
	if v == "" {
		return "—"
	}
	val := config.Get("core.employee." + group)
	if list, ok := val.([]any); ok {
		_ = list
		if v == "OTHER" {
			return c.T("Other")
		}
		return v
	}
	if m, ok := val.(*config.Map); ok {
		if l, ok := m.Get(v); ok {
			return c.T(php.Str(l))
		}
	}
	return c.T(v)
}

// EnumOptions is enum_options().
func EnumOptions(c *Ctx, group string) []Opt {
	val := config.Get("core.employee." + group)
	var out []Opt
	if list, ok := val.([]any); ok {
		for _, x := range list {
			s := php.Str(x)
			l := s
			if s == "OTHER" {
				l = c.T("Other")
			}
			out = append(out, Opt{Value: s, Label: l})
		}
		return out
	}
	for _, p := range config.AsPairs(val) {
		out = append(out, Opt{Value: p.Key, Label: c.T(p.Label)})
	}
	return out
}

func md5Sum(s string) [16]byte { return md5.Sum([]byte(s)) }

var monthDayRe = regexp.MustCompile(`\b(January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Oct|Nov|Dec|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\b`)

// SelectField is select_field(): the Tailwind dropdown that posts like a <select>.
// o: placeholder, id, submit, disabled, class, search, tones (map[string]string), size ("sm"), wrap, aria, required.
func SelectField(c *Ctx, name string, options []Opt, value string, o M) template.HTML {
	id := php.Str(o["id"])
	if id == "" {
		id = "sel-" + nonWord.ReplaceAllString(name, "-") + "-" + randHex(3)[:5]
	}
	rich := false
	for _, op := range options {
		if op.Sub != "" || op.Img != "" {
			rich = true
			break
		}
	}
	if r, ok := o["rich"]; ok {
		rich = php.Bool(r)
	}
	label, has := OptLabel(options, value)
	hasValue := value != "" && has
	var selected Opt
	for _, op := range options {
		if op.Value == value {
			selected = op
		}
	}
	if !hasValue {
		if p, ok := o["placeholder"]; ok {
			label = php.Str(p)
		} else {
			label = c.T("Choose…")
		}
	}
	tones, _ := o["tones"].(map[string]string)
	search := rich || len(options) > 8
	if s, ok := o["search"]; ok {
		search = php.Bool(s)
	}
	sm := php.Str(o["size"]) == "sm"
	base := php.Str(o["class"])
	if base == "" {
		switch {
		case sm:
			base = "rounded-md px-2 py-1 text-xs ring-1 ring-graphite-900/15 bg-white"
		case rich:
			base = "input h-auto min-h-8 py-1"
		default:
			base = "input"
		}
	}
	_, hasPlaceholder := o["placeholder"]
	return template.HTML(RenderPartial(c, "partials/select", M{
		"name": name, "options": options, "value": value, "o": o, "id": id, "rich": rich, "hasValue": hasValue, "label": label,
		"selected": selected, "tones": tones, "search": search, "sm": sm, "base": base, "hasPlaceholder": hasPlaceholder,
		"placeholder": php.Str(o["placeholder"]), "required": php.Truthy(o["required"]), "tone": tones[value],
	}))
}

var nonWord = regexp.MustCompile(`\W+`)

func regexpMust(s string) *regexp.Regexp { return regexp.MustCompile(s) }

func jsonString(s string) string {
	b, _ := json.Marshal(s)
	return strings.NewReplacer("<", "\\u003c", ">", "\\u003e", "&", "\\u0026").Replace(string(b))
}
