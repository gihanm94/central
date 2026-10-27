package accounting

import (
	"bytes"
	"crypto/tls"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/url"
	"regexp"
	"sort"
	"strings"
	"time"

	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/php"
)

/*
erpClient (Erp\Client) talks to the ERP: logs in once, keeps the session (id + cookies) in erp_tokens for an hour,
asks again on a 401, and reads the OData endpoints page by page.
*/
type erpClient struct {
	token   db.Row
	cookies *config.Map
}

const erpTTL = 3600

func newErpClient() *erpClient { return &erpClient{cookies: config.NewMap()} }

// session: the saved session if it is still fresh, otherwise a new login.
func (cl *erpClient) session(force bool) db.Row {
	if !force {
		t := cl.token
		if t == nil {
			t = acc().First("SELECT * FROM erp_tokens WHERE is_primary ORDER BY id DESC LIMIT 1")
		}
		if t != nil && !erpTokenExpired(t) {
			cl.token = t
			cl.cookies = config.NewMap()
			if v, err := config.Decode([]byte(php.OrStr(t.Str("cookies"), "[]"))); err == nil {
				if m, ok := v.(*config.Map); ok {
					cl.cookies = m
				}
			}
			return t
		}
	}
	return cl.login()
}

func erpTokenExpired(t db.Row) bool {
	return t.Str("session_id") == "" || t.Bool("session_suspended") || time.Now().Unix()-php.StrToTime(t["updated_at"]) > erpTTL
}

// login posts the user name and password; the ERP answers with a session id and cookies.
func (cl *erpClient) login() db.Row {
	user := connSetting("username")
	pass := erpPassword()
	if erpBaseURL() == "" || user == "" || pass == "" {
		panic(errors.New("Fill in the ERP address, user name and password first."))
	}
	cl.cookies = config.NewMap()
	body, _ := json.Marshal(map[string]any{"Username": user, "Password": pass, "ForceRelogin": true})
	b := string(body)
	r := cl.send("POST", erpEndpoint("tokenUrl"), &b, false, "")
	if r.status >= 400 || r.status == 0 {
		st := "no answer"
		if r.status != 0 {
			st = php.Str(r.status)
		}
		msg := r.err
		if msg == "" {
			msg = php.Substr(strings.TrimSpace(r.body), 0, 200)
		}
		panic(errors.New("Login failed (" + st + "): " + msg))
	}
	j := map[string]any{}
	_ = json.Unmarshal([]byte(r.body), &j)
	lower := map[string]any{}
	for k, v := range j {
		lower[strings.ToLower(k)] = v
	}
	if php.Empty(lower["sessionid"]) {
		panic(errors.New("Login answer has no session id."))
	}
	cookies := jsonEnc(cl.cookies, false)
	if cl.cookies.Len() == 0 {
		cookies = "[]"
	}
	cols := db.Cols{{Name: "session_id", Value: php.Str(lower["sessionid"])}, {Name: "session_suspended", Value: !php.Empty(lower["sessionsuspended"])}, {Name: "cookies", Value: cookies}}
	id := acc().Scalar("SELECT id FROM erp_tokens WHERE is_primary ORDER BY id DESC LIMIT 1")
	if id != nil {
		acc().Update("erp_tokens", append(cols, db.Col{Name: "updated_at", Value: php.NowString()}), db.Cols{{Name: "id", Value: id}})
	} else {
		acc().Insert("erp_tokens", append(cols, db.Col{Name: "is_primary", Value: true}), "")
	}
	cl.token = acc().First("SELECT * FROM erp_tokens WHERE is_primary ORDER BY id DESC LIMIT 1")
	return cl.token
}

type erpQuery struct {
	Filter  string
	Select  []string
	Expand  []string
	Top     int
	Skip    int
	OrderBy string
}

type erpPage struct {
	status int
	rows   []*config.Map
	err    string // "" = none
}

// page reads one page of an endpoint (every request asks newest first, "Id desc", unless told otherwise).
func (cl *erpClient) page(endpointKey string, q erpQuery) erpPage {
	type kv struct{ k, v string }
	var params []kv
	if q.Filter != "" {
		params = append(params, kv{"$filter", q.Filter})
	}
	if len(q.Select) > 0 {
		sel := make([]string, len(q.Select))
		for i, s := range q.Select {
			sel[i] = strings.ReplaceAll(s, ".", "/")
		}
		params = append(params, kv{"$select", strings.Join(sel, ",")})
	}
	if len(q.Expand) > 0 {
		params = append(params, kv{"$expand", strings.Join(q.Expand, ",")})
	}
	ob := q.OrderBy
	if ob == "" {
		ob = "Id desc"
	}
	params = append(params, kv{"$orderby", ob})
	top := q.Top
	if top == 0 {
		top = 1000
	}
	params = append(params, kv{"$top", php.Str(top)}, kv{"$skip", php.Str(q.Skip)})
	parts := make([]string, len(params))
	for i, p := range params { // http_build_query(…, PHP_QUERY_RFC3986)
		parts[i] = rfc3986(p.k) + "=" + rfc3986(p.v)
	}
	path := erpEndpoint(endpointKey) + "?" + strings.Join(parts, "&")

	var r erpResp
	for try := 0; try < 2; try++ {
		s := cl.session(try > 0)
		r = cl.send("GET", path, nil, true, s.Str("session_id"))
		if r.status == 401 && try == 0 {
			continue
		}
		break
	}
	if r.status < 200 || r.status >= 300 {
		e := r.err
		if e == "" {
			e = "HTTP " + php.Str(r.status) + ": " + php.Substr(strings.TrimSpace(stripTags(r.body)), 0, 200)
		}
		return erpPage{status: r.status, err: e}
	}
	v, err := decodeOrdered([]byte(r.body))
	var list []any
	switch x := v.(type) {
	case []any:
		list = x
	case *config.Map:
		list, _ = mget(x, "value").([]any)
	default:
		return erpPage{status: r.status, err: "The answer is not JSON."}
	}
	if err != nil {
		return erpPage{status: r.status, err: "The answer is not JSON."}
	}
	rows := []*config.Map{}
	for _, e := range list {
		if m, ok := e.(*config.Map); ok {
			rows = append(rows, m)
		}
	}
	return erpPage{status: r.status, rows: rows}
}

func rfc3986(s string) string { return strings.ReplaceAll(url.QueryEscape(s), "+", "%20") }

var tagRe = regexp.MustCompile(`<[^>]*>`)

func stripTags(s string) string { return tagRe.ReplaceAllString(s, "") }

type erpResp struct {
	status int
	body   string
	err    string
}

func (cl *erpClient) send(method, path string, body *string, withSession bool, sessionID string) erpResp {
	verify := connSetting("verify_tls") == "1"
	timeout := php.Int(connSetting("timeout"))
	if timeout == 0 {
		timeout = 120
	}
	timeout = max(10, timeout)
	client := &http.Client{
		Timeout:   time.Duration(timeout) * time.Second,
		Transport: &http.Transport{TLSClientConfig: &tls.Config{InsecureSkipVerify: !verify}, Proxy: http.ProxyFromEnvironment, ResponseHeaderTimeout: time.Duration(timeout) * time.Second},
	}
	var rd io.Reader
	if body != nil {
		rd = strings.NewReader(*body)
	}
	t0 := time.Now()
	req, err := http.NewRequest(method, erpBaseURL()+path, rd)
	var out erpResp
	var setCookies []string
	if err == nil {
		req.Header.Set("Accept", "application/json")
		if body != nil {
			req.Header.Set("Content-Type", "application/json")
		}
		if withSession && sessionID != "" {
			req.Header.Set("X-Monitor-SessionId", sessionID)
		}
		if cl.cookies.Len() > 0 {
			var cs []string
			for _, k := range cl.cookies.Keys {
				cs = append(cs, k+"="+php.Str(cl.cookies.Vals[k]))
			}
			req.Header.Set("Cookie", strings.Join(cs, "; "))
		}
		var resp *http.Response
		resp, err = client.Do(req)
		if err == nil {
			raw, rerr := io.ReadAll(resp.Body)
			resp.Body.Close()
			out.status = resp.StatusCode
			out.body = string(raw)
			setCookies = resp.Header.Values("Set-Cookie")
			if rerr != nil {
				err = rerr
			}
		}
	}
	if err != nil {
		out.err = err.Error()
		out.body = ""
	}
	ms := int(time.Since(t0).Milliseconds())
	shown := path
	if i := strings.IndexByte(path, '?'); i >= 0 {
		q, uerr := url.PathUnescape(path[i+1:])
		if uerr != nil {
			q = path[i+1:]
		}
		shown = path[:i] + "?" + q
	}
	ctx := om("status", out.status, "ms", ms, "bytes", len(out.body))
	switch {
	case out.err != "":
		logError("erp", method+" "+shown+" failed: "+out.err, ctx)
	case out.status >= 400:
		ctx.Set("body", php.Substr(strings.TrimSpace(stripTags(out.body)), 0, 400))
		logError("erp", method+" "+shown+" → HTTP "+php.Str(out.status), ctx)
	default:
		logInfo("erp", method+" "+shown+" → HTTP "+php.Str(out.status), ctx)
	}
	if len(setCookies) > 0 { // a new answer replaces the old cookies, like the old client did
		cl.cookies = config.NewMap()
		for _, raw := range setCookies {
			first := strings.SplitN(raw, ";", 2)[0]
			if k, v, ok := strings.Cut(first, "="); ok {
				cl.cookies.Set(strings.TrimSpace(k), strings.TrimSpace(v))
			}
		}
	}
	return out
}

// decodeOrdered parses JSON keeping object order and the literal text of numbers (json.Number).
func decodeOrdered(data []byte) (any, error) {
	d := json.NewDecoder(bytes.NewReader(data))
	d.UseNumber()
	v, err := decodeOrderedValue(d)
	if err != nil {
		return nil, err
	}
	return v, nil
}

func decodeOrderedValue(d *json.Decoder) (any, error) {
	t, err := d.Token()
	if err != nil {
		return nil, err
	}
	if dl, ok := t.(json.Delim); ok {
		switch dl {
		case '{':
			m := config.NewMap()
			for d.More() {
				kt, err := d.Token()
				if err != nil {
					return nil, err
				}
				v, err := decodeOrderedValue(d)
				if err != nil {
					return nil, err
				}
				m.Set(kt.(string), v)
			}
			_, err := d.Token()
			return m, err
		case '[':
			list := []any{}
			for d.More() {
				v, err := decodeOrderedValue(d)
				if err != nil {
					return nil, err
				}
				list = append(list, v)
			}
			_, err := d.Token()
			return list, err
		}
	}
	return t, nil
}

var _ = sort.Strings
