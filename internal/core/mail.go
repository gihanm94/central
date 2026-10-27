package core

import (
	"regexp"
	"strings"

	"acme/internal/app"
	"acme/internal/google"
	"acme/internal/php"
)

// Your own Gmail inside the system (MailController): read, search, reply, send. Mail stays at Google; nothing is copied here.

func init() {
	signedIn(func(r app.Router) {
		r.Get("/mail", mailIndex)
		r.Post("/mail/send", mailSend)
		r.Get("/mail/{mid}", mailShow)
	})
}

var mailLabels = [][2]string{{"INBOX", "Inbox"}, {"SENT", "Sent"}, {"STARRED", "Starred"}, {"DRAFT", "Drafts"}}

var fromRe = regexp.MustCompile(`^(.*?)\s*<([^>]+)>$`)

func mailIndex(c *app.Ctx) {
	u := c.MustUser()
	connected := google.Connected(u.ID)
	label := "INBOX"
	for _, l := range mailLabels {
		if c.Query("label") == l[0] {
			label = l[0]
		}
	}
	q := c.Query("q", "")
	data := google.Inbox{Rows: []app.M{}}
	var mailErr any
	if connected {
		if err := app.Catch(func() { data = google.MailInbox(c, u.ID, label, q, c.Query("t"), 15) }); err != nil {
			mailErr = err.Error()
		}
		connected = google.Connected(u.ID) // Google may have ended it just now: show that, not an error
	}
	conn := google.Connection(u.ID)
	var lost any
	if !connected && conn != nil {
		lost = conn["last_error"]
	}
	page := max(1, php.Int(c.Query("p", "1")))
	// the list, ready to show
	today := php.Today()
	type row struct {
		ID, Name, Subject, Snippet, Date string
		Unread                           bool
	}
	var rows []row
	for _, m := range data.Rows {
		from := php.Str(m["from"])
		name := ""
		if f := fromRe.FindStringSubmatch(from); f != nil {
			name = strings.Trim(f[1], "\" ")
			if name == "" {
				name = f[2]
			}
		} else {
			name = from
		}
		d := ""
		if !php.Empty(m["date"]) {
			if php.DateOf("Y-m-d", m["date"]) == today {
				d = php.DateOf("H:i", m["date"])
			} else {
				d = php.DateOf("d M", m["date"])
			}
		}
		subject := php.Str(m["subject"])
		if subject == "" {
			subject = "(" + c.T("no subject") + ")"
		}
		rows = append(rows, row{ID: php.Str(m["id"]), Name: name, Subject: subject, Snippet: php.Str(m["snippet"]), Date: d, Unread: php.Bool(m["unread"])})
	}
	labelLinks := [][3]any{}
	for _, l := range mailLabels {
		href := c.URL("/mail")
		if l[0] != "INBOX" {
			href = c.URL("/mail", "label", l[0])
		}
		labelLinks = append(labelLinks, [3]any{href, c.T(l[1]), l[0] == label})
	}
	older := ""
	if data.Next != "" || page > 1 {
		lb := ""
		if label != "INBOX" {
			lb = label
		}
		older = c.URL("/mail", "label", lb, "q", q, "t", data.Next, "p", page+1)
	}
	email := ""
	if conn != nil {
		email = conn.Str("google_email")
	}
	c.View("mail/index", app.M{"title": c.T("Mail"), "connected": connected, "configured": google.Configured(), "label": label, "labels": labelLinks, "q": q,
		"rows": rows, "next": data.Next, "estimate": data.Estimate, "error": mailErr, "email": email, "lost": lost, "page": page, "older": older,
		"reply": nil})
}

func mailShow(c *app.Ctx) {
	u := c.MustUser()
	if !google.Connected(u.ID) {
		c.Redirect("/mail")
	}
	var m app.M
	if err := app.Catch(func() { m = google.MailMessage(c, u.ID, c.Param("mid")) }); err != nil {
		c.Flash("error", err.Error())
		c.Redirect("/mail")
	}
	images := c.Query("images") == "1"
	htm := php.Str(m["html"])
	var body string
	if htm != "" {
		body = google.SafeHTML(htm, images)
	} else {
		body = `<pre style="white-space:pre-wrap;font:14px/1.5 system-ui,sans-serif">` + app.E(m["text"]) + `</pre>`
	}
	img := "data:"
	if images {
		img = "* data:"
	}
	doc := `<!doctype html><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src ` + img + `; style-src 'unsafe-inline'"><base target="_blank"><style>body{font:14px/1.55 system-ui,sans-serif;color:#1b1c1f;margin:12px;word-wrap:break-word}img{max-width:100%;height:auto}blockquote{border-left:3px solid #ddd;margin:8px 0;padding-left:12px;color:#555}</style>` + body
	subject := php.Str(m["subject"])
	title := subject
	if title == "" {
		title = c.T("Mail")
	}
	from := php.Str(m["from"])
	replyTo := from
	if f := regexp.MustCompile(`<([^>]+)>`).FindStringSubmatch(from); f != nil {
		replyTo = f[1]
	}
	reSubject := subject
	if !regexp.MustCompile(`(?i)^re:`).MatchString(subject) {
		reSubject = "Re: " + subject
	}
	date := ""
	if !php.Empty(m["date"]) {
		date = php.DateOf("d M Y, H:i", m["date"])
	}
	if subject == "" {
		subject = "(" + c.T("no subject") + ")"
	}
	c.View("mail/show", app.M{"title": title, "m": m, "subject": subject, "date": date, "doc": doc,
		"hasImages": htm != "" && regexp.MustCompile(`(?i)<img\b`).MatchString(htm) && !images,
		"reply": app.M{"to": replyTo, "subject": reSubject, "thread": m["thread"], "in_reply_to": m["message_id"], "references": m["references"]}})
}

var emailInBrackets = regexp.MustCompile(`<[^>]+@[^>]+>`)

func mailSend(c *app.Ctx) {
	u := c.MustUser()
	to := c.Input("to")
	subject := c.Input("subject")
	body := php.Str(c.InputAny("body"))
	first := strings.TrimSpace(strings.Split(to, ",")[0])
	if !validEmail(first) && !emailInBrackets.MatchString(to) {
		c.Flash("error", c.T("Enter a valid e-mail address."))
		c.Back("", "")
	}
	if err := app.Catch(func() {
		google.MailSend(c, u.ID, to, subject, body, php.Str(c.InputAny("thread")), php.Str(c.InputAny("in_reply_to")), php.Str(c.InputAny("references")), c.Input("cc"))
		c.Flash("success", c.T("Message sent."))
	}); err != nil {
		c.Flash("error", err.Error())
		c.Back("", "")
	}
	c.Redirect("/mail?label=SENT")
}

// validEmail is close to FILTER_VALIDATE_EMAIL: a bare address, no display name.
var bareEmail = regexp.MustCompile(`^[A-Za-z0-9.!#$%&'*+/=?^_` + "`" + `{|}~-]+@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$`)

func validEmail(s string) bool { return bareEmail.MatchString(s) }
