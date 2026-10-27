// Package api is the mobile/API front door of /api/v1 (App\Core\Api in the PHP version): Bearer tokens instead of
// the session, JSON answers, errors as {"error": {"code", "message", "fields"?}}, rate limits and the request log.
//
// Module API handlers (app.Module.API) are ordinary app.Handler funcs registered with paths relative to /api/v1.
// They answer with c.JSON(body) (or api.Reply) and stop with api.Fail / app.Abort / app.Invalid; the helpers in
// this file are the PHP base class App\Core\Api\Controller.
package api

import (
	"fmt"
	"math"
	"net/http"
	"strings"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/php"
)

// Error is ApiException: an answer other than 2xx, {"error": {"code": "...", "message": "..."}}.
type Error struct {
	Status  int
	Code    string
	Message string
	Headers map[string]string
	Fields  *config.Map
}

func (e *Error) Error() string { return e.Message }

// Fail stops the request with an API error (throw new ApiException(...)); headers are name/value pairs.
func Fail(status int, code, message string, headers ...string) {
	e := &Error{Status: status, Code: code, Message: message}
	if len(headers) > 1 {
		e.Headers = map[string]string{}
		for i := 0; i+1 < len(headers); i += 2 {
			e.Headers[headers[i]] = headers[i+1]
		}
	}
	panic(e)
}

// Obj is an ordered JSON object from name/value pairs (PHP arrays keep their key order).
func Obj(kv ...any) *config.Map {
	m := config.NewMap()
	for i := 0; i+1 < len(kv); i += 2 {
		m.Set(php.Str(kv[i]), kv[i+1])
	}
	return m
}

// Rows turns ordered rows into JSON objects that keep the column order.
func Rows(rows []db.OrderedRow) []*config.Map {
	out := make([]*config.Map, 0, len(rows))
	for _, r := range rows {
		out = append(out, RowObj(r))
	}
	return out
}

// RowObj is one ordered row as a JSON object.
func RowObj(r db.OrderedRow) *config.Map {
	m := config.NewMap()
	for i, k := range r.Cols {
		m.Set(k, r.Vals[i])
	}
	return m
}

// User is $this->user(): the person behind the token, or 401.
func User(c *app.Ctx) *app.CurrentUser {
	u := c.User()
	if u == nil {
		Fail(http.StatusUnauthorized, "unauthenticated", c.T("Sign in first."))
	}
	return u
}

// Need: 403 unless the person holds action on resource (same permissions as the website).
func Need(c *app.Ctx, resource string, action ...string) {
	a := "view"
	if len(action) > 0 {
		a = action[0]
	}
	if !User(c).Can(resource, a) {
		Fail(http.StatusForbidden, "forbidden", c.T("You don't have permission to :a :r.", "a", a, "r", strings.ReplaceAll(resource, "_", " ")))
	}
}

// NotFound is 404 not_found.
func NotFound(c *app.Ctx) { Fail(http.StatusNotFound, "not_found", c.T("Not found.")) }

// Q is the search text from ?q= (trimmed, at most 100 characters).
func Q(c *app.Ctx) string { return php.Substr(c.Query("q"), 0, 100) }

// Like is an ILIKE pattern with %, _ and \ taken literally.
func Like(q string) string {
	return "%" + strings.NewReplacer(`\`, `\\`, "%", `\%`, "_", `\_`).Replace(q) + "%"
}

// Paged is one page of sql (which must not contain ORDER BY / LIMIT): ?page= (from 1) and ?per_page= (1–100,
// default 25). mapFn (optional) turns a row into what the app gets.
func Paged(c *app.Ctx, sql string, params []any, order, conn string, mapFn func(*config.Map) any) *config.Map {
	if conn == "" {
		conn = "core"
	}
	per := max(1, min(100, php.Int(c.Query("per_page", "25"))))
	page := max(1, min(10000, php.Int(c.Query("page", "1"))))
	q := db.On(conn)
	total := php.Int(q.Scalar("SELECT count(*) FROM ("+sql+") x", params...))
	rows := q.SelectOrdered(fmt.Sprintf("%s ORDER BY %s LIMIT %d OFFSET %d", sql, order, per, (page-1)*per), params...)
	data := make([]any, 0, len(rows))
	for _, r := range rows {
		if mapFn != nil {
			data = append(data, mapFn(RowObj(r)))
		} else {
			data = append(data, RowObj(r))
		}
	}
	return Obj("data", data, "meta", Obj("page", page, "per_page", per, "total", total, "pages", int(math.Ceil(float64(total)/float64(per)))))
}

// Input is only these keys of the request body (JSON or form), in the body's order.
func Input(c *app.Ctx, allowed ...string) *config.Map {
	all := c.All()
	out := config.NewMap()
	for _, k := range all.Keys {
		if php.InList(k, allowed...) {
			out.Set(k, all.Vals[k])
		}
	}
	return out
}

// Pick keeps only keys of a row, in that order (the API never sends whole table rows); nil stays nil.
func Pick(row db.Row, keys ...string) *config.Map {
	if row == nil {
		return nil
	}
	out := config.NewMap()
	for _, k := range keys {
		out.Set(k, row[k])
	}
	return out
}

// Reply answers with body; a "_status" key (as the PHP handlers return it) becomes the status code.
func Reply(c *app.Ctx, body *config.Map) {
	status := http.StatusOK
	if v, ok := body.Get("_status"); ok {
		status = php.Int(v)
		body.Delete("_status")
	}
	c.JSON(body, status)
}

// Device is the device row of this request's token (Kernel::device()); nil on sign-in / refresh.
func Device(c *app.Ctx) db.Row {
	d, _ := c.Shared[deviceKey].(db.Row)
	return d
}

const deviceKey = "_api_device"
