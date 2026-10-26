package app

import (
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/hex"
	"encoding/json"
	"os"
	"path/filepath"
	"regexp"
	"sync"
	"time"

	"acme/internal/php"
)

// SessionCookie is the browser cookie that carries the session id (PHP used the same name).
const SessionCookie = "acme_session"

// Session is the server-side session of one browser ($_SESSION). Values are JSON-friendly.
// Flash messages, old input, validation errors and the CSRF token live here, like App\Core\Support\Session.
type Session struct {
	ID      string
	Data    map[string]any
	changed bool
	oldIDs  []string // ids replaced in this request: their files are removed (or kept for the grace period)
	keepOld bool
}

var (
	sessMu    sync.Mutex
	sessLocks = map[string]*sync.Mutex{}
	sessIDRe  = regexp.MustCompile(`^[a-f0-9]{64}$`)
)

func sessionDir() string { return filepath.Join(BasePath, "storage", "sessions") }

func newSessionID() string {
	b := make([]byte, 32)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

func sessionFile(id string) string {
	h := sha256.Sum256([]byte(id))
	return filepath.Join(sessionDir(), hex.EncodeToString(h[:16])+".json")
}

type sessionDisk struct {
	Data    map[string]any `json:"data"`
	Expires int64          `json:"expires"`
	// AliasOf: an old id that still answers for a few seconds after a rotation (parallel requests)
	AliasOf string `json:"alias_of,omitempty"`
}

// lockSession serialises the requests of one browser, like PHP's session file lock.
func lockSession(id string) func() {
	sessMu.Lock()
	l, ok := sessLocks[id]
	if !ok {
		l = &sync.Mutex{}
		sessLocks[id] = l
	}
	sessMu.Unlock()
	l.Lock()
	return l.Unlock
}

func readSession(id string, depth int) (*Session, bool) {
	if !sessIDRe.MatchString(id) {
		return nil, false
	}
	raw, err := os.ReadFile(sessionFile(id))
	if err != nil {
		return nil, false
	}
	var d sessionDisk
	if json.Unmarshal(raw, &d) != nil || d.Expires < time.Now().Unix() {
		return nil, false
	}
	if d.AliasOf != "" && depth == 0 {
		return readSession(d.AliasOf, 1)
	}
	if d.Data == nil {
		d.Data = map[string]any{}
	}
	return &Session{ID: id, Data: d.Data}, true
}

func sessionLifetime() int64 {
	return int64((cfgInt("security.session_max_hours", 12) + 1) * 3600)
}

func (s *Session) save() {
	_ = os.MkdirAll(sessionDir(), 0o770)
	exp := time.Now().Unix() + sessionLifetime()
	b, _ := json.Marshal(sessionDisk{Data: s.Data, Expires: exp})
	tmp := sessionFile(s.ID) + ".tmp"
	if os.WriteFile(tmp, b, 0o600) == nil {
		_ = os.Rename(tmp, sessionFile(s.ID))
	}
	for _, old := range s.oldIDs {
		if s.keepOld {
			a, _ := json.Marshal(sessionDisk{AliasOf: s.ID, Expires: time.Now().Unix() + 60})
			_ = os.WriteFile(sessionFile(old), a, 0o600)
		} else {
			_ = os.Remove(sessionFile(old))
		}
	}
	s.oldIDs = nil
}

// Get / Set / Delete on the session data.
func (s *Session) Get(k string) any { return s.Data[k] }

func (s *Session) Set(k string, v any) {
	s.Data[k] = v
	s.changed = true
}

func (s *Session) Delete(k string) {
	if _, ok := s.Data[k]; ok {
		delete(s.Data, k)
		s.changed = true
	}
}

func (s *Session) Int(k string) int { return php.Int(s.Data[k]) }

// Clear empties the session ($_SESSION = []).
func (s *Session) Clear() {
	s.Data = map[string]any{}
	s.changed = true
}

// Regenerate gives the session a new id (session_regenerate_id). keepOld: the old id still answers for 60 s.
func (s *Session) Regenerate(keepOld bool) {
	s.oldIDs = append(s.oldIDs, s.ID)
	s.keepOld = keepOld
	s.ID = newSessionID()
	s.changed = true
}

// CSRF returns this session's token, making one when needed.
func (s *Session) CSRF() string {
	if t, ok := s.Data["_token"].(string); ok && t != "" {
		return t
	}
	t := newSessionID()
	s.Set("_token", t)
	return t
}

func (s *Session) VerifyCSRF(token string) bool {
	t, ok := s.Data["_token"].(string)
	return ok && token != "" && subtle.ConstantTimeCompare([]byte(t), []byte(token)) == 1
}

// Flash keeps a value for the next request.
func (s *Session) Flash(k string, v any) {
	next, _ := s.Data["_flash_next"].(map[string]any)
	if next == nil {
		next = map[string]any{}
	}
	next[k] = v
	s.Set("_flash_next", next)
}

// ageFlash: last request's flash becomes readable, then cleared (once per page request).
func (s *Session) ageFlash() {
	next, _ := s.Data["_flash_next"].(map[string]any)
	_, had := s.Data["_flash"]
	if next == nil && !had {
		return
	}
	if next == nil {
		next = map[string]any{}
	}
	s.Data["_flash"] = next
	delete(s.Data, "_flash_next")
	s.changed = true
}

// FlashGet reads this request's flash value.
func (s *Session) FlashGet(k string) any {
	f, _ := s.Data["_flash"].(map[string]any)
	return f[k]
}

// Old is the input sent back after a failed validation.
func (s *Session) Old(k string) (any, bool) {
	f, _ := s.Data["_flash"].(map[string]any)
	o, _ := f["_old"].(map[string]any)
	v, ok := o[k]
	return v, ok
}

// Errors are this request's validation errors, in order.
func (s *Session) Errors() *Errors {
	f, _ := s.Data["_flash"].(map[string]any)
	return errorsFrom(f["_errors"])
}

// GCSessions removes expired session files (called now and then).
func GCSessions() {
	files, _ := filepath.Glob(filepath.Join(sessionDir(), "*.json"))
	now := time.Now().Unix()
	for _, f := range files {
		raw, err := os.ReadFile(f)
		if err != nil {
			continue
		}
		var d sessionDisk
		if json.Unmarshal(raw, &d) != nil || d.Expires < now {
			_ = os.Remove(f)
		}
	}
}
