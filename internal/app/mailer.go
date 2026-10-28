package app

import (
	"bufio"
	"crypto/tls"
	"encoding/base64"
	"fmt"
	"html/template"
	"math/rand/v2"
	"net"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"acme/internal/config"
	"acme/internal/conv"
	"acme/internal/db"
)

// SendMail sends HTML e-mail (Mailer::send): driver "log" writes storage/mail/*.html, "smtp" really sends.
func SendMail(to, toName, subject, html string) bool {
	var err error
	ok := false
	if e := Catch(func() {
		if config.String("mail.driver", "log") == "smtp" {
			err = smtpSend(to, toName, subject, html)
		} else {
			err = logMail(to, subject, html)
		}
	}); e != nil {
		err = e
	}
	ok = err == nil
	errText := ""
	if err != nil {
		errText = err.Error()
		WriteErrorLog("[mail] " + errText)
	}
	recordMail(to, subject, ok, errText)
	return ok
}

func recordMail(to, subject string, ok bool, errText string) {
	_ = db.Try(func() {
		status := "sent"
		var e any
		if !ok {
			status = "failed"
			if errText == "" {
				errText = "Unknown error"
			}
			e = conv.Substr(errText, 0, 1000)
		}
		db.Insert("mail_log", db.Cols{{Name: "to_email", Value: conv.Substr(to, 0, 190)}, {Name: "subject", Value: conv.Substr(subject, 0, 250)},
			{Name: "driver", Value: config.String("mail.driver", "log")}, {Name: "status", Value: status}, {Name: "error", Value: e}}, "")
		if rand.IntN(50) == 0 {
			db.Exec("DELETE FROM mail_log WHERE id < (SELECT COALESCE(max(id), 0) - 1000 FROM mail_log)")
		}
	})
}

// MailButton is the call-to-action of a branded message.
type MailButton struct{ Label, URL string }

// Notify sends the branded message (Mailer::notify): lines are HTML (escape what you put in them).
func Notify(c *Ctx, to, toName, subject string, lines []template.HTML, button *MailButton, note string) bool {
	brand := Branding(c)
	var btn any
	if button != nil {
		btn = M{"label": button.Label, "url": button.URL}
	}
	html := RenderPartial(c, "emails/message", M{"subject": subject, "name": toName, "lines": lines, "button": btn, "note": note, "brand": brand})
	return SendMail(to, toName, "["+Setting("company_name", config.String("app.name", ""))+"] "+subject, html)
}

var nonAlnum = regexp.MustCompile(`(?i)[^a-z0-9]+`)

func logMail(to, subject, html string) error {
	dir := filepath.Join(BasePath, "storage", "mail")
	if err := os.MkdirAll(dir, 0o775); err != nil {
		return err
	}
	name := conv.Date("Ymd-His", conv.Now()) + "-" + randHex(3)[:6] + "-" + nonAlnum.ReplaceAllString(to, "-") + ".html"
	return os.WriteFile(filepath.Join(dir, name), []byte("<!-- To: "+to+"\n     Subject: "+template.HTMLEscapeString(subject)+" -->\n"+html), 0o664)
}

func smtpSend(to, toName, subject, html string) error {
	host := config.String("mail.host", "")
	port := config.Int("mail.port", 587)
	enc := config.String("mail.encryption", "tls")
	verify := config.Bool("mail.verify_tls", true)
	addr := net.JoinHostPort(host, conv.Str(port))
	tlsCfg := &tls.Config{ServerName: host, InsecureSkipVerify: !verify, MinVersion: tls.VersionTLS12}
	var conn net.Conn
	var err error
	d := &net.Dialer{Timeout: 10 * time.Second}
	if enc == "ssl" {
		conn, err = tls.DialWithDialer(d, "tcp", addr, tlsCfg)
	} else {
		conn, err = d.Dial("tcp", addr)
	}
	if err != nil {
		return fmt.Errorf("SMTP connect failed (%s:%d): %v", host, port, err)
	}
	defer conn.Close()
	_ = conn.SetDeadline(time.Now().Add(60 * time.Second))
	r := bufio.NewReader(conn)
	read := func() string {
		var data strings.Builder
		for {
			line, err := r.ReadString('\n')
			data.WriteString(line)
			if err != nil || (len(line) > 3 && line[3] == ' ') {
				break
			}
		}
		return data.String()
	}
	cmd := func(command string, expect ...int) error {
		if _, err := conn.Write([]byte(command + "\r\n")); err != nil {
			return err
		}
		res := read()
		code := conv.Int(conv.Substr(res, 0, 3))
		for _, e := range expect {
			if e == code {
				return nil
			}
		}
		return fmt.Errorf("SMTP error after \"%s\": %s", strings.SplitN(command, " ", 2)[0], strings.TrimSpace(res))
	}
	read()
	hn, _ := os.Hostname()
	if hn == "" {
		hn = "localhost"
	}
	ehlo := "EHLO " + hn
	if err := cmd(ehlo, 250); err != nil {
		return err
	}
	if enc == "tls" {
		if err := cmd("STARTTLS", 220); err != nil {
			return err
		}
		tc := tls.Client(conn, tlsCfg)
		if err := tc.Handshake(); err != nil {
			return fmt.Errorf("TLS handshake with the mail server failed (certificate not trusted? set mail.verify_tls to false in config.json only if you must)")
		}
		conn = tc
		r = bufio.NewReader(conn)
		if err := cmd(ehlo, 250); err != nil {
			return err
		}
	}
	if u := config.String("mail.username", ""); u != "" {
		if err := cmd("AUTH LOGIN", 334); err != nil {
			return err
		}
		if err := cmd(base64.StdEncoding.EncodeToString([]byte(u)), 334); err != nil {
			return err
		}
		if err := cmd(base64.StdEncoding.EncodeToString([]byte(config.String("mail.password", ""))), 235); err != nil {
			return err
		}
	}
	from := config.String("mail.from_address", "")
	if err := cmd("MAIL FROM:<"+from+">", 250); err != nil {
		return err
	}
	if err := cmd("RCPT TO:<"+to+">", 250, 251); err != nil {
		return err
	}
	if err := cmd("DATA", 354); err != nil {
		return err
	}
	b64 := func(s string) string { return "=?UTF-8?B?" + base64.StdEncoding.EncodeToString([]byte(s)) + "?=" }
	domain := "localhost"
	if i := strings.IndexByte(from, '@'); i >= 0 {
		domain = from[i+1:]
	}
	headers := []string{
		"Date: " + conv.Now().Format(time.RFC1123Z),
		"From: " + b64(config.String("mail.from_name", "")) + " <" + from + ">",
		"To: " + b64(toName) + " <" + to + ">",
		"Subject: " + b64(subject),
		"Message-ID: <" + randHex(8) + "@" + domain + ">",
		"MIME-Version: 1.0",
		"Content-Type: text/html; charset=UTF-8",
		"Content-Transfer-Encoding: base64",
	}
	enc64 := base64.StdEncoding.EncodeToString([]byte(html))
	var body strings.Builder
	for i := 0; i < len(enc64); i += 76 {
		end := min(i+76, len(enc64))
		body.WriteString(enc64[i:end] + "\r\n")
	}
	if err := cmd(strings.Join(headers, "\r\n")+"\r\n\r\n"+body.String()+"\r\n.", 250); err != nil {
		return err
	}
	return cmd("QUIT", 221)
}
