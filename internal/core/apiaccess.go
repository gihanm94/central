package core

import (
	"net/http"
	"strings"

	"acme/internal/api"
	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/db"
)

// Mobile app & API on the website (ApiAccessController): administrators see every signed-in device and the
// request log; each person sees and ends their own.

func apiGate(c *app.Ctx) {
	if !c.MustUser().IsAdmin() {
		app.Abort(http.StatusForbidden, c.T("Only an administrator can open this."))
	}
}

func apiAdmin(c *app.Ctx) {
	apiGate(c)
	errorsOnly := c.Query("log") == "errors"
	devices := db.Select(`SELECT d.id, d.name, d.platform, d.created_at, d.last_used_at, d.last_ip, d.expires_at, u.name AS user_name, u.email
                FROM api_devices d JOIN users u ON u.id = d.user_id WHERE d.revoked_at IS NULL ORDER BY d.last_used_at DESC NULLS LAST LIMIT 500`)
	roles := db.Select(`SELECT r.name FROM roles r JOIN role_permissions rp ON rp.role_id = r.id JOIN permissions p ON p.id = rp.permission_id
                WHERE p.key = 'api_access' AND rp.can_view ORDER BY r.level DESC`)
	where := ""
	if errorsOnly {
		where = " WHERE l.status >= 400"
	}
	logRows := db.Select("SELECT l.*, u.name AS user_name FROM api_log l LEFT JOIN users u ON u.id = l.user_id" + where + " ORDER BY l.id DESC LIMIT 200")
	counts := db.First(`SELECT count(*) FILTER (WHERE at > now() - interval '24 hours') AS day, count(*) FILTER (WHERE at > now() - interval '24 hours' AND status >= 400) AS day_errors,
                count(*) FILTER (WHERE at > now() - interval '24 hours' AND status = 401) AS day_401, count(*) FILTER (WHERE at > now() - interval '24 hours' AND status = 429) AS day_429 FROM api_log`)
	var roleNames []string
	for _, r := range roles {
		roleNames = append(roleNames, r.Str("name"))
	}
	cards := [][]any{
		{c.T("Signed-in devices"), len(devices)}, {c.T("Requests, last 24 h"), counts.Int("day")},
		{c.T("Refused (401), last 24 h"), counts.Int("day_401")}, {c.T("Rate-limited (429), last 24 h"), counts.Int("day_429")},
	}
	for _, l := range logRows {
		cls := "text-emerald-700"
		if l.Int("status") >= 500 {
			cls = "text-signal-800"
		} else if l.Int("status") >= 400 {
			cls = "text-amber-700"
		}
		l["status_class"] = cls
	}
	toggle := c.URL("/api-access", "log", "errors")
	if errorsOnly {
		toggle = c.URL("/api-access")
	}
	c.View("admin/api", app.M{"title": c.T("Mobile app & API"), "devices": devices, "roles": strings.Join(roleNames, ", "), "log": logRows,
		"errors": errorsOnly, "toggle": toggle, "cards": cards,
		"settings": app.M{"enabled": config.Bool("api.enabled", true), "https": config.Bool("api.require_https", true) && config.String("app.env", "") != "local",
			"access": api.AccessMinutes(), "refresh": api.RefreshDays(), "max": api.MaxDays(), "rate": config.Int("api.rate_per_minute", 120)}})
}

func apiAdminRevoke(c *app.Ctx) {
	apiGate(c)
	api.Revoke(c.ParamInt("id"), "revoked_by_admin")
	c.Flash("success", c.T("The device was signed out."))
	c.Redirect("/api-access")
}

// apiMine: Profile → Mobile devices.
func apiMine(c *app.Ctx) {
	u := c.MustUser()
	sub := u.Row.Str("job_title")
	if u.Row["job_title"] == nil {
		sub = c.T(u.RoleName)
	}
	c.View("profile/devices", app.M{"title": c.T("Mobile devices"), "me": u, "subtitle": sub, "allowed": api.Allowed(u),
		"devices": db.Select("SELECT id, name, platform, created_at, last_used_at, last_ip, expires_at FROM api_devices WHERE user_id = ? AND revoked_at IS NULL ORDER BY last_used_at DESC NULLS LAST", u.ID)})
}

func apiRevokeMine(c *app.Ctx) {
	u := c.MustUser()
	id := c.ParamInt("id")
	if db.Scalar("SELECT 1 FROM api_devices WHERE id = ? AND user_id = ?", id, u.ID) != nil {
		api.Revoke(id, "revoked_by_user")
	}
	c.Flash("success", c.T("The device was signed out."))
	c.Redirect("/profile/devices")
}

func init() {
	signedIn(func(r app.Router) {
		r.Get("/api-access", apiAdmin)
		r.Post("/api-access/devices/{id}/revoke", apiAdminRevoke)
		r.Get("/profile/devices", apiMine)
		r.Post("/profile/devices/{id}/revoke", apiRevokeMine)
	})
}
