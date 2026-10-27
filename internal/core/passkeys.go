package core

import (
	"crypto"
	"crypto/ecdsa"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/rsa"
	"crypto/sha256"
	"crypto/subtle"
	"crypto/x509"
	"encoding/base64"
	"encoding/binary"
	"encoding/json"
	"encoding/pem"
	"strings"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/php"
)

// Passkeys (PasskeyController + App\Core\Auth\WebAuthn), with no external library.
// Registration uses the browser's response.getPublicKey() (SPKI), so no CBOR parsing.
// Sign-in verifies the signature with the standard library: ES256 (-7), RS256 (-257) or Ed25519 (-8).
// The public key is stored as PEM, like the PHP version, so passkeys added there keep working here.

func init() {
	guest(func(r app.Router) {
		r.Post("/passkeys/login/options", passkeyLoginOptions)
		r.Post("/passkeys/login", passkeyLogin)
	})
	signedIn(func(r app.Router) {
		r.Post("/passkeys/options", passkeyRegisterOptions)
		r.Post("/passkeys", passkeyRegister)
		r.Post("/passkeys/{id}/delete", passkeyDestroy)
	})
}

func passkeyLoginOptions(c *app.Ctx) { c.JSON(webauthnLoginOptions(c)) }

func passkeyLogin(c *app.Ctx) {
	userID := webauthnVerifyLogin(c)
	user := app.LoadUser(userID)
	if user == nil {
		app.Invalid("passkey", c.T("This account is deactivated."))
	}
	c.Login(user, c.Bool("remember"), "passkey")
	to := php.Str(c.Sess.Get("_intended"))
	if to == "" {
		to = user.HomeURL()
	}
	c.Sess.Delete("_intended")
	if pre := app.BasePrefix(); pre != "" && strings.HasPrefix(to, pre+"/") {
		to = to[len(pre):]
	}
	if !strings.HasPrefix(to, "/") {
		to = "/dashboard"
	}
	c.JSON(app.M{"redirect": c.URL(to)})
}

func passkeyRegisterOptions(c *app.Ctx) { c.JSON(webauthnRegistrationOptions(c, c.MustUser())) }

func passkeyRegister(c *app.Ctx) {
	u := c.MustUser()
	id := webauthnRegister(c, u)
	name := php.Str(db.Scalar("SELECT name FROM passkeys WHERE id = ?", id))
	app.LogActivity(c, app.ActivityEntry{Action: "passkey_added", Type: "user", ID: u.ID, Label: u.Name, Text: `Added passkey ":label"`, Params: []any{"label", name}, NoNotify: true})
	c.JSON(app.M{"ok": true})
}

func passkeyDestroy(c *app.Ctx) {
	u := c.MustUser()
	id := c.ParamInt("id")
	key := db.First("SELECT * FROM passkeys WHERE id = ? AND user_id = ?", id, u.ID)
	if key == nil {
		app.Abort(404)
	}
	db.Exec("DELETE FROM passkeys WHERE id = ?", id)
	app.LogActivity(c, app.ActivityEntry{Action: "passkey_removed", Type: "user", ID: u.ID, Label: u.Name, Text: `Removed passkey ":label"`, Params: []any{"label", key.Str("name")}, NoNotify: true})
	c.Back("success", c.T("Passkey removed."))
}

// ---------------------------------------------------------------- WebAuthn

func webauthnRpID(c *app.Ctx) string {
	if id := config.String("security.passkey_rp_id", ""); id != "" {
		return id
	}
	return c.Host()
}

func webauthnFail(c *app.Ctx, msg string) { app.Invalid("passkey", msg) } // PHP throws the English text as is

func wsB64u(b []byte) string { return base64.RawURLEncoding.EncodeToString(b) }

// wsB64uDecode is the PHP decoder: base64url with or without padding; "" when it cannot be read.
func wsB64uDecode(s string) []byte {
	s = strings.TrimRight(strings.NewReplacer("-", "+", "_", "/").Replace(s), "=")
	b, err := base64.RawStdEncoding.DecodeString(s)
	if err != nil {
		return nil
	}
	return b
}

func webauthnRegistrationOptions(c *app.Ctx, u *app.CurrentUser) app.M {
	challenge := make([]byte, 32)
	_, _ = rand.Read(challenge)
	c.Sess.Set("webauthn_register", wsB64u(challenge))
	uid := sha256.Sum256([]byte(config.String("app.key", "") + "|" + php.Str(u.ID)))
	exclude := []app.M{}
	for _, r := range db.Select("SELECT credential_id FROM passkeys WHERE user_id = ?", u.ID) {
		exclude = append(exclude, app.M{"type": "public-key", "id": r.Str("credential_id")})
	}
	return app.M{
		"challenge":              wsB64u(challenge),
		"rp":                     app.M{"name": app.Setting("company_name", config.String("app.name", "")), "id": webauthnRpID(c)},
		"user":                   app.M{"id": wsB64u(uid[:]), "name": u.Email, "displayName": u.Name},
		"pubKeyCredParams":       []app.M{{"type": "public-key", "alg": -7}, {"type": "public-key", "alg": -8}, {"type": "public-key", "alg": -257}},
		"timeout":                60000,
		"attestation":            "none",
		"authenticatorSelection": app.M{"residentKey": "required", "requireResidentKey": true, "userVerification": "preferred"},
		"excludeCredentials":     exclude,
	}
}

func webauthnRegister(c *app.Ctx, u *app.CurrentUser) int {
	expected := php.Str(c.Sess.Get("webauthn_register"))
	c.Sess.Delete("webauthn_register")

	webauthnCheckClient(c, c.Input("clientDataJSON"), "webauthn.create", expected)
	auth := wsB64uDecode(c.Input("authenticatorData"))
	webauthnCheckAuthData(c, auth)

	der := wsB64uDecode(c.Input("publicKey"))
	if len(der) == 0 {
		webauthnFail(c, "This browser cannot create passkeys. Update it or try Chrome, Edge or Safari.")
	}
	enc := base64.StdEncoding.EncodeToString(der)
	var chunks []string
	for len(enc) > 64 {
		chunks = append(chunks, enc[:64])
		enc = enc[64:]
	}
	chunks = append(chunks, enc)
	pemText := "-----BEGIN PUBLIC KEY-----\n" + strings.Join(chunks, "\n") + "\n-----END PUBLIC KEY-----\n"
	alg := php.Int(c.InputAny("publicKeyAlgorithm"))
	if !(alg == -7 || alg == -8 || alg == -257) {
		webauthnFail(c, "Unsupported passkey type.")
	}
	if alg != -8 {
		if _, err := x509.ParsePKIXPublicKey(der); err != nil {
			webauthnFail(c, "Unsupported passkey type.")
		}
	}
	rawID := c.Input("rawId")
	if rawID == "" || len(rawID) > 500 {
		webauthnFail(c, "Invalid passkey id.")
	}
	name := strings.TrimSpace(c.Input("name"))
	if name == "" {
		name = "Passkey"
	}
	return db.Core.InsertID("passkeys", db.Cols{{Name: "user_id", Value: u.ID}, {Name: "name", Value: php.Substr(name, 0, 80)}, {Name: "credential_id", Value: rawID},
		{Name: "public_key", Value: pemText}, {Name: "algorithm", Value: alg}, {Name: "sign_count", Value: int64(binary.BigEndian.Uint32(auth[33:37]))}})
}

func webauthnLoginOptions(c *app.Ctx) app.M {
	challenge := make([]byte, 32)
	_, _ = rand.Read(challenge)
	c.Sess.Set("webauthn_login", wsB64u(challenge))
	return app.M{"challenge": wsB64u(challenge), "rpId": webauthnRpID(c), "timeout": 60000, "userVerification": "preferred", "allowCredentials": []any{}}
}

// webauthnVerifyLogin returns the user id the passkey belongs to.
func webauthnVerifyLogin(c *app.Ctx) int {
	expected := php.Str(c.Sess.Get("webauthn_login"))
	c.Sess.Delete("webauthn_login")

	key := db.First("SELECT * FROM passkeys WHERE credential_id = ?", c.Input("rawId"))
	if key == nil {
		webauthnFail(c, "This passkey is not registered. Sign in with your password, then add it from your profile.")
	}
	clientRaw := wsB64uDecode(c.Input("clientDataJSON"))
	webauthnCheckClient(c, c.Input("clientDataJSON"), "webauthn.get", expected)
	auth := wsB64uDecode(c.Input("authenticatorData"))
	webauthnCheckAuthData(c, auth)

	ch := sha256.Sum256(clientRaw)
	signed := append(append([]byte{}, auth...), ch[:]...)
	sig := wsB64uDecode(c.Input("signature"))
	if !webauthnVerifySignature(key.Int("algorithm"), key.Str("public_key"), signed, sig) {
		webauthnFail(c, "The passkey signature could not be verified.")
	}
	count := int64(binary.BigEndian.Uint32(auth[33:37]))
	if count != 0 && key.Int("sign_count") != 0 && count <= int64(key.Int("sign_count")) {
		webauthnFail(c, "This passkey looks cloned and was blocked.")
	}
	db.Exec("UPDATE passkeys SET sign_count = ?, last_used_at = now() WHERE id = ?", count, key["id"])
	return key.Int("user_id")
}

// webauthnVerifySignature checks sig over data with the stored PEM key (SHA-256; ECDSA signatures are DER).
func webauthnVerifySignature(alg int, pemText string, data, sig []byte) bool {
	block, _ := pem.Decode([]byte(pemText))
	if block == nil {
		return false
	}
	if alg == -8 {
		// the raw key is the last 32 bytes of the SPKI (like the PHP version)
		if len(sig) != ed25519.SignatureSize || len(block.Bytes) < 32 {
			return false
		}
		return ed25519.Verify(ed25519.PublicKey(block.Bytes[len(block.Bytes)-32:]), data, sig)
	}
	pub, err := x509.ParsePKIXPublicKey(block.Bytes)
	if err != nil {
		return false
	}
	h := sha256.Sum256(data)
	switch k := pub.(type) {
	case *ecdsa.PublicKey:
		return ecdsa.VerifyASN1(k, h[:], sig)
	case *rsa.PublicKey:
		return rsa.VerifyPKCS1v15(k, crypto.SHA256, h[:], sig) == nil
	case ed25519.PublicKey:
		return ed25519.Verify(k, data, sig)
	}
	return false
}

func webauthnCheckClient(c *app.Ctx, b64 string, typ, challenge string) {
	var cd map[string]any
	if err := json.Unmarshal(wsB64uDecode(b64), &cd); err != nil || cd == nil || php.Str(cd["type"]) != typ {
		webauthnFail(c, "Unexpected passkey response.")
	}
	if challenge == "" || subtle.ConstantTimeCompare([]byte(challenge), []byte(php.Str(cd["challenge"]))) != 1 {
		webauthnFail(c, "The passkey request expired. Try again.")
	}
	if strings.TrimRight(php.Str(cd["origin"]), "/") != c.Origin() {
		webauthnFail(c, "Passkey origin does not match this site.")
	}
}

func webauthnCheckAuthData(c *app.Ctx, auth []byte) {
	rp := sha256.Sum256([]byte(webauthnRpID(c)))
	if len(auth) < 37 || subtle.ConstantTimeCompare(rp[:], auth[:32]) != 1 {
		webauthnFail(c, "This passkey was made for a different site.")
	}
	if auth[32]&0x01 == 0 {
		webauthnFail(c, "Touch your security key or confirm on your device.")
	}
}
