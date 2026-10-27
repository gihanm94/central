package app

import (
	"context"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"runtime/debug"
	"strings"
	"sync"
	"time"

	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/php"

	"github.com/go-chi/chi/v5"
)

// Handler is a page or action: it answers through the Ctx (View, Redirect, JSON …).
type Handler func(c *Ctx)

// Middleware runs before a handler; it stops the request by answering (Redirect, Abort …).
type Middleware func(c *Ctx)

type ctxKey struct{}

// CtxOf returns the Ctx of a request.
func CtxOf(r *http.Request) *Ctx { c, _ := r.Context().Value(ctxKey{}).(*Ctx); return c }

// Router registers routes like routes/web.php: {id} only matches digits.
type Router struct {
	R  chi.Router
	mw []Middleware
}

var idParam = regexp.MustCompile(`\{(\w+)\}`)

func pattern(p string) string {
	p = idParam.ReplaceAllStringFunc(p, func(m string) string {
		if m == "{id}" {
			return "{id:[0-9]+}"
		}
		return m
	})
	if p != "/" {
		p = strings.TrimRight(p, "/")
	}
	return p
}

func (r Router) handle(method, path string, h Handler, mw []Middleware) {
	all := append(append([]Middleware{}, r.mw...), mw...)
	fn := func(w http.ResponseWriter, req *http.Request) {
		c := CtxOf(req)
		c.R = req // the router's copy carries the route parameters
		if method == http.MethodPost && !c.API {
			tok := c.Input("_token")
			if tok == "" {
				tok = req.Header.Get("X-CSRF-TOKEN")
			}
			if !c.Sess.VerifyCSRF(tok) {
				Abort(419, "Your session expired. Reload the page and try again.")
			}
		}
		for _, m := range all {
			m(c)
		}
		h(c)
	}
	r.R.MethodFunc(method, pattern(path), fn)
}

func (r Router) Get(path string, h Handler, mw ...Middleware) { r.handle(http.MethodGet, path, h, mw) }
func (r Router) Post(path string, h Handler, mw ...Middleware) {
	r.handle(http.MethodPost, path, h, mw)
}

// Group adds middleware to the routes fn registers.
func (r Router) Group(mw []Middleware, fn func(Router)) {
	fn(Router{R: r.R, mw: append(append([]Middleware{}, r.mw...), mw...)})
}

// Any answers GET and POST on prefix and everything below it.
func (r Router) Any(prefix string, h Handler, mw ...Middleware) {
	p := strings.TrimRight(prefix, "/")
	for _, m := range []string{http.MethodGet, http.MethodPost} {
		r.handle(m, p, h, mw)
		r.handle(m, p+"/*", h, mw)
	}
}

// Guest: signed-in people go to the dashboard.
func Guest(c *Ctx) {
	if c.User() != nil {
		c.Redirect("/dashboard")
	}
}

// Auth: guests go to the sign-in page (JSON callers get 401).
func Auth(c *Ctx) {
	if c.User() == nil {
		if c.IsJSON() {
			Abort(http.StatusUnauthorized, "Please sign in again.")
		}
		c.Sess.Set("_intended", c.R.URL.RequestURI())
		c.Redirect("/login")
	}
}

// Perm: the person must hold resource.action.
func Perm(resource, action string) Middleware {
	return func(c *Ctx) { c.Authorize(resource, action) }
}

// ---------------------------------------------------------------- server

// Installed: storage/installed.lock exists.
func Installed() bool {
	_, err := os.Stat(filepath.Join(BasePath, "storage", "installed.lock"))
	return err == nil
}

// RequestHook runs at the start of every page request of a module (PHP hook "request").
var lastGC time.Time
var gcMu sync.Mutex

// NewServer builds the HTTP handler. routes registers the core pages; modules add theirs.
func NewServer(routes func(r Router), api func(r chi.Router), installer http.Handler) http.Handler {
	mux := chi.NewRouter()
	mux.NotFound(func(w http.ResponseWriter, r *http.Request) { Abort(http.StatusNotFound) })
	mux.MethodNotAllowed(func(w http.ResponseWriter, r *http.Request) { Abort(http.StatusMethodNotAllowed) })

	web := Router{R: mux}
	routes(web)
	for _, m := range Modules() {
		if m.Routes == nil {
			continue
		}
		key := m.Key
		guard := func(c *Ctx) {
			c.Module = key
			if !ModuleEnabled(key) {
				Abort(http.StatusNotFound)
			}
			if len(ModuleProblems(key)) > 0 {
				c.Status(http.StatusServiceUnavailable)
				c.View("errors/http", M{"status": 503, "message": c.T("This module is temporarily unavailable"), "title": "Error 503"})
			}
			RunHooks("request", key, func(_ string, fn any) any {
				if f, ok := fn.(HookRequest); ok {
					f(c)
				}
				return nil
			})
		}
		m.Routes(Router{R: mux, mw: []Middleware{Auth, guard}})
	}
	if api != nil {
		mux.Route("/api", func(r chi.Router) { api(r) })
	}

	static := http.FileServer(http.Dir(filepath.Join(BasePath, "public")))
	staticFile := regexp.MustCompile(`(?i)\.[a-z0-9]{2,5}$`)

	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if pre := BasePrefix(); pre != "" {
			if !strings.HasPrefix(r.URL.Path, pre) {
				http.NotFound(w, r)
				return
			}
		}
		c := &Ctx{W: w, R: r, Shared: M{}}
		path := c.Path()

		// real files: assets and uploads (never a dot-file)
		if staticFile.MatchString(path) || strings.HasPrefix(path, "/assets/") || strings.HasPrefix(path, "/uploads/") {
			serveStatic(w, r, path, static)
			return
		}
		if path == "/install" || path == "/install.php" {
			if installer != nil {
				installer.ServeHTTP(w, r)
				return
			}
		}
		isAPI := strings.HasPrefix(path, "/api/")
		if !Installed() {
			if isAPI {
				w.Header().Set("Content-Type", "application/json")
				w.WriteHeader(http.StatusServiceUnavailable)
				_, _ = w.Write([]byte(`{"error":{"code":"not_installed","message":"The system is not installed yet."}}`))
				return
			}
			http.Redirect(w, r, BasePrefix()+"/install", http.StatusFound)
			return
		}
		if NeedsConfig != nil && NeedsConfig(w, r) {
			return
		}
		c.API = isAPI
		r = r.WithContext(context.WithValue(r.Context(), ctxKey{}, c))
		c.R = r

		h := w.Header()
		h.Del("X-Powered-By")
		h.Set("X-Frame-Options", "SAMEORIGIN")
		h.Set("X-Content-Type-Options", "nosniff")
		h.Set("Referrer-Policy", "same-origin")
		h.Set("Cross-Origin-Opener-Policy", "same-origin")
		h.Set("Permissions-Policy", "geolocation=(), microphone=(), payment=(), usb=(), camera=(self)")
		if c.IsHTTPS() {
			h.Set("Strict-Transport-Security", "max-age=31536000")
		}

		defer func() {
			if rec := recover(); rec != nil {
				handlePanic(c, rec)
			}
		}()

		if !isAPI {
			defer c.loadSession()()
			c.Sess.ageFlash()
		}
		AutoMigrate()
		if !isAPI {
			var loc string
			if u := c.User(); u != nil {
				loc = u.Locale
			}
			c.SetLocale(c.ResolveLocale(loc))
			if dl := c.Query("_dl"); regexp.MustCompile(`^[a-z0-9]{6,20}$`).MatchString(dl) {
				http.SetCookie(w, &http.Cookie{Name: "dl_" + dl, Value: "1", Path: cookiePath(), MaxAge: 120, SameSite: http.SameSiteLaxMode, Secure: c.IsHTTPS()})
			}
		}
		gcMu.Lock()
		if time.Since(lastGC) > time.Hour {
			lastGC = time.Now()
			go GCSessions()
		}
		gcMu.Unlock()

		mux.ServeHTTP(w, r)
		// a handler that wrote nothing (should not happen) still saves the session
		c.saveSession()
	})
}

func serveStatic(w http.ResponseWriter, r *http.Request, path string, static http.Handler) {
	clean := filepath.Clean("/" + path)
	for _, part := range strings.Split(clean, "/") {
		if strings.HasPrefix(part, ".") {
			http.Error(w, "Not found", http.StatusNotFound)
			return
		}
	}
	full := filepath.Join(BasePath, "public", clean)
	st, err := os.Stat(full)
	if err != nil || st.IsDir() || strings.HasSuffix(clean, ".php") {
		http.Error(w, "Not found", http.StatusNotFound)
		return
	}
	w.Header().Set("X-Content-Type-Options", "nosniff")
	if strings.HasPrefix(clean, "/uploads/") {
		// an uploaded file opened directly cannot run script or reach the session
		w.Header().Set("Content-Security-Policy", "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox")
	}
	if pre := BasePrefix(); pre != "" {
		r2 := r.Clone(r.Context())
		r2.URL.Path = clean
		static.ServeHTTP(w, r2)
		return
	}
	static.ServeHTTP(w, r)
}

// loadSession reads the browser's session and locks it for this request (like PHP's session file lock);
// the returned func releases it.
func (c *Ctx) loadSession() func() {
	id := ""
	if ck, err := c.R.Cookie(SessionCookie); err == nil {
		id = ck.Value
	}
	if id != "" && sessIDRe.MatchString(id) {
		unlock := lockSession(id)
		if s, ok := readSession(id, 0); ok {
			c.Sess = s
			return unlock
		}
		unlock()
	}
	c.Sess = &Session{ID: newSessionID(), Data: map[string]any{}}
	return func() {}
}

var secretFields = []string{"password", "password_confirmation", "current_password", "_token"}

func handlePanic(c *Ctx, rec any) {
	if _, ok := rec.(halt); ok {
		return
	}
	// answer, stopping at the response's own halt
	respond := func(fn func()) {
		defer func() {
			if r := recover(); r != nil {
				if _, ok := r.(halt); !ok {
					LogError(c, fmt.Errorf("while showing an error: %v", r), debug.Stack())
					http.Error(c.W, "Something went wrong.", http.StatusInternalServerError)
				}
			}
		}()
		fn()
	}
	switch e := rec.(type) {
	case *ValidationError:
		respond(func() {
			if c.IsJSON() || c.Sess == nil {
				errs := M{}
				for _, k := range e.Errors.Keys {
					errs[k] = []string{e.Errors.Msgs[k]}
				}
				c.JSON(M{"message": e.Errors.First(), "errors": errs}, http.StatusUnprocessableEntity)
			}
			old := map[string]any{}
			all := c.All()
			for _, k := range all.Keys {
				if php.InList(k, secretFields...) {
					continue
				}
				old[k] = plain(all.Vals[k])
			}
			c.Flash("_errors", e.Errors.toSession())
			c.Flash("_old", old)
			c.Back("", "")
		})
		return
	case *HTTPError:
		respond(func() {
			if c.IsJSON() || c.API {
				msg := e.Message
				if msg == "" {
					msg = fmt.Sprintf("Error %d", e.Status)
				}
				c.JSON(M{"message": msg}, e.Status)
			}
			if c.Method() == http.MethodPost && php.InList(php.Str(e.Status), "403", "404", "405", "409", "419", "422", "429") && c.Sess != nil && c.User() != nil {
				msg := e.Message
				if msg == "" {
					switch e.Status {
					case 403:
						msg = c.T("You don't have access to this page")
					case 404:
						msg = c.T("We couldn't find that page")
					case 419:
						msg = c.T("Your session expired. Try again.")
					case 429:
						msg = c.T("Too many requests. Wait a moment and try again.")
					default:
						msg = c.T("Error :code.", "code", e.Status)
					}
				}
				c.Back("error", msg)
			}
			c.Status(e.Status)
			if c.Sess == nil {
				c.Sess = &Session{ID: newSessionID(), Data: map[string]any{}}
			}
			c.View("errors/http", M{"status": e.Status, "message": e.Message, "title": fmt.Sprintf("Error %d", e.Status)})
		})
		return
	}
	err, ok := rec.(error)
	if !ok {
		err = fmt.Errorf("%v", rec)
	}
	ref := strings.ToUpper(randHex(5)[:8])
	who := "guest"
	if c.user != nil {
		who = fmt.Sprintf("user #%d", c.user.ID)
	}
	LogError(c, fmt.Errorf("[ref %s] %s %s · %s · %s\n%v", ref, c.Method(), c.Path(), who, c.IP(), err), debug.Stack())
	if m := ModuleForPath(c.Path()); m != "" {
		RunHooks("error", m, func(_ string, fn any) any {
			if f, ok := fn.(HookError); ok {
				f(c, err)
			}
			return nil
		})
	}
	respond(func() {
		msg := c.T("Something went wrong. The error was logged (reference :ref).", "ref", ref)
		if c.IsJSON() || c.API {
			c.JSON(M{"message": msg, "reference": ref}, http.StatusInternalServerError)
		}
		if c.Method() == http.MethodPost && c.Sess != nil && c.user != nil {
			c.Back("error", c.T("Something went wrong and nothing was saved. The error was logged (reference :ref).", "ref", ref))
		}
		c.Status(http.StatusInternalServerError)
		if config.String("app.env", "") == "local" {
			c.HTML(`<pre style="padding:2rem;white-space:pre-wrap;font:13px/1.5 monospace">` + escapeHTML(fmt.Sprintf("Reference %s\n%T: %v\n\n%s", ref, err, err, debug.Stack())) + `</pre>`)
		}
		if c.Sess == nil {
			c.Sess = &Session{ID: newSessionID(), Data: map[string]any{}}
		}
		c.View("errors/http", M{"status": 500, "message": msg, "title": "Error", "ref": ref})
	})
}

func plain(v any) any {
	switch x := v.(type) {
	case *config.Map:
		m := map[string]any{}
		for _, k := range x.Keys {
			m[k] = plain(x.Vals[k])
		}
		return m
	case []any:
		out := make([]any, len(x))
		for i, e := range x {
			out[i] = plain(e)
		}
		return out
	}
	return v
}

func escapeHTML(s string) string {
	r := strings.NewReplacer("&", "&amp;", "<", "&lt;", ">", "&gt;", `"`, "&quot;", "'", "&#39;")
	return r.Replace(s)
}

var logMu sync.Mutex

// LogError appends to storage/logs/php-error.log in PHP's error_log format (System logs reads it).
func LogError(c *Ctx, err error, stack []byte) {
	msg := err.Error()
	if len(stack) > 0 {
		msg += "\nStack trace:\n" + string(stack)
	}
	WriteErrorLog(msg)
}

// WriteErrorLog writes one entry "[21-Oct-2026 14:00:00 Asia/Bangkok] message".
func WriteErrorLog(msg string) {
	logMu.Lock()
	defer logMu.Unlock()
	dir := filepath.Join(BasePath, "storage", "logs")
	_ = os.MkdirAll(dir, 0o775)
	f, err := os.OpenFile(filepath.Join(dir, "php-error.log"), os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o664)
	if err != nil {
		log.Print(msg)
		return
	}
	defer f.Close()
	now := php.Now()
	fmt.Fprintf(f, "[%s %s] %s\n", now.Format("02-Jan-2006 15:04:05"), php.Loc.String(), msg)
}

// JSONBody decodes a JSON request body into v.
func (c *Ctx) JSONBody(v any) error { return json.NewDecoder(c.R.Body).Decode(v) }

var _ = db.Try
