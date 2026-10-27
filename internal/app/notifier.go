package app

import (
	"encoding/json"
	"html/template"
	"strings"

	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/php"
)

// Notifier decides who hears about what, and how (App\Core\Support\Notifier).
// Company switches (Company settings → Notifications): per module × action × channel.
// Personal switches (Profile → Notifications): each person can turn off what they receive.
// Every message is written in the recipient's own language.

// NotifyActions / NotifyChannels are config core.notify_actions / notify_channels keys.
func NotifyActions() []string  { return config.Strings("core.notify_actions") }
func NotifyChannels() []string { return config.Strings("core.notify_channels") }

// NotifyMatrix is the company matrix with defaults filled in: [module][action][channel].
func NotifyMatrix() map[string]map[string]map[string]bool {
	var saved map[string]map[string]map[string]any
	_ = json.Unmarshal([]byte(Setting("notify_matrix", "")), &saved)
	out := map[string]map[string]map[string]bool{}
	for _, m := range Modules() {
		out[m.Key] = map[string]map[string]bool{}
		for _, a := range NotifyActions() {
			out[m.Key][a] = map[string]bool{}
			for _, ch := range NotifyChannels() {
				v, ok := saved[m.Key][a][ch]
				out[m.Key][a][ch] = !ok || php.Truthy(v)
			}
		}
	}
	return out
}

// NotifySecurity is the security events × channels (e-mail on by default).
func NotifySecurity() map[string]map[string]bool {
	var saved map[string]map[string]any
	_ = json.Unmarshal([]byte(Setting("notify_security", "")), &saved)
	out := map[string]map[string]bool{}
	for _, e := range config.Strings("core.security_events") {
		out[e] = map[string]bool{}
		for _, ch := range NotifyChannels() {
			v, ok := saved[e][ch]
			if ok {
				out[e][ch] = php.Truthy(v)
			} else {
				out[e][ch] = ch == "email"
			}
		}
	}
	return out
}

// NotifyPrefs are a person's opt-outs (missing = on): [module][action][channel].
func NotifyPrefs(raw any) map[string]map[string]map[string]any {
	out := map[string]map[string]map[string]any{}
	switch x := raw.(type) {
	case map[string]any:
		b, _ := json.Marshal(x)
		_ = json.Unmarshal(b, &out)
	default:
		_ = json.Unmarshal([]byte(php.Str(raw)), &out)
	}
	return out
}

func prefOn(p map[string]any, ch string) bool {
	v, ok := p[ch]
	return !ok || php.Truthy(v)
}

func rule(m map[string]map[string]map[string]bool, module, action string) map[string]bool {
	if r, ok := m[module][action]; ok {
		return r
	}
	return map[string]bool{"app": false, "email": false, "lark": false}
}

// notifyEvent is a record event (create / edit / delete / import / export / download) to the actor and the owner.
func notifyEvent(c *Ctx, module, action string, text M, changes any, actorID, ownerID int, url string) {
	if !php.InList(action, NotifyActions()...) {
		return
	}
	r := rule(NotifyMatrix(), module, action)
	if !r["app"] && !r["email"] && !r["lark"] {
		return
	}
	actorName := ""
	if actorID > 0 {
		actorName = php.Str(db.Scalar("SELECT name FROM users WHERE id = ?", actorID))
	}
	var chg *Changes
	switch x := changes.(type) {
	case *Changes:
		chg = x
	}
	build := func(isActor bool) (string, []template.HTML) {
		desc := RenderActivityText(c.Locale(), text)
		who := actorName
		if actorID == 0 {
			who = c.T("System")
		}
		if isActor {
			who = c.T("You")
		}
		intro := c.T("Someone acted on a record that belongs to you:")
		if isActor {
			intro = c.T("This confirms an action you just took:")
		}
		lines := []template.HTML{
			template.HTML(E(intro)),
			template.HTML("<strong>" + E(who) + "</strong>: " + E(desc)),
			template.HTML(E(c.T("Time")+": "+FormatDate(c, php.NowString(), "d M Y, H:i")+"  |  IP: ") + E(c.IP())),
		}
		if chg != nil {
			for i, f := range chg.Keys {
				if i >= 8 {
					break
				}
				ch := chg.Vals[f]
				from, to := php.Str(php.Coalesce(ch.From, "—")), php.Str(php.Coalesce(ch.To, "—"))
				lines = append(lines, template.HTML("&bull; "+E(c.T(php.Ucfirst(strings.ReplaceAll(f, "_", " "))))+": "+E(php.Limit(from, 40))+" &rarr; "+E(php.Limit(to, 40))))
			}
		}
		label := config.String("core.notify_actions."+action, action)
		return c.T(php.Ucfirst(label)) + ": " + php.Limit(desc, 70), lines
	}
	for _, u := range notifyRecipients(actorID, ownerID) {
		isActor := u.Int("id") == actorID
		prefs := NotifyPrefs(u["notify_prefs"])[module][action]
		if err := Catch(func() {
			c.With(u.Str("locale"), func() {
				subject, lines := build(isActor)
				if r["email"] && prefOn(prefs, "email") {
					Notify(c, u.Str("email"), u.Str("name"), subject, lines, &MailButton{Label: c.T("Open dashboard"), URL: c.URL("/dashboard")}, c.T("If you did not expect this, contact your administrator."))
				}
				if r["lark"] && LarkMode() == "app" && prefOn(prefs, "lark") {
					LarkDirect(c, u.Str("email"), subject, htmlStrings(lines[1:]), c.URL("/dashboard"))
				}
				// the bell: only for people other than the one who did it
				if r["app"] && prefOn(prefs, "app") && !isActor {
					body := ""
					if len(lines) > 1 {
						body = stripTags(string(lines[1]))
					}
					Bell(c, u.Int("id"), module, action, subject, body, url, actorID)
				}
			})
		}); err != nil {
			WriteErrorLog("[notify] " + module + "/" + action + " to user " + u.Str("id") + ": " + err.Error())
		}
	}
	if r["lark"] && LarkMode() == "webhook" {
		c.With("", func() {
			subject, lines := build(false)
			LarkWebhook(c, subject, htmlStrings(lines[1:]), c.URL("/activity"))
		})
	}
}

func htmlStrings(h []template.HTML) []string {
	out := make([]string, len(h))
	for i, x := range h {
		out[i] = string(x)
	}
	return out
}

func stripTags(s string) string { return tagRe.ReplaceAllString(s, "") }

// Deliver is one message to one person (a comment on their record, a reminder, something shared with them),
// through the channels the company and that person left on. title and body are activity texts
// (M{"key": …, "params": *config.Map or map}) written in the recipient's language.
func Deliver(c *Ctx, module, action string, userID int, title, body M, url string, actorID int) {
	r := rule(NotifyMatrix(), module, action)
	if !r["app"] && !r["email"] && !r["lark"] {
		return
	}
	u := db.First("SELECT id, name, email, locale, notify_prefs FROM users WHERE id = ? AND is_active AND deleted_at IS NULL", userID)
	if u == nil {
		return
	}
	prefs := NotifyPrefs(u["notify_prefs"])[module][action]
	c.With(u.Str("locale"), func() {
		subject := RenderActivityText(c.Locale(), title)
		line := RenderActivityText(c.Locale(), body)
		if r["app"] && prefOn(prefs, "app") {
			Bell(c, u.Int("id"), module, action, subject, line, url, actorID)
		}
		to := url
		if to == "" {
			to = c.URL("/dashboard")
		}
		if r["email"] && prefOn(prefs, "email") {
			Notify(c, u.Str("email"), u.Str("name"), subject, []template.HTML{template.HTML(E(line))}, &MailButton{Label: c.T("Open"), URL: to}, c.T("You can switch these e-mails off under Profile → Notifications."))
		}
		if r["lark"] && LarkMode() == "app" && prefOn(prefs, "lark") {
			LarkDirect(c, u.Str("email"), subject, []string{line}, to)
		}
	})
}

// Text builds an activity text for Deliver: app.Text("Reminder: :title", "title", t).
func Text(key string, params ...any) M {
	pm := config.NewMap()
	for i := 0; i+1 < len(params); i += 2 {
		pm.Set(php.Str(params[i]), params[i+1])
	}
	return M{"key": key, "params": pm}
}

// Bell puts a message in somebody's bell.
func Bell(c *Ctx, userID int, module, typ, title, body, url string, actorID int) {
	var b, u, a any
	if body != "" {
		b = php.Substr(body, 0, 500)
	}
	if url != "" {
		base := c.BaseURL()
		if strings.HasPrefix(url, base) {
			url = url[len(base):]
		}
		u = php.Substr(url, 0, 255)
	}
	if actorID > 0 {
		a = actorID
	}
	db.Insert("notifications", db.Cols{{Name: "user_id", Value: userID}, {Name: "module", Value: module}, {Name: "type", Value: typ},
		{Name: "title", Value: php.Substr(title, 0, 200)}, {Name: "body", Value: b}, {Name: "url", Value: u}, {Name: "actor_id", Value: a}}, "")
}

// SecurityAlert: sign-in and password alerts, always about the person themselves; build runs in their language.
func SecurityAlert(c *Ctx, event string, userID int, build func() (subject string, lines []template.HTML, note string), button *MailButton) {
	r, ok := NotifySecurity()[event]
	if !ok {
		r = map[string]bool{"email": true, "lark": false}
	}
	u := db.First("SELECT id, name, email, locale, notify_prefs FROM users WHERE id = ?", userID)
	if u == nil {
		return
	}
	prefs := NotifyPrefs(u["notify_prefs"])["_security"][event]
	c.With(u.Str("locale"), func() {
		subject, lines, note := build()
		// a password change e-mail is never silenced: it protects the account
		if r["email"] && (prefOn(prefs, "email") || event == "password") {
			Notify(c, u.Str("email"), u.Str("name"), subject, lines, button, note)
		}
		if r["lark"] && prefOn(prefs, "lark") {
			url := ""
			if button != nil {
				url = button.URL
			}
			switch LarkMode() {
			case "app":
				LarkDirect(c, u.Str("email"), subject, htmlStrings(lines), url)
			case "webhook":
				if event == "password" {
					LarkWebhook(c, subject+" — "+u.Str("name"), htmlStrings(lines), "")
				}
			}
		}
	})
}

func notifyRecipients(actorID, ownerID int) []db.Row {
	var ids []string
	seen := map[int]bool{}
	for _, id := range []int{actorID, ownerID} {
		if id > 0 && !seen[id] {
			seen[id] = true
			ids = append(ids, php.Str(id))
		}
	}
	if len(ids) == 0 {
		return nil
	}
	return db.Select("SELECT id, name, email, locale, notify_prefs FROM users WHERE is_active AND deleted_at IS NULL AND id IN (" + strings.Join(ids, ",") + ")")
}

func init() {
	NotifyEvent = notifyEvent
	LoginAlert = func(c *Ctx, u *CurrentUser, method string) {
		ip, ua := c.IP(), c.UserAgent()
		SecurityAlert(c, "login", u.ID, func() (string, []template.HTML, string) {
			return c.T("New sign-in to your account"), []template.HTML{
					template.HTML(E(c.T("Your account was signed in with :method.", "method", c.T(method)))),
					template.HTML(E(c.T("Time") + ": " + FormatDate(c, php.NowString(), "d M Y, H:i"))),
					template.HTML(E(c.T("IP address")) + ": " + E(ip)),
					template.HTML(E(c.T("Device")) + ": " + E(php.Limit(ua, 120))),
				},
				c.T("Not you? Reset your password now and tell your administrator.")
		}, &MailButton{Label: c.T("Review security settings"), URL: c.URL("/profile/security")})
	}
}
