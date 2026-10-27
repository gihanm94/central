package core

import (
	"encoding/json"
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

// phpJSON is json_encode($v, JSON_UNESCAPED_UNICODE): "/" escaped as "\/", <, > and & left alone.
func phpJSON(v any) string {
	b, err := json.Marshal(v)
	if err != nil {
		return "null"
	}
	s := strings.NewReplacer(`<`, "<", `>`, ">", `&`, "&").Replace(string(b))
	return strings.ReplaceAll(s, "/", `\/`)
}
