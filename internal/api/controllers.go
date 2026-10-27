package api

import (
	"html/template"
	"net/http"
	"regexp"
	"strings"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/php"
)

// ---------------------------------------------------------------- AuthController: sign in, refresh, sign out (mobile app)

var deviceIDRe = regexp.MustCompile(`^[A-Za-z0-9._-]{8,120}$`)

// login: POST {username (or email), password, device_name, device_id?, platform?} → token pair + who you are.
func login(c *app.Ctx) {
	in := Input(c, "username", "email", "password", "device_name", "device_id", "platform")
	str := func(k string) string { v, _ := in.Get(k); return php.Str(v) }
	email := str("username")
	if email == "" {
		email = str("email")
	}
	email = strings.ToLower(strings.TrimSpace(email)) // sign-in name: username, or e-mail
	password := str("password")
	name := strings.TrimSpace(str("device_name"))
	var deviceKey any
	if k := strings.TrimSpace(str("device_id")); k != "" {
		deviceKey = k
	}
	platform := "other"
	if p, ok := in.Get("platform"); ok {
		if s, isStr := p.(string); isStr && php.InList(s, "ios", "android", "web", "other") {
			platform = s
		}
	}
	errs := app.NewErrors()
	if email == "" || php.Len(email) > 190 {
		errs.Add("username", c.T("Enter your username."))
	}
	if password == "" || len(password) > 200 {
		errs.Add("password", c.T("Enter your password."))
	}
	if name == "" || php.Len(name) > 120 {
		errs.Add("device_name", c.T("Name this device (up to 120 characters)."))
	}
	if deviceKey != nil && !deviceIDRe.MatchString(deviceKey.(string)) {
		errs.Add("device_id", c.T("Invalid device id."))
	}
	if errs.Len() > 0 {
		app.InvalidErrors(errs)
	}
	Hit(c, "login:"+email, 10) // one address from many IPs

	userID, msg, locked := c.VerifyLogin(email, password, "api")
	if locked > 0 {
		Fail(http.StatusTooManyRequests, "locked", msg, "Retry-After", php.Str(locked))
	}
	if msg != "" {
		Fail(http.StatusUnauthorized, "invalid_credentials", msg)
	}
	u := app.LoadUser(userID)
	if u == nil {
		Fail(http.StatusUnauthorized, "invalid_credentials", c.T("These details do not match an active account."))
	}
	if !Allowed(u) {
		app.GuardEvent(c, u.ID, "failed", "api", "No mobile app & API permission", email)
		Fail(http.StatusForbidden, "no_api_access", c.T("Your account may not use the mobile app. Ask an administrator."))
	}

	tokens := Issue(c, u, name, deviceKey, platform)
	c.ActAs(u)
	db.Exec("UPDATE users SET last_login_at = now(), last_login_ip = ? WHERE id = ?", c.IP(), u.ID)
	app.GuardEvent(c, u.ID, "login", "api", php.Substr(name, 0, 120), "")
	devID, _ := tokens.Get("device_id")
	app.LogActivity(c, app.ActivityEntry{Action: "login", Type: "user", ID: u.ID, Label: u.Name, Text: "Signed in on the mobile app (:device)",
		Params: []any{"device", name}, Props: app.M{"method": "api", "device_id": devID}, Actor: u.ID, NoNotify: true})
	if config.Bool("notifications.login_alert", true) {
		ip := c.IP()
		app.SecurityAlert(c, "login", u.ID, func() (string, []template.HTML, string) {
			return c.T("New sign-in to your account"), []template.HTML{
					template.HTML(app.E(c.T("Your account was signed in on the mobile app."))),
					template.HTML(app.E(c.T("Device")) + ": " + app.E(name)),
					template.HTML(app.E(c.T("Time") + ": " + app.FormatDate(c, php.NowString(), "d M Y, H:i"))),
					template.HTML(app.E(c.T("IP address")) + ": " + app.E(ip)),
				},
				c.T("Not you? Reset your password now and tell your administrator.")
		}, &app.MailButton{Label: c.T("Review security settings"), URL: c.URL("/profile/security")})
	}
	tokens.Set("user", Profile(c, u))
	c.JSON(tokens, http.StatusOK)
}

// refresh: POST {refresh_token} → new token pair (the old refresh token stops working).
func refresh(c *app.Ctx) {
	v, _ := Input(c, "refresh_token").Get("refresh_token")
	token := php.Str(v)
	if token == "" || len(token) > 100 {
		Fail(http.StatusUnauthorized, "invalid_token", c.T("Sign in again."))
	}
	pair, _ := Refresh(c, token)
	c.JSON(pair)
}

// logout: POST → this device is signed out (both tokens stop working).
func logout(c *app.Ctx) {
	d := Device(c)
	Revoke(d.Int("id"), "logout")
	app.GuardEvent(c, User(c).ID, "logout", "api", php.Substr(d.Str("name"), 0, 120), "")
	c.JSON(Obj("ok", true))
}

// ---------------------------------------------------------------- MeController: profile, devices, notifications

// resources: permissions the mobile app cares about, by module.
var resources = []struct {
	module string
	list   []string
}{
	{"crm", []string{"crm_leads", "crm_contacts", "crm_opportunities", "crm_activities", "crm_projects", "crm_tasks"}},
	{"machines", []string{"machines_checklists", "machines_machines", "machines_maintenance", "machines_calibration"}},
	{"accounting", []string{"accounting_customers", "accounting_products", "accounting_orders", "accounting_invoices", "accounting_sellers", "accounting_inet", "accounting_billing"}},
}

// Profile is who the person is and what the app may show (MeController::profile).
func Profile(c *app.Ctx, u *app.CurrentUser) *config.Map {
	perms := config.NewMap()
	for _, g := range resources {
		if !app.ModuleUsable(g.module) {
			continue
		}
		for _, r := range g.list {
			actions := []string{}
			for _, a := range []string{"view", "create", "edit", "delete", "download"} {
				if u.Can(r, a) {
					actions = append(actions, a)
				}
			}
			if len(actions) > 0 {
				m, _ := perms.Vals[g.module].(*config.Map)
				if m == nil {
					m = config.NewMap()
					perms.Set(g.module, m)
				}
				m.Set(r, actions)
			}
		}
	}
	modules := append([]string{}, perms.Keys...)
	var avatar any
	if u.Avatar != "" {
		avatar = c.UploadURL(u.Avatar)
	}
	return Obj("id", u.ID, "name", u.Name, "email", u.Email, "role", u.RoleName, "department", u.Row["department_name"], "team", u.Row["team_name"],
		"job_title", u.Row["job_title"], "locale", u.Row["locale"], "avatar_url", avatar, "is_admin", u.IsAdmin(), "modules", modules, "permissions", perms)
}

func me(c *app.Ctx) { c.JSON(Obj("data", Profile(c, User(c)))) }

// devices: my signed-in devices (this one is marked).
func devices(c *app.Ctx) {
	current := Device(c).Int("id")
	rows := Rows(db.Core.SelectOrdered("SELECT id, name, platform, created_at, last_used_at, last_ip, expires_at FROM api_devices WHERE user_id = ? AND revoked_at IS NULL ORDER BY last_used_at DESC NULLS LAST", User(c).ID))
	for _, r := range rows {
		id, _ := r.Get("id")
		r.Set("current", php.Int(id) == current)
	}
	c.JSON(Obj("data", rows))
}

// revoke: sign out one of my devices (e.g. a lost phone).
func revoke(c *app.Ctx) {
	id := c.ParamInt("id")
	if db.Scalar("SELECT 1 FROM api_devices WHERE id = ? AND user_id = ? AND revoked_at IS NULL", id, User(c).ID) == nil {
		Fail(http.StatusNotFound, "not_found", c.T("Not found."))
	}
	Revoke(id, "revoked_by_user")
	c.JSON(Obj("ok", true))
}

// notifications: my latest notifications (?unread=1 for unread only).
func notifications(c *app.Ctx) {
	sql := "SELECT id, module, type, title, body, url, read_at, created_at FROM notifications WHERE user_id = ?"
	if c.Query("unread") == "1" {
		sql += " AND read_at IS NULL"
	}
	c.JSON(Paged(c, sql, []any{User(c).ID}, "created_at DESC, id DESC", "core", nil))
}

// ---------------------------------------------------------------- SystemController

// unavailable: API URLs of a module that is fenced off.
func unavailable(c *app.Ctx) {
	Fail(http.StatusServiceUnavailable, "module_unavailable", c.T("This part of the system is temporarily unavailable. Everything else keeps working."))
}

// ---------------------------------------------------------------- RateLimit

// Hit counts a request in fixed one-minute windows (api_rate); over the limit → 429 with Retry-After.
func Hit(c *app.Ctx, key string, perMinute int) {
	now := php.Now()
	window := php.Date("Y-m-d H:i:00", now)
	hits := php.Int(db.Scalar(`INSERT INTO api_rate (key, window_start, hits) VALUES (?, ?, 1)
            ON CONFLICT (key, window_start) DO UPDATE SET hits = api_rate.hits + 1 RETURNING hits`, php.Substr(key, 0, 120), window))
	if hits > perMinute {
		retry := 60 - now.Second()
		Fail(http.StatusTooManyRequests, "rate_limited", c.T("Too many requests. Try again in :s seconds.", "s", retry), "Retry-After", php.Str(retry))
	}
	if randInt(500) == 0 { // now and then, forget old windows
		db.Exec("DELETE FROM api_rate WHERE window_start < now() - interval '10 minutes'")
	}
}
