package accounting

import (
	"errors"
	"math"
	"os"
	"regexp"
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/php"
)

// ---------------------------------------------------------------- AccountingController (overview)

func overviewPage(c *app.Ctx) {
	show := app.M{"finance": c.Can("accounting_invoices", "view"), "orders": c.Can("accounting_orders", "view"), "inet": c.Can("accounting_inet", "view"), "billing": c.Can("accounting_billing", "view")}
	anyShown := false
	for _, v := range show {
		if v.(bool) {
			anyShown = true
		}
	}
	data := app.M{}
	if anyShown {
		data = dashboardGet(c.Query("refresh") == "1" && c.MustUser().IsAdmin())
	}
	f := func(v any) string { return php.NF(v, 0) }
	delta := func(now, then float64) (string, string) {
		if then == 0 {
			return "", "neutral"
		}
		p := (now - then) / math.Abs(then) * 100
		arrow, tone := "▼ ", "down"
		if p >= 0 {
			arrow, tone = "▲ ", "up"
		}
		return arrow + php.NF(math.Abs(p), 1) + "%", tone
	}
	type card struct{ Label, Value, Sub, Tone string }
	var cards []card
	kpi, _ := data["kpi"].(app.M)
	kf := func(k string) float64 { return php.Float(kpi[k]) }
	if show["finance"].(bool) && len(data) > 0 {
		t1, c1 := delta(kf("month"), kf("month_prev_year"))
		t2, c2 := delta(kf("ytd"), kf("ytd_prev"))
		s1, s2 := c.T("no data last year"), c.T("no data last year")
		if t1 != "" {
			s1 = t1 + " " + c.T("vs same month last year")
		}
		if t2 != "" {
			s2 = t2 + " " + c.T("vs last year to date")
		}
		cards = append(cards, card{c.T("Revenue this month"), "฿" + f(kpi["month"]), s1, c1}, card{c.T("Revenue this year"), "฿" + f(kpi["ytd"]), s2, c2},
			card{c.T("Receivable outstanding"), "฿" + f(data["receivable"]), c.T("open invoices not paid yet"), "neutral"})
	}
	if show["orders"].(bool) && len(data) > 0 {
		cards = append(cards, card{c.T("Orders this month"), php.Str(data["orders_this_month"]), php.Str(php.Coalesce(kpi["invoices_month"], 0)) + " " + c.T("invoices this month"), "neutral"})
	}
	if show["inet"].(bool) && len(data) > 0 {
		in, _ := data["inet"].(app.M)
		w := php.Int(in["waiting"])
		t := "neutral"
		if w > 0 {
			t = "down"
		}
		cards = append(cards, card{c.T("E-tax waiting to send"), php.Str(w), php.Str(php.Int(data["not_in_inet"])) + " " + c.T("invoices (60 days) not generated yet"), t})
	}
	if show["billing"].(bool) && len(data) > 0 {
		cards = append(cards, card{c.T("Billing notes today"), php.Str(data["billing_today"]), "฿" + f(data["billing_open"]) + " " + c.T("billed, not paid"), "neutral"})
	}
	var payload string
	built := ""
	if len(data) > 0 {
		d2 := app.M{}
		for k, v := range data {
			d2[k] = v
		}
		months := []any{}
		for _, m := range []string{"Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"} {
			months = append(months, c.T(m))
		}
		d2["labels"] = app.M{"month": c.T("This year"), "prev": c.T("Last year"), "orders": c.T("Orders"), "amount": c.T("Amount"), "notdue": c.T("Not due"), "days": c.T("days"),
			"inet": []any{c.T("Signed by INET"), c.T("Waiting at INET"), c.T("Generated, not sent"), c.T("Not generated")},
			"bill": app.M{"pending": c.T("Pending"), "overdue": c.T("Overdue"), "paid": c.T("Paid"), "complete": c.T("Complete")}, "months": months}
		payload = jsonEnc(d2, false)
		payload = strings.NewReplacer("<", `<`, ">", `>`, "&", `&`).Replace(payload)
		built = php.Date("H:i", time.Unix(php.StrToTime(data["built"]), 0).In(php.Loc))
	}
	c.View("accounting/index", app.M{"title": c.T("Accounting overview"), "show": show, "has": len(data) > 0, "cards": cards, "payload": payloadJS(payload), "built": built, "isAdmin": c.MustUser().IsAdmin()})
}

// ---------------------------------------------------------------- CompanyController

func adminGate(c *app.Ctx, msg string) {
	if !c.MustUser().IsAdmin() {
		app.Abort(403, c.T(msg))
	}
}

func companyEdit(c *app.Ctx) {
	adminGate(c, "Only an administrator can open this.")
	co := companyPrimary()
	if co == nil {
		co = db.Row{}
	}
	var fields []app.M
	for _, f := range companyFields {
		val := co.Str(f[0])
		if f[0] == "branch_id" && co[f[0]] == nil {
			val = "00000"
		}
		fields = append(fields, app.M{"key": f[0], "label": c.T(f[1]), "wide": inStrs(f[0], []string{"address_th", "address_en", "tax_info", "footer_text_th", "footer_text_en", "payment_note_th", "payment_note_en", "receipt_note_th", "receipt_note_en", "credit_note_th", "credit_note_en"}),
			"secret": f[0] == "access_key" || f[0] == "api_key", "required": f[0] == "tax_id" || f[0] == "name_th", "value": val})
	}
	chromiumSet := sget("pdf.chromium", "")
	c.View("accounting/company", app.M{"title": c.T("Company"), "fields": fields, "chromium": chromium(), "chromiumSet": chromiumSet, "scheme": sget("inet.auth_scheme", "Bearer")})
}

func companySaveAction(c *app.Ctx) {
	adminGate(c, "Only an administrator can open this.")
	all := c.All()
	in := func(k string) string { return strings.TrimSpace(php.Str(mget(all, k))) }
	tax := digits(in("tax_id"))
	if len(tax) != 13 {
		app.Invalid("tax_id", c.T("The tax ID has 13 digits."))
	}
	if in("name_th") == "" {
		app.Invalid("name_th", c.T("Enter the company name."))
	}
	branch := php.Str(php.Coalesce(mget(all, "branch_id"), "00000"))
	if !regexp.MustCompile(`^\d{5}$`).MatchString(branch) {
		app.Invalid("branch_id", c.T("The branch ID has 5 digits, e.g. 00000."))
	}
	companySave(func(k string) string {
		if k == "tax_id" {
			return tax
		}
		return in(k)
	})
	chrome := in("chromium")
	if chrome != "" {
		if st, err := os.Stat(chrome); err != nil || !st.Mode().IsRegular() || st.Mode()&0o111 == 0 {
			app.Invalid("chromium", c.T("That program was not found."))
		}
	}
	sput("pdf.chromium", chrome, c.UserID())
	scheme := php.OrStr(php.Str(mget(all, "scheme")), "Bearer")
	if scheme != "Bearer" && scheme != "Basic" {
		scheme = "Bearer"
	}
	sput("inet.auth_scheme", scheme, c.UserID())
	c.Flash("success", c.T("Company saved."))
	c.Redirect("/accounting/company")
}

// ---------------------------------------------------------------- NotifyController

func notifyEdit(c *app.Ctx) {
	adminGate(c, "Only an administrator can open this.")
	picked := map[int]bool{}
	for _, id := range notifyUserIDs() {
		picked[id] = true
	}
	var users []app.M
	for _, u := range db.Select("SELECT id, name, email FROM users WHERE is_active AND deleted_at IS NULL ORDER BY name") {
		users = append(users, app.M{"id": u.Int("id"), "name": u.Str("name"), "email": u.Str("email"), "checked": picked[u.Int("id")]})
	}
	var today []app.M
	for _, n := range notifyToday() {
		today = append(today, app.M{"billing_number": n.Str("billing_number"), "total": php.NF(n["total_amount"], 2), "customer_name": n.Str("customer_name")})
	}
	last := sgetNil("notify.last")
	c.View("accounting/notify", app.M{"title": c.T("Notifications"), "enabled": notifyEnabled(), "time": notifyTime(), "mode": app.LarkMode(), "users": users, "today": today, "last": last})
}

func notifySave(c *app.Ctx) {
	adminGate(c, "Only an administrator can open this.")
	u := c.UserID()
	t := c.Input("time", "08:30")
	on := "0"
	if c.Bool("enabled") {
		on = "1"
	}
	sput("notify.enabled", on, u)
	if regexp.MustCompile(`^([01]\d|2[0-3]):[0-5]\d$`).MatchString(t) {
		sput("notify.time", t, u)
	} else {
		sput("notify.time", "08:30", u)
	}
	var ids []string
	for _, s := range c.Inputs("users") {
		ids = append(ids, php.Str(php.Int(s)))
	}
	sput("notify.users", strings.Join(ids, ","), u)
	c.Flash("success", c.T("Notification settings saved."))
	c.Redirect("/accounting/notify")
}

// notifySendNow sends today's list now (also when it is empty, so the connection can be tested).
func notifySendNow(c *app.Ctx) {
	adminGate(c, "Only an administrator can open this.")
	r := notifySend(c, true)
	if r.sent > 0 {
		msg := c.T("Sent to :n recipient(s) on Lark.", "n", r.sent)
		if len(r.failed) > 0 {
			msg += " " + c.T("Failed: :x", "x", strings.Join(r.failed, ", "))
		}
		c.Flash("success", msg)
	} else {
		x := strings.Join(r.failed, ", ")
		if x == "" {
			x = c.T("unknown")
		}
		c.Flash("error", c.T("Nothing was sent: :x", "x", x))
	}
	c.Redirect("/accounting/notify")
}

// ---------------------------------------------------------------- LogController

func logsIndex(c *app.Ctx) {
	adminGate(c, "Only an administrator can open the logs.")
	today := php.Date("Y-m-d", php.Now())
	days := logDays()
	seen := false
	for _, d := range days {
		if d.Day == today {
			seen = true
		}
	}
	var list []string
	for _, d := range days {
		list = append(list, d.Day)
	}
	if !seen {
		list = append(list, today)
	}
	c.View("accounting/logs", app.M{"title": c.T("Logs"), "days": list, "today": today, "keep": logKeepDays})
}

// logsTail: new lines after a byte offset (JSON).
func logsTail(c *app.Ctx) {
	run := c.Input("run")
	if !c.MustUser().IsAdmin() {
		if !(c.Can("accounting_inet", "create") && run != "") {
			app.Abort(403)
		}
	}
	tail := min(1000, max(10, php.Int(c.Input("tail", "300"))))
	after := int64(php.Int(c.Input("after", "-1")))
	c.JSON(logRead(c.Input("day", php.Date("Y-m-d", php.Now())), after, tail, map[string]string{"level": c.Input("level", "debug"), "ch": c.Input("ch"), "q": c.Input("q"), "run": run}))
}

func logsDownload(c *app.Ctx) {
	adminGate(c, "Only an administrator can open the logs.")
	file := logFile(c.Input("day", php.Date("Y-m-d", php.Now())))
	b, err := os.ReadFile(file)
	if err != nil {
		app.Abort(404)
	}
	c.Download("accounting-"+file[strings.LastIndex(file, "/")+1:], "text/plain; charset=utf-8", b)
}

func logsClear(c *app.Ctx) {
	adminGate(c, "Only an administrator can open the logs.")
	day := c.Input("day")
	today := php.Date("Y-m-d", php.Now())
	f := logFile(day)
	if day != "" && day != today {
		if _, err := os.Stat(f); err == nil {
			_ = os.Remove(f)
		}
	} else if day == today {
		_ = os.WriteFile(f, nil, 0o664)
	}
	c.Flash("success", c.T("Log of :day cleared.", "day", day))
	c.Back("", "")
}

// ---------------------------------------------------------------- ErpController

func erpIndex(c *app.Ctx) {
	adminGate(c, "Only an administrator can open the ERP connection.")
	state := map[string]db.Row{}
	for _, r := range acc().Select("SELECT * FROM erp_sync_state") {
		state[r.Str("entity")] = r
	}
	ents := erpEntities()
	count := map[string]int{}
	sum := 0
	for _, k := range ents.Keys {
		d, _ := ents.Vals[k].(*config.Map)
		table := php.Str(mget(mmap(cfgMap("erp_schema"), mstr(d, "entity")), "table"))
		n := php.Int(acc().Scalar("SELECT count(*) FROM " + table))
		count[k] = n
		sum += n
	}
	token := acc().First("SELECT id, session_id, session_suspended, updated_at FROM erp_tokens WHERE is_primary ORDER BY id DESC LIMIT 1")
	totals := erpTotals()
	running := php.Int(acc().Scalar("SELECT count(*) FROM erp_sync_runs WHERE status = 'running' AND started_at > now() - interval '2 hours'"))

	// the API table: one row per endpoint with the entities copied from it
	byAPI := map[string][]string{}
	for _, k := range ents.Keys {
		d, _ := ents.Vals[k].(*config.Map)
		byAPI[mstr(d, "api")] = append(byAPI[mstr(d, "api")], k)
	}
	groupTone := map[string]string{"hot": "bg-signal-50 text-signal-800", "cold": "bg-sky-50 text-sky-800", "full": "bg-graphite-900/6 text-graphite-800"}
	stateTone := map[string]string{"ok": "bg-emerald-50 text-emerald-800", "failed": "bg-signal-50 text-signal-800", "running": "bg-amber-50 text-amber-800", "never": "bg-graphite-900/6 text-graphite-700"}
	var apis []app.M
	eps := cfgMap("erp.endpoints")
	for _, key := range eps.Keys {
		def, _ := eps.Vals[key].([]any)
		label := ""
		if len(def) > 1 {
			label = php.Str(def[1])
		}
		var entsOut []app.M
		for _, k := range byAPI[key] {
			d := entityDef(k)
			st := state[k]
			stStatus := "never"
			stLine := ""
			stErr := ""
			if st != nil {
				stStatus = php.OrStr(st.Str("status"), "never")
				if st.Str("last_run_at") != "" {
					stLine = " " + app.TimeAgo(c, st["last_run_at"]) + " · " + php.NF(st.Int("saved"), 0) + " " + c.T("saved")
					if st.Int("duration_ms") != 0 {
						stLine += " · " + php.Str(phpRound(st.Float("duration_ms")/1000, 1)) + "s"
					}
				}
				stErr = st.Str("error")
			}
			entsOut = append(entsOut, app.M{"key": k, "group": mstr(d, "group"), "groupTone": groupTone[mstr(d, "group")], "label": mstr(d, "label"), "overwrite": overwriteOn(k),
				"status": stStatus, "statusTone": stateTone[stStatus], "line": stLine, "error": stErr, "count": php.NF(count[k], 0), "size": php.NF(php.Int(mget(d, "size")), 0)})
		}
		apis = append(apis, app.M{"key": key, "label": label, "value": erpEndpoint(key), "ents": entsOut, "isToken": key == "tokenUrl"})
	}
	var runs []app.M
	_ = runs
	tokenAge := -1
	tokenState := "none"
	tokenSession, tokenGot := "", ""
	if token != nil {
		tokenAge = int(math.Round(float64(time.Now().Unix()-php.StrToTime(token["updated_at"])) / 60))
		tokenState = "valid"
		if tokenAge > 60 || token.Bool("session_suspended") {
			tokenState = "expired"
		}
		tokenSession = php.Substr(token.Str("session_id"), 0, 6)
		tokenGot = app.TimeAgo(c, token["updated_at"])
	}
	days := [][2]string{{"1", c.T("Mon")}, {"2", c.T("Tue")}, {"3", c.T("Wed")}, {"4", c.T("Thu")}, {"5", c.T("Fri")}, {"6", c.T("Sat")}, {"7", c.T("Sun")}}
	onDays := map[string]bool{}
	for _, d := range strings.Split(schedSetting("days"), ",") {
		if d != "" {
			onDays[d] = true
		}
	}
	var dayList []app.M
	for _, d := range days {
		dayList = append(dayList, app.M{"n": d[0], "label": d[1], "on": onDays[d[0]]})
	}
	hb := readHeartbeat()
	sched := schedSetting("enabled") == "1"
	beatText := " · " + c.T("has not run yet")
	if hb != nil {
		beatText = " · " + c.T("looked :ago ago", "ago", php.Str(max(0, int(time.Now().Unix()-hb.at)))+"s") + " (" + hb.by + ")"
	}
	schedText := c.T("Schedule off")
	if sched {
		schedText = c.T("Schedule on")
	}
	lastOK := "—"
	if totals["last_ok"] != nil {
		lastOK = app.TimeAgo(c, totals["last_ok"])
	}
	failedDay := totals.Int("failed_day")
	statCards := []app.M{
		{"key": "today", "label": c.T("Rows saved today"), "value": php.NF(totals.Int("today"), 0), "cls": "text-graphite-900"},
		{"key": "all", "label": c.T("Rows saved in all runs"), "value": php.NF(totals.Int("all_time"), 0), "cls": "text-graphite-900"},
		{"key": "rows", "label": c.T("Rows in the tables now"), "value": php.NF(sum, 0), "cls": "text-graphite-900"},
		{"key": "last_ok", "label": c.T("Last good run"), "value": lastOK, "cls": "text-graphite-900"},
		{"key": "failed", "label": c.T("Failed runs (24 h)"), "value": php.Str(failedDay), "cls": map[bool]string{true: "text-signal-700", false: "text-graphite-900"}[failedDay > 0]},
	}
	runLabel := c.T("Idle")
	if running > 0 {
		runLabel = c.T("Syncing now")
	}
	cron := "* * * * * " + program() + " -base " + app.BasePath + " erp-sync tick"
	c.View("accounting/erp", app.M{"title": c.T("ERP connection"), "apis": apis, "configured": erpConfigured(), "inWindow": inWindow(time.Now()), "cron": cron, "hasPassword": erpPassword() != "",
		"hasInet": sget("inet.authorization", "") != "", "tokenState": tokenState, "tokenSession": tokenSession, "tokenGot": tokenGot, "hasToken": token != nil, "days": dayList,
		"conn": app.M{"ip": connSetting("ip"), "port": connSetting("port"), "username": connSetting("username"), "timeout": connSetting("timeout"), "verify": connSetting("verify_tls") == "1", "base": erpBaseURL()},
		"sched": app.M{"enabled": sched, "hot": schedSetting("hot_minutes"), "cold": schedSetting("cold_minutes"), "from": schedSetting("hour_start"), "to": schedSetting("hour_end"), "tz": schedSetting("tz")},
		"inet": app.M{"sendApi": inetSetting("sendApi"), "statusApi": inetSetting("statusApi"), "paramsApi": inetSetting("paramsApi")},
		"stats": statCards, "runningLabel": runLabel, "schedText": schedText + beatText, "endpointCount": len(eps.Keys),
		"errIP": c.Sess.Errors().Get("ip"), "errSend": c.Sess.Errors().Get("inet_sendApi"), "errStatus": c.Sess.Errors().Get("inet_statusApi"), "errParams": c.Sess.Errors().Get("inet_paramsApi")})
}

func erpTotals() db.Row {
	return acc().First(`SELECT coalesce(sum(saved) FILTER (WHERE started_at::date = CURRENT_DATE), 0) AS today, coalesce(sum(saved), 0) AS all_time, max(finished_at) FILTER (WHERE status = 'ok') AS last_ok, count(*) FILTER (WHERE status = 'failed' AND started_at > now() - interval '1 day') AS failed_day FROM erp_sync_runs`)
}

var ipRe = regexp.MustCompile(`^(https?://)?[A-Za-z0-9.\-]+$`)

func erpSaveConnection(c *app.Ctx) {
	adminGate(c, "Only an administrator can open the ERP connection.")
	u := c.UserID()
	ip := strings.TrimSpace(c.Input("ip"))
	if ip != "" && !ipRe.MatchString(ip) {
		app.Invalid("ip", c.T("Enter the server address, e.g. 5.223.76.93"))
	}
	verify := "0"
	if c.Input("verify_tls") != "" && c.Input("verify_tls") != "0" {
		verify = "1"
	}
	timeout := php.Int(c.Input("timeout", "120"))
	for _, kv := range [][2]string{{"ip", ip}, {"port", digits(c.Input("port"))}, {"username", strings.TrimSpace(c.Input("username"))}, {"verify_tls", verify}, {"timeout", php.Str(max(10, min(900, timeout)))}} {
		sput("conn."+kv[0], kv[1], u)
	}
	if pw := c.Input("password"); pw != "" { // empty = keep the saved one
		sput("conn.password", pw, u)
	}
	if auth := strings.TrimSpace(c.Input("inet_authorization")); auth != "" {
		sput("inet.authorization", auth, u)
	}
	for _, k := range []string{"sendApi", "statusApi", "paramsApi"} {
		v := strings.TrimSpace(c.Input("inet_" + k))
		if v != "" && !strings.HasPrefix(v, "https://") {
			app.Invalid("inet_"+k, c.T("Use an https:// address."))
		}
		sput("inet."+k, v, u)
	}
	var days []int
	seen := map[int]bool{}
	for _, d := range c.Inputs("days") {
		if n := php.Int(d); n >= 1 && n <= 7 && !seen[n] {
			seen[n] = true
			days = append(days, n)
		}
	}
	tz := c.Input("tz", "Asia/Bangkok")
	if _, err := time.LoadLocation(tz); err != nil || !tzRe.MatchString(tz) || tz == "" || tz == "Local" {
		tz = "Asia/Bangkok"
	}
	on := "0"
	if c.Input("enabled") != "" && c.Input("enabled") != "0" {
		on = "1"
	}
	sput("schedule.enabled", on, u)
	sput("schedule.tz", tz, u)
	sput("schedule.hour_start", php.Str(max(0, min(23, php.Int(c.Input("hour_start", "8"))))), u)
	sput("schedule.hour_end", php.Str(max(1, min(24, php.Int(c.Input("hour_end", "19"))))), u)
	ds := []string{}
	sortInts(days)
	for _, d := range days {
		ds = append(ds, php.Str(d))
	}
	if len(ds) == 0 {
		ds = []string{"1", "2", "3", "4", "5"}
	}
	sput("schedule.days", strings.Join(ds, ","), u)
	sput("schedule.hot_minutes", php.Str(max(1, min(1440, php.Int(c.Input("hot_minutes", "3"))))), u)
	sput("schedule.cold_minutes", php.Str(max(1, min(1440, php.Int(c.Input("cold_minutes", "30"))))), u)
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "erp_connection", Label: "ERP", Text: "Changed the ERP connection settings", NoNotify: true, Module: "accounting"})
	c.Flash("success", c.T("ERP connection saved."))
	c.Redirect("/accounting/erp")
}

func sortInts(a []int) {
	for i := 1; i < len(a); i++ {
		for j := i; j > 0 && a[j] < a[j-1]; j-- {
			a[j], a[j-1] = a[j-1], a[j]
		}
	}
}

func erpSaveEndpoints(c *app.Ctx) {
	adminGate(c, "Only an administrator can open the ERP connection.")
	changed := 0
	if c.Input("overwrite_form") != "" {
		on := app.InMap(c.InputAny("overwrite"))
		for _, k := range erpEntities().Keys {
			v := "0"
			if on != nil {
				if _, ok := on.Get(k); ok {
					v = "1"
				}
			}
			if overwriteOn(k) != (v == "1") {
				sput("overwrite."+k, v, c.UserID())
				changed++
			}
		}
	}
	if api := app.InMap(c.InputAny("api")); api != nil {
		eps := cfgMap("erp.endpoints")
		for _, key := range api.Keys {
			if _, ok := eps.Get(key); !ok {
				continue
			}
			path := strings.TrimSpace(php.Str(api.Vals[key]))
			if path == "" || path[0] != '/' || regexp.MustCompile(`\s|\.\.`).MatchString(path) {
				continue
			}
			if path != erpEndpoint(key) {
				sput("api."+key, path, c.UserID())
				changed++
			}
		}
	}
	if changed > 0 {
		c.Flash("success", c.T(":n API addresses saved.", "n", changed))
	} else {
		c.Flash("success", c.T("Nothing changed."))
	}
	c.Redirect("/accounting/erp#apis")
}

// erpLogin gets a new session now and shows it.
func erpLogin(c *app.Ctx) {
	adminGate(c, "Only an administrator can open the ERP connection.")
	var t db.Row
	if err := app.Catch(func() { t = newErpClient().login() }); err != nil {
		c.Flash("error", err.Error())
	} else {
		c.Flash("success", c.T("Logged in to the ERP. Session :id… saved for one hour.", "id", php.Substr(t.Str("session_id"), 0, 6)))
	}
	c.Redirect("/accounting/erp")
}

// erpTest asks one API for a single row, to see that its address and the login work.
func erpTest(c *app.Ctx) {
	adminGate(c, "Only an administrator can open the ERP connection.")
	key := c.Input("key")
	if _, ok := cfgMap("erp.endpoints").Get(key); !ok || key == "tokenUrl" {
		app.Abort(404)
	}
	var r erpPage
	if err := app.Catch(func() { r = newErpClient().page(key, erpQuery{Top: 1}) }); err != nil {
		c.Flash("error", key+": "+err.Error())
	} else if r.err != "" {
		c.Flash("error", key+": "+r.err)
	} else {
		c.Flash("success", key+": HTTP "+php.Str(r.status)+", "+php.Str(len(r.rows))+" row(s) came back.")
	}
	c.Redirect("/accounting/erp#apis")
}

// erpStatus: everything the page shows that changes while it is open (polled every few seconds).
func erpStatus(c *app.Ctx) {
	adminGate(c, "Only an administrator can open the ERP connection.")
	var state []app.M
	for _, r := range acc().Select("SELECT entity, status, mode, last_run_at, last_ok_at, fetched, saved, duration_ms, error FROM erp_sync_state") {
		m := app.M(r)
		if r.Str("last_run_at") != "" {
			m["ago"] = app.TimeAgo(c, r["last_run_at"])
		} else {
			m["ago"] = nil
		}
		state = append(state, m)
	}
	if state == nil {
		state = []app.M{}
	}
	var runs []app.M
	for _, r := range acc().Select("SELECT id, kind, source, status, message, fetched, saved, started_at FROM erp_sync_runs ORDER BY id DESC LIMIT 12") {
		m := app.M(r)
		m["ago"] = app.TimeAgo(c, r["started_at"])
		runs = append(runs, m)
	}
	if runs == nil {
		runs = []app.M{}
	}
	hb := readHeartbeat()
	sch := app.M{"at": nil, "by": nil, "ago": nil, "enabled": schedSetting("enabled") == "1", "inWindow": inWindow(time.Now())}
	if hb != nil {
		sch["at"], sch["by"], sch["ago"] = hb.at, hb.by, time.Now().Unix()-hb.at
	}
	var token any
	if t := acc().First("SELECT session_suspended, updated_at FROM erp_tokens WHERE is_primary ORDER BY id DESC LIMIT 1"); t != nil {
		token = app.M{"ago": app.TimeAgo(c, t["updated_at"]), "minutes": int(math.Round(float64(time.Now().Unix()-php.StrToTime(t["updated_at"])) / 60))}
	}
	c.JSON(app.M{"now": time.Now().Unix(), "state": state, "runs": runs,
		"running": php.Int(acc().Scalar("SELECT count(*) FROM erp_sync_runs WHERE status = 'running' AND started_at > now() - interval '2 hours'")),
		"totals":  erpTotals(), "scheduler": sch, "token": token})
}

// erpRuns: run history, a page at a time (status: ok | warning | failed | running).
func erpRuns(c *app.Ctx) {
	adminGate(c, "Only an administrator can open the ERP connection.")
	st := c.Query("status")
	per := php.Int(c.Query("per", "10"))
	if per != 10 && per != 20 && per != 50 {
		per = 10
	}
	where := ""
	var par []any
	if inStrs(st, []string{"ok", "warning", "failed", "running"}) {
		where = "WHERE status = ?"
		par = []any{st}
	}
	total := php.Int(acc().Scalar("SELECT count(*) FROM erp_sync_runs "+where, par...))
	pages := max(1, int(math.Ceil(float64(total)/float64(per))))
	page := min(pages, max(1, php.Int(c.Query("page", "1"))))
	rows := acc().Select(`SELECT id, kind, source, status, message, fetched, saved, started_at, finished_at,
                                   CASE WHEN finished_at IS NOT NULL THEN round(extract(epoch FROM finished_at - started_at)::numeric, 1) END AS seconds
                              FROM erp_sync_runs `+where+` ORDER BY id DESC LIMIT `+php.Str(per)+` OFFSET `+php.Str((page-1)*per), par...)
	out := []app.M{}
	for _, r := range rows {
		m := app.M(r)
		m["ago"] = app.TimeAgo(c, r["started_at"])
		m["at"] = php.DateOf("d M H:i:s", r["started_at"])
		out = append(out, m)
	}
	c.JSON(app.M{"rows": out, "total": total, "pages": pages, "page": page})
}

// erpRun starts a sync in the background (a server that cannot start processes runs it here).
func erpRun(c *app.Ctx) {
	adminGate(c, "Only an administrator can open the ERP connection.")
	group, entity := c.Input("group"), c.Input("entity")
	mode := "latest"
	if c.Input("mode") == "all" {
		mode = "all"
	}
	if !erpConfigured() {
		c.Flash("error", c.T("Fill in the ERP address, user name and password first."))
		c.Redirect("/accounting/erp")
	}
	var args []string
	var label string
	switch {
	case entity != "":
		if entityDef(entity) == nil {
			app.Abort(404)
		}
		args = []string{"entity", entity, mode}
		label = entity
	case inStrs(group, []string{"hot", "cold", "full", "all"}):
		m := "default"
		if mode == "all" {
			m = "all"
		}
		args = []string{group, php.Str(c.UserID()), m}
		label = group
	default:
		app.Abort(404)
	}
	if spawn(args) {
		extra := ""
		if mode == "all" {
			extra = " (all rows)"
		}
		logInfo("sync", "Started by "+c.MustUser().Email+": "+label+extra)
		c.Flash("success", c.T("Started :what. Watch it below.", "what", label))
	} else {
		if entity != "" {
			runEntity(entity, mode, "", c.UserID())
		} else {
			fm := ""
			if mode == "all" {
				fm = "all"
			}
			runGroup(group, "manual", c.UserID(), fm)
		}
		c.Flash("success", c.T("Finished :what.", "what", label))
	}
	c.Redirect("/accounting/erp")
}

var _ = errors.New
