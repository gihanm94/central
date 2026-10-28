package api

import (
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"html/template"
	"net/http"
	"regexp"
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/conv"
	"acme/internal/db"
)

// API sign-ins, one row per device (api_devices) — App\Core\Api\Tokens.
//   - access token: random 256-bit, lives api.access_minutes (default 15), sent as "Authorization: Bearer …"
//   - refresh token: random 256-bit, rotates on every use and idles out after api.refresh_days (default 30)
//   - a refresh token that was already used comes back → it was copied: the device is signed out and the person warned
//   - every sign-in ends after api.max_days (default 90), when the person is deactivated, loses "Mobile app & API",
//     changes password / signs out everywhere (users.session_version), or the device is revoked
//
// Only SHA-256 hashes of tokens are stored.

const (
	accessPrefix  = "acme_at_"
	refreshPrefix = "acme_rt_"
)

func AccessMinutes() int { return max(1, min(60, config.Int("api.access_minutes", 15))) }
func RefreshDays() int   { return max(1, config.Int("api.refresh_days", 30)) }
func MaxDays() int       { return max(1, config.Int("api.max_days", 90)) }

func hash(token string) string { h := sha256.Sum256([]byte(token)); return hex.EncodeToString(h[:]) }

func random(prefix string) string {
	b := make([]byte, 32)
	_, _ = rand.Read(b)
	return prefix + base64.RawURLEncoding.EncodeToString(b)
}

// Allowed: may this person use the API at all?
func Allowed(u *app.CurrentUser) bool { return u.Can("api_access", "view") }

func iso(t time.Time) string { return conv.Date("c", t.In(conv.Loc)) }

// Issue: after a correct sign-in name + password, a new device sign-in; returns the token pair for the app.
func Issue(c *app.Ctx, u *app.CurrentUser, name string, deviceKey any, platform string) *config.Map {
	ua := c.UserAgent()
	if deviceKey != nil { // signing in again on the same install replaces its old sign-in
		db.Exec(`UPDATE api_devices SET revoked_at = now(), revoked_reason = 'replaced', access_hash = NULL, refresh_hash = NULL
                      WHERE user_id = ? AND device_key = ? AND revoked_at IS NULL`, u.ID, deviceKey)
	}
	now := time.Now()
	id := db.Core.InsertID("api_devices", db.Cols{
		{Name: "user_id", Value: u.ID}, {Name: "name", Value: name}, {Name: "device_key", Value: deviceKey}, {Name: "platform", Value: platform},
		{Name: "session_version", Value: u.SessionVersion}, {Name: "expires_at", Value: iso(now.Add(time.Duration(MaxDays()) * 24 * time.Hour))},
		{Name: "created_ip", Value: c.IP()}, {Name: "last_ip", Value: c.IP()}, {Name: "last_used_at", Value: iso(now)},
		{Name: "user_agent", Value: conv.Substr(ua, 0, 255)},
	})
	return rotate(c, id, nil)
}

// rotate: a new token pair for a device; the refresh token it replaces is remembered to catch reuse.
func rotate(c *app.Ctx, deviceID int, oldRefreshHash any) *config.Map {
	access := random(accessPrefix)
	refresh := random(refreshPrefix)
	d := db.First("SELECT expires_at FROM api_devices WHERE id = ?", deviceID)
	now := time.Now().Unix()
	end := conv.StrToTime(d["expires_at"])
	accessEnd := min(now+int64(AccessMinutes()*60), end)
	refreshEnd := min(now+int64(RefreshDays()*86400), end)
	db.Exec(`UPDATE api_devices SET access_hash = ?, access_expires_at = ?, refresh_hash = ?, refresh_expires_at = ?, prev_refresh_hash = ?,
                  last_used_at = now(), last_ip = ? WHERE id = ?`,
		hash(access), iso(time.Unix(accessEnd, 0)), hash(refresh), iso(time.Unix(refreshEnd, 0)), oldRefreshHash, c.IP(), deviceID)
	return Obj("token_type", "Bearer", "access_token", access, "expires_in", max(0, accessEnd-now),
		"refresh_token", refresh, "refresh_expires_in", max(0, refreshEnd-now), "device_id", deviceID)
}

// Refresh swaps a refresh token for a new pair; also returns the person's id.
func Refresh(c *app.Ctx, refresh string) (*config.Map, int) {
	if !strings.HasPrefix(refresh, refreshPrefix) {
		Fail(http.StatusUnauthorized, "invalid_token", c.T("Sign in again."))
	}
	h := hash(refresh)
	// Atomically take the current refresh token, so two parallel refreshes cannot both win.
	d := db.First("UPDATE api_devices SET refresh_hash = NULL WHERE refresh_hash = ? AND revoked_at IS NULL RETURNING *", h)
	if d == nil {
		stolen := db.First("SELECT * FROM api_devices WHERE prev_refresh_hash = ? AND revoked_at IS NULL", h)
		if stolen != nil {
			Revoke(stolen.Int("id"), "token_reuse")
			app.LogActivity(c, app.ActivityEntry{Action: "api_token_reuse", Type: "user", ID: stolen.Int("user_id"),
				Text: "An old sign-in token of :device was used again; the device was signed out", Params: []any{"device", stolen["name"]},
				Props: app.M{"ip": c.IP()}, Actor: stolen.Int("user_id"), NoNotify: true})
			alert(c, stolen.Int("user_id"), stolen.Str("name"))
		}
		Fail(http.StatusUnauthorized, "invalid_token", c.T("Sign in again."))
	}
	now := time.Now().Unix()
	if conv.StrToTime(d["refresh_expires_at"]) <= now || conv.StrToTime(d["expires_at"]) <= now {
		Revoke(d.Int("id"), "expired")
		Fail(http.StatusUnauthorized, "session_ended", c.T("Your sign-in on this device has ended. Sign in again."))
	}
	u := deviceUser(c, d)
	return rotate(c, d.Int("id"), h), u.ID
}

var bearerRe = regexp.MustCompile(`^Bearer\s+(acme_at_[A-Za-z0-9_-]{43})$`)

// Authenticate: the person and device behind "Authorization: Bearer …", or an API error (401/403).
func Authenticate(c *app.Ctx, header string) (*app.CurrentUser, db.Row) {
	m := bearerRe.FindStringSubmatch(strings.TrimSpace(header))
	if header == "" || m == nil {
		Fail(http.StatusUnauthorized, "unauthenticated", c.T("Sign in first."), "WWW-Authenticate", "Bearer")
	}
	d := db.First("SELECT * FROM api_devices WHERE access_hash = ? AND revoked_at IS NULL", hash(m[1]))
	if d == nil || conv.StrToTime(d["access_expires_at"]) <= time.Now().Unix() {
		Fail(http.StatusUnauthorized, "token_expired", c.T("The access token has expired. Refresh it."), "WWW-Authenticate", `Bearer error="invalid_token"`)
	}
	u := deviceUser(c, d)
	if d["last_used_at"] == nil || conv.StrToTime(d["last_used_at"]) < time.Now().Unix()-60 || d.Str("last_ip") != c.IP() {
		db.Exec("UPDATE api_devices SET last_used_at = now(), last_ip = ? WHERE id = ?", c.IP(), d["id"])
	}
	return u, d
}

// deviceUser: the device's person, if they may still use the API; otherwise the device is signed out.
func deviceUser(c *app.Ctx, d db.Row) *app.CurrentUser {
	u := app.LoadUser(d.Int("user_id"))
	why := ""
	switch {
	case u == nil:
		why = "user_inactive"
	case u.SessionVersion != d.Int("session_version"):
		why = "signed_out_everywhere"
	case conv.StrToTime(d["expires_at"]) <= time.Now().Unix():
		why = "expired"
	case !Allowed(u):
		why = "no_api_access"
	}
	if why != "" {
		Revoke(d.Int("id"), why)
		if why == "no_api_access" {
			Fail(http.StatusUnauthorized, "no_api_access", c.T("Your account may not use the mobile app. Ask an administrator."))
		}
		Fail(http.StatusUnauthorized, "session_ended", c.T("Your sign-in on this device has ended. Sign in again."))
	}
	return u
}

// Revoke signs one device out (both tokens stop working).
func Revoke(deviceID int, reason string) {
	db.Exec(`UPDATE api_devices SET revoked_at = coalesce(revoked_at, now()), revoked_reason = coalesce(revoked_reason, ?), access_hash = NULL, refresh_hash = NULL, prev_refresh_hash = NULL WHERE id = ?`,
		conv.Substr(reason, 0, 40), deviceID)
}

// RevokeAll signs out every device of a person; returns how many.
func RevokeAll(userID int, reason string) int {
	return db.Exec(`UPDATE api_devices SET revoked_at = now(), revoked_reason = ?, access_hash = NULL, refresh_hash = NULL, prev_refresh_hash = NULL
                         WHERE user_id = ? AND revoked_at IS NULL`, conv.Substr(reason, 0, 40), userID)
}

func alert(c *app.Ctx, userID int, device string) {
	// the alert must never break the response
	_ = app.Catch(func() {
		app.GuardEvent(c, userID, "revoked", "api", "Token reuse: "+device, "")
		ip := c.IP()
		app.SecurityAlert(c, "login", userID, func() (string, []template.HTML, string) {
			return c.T("A device was signed out for your safety"), []template.HTML{
					template.HTML(c.T("An old sign-in token of :d was used again, which can mean it was copied. That device was signed out.", "d", app.E(device))),
					template.HTML(c.T("IP address") + ": " + app.E(ip)),
				},
				c.T("If this was not you, change your password now and tell your administrator.")
		}, &app.MailButton{Label: c.T("Review security settings"), URL: c.URL("/profile/security")})
	})
}
