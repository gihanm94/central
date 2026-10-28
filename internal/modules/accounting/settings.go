package accounting

import (
	"strings"
	"sync"
	"time"

	"acme/internal/config"
	"acme/internal/conv"
	"acme/internal/db"
)

// Connection settings of the ERP and the e-Tax portal (ErpSettings): defaults from config/erp.json, changes saved in erp_settings.
// PHP read the table once per request; here it is kept for two seconds (and forgotten at once after a change).

var (
	setMu    sync.Mutex
	setSaved map[string]any
	setAt    time.Time
)

func settingsSaved() map[string]any {
	setMu.Lock()
	defer setMu.Unlock()
	if setSaved != nil && time.Since(setAt) < 2*time.Second {
		return setSaved
	}
	out := map[string]any{}
	if err := db.Try(func() {
		for _, r := range acc().Select("SELECT key, value FROM erp_settings") {
			out[r.Str("key")] = r["value"]
		}
	}); err != nil {
		out = map[string]any{}
	}
	setSaved, setAt = out, time.Now()
	return out
}

func settingsForget() {
	setMu.Lock()
	setSaved = nil
	setMu.Unlock()
}

// sget is ErpSettings::get($key, $default): a saved value that is not empty, else the default.
func sget(key, def string) string {
	v, ok := settingsSaved()[key]
	if ok && v != nil && conv.Str(v) != "" {
		return conv.Str(v)
	}
	return def
}

// sgetNil is ErpSettings::get($key) with a null default.
func sgetNil(key string) any {
	v, ok := settingsSaved()[key]
	if ok && v != nil && conv.Str(v) != "" {
		return conv.Str(v)
	}
	return nil
}

// sput is ErpSettings::put (by 0 = NULL).
func sput(key string, value any, by int) {
	var u any
	if by > 0 {
		u = by
	}
	acc().Exec(`INSERT INTO erp_settings (key, value, updated_by, updated_at) VALUES (?, ?, ?, now())
                  ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_by = EXCLUDED.updated_by, updated_at = now()`, key, value, u)
	settingsForget()
}

func connSetting(key string) string {
	return sget("conn."+key, config.String("erp.connection."+key, ""))
}

func schedSetting(key string) string {
	return sget("schedule."+key, config.String("erp.schedule."+key, ""))
}

// overwriteOn: per ERP table, update rows that already exist (on) or only add new ones (off, the default).
func overwriteOn(entityKey string) bool { return sget("overwrite."+entityKey, "0") == "1" }

func erpPassword() string { return sget("conn.password", "") }

func erpBaseURL() string {
	ip := strings.TrimSpace(connSetting("ip"))
	if ip == "" {
		return ""
	}
	port := strings.TrimSpace(connSetting("port"))
	base := ip
	if !strings.HasPrefix(ip, "http") {
		base = "https://" + ip
	}
	if port != "" {
		base += ":" + port
	}
	return base
}

func erpEndpoint(key string) string {
	def := ""
	if l, ok := config.Get("erp.endpoints." + key).([]any); ok && len(l) > 0 {
		def = conv.Str(l[0])
	}
	return sget("api."+key, def)
}

func inetSetting(key string) string {
	return sget("inet."+key, config.String("erp.inet."+key, ""))
}

func erpConfigured() bool {
	return erpBaseURL() != "" && connSetting("username") != "" && erpPassword() != ""
}
