package app

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"html"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"acme/internal/config"
	"acme/internal/conv"
)

// Lark (Feishu) messages without any SDK (App\Core\Support\Lark).
//   webhook mode: one message per event into a group chat (custom bot webhook, optional signature secret)
//   app mode:     a direct message to each person, found by their work e-mail

func LarkMode() string { return conv.OrStr(Setting("lark_mode", ""), "off") }

// LarkWebhook posts into the group chat.
func LarkWebhook(c *Ctx, title string, lines []string, url string) bool {
	hook := Setting("lark_webhook_url", "")
	if hook == "" {
		return false
	}
	payload := M{"msg_type": "post", "content": M{"post": M{"en_us": larkPost(c, title, lines, url)}}}
	if secret := Setting("lark_webhook_secret", ""); secret != "" {
		ts := strconv.FormatInt(time.Now().Unix(), 10)
		payload["timestamp"] = ts
		m := hmac.New(sha256.New, []byte(ts+"\n"+secret))
		payload["sign"] = base64.StdEncoding.EncodeToString(m.Sum(nil))
	}
	res := larkHTTP(hook, payload, "")
	code, ok := res["code"]
	if !ok {
		code, ok = res["StatusCode"]
	}
	return ok && conv.Int(code) == 0
}

// LarkDirect sends a direct message to a person by work e-mail.
func LarkDirect(c *Ctx, email, title string, lines []string, url string) bool {
	token := larkToken()
	if token == "" {
		return false
	}
	content, _ := json.Marshal(M{"en_us": larkPost(c, title, lines, url)})
	res := larkHTTP(larkDomain()+"/open-apis/im/v1/messages?receive_id_type=email", M{"receive_id": email, "msg_type": "post", "content": string(content)}, token)
	code, ok := res["code"]
	return ok && conv.Int(code) == 0
}

// LarkTest sends a test message: ok and what to say.
func LarkTest(c *Ctx, email string) (bool, string) {
	title := c.T("Test message from :app", "app", Setting("company_name", config.String("app.name", "")))
	lines := []string{c.T("If you can read this, Lark notifications work.")}
	switch LarkMode() {
	case "webhook":
		if LarkWebhook(c, title, lines, "") {
			return true, c.T("Test message posted to the Lark group.")
		}
		return false, c.T("Lark did not accept the message. Check the webhook address and secret.")
	case "app":
		if LarkDirect(c, email, title, lines, "") {
			return true, c.T("Test message sent to you on Lark.")
		}
		return false, c.T("Lark did not accept the message. Check the app ID, secret and that your Lark e-mail matches.")
	}
	return false, c.T("Lark is switched off.")
}

func larkDomain() string {
	return strings.TrimRight(conv.OrStr(Setting("lark_domain", ""), "https://open.larksuite.com"), "/")
}

func larkToken() string {
	cache := filepath.Join(BasePath, "storage", "cache", "lark_token.json")
	var saved struct {
		Token   string `json:"token"`
		Expires int64  `json:"expires"`
		App     string `json:"app"`
	}
	if raw, err := os.ReadFile(cache); err == nil {
		_ = json.Unmarshal(raw, &saved)
		if saved.Token != "" && saved.Expires > time.Now().Unix()+60 && saved.App == Setting("lark_app_id", "") {
			return saved.Token
		}
	}
	res := larkHTTP(larkDomain()+"/open-apis/auth/v3/tenant_access_token/internal", M{"app_id": Setting("lark_app_id", ""), "app_secret": Setting("lark_app_secret", "")}, "")
	tok := conv.Str(res["tenant_access_token"])
	if conv.Int(conv.Coalesce(res["code"], -1)) != 0 || tok == "" {
		b, _ := json.Marshal(res)
		WriteErrorLog("[lark] token failed: " + string(b))
		return ""
	}
	exp := conv.Int(conv.Coalesce(res["expire"], 3600))
	b, _ := json.Marshal(M{"token": tok, "expires": time.Now().Unix() + int64(exp), "app": Setting("lark_app_id", "")})
	_ = os.WriteFile(cache, b, 0o664)
	return tok
}

func larkPost(c *Ctx, title string, lines []string, url string) M {
	content := []any{}
	for _, l := range lines {
		content = append(content, []any{M{"tag": "text", "text": html.UnescapeString(stripTags(l))}})
	}
	if url != "" {
		content = append(content, []any{M{"tag": "a", "text": c.T("Open"), "href": url}})
	}
	return M{"title": title, "content": content}
}

func larkHTTP(url string, body M, bearer string) M {
	out := M{}
	b, _ := json.Marshal(body)
	req, err := http.NewRequest(http.MethodPost, url, bytes.NewReader(b))
	if err != nil {
		WriteErrorLog("[lark] " + err.Error())
		return out
	}
	req.Header.Set("Content-Type", "application/json; charset=utf-8")
	if bearer != "" {
		req.Header.Set("Authorization", "Bearer "+bearer)
	}
	resp, err := (&http.Client{Timeout: 6 * time.Second}).Do(req)
	if err != nil {
		WriteErrorLog("[lark] " + err.Error())
		return out
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	_ = json.Unmarshal(raw, &out)
	code, ok := out["code"]
	if !ok {
		code = out["StatusCode"]
	}
	if conv.Int(code) != 0 {
		WriteErrorLog("[lark] " + url + " → " + string(raw))
	}
	return out
}
