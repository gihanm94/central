package app

import (
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"regexp"
	"strings"
	"time"

	"acme/internal/config"
	"acme/internal/conv"
	"acme/internal/db"

	"golang.org/x/crypto/bcrypt"
)

// CurrentUser is the signed-in person with role and permission helpers (App\Core\Auth\CurrentUser).
type CurrentUser struct {
	ID               int
	Name             string
	Email            string
	Username         string
	RoleID           int
	RoleSlug         string
	RoleName         string
	RoleLevel        int
	RoleScope        string
	DataScope        string
	DepartmentID     int // 0 = none
	TeamID           int // 0 = none
	DepartmentName   string
	TeamName         string
	JobTitle         string
	Avatar           string
	SessionVersion   int
	LastLoginAt      string
	Locale           string
	DefaultModule    string
	DefaultDashboard string
	NotifyPrefs      map[string]any
	UIScale          int
	Row              db.Row
	perms            map[string]map[string]bool // loaded once per request, like the PHP static cache
}

func newCurrentUser(r db.Row) *CurrentUser {
	u := &CurrentUser{
		ID: r.Int("id"), Name: r.Str("name"), Email: r.Str("email"), Username: r.Str("username"),
		RoleID: r.Int("role_id"), RoleSlug: r.Str("role_slug"), RoleName: r.Str("role_name"), RoleLevel: r.Int("role_level"),
		RoleScope: r.Str("role_scope"), DataScope: r.Str("data_scope"), DepartmentID: r.Int("department_id"), TeamID: r.Int("team_id"),
		DepartmentName: r.Str("department_name"), TeamName: r.Str("team_name"), JobTitle: r.Str("job_title"), Avatar: r.Str("avatar"),
		SessionVersion: r.Int("session_version"), LastLoginAt: r.Str("last_login_at"), Locale: r.Str("locale"),
		DefaultModule: r.Str("default_module"), DefaultDashboard: r.Str("default_dashboard"), Row: r,
	}
	_ = json.Unmarshal([]byte(r.Str("notify_prefs")), &u.NotifyPrefs)
	if u.NotifyPrefs == nil {
		u.NotifyPrefs = map[string]any{}
	}
	u.UIScale = conv.Int(r["ui_scale"])
	if u.UIScale == 0 {
		u.UIScale = 100
	}
	u.UIScale = max(70, min(150, u.UIScale))
	return u
}

func (u *CurrentUser) IsAdmin() bool { return u != nil && u.RoleSlug == "admin" }

func (u *CurrentUser) HasRole(slugs ...string) bool {
	return u != nil && conv.InList(u.RoleSlug, slugs...)
}

// DeptID / TeamIDOrNil are the ids as SQL values (nil when unset).
func (u *CurrentUser) DeptID() any {
	if u.DepartmentID == 0 {
		return nil
	}
	return u.DepartmentID
}

func (u *CurrentUser) TeamIDOrNil() any {
	if u.TeamID == 0 {
		return nil
	}
	return u.TeamID
}

// Scope is the effective data scope: all | department | team | own.
func (u *CurrentUser) Scope() string {
	if u.IsAdmin() {
		return "all"
	}
	s := u.DataScope
	if s == "" {
		s = u.RoleScope
	}
	switch {
	case s == "department" && u.DepartmentID == 0:
		return "own"
	case s == "team" && u.TeamID == 0:
		return "own"
	}
	return s
}

func (u *CurrentUser) Can(resource, action string) bool {
	if u == nil {
		return false
	}
	if u.IsAdmin() {
		return true
	}
	if u.perms == nil {
		u.perms = PermissionMatrix(u.ID, u.RoleID)
	}
	return u.perms[resource][action]
}

// ForgetPermissions makes the next Can read the matrix again (after it changed).
func (u *CurrentUser) ForgetPermissions() { u.perms = nil }

func (u *CurrentUser) FirstName() string {
	f := strings.Fields(u.Name)
	if len(f) == 0 {
		return ""
	}
	return f[0]
}

func (u *CurrentUser) Initials() string { return conv.Initials(u.Name) }

// HomeURL is where to land after sign-in: the default module's home.
func (u *CurrentUser) HomeURL() string {
	m := "core"
	if u.DefaultModule != "" && u.DefaultModule != "core" && ModuleUsable(u.DefaultModule) {
		m = u.DefaultModule
	}
	return ModuleByKey(m).Home
}

// ---------------------------------------------------------------- Auth

const rememberCookie = "acme_remember"

// LoadUser reads an active person with role, department, team and job title.
func LoadUser(id int) *CurrentUser {
	r := db.First(`SELECT u.*, r.slug AS role_slug, r.name AS role_name, r.level AS role_level, r.data_scope AS role_scope,
                    d.name AS department_name, t.name AS team_name, p.job_title
               FROM users u
               JOIN roles r ON r.id = u.role_id
          LEFT JOIN departments d ON d.id = u.department_id
          LEFT JOIN teams t ON t.id = u.team_id
          LEFT JOIN employee_profiles p ON p.user_id = u.id
              WHERE u.id = ? AND u.is_active AND u.deleted_at IS NULL`, id)
	if r == nil {
		return nil
	}
	return newCurrentUser(r)
}

// User is Auth::user(): the session, else a remember-me cookie.
func (c *Ctx) User() *CurrentUser {
	if c.userResolved {
		return c.user
	}
	c.userResolved = true
	if c.Sess == nil {
		return nil
	}
	if uid := c.Sess.Int("uid"); uid > 0 {
		u := LoadUser(uid)
		if u != nil && u.SessionVersion == c.Sess.Int("sv") {
			why := c.guardCheck(u.ID)
			if why == "" {
				c.user = u
				return u
			}
			c.clearSession()
			c.forgetRemember()
			if why == "expired" {
				c.Flash("error", c.T("For your security you were signed out after :h hours. Please sign in again.", "h", SessionMaxHours()))
			} else {
				c.Flash("error", c.T("This session was ended. Please sign in again."))
			}
			return nil
		}
		c.clearSession()
	}
	if ck := c.Cookie(rememberCookie); ck != "" {
		u, started := c.fromRemember(ck)
		if u != nil {
			c.startSession(u, "remember", started)
			c.issueRemember(u.ID, started)
			LogActivity(c, ActivityEntry{Action: "login", Type: "user", ID: u.ID, Label: u.Name, Text: "Signed in automatically (remember me)", Actor: u.ID, NoNotify: true})
			c.user = u
			return u
		}
		c.forgetRemember()
	}
	return nil
}

// ID is Auth::id() (0 for guests).
func (c *Ctx) UserID() int {
	if u := c.User(); u != nil {
		return u.ID
	}
	return 0
}

// ActAs: the API authenticated this request with a token.
func (c *Ctx) ActAs(u *CurrentUser) {
	c.user = u
	c.userResolved = true
}

// Can is can($resource, $action).
func (c *Ctx) Can(resource, action string) bool { return c.User().Can(resource, action) }

// MustUser is $this->user(): the person, or 401.
func (c *Ctx) MustUser() *CurrentUser {
	u := c.User()
	if u == nil {
		Abort(http.StatusUnauthorized)
	}
	return u
}

const dummyHash = "$2y$10$USo5amzlXOl..rh2CsA1O.CQLOjNh3Mn.SEy/SL1wgN/p1bMJC/by"

// VerifyLogin checks a sign-in name (username or e-mail) and password with the lockouts.
// Returns the user id, or 0 with a message (and seconds locked when locked).
func (c *Ctx) VerifyLogin(email, password, method string) (int, string, int) {
	email = strings.TrimSpace(email)
	key := strings.ToLower(email) + "|" + c.IP()
	lock := db.First("SELECT attempts, locked_until FROM login_attempts WHERE key = ?", key)
	if lock != nil && lock.Str("locked_until") != "" && conv.StrToTime(lock["locked_until"]) > time.Now().Unix() {
		wait := max(1, int(conv.StrToTime(lock["locked_until"])-time.Now().Unix()))
		return 0, c.T("Too many attempts. Try again in :seconds seconds.", "seconds", wait), wait
	}
	var row db.Row
	if strings.Contains(email, "@") {
		row = db.First("SELECT id, password, is_active FROM users WHERE lower(email) = lower(?) AND deleted_at IS NULL", email)
	} else {
		row = db.First("SELECT id, password, is_active FROM users WHERE lower(username) = lower(?) AND deleted_at IS NULL", email)
	}
	acct := "acct|" + strings.ToLower(email)
	if row != nil {
		acct = "acct|#" + row.Str("id")
	}
	acctLock := db.First("SELECT attempts, locked_until FROM login_attempts WHERE key = ?", acct)
	if acctLock != nil && acctLock.Str("locked_until") != "" && conv.StrToTime(acctLock["locked_until"]) > time.Now().Unix() {
		wait := max(1, int(conv.StrToTime(acctLock["locked_until"])-time.Now().Unix()))
		return 0, c.T("Too many attempts. Try again in :seconds seconds.", "seconds", wait), wait
	}
	hash := dummyHash
	if row != nil {
		hash = row.Str("password")
	}
	ok := PasswordVerify(password, hash) && row != nil
	if !ok || !row.Bool("is_active") {
		maxTries := config.Int("security.max_login_attempts", 5)
		attempts := 1
		if lock != nil {
			attempts = lock.Int("attempts") + 1
		}
		var until any
		n := attempts
		if attempts >= maxTries {
			n = 0
			until = conv.Date("c", conv.Now().Add(time.Duration(config.Int("security.lockout_minutes", 1))*time.Minute))
		}
		db.Exec(`INSERT INTO login_attempts (key, attempts, locked_until) VALUES (?, ?, ?)
                      ON CONFLICT (key) DO UPDATE SET attempts = EXCLUDED.attempts, locked_until = EXCLUDED.locked_until`, key, n, until)
		acctTries := 1
		if acctLock != nil {
			acctTries = acctLock.Int("attempts") + 1
		}
		var acctUntil any
		an := acctTries
		if acctTries >= 10 {
			an = 0
			acctUntil = conv.Date("c", conv.Now().Add(15*time.Minute))
		}
		db.Exec(`INSERT INTO login_attempts (key, attempts, locked_until) VALUES (?, ?, ?)
                      ON CONFLICT (key) DO UPDATE SET attempts = EXCLUDED.attempts, locked_until = EXCLUDED.locked_until`, acct, an, acctUntil)
		var rid int
		if row != nil {
			rid = row.Int("id")
		}
		LogActivity(c, ActivityEntry{Action: "login_failed", Text: "Failed sign-in for :name", Params: []any{"name", email},
			Props: M{"email": email, "method": method}, Actor: rid, NoNotify: true})
		GuardEvent(c, rid, "failed", method, "", email)
		if ok {
			return 0, c.T("This account is deactivated. Contact your administrator."), 0
		}
		return 0, c.T("These details do not match an active account."), 0
	}
	db.Exec("DELETE FROM login_attempts WHERE key IN (?, ?)", key, acct)
	if PasswordNeedsRehash(row.Str("password")) {
		db.Exec("UPDATE users SET password = ? WHERE id = ?", PasswordHash(password), row.Int("id"))
	}
	return row.Int("id"), "", 0
}

// Attempt signs in with a password; returns "" or the error message.
func (c *Ctx) Attempt(email, password string, remember bool) string {
	id, msg, _ := c.VerifyLogin(email, password, "password")
	if msg != "" {
		return msg
	}
	c.Login(LoadUser(id), remember, "password")
	return ""
}

// LoginAlert is set by the notifier: the "new sign-in" security e-mail.
var LoginAlert func(c *Ctx, u *CurrentUser, method string)

// Login is the final step of every sign-in method.
func (c *Ctx) Login(u *CurrentUser, remember bool, method string) {
	c.startSession(u, method, "")
	if remember {
		c.issueRemember(u.ID, "")
	}
	db.Exec("UPDATE users SET last_login_at = now(), last_login_ip = ? WHERE id = ?", c.IP(), u.ID)
	c.user = u
	c.userResolved = true
	LogActivity(c, ActivityEntry{Action: "login", Type: "user", ID: u.ID, Label: u.Name, Text: "Signed in with :method", Params: []any{"method", method},
		Props: M{"method": method}, Actor: u.ID, NoNotify: true})
	if config.Bool("notifications.login_alert", true) && LoginAlert != nil {
		LoginAlert(c, u, method)
	}
}

// Logout signs this browser out.
func (c *Ctx) Logout() {
	u := c.User()
	if u != nil {
		LogActivity(c, ActivityEntry{Action: "logout", Type: "user", ID: u.ID, Label: u.Name, Text: "Signed out", Actor: u.ID, NoNotify: true})
	}
	c.guardEnd("logout")
	if u != nil {
		GuardEvent(c, u.ID, "logout", "", "", "")
	}
	if ck := c.Cookie(rememberCookie); ck != "" {
		sel := strings.SplitN(ck, ":", 2)[0]
		db.Exec("DELETE FROM remember_tokens WHERE selector = ?", sel)
	}
	c.forgetRemember()
	c.clearSession()
	c.user = nil
}

// LogoutOtherDevices: sign out everywhere else (password change); this browser stays signed in.
func (c *Ctx) LogoutOtherDevices(userID int) {
	db.Exec("UPDATE users SET session_version = session_version + 1 WHERE id = ?", userID)
	c.Sess.Set("sv", conv.Int(db.Scalar("SELECT session_version FROM users WHERE id = ?", userID)))
	cur := strings.SplitN(c.Cookie(rememberCookie), ":", 2)[0]
	db.Exec("DELETE FROM remember_tokens WHERE user_id = ? AND selector <> ?", userID, cur)
	RevokeAllSessions(c, userID, c.Sess.Int("sid"), userID)
}

func (c *Ctx) startSession(u *CurrentUser, method, startedAt string) {
	c.Sess.Regenerate(false)
	c.Sess.Delete("_token")
	c.Sess.Set("uid", u.ID)
	c.Sess.Set("sv", u.SessionVersion)
	c.guardStart(u.ID, method, startedAt)
}

func (c *Ctx) clearSession() {
	if c.Sess == nil {
		return
	}
	c.Sess.Clear()
	c.Sess.Regenerate(false)
}

func randHex(n int) string {
	b := make([]byte, n)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

func sha256Hex(s string) string {
	h := sha256.Sum256([]byte(s))
	return hex.EncodeToString(h[:])
}

func (c *Ctx) issueRemember(userID int, startedAt string) {
	sel, val := randHex(12), randHex(32)
	started := startedAt
	if started == "" {
		started = conv.Date("c", conv.Now())
	}
	until := min(conv.StrToTime(started)+int64(SessionMaxHours())*3600, time.Now().Unix()+86400*int64(config.Int("security.remember_days", 30)))
	db.Insert("remember_tokens", db.Cols{{"user_id", userID}, {"selector", sel}, {"token_hash", sha256Hex(val)}, {"user_agent", c.UserAgent()},
		{"expires_at", conv.Date("c", conv.Unix(until))}, {"started_at", started}})
	http.SetCookie(c.W, &http.Cookie{Name: rememberCookie, Value: sel + ":" + val, Path: cookiePath(), Expires: time.Unix(until, 0), HttpOnly: true, SameSite: http.SameSiteLaxMode, Secure: c.IsHTTPS()})
	if sid := c.Sess.Int("sid"); sid > 0 {
		db.Exec("UPDATE user_sessions SET remember_selector = ? WHERE id = ?", sel, sid)
	}
}

func (c *Ctx) fromRemember(cookie string) (*CurrentUser, string) {
	parts := strings.SplitN(cookie, ":", 2)
	if len(parts) != 2 {
		return nil, ""
	}
	row := db.First("SELECT * FROM remember_tokens WHERE selector = ? AND expires_at > now()", parts[0])
	if row == nil || subtle.ConstantTimeCompare([]byte(row.Str("token_hash")), []byte(sha256Hex(parts[1]))) != 1 {
		return nil, ""
	}
	db.Exec("DELETE FROM remember_tokens WHERE id = ?", row["id"])
	started := row.Str("started_at")
	if started == "" {
		started = row.Str("created_at")
	}
	if conv.StrToTime(started)+int64(SessionMaxHours())*3600 <= time.Now().Unix() {
		return nil, ""
	}
	return LoadUser(row.Int("user_id")), started
}

func (c *Ctx) forgetRemember() {
	http.SetCookie(c.W, &http.Cookie{Name: rememberCookie, Value: "", Path: cookiePath(), MaxAge: -1, HttpOnly: true, SameSite: http.SameSiteLaxMode, Secure: c.IsHTTPS()})
}

// ---------------------------------------------------------------- passwords

// PasswordHash is password_hash($p, PASSWORD_DEFAULT): bcrypt, written as $2y$ like PHP.
func PasswordHash(p string) string {
	h, err := bcrypt.GenerateFromPassword([]byte(p), 10)
	if err != nil {
		panic(err)
	}
	s := string(h)
	if strings.HasPrefix(s, "$2a$") {
		s = "$2y$" + s[4:]
	}
	return s
}

// PasswordVerify is password_verify.
func PasswordVerify(p, hash string) bool {
	if hash == "" {
		return false
	}
	return bcrypt.CompareHashAndPassword([]byte(hash), []byte(p)) == nil
}

// PasswordNeedsRehash: not bcrypt with cost 10.
func PasswordNeedsRehash(hash string) bool {
	cost, err := bcrypt.Cost([]byte(hash))
	return err != nil || cost != 10
}

// ---------------------------------------------------------------- session guard

func SessionMaxHours() int      { return max(1, config.Int("security.session_max_hours", 12)) }
func SessionRotateMinutes() int { return max(1, config.Int("security.session_rotate_minutes", 10)) }

const sessionGrace = 60

func (c *Ctx) guardStart(userID int, method, startedAt string) int {
	started := startedAt
	if started == "" {
		started = conv.Date("c", conv.Now())
	}
	ua := c.UserAgent()
	id := db.Core.InsertID("user_sessions", db.Cols{{"user_id", userID}, {"session_hash", sha256Hex(c.Sess.ID)}, {"method", method}, {"ip", c.IP()},
		{"user_agent", conv.Substr(ua, 0, 255)}, {"device", DeviceLabel(c, ua)}, {"created_at", started},
		{"expires_at", conv.Date("c", conv.Unix(conv.StrToTime(started)+int64(SessionMaxHours())*3600))}})
	GuardEvent(c, userID, "login", method, "", "")
	c.Sess.Set("sid", id)
	return id
}

// GuardEvent writes login_events (never blocks a sign-in).
func GuardEvent(c *Ctx, userID int, event, method, note, email string) {
	_ = db.Try(func() {
		ua := c.UserAgent()
		var uid, m, n, e any
		if userID > 0 {
			uid = userID
		}
		if method != "" {
			m = method
		}
		if note != "" {
			n = conv.Substr(note, 0, 190)
		}
		if email != "" {
			e = conv.Substr(email, 0, 190)
		}
		db.Insert("login_events", db.Cols{{"user_id", uid}, {"email", e}, {"event", event}, {"method", m}, {"ip", c.IP()},
			{"user_agent", conv.Substr(ua, 0, 255)}, {"device", DeviceLabel(c, ua)}, {"note", n}}, "")
	})
}

// guardCheck: is this browser's session still allowed? Rotates the id when due. "" = fine, else revoked | expired.
func (c *Ctx) guardCheck(userID int) string {
	cur := sha256Hex(c.Sess.ID)
	row := db.First("SELECT * FROM user_sessions WHERE user_id = ? AND (session_hash = ? OR prev_hash = ?) ORDER BY id DESC LIMIT 1", userID, cur, cur)
	if row == nil {
		if db.Scalar("SELECT 1 FROM user_sessions WHERE session_hash = ? OR prev_hash = ?", cur, cur) != nil {
			return "revoked"
		}
		c.guardStart(userID, "session", "")
		return ""
	}
	c.Sess.Set("sid", row.Int("id"))
	if row.Str("revoked_at") != "" {
		if r := row.Str("revoke_reason"); r != "" {
			return r
		}
		return "revoked"
	}
	now := time.Now().Unix()
	if conv.StrToTime(row["expires_at"]) <= now {
		db.Exec("UPDATE user_sessions SET revoked_at = now(), revoke_reason = 'expired' WHERE id = ?", row["id"])
		GuardEvent(c, userID, "expired", row.Str("method"), conv.Str(SessionMaxHours())+" h limit", "")
		return "expired"
	}
	if row.Str("session_hash") != cur {
		if conv.StrToTime(row["prev_until"]) >= now {
			return ""
		}
		return "revoked"
	}
	if now-conv.StrToTime(row["rotated_at"]) >= int64(SessionRotateMinutes())*60 {
		c.Sess.Regenerate(true)
		db.Exec("UPDATE user_sessions SET prev_hash = ?, prev_until = ?, session_hash = ?, rotated_at = now(), last_seen_at = now(), ip = ? WHERE id = ?",
			cur, conv.Date("c", conv.Unix(now+sessionGrace)), sha256Hex(c.Sess.ID), c.IP(), row["id"])
	} else if now-conv.StrToTime(row["last_seen_at"]) >= 60 {
		db.Exec("UPDATE user_sessions SET last_seen_at = now() WHERE id = ?", row["id"])
	}
	return ""
}

func (c *Ctx) guardEnd(reason string) {
	if sid := c.Sess.Int("sid"); sid > 0 {
		db.Exec("UPDATE user_sessions SET revoked_at = now(), revoke_reason = ? WHERE id = ? AND revoked_at IS NULL", reason, sid)
	}
}

// RevokeSession ends one session; returns it, or nil.
func RevokeSession(c *Ctx, id, by int) db.Row {
	row := db.First("SELECT * FROM user_sessions WHERE id = ? AND revoked_at IS NULL", id)
	if row == nil {
		return nil
	}
	db.Exec("UPDATE user_sessions SET revoked_at = now(), revoked_by = ?, revoke_reason = 'revoked' WHERE id = ?", by, id)
	if sel := row.Str("remember_selector"); sel != "" {
		db.Exec("DELETE FROM remember_tokens WHERE selector = ?", sel)
	}
	note := "by an administrator"
	if by == row.Int("user_id") {
		note = "by the person"
	}
	GuardEvent(c, row.Int("user_id"), "revoked", row.Str("method"), note, "")
	return row
}

// RevokeAllSessions ends every session of a person except one; returns how many.
func RevokeAllSessions(c *Ctx, userID, exceptID, by int) int {
	sql := "SELECT id FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL AND expires_at > now()"
	args := []any{userID}
	if exceptID > 0 {
		sql += " AND id <> ?"
		args = append(args, exceptID)
	}
	n := 0
	for _, r := range db.Select(sql, args...) {
		if RevokeSession(c, r.Int("id"), by) != nil {
			n++
		}
	}
	return n
}

// IsCurrentSession: the row is this browser's session.
func (c *Ctx) IsCurrentSession(row db.Row) bool {
	return c.Sess != nil && c.Sess.Int("sid") > 0 && c.Sess.Int("sid") == row.Int("id")
}

// ---------------------------------------------------------------- permissions

// Actions are config core.actions.
func Actions() []string { return config.Strings("core.actions") }

// PermissionMatrix: role permissions + the person's own overrides (NULL = inherit).
func PermissionMatrix(userID, roleID int) map[string]map[string]bool {
	acts := Actions()
	cols := make([]string, len(acts))
	for i, a := range acts {
		cols[i] = "rp.can_" + a
	}
	m := map[string]map[string]bool{}
	for _, r := range db.Select("SELECT p.key, "+strings.Join(cols, ", ")+" FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?", roleID) {
		k := r.Str("key")
		if m[k] == nil {
			m[k] = map[string]bool{}
		}
		for _, a := range acts {
			m[k][a] = r.Bool("can_" + a)
		}
	}
	ucols := strings.ReplaceAll(strings.Join(cols, ", "), "rp.", "up.")
	for _, r := range db.Select("SELECT p.key, "+ucols+" FROM user_permissions up JOIN permissions p ON p.id = up.permission_id WHERE up.user_id = ?", userID) {
		k := r.Str("key")
		if m[k] == nil {
			m[k] = map[string]bool{}
		}
		for _, a := range acts {
			if r["can_"+a] != nil {
				m[k][a] = r.Bool("can_" + a)
			}
		}
	}
	return m
}

// ScopeCols names the columns a data scope looks at.
type ScopeCols struct {
	Owner      []string
	Department string
	Team       string
}

// ScopeSQL is Permission::scope(): an SQL fragment limiting rows to what the person may see (safe to AND in).
func ScopeSQL(u *CurrentUser, alias string, cols ScopeCols) (string, []any) {
	scope := u.Scope()
	if scope == "all" {
		return "TRUE", nil
	}
	var parts []string
	var params []any
	if scope == "department" && cols.Department != "" {
		parts = append(parts, alias+"."+cols.Department+" = ?")
		params = append(params, u.DeptID())
	}
	if scope == "team" && cols.Team != "" {
		parts = append(parts, alias+"."+cols.Team+" = ?")
		params = append(params, u.TeamIDOrNil())
	}
	for _, o := range cols.Owner {
		parts = append(parts, alias+"."+o+" = ?")
		params = append(params, u.ID)
	}
	if len(parts) == 0 {
		return "FALSE", nil
	}
	return "(" + strings.Join(parts, " OR ") + ")", params
}

// ---------------------------------------------------------------- device

var (
	reEdge    = regexp.MustCompile(`Edg(e|A|iOS)?/`)
	reOpera   = regexp.MustCompile(`OPR/|Opera`)
	reFirefox = regexp.MustCompile(`Firefox/|FxiOS`)
	reChrome  = regexp.MustCompile(`Chrome/|CriOS`)
	reSafari  = regexp.MustCompile(`Safari/`)
	reScript  = regexp.MustCompile(`(?i)curl|python|Postman`)
	reIOS     = regexp.MustCompile(`iPhone|iPad|iPod`)
	reTablet  = regexp.MustCompile(`iPad|Tablet`)
	reMobile  = regexp.MustCompile(`Mobile|iPhone|Android`)
)

// DeviceLabel is "Chrome · macOS · Desktop" from a user agent.
func DeviceLabel(c *Ctx, ua string) string {
	if ua == "" {
		return c.T("Unknown device")
	}
	browser := c.T("Browser")
	switch {
	case reEdge.MatchString(ua):
		browser = "Edge"
	case reOpera.MatchString(ua):
		browser = "Opera"
	case reFirefox.MatchString(ua):
		browser = "Firefox"
	case reChrome.MatchString(ua):
		browser = "Chrome"
	case reSafari.MatchString(ua):
		browser = "Safari"
	case reScript.MatchString(ua):
		browser = "Script"
	}
	os := ""
	switch {
	case reIOS.MatchString(ua):
		os = "iOS"
	case strings.Contains(ua, "Android"):
		os = "Android"
	case strings.Contains(ua, "Windows"):
		os = "Windows"
	case strings.Contains(ua, "Mac OS X") || strings.Contains(ua, "Macintosh"):
		os = "macOS"
	case strings.Contains(ua, "CrOS"):
		os = "ChromeOS"
	case strings.Contains(ua, "Linux"):
		os = "Linux"
	}
	typ := c.T("Desktop")
	if reTablet.MatchString(ua) {
		typ = c.T("Tablet")
	} else if reMobile.MatchString(ua) {
		typ = c.T("Mobile")
	}
	out := browser
	if os != "" {
		out += " · " + os
	}
	return out + " · " + typ
}
