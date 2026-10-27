// Package google is Google OAuth + a tiny API client, without any SDK (App\Core\Support\Google in the PHP version).
//
// One connection per person (google_connections). The administrator enters the OAuth client id / secret once
// (Administration → Connectors); each person then connects their own account. The three addresses can be changed in
// Settings (google_auth_url, google_token_url, google_api_url) — used by the test server.
//
// One sign-in serves everything: the consent asks for Gmail + Calendar and the scopes modules add (hook 'google.scopes',
// e.g. Sheets), and modules are told about it (hook 'google.connected'). Disconnecting only forgets the tokens here; it does
// not revoke the grant at Google, because that would also cut the other parts using the same Google account.
//
// Like the PHP code, failures are thrown (panic with an error); callers catch them with app.Catch.
package google

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"net/url"
	"regexp"
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

// Scopes: Gmail + Calendar (Google::SCOPES).
var Scopes = []string{
	"openid", "email",
	"https://www.googleapis.com/auth/gmail.readonly", "https://www.googleapis.com/auth/gmail.send",
	"https://www.googleapis.com/auth/calendar",
}

// Fail throws a RuntimeException.
func Fail(msg string) { panic(errors.New(msg)) }

func ClientID() string { return strings.TrimSpace(app.Setting("google_client_id", "")) }

func ClientSecret() string {
	s, _ := app.Decrypt(app.Setting("google_client_secret", ""))
	return s
}

func Configured() bool { return ClientID() != "" && ClientSecret() != "" }

// RedirectURI is url('/connect/google/callback').
func RedirectURI(c *app.Ctx) string { return c.URL("/connect/google/callback") }

func authURL() string {
	return php.OrStr(app.Setting("google_auth_url", ""), "https://accounts.google.com/o/oauth2/v2/auth")
}
func tokenURL() string {
	return php.OrStr(app.Setting("google_token_url", ""), "https://oauth2.googleapis.com/token")
}

// APIURL is the API base address.
func APIURL() string {
	return strings.TrimRight(php.OrStr(app.Setting("google_api_url", ""), "https://www.googleapis.com"), "/")
}

// AllScopes: Gmail + Calendar and every scope a module asks for (hook 'google.scopes'), so one consent covers all.
func AllScopes() []string {
	all := append([]string{}, Scopes...)
	res := app.RunHooks("google.scopes", "", func(_ string, fn any) any {
		if f, ok := fn.(app.HookScopes); ok {
			return f()
		}
		return nil
	})
	for _, m := range app.Modules() { // the order of the modules, like PHP's foreach
		if more, ok := res[m.Key].([]string); ok {
			all = append(all, more...)
		}
	}
	return unique(all)
}

func unique(in []string) []string {
	seen := map[string]bool{}
	out := []string{}
	for _, s := range in {
		if !seen[s] {
			seen[s] = true
			out = append(out, s)
		}
	}
	return out
}

// KV is one query value; V is a string, an int or a []string (repeated: metadataHeaders=From&metadataHeaders=Subject).
type KV struct {
	K string
	V any
}

// Query is http_build_query of ordered values (empty values are kept; use Filter for array_filter).
type Query []KV

// Filter is array_filter: drops empty values ("", 0, nil).
func (q Query) Filter() Query {
	out := Query{}
	for _, kv := range q {
		if !php.Empty(kv.V) {
			out = append(out, kv)
		}
	}
	return out
}

func phpEscape(s string) string { return strings.ReplaceAll(url.QueryEscape(s), "~", "%7E") }

// Encode is http_build_query (arrays without their [n] index, like the PHP client sent them).
func (q Query) Encode() string {
	var parts []string
	for _, kv := range q {
		switch v := kv.V.(type) {
		case []string:
			for _, s := range v {
				parts = append(parts, phpEscape(kv.K)+"="+phpEscape(s))
			}
		case bool:
			b := "0"
			if v {
				b = "1"
			}
			parts = append(parts, phpEscape(kv.K)+"="+b)
		case nil:
		default:
			parts = append(parts, phpEscape(kv.K)+"="+phpEscape(php.Str(v)))
		}
	}
	return strings.Join(parts, "&")
}

// LoginURL is the consent page address. scopes nil = AllScopes(); hint "" = none.
func LoginURL(c *app.Ctx, state, hint string, scopes []string, prompt string) string {
	if scopes == nil {
		scopes = AllScopes()
	}
	if prompt == "" {
		prompt = "consent"
	}
	return authURL() + "?" + Query{
		{"client_id", ClientID()}, {"redirect_uri", RedirectURI(c)}, {"response_type", "code"}, {"scope", strings.Join(scopes, " ")}, {"access_type", "offline"},
		// keep what was granted before: one refresh token keeps working for Gmail, Calendar and Sheets together
		{"prompt", prompt}, {"include_granted_scopes", "true"}, {"state", state}, {"login_hint", hint},
	}.Filter().Encode()
}

// ForwardTo: sign-in flows of modules use the same redirect URI: they put their own callback path in the session
// and /connect/google/callback hands the answer on to it.
func ForwardTo(c *app.Ctx, path string) { c.Sess.Set("google_forward", path) }

// Connection is the person's google_connections row (nil when none).
func Connection(userID int) db.Row {
	return db.First("SELECT * FROM google_connections WHERE user_id = ?", userID)
}

func Connected(userID int) bool {
	c := Connection(userID)
	return c != nil && !php.Empty(c["refresh_token"])
}

func decrypt(v any) string { s, _ := app.Decrypt(php.Str(v)); return s }

func expiry(seconds int) string { return php.Date("c", php.Unix(time.Now().Unix()+int64(seconds)-30)) }

// Connect finishes the sign-in: swap the code for tokens and keep them. Returns the saved row values.
func Connect(c *app.Ctx, userID int, code string) app.M {
	t := token(c, Query{{"grant_type", "authorization_code"}, {"code", code}, {"redirect_uri", RedirectURI(c)}})
	if php.Empty(t["access_token"]) {
		Fail(c.T("Google did not accept the sign-in.") + " " + php.Str(php.Coalesce(t["error_description"], t["error"], "")))
	}
	access := php.Str(t["access_token"])
	old := Connection(userID)
	refresh := php.Str(t["refresh_token"])
	if t["refresh_token"] == nil && old != nil {
		refresh = decrypt(old["refresh_token"])
	}
	if refresh == "" {
		Fail(c.T("Google gave no long-lived access. Remove the app under your Google account permissions and connect again."))
	}
	_, me := APIRaw(access, "GET", "/gmail/v1/users/me/profile", nil, nil)
	expiresIn := 3600
	if t["expires_in"] != nil {
		expiresIn = php.Int(t["expires_in"])
	}
	scopes := strings.Join(Scopes, " ")
	if t["scope"] != nil {
		scopes = php.Str(t["scope"])
	}
	row := app.M{"google_email": php.Str(me["emailAddress"]), "access_token": app.Encrypt(access), "refresh_token": app.Encrypt(refresh),
		"expires_at": expiry(expiresIn), "scopes": scopes, "last_error": nil, "updated_at": php.NowString()}
	save(userID, row)
	// Modules that use the same Google account (Sheets) take it too, when the grant covers them.
	email := php.Str(row["google_email"])
	granted := strings.Split(scopes, " ")
	app.RunHooks("google.connected", "", func(_ string, fn any) any {
		if f, ok := fn.(app.HookGoogleConnected); ok {
			f(c, userID, email, access, refresh, expiresIn, granted)
		}
		return nil
	})
	return row
}

func save(userID int, row app.M) {
	db.Exec(`INSERT INTO google_connections (user_id, google_email, access_token, refresh_token, expires_at, scopes, last_error, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, now())
                  ON CONFLICT (user_id) DO UPDATE SET google_email = EXCLUDED.google_email, access_token = EXCLUDED.access_token, refresh_token = EXCLUDED.refresh_token, expires_at = EXCLUDED.expires_at, scopes = EXCLUDED.scopes, last_error = NULL, updated_at = now()`,
		userID, row["google_email"], row["access_token"], row["refresh_token"], row["expires_at"], row["scopes"], nil)
}

// Adopt: a module's own Google sign-in also granted Gmail + Calendar: use it for Mail and Calendar too, unless the person
// already has a working connection to another Google account.
func Adopt(userID int, email, access, refresh string, expiresIn int, scopes []string) bool {
	have := map[string]bool{"openid": true, "email": true}
	for _, s := range scopes {
		have[s] = true
	}
	for _, s := range Scopes {
		if !have[s] {
			return false // Gmail / Calendar not granted
		}
	}
	c := Connection(userID)
	if c != nil && !php.Empty(c["refresh_token"]) && php.Empty(c["last_error"]) && !strings.EqualFold(c.Str("google_email"), email) {
		return false // keep their own, different account
	}
	save(userID, app.M{"google_email": email, "access_token": app.Encrypt(access), "refresh_token": app.Encrypt(refresh),
		"expires_at": expiry(expiresIn), "scopes": strings.Join(scopes, " ")})
	return true
}

// Disconnect forgets the tokens here. The grant stays at Google (revoking it would also cut Sheets on the same account).
func Disconnect(userID int) {
	db.Exec("DELETE FROM google_connections WHERE user_id = ?", userID)
}

// AccessToken is a valid access token (refreshed when it is about to end).
func AccessToken(c *app.Ctx, userID int) string {
	conn := Connection(userID)
	if conn == nil {
		Fail(c.T("Google is not connected."))
	}
	if !php.Empty(conn["expires_at"]) && php.StrToTime(conn["expires_at"]) > time.Now().Unix()+60 && !php.Empty(conn["access_token"]) {
		return decrypt(conn["access_token"])
	}
	rt := decrypt(conn["refresh_token"])
	if rt == "" { // saved under another app key (config/config.php was rebuilt)
		db.Exec("UPDATE google_connections SET access_token = NULL, refresh_token = NULL, last_error = 'The saved Google key can no longer be read (the app key changed). Connect again.' WHERE user_id = ?", userID)
		Fail(c.T("Google needs you to connect again."))
	}
	t := token(c, Query{{"grant_type", "refresh_token"}, {"refresh_token", rt}})
	if php.Empty(t["access_token"]) {
		if php.Str(t["error"]) == "invalid_grant" { // ended at Google: removed by the person, password change, or a 'Testing' OAuth app (7-day limit)
			db.Exec("UPDATE google_connections SET access_token = NULL, refresh_token = NULL, last_error = ? WHERE user_id = ?",
				"Google ended the connection ("+php.Substr(php.Str(php.Coalesce(t["error_description"], "invalid_grant")), 0, 120)+"). Connect again.", userID)
		} else { // network or Google trouble: keep the connection, try again later
			db.Exec("UPDATE google_connections SET last_error = ? WHERE user_id = ?", "Google could not be reached just now: "+php.Substr(php.Str(php.Coalesce(t["error"], "no answer")), 0, 120), userID)
		}
		Fail(c.T("Google needs you to connect again."))
	}
	expiresIn := 3600
	if t["expires_in"] != nil {
		expiresIn = php.Int(t["expires_in"])
	}
	db.Exec("UPDATE google_connections SET access_token = ?, expires_at = ?, last_error = NULL, updated_at = now() WHERE user_id = ?", app.Encrypt(php.Str(t["access_token"])), expiry(expiresIn), userID)
	return php.Str(t["access_token"])
}

// API calls Google with the person's token; a refused token is refreshed once and the call retried.
func API(c *app.Ctx, userID int, method, path string, query Query, body any) (int, map[string]any) {
	st, b := APIRaw(AccessToken(c, userID), method, path, query, body)
	if st == 401 { // the token was refused: refresh once and retry
		db.Exec("UPDATE google_connections SET expires_at = now() - interval '1 minute' WHERE user_id = ?", userID)
		st, b = APIRaw(AccessToken(c, userID), method, path, query, body)
	}
	return st, b
}

var client = &http.Client{
	Timeout: 20 * time.Second,
	Transport: &http.Transport{
		Proxy:               http.ProxyFromEnvironment,
		DialContext:         (&net.Dialer{Timeout: 8 * time.Second}).DialContext,
		TLSHandshakeTimeout: 8 * time.Second,
	},
}

// JSONBody marshals like json_encode(…, JSON_UNESCAPED_UNICODE) (no HTML escaping).
func JSONBody(v any) []byte {
	var buf bytes.Buffer
	enc := json.NewEncoder(&buf)
	enc.SetEscapeHTML(false)
	_ = enc.Encode(v)
	return bytes.TrimRight(buf.Bytes(), "\n")
}

// APIRaw calls Google with a token: status and the decoded JSON answer ({} when not JSON). body nil = no body.
func APIRaw(tok, method, path string, query Query, body any) (int, map[string]any) {
	u := path
	if !strings.HasPrefix(path, "https://") && !strings.HasPrefix(path, "http://") {
		u = APIURL() + path
	}
	if len(query) > 0 {
		u += "?" + query.Encode()
	}
	var rd io.Reader
	if body != nil {
		rd = bytes.NewReader(JSONBody(body))
	}
	req, err := http.NewRequestWithContext(context.Background(), method, u, rd)
	if err != nil {
		Fail("Google: " + err.Error())
	}
	req.Header.Set("Authorization", "Bearer "+tok)
	req.Header.Set("Accept", "application/json")
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	resp, err := client.Do(req)
	if err != nil {
		Fail("Google: " + err.Error())
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(resp.Body)
	return resp.StatusCode, decodeObject(raw)
}

func decodeObject(raw []byte) map[string]any {
	var m map[string]any
	if err := json.Unmarshal(raw, &m); err != nil || m == nil {
		return map[string]any{}
	}
	return m
}

// Oauth is the token endpoint: exchange a code ('authorization_code') or renew ('refresh_token'). Raw answer, never
// throws for an OAuth error.
func Oauth(c *app.Ctx, params Query) map[string]any { return token(c, params) }

func token(c *app.Ctx, params Query) map[string]any {
	return post(tokenURL(), append(append(Query{}, params...), KV{"client_id", ClientID()}, KV{"client_secret", ClientSecret()}))
}

func post(u string, form Query) map[string]any {
	resp, err := client.Post(u, "application/x-www-form-urlencoded", strings.NewReader(form.Encode()))
	if err != nil {
		return map[string]any{}
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(resp.Body)
	return decodeObject(raw)
}

// ---------------------------------------------------------------- small helpers for JSON answers

// Map is $a['k'] as an array ([] when missing).
func Map(v any) map[string]any {
	if m, ok := v.(map[string]any); ok {
		return m
	}
	return map[string]any{}
}

// List is $a['k'] as a list.
func List(v any) []any {
	if l, ok := v.([]any); ok {
		return l
	}
	return nil
}

// ErrorMessage is $body['error']['message'] ?? def.
func ErrorMessage(body map[string]any, def string) string {
	if m, ok := Map(body["error"])["message"]; ok && m != nil {
		return php.Str(m)
	}
	return def
}

var tagRe = regexp.MustCompile(`(?s)<[^>]*>`)

// RawURLEncode is rawurlencode().
func RawURLEncode(s string) string { return strings.ReplaceAll(url.QueryEscape(s), "+", "%20") }

// StripTags is strip_tags().
func StripTags(s string) string { return tagRe.ReplaceAllString(s, "") }
