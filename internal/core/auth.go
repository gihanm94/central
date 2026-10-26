package core

import (
	"crypto/subtle"
	"html/template"
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/i18n"
	"acme/internal/php"
)

// Sign in, sign out, forgot / reset password (AuthController). There is no public sign-up.

func showLogin(c *app.Ctx) {
	c.View("auth/login", app.M{"title": c.T("Sign in")})
}

func login(c *app.Ctx) {
	data := c.ValidateInput(app.Rule{Field: "username", Rules: "required|max:190", Label: c.T("username")}, app.Rule{Field: "password", Rules: "required", Label: c.T("password")})
	if msg := c.Attempt(data.Str("username"), data.Str("password"), c.Bool("remember")); msg != "" {
		app.Invalid("username", msg)
	}
	to := php.Str(c.Sess.Get("_intended"))
	if to == "" {
		to = c.User().HomeURL()
	}
	c.Sess.Delete("_intended")
	if pre := app.BasePrefix(); pre != "" && strings.HasPrefix(to, pre+"/") {
		to = to[len(pre):]
	}
	if strings.HasPrefix(to, "/") && !strings.HasPrefix(to, "//") {
		c.Redirect(to)
	}
	c.Redirect("/dashboard")
}

// language switcher (sign-in page and top bar); saves the choice for signed-in people too
func language(c *app.Ctx) {
	code := c.Param("code")
	if i18n.Has(code) {
		c.SetCookie("lang", code, 86400*365, true)
		if u := c.User(); u != nil {
			db.Exec("UPDATE users SET locale = ? WHERE id = ?", code, u.ID)
		}
	}
	c.Back("", "")
}

func logout(c *app.Ctx) {
	c.Logout()
	c.Flash("status", c.T("You have been signed out."))
	c.Redirect("/login")
}

func showForgot(c *app.Ctx) {
	c.View("auth/forgot-password", app.M{"title": c.T("Reset password")})
}

func sendReset(c *app.Ctx) {
	data := c.ValidateInput(app.Rule{Field: "email", Rules: "required|email", Label: c.T("e-mail")})
	user := db.First("SELECT id, name, email, locale FROM users WHERE lower(email) = lower(?) AND is_active AND deleted_at IS NULL", data.Str("email"))
	if user != nil {
		recent := db.Scalar("SELECT 1 FROM password_resets WHERE email = lower(?) AND created_at > now() - interval '1 minute'", user.Str("email"))
		if recent == nil {
			token := randHex(32)
			db.Exec(`INSERT INTO password_resets (email, token_hash, created_at) VALUES (lower(?), ?, now())
                          ON CONFLICT (email) DO UPDATE SET token_hash = EXCLUDED.token_hash, created_at = now()`, user.Str("email"), sha256Hex(token))
			minutes := config.Int("security.reset_minutes", 60)
			c.With(user.Str("locale"), func() {
				app.Notify(c, user.Str("email"), user.Str("name"), c.T("Reset your password"), []template.HTML{
					esc(c.T("We received a request to reset the password for your account.")),
					esc(c.T("This link expires in :minutes minutes.", "minutes", minutes)),
				}, &app.MailButton{Label: c.T("Choose a new password"), URL: c.URL("/reset-password", "token", token, "email", user.Str("email"))},
					c.T("If you did not ask for this, ignore this e-mail. Your password stays the same."))
			})
			app.LogActivity(c, app.ActivityEntry{Action: "password_reset_requested", Type: "user", ID: user.Int("id"), Label: user.Str("name"),
				Text: "Requested a password reset link", Actor: user.Int("id"), NoNotify: true})
		}
	}
	// same answer either way, so nobody can probe which e-mails exist
	c.Flash("status", c.T("If that e-mail belongs to an active account, a reset link is on its way."))
	c.Redirect("/forgot-password")
}

func showReset(c *app.Ctx) {
	c.View("auth/reset-password", app.M{"title": c.T("Choose a new password"), "token": c.Query("token"), "email": c.Query("email")})
}

func reset(c *app.Ctx) {
	data := c.ValidateInput(
		app.Rule{Field: "token", Rules: "required"},
		app.Rule{Field: "email", Rules: "required|email", Label: c.T("e-mail")},
		app.Rule{Field: "password", Rules: "required|password", Label: c.T("New password")},
		app.Rule{Field: "password_confirmation", Rules: "required|same:password", Label: c.T("Confirm new password")},
	)
	row := db.First("SELECT * FROM password_resets WHERE email = lower(?)", data.Str("email"))
	minutes := config.Int("security.reset_minutes", 60)
	if row == nil || subtle.ConstantTimeCompare([]byte(row.Str("token_hash")), []byte(sha256Hex(data.Str("token")))) != 1 ||
		php.StrToTime(row["created_at"]) < time.Now().Unix()-int64(60*minutes) {
		app.Invalid("email", c.T("This reset link is invalid or has expired. Request a new one."))
	}
	user := db.First("SELECT id, name, email FROM users WHERE lower(email) = lower(?) AND deleted_at IS NULL", data.Str("email"))
	if user == nil {
		app.Invalid("email", c.T("Account not found."))
	}
	db.Exec("UPDATE users SET password = ?, session_version = session_version + 1, updated_at = now() WHERE id = ?", app.PasswordHash(data.Str("password")), user["id"])
	db.Exec("DELETE FROM remember_tokens WHERE user_id = ?", user["id"])
	db.Exec("DELETE FROM password_resets WHERE email = lower(?)", data.Str("email"))
	app.LogActivity(c, app.ActivityEntry{Action: "password_reset", Type: "user", ID: user.Int("id"), Label: user.Str("name"), Text: "Reset password with an e-mail link", Actor: user.Int("id"), NoNotify: true})

	ip := c.IP()
	{
		app.SecurityAlert(c, "password", user.Int("id"), func() (string, []template.HTML, string) {
			return c.T("Your password was changed"),
				[]template.HTML{esc(c.T("Your password was reset using an e-mail link.")), template.HTML(template.HTMLEscapeString(c.T("IP address")+": "+ip) + " | " + template.HTMLEscapeString(app.FormatDate(c, php.NowString(), "d M Y, H:i")))},
				c.T("If this was not you, contact your administrator immediately.")
		}, &app.MailButton{Label: c.T("Sign in"), URL: c.URL("/login")})
	}
	c.Flash("status", c.T("Password saved. Sign in with your new password."))
	c.Redirect("/login")
}

func esc(s string) template.HTML { return template.HTML(template.HTMLEscapeString(s)) }
