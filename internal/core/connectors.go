package core

import (
	"crypto/subtle"
	"net/url"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/google"
	"acme/internal/php"
)

// Connectors (ConnectorController): the administrator enters the Google OAuth client once; each person connects their
// own Gmail + Calendar.

func init() {
	signedIn(func(r app.Router) {
		r.Get("/connectors", connectorsAdmin)
		r.Post("/connectors", connectorsSaveAdmin)
		r.Post("/connectors/user/{id}/disconnect", connectorsDisconnectUser)
		r.Get("/profile/connectors", connectorsProfile)
		r.Post("/profile/connectors/google/disconnect", connectorsDisconnect)
		r.Get("/connect/google", connectorsStart)
		r.Get("/connect/google/callback", connectorsCallback)
	})
}

/* ----------------------------------------------------------------- administrator */

func connectorsAdmin(c *app.Ctx) {
	if !c.MustUser().IsAdmin() {
		app.Abort(403, c.T("Only an administrator can open this."))
	}
	c.View("admin/connectors", app.M{
		"title": c.T("Connectors"), "clientId": google.ClientID(), "hasSecret": google.ClientSecret() != "", "redirect": google.RedirectURI(c),
		"people": db.Select("SELECT g.*, u.name, u.email FROM google_connections g JOIN users u ON u.id = g.user_id ORDER BY u.name"),
	})
}

func connectorsSaveAdmin(c *app.Ctx) {
	if !c.MustUser().IsAdmin() {
		app.Abort(403)
	}
	app.PutSetting("google_client_id", c.Input("client_id"))
	if secret := c.Input("client_secret"); secret != "" {
		app.PutSetting("google_client_secret", app.Encrypt(secret))
	}
	c.Flash("success", c.T("Google connector saved."))
	c.Redirect("/connectors")
}

/* ----------------------------------------------------------------------- a person */

func connectorsProfile(c *app.Ctx) {
	u := c.MustUser()
	c.View("profile/connectors", app.M{"title": c.T("Connectors"), "me": ProfileMe(u), "configured": google.Configured(), "conn": google.Connection(u.ID)})
}

// ProfileMe is what the profile header (profile/_header) shows of a person.
func ProfileMe(u *app.CurrentUser) app.M {
	sub := u.JobTitle
	if u.Row["job_title"] == nil {
		sub = "" // $me->job_title ?? __($me->role_name): the role (translated in the view)
	}
	return app.M{"name": u.Name, "avatar": u.Avatar, "username": u.Username, "email": u.Email, "role_name": u.RoleName,
		"job_title": sub, "has_job_title": u.Row["job_title"] != nil}
}

// RedirectAway sends the browser to another site (header('Location: …'); exit).
func RedirectAway(c *app.Ctx, to string) {
	c.W.Header().Set("Location", to)
	c.Status(302)
	c.HTML("")
}

func connectorsStart(c *app.Ctx) {
	if !google.Configured() {
		app.Abort(404, c.T("The administrator has not set up the Google connector yet."))
	}
	state := randHex(16)
	c.Sess.Set("google_state", state)
	c.Sess.Delete("google_forward")
	back := c.Query("back")
	if back != "mail" && back != "calendar" {
		back = "profile"
	}
	c.Sess.Set("google_back", back)
	RedirectAway(c, google.LoginURL(c, state, c.MustUser().Email, nil, "consent"))
}

// pickQuery is array_intersect_key($_GET, array_flip($keys)) as a query string, in the order of the request.
func pickQuery(c *app.Ctx, keys ...string) string {
	seen := map[string]bool{}
	var order []string
	vals := map[string]string{}
	for _, part := range strings.Split(c.R.URL.RawQuery, "&") {
		k, v, _ := strings.Cut(part, "=")
		k, _ = url.QueryUnescape(k)
		v, _ = url.QueryUnescape(v)
		if !php.InList(k, keys...) {
			continue
		}
		if !seen[k] {
			seen[k] = true
			order = append(order, k)
		}
		vals[k] = v
	}
	q := google.Query{}
	for _, k := range order {
		q = append(q, google.KV{K: k, V: vals[k]})
	}
	return q.Encode()
}

func connectorsCallback(c *app.Ctx) {
	u := c.MustUser()
	forward := php.Str(c.Sess.Get("google_forward"))
	if forward != "" { // a module's own Google sign-in (see google.ForwardTo)
		c.Sess.Delete("google_forward")
		if strings.HasPrefix(forward, "/") && !strings.HasPrefix(forward, "//") {
			c.Redirect(forward + "?" + pickQuery(c, "code", "state", "error"))
		}
	}
	back := map[string]string{"mail": "/mail", "calendar": "/calendar"}[php.Str(c.Sess.Get("google_back"))]
	if back == "" {
		back = "/profile/connectors"
	}
	state := c.Query("state", "")
	saved := php.Str(c.Sess.Get("google_state"))
	if state == "" || subtle.ConstantTimeCompare([]byte(saved), []byte(state)) != 1 {
		c.Flash("error", c.T("The Google sign-in could not be checked. Try again."))
		c.Redirect("/profile/connectors")
	}
	c.Sess.Delete("google_state")
	c.Sess.Delete("google_back")
	if e := c.Query("error"); e != "" && e != "0" {
		c.Flash("error", c.T("Google said: :e", "e", e))
		c.Redirect("/profile/connectors")
	}
	var row app.M
	if err := app.Catch(func() { row = google.Connect(c, u.ID, c.Query("code", "")) }); err != nil {
		c.Flash("error", err.Error())
		back = "/profile/connectors"
	} else {
		c.Flash("success", c.T("Connected to Google as :e.", "e", row["google_email"]))
	}
	c.Redirect(back)
}

func connectorsDisconnect(c *app.Ctx) {
	google.Disconnect(c.MustUser().ID)
	c.Flash("success", c.T("Google was disconnected. Nothing is deleted from your Google account."))
	c.Redirect("/profile/connectors")
}

// An administrator can cut a person's connection.
func connectorsDisconnectUser(c *app.Ctx) {
	if !c.MustUser().IsAdmin() {
		app.Abort(403)
	}
	google.Disconnect(c.ParamInt("id"))
	c.Flash("success", c.T("Disconnected."))
	c.Redirect("/connectors")
}
