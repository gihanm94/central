package sheets

import (
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/google"
	"acme/internal/php"
)

// Google accounts a person connected for Sheets (gsheet_accounts): several per person, apart from the Gmail/Calendar
// connection. Uses the administrator's OAuth client (Administration → Connectors). (Support\Accounts)

// Scopes the module asks for.
var Scopes = []string{
	"openid", "email",
	"https://www.googleapis.com/auth/spreadsheets",           // write the synced tabs
	"https://www.googleapis.com/auth/drive.metadata.readonly", // list the person's spreadsheets to pick from
}

const scopeSheets = "https://www.googleapis.com/auth/spreadsheets"

func accountsOf(userID int) []db.Row {
	return db.Select("SELECT id, user_id, google_email, last_error, connected_at, refresh_token IS NOT NULL AS usable FROM gsheet_accounts WHERE user_id = ? ORDER BY google_email", userID)
}

func findAccount(id, userID int) db.Row {
	return db.First("SELECT * FROM gsheet_accounts WHERE id = ? AND user_id = ?", id, userID)
}

func expiry(seconds int) string { return php.Date("c", php.Unix(time.Now().Unix()+int64(seconds)-30)) }

// connectAccount finishes the Google sign-in: keep (or refresh) the account. Returns the e-mail.
func connectAccount(c *app.Ctx, userID int, code string) string {
	t := google.Oauth(c, google.Query{{K: "grant_type", V: "authorization_code"}, {K: "code", V: code}, {K: "redirect_uri", V: google.RedirectURI(c)}})
	if php.Empty(t["access_token"]) {
		google.Fail(c.T("Google did not accept the sign-in.") + " " + php.Str(php.Coalesce(t["error_description"], t["error"], "")))
	}
	scope := strings.Join(Scopes, " ")
	if t["scope"] != nil {
		scope = php.Str(t["scope"])
	}
	granted := strings.Split(scope, " ")
	if !php.InList(scopeSheets, granted...) {
		google.Fail(c.T("Google Sheets access was not granted. Connect again and allow access to your spreadsheets."))
	}
	access := php.Str(t["access_token"])
	_, me := google.APIRaw(access, "GET", "/oauth2/v3/userinfo", nil, nil)
	email := php.Str(me["email"])
	if email == "" {
		google.Fail(c.T("Google did not tell which account this is."))
	}
	refresh := php.Str(t["refresh_token"])
	if t["refresh_token"] == nil {
		if old := db.First("SELECT refresh_token FROM gsheet_accounts WHERE user_id = ? AND google_email = ?", userID, email); old != nil && !php.Empty(old["refresh_token"]) {
			refresh, _ = app.Decrypt(old.Str("refresh_token"))
		}
	}
	if refresh == "" {
		google.Fail(c.T("Google gave no long-lived access. Remove the app under your Google account permissions and connect again."))
	}
	expiresIn := 3600
	if t["expires_in"] != nil {
		expiresIn = php.Int(t["expires_in"])
	}
	saveAccount(userID, email, access, refresh, expiresIn)
	// The same consent also covered Gmail + Calendar: Mail and Calendar use this account too (if they have none of their own).
	google.Adopt(userID, email, access, refresh, expiresIn, granted)
	return email
}

func saveAccount(userID int, email, access, refresh string, expiresIn int) {
	db.Exec(`INSERT INTO gsheet_accounts (user_id, google_email, access_token, refresh_token, expires_at) VALUES (?, ?, ?, ?, ?)
                  ON CONFLICT (user_id, google_email) DO UPDATE SET access_token = EXCLUDED.access_token, refresh_token = EXCLUDED.refresh_token,
                  expires_at = EXCLUDED.expires_at, last_error = NULL, updated_at = now()`,
		userID, email, app.Encrypt(access), app.Encrypt(refresh), expiry(expiresIn))
}

// adopt is hook 'google.connected': the main 'Connect Google' also granted Sheets, so the account is ready here too.
func adopt(userID int, email, access, refresh string, expiresIn int, scopes []string) bool {
	if email == "" || !php.InList(scopeSheets, scopes...) {
		return false
	}
	saveAccount(userID, email, access, refresh, expiresIn)
	return true
}

// disconnectAccount removes the account and (by cascade) its syncs.
func disconnectAccount(id, userID int) {
	if findAccount(id, userID) == nil {
		return
	}
	// Only forgotten here: revoking at Google would also cut Mail and Calendar on the same account.
	db.Exec("DELETE FROM gsheet_accounts WHERE id = ?", id)
}

func accountToken(c *app.Ctx, accountID int) string {
	a := db.First("SELECT * FROM gsheet_accounts WHERE id = ?", accountID)
	if a == nil {
		google.Fail(c.T("The Google account was disconnected."))
	}
	if !php.Empty(a["access_token"]) && !php.Empty(a["expires_at"]) && php.StrToTime(a["expires_at"]) > time.Now().Unix()+60 {
		s, _ := app.Decrypt(a.Str("access_token"))
		return s
	}
	if php.Empty(a["refresh_token"]) {
		google.Fail(c.T("Google needs you to connect :e again.", "e", a["google_email"]))
	}
	rt, _ := app.Decrypt(a.Str("refresh_token"))
	t := google.Oauth(c, google.Query{{K: "grant_type", V: "refresh_token"}, {K: "refresh_token", V: rt}})
	if php.Empty(t["access_token"]) {
		if php.Str(t["error"]) == "invalid_grant" {
			db.Exec("UPDATE gsheet_accounts SET access_token = NULL, refresh_token = NULL, last_error = 'The access was removed on the Google side. Connect again.', updated_at = now() WHERE id = ?", accountID)
		}
		google.Fail(c.T("Google needs you to connect :e again.", "e", a["google_email"]))
	}
	expiresIn := 3600
	if t["expires_in"] != nil {
		expiresIn = php.Int(t["expires_in"])
	}
	db.Exec("UPDATE gsheet_accounts SET access_token = ?, expires_at = ?, last_error = NULL, updated_at = now() WHERE id = ?",
		app.Encrypt(php.Str(t["access_token"])), expiry(expiresIn), accountID)
	return php.Str(t["access_token"])
}

// accountAPI: a Google API call with the account's token; refreshes once on 401.
func accountAPI(c *app.Ctx, accountID int, method, u string, query google.Query, body any) (int, map[string]any) {
	st, b := google.APIRaw(accountToken(c, accountID), method, u, query, body)
	if st == 401 {
		db.Exec("UPDATE gsheet_accounts SET expires_at = now() - interval '1 minute' WHERE id = ?", accountID)
		st, b = google.APIRaw(accountToken(c, accountID), method, u, query, body)
	}
	return st, b
}
