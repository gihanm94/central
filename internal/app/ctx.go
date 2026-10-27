package app

import (
	"bytes"
	"encoding/json"
	"html/template"
	"io"
	"mime/multipart"
	"net"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"

	"acme/internal/config"
	"acme/internal/i18n"
	"acme/internal/php"

	"github.com/go-chi/chi/v5"
)

// BasePath is the project folder (config/, lang/, public/, storage/, web/views …).
var BasePath = "."

// M is view / JSON data.
type M = map[string]any

// Ctx is one request: the PHP superglobals, the session, the signed-in person and the response helpers.
// Response helpers that finish the request (View, Redirect, Back, JSON, Download …) stop the handler,
// like the PHP code's redirect() / json_response() / exit.
type Ctx struct {
	W    http.ResponseWriter
	R    *http.Request
	Sess *Session

	locale       string
	user         *CurrentUser
	userResolved bool
	input        *config.Map
	jsonBody     bool
	status       int
	// API: the token-authenticated request has no session
	API bool
	// Shared view data (View::share)
	Shared M
	// Module is the module this URL belongs to ("" = core)
	Module string
}

func cfgInt(k string, d int) int { return config.Int(k, d) }

// ---------------------------------------------------------------- request

func (c *Ctx) Method() string { return strings.ToUpper(c.R.Method) }

// Path is the request path without the base folder, always starting with / (Request::path()).
func (c *Ctx) Path() string {
	p := c.R.URL.Path
	if pre := BasePrefix(); pre != "" && strings.HasPrefix(p, pre) {
		p = p[len(pre):]
	}
	p = "/" + strings.Trim(p, "/")
	return p
}

// Param is a route parameter ({id}).
func (c *Ctx) Param(name string) string {
	v := chi.URLParam(c.R, name)
	if u, err := url.PathUnescape(v); err == nil {
		return u
	}
	return v
}

// ParamInt is a numeric route parameter.
func (c *Ctx) ParamInt(name string) int { return php.Int(c.Param(name)) }

// IsJSON: the client sent or wants JSON.
func (c *Ctx) IsJSON() bool {
	return strings.Contains(c.R.Header.Get("Content-Type"), "application/json") || strings.Contains(c.R.Header.Get("Accept"), "application/json")
}

const maxForm = 64 << 20

// All is Request::all(): GET and POST fields merged (POST wins), or the JSON body, as PHP-style nested arrays.
func (c *Ctx) All() *config.Map {
	if c.input != nil {
		return c.input
	}
	ct := c.R.Header.Get("Content-Type")
	if strings.Contains(ct, "application/json") {
		raw, _ := io.ReadAll(io.LimitReader(c.R.Body, maxForm))
		c.R.Body = io.NopCloser(bytes.NewReader(raw))
		v, err := config.Decode(raw)
		m, _ := v.(*config.Map)
		if err != nil || m == nil {
			m = config.NewMap()
		}
		c.input = m
		c.jsonBody = true
		return m
	}
	q := c.R.URL.Query()
	merged := ParseForm(q, formOrder(c.R.URL.RawQuery))
	if c.R.Method == http.MethodPost {
		var order []string
		if strings.HasPrefix(ct, "multipart/form-data") {
			_ = c.R.ParseMultipartForm(maxForm)
		} else {
			raw, _ := io.ReadAll(io.LimitReader(c.R.Body, maxForm))
			c.R.Body = io.NopCloser(bytes.NewReader(raw))
			order = formOrder(string(raw))
			_ = c.R.ParseForm()
		}
		post := c.R.PostForm
		if c.R.MultipartForm != nil {
			post = url.Values(c.R.MultipartForm.Value)
			order = nil
			for k := range post {
				order = append(order, k)
			}
			sort.Strings(order)
		}
		pm := ParseForm(post, order)
		for _, k := range pm.Keys {
			merged.Set(k, pm.Vals[k])
		}
	}
	c.input = merged
	return merged
}

// Replace sets what All() returns (the API hands controllers only the fields it allows).
func (c *Ctx) Replace(m *config.Map) { c.input = m }

// InputAny is the raw value of a field (string, []any, *config.Map or nil).
func (c *Ctx) InputAny(k string) any { v, _ := c.All().Get(k); return v }

// Has is array_key_exists($k, Request::all()).
func (c *Ctx) Has(k string) bool { _, ok := c.All().Get(k); return ok }

// Input is Request::input($k): trimmed string ("" when missing).
func (c *Ctx) Input(k string, def ...string) string {
	v, ok := c.All().Get(k)
	if !ok && len(def) > 0 {
		return def[0]
	}
	return InStr(v)
}

// Inputs is (array) Request::input($k) as strings.
func (c *Ctx) Inputs(k string) []string { return InStrings(c.InputAny(k)) }

// Bool is Request::boolean($k).
func (c *Ctx) Bool(k string) bool { return php.Bool(c.InputAny(k)) }

// Query is Request::query($k): a GET field, trimmed.
func (c *Ctx) Query(k string, def ...string) string {
	q := c.R.URL.Query()
	if _, ok := q[k]; !ok {
		if len(def) > 0 {
			return def[0]
		}
		return ""
	}
	return strings.TrimSpace(q.Get(k))
}

// QueryHas is isset($_GET[$k]).
func (c *Ctx) QueryHas(k string) bool { _, ok := c.R.URL.Query()[k]; return ok }

// QueryMap is $_GET as a map of single values (for links that keep the filters).
func (c *Ctx) QueryMap() map[string]string {
	out := map[string]string{}
	for k, v := range c.R.URL.Query() {
		if len(v) > 0 {
			out[k] = v[0]
		}
	}
	return out
}

// File is Request::file($k): an uploaded file, or nil.
func (c *Ctx) File(k string) *multipart.FileHeader {
	c.All()
	if c.R.MultipartForm == nil {
		return nil
	}
	if fs := c.R.MultipartForm.File[k]; len(fs) > 0 && fs[0].Size >= 0 && fs[0].Filename != "" {
		return fs[0]
	}
	return nil
}

// Files are all the files of a field (name="files[]").
func (c *Ctx) Files(k string) []*multipart.FileHeader {
	c.All()
	if c.R.MultipartForm == nil {
		return nil
	}
	out := []*multipart.FileHeader{}
	for _, name := range []string{k, k + "[]"} {
		for _, f := range c.R.MultipartForm.File[name] {
			if f.Filename != "" {
				out = append(out, f)
			}
		}
	}
	return out
}

// IP is REMOTE_ADDR.
func (c *Ctx) IP() string {
	h, _, err := net.SplitHostPort(c.R.RemoteAddr)
	if err != nil {
		return c.R.RemoteAddr
	}
	return h
}

func (c *Ctx) UserAgent() string { return php.Substr(c.R.UserAgent(), 0, 250) }

// Host is the host name without port.
func (c *Ctx) Host() string {
	h := c.R.Host
	if i := strings.LastIndexByte(h, ':'); i >= 0 && !strings.Contains(h[i:], "]") {
		h = h[:i]
	}
	return h
}

// IsHTTPS believes X-Forwarded-Proto only from this machine or the private network.
func (c *Ctx) IsHTTPS() bool {
	if c.R.TLS != nil {
		return true
	}
	if c.R.Header.Get("X-Forwarded-Proto") != "https" {
		return false
	}
	ip := net.ParseIP(c.IP())
	return ip != nil && (ip.IsLoopback() || ip.IsPrivate() || ip.IsLinkLocalUnicast())
}

// Origin is scheme://host.
func (c *Ctx) Origin() string {
	s := "http"
	if c.IsHTTPS() {
		s = "https"
	}
	return s + "://" + c.R.Host
}

// Cookie value ("" when missing).
func (c *Ctx) Cookie(name string) string {
	ck, err := c.R.Cookie(name)
	if err != nil {
		return ""
	}
	v, _ := url.QueryUnescape(ck.Value)
	return v
}

// SetCookie writes a cookie (path /, HttpOnly unless jsReadable, SameSite Lax, Secure on HTTPS).
func (c *Ctx) SetCookie(name, value string, maxAge int, jsReadable bool) {
	http.SetCookie(c.W, &http.Cookie{Name: name, Value: url.QueryEscape(value), Path: cookiePath(), MaxAge: maxAge, HttpOnly: !jsReadable, SameSite: http.SameSiteLaxMode, Secure: c.IsHTTPS()})
}

func cookiePath() string {
	if p := BasePrefix(); p != "" {
		return p + "/"
	}
	return "/"
}

// ---------------------------------------------------------------- URLs

// BasePrefix is the folder part of app.url ("" when the site is at the root).
func BasePrefix() string {
	u, err := url.Parse(config.String("app.url", ""))
	if err != nil {
		return ""
	}
	return strings.TrimRight(u.Path, "/")
}

// AllowedHosts are the host names (with port) this site answers to.
func AllowedHosts() []string {
	var out []string
	if u, err := url.Parse(config.String("app.url", "")); err == nil && u.Host != "" {
		out = append(out, strings.ToLower(u.Host))
	}
	for _, h := range config.Strings("app.hosts") {
		out = append(out, strings.ToLower(h))
	}
	return out
}

// BaseURL never builds links from a forged Host header.
func (c *Ctx) BaseURL() string {
	if c == nil || c.R == nil || c.R.Host == "" {
		return strings.TrimRight(config.String("app.url", ""), "/")
	}
	if !php.InList(strings.ToLower(c.R.Host), AllowedHosts()...) {
		return strings.TrimRight(config.String("app.url", ""), "/")
	}
	return c.Origin() + BasePrefix()
}

// URL is url($path, $query): empty query values are left out.
func (c *Ctx) URL(path string, query ...any) string {
	u := c.BaseURL() + "/" + strings.TrimLeft(path, "/")
	if q := buildQuery(query...); q != "" {
		u += "?" + q
	}
	return u
}

// buildQuery is http_build_query of name/value pairs, or of one map[string]string / map[string]any.
func buildQuery(query ...any) string {
	v := url.Values{}
	var keys []string
	add := func(k string, val any) {
		s := php.Str(val)
		if val == nil || s == "" {
			return
		}
		if _, ok := v[k]; !ok {
			keys = append(keys, k)
		}
		v.Set(k, s)
	}
	if len(query) == 1 {
		switch m := query[0].(type) {
		case map[string]string:
			ks := make([]string, 0, len(m))
			for k := range m {
				ks = append(ks, k)
			}
			sort.Strings(ks)
			for _, k := range ks {
				add(k, m[k])
			}
		case map[string]any:
			ks := make([]string, 0, len(m))
			for k := range m {
				ks = append(ks, k)
			}
			sort.Strings(ks)
			for _, k := range ks {
				add(k, m[k])
			}
		}
	} else {
		for i := 0; i+1 < len(query); i += 2 {
			add(php.Str(query[i]), query[i+1])
		}
	}
	parts := make([]string, 0, len(keys))
	for _, k := range keys {
		parts = append(parts, url.QueryEscape(k)+"="+url.QueryEscape(v.Get(k)))
	}
	return strings.Join(parts, "&")
}

// Asset is asset($path): the URL with ?v=<mtime> so browsers fetch a changed file.
func (c *Ctx) Asset(path string) string {
	u := c.URL(path)
	if st, err := os.Stat(filepath.Join(BasePath, "public", strings.TrimLeft(path, "/"))); err == nil {
		u += "?v=" + strconv.FormatInt(st.ModTime().Unix(), 10)
	}
	return u
}

// UploadURL is upload_url(): public/uploads/<path>, or "".
func (c *Ctx) UploadURL(path any) string {
	p := php.Str(path)
	if p == "" {
		return ""
	}
	return c.URL("uploads/" + p)
}

// ---------------------------------------------------------------- language

// Locale is the language of this request.
func (c *Ctx) Locale() string {
	if c.locale == "" {
		return "en"
	}
	return c.locale
}

func (c *Ctx) SetLocale(l string) {
	if i18n.Has(l) {
		c.locale = l
	} else {
		c.locale = "en"
	}
}

// T is __($key, $params): params are name/value pairs.
func (c *Ctx) T(key string, params ...any) string { return i18n.T(c.Locale(), key, params...) }

// With runs fn in another language (I18n::with) — e-mails in the recipient's language.
func (c *Ctx) With(locale string, fn func()) {
	prev := c.locale
	if locale == "" {
		locale = Setting("default_locale", "en")
	}
	c.SetLocale(locale)
	defer func() { c.locale = prev }()
	fn()
}

// ResolveLocale: the person's choice → language cookie (guests) → company default → English.
func (c *Ctx) ResolveLocale(userLocale string) string {
	for _, cand := range []string{userLocale, c.Cookie("lang"), Setting("default_locale", ""), "en"} {
		if cand != "" && i18n.Has(cand) {
			return cand
		}
	}
	return "en"
}

// ---------------------------------------------------------------- responses

// Status sets the status code of the next response.
func (c *Ctx) Status(code int) { c.status = code }

func (c *Ctx) write(contentType string, body []byte) {
	if contentType != "" {
		c.W.Header().Set("Content-Type", contentType)
	}
	c.saveSession()
	code := c.status
	if code == 0 {
		code = http.StatusOK
	}
	c.W.WriteHeader(code)
	_, _ = c.W.Write(body)
	panic(halt{})
}

// HTML sends a page.
func (c *Ctx) HTML(s string) { c.write("text/html; charset=utf-8", []byte(s)) }

// Text sends plain text.
func (c *Ctx) Text(s string) { c.write("text/plain; charset=utf-8", []byte(s)) }

// View renders a page inside its layout and sends it (return view(...)).
func (c *Ctx) View(name string, data M) { c.HTML(Render(c, name, data)) }

// Partial renders a template without the layout (partial()).
func (c *Ctx) Partial(name string, data M) template.HTML {
	return template.HTML(RenderPartial(c, name, data))
}

// JSON sends JSON (json_response).
func (c *Ctx) JSON(v any, status ...int) {
	if len(status) > 0 {
		c.status = status[0]
	}
	b, err := json.Marshal(v)
	if err != nil {
		b = []byte(`{"message":"Could not encode the answer."}`)
	}
	c.write("application/json", b)
}

// Download sends a file to save.
func (c *Ctx) Download(filename, contentType string, body []byte) {
	c.W.Header().Set("Content-Disposition", ContentDisposition("attachment", filename))
	c.W.Header().Set("Cache-Control", "private, no-store")
	c.W.Header().Set("X-Content-Type-Options", "nosniff")
	c.write(contentType, body)
}

// Inline sends a file to show in the browser (PDF preview …).
func (c *Ctx) Inline(filename, contentType string, body []byte) {
	c.W.Header().Set("Content-Disposition", ContentDisposition("inline", filename))
	c.W.Header().Set("Cache-Control", "private, no-store")
	c.W.Header().Set("X-Content-Type-Options", "nosniff")
	c.write(contentType, body)
}

// ContentDisposition with a plain ASCII name and the UTF-8 one.
func ContentDisposition(kind, filename string) string {
	ascii := regexp.MustCompile(`[^A-Za-z0-9._ -]+`).ReplaceAllString(filename, "_")
	return kind + `; filename="` + ascii + `"; filename*=UTF-8''` + url.PathEscape(filename)
}

var schemeRe = regexp.MustCompile(`(?i)^([a-z][a-z0-9+.\-]*:|//|\\\\)`)

// Redirect is redirect($to): only to this site.
func (c *Ctx) Redirect(to string) {
	if schemeRe.MatchString(to) {
		base := c.BaseURL()
		if !(to == base || strings.HasPrefix(to, base+"/")) {
			to = c.URL("/dashboard")
		}
	} else {
		to = c.URL(to)
	}
	if c.API {
		c.JSON(M{"redirect": to}, http.StatusSeeOther)
	}
	c.W.Header().Set("Location", to)
	if c.status == 0 {
		c.status = http.StatusFound
	}
	c.write("", nil)
}

// Back goes to the previous page of this site (else the dashboard), with an optional flash message.
func (c *Ctx) Back(kind, message string) {
	if kind != "" {
		c.Flash(kind, message)
	}
	ref := c.R.Referer()
	if ref != "" && strings.HasPrefix(ref, c.BaseURL()+"/") {
		c.Redirect(ref)
	}
	c.Redirect("/dashboard")
}

// Flash keeps a message for the next page (success | status | warning | error …).
func (c *Ctx) Flash(k string, v any) {
	if c.Sess != nil {
		c.Sess.Flash(k, v)
	}
}

// Authorize stops with 403 unless the person holds resource.action.
func (c *Ctx) Authorize(resource, action string) {
	if !c.Can(resource, action) {
		Abort(http.StatusForbidden, "You don't have permission to "+action+" "+strings.ReplaceAll(resource, "_", " ")+".")
	}
}

func (c *Ctx) saveSession() {
	if c.Sess == nil || c.API {
		return
	}
	if c.Sess.changed {
		c.Sess.save()
		c.Sess.changed = false
		http.SetCookie(c.W, &http.Cookie{Name: SessionCookie, Value: c.Sess.ID, Path: cookiePath(), HttpOnly: true, SameSite: http.SameSiteLaxMode, Secure: c.IsHTTPS()})
	}
}
