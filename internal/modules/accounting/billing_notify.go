package accounting

import (
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/conv"
	"acme/internal/db"
)

// BillingNotify: one Lark message a day to the chosen people, every billing note whose remind date is today.
// Settings (Accounting → Notifications): on/off, time of day, who. Sent by the scheduler tick (cron or the website), once per day.

func notifyEnabled() bool { return sget("notify.enabled", "0") == "1" }
func notifyTime() string  { return sget("notify.time", "08:30") }

func notifyUserIDs() []int {
	var out []int
	for _, s := range strings.Split(sget("notify.users", ""), ",") {
		if n := conv.Int(s); n != 0 {
			out = append(out, n)
		}
	}
	return out
}

// notifyToday: the notes to follow up today.
func notifyToday() []db.Row {
	return acc().Select(`SELECT b.*, (SELECT count(*) FROM billing_note_invoices i WHERE i.billing_id = b.id) AS invoice_count
                             FROM billing_notes b WHERE b.remind_date = (now() AT TIME ZONE 'Asia/Bangkok')::date AND NOT b.is_complete ORDER BY b.billing_number`)
}

func notifyMessage(c *app.Ctx, notes []db.Row) (string, []string) {
	var lines []string
	sum := 0.0
	for _, n := range notes {
		status := c.T("Pending")
		if n.Bool("is_complete") {
			status = c.T("Complete")
		} else if n.Bool("is_paid") {
			status = c.T("Paid")
		}
		lines = append(lines, "• "+n.Str("billing_number")+" · "+n.Str("customer_code")+" "+n.Str("customer_name")+" · "+billingSymbol(n.Str("currency_code"))+nf(n.Float("total_amount"), 2, ".", ",")+" · "+conv.Str(n.Int("invoice_count"))+" "+c.T("invoices")+" · "+status)
		if n.Str("currency_code") == "THB" {
			sum += n.Float("total_amount")
		}
	}
	if len(notes) > 0 {
		lines = append(lines, c.T("Total (THB)")+": ฿"+nf(sum, 2, ".", ","))
	}
	if len(lines) == 0 {
		lines = []string{c.T("No billing notes are due today.")}
	}
	return c.T("Billing notes to follow up today (:n)", "n", len(notes)) + " · " + conv.Date("d/m/Y", conv.Now()), lines
}

type notifyResult struct {
	sent   int
	failed []string
	notes  int
}

// notifySend sends now.
func notifySend(c *app.Ctx, evenIfEmpty bool) notifyResult {
	notes := notifyToday()
	out := notifyResult{notes: len(notes)}
	if len(notes) == 0 && !evenIfEmpty {
		return out
	}
	title, lines := notifyMessage(c, notes)
	url := c.URL("/accounting/billing")
	switch app.LarkMode() {
	case "webhook":
		if app.LarkWebhook(c, title, lines, url) {
			out.sent++
		} else {
			out.failed = append(out.failed, "webhook")
		}
	case "app":
		ids := notifyUserIDs()
		var users []db.Row
		if len(ids) > 0 {
			args := make([]any, len(ids))
			for i, id := range ids {
				args[i] = id
			}
			users = db.Select("SELECT id, name, email FROM users WHERE id IN ("+strings.TrimSuffix(strings.Repeat("?,", len(ids)), ",")+") AND is_active AND deleted_at IS NULL", args...)
		}
		for _, u := range users {
			if app.LarkDirect(c, u.Str("email"), title, lines, url) {
				out.sent++
			} else {
				out.failed = append(out.failed, u.Str("email"))
			}
		}
		if len(users) == 0 {
			out.failed = append(out.failed, c.T("no recipients chosen"))
		}
	default:
		out.failed = append(out.failed, c.T("Lark is switched off."))
	}
	fl := []any{}
	for _, f := range out.failed {
		fl = append(fl, f)
	}
	logInfo("billing", "Lark daily list: "+conv.Str(out.notes)+" note(s), sent "+conv.Str(out.sent), om("failed", fl))
	return out
}

// notifyTick: called every minute by the scheduler: once a day, after the chosen time.
func notifyTick(c *app.Ctx) {
	if !notifyEnabled() {
		return
	}
	loc, err := time.LoadLocation("Asia/Bangkok")
	if err != nil {
		loc = conv.Loc
	}
	now := time.Now().In(loc)
	if now.Format("15:04") < notifyTime() || sget("notify.last", "") == now.Format("2006-01-02") {
		return
	}
	sput("notify.last", now.Format("2006-01-02"), 0) // claim the day first: two processes must not both send
	if err := app.Catch(func() { notifySend(c, false) }); err != nil {
		logException("billing", err, "daily Lark list failed")
	}
}
