package api

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"math/big"
	"net/http"
	"regexp"
	"runtime/debug"
	"strings"
	"sync"
	"time"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/php"

	"github.com/go-chi/chi/v5"
)

// Front door of /api/v1 (App\Core\Api\Kernel). No session and no cookies: every request but sign-in carries
// "Authorization: Bearer <access token>" (see Tokens). Answers are JSON; errors are
// {"error": {"code", "message", "fields"?}} without internals. Every request is written to api_log.
//
// Settings (config/config.json → "api"): enabled, require_https, access_minutes, refresh_days,
// max_days, rate_per_minute, login_per_minute.

const maxBody = 12 * 1024 * 1024

var public = []string{"POST /api/v1/auth/login", "POST /api/v1/auth/refresh"}

// Mount registers the API under the server's /api route.
func Mount(r chi.Router) { r.HandleFunc("/*", handle) }

var (
	routerOnce sync.Once
	v1         *chi.Mux
)

// router: core endpoints, then each module's own (paths relative to /api/v1).
func router() *chi.Mux {
	routerOnce.Do(func() {
		mux := chi.NewRouter()
		mux.NotFound(func(w http.ResponseWriter, r *http.Request) { app.Abort(http.StatusNotFound) })
		mux.MethodNotAllowed(func(w http.ResponseWriter, r *http.Request) { app.Abort(http.StatusMethodNotAllowed) })
		r := app.Router{R: mux}
		r.Post("/auth/login", login)
		r.Post("/auth/refresh", refresh)
		r.Post("/auth/logout", logout)
		r.Get("/me", me)
		r.Get("/me/devices", devices)
		r.Post("/me/devices/{id}/revoke", revoke)
		r.Get("/me/notifications", notifications)
		for _, m := range app.Modules() {
			if m.API != nil && m.Key != "core" {
				m.API(app.Router{R: mux})
			}
		}
		v1 = mux
	})
	return v1
}

// recorder keeps what a handler answered, so the kernel can add its headers and log the status.
type recorder struct {
	header http.Header
	status int
	body   bytes.Buffer
	wrote  bool
}

func newRecorder() *recorder                    { return &recorder{header: http.Header{}} }
func (r *recorder) Header() http.Header         { return r.header }
func (r *recorder) Write(b []byte) (int, error) { r.wrote = true; return r.body.Write(b) }
func (r *recorder) WriteHeader(code int) {
	if !r.wrote {
		r.status = code
	}
	r.wrote = true
}

type answer struct {
	status  int
	code    string
	body    any
	headers map[string]string
}

var moduleRe = regexp.MustCompile(`^/api/v1/([a-z0-9_]+)(/|$)`)

func handle(w http.ResponseWriter, req *http.Request) {
	c := app.CtxOf(req)
	if c == nil || !c.API { // "/api" itself is a web page that does not exist
		app.Abort(http.StatusNotFound)
	}
	started := time.Now()
	reqID := randHex(8)
	method := c.Method()
	path := c.Path()
	c.R.Header.Set("Accept", "application/json") // web actions the API delegates to answer in JSON (also for multipart uploads)
	rec := newRecorder()
	c.W = rec

	a := run(c, method, path, rec, reqID)
	c.Replace(nil)

	status := a.status
	if rec.wrote {
		status = rec.status
		if status == 0 {
			status = http.StatusOK
		}
	}
	writeLog(c, reqID, method, path, status, a.code, started)
	send(w, c, reqID, rec, a)
}

func run(c *app.Ctx, method, path string, rec *recorder, reqID string) (a answer) {
	defer func() {
		r := recover()
		if r == nil || rec.wrote { // nothing thrown, or the handler answered (its response stops it)
			return
		}
		switch e := r.(type) {
		case *Error:
			errObj := config.NewMap()
			errObj.Set("code", e.Code)
			if e.Message != "" {
				errObj.Set("message", e.Message)
			}
			if e.Fields.Len() > 0 {
				errObj.Set("fields", e.Fields)
			}
			a = answer{status: e.Status, code: e.Code, body: Obj("error", errObj), headers: e.Headers}
		case *app.ValidationError:
			fields := config.NewMap()
			for _, k := range e.Errors.Keys {
				fields.Set(k, e.Errors.Msgs[k])
			}
			a = answer{status: http.StatusUnprocessableEntity, code: "invalid", body: Obj("error", Obj("code", "invalid", "message", e.Errors.First(), "fields", fields))}
		case *app.HTTPError:
			code := map[int]string{401: "unauthenticated", 403: "forbidden", 404: "not_found", 405: "method_not_allowed", 419: "invalid", 503: "unavailable"}[e.Status]
			if code == "" {
				code = "error"
			}
			msg := e.Message
			if msg == "" {
				if e.Status == http.StatusNotFound {
					msg = "Not found."
				} else {
					msg = fmt.Sprintf("Error %d", e.Status)
				}
			}
			a = answer{status: e.Status, code: code, body: Obj("error", Obj("code", code, "message", msg))}
		default:
			app.WriteErrorLog(fmt.Sprintf("[api %s] %v\nStack trace:\n%s", reqID, r, debug.Stack()))
			a = answer{status: http.StatusInternalServerError, code: "server_error",
				body: Obj("error", Obj("code", "server_error", "message", "Something went wrong. Quote request "+reqID+" to your administrator."))}
		}
	}()

	if !config.Bool("api.enabled", true) {
		Fail(http.StatusNotFound, "not_found", "Not found.")
	}
	if config.Bool("api.require_https", true) && config.String("app.env", "") != "local" && !c.IsHTTPS() {
		Fail(http.StatusForbidden, "https_required", "The API only answers over HTTPS.")
	}
	if method != http.MethodGet && method != http.MethodPost {
		Fail(http.StatusMethodNotAllowed, "method_not_allowed", "Use GET or POST.", "Allow", "GET, POST")
	}
	if c.R.ContentLength > maxBody {
		Fail(http.StatusRequestEntityTooLarge, "too_large", "The request is too large.")
	}
	if method == http.MethodPost {
		typ := strings.ToLower(c.R.Header.Get("Content-Type"))
		isJSON := strings.HasPrefix(typ, "application/json")
		if !isJSON && !strings.HasPrefix(typ, "multipart/form-data") {
			Fail(http.StatusUnsupportedMediaType, "unsupported_media_type", "Send JSON (application/json) or multipart/form-data.")
		}
		c.R.Body = http.MaxBytesReader(nil, c.R.Body, maxBody)
		if isJSON {
			raw, _ := io.ReadAll(c.R.Body)
			c.R.Body = io.NopCloser(bytes.NewReader(raw))
			t := bytes.TrimSpace(raw)
			if len(t) > 0 && (!json.Valid(t) || (t[0] != '{' && t[0] != '[')) {
				Fail(http.StatusBadRequest, "invalid_json", "The body is not valid JSON.")
			}
		}
	}

	if php.InList(method+" "+path, public...) {
		Hit(c, "ip:"+c.IP(), max(3, config.Int("api.login_per_minute", 10)))
	} else {
		u, d := Authenticate(c, c.R.Header.Get("Authorization"))
		c.ActAs(u)
		c.Shared[deviceKey] = d
		Hit(c, "dev:"+d.Str("id"), max(10, config.Int("api.rate_per_minute", 120)))
	}
	loc := ""
	if u := c.User(); u != nil {
		loc = u.Locale
	}
	if loc == "" {
		loc = language(c)
	}
	c.SetLocale(c.ResolveLocale(loc))

	// a module that is off has no API (plain 404); one that is fenced off answers 503 on its API URLs
	if m := moduleRe.FindStringSubmatch(path); m != nil && m[1] != "core" && app.ModuleExists(m[1]) {
		key := m[1]
		if !app.ModuleEnabled(key) || app.ModuleByKey(key).API == nil {
			app.Abort(http.StatusNotFound)
		}
		p := app.ModuleProblems(key)
		_, a1 := p["manifest"]
		_, a2 := p["migrations"]
		if a1 || a2 {
			unavailable(c)
		}
		c.Module = key
	}

	sub := ""
	if strings.HasPrefix(path, "/api/v1/") {
		sub = path[len("/api/v1"):]
	} else {
		app.Abort(http.StatusNotFound)
	}
	r2 := c.R.Clone(context.WithValue(c.R.Context(), chi.RouteCtxKey, (*chi.Context)(nil)))
	r2.URL.Path = sub
	r2.URL.RawPath = ""
	router().ServeHTTP(rec, r2)
	if !rec.wrote {
		a = answer{status: http.StatusOK, body: Obj("ok", true)}
	}
	return a
}

var langRe = regexp.MustCompile(`(?i)^(th|en)\b`)

func language(c *app.Ctx) string {
	if m := langRe.FindStringSubmatch(c.R.Header.Get("Accept-Language")); m != nil {
		return strings.ToLower(m[1])
	}
	return ""
}

func writeLog(c *app.Ctx, reqID, method, path string, status int, code string, started time.Time) {
	err := db.Try(func() {
		var dev, uid, errCode any
		if d := Device(c); d != nil {
			dev = d["id"]
		}
		if u := c.User(); u != nil {
			uid = u.ID
		}
		if code != "" {
			errCode = code
		}
		db.Insert("api_log", db.Cols{{Name: "request_id", Value: reqID}, {Name: "device_id", Value: dev}, {Name: "user_id", Value: uid},
			{Name: "method", Value: method}, {Name: "path", Value: php.Substr(path, 0, 255)}, {Name: "status", Value: status},
			{Name: "ms", Value: int(time.Since(started).Milliseconds())}, {Name: "ip", Value: c.IP()}, {Name: "error", Value: errCode}}, "")
		if randInt(1000) == 0 {
			db.Exec("DELETE FROM api_log WHERE at < now() - interval '90 days'")
		}
	})
	if err != nil {
		app.WriteErrorLog("[api] log: " + err.Error())
	}
}

func send(w http.ResponseWriter, c *app.Ctx, reqID string, rec *recorder, a answer) {
	h := w.Header()
	for _, k := range []string{"X-Powered-By", "X-Frame-Options", "Cross-Origin-Opener-Policy", "Permissions-Policy"} {
		h.Del(k)
	}
	if rec.wrote {
		for k, v := range rec.header {
			h[k] = v
		}
	}
	h.Set("X-Request-Id", reqID)
	h.Set("X-Content-Type-Options", "nosniff")
	h.Set("Cache-Control", "no-store")
	h.Set("Referrer-Policy", "no-referrer")
	h.Set("Content-Security-Policy", "default-src 'none'; frame-ancestors 'none'")
	if c.IsHTTPS() {
		h.Set("Strict-Transport-Security", "max-age=31536000")
	}
	for k, v := range a.headers {
		h.Set(k, v)
	}
	if rec.wrote {
		status := rec.status
		if status == 0 {
			status = http.StatusOK
		}
		body := rec.body.Bytes()
		if strings.HasPrefix(h.Get("Content-Type"), "application/json") {
			h.Set("Content-Type", "application/json; charset=utf-8")
			body = unescapeHTML(body)
		}
		w.WriteHeader(status)
		_, _ = w.Write(body)
		return
	}
	b, err := json.Marshal(a.body)
	if err != nil {
		b = []byte(`{"error":{"code":"server_error","message":"Could not encode the answer."}}`)
	}
	h.Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(a.status)
	_, _ = w.Write(unescapeHTML(b))
}

// unescapeHTML turns Go's < > & back into < > & (PHP's json_encode leaves them as they are).
func unescapeHTML(b []byte) []byte {
	if !bytes.Contains(b, []byte(`\u00`)) {
		return b
	}
	out := make([]byte, 0, len(b))
	for i := 0; i < len(b); i++ {
		if b[i] != '\\' || i+1 >= len(b) {
			out = append(out, b[i])
			continue
		}
		if b[i+1] == 'u' && i+5 < len(b) {
			switch string(b[i+2 : i+6]) {
			case "003c":
				out = append(out, '<')
				i += 5
				continue
			case "003e":
				out = append(out, '>')
				i += 5
				continue
			case "0026":
				out = append(out, '&')
				i += 5
				continue
			}
		}
		out = append(out, b[i], b[i+1]) // any other escape stays as it is
		i++
	}
	return out
}

// Delegate calls a web action that answers with c.JSON and returns that answer, with the request input limited
// to input. Used where the web already has the full save logic (validation, owner, visibility, notifications,
// activity log), so mobile follows the same rules. The status is the action's, 200 becoming 201.
// redirectMeansDone: an action that answers success with a redirect gives {"ok": true} (errors are exceptions).
func Delegate(c *app.Ctx, action app.Handler, input *config.Map, redirectMeansDone bool) (*config.Map, int) {
	rec := newRecorder()
	saved := c.W
	c.W = rec
	c.Replace(input)
	defer func() {
		c.W = saved
		c.Replace(nil)
		c.Status(0)
	}()
	func() {
		defer func() {
			if r := recover(); r != nil && !rec.wrote {
				panic(r)
			}
		}()
		action(c)
	}()
	if !rec.wrote {
		panic(fmt.Errorf("delegated action gave no answer"))
	}
	v, _ := config.Decode(rec.body.Bytes())
	data, ok := v.(*config.Map)
	if !ok {
		data = config.NewMap()
	}
	if to, isRedirect := data.Get("redirect"); isRedirect {
		if redirectMeansDone {
			return Obj("ok", true), http.StatusOK
		}
		panic(fmt.Errorf("delegated action redirected to %v", to))
	}
	status := rec.status
	if status == 0 || status == http.StatusOK {
		status = http.StatusCreated
	}
	return data, status
}

func randHex(n int) string {
	b := make([]byte, n)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

func randInt(n int64) int64 {
	v, err := rand.Int(rand.Reader, big.NewInt(n))
	if err != nil {
		return 1
	}
	return v.Int64()
}
