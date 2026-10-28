package app

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"html/template"
	"strings"
	"sync"
	"time"

	"acme/internal/config"
	"acme/internal/conv"
	"acme/internal/db"
	"acme/internal/i18n"
)

// ---------------------------------------------------------------- settings (key/value company settings)

// Settings are read once per request (Ctx) or per call outside a request.
func settingsAll() map[string]string {
	out := map[string]string{}
	_ = db.Try(func() {
		for _, r := range db.Select("SELECT key, value FROM settings") {
			out[r.Str("key")] = r.Str("value")
		}
	})
	return out
}

var (
	settingsMu    sync.RWMutex
	settingsCache map[string]string
	settingsAt    time.Time
)

func settingsMap() map[string]string {
	settingsMu.RLock()
	m, at := settingsCache, settingsAt
	settingsMu.RUnlock()
	if m != nil && time.Since(at) < 3*time.Second {
		return m
	}
	m = settingsAll()
	settingsMu.Lock()
	settingsCache, settingsAt = m, time.Now()
	settingsMu.Unlock()
	return m
}

// Setting is setting($key, $default) (read again every few seconds, and at once after PutSetting).
func Setting(key, def string) string {
	if v, ok := settingsMap()[key]; ok {
		return v
	}
	return def
}

// SettingSet tells whether a setting is stored at all.
func SettingSet(key string) bool {
	_, ok := settingsMap()[key]
	return ok
}

// PutSetting stores a setting (nil value = NULL).
func PutSetting(key string, value any) {
	db.Exec(`INSERT INTO settings (key, value, updated_at) VALUES (?, ?, now())
                  ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()`, key, value)
	ForgetSettings()
}

// ForgetSettings drops the settings cache.
func ForgetSettings() {
	settingsMu.Lock()
	settingsCache = nil
	settingsMu.Unlock()
}

// Branding is Settings::branding().
func Branding(c *Ctx) M {
	name := Setting("company_name", config.String("app.name", ""))
	banner := strings.TrimSpace(Setting("login_banner", name))
	return M{
		"name":        name,
		"tagline":     Setting("login_tagline", "Every department, one sign-in."),
		"logo":        c.UploadURL(Setting("company_logo", "")),
		"login_image": c.UploadURL(Setting("login_image", "")),
		"support":     Setting("support_email", ""),
		"banner":      banner,
		"auth_logo":   Setting("auth_logo", "1") != "0",
	}
}

// ---------------------------------------------------------------- crypt (AES-256-GCM, same format as PHP: "v1." + base64(iv|tag|cipher))

func cryptKey() []byte {
	h := sha256.Sum256([]byte("acme-secret|" + config.String("app.key", "")))
	return h[:]
}

// Encrypt a small secret (OAuth tokens, client secrets).
func Encrypt(plain string) string {
	block, _ := aes.NewCipher(cryptKey())
	gcm, _ := cipher.NewGCM(block)
	iv := make([]byte, 12)
	_, _ = rand.Read(iv)
	sealed := gcm.Seal(nil, iv, []byte(plain), nil) // cipher | tag
	ct, tag := sealed[:len(sealed)-16], sealed[len(sealed)-16:]
	raw := append(append(append([]byte{}, iv...), tag...), ct...)
	return "v1." + base64.StdEncoding.EncodeToString(raw)
}

// Decrypt returns the secret, "" for "", or ok=false when it cannot be read.
func Decrypt(stored string) (string, bool) {
	if stored == "" {
		return "", true
	}
	if !strings.HasPrefix(stored, "v1.") {
		return "", false
	}
	raw, err := base64.StdEncoding.DecodeString(stored[3:])
	if err != nil || len(raw) < 29 {
		return "", false
	}
	block, _ := aes.NewCipher(cryptKey())
	gcm, _ := cipher.NewGCM(block)
	iv, tag, ct := raw[:12], raw[12:28], raw[28:]
	p, err := gcm.Open(nil, iv, append(append([]byte{}, ct...), tag...), nil)
	if err != nil {
		return "", false
	}
	return string(p), true
}

// ---------------------------------------------------------------- activity log

// ActivityEntry is one Activity::log() call.
type ActivityEntry struct {
	Action   string
	Type     string // subject type ("" = none)
	ID       int    // subject id (0 = none)
	Label    string
	Text     string // English key: 'Updated :type ":label"'
	Params   []any  // name/value pairs for Text
	Props    M      // properties (changes, attributes, _url …)
	Owner    int    // record owner to notify (0 = none)
	Actor    int    // who did it (0 = the signed-in person)
	NoNotify bool
	Module   string // "" = core
}

// NotifyEvent is set by the notifier: a record event reaches the actor and the owner by app / e-mail / Lark.
var NotifyEvent func(c *Ctx, module, action string, text M, changes any, actorID, ownerID int, url string)

// LogActivity writes activity_logs (descriptions in English, re-rendered in the reader's language) and notifies.
func LogActivity(c *Ctx, e ActivityEntry) int {
	if e.Actor == 0 && c != nil {
		e.Actor = c.UserID()
	}
	if e.Text == "" {
		e.Text = conv.Ucfirst(e.Action)
	}
	if e.Module == "" {
		e.Module = "core"
	}
	params := map[string]any{}
	order := []string{}
	for i := 0; i+1 < len(e.Params); i += 2 {
		k := conv.Str(e.Params[i])
		params[k] = e.Params[i+1]
		order = append(order, k)
	}
	pm := config.NewMap()
	for _, k := range order {
		pm.Set(k, params[k])
	}
	text := M{"key": e.Text, "params": pm}
	props := e.Props
	if props == nil {
		props = M{}
	}
	props["_t"] = text
	pj, _ := json.Marshal(props)
	var actor, typ, sid, label any
	if e.Actor > 0 {
		actor = e.Actor
	}
	if e.Type != "" {
		typ = e.Type
	}
	if e.ID > 0 {
		sid = e.ID
	}
	if e.Label != "" {
		label = conv.Substr(e.Label, 0, 250)
	}
	ip, ua := "", ""
	if c != nil {
		ip, ua = c.IP(), c.UserAgent()
	}
	id := db.Core.InsertID("activity_logs", db.Cols{{"user_id", actor}, {"module", e.Module}, {"action", e.Action}, {"subject_type", typ}, {"subject_id", sid},
		{"subject_label", label}, {"description", conv.Substr(RenderActivityText("en", text), 0, 250)}, {"properties", string(pj)}, {"ip_address", nilIfEmpty(ip)}, {"user_agent", ua}})
	if !e.NoNotify && NotifyEvent != nil {
		url := conv.Str(props["_url"])
		NotifyEvent(c, e.Module, e.Action, text, props["changes"], e.Actor, e.Owner, url)
	}
	return id
}

func nilIfEmpty(s string) any {
	if s == "" {
		return nil
	}
	return s
}

// RenderActivityText is activity_render(): the stored text in a language (the :type parameter is translated too).
func RenderActivityText(locale string, t any) string {
	var key string
	var params *config.Map
	switch x := t.(type) {
	case M:
		key = conv.Str(x["key"])
		params, _ = x["params"].(*config.Map)
		if params == nil {
			if pm, ok := x["params"].(map[string]any); ok {
				params = config.NewMap()
				for k, v := range pm {
					params.Set(k, v)
				}
			}
		}
	case *config.Map:
		k, _ := x.Get("key")
		key = conv.Str(k)
		p, _ := x.Get("params")
		params, _ = p.(*config.Map)
	}
	var pairs []any
	if params != nil {
		for _, k := range params.Keys {
			v := params.Vals[k]
			if k == "type" {
				v = i18n.T(locale, conv.Str(v))
			}
			pairs = append(pairs, k, v)
		}
	}
	return i18n.T(locale, key, pairs...)
}

// ActivityText is activity_text($log): a log row's description in the reader's language.
func ActivityText(c *Ctx, log db.Row) string {
	v, err := config.Decode([]byte(log.Str("properties")))
	if err == nil {
		if m, ok := v.(*config.Map); ok {
			if t, ok := m.Get("_t"); ok {
				return RenderActivityText(c.Locale(), t)
			}
		}
	}
	return log.Str("description")
}

// Change is one field difference of an "updated" entry.
type Change struct {
	From any `json:"from"`
	To   any `json:"to"`
}

// Changes keeps the field order of a diff.
type Changes struct {
	Keys []string
	Vals map[string]Change
}

func (c *Changes) Len() int { return len(c.Keys) }

func (c *Changes) Set(k string, ch Change) {
	if c.Vals == nil {
		c.Vals = map[string]Change{}
	}
	if _, ok := c.Vals[k]; !ok {
		c.Keys = append(c.Keys, k)
	}
	c.Vals[k] = ch
}

func (c *Changes) MarshalJSON() ([]byte, error) {
	m := config.NewMap()
	for _, k := range c.Keys {
		m.Set(k, c.Vals[k])
	}
	return m.MarshalJSON()
}

// Diff is Activity::diff(): field-by-field changes.
func Diff(before, after map[string]any, fields []string) *Changes {
	out := &Changes{}
	flat := func(v any) any {
		switch x := v.(type) {
		case bool:
			if x {
				return "yes"
			}
			return "no"
		case string:
			if x == "t" {
				return "yes"
			}
			if x == "f" {
				return "no"
			}
		}
		return v
	}
	for _, f := range fields {
		nv, ok := after[f]
		if !ok {
			continue
		}
		o, n := flat(before[f]), flat(nv)
		if conv.Str(o) != conv.Str(n) {
			out.Set(f, Change{From: o, To: n})
		}
	}
	return out
}

func phpStr(v any) string { return conv.Str(v) }

// E is e(): HTML-escape a value for HTML built in Go.
func E(v any) string { return template.HTMLEscapeString(conv.Str(v)) }

// HTML marks a string as safe HTML (built with E for every value in it).
func HTML(s string) template.HTML { return template.HTML(s) }

// Badge is a small coloured label: <span class="badge …">.
func Badge(label, classes string) template.HTML {
	return template.HTML(`<span class="badge ` + classes + `">` + E(label) + `</span>`)
}

// DeptColor is the colour of a department: its own, else a stable pick from the standard palette.
var DeptPalette = []string{"#2563eb", "#059669", "#d97706", "#7c3aed", "#db2777", "#0891b2", "#65a30d", "#ea580c", "#475569", "#be123c"}

const DeptNone = "#9ca3af"

var hex6 = regexpMust(`^#[0-9a-fA-F]{6}$`)

func DeptColor(id int, color string) string {
	if color != "" && hex6.MatchString(color) {
		return strings.ToLower(color)
	}
	if id > 0 {
		return DeptPalette[(id-1)%len(DeptPalette)]
	}
	return DeptNone
}
