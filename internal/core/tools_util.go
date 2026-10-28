package core

import (
	"encoding/json"
	"fmt"
	"strings"

	"acme/internal/config"
)

// om builds an ordered object (a PHP array literal) from key/value pairs.
func om(kv ...any) *config.Map {
	m := config.NewMap()
	for i := 0; i+1 < len(kv); i += 2 {
		m.Set(kv[i].(string), kv[i+1])
	}
	return m
}

// jsonCompat is json_encode($v, JSON_UNESCAPED_UNICODE): "/" escaped as "\/", <, > and & left alone.
func jsonCompat(v any) string {
	b, err := json.Marshal(v)
	if err != nil {
		return "null"
	}
	s := strings.NewReplacer(`<`, "<", `>`, ">", `&`, "&").Replace(string(b))
	return strings.ReplaceAll(s, "/", `\/`)
}

// jsonCompatAscii is json_encode($v) without flags: like jsonCompat, and every non-ASCII character as \uXXXX.
func jsonCompatAscii(v any) string {
	var b strings.Builder
	for _, r := range jsonCompat(v) {
		switch {
		case r < 0x80:
			b.WriteRune(r)
		case r > 0xFFFF:
			r -= 0x10000
			fmt.Fprintf(&b, "\\u%04x\\u%04x", 0xD800+(r>>10), 0xDC00+(r&0x3FF))
		default:
			fmt.Fprintf(&b, "\\u%04x", r)
		}
	}
	return b.String()
}
