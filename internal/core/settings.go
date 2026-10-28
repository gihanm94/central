package core

import (
	"encoding/json"
	"html/template"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strings"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/conv"
	"acme/internal/db"
	"acme/internal/i18n"
)

// Company settings: branding (logo, sign-in picture, language) and notifications (e-mail, Lark) (SettingsController).

func init() {
	signedIn(func(r app.Router) {
		r.Get("/settings", settingsEdit)
		r.Post("/settings", settingsUpdate)
		r.Post("/settings/test-mail", settingsTestMail)
		r.Get("/mail-log", settingsMailLog)                                       // Administrator → Audit → Mail log
		r.Get("/settings/mail-log", func(c *app.Ctx) { c.Redirect("/mail-log") }) // the mail log used to be a tab of Company settings
		r.Get("/settings/notifications", settingsNotifications)
		r.Post("/settings/notifications", settingsSaveNotifications)
		r.Post("/settings/test-lark", settingsTestLark)
		r.Get("/settings/powerbi", settingsPowerbi)
		r.Post("/settings/powerbi", settingsSavePowerbi)
	})
}

// wsSetting is Settings::get($key): nil when missing (or NULL).
func wsSetting(key string) any {
	if !app.SettingSet(key) {
		return nil
	}
	v := app.Setting(key, "")
	if v == "" {
		return nil
	}
	return v
}

func wsLocaleOpts() []app.Opt {
	var out []app.Opt
	for _, p := range i18n.Available() {
		out = append(out, app.Opt{Value: p.Key, Label: p.Label})
	}
	return out
}

func wsLocaleKeys() string {
	var k []string
	for _, p := range i18n.Available() {
		k = append(k, p.Key)
	}
	return strings.Join(k, ",")
}

// wsMail are the mail settings the pages show.
func wsMail(c *app.Ctx) app.M {
	return app.M{"driver": config.String("mail.driver", ""), "host": config.String("mail.host", ""), "port": config.Int("mail.port", 0), "from": config.String("mail.from_address", "")}
}

func settingsEdit(c *app.Ctx) {
	c.Authorize("settings", "view")
	c.View("settings/edit", app.M{"title": c.T("Company settings"), "brand": app.Branding(c), "locales": wsLocaleOpts(),
		"accessRequests": app.Setting("access_requests", "1") != "0", "mail": wsMail(c), "defaultLocale": app.Setting("default_locale", "en")})
}

func settingsUpdate(c *app.Ctx) {
	c.Authorize("settings", "edit")
	data := c.ValidateInput(
		app.Rule{Field: "company_name", Rules: "required|max:120", Label: c.T("Company name")},
		app.Rule{Field: "login_tagline", Rules: "nullable|max:160"},
		app.Rule{Field: "login_banner", Rules: "nullable|max:60", Label: c.T("Sign-in banner")},
		app.Rule{Field: "support_email", Rules: "nullable|email|max:160", Label: c.T("Support e-mail")},
		app.Rule{Field: "default_locale", Rules: "required|in:" + wsLocaleKeys(), Label: c.T("Default language")},
	)

	changed := &app.Changes{}
	for _, key := range data.Keys {
		value := data.Get(key)
		old := wsSetting(key)
		if conv.Str(old) != conv.Str(value) || (old == nil) != (value == nil) {
			changed.Set(key, app.Change{From: old, To: value})
			app.PutSetting(key, value)
		}
	}

	for _, sw := range []string{"access_requests", "auth_logo"} {
		on := "0"
		if c.Bool(sw) {
			on = "1"
		}
		cur := conv.Str(conv.Coalesce(wsSetting(sw), "1"))
		if !app.SettingSet(sw) {
			cur = "1"
		}
		if cur != on {
			changed.Set(sw, app.Change{From: cur, To: on})
			app.PutSetting(sw, on)
		}
	}

	for _, img := range []struct {
		key, folder string
		kb          int
		svg         bool
		remove      string
	}{{"company_logo", "branding", 2048, false, "remove_logo"}, {"login_image", "branding", 5120, false, "remove_login_image"}} {
		old := app.Setting(img.key, "")
		if file := c.File(img.key); file != nil {
			app.PutSetting(img.key, app.UploadImage(c, file, img.folder, img.kb, img.svg))
		} else if c.Bool(img.remove) && old != "" {
			app.PutSetting(img.key, nil)
		} else {
			continue
		}
		app.DeleteUpload(old)
		from, to := "none", "removed"
		if old != "" {
			from = "old image"
		}
		if app.Setting(img.key, "") != "" {
			to = "new image"
		}
		changed.Set(img.key, app.Change{From: from, To: to})
	}

	if changed.Len() > 0 {
		app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "settings", Label: "Company settings", Text: "Updated company settings", Props: app.M{"changes": changed}})
		c.Back("success", c.T("Settings saved."))
	}
	c.Back("success", c.T("Nothing changed."))
}

// ---------------------------------------------------------------- notifications

func wsPairs(key string) []config.Pair { return config.Pairs(key) }

func settingsNotifications(c *app.Ctx) {
	c.Authorize("settings", "view")
	matrix := app.NotifyMatrix()
	security := app.NotifySecurity()
	actions, channels := wsPairs("core.notify_actions"), wsPairs("core.notify_channels")
	type cell struct {
		Name, Aria string
		On         bool
	}
	type row struct {
		First        bool
		Module, Name string
		Icon         string
		Enabled      bool
		Channel      string
		Cells        []cell
	}
	var rows []row
	for _, m := range app.Modules() {
		for i, ch := range channels {
			r := row{First: i == 0, Module: m.Key, Name: c.T(m.Name), Icon: m.Icon, Enabled: m.Enabled, Channel: c.T(ch.Label)}
			for _, a := range actions {
				r.Cells = append(r.Cells, cell{Name: "notify[" + m.Key + "][" + a.Key + "][" + ch.Key + "]", Aria: m.Key + " " + a.Key + " " + ch.Key, On: matrix[m.Key][a.Key][ch.Key]})
			}
			rows = append(rows, r)
		}
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
			r.Cells = append(r.Cells, cell{Name: "security[" + ev.Key + "][" + ch.Key + "]", Aria: ev.Label + " " + ch.Key, On: security[ev.Key][ch.Key]})
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
	mask := func(k string) string {
		if app.Setting(k, "") != "" {
			return "••••••••"
		}
		return ""
	}
	domain := app.Setting("lark_domain", "")
	if domain == "" {
		domain = "https://open.larksuite.com"
	}
	c.View("settings/notifications", app.M{
		"title": c.T("Notifications"), "rows": rows, "rowspan": len(channels), "actions": actLabels, "secChannels": secLabels, "security": sec,
		"modes":   []app.Opt{{Value: "off", Label: c.T("Off")}, {Value: "webhook", Label: c.T("Group chat (bot webhook)")}, {Value: "app", Label: c.T("Direct message to each person (Lark app)")}},
		"domains": app.Opts("https://open.larksuite.com", "Lark (larksuite.com)", "https://open.feishu.cn", "Feishu (feishu.cn)"),
		"lark": app.M{"mode": app.LarkMode(), "domain": domain, "webhook": app.Setting("lark_webhook_url", ""), "secret": mask("lark_webhook_secret"),
			"app_id": app.Setting("lark_app_id", ""), "app_secret": mask("lark_app_secret")},
	})
}

var larkHook = regexp.MustCompile(`^https://open\.(larksuite\.com|feishu\.cn)/open-apis/bot/v2/hook/[\w-]+$`)

// wsChecked: !empty($in[a][b][c]) on a nested input array.
func wsChecked(v any, path ...string) bool {
	for _, k := range path {
		m, ok := v.(*config.Map)
		if !ok {
			return false
		}
		v, _ = m.Get(k)
	}
	return !conv.Empty(v)
}

func settingsSaveNotifications(c *app.Ctx) {
	c.Authorize("settings", "edit")
	mode := c.Input("lark_mode", "off")
	if !conv.InList(mode, "off", "webhook", "app") {
		app.Invalid("lark_mode", c.T("Choose how Lark messages are sent."))
	}
	domain := c.Input("lark_domain")
	if !conv.InList(domain, "https://open.larksuite.com", "https://open.feishu.cn") {
		domain = "https://open.larksuite.com"
	}
	webhook := c.Input("lark_webhook_url")
	if mode == "webhook" && !larkHook.MatchString(webhook) {
		app.Invalid("lark_webhook_url", c.T("Paste the bot webhook address from Lark (it starts with https://open.larksuite.com/open-apis/bot/v2/hook/)."))
	}
	if mode == "app" && c.Input("lark_app_id") == "" {
		app.Invalid("lark_app_id", c.T("Enter the Lark app ID."))
	}
	orNil := func(s string) any {
		if s == "" {
			return nil
		}
		return s
	}
	app.PutSetting("lark_mode", mode)
	app.PutSetting("lark_domain", domain)
	app.PutSetting("lark_webhook_url", orNil(webhook))
	app.PutSetting("lark_app_id", orNil(c.Input("lark_app_id")))
	for _, secret := range []string{"lark_webhook_secret", "lark_app_secret"} {
		v := c.Input(secret)
		if v != "" && v != "••••••••" {
			app.PutSetting(secret, v)
		} else if !conv.Empty(c.InputAny("clear_" + secret)) {
			app.PutSetting(secret, nil)
		}
	}

	in := c.InputAny("notify")
	matrix := config.NewMap()
	for _, m := range app.Modules() {
		am := config.NewMap()
		for _, a := range app.NotifyActions() {
			cm := config.NewMap()
			for _, ch := range app.NotifyChannels() {
				cm.Set(ch, wsChecked(in, m.Key, a, ch))
			}
			am.Set(a, cm)
		}
		matrix.Set(m.Key, am)
	}
	sin := c.InputAny("security")
	security := config.NewMap()
	for _, e := range config.Strings("core.security_events") {
		cm := config.NewMap()
		for _, ch := range app.NotifyChannels() {
			cm.Set(ch, wsChecked(sin, e, ch))
		}
		security.Set(e, cm)
	}
	mj, _ := json.Marshal(matrix)
	sj, _ := json.Marshal(security)
	app.PutSetting("notify_matrix", string(mj))
	app.PutSetting("notify_security", string(sj))
	_ = os.Remove(filepath.Join(app.BasePath, "storage", "cache", "lark_token.json"))

	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "settings", Label: "Notifications", Text: "Updated notification settings", NoNotify: true})
	c.Back("success", c.T("Notification settings saved."))
}

// ---------------------------------------------------------------- mail log, tests, Power BI

// The last e-mails the system tried to send, and whether each went out.
func settingsMailLog(c *app.Ctx) {
	c.Authorize("settings", "view")
	c.View("settings/mail-log", app.M{"title": c.T("Mail log"), "rows": db.Select("SELECT * FROM mail_log ORDER BY id DESC LIMIT 100"), "mail": wsMail(c)})
}

func settingsTestMail(c *app.Ctx) {
	c.Authorize("settings", "edit")
	u := c.MustUser()
	driver := config.String("mail.driver", "")
	ok := app.Notify(c, u.Email, u.Name, c.T("Test e-mail"), []template.HTML{template.HTML(app.E(c.T("If you can read this, e-mail delivery works."))),
		template.HTML(app.E(c.T("Driver")) + ": <strong>" + app.E(driver) + "</strong>")}, nil, "")
	if !ok {
		c.Back("error", c.T("Sending failed. See Administrator → Mail log for the reason."))
	}
	if driver == "log" {
		c.Back("success", c.T(`Test e-mail written to storage/mail (driver is "log").`))
	}
	c.Back("success", c.T("Test e-mail sent to :email.", "email", u.Email))
}

func settingsTestLark(c *app.Ctx) {
	c.Authorize("settings", "edit")
	ok, msg := app.LarkTest(c, c.MustUser().Email)
	if ok {
		c.Back("success", msg)
	}
	c.Back("error", msg)
}

func wsPowerbiGate(c *app.Ctx) {
	if !c.MustUser().IsAdmin() {
		app.Abort(403, c.T("Only an administrator can change the Power BI report."))
	}
}

// Power BI report of Management → Power BI → Overview — administrators only.
func settingsPowerbi(c *app.Ctx) {
	wsPowerbiGate(c)
	c.View("settings/powerbi", app.M{"title": c.T("Company settings"), "url": app.PowerBiURL()})
}

func settingsSavePowerbi(c *app.Ctx) {
	wsPowerbiGate(c)
	before := app.PowerBiURL()
	u := app.PowerBiLink(c, c.Input("embed"))
	if u != "" {
		app.PutSetting(app.PowerBiKey, u)
	} else {
		app.PutSetting(app.PowerBiKey, nil)
	}
	host := func(s string) string {
		if s == "" {
			return "—"
		}
		p, err := url.Parse(s)
		if err != nil {
			return ""
		}
		return p.Hostname()
	}
	text := "Removed the Power BI overview report"
	if u != "" {
		text = "Set the Power BI overview report"
	}
	ch := &app.Changes{}
	ch.Set("report", app.Change{From: host(before), To: host(u)})
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "powerbi", Label: "Power BI overview", Text: text, Props: app.M{"changes": ch}, NoNotify: true})
	if u != "" {
		c.Flash("success", c.T("Power BI report saved."))
	} else {
		c.Flash("success", c.T("Power BI report removed."))
	}
	c.Redirect("/settings/powerbi")
}
