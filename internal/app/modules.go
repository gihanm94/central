package app

import (
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"regexp"
	"runtime/debug"
	"sort"
	"strings"
	"sync"
	"time"

	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/php"
)

// MenuItem is a sidebar entry or heading.
type MenuItem struct {
	Heading       string
	Label         string
	URL           string
	Icon          string
	Permission    string   // "resource.action"
	PermissionAny []string // any one is enough
	AdminOnly     bool
}

// Dashboard is a page people can pick as their default.
type Dashboard struct {
	Key, Label, URL string
}

// Module is a plug-in area (app/Modules/<Name>/module.php in the PHP version).
type Module struct {
	Key           string
	Name          string
	Icon          string
	Home          string
	Enabled       bool
	DB            string // database connection name
	Dashboards    []Dashboard
	Menu          []MenuItem
	Permission    string
	PermissionAny []string
	Paths         []string // core pages shown under this module
	// Hooks by name; each value is a func whose signature the caller knows (see RunHooks).
	Hooks map[string]any
	// Routes registers the module's pages (routes.php). They sit inside the signed-in group (app.Auth already applies)
	// and are only reached while the module is enabled and not fenced off.
	Routes func(r Router)
	// API registers the module's /api/v1/<key>/… endpoints (api.php); paths are relative to /api/v1.
	API func(r Router)
	// MigrationsDir: the module's own SQL upgrades (remembered as "<key>/<file>"), relative to BasePath.
	MigrationsDir string
}

var (
	modMu    sync.RWMutex
	registry = map[string]*Module{}
	order    []string
)

// RegisterModule adds a module (called from each module package's init).
func RegisterModule(m *Module) {
	modMu.Lock()
	defer modMu.Unlock()
	if m.Icon == "" {
		m.Icon = "grid"
	}
	if m.Home == "" {
		m.Home = "/" + m.Key
	}
	if m.DB == "" {
		m.DB = m.Key
	}
	if _, ok := registry[m.Key]; !ok {
		order = append(order, m.Key)
	}
	registry[m.Key] = m
}

// BootModules merges config/modules.json (core entry, overrides, placeholders) into the registry.
func BootModules() {
	cfg := config.MapAt("modules")
	modMu.Lock()
	defer modMu.Unlock()
	for _, key := range cfg.Keys {
		ov, _ := cfg.Vals[key].(*config.Map)
		m, ok := registry[key]
		if !ok {
			m = &Module{Key: key, Name: php.Ucfirst(key), Icon: "grid", Home: "/" + key, DB: key, Enabled: false}
			registry[key] = m
			order = append(order, key)
		}
		applyOverrides(m, ov)
	}
	// core first, then the configured order, then the rest
	sorted := []string{}
	seen := map[string]bool{}
	add := func(k string) {
		if !seen[k] && registry[k] != nil {
			seen[k] = true
			sorted = append(sorted, k)
		}
	}
	add("core")
	for _, k := range cfg.Keys {
		add(k)
	}
	rest := append([]string(nil), order...)
	sort.Strings(rest) // modules not named in config follow in folder-name order, like PHP's glob
	for _, k := range rest {
		add(k)
	}
	order = sorted
}

func applyOverrides(m *Module, ov *config.Map) {
	if ov == nil {
		return
	}
	for _, k := range ov.Keys {
		v := ov.Vals[k]
		switch k {
		case "name":
			m.Name = php.Str(v)
		case "icon":
			m.Icon = php.Str(v)
		case "home":
			m.Home = php.Str(v)
		case "enabled":
			m.Enabled = config.AsBool(v, m.Enabled)
		case "db":
			m.DB = php.Str(v)
		case "permission":
			m.Permission = php.Str(v)
		case "permission_any":
			m.PermissionAny = config.AsStrings(v)
		case "paths":
			m.Paths = config.AsStrings(v)
		case "dashboards":
			m.Dashboards = nil
			if dm, ok := v.(*config.Map); ok {
				for _, dk := range dm.Keys {
					d, _ := dm.Vals[dk].(*config.Map)
					l, _ := d.Get("label")
					u, _ := d.Get("url")
					m.Dashboards = append(m.Dashboards, Dashboard{Key: dk, Label: php.Str(l), URL: php.Str(u)})
				}
			}
		case "menu":
			m.Menu = MenuFromConfig(v)
		}
	}
}

// MenuFromConfig reads menu entries from config.
func MenuFromConfig(v any) []MenuItem {
	var out []MenuItem
	for _, e := range InList(v) {
		em, ok := e.(*config.Map)
		if !ok {
			continue
		}
		g := func(k string) string { x, _ := em.Get(k); return php.Str(x) }
		pa, _ := em.Get("permission_any")
		ao, _ := em.Get("admin_only")
		out = append(out, MenuItem{Heading: g("heading"), Label: g("label"), URL: g("url"), Icon: g("icon"), Permission: g("permission"),
			PermissionAny: config.AsStrings(pa), AdminOnly: config.AsBool(ao, false)})
	}
	return out
}

// Modules lists every module, core first.
func Modules() []*Module {
	modMu.RLock()
	defer modMu.RUnlock()
	out := make([]*Module, 0, len(order))
	for _, k := range order {
		out = append(out, registry[k])
	}
	return out
}

// ModuleByKey returns a module (an empty disabled one when unknown).
func ModuleByKey(key string) *Module {
	modMu.RLock()
	defer modMu.RUnlock()
	if m, ok := registry[key]; ok {
		return m
	}
	return &Module{Key: key, Name: php.Ucfirst(key), Icon: "grid", Home: "/" + key}
}

func ModuleExists(key string) bool {
	modMu.RLock()
	defer modMu.RUnlock()
	_, ok := registry[key]
	return ok
}

func ModuleEnabled(key string) bool { return ModuleExists(key) && ModuleByKey(key).Enabled }

// ModuleUsable: enabled and not fenced off by a failure.
func ModuleUsable(key string) bool { return ModuleEnabled(key) && len(ModuleProblems(key)) == 0 }

// ModuleAllowed: may this person open the module (its permission / permission_any)?
func (c *Ctx) ModuleAllowed(key string) bool {
	m := ModuleByKey(key)
	check := func(perm string) bool {
		res, act, ok := strings.Cut(perm, ".")
		if !ok {
			act = "view"
		}
		if res == "admin" {
			return c.User().IsAdmin()
		}
		return c.Can(res, act)
	}
	if m.Permission != "" && !check(m.Permission) {
		return false
	}
	if len(m.PermissionAny) > 0 {
		for _, p := range m.PermissionAny {
			if check(p) {
				return true
			}
		}
		return false
	}
	return true
}

// ModuleForDisplay: the module a page belongs to for the menu (a module may claim core pages via Paths).
func (c *Ctx) ModuleForDisplay(path string) string {
	seg := strings.SplitN(strings.Trim(path, "/"), "/", 2)[0]
	if ModuleEnabled(seg) {
		return seg
	}
	best, l := "core", 0
	for _, m := range Modules() {
		if !m.Enabled || !c.ModuleAllowed(m.Key) {
			continue
		}
		for _, p := range m.Paths {
			p = "/" + strings.Trim(p, "/")
			if (path == p || strings.HasPrefix(path, p+"/")) && len(p) > l {
				best, l = m.Key, len(p)
			}
		}
	}
	return best
}

// ModuleForPath: the module a URL belongs to (/crm/leads → crm), "" for core pages.
func ModuleForPath(path string) string {
	seg := strings.SplitN(strings.Trim(path, "/"), "/", 2)[0]
	if seg != "core" && ModuleExists(seg) {
		return seg
	}
	return ""
}

// RunHooks calls hook name of every usable module (or only `only`); a hook that panics is logged and skipped.
// call receives the module key and the hook value and returns its result.
func RunHooks(name, only string, call func(key string, fn any) any) map[string]any {
	out := map[string]any{}
	for _, m := range Modules() {
		fn, ok := m.Hooks[name]
		if !ok || (only != "" && m.Key != only) || !m.Enabled || fenced(m.Key) {
			continue
		}
		var res any
		err := Catch(func() { res = call(m.Key, fn) })
		if err != nil {
			ModuleFail(m.Key, "hook:"+name, err)
			continue
		}
		ModuleOK(m.Key, "hook:"+name)
		out[m.Key] = res
	}
	return out
}

// ---------------------------------------------------------------- health status (storage/cache/modules-status.json)

var blocking = []string{"manifest", "migrations", "routes"}

type moduleFailure struct {
	Message string `json:"message"`
	Class   string `json:"class"`
	File    string `json:"file"`
	Line    int    `json:"line"`
	At      string `json:"at,omitempty"`
}

var (
	statusMu sync.Mutex
	status   map[string]map[string]moduleFailure
)

func statusFile() string { return filepath.Join(BasePath, "storage", "cache", "modules-status.json") }

func loadStatus() map[string]map[string]moduleFailure {
	if status == nil {
		status = map[string]map[string]moduleFailure{}
		if raw, err := os.ReadFile(statusFile()); err == nil {
			_ = json.Unmarshal(raw, &status)
		}
	}
	return status
}

// ModuleStatus is every recorded failure.
func ModuleStatus() map[string]map[string]moduleFailure {
	statusMu.Lock()
	defer statusMu.Unlock()
	s := loadStatus()
	out := make(map[string]map[string]moduleFailure, len(s))
	for k, v := range s {
		out[k] = v
	}
	return out
}

// ModuleProblems are a module's blocking failures (empty when healthy).
func ModuleProblems(key string) map[string]moduleFailure {
	statusMu.Lock()
	defer statusMu.Unlock()
	out := map[string]moduleFailure{}
	for _, b := range blocking {
		if f, ok := loadStatus()[key][b]; ok {
			out[b] = f
		}
	}
	return out
}

func fenced(key string) bool {
	statusMu.Lock()
	defer statusMu.Unlock()
	s := loadStatus()[key]
	_, a := s["manifest"]
	_, b := s["migrations"]
	return a || b
}

// ModuleFail records a failure of a module.
func ModuleFail(key, where string, err error) {
	log.Printf("[module:%s] %s failed: %v", key, where, err)
	statusMu.Lock()
	defer statusMu.Unlock()
	s := loadStatus()
	entry := moduleFailure{Message: err.Error(), Class: fmt.Sprintf("%T", errors.Unwrap(err))}
	if entry.Class == "<nil>" {
		entry.Class = fmt.Sprintf("%T", err)
	}
	if old, ok := s[key][where]; ok && old.Message == entry.Message {
		return
	}
	entry.At = php.Date("c", php.Now())
	if s[key] == nil {
		s[key] = map[string]moduleFailure{}
	}
	s[key][where] = entry
	saveStatus(s)
}

// ModuleOK forgets a failure.
func ModuleOK(key, where string) {
	statusMu.Lock()
	defer statusMu.Unlock()
	s := loadStatus()
	if _, ok := s[key][where]; !ok {
		return
	}
	delete(s[key], where)
	if len(s[key]) == 0 {
		delete(s, key)
	}
	saveStatus(s)
}

// ModuleClear forgets recorded failures (all, or one module's).
func ModuleClear(key string) {
	statusMu.Lock()
	defer statusMu.Unlock()
	s := loadStatus()
	if key == "" {
		for k := range s {
			delete(s, k)
		}
	} else {
		delete(s, key)
	}
	saveStatus(s)
}

func saveStatus(s map[string]map[string]moduleFailure) {
	_ = os.MkdirAll(filepath.Dir(statusFile()), 0o775)
	b, _ := json.MarshalIndent(s, "", "    ")
	_ = os.WriteFile(statusFile(), b, 0o664)
}

// ---------------------------------------------------------------- migrations

type migrationSet struct {
	owner, conn string
	files       []string
	module      string
}

// MigrationsDir is database/sql/migrations.
func MigrationsDir() string { return filepath.Join(BasePath, "database", "sql", "migrations") }

func migrationSets() []migrationSet {
	var sets []migrationSet
	dirs, _ := filepath.Glob(filepath.Join(MigrationsDir(), "*"))
	sort.Strings(dirs)
	for _, d := range dirs {
		if st, err := os.Stat(d); err != nil || !st.IsDir() {
			continue
		}
		files, _ := filepath.Glob(filepath.Join(d, "*.sql"))
		sort.Strings(files)
		sets = append(sets, migrationSet{filepath.Base(d), filepath.Base(d), files, ""})
	}
	for _, m := range Modules() {
		if m.MigrationsDir == "" {
			continue
		}
		files, _ := filepath.Glob(filepath.Join(BasePath, m.MigrationsDir, "*.sql"))
		sort.Strings(files)
		sets = append(sets, migrationSet{m.Key, m.DB, files, m.Key})
	}
	return sets
}

// MigrationsFingerprint changes when migration files are added.
func MigrationsFingerprint() string {
	var names []string
	for _, s := range migrationSets() {
		for _, f := range s.files {
			names = append(names, s.owner+"/"+filepath.Base(f))
		}
	}
	return fmt.Sprintf("%x", md5Sum(strings.Join(names, "|")))
}

var migrateMu sync.Mutex

// AutoMigrate: cheap check on every request, real work only when files changed (one request at a time).
func AutoMigrate() {
	stamp := filepath.Join(BasePath, "storage", "cache", "schema.version")
	fp := MigrationsFingerprint()
	raw, _ := os.ReadFile(stamp)
	saved := strings.TrimSpace(string(raw))
	if saved == fp {
		return
	}
	if strings.HasPrefix(saved, fp+"|retry|") && time.Now().Unix()-int64(php.Int(saved[len(fp)+7:])) < 60 {
		return
	}
	if !migrateMu.TryLock() {
		return
	}
	defer migrateMu.Unlock()
	_, failed, err := RunMigrations(nil, true)
	if err != nil {
		panic(err)
	}
	_ = os.MkdirAll(filepath.Dir(stamp), 0o775)
	if failed {
		_ = os.WriteFile(stamp, []byte(fp+"|retry|"+php.Str(int(time.Now().Unix()))), 0o664)
	} else {
		_ = os.WriteFile(stamp, []byte(fp), 0o664)
	}
}

// RunMigrations runs every new SQL file once, in name order. isolate: a failing module is recorded against it and
// the others still run (a core failure is returned). connect overrides the connection (installer).
func RunMigrations(connect func(conn string) db.Q, isolate bool) (ran []string, failed bool, err error) {
	for _, s := range migrationSets() {
		q := db.On(s.conn)
		if connect != nil {
			q = connect(s.conn)
		}
		e := Catch(func() {
			q.ExecScript("SET statement_timeout = 0")
			q.ExecScript("CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(190) PRIMARY KEY, ran_at TIMESTAMPTZ NOT NULL DEFAULT now())")
			done := map[string]bool{}
			for _, v := range q.Column("SELECT name FROM schema_migrations") {
				done[php.Str(v)] = true
			}
			for _, f := range s.files {
				name := filepath.Base(f)
				if s.module != "" {
					name = s.module + "/" + name
				}
				if done[name] {
					continue
				}
				sql, rerr := os.ReadFile(f)
				if rerr != nil {
					panic(rerr)
				}
				if ferr := Catch(func() { q.ExecScript(string(sql)) }); ferr != nil {
					panic(fmt.Errorf("Migration %s/%s failed: %v", s.owner, filepath.Base(f), ferr))
				}
				q.Exec("INSERT INTO schema_migrations (name) VALUES (?)", name)
				ran = append(ran, s.owner+"/"+filepath.Base(f))
			}
			if connect == nil {
				q.ExecScript(fmt.Sprintf("SET statement_timeout = %d", db.StatementTimeoutMS()))
			}
		})
		if e == nil {
			if isolate && (s.module != "" || s.conn != "core") {
				markMigrations(s, nil)
			}
			continue
		}
		if !isolate || (s.module == "" && s.conn == "core") {
			return ran, true, e
		}
		failed = true
		markMigrations(s, e)
	}
	return ran, failed, nil
}

func markMigrations(s migrationSet, err error) {
	if s.module != "" {
		if err != nil {
			ModuleFail(s.module, "migrations", err)
		} else {
			ModuleOK(s.module, "migrations")
		}
		return
	}
	hit := false
	for _, m := range Modules() {
		if m.Key != "core" && m.DB == s.conn {
			if err != nil {
				ModuleFail(m.Key, "migrations", err)
			} else {
				ModuleOK(m.Key, "migrations")
			}
			hit = true
		}
	}
	if err != nil && !hit {
		log.Printf("[migrations:%s] %v\n%s", s.conn, err, debug.Stack())
	}
}

var _ = regexp.MustCompile

// Commands are command-line jobs (acme <name> …): bin/erp-sync.php and friends. Each returns the exit code.
var Commands = map[string]func(args []string) int{}
