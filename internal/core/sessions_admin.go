package core

import (
	"math"
	"os"
	"path/filepath"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

// Administrators: every active session of every person, the sign-in log, and ending sessions (SessionAdminController).
// Also the Modules page (ModuleController).

func init() {
	signedIn(func(r app.Router) {
		r.Get("/sessions", sessionsAdminIndex)
		r.Post("/sessions/user/{id}/revoke", sessionsAdminRevokeUser)
		r.Post("/sessions/{id}/revoke", sessionsAdminRevoke)
		r.Get("/modules", modulesIndex)
		r.Post("/modules/retry", modulesRetry)
		// Every URL of a module that is fenced off answers with ModuleUnavailable; others are plain 404s.
		for _, m := range app.Modules() {
			if m.Key != "core" {
				r.Any("/"+m.Key, ModuleUnavailable)
			}
		}
	})
}

func wsAdminGate(c *app.Ctx) {
	if !c.MustUser().IsAdmin() {
		app.Abort(403, c.T("Only an administrator can open this."))
	}
}

// wsEventLabels / wsEventTones: login_events.event → label and badge colours.
func wsEventLabels(c *app.Ctx) map[string]string {
	return map[string]string{"login": c.T("Signed in"), "logout": c.T("Signed out"), "failed": c.T("Failed sign-in"), "revoked": c.T("Session ended"), "expired": c.T("Session expired")}
}

var wsEventTones = map[string]string{"login": "bg-emerald-50 text-emerald-800", "logout": "bg-graphite-900/6 text-graphite-700", "failed": "bg-signal-50 text-signal-800", "revoked": "bg-amber-50 text-amber-800", "expired": "bg-graphite-900/6 text-graphite-700"}

func wsEvents(c *app.Ctx, rows []db.Row) []app.M {
	labels := wsEventLabels(c)
	out := make([]app.M, 0, len(rows))
	for _, r := range rows {
		ev := r.Str("event")
		label, ok := labels[ev]
		if !ok {
			label = ev
		}
		tone, ok := wsEventTones[ev]
		if !ok {
			tone = wsEventTones["logout"]
		}
		out = append(out, app.M{"row": r, "label": label, "tone": tone})
	}
	return out
}

func sessionsAdminIndex(c *app.Ctx) {
	wsAdminGate(c)
	tab := "active"
	if c.Query("tab") == "log" {
		tab = "log"
	}
	q := c.Query("q")
	page := max(1, php.Int(c.Query("page", "1")))
	per := 20
	like := "%" + strings.NewReplacer(`\`, `\\`, "%", `\%`, "_", `\_`).Replace(strings.ToLower(q)) + "%"
	var par []any
	if q != "" {
		par = []any{like, like, like, like}
	}
	var total int
	var rows []db.Row
	if tab == "active" {
		where := "s.revoked_at IS NULL AND s.expires_at > now()"
		if q != "" {
			where += " AND (lower(u.name) LIKE ? OR lower(u.email) LIKE ? OR s.ip LIKE ? OR lower(s.device) LIKE ?)"
		}
		total = php.Int(db.Scalar("SELECT count(*) FROM user_sessions s JOIN users u ON u.id = s.user_id WHERE "+where, par...))
		rows = db.Select("SELECT s.*, u.name, u.email FROM user_sessions s JOIN users u ON u.id = s.user_id WHERE "+where+" ORDER BY s.last_seen_at DESC LIMIT "+php.Str(per)+" OFFSET "+php.Str((page-1)*per), par...)
	} else {
		where := ""
		if q != "" {
			where = "WHERE (lower(coalesce(u.name, '')) LIKE ? OR lower(coalesce(e.email, u.email, '')) LIKE ? OR e.ip LIKE ? OR lower(coalesce(e.device, '')) LIKE ?)"
		}
		total = php.Int(db.Scalar("SELECT count(*) FROM login_events e LEFT JOIN users u ON u.id = e.user_id "+where, par...))
		rows = db.Select("SELECT e.*, u.name, coalesce(u.email, e.email) AS mail FROM login_events e LEFT JOIN users u ON u.id = e.user_id "+where+" ORDER BY e.id DESC LIMIT "+php.Str(per)+" OFFSET "+php.Str((page-1)*per), par...)
	}
	pages := max(1, int(math.Ceil(float64(total)/float64(per))))
	link := func(p int) string {
		q2 := map[string]any{"q": q, "page": p}
		if tab == "log" {
			q2["tab"] = "log"
		}
		return c.URL("/sessions", wsOrdered(q2, "tab", "q", "page")...)
	}
	var active []app.M
	for _, r := range rows {
		active = append(active, app.M{"row": r, "cur": c.IsCurrentSession(r)})
	}
	c.View("admin/sessions", app.M{
		"title": c.T("Sessions"), "tab": tab, "q": q, "rows": rows, "active": active, "events": wsEvents(c, rows), "page": page, "pages": pages, "total": total,
		"activeCount": php.Int(db.Scalar("SELECT count(*) FROM user_sessions WHERE revoked_at IS NULL AND expires_at > now()")),
		"intro":       c.T("Who is signed in, from which device. The browser id changes every :m minutes and every sign-in ends after :h hours.", "m", app.SessionRotateMinutes(), "h", app.SessionMaxHours()),
		"prevURL":     link(page - 1), "nextURL": link(page + 1),
	})
}

// wsOrdered turns a map into url() pairs in the given key order (http_build_query keeps the array order).
func wsOrdered(m map[string]any, keys ...string) []any {
	var out []any
	for _, k := range keys {
		if v, ok := m[k]; ok {
			out = append(out, k, v)
		}
	}
	return out
}

func sessionsAdminRevoke(c *app.Ctx) {
	wsAdminGate(c)
	id := c.ParamInt("id")
	row := db.First("SELECT * FROM user_sessions WHERE id = ?", id)
	if row == nil {
		app.Abort(404)
	}
	mine := c.IsCurrentSession(row)
	app.RevokeSession(c, id, c.MustUser().ID)
	if mine {
		c.Logout()
		c.Flash("success", c.T("You were signed out."))
		c.Redirect("/login")
	}
	c.Flash("success", c.T("Session ended."))
	c.Redirect("/sessions")
}

// End every session of one person.
func sessionsAdminRevokeUser(c *app.Ctx) {
	wsAdminGate(c)
	id := c.ParamInt("id")
	except := 0
	if c.Sess.Int("sid") > 0 && c.MustUser().ID == id {
		except = c.Sess.Int("sid")
	}
	n := app.RevokeAllSessions(c, id, except, c.MustUser().ID)
	n += wsRevokeAPIDevices(id, "revoked_by_admin")
	c.Flash("success", c.T(":n session(s) ended.", "n", n))
	c.Redirect("/sessions")
}

// wsRevokeAPIDevices is Api\Tokens::revokeAll: signs every mobile device of a person out.
func wsRevokeAPIDevices(userID int, reason string) int {
	return db.Exec(`UPDATE api_devices SET revoked_at = now(), revoked_reason = ?, access_hash = NULL, refresh_hash = NULL, prev_refresh_hash = NULL
                         WHERE user_id = ? AND revoked_at IS NULL`, php.Substr(reason, 0, 40), userID)
}

// ---------------------------------------------------------------- modules

// ModuleUnavailable answers every URL of a module that failed to load. The rest of the system keeps working.
func ModuleUnavailable(c *app.Ctx) {
	key := app.ModuleForPath(c.Path())
	if key == "" || !app.ModuleEnabled(key) || len(app.ModuleProblems(key)) == 0 {
		app.Abort(404)
	}
	msg := c.T(":module is temporarily unavailable. Everything else keeps working.", "module", c.T(app.ModuleByKey(key).Name))
	if c.MustUser().IsAdmin() {
		msg += " " + c.T("Details are on the Modules page (Administration → Modules).")
	}
	app.Abort(503, msg)
}

func modulesIndex(c *app.Ctx) {
	wsAdminGate(c)
	status := app.ModuleStatus()
	where := map[string]string{"manifest": c.T("module.php"), "routes": c.T("routes.php"), "api": c.T("api.php (mobile API)"), "migrations": c.T("Database migrations")}
	var list []app.M
	for _, m := range app.Modules() {
		if m.Key == "core" {
			continue
		}
		issues := status[m.Key]
		var probs []app.M
		var keys []string
		for k := range issues {
			keys = append(keys, k)
		}
		// keep a stable order (PHP: the order they were recorded)
		wsSortStrings(keys)
		for _, w := range keys {
			i := issues[w]
			label, ok := where[w]
			if !ok {
				if strings.HasPrefix(w, "hook:") {
					label = c.T("Background task “:h”", "h", w[5:])
				} else {
					label = w
				}
			}
			probs = append(probs, app.M{"label": label, "at": i.At, "message": i.Message, "where": i.File + ":" + php.Str(i.Line)})
		}
		dir := ""
		if m.Routes != nil || len(m.Menu) > 0 || m.Hooks != nil {
			dir = "internal/modules/" + m.Key
			if _, err := os.Stat(filepath.Join(app.BasePath, dir)); err != nil {
				dir = ""
			}
		}
		state := "working"
		switch {
		case !m.Enabled:
			state = "off"
		case len(app.ModuleProblems(m.Key)) > 0:
			state = "blocked"
		case len(issues) > 0:
			state = "warnings"
		}
		list = append(list, app.M{"key": m.Key, "name": c.T(m.Name), "icon": m.Icon, "home": m.Home, "dir": dir, "state": state, "problems": probs})
	}
	c.View("admin/modules", app.M{"title": c.T("Modules"), "modules": list, "hasStatus": len(status) > 0})
}

// Forget a module's recorded failures; its next page view or hook call tries again.
func modulesRetry(c *app.Ctx) {
	wsAdminGate(c)
	app.ModuleClear(c.Input("module"))
	_ = os.Remove(filepath.Join(app.BasePath, "storage", "cache", "schema.version")) // migrations are re-checked on the next request too
	c.Flash("success", c.T("Status cleared. The module is tried again on its next use."))
	c.Redirect("/modules")
}

func wsSortStrings(s []string) {
	for i := 1; i < len(s); i++ {
		for j := i; j > 0 && s[j] < s[j-1]; j-- {
			s[j], s[j-1] = s[j-1], s[j]
		}
	}
}
