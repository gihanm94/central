package core

import (
	"bytes"
	"context"
	"crypto/subtle"
	"encoding/json"
	"errors"
	"fmt"
	"html/template"
	"log"
	"net"
	"net/http"
	"net/mail"
	"net/url"
	"os"
	"os/user"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/conv"
	"acme/internal/db"

	"github.com/jackc/pgx/v5"
)

// One-time installer (public/install.php) and the "reconnect the database" fallback (App\Core\Support\AutoConfig).
// Served at /install and /install.php before installation, without the normal session: its form carries its own
// CSRF token, double-submitted in a cookie. Creates the PostgreSQL databases, tables, roles, permissions, the admin
// account and (optionally) demo data, writes config/config.json and storage/installed.lock, then locks itself.

const installCookie = "acme_install"

// dbFiles: the schema of each database, in the order the PHP installer runs them.
var dbFiles = []struct{ key, file string }{
	{"core", "user_db.sql"}, {"crm", "crm_db.sql"}, {"accounting", "account_db.sql"},
	{"inventory", "inventory_db.sql"}, {"machines", "machine_db.sql"}, {"hr", "hr_db.sql"},
}

var dbLabels = []struct{ key, label string }{
	{"core", "Core: people, roles, logs"}, {"crm", "CRM"}, {"accounting", "Accounting"}, {"inventory", "Inventory"}, {"machines", "Machines"}, {"hr", "HR"},
}

var installMu sync.Mutex

// Installer is the first-run installer (/install).
func Installer() http.Handler {
	app.NeedsConfig = needsConfig
	if app.Installed() && !configExists() {
		ensureConfig(nil)
	}
	return http.HandlerFunc(serveInstall)
}

func configPath() string   { return filepath.Join(app.BasePath, "config", "config.json") }
func lockPath() string     { return filepath.Join(app.BasePath, "storage", "installed.lock") }
func configExists() bool   { _, err := os.Stat(configPath()); return err == nil }
func templatePath() string { return filepath.Join(app.BasePath, "config", "config.json.example") }

// loadConfigFile: config/config.json, else the template (config/config.json.example).
func loadConfigFile() (*config.Map, error) {
	p := configPath()
	if !configExists() {
		p = templatePath()
	}
	raw, err := os.ReadFile(p)
	if err != nil {
		return nil, err
	}
	v, err := config.Decode(raw)
	if err != nil {
		return nil, fmt.Errorf("%s: %w", filepath.Base(p), err)
	}
	m, ok := v.(*config.Map)
	if !ok {
		return nil, fmt.Errorf("%s is not a JSON object", filepath.Base(p))
	}
	return m, nil
}

// cfgGet / cfgSet read and write a dotted key of a config tree.
func cfgGet(m *config.Map, key string) any {
	var v any = m
	for _, part := range strings.Split(key, ".") {
		mm, ok := v.(*config.Map)
		if !ok {
			return nil
		}
		v, _ = mm.Get(part)
	}
	return v
}

func cfgStr(m *config.Map, key string) string { return config.AsString(cfgGet(m, key), "") }

func cfgSet(m *config.Map, key string, value any) {
	parts := strings.Split(key, ".")
	for _, p := range parts[:len(parts)-1] {
		next, ok := m.Vals[p].(*config.Map)
		if !ok {
			next = config.NewMap()
			m.Set(p, next)
		}
		m = next
	}
	m.Set(parts[len(parts)-1], value)
}

// writeConfig saves config/config.json (0600: it holds passwords) and makes the running server use it.
func writeConfig(m *config.Map) error {
	raw, err := json.Marshal(m)
	if err != nil {
		return err
	}
	var out bytes.Buffer
	_ = json.Indent(&out, raw, "", "    ")
	out.WriteByte('\n')
	tmp := configPath() + ".tmp"
	if err := os.WriteFile(tmp, out.Bytes(), 0o600); err != nil {
		return err
	}
	_ = os.Chmod(tmp, 0o600)
	if err := os.Rename(tmp, configPath()); err != nil {
		_ = os.Remove(tmp)
		return err
	}
	reloadConfig()
	return nil
}

// reloadConfig re-reads config/*.json and forgets the database connections.
func reloadConfig() {
	_ = config.Load(filepath.Join(app.BasePath, "config"))
	if loc, err := time.LoadLocation(config.String("app.timezone", "UTC")); err == nil {
		conv.Loc = loc
	}
	db.Close()
}

func isLocalAddr(r *http.Request) bool {
	h, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		h = r.RemoteAddr
	}
	return h == "127.0.0.1" || h == "::1"
}

// ---------------------------------------------------------------- AutoConfig

type dbLogin struct {
	host     string
	port     int
	username string
	password string
}

var hostRe = regexp.MustCompile(`^[A-Za-z0-9.\-:]+$`)

// tryLogin: "" when this login opens the installed core database, otherwise why not.
func tryLogin(cfg *config.Map, l dbLogin) string {
	if l.username == "" || !hostRe.MatchString(l.host) {
		return "Enter the host and user name."
	}
	ctx, cancel := context.WithTimeout(context.Background(), 4*time.Second)
	defer cancel()
	dsn := db.DSNFor(l.host, l.port, cfgStr(cfg, "db.databases.core"), l.username, l.password)
	dsn = strings.Replace(dsn, "connect_timeout=3", "connect_timeout=2", 1)
	conn, err := pgx.Connect(ctx, dsn)
	if err != nil {
		return err.Error()
	}
	defer conn.Close(ctx)
	var one int
	if err := conn.QueryRow(ctx, "SELECT 1 FROM users LIMIT 1").Scan(&one); err != nil && !errors.Is(err, pgx.ErrNoRows) {
		return err.Error()
	}
	return ""
}

var localHostRe = regexp.MustCompile(`^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$`)

// autoWrite rebuilds config/config.json from the template with a working login.
func autoWrite(cfg *config.Map, l dbLogin, r *http.Request) error {
	cfgSet(cfg, "db.host", l.host)
	cfgSet(cfg, "db.port", l.port)
	cfgSet(cfg, "db.username", l.username)
	cfgSet(cfg, "db.password", l.password)
	cfgSet(cfg, "app.key", randHex(32))
	// The site address comes from the Host header only on this computer (a visitor could send any Host);
	// everywhere else the system starts in production mode and app.url / app.hosts are set by hand.
	local := r != nil && isLocalAddr(r) && localHostRe.MatchString(r.Host)
	if local {
		cfgSet(cfg, "app.url", "http://"+r.Host)
		cfgSet(cfg, "app.env", "local")
	} else {
		cfgSet(cfg, "app.env", "production")
	}
	if err := writeConfig(cfg); err != nil {
		return errors.New("Found the database, but config/ is not writable.")
	}
	app.WriteErrorLog(`[autoconfig] config/config.json was missing; rebuilt it for database user "` + l.username + `"`)
	return nil
}

// ensureConfig: the system is installed but config/config.json is gone (it is not in git) — find the existing
// database by itself: DB_HOST / DB_PORT / DB_USERNAME / DB_PASSWORD, the template's login, the login of the
// account running the server, postgres. A login counts only if it opens the core database and finds the
// installed tables, so nothing a visitor sends is ever used here.
func ensureConfig(r *http.Request) bool {
	if configExists() || !app.Installed() {
		return true
	}
	cfg, err := loadConfigFile()
	if err != nil {
		return false
	}
	host := os.Getenv("DB_HOST")
	if host == "" {
		host = cfgStr(cfg, "db.host")
	}
	port := conv.Int(os.Getenv("DB_PORT"))
	if port == 0 {
		port = conv.Int(cfgGet(cfg, "db.port"))
	}
	var list [][2]string
	if u, ok := os.LookupEnv("DB_USERNAME"); ok {
		list = append(list, [2]string{u, os.Getenv("DB_PASSWORD")})
	}
	list = append(list, [2]string{cfgStr(cfg, "db.username"), cfgStr(cfg, "db.password")})
	if me, err := user.Current(); err == nil && me.Username != "" && me.Username != "root" {
		list = append(list, [2]string{me.Username, ""})
	}
	list = append(list, [2]string{"postgres", ""})
	seen := map[[2]string]bool{}
	for _, p := range list {
		if seen[p] {
			continue
		}
		seen[p] = true
		l := dbLogin{host: host, port: port, username: p[0], password: p[1]}
		if tryLogin(cfg, l) == "" {
			return autoWrite(cfg, l, r) == nil
		}
	}
	return false
}

var (
	ensureMu   sync.Mutex
	ensureLast time.Time
)

// needsConfig (app.NeedsConfig): no login worked → the installer's "Reconnect database" form takes over.
func needsConfig(w http.ResponseWriter, r *http.Request) bool {
	if configExists() || !app.Installed() {
		return false
	}
	ensureMu.Lock()
	ok := false
	if time.Since(ensureLast) > 30*time.Second {
		ensureLast = time.Now()
		ok = ensureConfig(r)
	}
	ensureMu.Unlock()
	if ok {
		return false
	}
	path := strings.TrimPrefix(r.URL.Path, app.BasePrefix())
	if strings.HasPrefix(path, "/api/") {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusServiceUnavailable)
		_, _ = w.Write([]byte(`{"error":{"code":"not_configured","message":"The server is not configured."}}`))
		return true
	}
	http.Redirect(w, r, app.BasePrefix()+"/install", http.StatusFound)
	return true
}

// ---------------------------------------------------------------- the page

type installCheck struct {
	Label string
	OK    bool
}

type installPage struct {
	V             map[string]string
	Demo          bool
	Token         string
	Checks        []installCheck
	Ready         bool
	Errors        []string
	Log           []template.HTML
	Done          bool
	Installed     bool
	Reconnect     bool
	LocalUser     bool
	SavedDBPass   bool
	SavedMailPass bool
	CSS           string
	Locale        template.HTML
	SMTPEnc       template.HTML
	DBs           []struct{ Key, Label string }
}

func writable(dir string) bool {
	f, err := os.CreateTemp(dir, ".write-test-*")
	if err != nil {
		return false
	}
	name := f.Name()
	f.Close()
	_ = os.Remove(name)
	return true
}

func serveInstall(w http.ResponseWriter, r *http.Request) {
	installMu.Lock()
	defer installMu.Unlock()
	defer func() {
		if rec := recover(); rec != nil {
			app.WriteErrorLog(fmt.Sprintf("[install] %v", rec))
			http.Error(w, "Something went wrong.", http.StatusInternalServerError)
		}
	}()
	if r.Method != http.MethodGet && r.Method != http.MethodPost && r.Method != http.MethodHead {
		w.Header().Set("Allow", "GET, POST")
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}
	cfg, cfgErr := loadConfigFile()
	if cfg == nil {
		cfg = config.NewMap()
	}
	base := app.BasePath
	sqlDir := filepath.Join(base, "database", "sql")

	// ---------------------------------------------------------------- checks
	_, sqlErr := os.Stat(filepath.Join(sqlDir, "user_db.sql"))
	checks := []installCheck{
		{"config/config.json.example is readable", cfgErr == nil},
		{"database/sql is present", sqlErr == nil},
		{"config/ is writable", writable(filepath.Join(base, "config"))},
		{"storage/ is writable", writable(filepath.Join(base, "storage"))},
		{"public/uploads/ is writable", writable(filepath.Join(base, "public", "uploads"))},
	}
	ready := true
	for _, ch := range checks {
		ready = ready && ch.OK
	}

	var errs []string
	var logLines []template.HTML
	done := false

	token := ""
	if ck, err := r.Cookie(installCookie); err == nil && regexp.MustCompile(`^[0-9a-f]{32}$`).MatchString(ck.Value) {
		token = ck.Value
	}
	if token == "" {
		token = randHex(16)
	}
	http.SetCookie(w, &http.Cookie{Name: installCookie, Value: token, Path: app.BasePrefix() + "/", HttpOnly: true, SameSite: http.SameSiteStrictMode, Secure: r.TLS != nil})

	isPost := r.Method == http.MethodPost
	if isPost {
		r.Body = http.MaxBytesReader(w, r.Body, 1<<20)
		_ = r.ParseForm()
	}
	post := func(k string) (string, bool) {
		if !isPost {
			return "", false
		}
		vs, ok := r.PostForm[k]
		if !ok || len(vs) == 0 {
			return "", false
		}
		return vs[0], true
	}
	val := func(k, def string) string {
		if s, ok := post(k); ok {
			return s
		}
		return def
	}
	scheme := "http"
	if r.TLS != nil {
		scheme = "https"
	}
	host := r.Host
	if host == "" {
		host = "localhost:8000"
	}
	username, _ := post("username")
	if _, ok := post("username"); !ok {
		username = "admin"
	}
	v := map[string]string{
		"company":   val("company", cfgStr(cfg, "app.name")),
		"app_url":   val("app_url", scheme+"://"+host),
		"host":      val("host", cfgStr(cfg, "db.host")),
		"port":      val("port", cfgStr(cfg, "db.port")),
		"user":      val("user", cfgStr(cfg, "db.username")),
		"pass":      val("pass", ""), // passwords are never sent back into the page
		"admin":     val("admin", "System Admin"),
		"email":     val("email", "admin@acmeinter.com"),
		"username":  strings.ToLower(strings.TrimSpace(username)),
		"mail":      val("mail", cfgStr(cfg, "mail.driver")),
		"smtp_host": val("smtp_host", cfgStr(cfg, "mail.host")),
		"smtp_port": val("smtp_port", cfgStr(cfg, "mail.port")),
		"smtp_enc":  val("smtp_enc", cfgStr(cfg, "mail.encryption")),
		"smtp_user": val("smtp_user", cfgStr(cfg, "mail.username")),
		"smtp_pass": val("smtp_pass", ""),
		"from":      val("from", cfgStr(cfg, "mail.from_address")),
		"locale":    val("locale", "en"),
	}
	_, hasCompany := post("company")
	_, hasDemo := post("demo")
	demo := true
	if hasCompany {
		demo = hasDemo
	}
	dbKeys := config.AsStrings(cfgGet(cfg, "db.databases"))
	for _, k := range dbKeys {
		v["db_"+k] = val("db_"+k, cfgStr(cfg, "db.databases."+k))
	}
	savedDBPass := cfgStr(cfg, "db.password")
	savedMailPass := cfgStr(cfg, "mail.password")
	if isPost {
		if v["pass"] == "" { // left blank: keep the saved one
			v["pass"] = savedDBPass
		}
		if v["smtp_pass"] == "" {
			v["smtp_pass"] = savedMailPass
		}
	}
	formToken, _ := post("_token")
	tokenOK := subtle.ConstantTimeCompare([]byte(token), []byte(formToken)) == 1
	dbNameRe := regexp.MustCompile(`^[a-z_][a-z0-9_]{0,62}$`)

	// ---------------------------------------------- reconnect (installed, config/config.json missing)
	// Only from this computer: anyone else could point the app at a database they control.
	installed := app.Installed()
	reconnect := installed && !configExists()
	localUser := isLocalAddr(r)
	if reconnect && localUser && isPost {
		switch {
		case !tokenOK:
			errs = append(errs, "The form expired. Reload the page and try again.")
		case !dbNameRe.MatchString(v["db_core"]):
			errs = append(errs, "Database names may only use lower-case letters, numbers and _.")
		default:
			cfgSet(cfg, "db.databases.core", v["db_core"])
			port := conv.Int(v["port"])
			if port == 0 {
				port = 5432
			}
			l := dbLogin{host: strings.TrimSpace(v["host"]), port: port, username: strings.TrimSpace(v["user"]), password: v["pass"]}
			if why := tryLogin(cfg, l); why != "" {
				errs = append(errs, "Could not open "+v["db_core"]+": "+why)
			} else if err := autoWrite(cfg, l, r); err != nil {
				errs = append(errs, err.Error())
			} else {
				http.Redirect(w, r, "./", http.StatusSeeOther)
				return
			}
		}
	}

	// ------------------------------------------------------------- install
	if !installed && isPost && ready {
		if !tokenOK {
			errs = append(errs, "The form expired. Reload the page and try again.")
		}
		password, _ := post("password")
		confirm, _ := post("password_confirmation")
		if strings.TrimSpace(v["company"]) == "" {
			errs = append(errs, "Enter your company name.")
		}
		if a, err := mail.ParseAddress(v["email"]); err != nil || a.Address != v["email"] || !strings.Contains(v["email"][strings.LastIndex(v["email"], "@")+1:], ".") {
			errs = append(errs, "Enter a valid admin e-mail.")
		}
		if !regexp.MustCompile(`^[a-z0-9._-]{3,60}$`).MatchString(v["username"]) {
			errs = append(errs, "The admin username needs 3–60 letters, numbers, dots, dashes or underscores.")
		}
		if !strongPassword(password) {
			errs = append(errs, "Admin password needs 8+ characters with upper and lower case letters and a number.")
		}
		if password != confirm {
			errs = append(errs, "The two admin passwords do not match.")
		}
		for _, k := range dbKeys {
			if !dbNameRe.MatchString(v["db_"+k]) {
				errs = append(errs, "Database names may only use lower-case letters, numbers and _.")
				break
			}
		}
		if len(errs) == 0 {
			if err := runInstall(cfg, v, demo, password, dbKeys, sqlDir, &logLines); err != nil {
				errs = append(errs, err.Error())
			} else {
				done = true
			}
		}
	}

	installed = app.Installed()
	css := "assets/app.css?v=0"
	if st, err := os.Stat(filepath.Join(base, "public", "assets", "app.css")); err == nil {
		css = "assets/app.css?v=" + strconv.FormatInt(st.ModTime().Unix(), 10)
	}
	page := installPage{V: v, Demo: demo, Token: token, Checks: checks, Ready: ready, Errors: errs, Log: logLines, Done: done, Installed: installed,
		Reconnect: reconnect, LocalUser: localUser, SavedDBPass: savedDBPass != "", SavedMailPass: savedMailPass != "", CSS: css,
		Locale:  dropdown("locale", [][2]string{{"en", "English"}, {"th", "ไทย (Thai)"}}, v["locale"], "locale"),
		SMTPEnc: dropdown("smtp_enc", [][2]string{{"tls", "TLS (587)"}, {"ssl", "SSL (465)"}, {"none", "None"}}, v["smtp_enc"], "smtp_enc")}
	for _, d := range dbLabels {
		page.DBs = append(page.DBs, struct{ Key, Label string }{d.key, d.label})
	}
	var out bytes.Buffer
	if err := installTpl.Execute(&out, page); err != nil {
		panic(err)
	}
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("X-Frame-Options", "DENY")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	_, _ = w.Write(out.Bytes())
}

// strongPassword is /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/.
func strongPassword(p string) bool {
	if len(p) < 8 || strings.ContainsAny(p, "\n") {
		return false
	}
	var lo, up, dig bool
	for _, ch := range p {
		switch {
		case ch >= 'a' && ch <= 'z':
			lo = true
		case ch >= 'A' && ch <= 'Z':
			up = true
		case ch >= '0' && ch <= '9':
			dig = true
		}
	}
	return lo && up && dig
}

// dropdown is the same Tailwind dropdown as the app (no native <select>).
func dropdown(name string, options [][2]string, value, id string) template.HTML {
	h := template.HTMLEscapeString
	label := options[0][1]
	for _, o := range options {
		if o[0] == value {
			label = o[1]
		}
	}
	var items strings.Builder
	for _, o := range options {
		sel := "false"
		if o[0] == value {
			sel = "true"
		}
		items.WriteString(`<button type="button" role="option" data-value="` + h(o[0]) + `" data-label="` + h(o[1]) + `" aria-selected="` + sel + `" class="flex w-full items-center rounded-md px-2.5 py-1.5 text-left text-sm hover:bg-mist focus:bg-mist focus:outline-none aria-selected:font-medium">` + h(o[1]) + `</button>`)
	}
	return template.HTML(`<div class="relative" data-select><input type="hidden" name="` + h(name) + `" value="` + h(value) + `" data-select-input>` +
		`<button type="button" id="` + h(id) + `" data-select-button data-base="input" aria-haspopup="listbox" aria-expanded="false" class="input flex w-full items-center justify-between gap-2 text-left"><span data-select-label class="truncate">` + h(label) + `</span><span aria-hidden="true" class="text-graphite-400">&#8693;</span></button>` +
		`<div data-select-panel hidden role="listbox" class="fixed z-[70] min-w-44 rounded-lg bg-white p-1 shadow-xl ring-1 ring-graphite-900/10"><div class="max-h-64 overflow-y-auto">` + items.String() + `</div></div></div>`)
}

// runInstall does the work; lines it adds to logLines are HTML.
func runInstall(cfg *config.Map, v map[string]string, demo bool, password string, dbKeys []string, sqlDir string, logLines *[]template.HTML) (err error) {
	add := func(s string) { *logLines = append(*logLines, template.HTML(s)) }
	h := template.HTMLEscapeString
	ctx := context.Background()
	port := conv.Int(v["port"])
	dsn := func(name string) string { return db.DSNFor(v["host"], port, name, v["user"], v["pass"]) }
	defer func() {
		if rec := recover(); rec != nil {
			if e, ok := rec.(error); ok {
				err = e
			} else {
				err = fmt.Errorf("%v", rec)
			}
		}
		if err != nil {
			reloadConfig() // the running server goes back to what is saved
		}
	}()

	// 1. Server connection
	var server *pgx.Conn
	var last error
	for _, maint := range []string{"postgres", "template1", v["db_core"]} {
		c, e := pgx.Connect(ctx, dsn(maint))
		if e == nil {
			server = c
			break
		}
		last = e
	}
	if server == nil {
		return fmt.Errorf("Could not connect to PostgreSQL as \"%s\": %v", v["user"], last)
	}
	var version string
	_ = server.QueryRow(ctx, "SHOW server_version").Scan(&version)
	add("Connected to PostgreSQL " + version)

	// 2. Databases
	for _, k := range dbKeys {
		name := v["db_"+k]
		var one int
		e := server.QueryRow(ctx, "SELECT 1 FROM pg_database WHERE datname = $1", name).Scan(&one)
		if e == nil {
			add("Database " + name + " already exists — keeping it")
			continue
		}
		if _, e := server.Exec(ctx, `CREATE DATABASE "`+name+`" ENCODING 'UTF8'`); e != nil {
			server.Close(ctx)
			return fmt.Errorf("Could not create database %s. Give the user permission first (run database/sql/00_create_databases.sql as the postgres superuser), or create the database yourself. Details: %v", name, e)
		}
		add("Created database " + name)
	}
	server.Close(ctx)

	// 3. Tables
	conns := map[string]*pgx.Conn{}
	defer func() {
		for _, c := range conns {
			c.Close(ctx)
		}
	}()
	exec := func(c *pgx.Conn, sql string) {
		if _, e := c.PgConn().Exec(ctx, sql).ReadAll(); e != nil {
			panic(e)
		}
	}
	for _, f := range dbFiles {
		name, ok := v["db_"+f.key]
		if !ok {
			continue
		}
		c, e := pgx.Connect(ctx, dsn(name))
		if e != nil {
			return e
		}
		conns[f.key] = c
		sql, e := os.ReadFile(filepath.Join(sqlDir, f.file))
		if e != nil {
			return e
		}
		exec(c, string(sql))
		add("Tables ready in " + name)
	}
	core := conns["core"]
	if core == nil {
		return errors.New("No core database.")
	}

	// 4. Upgrades (database/sql/migrations) — new columns, settings … on the databases chosen here
	config.Set("db.host", v["host"])
	config.Set("db.port", port)
	config.Set("db.username", v["user"])
	config.Set("db.password", v["pass"])
	for _, k := range dbKeys {
		config.Set("db.databases."+k, v["db_"+k])
	}
	db.Close()
	ran, _, e := app.RunMigrations(nil, false)
	if e != nil {
		return e
	}
	for _, m := range ran {
		add("Applied " + h(m))
	}
	_ = os.MkdirAll(filepath.Join(app.BasePath, "storage", "cache"), 0o775)
	_ = os.WriteFile(filepath.Join(app.BasePath, "storage", "cache", "schema.version"), []byte(app.MigrationsFingerprint()), 0o664)

	// 5. Core data
	readSQL := func(f string) string {
		b, e := os.ReadFile(filepath.Join(sqlDir, f))
		if e != nil {
			panic(e)
		}
		return string(b)
	}
	exec(core, readSQL("user_db_seed.sql"))
	add("Roles, permissions and settings added")
	if demo {
		exec(core, readSQL("user_db_demo.sql"))
		add("Demo departments, teams, people, tasks and KPIs added")
		if crm := conns["crm"]; crm != nil {
			exec(crm, readSQL("crm_db_demo.sql"))
			add("Demo CRM leads, contacts, opportunities, activities and campaigns added")
		}
	}
	q := func(sql string, args ...any) {
		if _, e := core.Exec(ctx, sql, args...); e != nil {
			panic(e)
		}
	}
	adminName := strings.TrimSpace(v["admin"])
	if adminName == "" {
		adminName = "System Admin"
	}
	q("UPDATE users SET name = $1, email = $2, password = $3, is_active = TRUE, deleted_at = NULL, updated_at = now() WHERE id = 1",
		adminName, strings.ToLower(strings.TrimSpace(v["email"])), app.PasswordHash(password))
	// the admin signs in with this username (another account may hold it in the demo data: that one gets a number)
	q("UPDATE users SET username = NULL WHERE lower(username) = $1 AND id <> 1", v["username"])
	q("UPDATE users SET username = $1 WHERE id = 1", v["username"])
	q("UPDATE settings SET value = $1 WHERE key = 'company_name'", strings.TrimSpace(v["company"]))
	locale := v["locale"]
	if locale != "en" && locale != "th" {
		locale = "en"
	}
	q("INSERT INTO settings (key, value) VALUES ('default_locale', $1) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value", locale)
	q("INSERT INTO employee_profiles (user_id) SELECT id FROM users u WHERE NOT EXISTS (SELECT 1 FROM employee_profiles p WHERE p.user_id = u.id)")
	add("Admin account " + h(v["username"]) + " (" + h(v["email"]) + ") ready")

	// 6. Config file
	cfgSet(cfg, "app.name", strings.TrimSpace(v["company"]))
	appURL := strings.TrimRight(v["app_url"], "/")
	cfgSet(cfg, "app.url", appURL)
	if strings.HasPrefix(cfgStr(cfg, "app.key"), "change-me") {
		cfgSet(cfg, "app.key", randHex(32))
	}
	// Error details only on the developer's own computer; every real server runs in production mode.
	urlHost := ""
	if u, e := url.Parse(appURL); e == nil {
		urlHost = strings.ToLower(u.Hostname())
		if strings.Contains(u.Host, "[") {
			urlHost = "[" + urlHost + "]" // like PHP's parse_url
		}
	}
	if conv.InList(urlHost, "localhost", "127.0.0.1", "::1") {
		cfgSet(cfg, "app.env", "local")
	} else {
		cfgSet(cfg, "app.env", "production")
	}
	cfgSet(cfg, "db.host", v["host"])
	cfgSet(cfg, "db.port", port)
	cfgSet(cfg, "db.username", v["user"])
	cfgSet(cfg, "db.password", v["pass"])
	for _, k := range dbKeys {
		cfgSet(cfg, "db.databases."+k, v["db_"+k])
	}
	driver := "log"
	if v["mail"] == "smtp" {
		driver = "smtp"
	}
	enc := v["smtp_enc"]
	if !conv.InList(enc, "tls", "ssl", "none") {
		enc = "tls"
	}
	cfgSet(cfg, "mail.driver", driver)
	cfgSet(cfg, "mail.host", v["smtp_host"])
	cfgSet(cfg, "mail.port", conv.Int(v["smtp_port"]))
	cfgSet(cfg, "mail.encryption", enc)
	cfgSet(cfg, "mail.username", v["smtp_user"])
	cfgSet(cfg, "mail.password", v["smtp_pass"])
	cfgSet(cfg, "mail.from_address", v["from"])
	cfgSet(cfg, "mail.from_name", strings.TrimSpace(v["company"]))
	if e := writeConfig(cfg); e != nil {
		return e
	}
	add("Saved config/config.json")

	if e := os.WriteFile(lockPath(), []byte(conv.Date("c", conv.Now())), 0o664); e != nil {
		return e
	}
	log.Printf("installed: config/config.json and storage/installed.lock written")
	return nil
}

var installTpl = template.Must(template.New("install").Parse(`<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install · {{.V.company}}</title>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700&family=IBM+Plex+Sans+Thai:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{.CSS}}">
<script src="assets/app.js" defer></script>
</head>
<body class="min-h-screen bg-mist">
<header class="bg-graphite-900 text-white">
    <div class="mx-auto flex max-w-4xl items-center gap-3 px-6 py-5">
        <span class="flex size-9 items-center justify-center rounded bg-signal-600 font-display text-lg font-bold">A</span>
        <div><p class="font-display text-2xl font-semibold leading-none">Install the company system</p><p class="mt-1 text-sm text-graphite-300">Sets up PostgreSQL, the core tables and your admin account.</p></div>
    </div>
    <div class="h-1 bg-signal-600"></div>
</header>

<main class="mx-auto max-w-4xl px-6 py-8">
{{if .Reconnect}}
    <section class="panel p-6">
        <h1 class="page-title">Reconnect the database</h1>
        <p class="mt-2 text-sm text-steel">The system is installed, but <code>config/config.json</code> is missing and the usual PostgreSQL logins did not work. Enter the login of your existing database; nothing is changed in it, only the settings file is written again.</p>
        {{if not .LocalUser}}
            <p class="mt-4 rounded-md bg-mist p-4 text-sm">For safety this form works only on the server itself (<code>localhost</code>). On a server, copy <code>config/config.json.example</code> to <code>config/config.json</code> over SSH and fill in the database login.</p>
        {{else}}
            {{if .Errors}}<div class="mt-4 rounded-md border-l-4 border-signal-600 bg-signal-50 px-4 py-3 text-sm text-signal-800">{{range .Errors}}<p>{{.}}</p>{{end}}</div>{{end}}
            <form method="POST" class="mt-5 grid gap-5 sm:grid-cols-4">
                <input type="hidden" name="_token" value="{{.Token}}">
                <div class="sm:col-span-2"><label class="label" for="host">Host</label><input class="input" id="host" name="host" value="{{.V.host}}" required></div>
                <div><label class="label" for="port">Port</label><input class="input" id="port" name="port" value="{{.V.port}}" required></div>
                <div><label class="label" for="db_core">Core database</label><input class="input" id="db_core" name="db_core" value="{{.V.db_core}}" required></div>
                <div class="sm:col-span-2"><label class="label" for="user">Username</label><input class="input" id="user" name="user" value="{{.V.user}}" required autocomplete="off"></div>
                <div class="sm:col-span-2"><label class="label" for="pass">Password</label><input class="input" id="pass" name="pass" type="password" autocomplete="new-password"></div>
                <div class="sm:col-span-4 flex justify-end"><button class="btn-primary px-6 py-2.5">Connect and save</button></div>
            </form>
        {{end}}
    </section>
{{else if or .Done .Installed}}
    <section class="panel p-6">
        <h1 class="page-title">{{if .Done}}Installed{{else}}Already installed{{end}}</h1>
        {{if .Log}}<ul class="mt-4 space-y-1.5 text-sm">{{range .Log}}<li class="flex gap-2"><span class="text-emerald-600">&#10003;</span>{{.}}</li>{{end}}</ul>{{end}}
        {{if and .Done .Demo}}
            <div class="mt-6 rounded-md bg-mist p-4 text-sm">
                <p class="font-medium">Demo accounts (password <code>Password@123</code>)</p>
                <p class="mt-1 text-steel">Usernames: management · bu.manager · manager · member</p>
            </div>
        {{end}}
        <p class="mt-6 text-sm text-steel">The installer is now locked. To run it again, delete <code>storage/installed.lock</code>.</p>
        <a href="./" class="btn-primary mt-6">Go to sign in</a>
    </section>
{{else}}
    <section class="panel p-6">
        <h2 class="panel-title">Server check</h2>
        <ul class="mt-3 grid gap-1.5 text-sm sm:grid-cols-2">
            {{range .Checks}}
                <li class="flex items-center gap-2"><span class="{{if .OK}}text-emerald-600{{else}}text-signal-600{{end}}">{{if .OK}}&#10003;{{else}}&#10007;{{end}}</span>{{.Label}}</li>
            {{end}}
        </ul>
    </section>

    {{if .Errors}}
        <div class="mt-6 rounded-md border-l-4 border-signal-600 bg-signal-50 px-4 py-3 text-sm text-signal-800">{{range .Errors}}<p>{{.}}</p>{{end}}</div>
        {{if .Log}}<ul class="mt-3 space-y-1 text-sm text-steel">{{range .Log}}<li>&#10003; {{.}}</li>{{end}}</ul>{{end}}
    {{end}}

    <form method="POST" class="mt-6 space-y-6">
        <input type="hidden" name="_token" value="{{.Token}}">

        <fieldset class="panel">
            <div class="panel-head"><h2 class="panel-title">Company and admin account</h2></div>
            <div class="grid gap-5 p-6 sm:grid-cols-2">
                <div><label class="label" for="company">Company name</label><input class="input" id="company" name="company" value="{{.V.company}}" required></div>
                <div><label class="label" for="locale">Default language</label>{{.Locale}}<p class="hint">People can choose their own later.</p></div>
                <div><label class="label" for="app_url">Site address</label><input class="input" id="app_url" name="app_url" value="{{.V.app_url}}" required><p class="hint">Used in e-mail links.</p></div>
                <div><label class="label" for="admin">Admin name</label><input class="input" id="admin" name="admin" value="{{.V.admin}}" required></div>
                <div><label class="label" for="username">Admin username (you sign in with this)</label><input class="input" id="username" name="username" value="{{.V.username}}" required autocomplete="off" autocapitalize="none"></div>
                <div><label class="label" for="email">Admin e-mail</label><input class="input" id="email" name="email" type="email" value="{{.V.email}}" required><p class="hint">For password resets and notifications.</p></div>
                <div><label class="label" for="password">Admin password</label><input class="input" id="password" name="password" type="password" required autocomplete="new-password"><p class="hint">8+ characters, upper and lower case and a number.</p></div>
                <div><label class="label" for="password_confirmation">Repeat password</label><input class="input" id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"></div>
                <label class="flex items-start gap-3 text-sm sm:col-span-2"><input type="checkbox" name="demo" value="1" class="mt-0.5 size-4 accent-signal-600" {{if .Demo}}checked{{end}}><span><span class="font-medium">Add demo data</span><span class="block text-steel">Sample departments, teams, one person per role, tasks and KPIs, so you can see how each role works. Untick for a clean start.</span></span></label>
            </div>
        </fieldset>

        <fieldset class="panel">
            <div class="panel-head"><h2 class="panel-title">PostgreSQL</h2></div>
            <div class="grid gap-5 p-6 sm:grid-cols-4">
                <div class="sm:col-span-2"><label class="label" for="host">Host</label><input class="input" id="host" name="host" value="{{.V.host}}" required></div>
                <div><label class="label" for="port">Port</label><input class="input" id="port" name="port" value="{{.V.port}}" required></div>
                <div></div>
                <div class="sm:col-span-2"><label class="label" for="user">Username</label><input class="input" id="user" name="user" value="{{.V.user}}" required></div>
                <div class="sm:col-span-2"><label class="label" for="pass">Password</label><input class="input" id="pass" name="pass" type="password" autocomplete="new-password"{{if .SavedDBPass}} placeholder="Saved — leave blank to keep it"{{end}}></div>
            </div>
            <div class="border-t border-graphite-900/8 px-6 py-5">
                <p class="text-sm font-medium">Databases (created if missing)</p>
                <div class="mt-3 grid gap-4 sm:grid-cols-3">
                    {{range .DBs}}
                        <div><label class="label" for="db_{{.Key}}">{{.Label}}</label><input class="input" id="db_{{.Key}}" name="db_{{.Key}}" value="{{index $.V (printf "db_%s" .Key)}}" required></div>
                    {{end}}
                </div>
            </div>
        </fieldset>

        <fieldset class="panel">
            <div class="panel-head"><h2 class="panel-title">E-mail</h2></div>
            <div class="grid gap-5 p-6 sm:grid-cols-4">
                <div class="sm:col-span-4 flex flex-wrap gap-6 text-sm">
                    <label class="flex items-center gap-2"><input type="radio" name="mail" value="log" class="accent-signal-600" {{if ne .V.mail "smtp"}}checked{{end}}> Save e-mails to files (storage/mail) — good for testing</label>
                    <label class="flex items-center gap-2"><input type="radio" name="mail" value="smtp" class="accent-signal-600" {{if eq .V.mail "smtp"}}checked{{end}}> Send with SMTP</label>
                </div>
                <div class="sm:col-span-2"><label class="label" for="smtp_host">SMTP host</label><input class="input" id="smtp_host" name="smtp_host" value="{{.V.smtp_host}}"></div>
                <div><label class="label" for="smtp_port">Port</label><input class="input" id="smtp_port" name="smtp_port" value="{{.V.smtp_port}}"></div>
                <div><label class="label" for="smtp_enc">Security</label>{{.SMTPEnc}}</div>
                <div class="sm:col-span-2"><label class="label" for="smtp_user">SMTP username</label><input class="input" id="smtp_user" name="smtp_user" value="{{.V.smtp_user}}" autocomplete="off"></div>
                <div class="sm:col-span-2"><label class="label" for="smtp_pass">SMTP password</label><input class="input" id="smtp_pass" name="smtp_pass" type="password" autocomplete="new-password"{{if .SavedMailPass}} placeholder="Saved — leave blank to keep it"{{end}}><p class="hint">For Gmail use an App Password.</p></div>
                <div class="sm:col-span-2"><label class="label" for="from">Send from address</label><input class="input" id="from" name="from" type="email" value="{{.V.from}}"></div>
            </div>
        </fieldset>

        <div class="flex items-center justify-end gap-4">
            <p class="text-sm text-steel">Takes a few seconds. Safe to run again: existing data is kept.</p>
            <button class="btn-primary px-6 py-2.5" {{if not .Ready}}disabled{{end}}>Install now</button>
        </div>
    </form>
{{end}}
</main>
</body>
</html>
`))
