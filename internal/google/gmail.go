package google

import (
	"encoding/base64"
	"html"
	"net/mail"
	"regexp"
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/conv"
)

// The signed-in person's Gmail: list, read, send (Gmail).

const gmailBase = "/gmail/v1/users/me"

func gmailOK(st int, body map[string]any) map[string]any {
	if st >= 400 {
		Fail(ErrorMessage(body, "Gmail error") + " (" + conv.Str(st) + ")")
	}
	return body
}

// Inbox is one page of a label: rows, next page token ("" = none) and the estimate.
type Inbox struct {
	Rows     []app.M
	Next     string
	Estimate int
}

// MailInbox lists messages (per page).
func MailInbox(c *app.Ctx, userID int, label, q, page string, per int) Inbox {
	list := gmailOK(API(c, userID, "GET", gmailBase+"/messages", Query{{"labelIds", label}, {"q", q}, {"maxResults", per}, {"pageToken", page}}.Filter(), nil))
	rows := []app.M{}
	for _, it := range List(list["messages"]) {
		m := Map(it)
		x := gmailOK(API(c, userID, "GET", gmailBase+"/messages/"+RawURLEncode(conv.Str(m["id"])), Query{{"format", "metadata"}, {"metadataHeaders", []string{"From", "Subject", "Date"}}}, nil))
		h := headers(Map(x["payload"])["headers"])
		rows = append(rows, app.M{"id": conv.Str(x["id"]), "thread": conv.Str(x["threadId"]), "from": h["from"], "subject": h["subject"], "date": gmailDate(h["date"], x["internalDate"]),
			"snippet": html.UnescapeString(conv.Str(x["snippet"])), "unread": hasLabel(x, "UNREAD"), "attachments": false})
	}
	est := len(rows)
	if v, ok := list["resultSizeEstimate"]; ok && v != nil {
		est = conv.Int(v)
	}
	return Inbox{Rows: rows, Next: conv.Str(list["nextPageToken"]), Estimate: est}
}

func hasLabel(x map[string]any, l string) bool {
	for _, v := range List(x["labelIds"]) {
		if conv.Str(v) == l {
			return true
		}
	}
	return false
}

// MailMessage is one message: headers, text and html bodies, attachments (names only).
func MailMessage(c *app.Ctx, userID int, id string) app.M {
	x := gmailOK(API(c, userID, "GET", gmailBase+"/messages/"+RawURLEncode(id), Query{{"format", "full"}}, nil))
	payload := Map(x["payload"])
	h := headers(payload["headers"])
	var text, htm string
	files := []app.M{}
	walk(payload, &text, &htm, &files)
	return app.M{"id": conv.Str(x["id"]), "thread": conv.Str(x["threadId"]), "from": h["from"], "to": h["to"], "cc": h["cc"], "subject": h["subject"],
		"date": gmailDate(h["date"], x["internalDate"]), "message_id": h["message-id"], "references": h["references"], "text": text, "html": htm,
		"files": files, "unread": hasLabel(x, "UNREAD")}
}

// MailSend sends a plain-text message (a reply when threadID / inReplyTo are given).
func MailSend(c *app.Ctx, userID int, to, subject, body, threadID, inReplyTo, references, cc string) {
	from := ""
	if conn := Connection(userID); conn != nil {
		from = conn.Str("google_email")
	}
	head := []string{"To: " + clean(to), "Subject: =?UTF-8?B?" + base64.StdEncoding.EncodeToString([]byte(clean(subject))) + "?=", "MIME-Version: 1.0", "Content-Type: text/plain; charset=UTF-8", "Content-Transfer-Encoding: base64"}
	if from != "" {
		head = append([]string{"From: " + from}, head...)
	}
	if cc != "" {
		head = append(head, "Cc: "+clean(cc))
	}
	if inReplyTo != "" {
		head = append(head, "In-Reply-To: "+clean(inReplyTo), "References: "+clean(strings.TrimSpace(references+" "+inReplyTo)))
	}
	raw := strings.Join(head, "\r\n") + "\r\n\r\n" + chunkSplit(base64.StdEncoding.EncodeToString([]byte(body)))
	enc := strings.TrimRight(strings.NewReplacer("+", "-", "/", "_").Replace(base64.StdEncoding.EncodeToString([]byte(raw))), "=")
	msg := map[string]any{"raw": enc}
	if threadID != "" {
		msg["threadId"] = threadID
	}
	gmailOK(API(c, userID, "POST", gmailBase+"/messages/send", nil, msg))
}

// chunkSplit is chunk_split($s): 76 characters per line, each ended by \r\n.
func chunkSplit(s string) string {
	var b strings.Builder
	for len(s) > 76 {
		b.WriteString(s[:76] + "\r\n")
		s = s[76:]
	}
	b.WriteString(s + "\r\n")
	return b.String()
}

var nlRe = regexp.MustCompile(`[\r\n]+`)

func clean(v string) string { return strings.TrimSpace(nlRe.ReplaceAllString(v, " ")) }

func headers(list any) map[string]string {
	out := map[string]string{}
	for _, it := range List(list) {
		h := Map(it)
		out[strings.ToLower(conv.Str(h["name"]))] = conv.Str(h["value"])
	}
	return out
}

// gmailDate: ISO date of a message (nil when unknown).
func gmailDate(header string, internal any) any {
	if !conv.Empty(internal) {
		return conv.Date("c", conv.Unix(int64(conv.Int(internal)/1000)))
	}
	if header != "" {
		if t, err := mail.ParseDate(header); err == nil {
			return conv.Date("c", t.In(conv.Loc))
		}
		if ts := conv.StrToTime(header); ts != 0 {
			return conv.Date("c", time.Unix(ts, 0))
		}
	}
	return nil
}

func b64url(s string) string {
	b, err := base64.StdEncoding.DecodeString(padB64(strings.NewReplacer("-", "+", "_", "/").Replace(s)))
	if err != nil {
		b, _ = base64.RawStdEncoding.DecodeString(strings.TrimRight(strings.NewReplacer("-", "+", "_", "/").Replace(s), "="))
	}
	return string(b)
}

func padB64(s string) string {
	if m := len(s) % 4; m != 0 {
		s += strings.Repeat("=", 4-m)
	}
	return s
}

func walk(p map[string]any, text, htm *string, files *[]app.M) {
	mime := conv.Str(p["mimeType"])
	body := Map(p["body"])
	data := conv.Str(body["data"])
	switch {
	case !conv.Empty(p["filename"]) && !conv.Empty(body["attachmentId"]):
		*files = append(*files, app.M{"name": conv.Str(p["filename"]), "size": conv.Int(body["size"]), "type": mime})
	case data != "" && mime == "text/plain" && *text == "":
		*text = b64url(data)
	case data != "" && mime == "text/html" && *htm == "":
		*htm = b64url(data)
	}
	for _, part := range List(p["parts"]) {
		walk(Map(part), text, htm, files)
	}
}

var (
	activeTags   = []string{"script", "iframe", "object", "embed", "form", "meta", "link", "base", "style"}
	activePairRe []*regexp.Regexp
	activeOneRe  = regexp.MustCompile(`(?is)<(script|iframe|object|embed|form|meta|link|base|input|button)\b[^>]*/?>`)
	onAttrRe     = regexp.MustCompile(`(?i)\son\w+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)`)
	jsURLRe      = regexp.MustCompile(`(?i)(href|src|action|background)\s*=\s*(["']?)\s*(javascript|vbscript|data:text/html)[^"'>\s]*`)
	anchorRe     = regexp.MustCompile(`(?i)<a\s`)
	imgRe        = regexp.MustCompile(`(?i)<img\b[^>]*>`)
)

func init() {
	for _, t := range activeTags {
		activePairRe = append(activePairRe, regexp.MustCompile(`(?is)<`+t+`\b[^>]*>.*?</`+t+`>`))
	}
}

// SafeHTML removes everything active from e-mail HTML: scripts, frames, forms, event handlers, javascript: links;
// remote images unless asked for.
func SafeHTML(h string, images bool) string {
	// PHP removed <tag …>…</tag> pairs in one pass from left to right; one pattern per tag gives the same result
	// for the usual mail (pairs are not nested inside each other).
	for _, re := range activePairRe {
		h = re.ReplaceAllString(h, "")
	}
	h = activeOneRe.ReplaceAllString(h, "")
	h = onAttrRe.ReplaceAllString(h, "")
	h = jsURLRe.ReplaceAllString(h, "${1}=${2}#")
	h = anchorRe.ReplaceAllString(h, `<a target="_blank" rel="noopener noreferrer" `)
	if !images {
		h = imgRe.ReplaceAllString(h, "")
	}
	return h
}
