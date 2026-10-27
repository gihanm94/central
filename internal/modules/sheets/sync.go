package sheets

import (
	"crypto/subtle"
	"fmt"
	"regexp"
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/google"
	"acme/internal/php"
)

// A person's syncs (table → Google Sheet tab on a schedule) and their connected Google accounts (SyncController).

const selectSyncs = `SELECT s.*, t.db, t.table_name, t.label, a.google_email, u.name AS owner_name
        FROM gsheet_syncs s JOIN gsheet_tables t ON t.id = s.table_id JOIN gsheet_accounts a ON a.id = s.account_id JOIN users u ON u.id = s.user_id`

func syncIndex(c *app.Ctx) {
	u := c.MustUser()
	everyone := u.IsAdmin() && c.Query("all") == "1"
	var syncs []db.Row
	if everyone {
		syncs = db.Select(selectSyncs + " ORDER BY coalesce(t.label, t.table_name), s.id")
	} else {
		syncs = db.Select(selectSyncs+" WHERE s.user_id = ? ORDER BY coalesce(t.label, t.table_name), s.id", u.ID)
	}
	type syncView struct {
		Row                         db.Row
		Label, SheetURL, When, Next string
		Active, Mine                bool
		LastTitle, LastAgo          string
	}
	var list []syncView
	for _, s := range syncs {
		v := syncView{Row: s, Label: tableLabel(s), SheetURL: "https://docs.google.com/spreadsheets/d/" + google.RawURLEncode(s.Str("spreadsheet_id")),
			When: describe(c, s), Active: s.Bool("active"), Mine: s.Int("user_id") == u.ID}
		switch {
		case v.Active && !php.Empty(s["next_run_at"]):
			v.Next = c.T("Next: :t", "t", app.FormatDate(c, s["next_run_at"], "D d M, H:i"))
		case s.Str("mode") == "once" && !php.Empty(s["last_run_at"]):
			v.Next = c.T("Done")
		default:
			v.Next = c.T("Paused")
		}
		if !php.Empty(s["last_run_at"]) {
			v.LastTitle = app.FormatDate(c, s["last_run_at"], "d M Y, H:i")
			v.LastAgo = app.TimeAgo(c, s["last_run_at"])
		}
		list = append(list, v)
	}
	tables := allowed(c, u)
	type tableView struct {
		Row   db.Row
		Label string
	}
	var tv []tableView
	for _, t := range tables {
		tv = append(tv, tableView{t, tableLabel(t)})
	}
	label := officeLabel(c)
	paused := inOffice(time.Now())
	c.View("sheets/index", app.M{"title": c.T("Google Sheets"), "syncs": list, "everyone": everyone, "tables": tv,
		"accounts": accountsOf(u.ID), "configured": google.Configured(), "officeLabel": label, "paused": paused, "admin": u.IsAdmin()})
}

/* ------------------------------------------------------------------ Google accounts */

func syncConnect(c *app.Ctx) {
	if !google.Configured() {
		app.Abort(404, c.T("The administrator has not set up the Google connector yet."))
	}
	state := randHex(16)
	c.Sess.Set("gsheet_state", state)
	google.ForwardTo(c, "/sheets/connect/callback")
	// Sheets + Gmail + Calendar in one consent
	c.W.Header().Set("Location", google.LoginURL(c, state, "", nil, "consent select_account"))
	c.Status(302)
	c.HTML("")
}

func syncCallback(c *app.Ctx) {
	u := c.MustUser()
	state := c.Query("state", "")
	saved := php.Str(c.Sess.Get("gsheet_state"))
	ok := state != "" && subtle.ConstantTimeCompare([]byte(saved), []byte(state)) == 1
	c.Sess.Delete("gsheet_state")
	if !ok {
		goTo(c, "/sheets", "error", c.T("The Google sign-in could not be checked. Try again."))
	}
	if e := c.Query("error"); e != "" && e != "0" {
		goTo(c, "/sheets", "error", c.T("Google said: :e", "e", e))
	}
	var email string
	if err := app.Catch(func() { email = connectAccount(c, u.ID, c.Query("code", "")) }); err != nil {
		goTo(c, "/sheets", "error", err.Error())
	}
	back := php.Str(c.Sess.Get("gsheet_back"))
	if back == "" {
		back = "/sheets"
	}
	goTo(c, back, "success", c.T("Google account :e connected for Sheets.", "e", email))
}

func syncDisconnect(c *app.Ctx) {
	disconnectAccount(c.ParamInt("id"), c.MustUser().ID)
	goTo(c, "/sheets", "success", c.T("Google account disconnected. Its syncs were removed; nothing was deleted from Google."))
}

// syncFiles is JSON: spreadsheets of one of my accounts (?q= search).
func syncFiles(c *app.Ctx) {
	id := c.ParamInt("id")
	if findAccount(id, c.MustUser().ID) == nil {
		app.Abort(404)
	}
	var files []spreadsheetFile
	if err := app.Catch(func() { files = spreadsheets(c, id, c.Query("q", "")) }); err != nil {
		c.JSON(app.M{"message": err.Error()}, 502)
	}
	c.JSON(app.M{"files": files})
}

// syncTabs is JSON: tabs of a spreadsheet (?file=).
func syncTabs(c *app.Ctx) {
	id := c.ParamInt("id")
	if findAccount(id, c.MustUser().ID) == nil {
		app.Abort(404)
	}
	var info spreadsheetInfo
	if err := app.Catch(func() { info = spreadsheet(c, id, fileID(c.Query("file", ""))) }); err != nil {
		c.JSON(app.M{"message": err.Error()}, 502)
	}
	c.JSON(info)
}

/* ---------------------------------------------------------------------------- syncs */

func syncCreate(c *app.Ctx) {
	u := c.MustUser()
	tables := allowed(c, u)
	back := "/sheets/new"
	if c.Query("table") != "" && c.Query("table") != "0" {
		back += "?table=" + php.Str(php.Int(c.Query("table")))
	}
	c.Sess.Set("gsheet_back", back)
	tableID := 0
	if len(tables) > 0 {
		tableID = tables[0].Int("id")
	}
	if c.QueryHas("table") {
		tableID = php.Int(c.Query("table"))
	}
	syncForm(c, c.T("New sync"), nil, tables, accountsOf(u.ID), tableID)
}

func syncStore(c *app.Ctx) {
	u := c.MustUser()
	data, next := checked(c, nil)
	data = append(data, db.Col{Name: "user_id", Value: u.ID})
	id := db.Core.InsertID("gsheet_syncs", data)
	goTo(c, "/sheets", "success", c.T("Sync saved. Next run: :t.", "t", app.FormatDate(c, next, "D d M, H:i"))+" #"+php.Str(id))
}

func syncEdit(c *app.Ctx) {
	u := c.MustUser()
	s := mine(c, c.ParamInt("id"), false)
	syncForm(c, c.T("Edit sync"), s, allowed(c, u), accountsOf(u.ID), s.Int("table_id"))
}

func syncUpdate(c *app.Ctx) {
	id := c.ParamInt("id")
	s := mine(c, id, false)
	data, next := checked(c, s)
	data = append(data, db.Col{Name: "active", Value: true}, db.Col{Name: "updated_at", Value: php.NowString()})
	db.Update("gsheet_syncs", data, db.Cols{{Name: "id", Value: id}})
	goTo(c, "/sheets", "success", c.T("Sync saved. Next run: :t.", "t", app.FormatDate(c, next, "D d M, H:i")))
}

func syncDestroy(c *app.Ctx) {
	id := c.ParamInt("id")
	mine(c, id, true)
	db.Exec("DELETE FROM gsheet_syncs WHERE id = ?", id)
	goTo(c, "/sheets", "success", c.T("Sync removed. The Google Sheet itself is kept."))
}

func syncRun(c *app.Ctx) {
	id := c.ParamInt("id")
	mine(c, id, true)
	if err := app.Catch(func() {
		runNow(c, id)
		s := db.First("SELECT last_status, last_message FROM gsheet_syncs WHERE id = ?", id)
		kind := "error"
		if s.Str("last_status") == "ok" {
			kind = "success"
		}
		c.Flash(kind, s.Str("last_message"))
	}); err != nil {
		c.Flash("error", err.Error())
	}
	c.Back("", "")
}

// syncToggle pauses / resumes.
func syncToggle(c *app.Ctx) {
	id := c.ParamInt("id")
	s := mine(c, id, true)
	if s.Bool("active") {
		db.Exec("UPDATE gsheet_syncs SET active = FALSE, next_run_at = NULL, updated_at = now() WHERE id = ?", id)
		c.Back("success", c.T("Sync paused."))
	}
	next, ok := nextRun(s, time.Now())
	if !ok {
		goTo(c, "/sheets/"+php.Str(id)+"/edit", "error", c.T("That date has passed. Choose a new one."))
	}
	db.Exec("UPDATE gsheet_syncs SET active = TRUE, next_run_at = ?, updated_at = now() WHERE id = ?", php.Date("c", next), id)
	c.Back("success", c.T("Sync resumed. Next run: :t.", "t", php.Date("D d M, H:i", next)))
}

/* -------------------------------------------------------------------------- helpers */

func goTo(c *app.Ctx, to, kind, message string) {
	c.Flash(kind, message)
	c.Redirect(to)
}

// mine is a sync of mine (administrators: anybody's when adminToo).
func mine(c *app.Ctx, id int, adminToo bool) db.Row {
	u := c.MustUser()
	s := db.First("SELECT * FROM gsheet_syncs WHERE id = ?", id)
	if s == nil {
		app.Abort(404)
	}
	if s.Int("user_id") != u.ID && !(adminToo && u.IsAdmin()) {
		app.Abort(403)
	}
	return s
}

var (
	sheetLinkRe = regexp.MustCompile(`/spreadsheets/d/([A-Za-z0-9_-]+)`)
	sheetIDRe   = regexp.MustCompile(`^[A-Za-z0-9_-]{1,128}$`)
	tabBadRe    = regexp.MustCompile(`[\[\]\*\?/\\:]`)
)

// fileID: spreadsheet id from an id or a docs.google.com link.
func fileID(v string) string {
	v = strings.TrimSpace(v)
	if m := sheetLinkRe.FindStringSubmatch(v); m != nil {
		return m[1]
	}
	if sheetIDRe.MatchString(v) {
		return v
	}
	return ""
}

// checked validates the form and resolves the spreadsheet (creates a new one when asked): columns for gsheet_syncs
// and the next run ("" when none).
func checked(c *app.Ctx, old db.Row) (db.Cols, string) {
	u := c.MustUser()
	errs := app.NewErrors()
	tableID := php.Int(c.Input("table_id"))
	var table db.Row
	for _, t := range allowed(c, u) {
		if t.Int("id") == tableID {
			table = t
		}
	}
	if table == nil {
		errs.Add("table_id", c.T("Choose a table you may sync."))
	}
	accountID := php.Int(c.Input("account_id"))
	if findAccount(accountID, u.ID) == nil {
		errs.Add("account_id", c.T("Choose one of your connected Google accounts."))
	}
	tab := strings.TrimSpace(tabBadRe.ReplaceAllString(php.Str(c.InputAny("sheet_tab")), " "))
	if tab == "" || php.Len(tab) > 100 {
		errs.Add("sheet_tab", c.T("Give the tab a name (up to 100 characters)."))
	}
	isNew := c.Input("target") == "new"
	src := c.Input("spreadsheet_link")
	if src == "" {
		src = php.Str(c.InputAny("spreadsheet_id"))
	}
	fid := fileID(src)
	newName := c.Input("new_name")
	if isNew && newName == "" {
		errs.Add("new_name", c.T("Give the new spreadsheet a name."))
	}
	if !isNew && fid == "" {
		errs.Add("spreadsheet_id", c.T("Choose a spreadsheet or paste its link."))
	}
	w := validateSchedule(c, php.Str(c.InputAny("mode")), php.Str(c.InputAny("run_time")), c.Inputs("days"), php.Str(c.InputAny("run_date")), time.Now(), errs)
	if errs.Len() > 0 {
		app.InvalidErrors(errs)
	}

	var id, name string
	if err := app.Catch(func() {
		if isNew {
			id, name = create(c, accountID, php.Substr(newName, 0, 200), tab)
		} else {
			id, name = fid, spreadsheet(c, accountID, fid).Name
		}
	}); err != nil {
		field := "spreadsheet_id"
		if isNew {
			field = "new_name"
		}
		app.Invalid(field, err.Error())
	}
	var next any
	nextStr := ""
	if at, ok := nextRun(db.Row{"mode": w.Mode, "days": w.Days, "run_time": w.RunTime, "run_date": php.Str(w.RunDate)}, time.Now()); ok {
		nextStr = php.Date("c", at)
		next = nextStr
	}
	return db.Cols{{Name: "table_id", Value: tableID}, {Name: "account_id", Value: accountID}, {Name: "spreadsheet_id", Value: id},
		{Name: "spreadsheet_name", Value: php.Substr(name, 0, 255)}, {Name: "sheet_tab", Value: tab}, {Name: "next_run_at", Value: next},
		{Name: "mode", Value: w.Mode}, {Name: "days", Value: w.Days}, {Name: "run_time", Value: w.RunTime}, {Name: "run_date", Value: w.RunDate}}, nextStr
}

// syncForm shows sheets/form (values: the old input, else the sync, else the defaults).
func syncForm(c *app.Ctx, title string, s db.Row, tables, accounts []db.Row, tableID int) {
	v := func(k string, def any) any {
		if o, ok := c.Sess.Old(k); ok {
			return o
		}
		if s != nil {
			if x, ok := s[k]; ok && x != nil {
				return x
			}
		}
		return def
	}
	if o, ok := c.Sess.Old("table_id"); ok {
		tableID = php.Int(o)
	}
	label := ""
	type tableOpt struct {
		ID                 int
		Label, Text        string
		Selected           bool
	}
	var topts []tableOpt
	for _, t := range tables {
		l := tableLabel(t)
		if t.Int("id") == tableID {
			label = l
		}
		topts = append(topts, tableOpt{t.Int("id"), l, l + " (" + t.Str("db") + "." + t.Str("table_name") + ")", t.Int("id") == tableID})
	}
	mode := php.Str(v("mode", "daily"))
	var days []int
	if o, ok := c.Sess.Old("days"); ok {
		for _, d := range app.InStrings(o) {
			days = append(days, php.Int(d))
		}
	} else if s != nil {
		for _, d := range strings.Split(s.Str("days"), ",") {
			days = append(days, php.Int(d))
		}
	} else {
		days = []int{1, 2, 3, 4, 5}
	}
	target := "new"
	if s != nil {
		target = "existing"
	}
	if o, ok := c.Sess.Old("target"); ok {
		target = php.Str(o)
	}
	firstAcc := 0
	if len(accounts) > 0 {
		firstAcc = accounts[0].Int("id")
	}
	accountID := php.Int(v("account_id", firstAcc))
	type dayOpt struct {
		N       int
		Label   string
		Checked bool
	}
	var dopts []dayOpt
	for n := 1; n <= 7; n++ {
		dopts = append(dopts, dayOpt{n, c.T(dayNames[n]), inList(n, days)})
	}
	o := office()
	action := "/sheets"
	if s != nil {
		action = "/sheets/" + s.Str("id")
	}
	newName := label
	if x, ok := c.Sess.Old("new_name"); ok {
		newName = php.Str(x)
	}
	link := ""
	if x, ok := c.Sess.Old("spreadsheet_link"); ok {
		link = php.Str(x)
	}
	c.View("sheets/form", app.M{"title": title, "sync": s, "tables": topts, "accounts": accounts, "accountId": accountID,
		"mode": mode, "days": dopts, "target": target, "action": action, "newName": newName, "link": link,
		"tab": php.Str(v("sheet_tab", label)), "runDate": php.Str(v("run_date", php.Today())), "today": php.Today(),
		"runTime": php.Substr(php.Str(v("run_time", o.End)), 0, 5), "officeLabel": officeLabel(c)})
}

var _ = fmt.Sprint
