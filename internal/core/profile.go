package core

import (
	"encoding/json"
	"html/template"
	"math"
	"strings"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/conv"
	"acme/internal/db"
)

// Every employee manages their own details, security, preferences and notifications (ProfileController).

func init() {
	signedIn(func(r app.Router) {
		r.Get("/profile", profileEdit)
		r.Post("/profile", profileUpdate)
		r.Get("/profile/security", profileSecurity)
		r.Get("/profile/sessions", profileSessions)
		r.Post("/profile/sessions/revoke-others", profileRevokeOtherSessions)
		r.Post("/profile/sessions/{id}/revoke", profileRevokeSession)
		r.Post("/profile/password", profilePassword)
		r.Get("/profile/preferences", profilePreferences)
		r.Post("/profile/preferences", profileSavePreferences)
		r.Post("/profile/scale", profileScale)
		r.Get("/profile/notifications", profileNotifications)
		r.Post("/profile/notifications", profileSaveNotifications)
		r.Post("/profile/devices/{id}/forget", profileForgetDevice)
	})
}

// wsMe is what profile/_header shows.
func wsMe(c *app.Ctx, u *app.CurrentUser) app.M {
	who := c.T(u.RoleName)
	if u.Row["job_title"] != nil {
		who = u.JobTitle
	}
	return app.M{"u": u, "who": who}
}

func profileEdit(c *app.Ctx) {
	u := c.MustUser()
	p := db.First("SELECT * FROM employee_profiles WHERE user_id = ?", u.ID)
	if p == nil {
		p = db.Row{}
	}
	manager := conv.Str(db.Scalar("SELECT m.name FROM users u JOIN users m ON m.id = u.manager_id WHERE u.id = ?", u.ID))
	level := ""
	if p["employee_level"] != nil {
		level = app.EnumLabel(c, "levels", p.Str("employee_level"))
	}
	joined := ""
	if p["joined_at"] != nil {
		joined = app.FormatDate(c, p["joined_at"], "d M Y")
	}
	facts := [][2]string{
		{c.T("Employee code"), p.Str("employee_code")}, {c.T("Job title"), p.Str("job_title")}, {c.T("Employee level"), level},
		{c.T("Employment status"), app.EnumLabel(c, "statuses", p.Str("employment_status"))}, {c.T("Employment type"), app.EnumLabel(c, "types", p.Str("employment_type"))},
		{c.T("Role"), c.T(u.RoleName)}, {c.T("Department"), u.DepartmentName}, {c.T("Team"), u.TeamName}, {c.T("Reports to"), manager}, {c.T("Joined"), joined},
	}
	for i := range facts {
		if facts[i][1] == "" || facts[i][1] == "0" {
			facts[i][1] = "—"
		}
	}
	type field struct {
		Name, Label, Type, Value string
		Wide                     bool
	}
	first := []field{{"name", c.T("Full name"), "text", u.Name, true}, {"phone", c.T("Phone"), "tel", p.Str("phone"), false}, {"date_of_birth", c.T("Date of birth"), "date", p.Str("date_of_birth"), false}}
	second := []field{{"address", c.T("Address"), "text", p.Str("address"), true}, {"emergency_contact_name", c.T("Emergency contact"), "text", p.Str("emergency_contact_name"), false},
		{"emergency_contact_phone", c.T("Emergency contact phone"), "tel", p.Str("emergency_contact_phone"), false}}
	c.View("profile/edit", app.M{"title": c.T("Profile"), "me": wsMe(c, u), "profile": p, "first": first, "second": second, "facts": facts,
		"genders": app.EnumOptions(c, "genders"), "scope": u.Scope()})
}

func profileUpdate(c *app.Ctx) {
	u := c.MustUser()
	genders := strings.Join(config.AsStrings(config.Get("core.employee.genders")), ",")
	data := c.ValidateInput(
		app.Rule{Field: "name", Rules: "required|max:120", Label: c.T("Full name")},
		app.Rule{Field: "phone", Rules: "nullable|max:30"},
		app.Rule{Field: "address", Rules: "nullable|max:255"},
		app.Rule{Field: "date_of_birth", Rules: "nullable|date", Label: c.T("Date of birth")},
		app.Rule{Field: "gender", Rules: "nullable|in:" + genders, Label: c.T("Gender")},
		app.Rule{Field: "emergency_contact_name", Rules: "nullable|max:120"},
		app.Rule{Field: "emergency_contact_phone", Rules: "nullable|max:30"},
	)
	before := db.First(`SELECT u.name, p.phone, p.address, p.date_of_birth, p.gender, p.emergency_contact_name, p.emergency_contact_phone
                               FROM users u LEFT JOIN employee_profiles p ON p.user_id = u.id WHERE u.id = ?`, u.ID)

	var avatar any
	if u.Avatar != "" {
		avatar = u.Avatar
	}
	if file := c.File("avatar"); file != nil {
		avatar = app.UploadImage(c, file, "avatars", 2048, false)
		app.DeleteUpload(u.Avatar)
	} else if c.Bool("remove_avatar") {
		app.DeleteUpload(u.Avatar)
		avatar = nil
	}

	db.Exec("UPDATE users SET name = ?, avatar = ?, updated_at = now() WHERE id = ?", data.Get("name"), avatar, u.ID)
	var cols, sets []string
	args := []any{u.ID}
	for _, k := range data.Keys {
		if k == "name" {
			continue
		}
		cols = append(cols, k)
		sets = append(sets, k+" = EXCLUDED."+k)
		args = append(args, data.Get(k))
	}
	db.Exec("INSERT INTO employee_profiles (user_id, "+strings.Join(cols, ", ")+") VALUES (?"+strings.Repeat(", ?", len(cols))+`)
                  ON CONFLICT (user_id) DO UPDATE SET `+strings.Join(sets, ", ")+", updated_at = now()", args...)

	var b map[string]any
	if before != nil {
		b = before
	}
	changes := app.Diff(b, data.Map(), data.Keys)
	if conv.Str(avatar) != u.Avatar {
		from, to := "none", "removed"
		if u.Avatar != "" {
			from = "old photo"
		}
		if avatar != nil {
			to = "new photo"
		}
		changes.Set("photo", app.Change{From: from, To: to})
	}
	if changes.Len() > 0 {
		app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "user", ID: u.ID, Label: data.Str("name"), Text: "Updated own profile", Props: app.M{"changes": changes}, Owner: u.ID})
		c.Back("success", c.T("Profile saved."))
	}
	c.Back("success", c.T("Nothing changed."))
}

// ---------------------------------------------------------------- security

func profileSecurity(c *app.Ctx) {
	u := c.MustUser()
	c.View("profile/security", app.M{
		"title": c.T("Security"), "me": wsMe(c, u),
		"passkeys": db.Select("SELECT * FROM passkeys WHERE user_id = ? ORDER BY created_at DESC", u.ID),
		"devices":  db.Select("SELECT * FROM remember_tokens WHERE user_id = ? AND expires_at > now() ORDER BY created_at DESC", u.ID),
		"logins":   db.Select("SELECT created_at, ip_address, user_agent, description, properties FROM activity_logs WHERE user_id = ? AND action IN ('login','login_failed') ORDER BY created_at DESC LIMIT 6", u.ID),
	})
}

// My signed-in browsers + the device log.
func profileSessions(c *app.Ctx) {
	u := c.MustUser()
	page := max(1, conv.Int(c.Query("page", "1")))
	per := 15
	total := conv.Int(db.Scalar("SELECT count(*) FROM login_events WHERE user_id = ?", u.ID))
	sessions := db.Select("SELECT * FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL AND expires_at > now() ORDER BY last_seen_at DESC", u.ID)
	var list []app.M
	for _, s := range sessions {
		list = append(list, app.M{"row": s, "cur": c.IsCurrentSession(s)})
	}
	events := db.Select("SELECT * FROM login_events WHERE user_id = ? ORDER BY id DESC LIMIT "+conv.Str(per)+" OFFSET "+conv.Str((page-1)*per), u.ID)
	c.View("profile/sessions", app.M{
		"title": c.T("Sessions"), "me": wsMe(c, u), "sessions": list, "events": wsEvents(c, events), "page": page,
		"pages": max(1, int(math.Ceil(float64(total)/float64(per)))), "total": total, "maxHours": app.SessionMaxHours(),
	})
}

// End one of my sessions; ending the one I am using signs me out.
func profileRevokeSession(c *app.Ctx) {
	u := c.MustUser()
	id := c.ParamInt("id")
	row := db.First("SELECT * FROM user_sessions WHERE id = ? AND user_id = ?", id, u.ID)
	if row == nil {
		app.Abort(404)
	}
	mine := c.IsCurrentSession(row)
	app.RevokeSession(c, id, u.ID)
	if mine {
		c.Logout()
		c.Flash("success", c.T("You were signed out."))
		c.Redirect("/login")
	}
	c.Flash("success", c.T("Session ended. That device is signed out."))
	c.Redirect("/profile/sessions")
}

func profileRevokeOtherSessions(c *app.Ctx) {
	u := c.MustUser()
	n := app.RevokeAllSessions(c, u.ID, c.Sess.Int("sid"), u.ID)
	n += wsRevokeAPIDevices(u.ID, "revoked_by_user") // and every mobile device
	c.Flash("success", c.T(":n other session(s) ended.", "n", n))
	c.Redirect("/profile/sessions")
}

func profilePassword(c *app.Ctx) {
	u := c.MustUser()
	data := c.ValidateInput(
		app.Rule{Field: "current_password", Rules: "required", Label: c.T("Current password")},
		app.Rule{Field: "password", Rules: "required|password", Label: c.T("New password")},
		app.Rule{Field: "password_confirmation", Rules: "required|same:password", Label: c.T("Confirm new password")},
	)
	hash := conv.Str(db.Scalar("SELECT password FROM users WHERE id = ?", u.ID))
	if !app.PasswordVerify(data.Str("current_password"), hash) {
		app.Invalid("current_password", c.T("Your current password is not correct."))
	}
	db.Exec("UPDATE users SET password = ?, updated_at = now() WHERE id = ?", app.PasswordHash(data.Str("password")), u.ID)
	c.LogoutOtherDevices(u.ID)
	app.LogActivity(c, app.ActivityEntry{Action: "password_changed", Type: "user", ID: u.ID, Label: u.Name, Text: "Changed password from profile", NoNotify: true})

	ip := c.IP()
	app.SecurityAlert(c, "password", u.ID, func() (string, []template.HTML, string) {
		return c.T("Your password was changed"), []template.HTML{
				template.HTML(app.E(c.T("Your password was changed from your profile. Other devices were signed out."))),
				template.HTML(app.E(c.T("IP address") + ": " + ip + " | " + app.FormatDate(c, conv.NowString(), "d M Y, H:i"))),
			},
			c.T("If this was not you, contact your administrator immediately.")
	}, nil)
	c.Back("success", c.T("Password changed. Other devices were signed out."))
}

func profileForgetDevice(c *app.Ctx) {
	db.Exec("DELETE FROM remember_tokens WHERE id = ? AND user_id = ?", c.ParamInt("id"), c.MustUser().ID)
	c.Back("success", c.T("That device will need to sign in again."))
}

// ---------------------------------------------------------------- preferences

func profilePreferences(c *app.Ctx) {
	u := c.MustUser()
	var mods []app.Opt
	enabled := map[string]bool{}
	for _, m := range app.Modules() {
		if m.Enabled {
			mods = append(mods, app.Opt{Value: m.Key, Label: c.T(m.Name)})
			enabled[m.Key] = true
		}
	}
	cur := "core"
	if u.DefaultModule != "" && enabled[u.DefaultModule] {
		cur = u.DefaultModule
	}
	if v, ok := c.Sess.Old("default_module"); ok {
		cur = conv.Str(v)
	}
	locale := u.Locale
	if locale == "" {
		locale = c.Locale()
	}
	var boards []app.M
	for _, b := range DashboardsFor(c, u) {
		name := "_dash_" + b.Module
		if b.Module == cur {
			name = "default_dashboard"
		}
		boards = append(boards, app.M{"module": b.Module, "boards": b.Boards, "name": name, "show": b.Module == cur,
			"in": c.T("In :module", "module", c.T(app.ModuleByKey(b.Module).Name))})
	}
	c.View("profile/preferences", app.M{"title": c.T("Preferences"), "me": wsMe(c, u), "locales": wsLocaleOpts(), "locale": locale,
		"modules": mods, "curMod": cur, "dashboards": boards, "defaultDashboard": u.DefaultDashboard})
}

func profileSavePreferences(c *app.Ctx) {
	u := c.MustUser()
	var mods []string
	for _, m := range app.Modules() {
		if m.Enabled {
			mods = append(mods, m.Key)
		}
	}
	var keys []string
	for _, b := range DashboardsFor(c, u) {
		if b.Module == c.Input("default_module") {
			for _, o := range b.Boards {
				keys = append(keys, o.Value)
			}
		}
	}
	data := c.ValidateInput(
		app.Rule{Field: "locale", Rules: "required|in:" + wsLocaleKeys(), Label: c.T("Language")},
		app.Rule{Field: "default_module", Rules: "required|in:" + strings.Join(mods, ","), Label: c.T("Module to open after sign-in")},
		app.Rule{Field: "default_dashboard", Rules: "nullable|in:" + strings.Join(keys, ","), Label: c.T("Dashboard to open")},
	)
	db.Exec("UPDATE users SET locale = ?, default_module = ?, default_dashboard = ?, updated_at = now() WHERE id = ?",
		data.Get("locale"), data.Get("default_module"), data.Get("default_dashboard"), u.ID)
	c.SetCookie("lang", data.Str("locale"), 86400*365, true)
	c.SetLocale(data.Str("locale"))
	c.Back("success", c.T("Preferences saved."))
}

// Display size (zoom) — saved per person, applied as the page's base text size.
func profileScale(c *app.Ctx) {
	pct := conv.Int(c.Input("scale", "100"))
	pct = max(70, min(150, pct))
	db.Exec("UPDATE users SET ui_scale = ? WHERE id = ?", pct, c.MustUser().ID)
	c.JSON(app.M{"scale": pct})
}

// ---------------------------------------------------------------- notifications

func profileNotifications(c *app.Ctx) {
	u := c.MustUser()
	company := app.NotifyMatrix()
	security := app.NotifySecurity()
	prefs := app.NotifyPrefs(u.Row["notify_prefs"])
	larkOn := app.LarkMode() == "app"
	actions, channels := wsPairs("core.notify_actions"), wsPairs("core.notify_channels")
	pref := func(m, a, ch string) bool {
		v, ok := prefs[m][a][ch]
		return !ok || v == nil || conv.Truthy(v)
	}
	type cell struct {
		Name, Aria  string
		On, Allowed bool
	}
	type chRow struct {
		Label, Icon string
		Cells       []cell
	}
	type modBlock struct {
		Name, Icon string
		Rows       []chRow
	}
	var mods []modBlock
	for _, m := range app.Modules() {
		if !m.Enabled {
			continue
		}
		mb := modBlock{Name: c.T(m.Name), Icon: m.Icon}
		for _, ch := range channels {
			icon := "chat"
			if ch.Key == "email" {
				icon = "mail"
			}
			r := chRow{Label: c.T(ch.Label), Icon: icon}
			for _, a := range actions {
				allowed := company[m.Key][a.Key][ch.Key] && (ch.Key == "email" || larkOn)
				r.Cells = append(r.Cells, cell{Name: "notify[" + m.Key + "][" + a.Key + "][" + ch.Key + "]", Aria: m.Key + " " + a.Key + " " + ch.Key, On: pref(m.Key, a.Key, ch.Key), Allowed: allowed})
			}
			mb.Rows = append(mb.Rows, r)
		}
		mods = append(mods, mb)
	}
	var secCh []config.Pair
	for _, ch := range channels {
		if ch.Key != "app" { // the bell is not used for security alerts
			secCh = append(secCh, ch)
		}
	}
	type secRow struct {
		Label string
		Cells []cell
	}
	var sec []secRow
	for _, ev := range wsPairs("core.security_events") {
		r := secRow{Label: c.T(ev.Label)}
		for _, ch := range secCh {
			forced := ev.Key == "password" && ch.Key == "email"
			allowed := security[ev.Key][ch.Key] && (ch.Key == "email" || larkOn) && !forced
			on := forced || pref("_security", ev.Key, ch.Key)
			r.Cells = append(r.Cells, cell{Name: "notify[_security][" + ev.Key + "][" + ch.Key + "]", Aria: ev.Label + " " + ch.Key, On: on, Allowed: allowed})
		}
		sec = append(sec, r)
	}
	var actLabels, secLabels []string
	for _, a := range actions {
		actLabels = append(actLabels, c.T(a.Label))
	}
	for _, ch := range secCh {
		secLabels = append(secLabels, c.T(ch.Label))
	}
	c.View("profile/notifications", app.M{"title": c.T("My notifications"), "me": wsMe(c, u), "mods": mods, "actions": actLabels,
		"security": sec, "secChannels": secLabels, "larkOn": larkOn})
}

func profileSaveNotifications(c *app.Ctx) {
	u := c.MustUser()
	in := c.InputAny("notify")
	prefs := config.NewMap()
	for _, m := range app.Modules() {
		am := config.NewMap()
		for _, a := range app.NotifyActions() {
			cm := config.NewMap()
			for _, ch := range app.NotifyChannels() {
				cm.Set(ch, wsChecked(in, m.Key, a, ch))
			}
			am.Set(a, cm)
		}
		prefs.Set(m.Key, am)
	}
	sm := config.NewMap()
	for _, e := range config.Strings("core.security_events") {
		cm := config.NewMap()
		for _, ch := range app.NotifyChannels() {
			cm.Set(ch, wsChecked(in, "_security", e, ch))
		}
		sm.Set(e, cm)
	}
	prefs.Set("_security", sm)
	b, _ := json.Marshal(prefs)
	db.Exec("UPDATE users SET notify_prefs = ?, updated_at = now() WHERE id = ?", string(b), u.ID)
	c.Flash("success", c.T("Your notification choices were saved."))
	c.Redirect("/profile/notifications")
}
