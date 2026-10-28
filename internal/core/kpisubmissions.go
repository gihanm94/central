package core

import (
	"errors"
	"fmt"
	"html/template"
	"log"
	"net/url"
	"os"
	"regexp"
	"sort"
	"strings"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/conv"
	"acme/internal/db"
	"acme/internal/kpisub"
)

// User Management → KPI submissions (KpiSubmissionController): monthly KPI / OKR results, grouped by month, approved by
// the chosen approver.

type kpiFilter struct {
	Year int
	View string
	Q    string
}

func kpiFilters(c *app.Ctx) kpiFilter {
	thisYear := conv.Now().Year()
	year := conv.Int(c.Query("year", conv.Str(thisYear)))
	if year < 2000 || year > 2100 {
		year = thisYear
	}
	view := c.Query("view", "all")
	if !conv.InList(view, "all", "mine", "approve") {
		view = "all"
	}
	return kpiFilter{Year: year, View: view, Q: conv.Substr(strings.TrimSpace(c.Query("q")), 0, 100)}
}

// kpiRows: the visible rows of one year, newest month first.
func kpiRows(c *app.Ctx, f kpiFilter) []db.Row {
	u := c.MustUser()
	vs, vp := kpisub.VisibleSQL(u, "s")
	where := []string{"s.deleted_at IS NULL", "s.period_year = ?", vs}
	params := append([]any{f.Year}, vp...)
	switch f.View {
	case "mine":
		where = append(where, "s.user_id = ?")
		params = append(params, u.ID)
	case "approve":
		where = append(where, "s.approver_id = ? AND s.approved_at IS NULL")
		params = append(params, u.ID)
	}
	if f.Q != "" {
		like := "%" + strings.NewReplacer(`\`, `\\`, "%", `\%`, "_", `\_`).Replace(f.Q) + "%"
		where = append(where, "(s.task_title ILIKE ? OR su.name ILIKE ? OR s.note ILIKE ?)")
		params = append(params, like, like, like)
	}
	rows := db.Select(`SELECT s.*, su.name AS submitter_name, su.avatar AS submitter_avatar, ap.name AS approver_name, ap.avatar AS approver_avatar, d.name AS department_name
              FROM kpi_submissions s
              JOIN users su ON su.id = s.user_id
         LEFT JOIN users ap ON ap.id = s.approver_id
         LEFT JOIN departments d ON d.id = s.department_id
             WHERE `+strings.Join(where, " AND ")+`
          ORDER BY su.name, s.user_id, s.period_month DESC, s.submitted_at, s.id LIMIT 5000`, params...)
	if len(rows) > 0 {
		ids := make([]string, len(rows))
		for i, r := range rows {
			ids[i] = conv.Str(r.Int("id"))
		}
		files := map[int][]db.Row{}
		for _, f := range db.Select("SELECT id, submission_id, name, size FROM kpi_submission_files WHERE submission_id IN (" + strings.Join(ids, ",") + ") ORDER BY id") {
			files[f.Int("submission_id")] = append(files[f.Int("submission_id")], f)
		}
		for _, r := range rows {
			r["files"] = files[r.Int("id")]
		}
	}
	return rows
}

type kpiMonth struct {
	M, Count, Approved, MineToApprove int
	Label, Avg                        string
	Rows                              []app.M
}

type kpiGroup struct {
	UID                           int
	Name, Avatar, Department, Avg any
	Count, Approved               int
	Months                        []*kpiMonth
}

func kpiNum(n any) string {
	if n == nil {
		return "—"
	}
	return conv.NF(conv.Float(n), 2)
}

func kpiIndex(c *app.Ctx) {
	c.Authorize("kpi_submissions", "view")
	f := kpiFilters(c)
	u := c.MustUser()
	today := conv.Today()
	// Submitted by → month (newest first), like the grouped sheet people used before
	var groups []*kpiGroup
	byUser := map[int]*kpiGroup{}
	byMonth := map[[2]int]*kpiMonth{}
	for _, r := range kpiRows(c, f) {
		g := byUser[r.Int("user_id")]
		if g == nil {
			g = &kpiGroup{UID: r.Int("user_id"), Name: r["submitter_name"], Avatar: r["submitter_avatar"], Department: r["department_name"]}
			byUser[g.UID] = g
			groups = append(groups, g)
		}
		m := r.Int("period_month")
		mo := byMonth[[2]int{g.UID, m}]
		if mo == nil {
			mo = &kpiMonth{M: m, Label: app.FormatDate(c, fmt.Sprintf("%04d-%02d-01", f.Year, m), "F Y")}
			byMonth[[2]int{g.UID, m}] = mo
			g.Months = append(g.Months, mo)
		}
		approveBy := conv.Date("Y-m-d", kpisub.ApproveBy(f.Year, m))
		lateApproval := today > approveBy
		if r["approved_at"] != nil {
			lateApproval = conv.Substr(r.Str("approved_at"), 0, 10) > approveBy
		}
		host := ""
		if r.Str("related_link") != "" {
			if pu, err := url.Parse(r.Str("related_link")); err == nil && pu.Hostname() != "" {
				host = pu.Hostname()
			} else {
				host = r.Str("related_link")
			}
		}
		mo.Rows = append(mo.Rows, app.M{"r": r, "n": len(mo.Rows) + 1, "lateApproval": lateApproval, "canApprove": kpisub.CanApprove(u, r),
			"canEdit": kpisub.CanEdit(u, r), "canDelete": kpisub.CanDelete(u, r), "host": host,
			"finished": kpiNum(r["finished"]), "total": kpiNum(r["total"]), "criteria": kpiNum(r["criteria"])})
	}
	for _, g := range groups {
		sort.SliceStable(g.Months, func(i, j int) bool { return g.Months[i].M > g.Months[j].M })
		sum := 0.0
		for _, mo := range g.Months {
			msum := 0.0
			for _, x := range mo.Rows {
				r := x["r"].(db.Row)
				mo.Count++
				msum += r.Float("percentage")
				if r["approved_at"] != nil {
					mo.Approved++
				} else if r.Int("approver_id") == u.ID {
					mo.MineToApprove++
				}
			}
			mo.Avg = conv.NF(msum/float64(mo.Count), 0)
			g.Count += mo.Count
			g.Approved += mo.Approved
			sum += msum
		}
		g.Avg = conv.NF(sum/float64(g.Count), 0)
	}
	seen := map[int]bool{}
	var years []int
	for _, v := range db.Core.Column("SELECT DISTINCT period_year FROM kpi_submissions WHERE deleted_at IS NULL") {
		if y := conv.Int(v); !seen[y] {
			seen[y] = true
			years = append(years, y)
		}
	}
	for _, y := range []int{conv.Now().Year(), f.Year} {
		if !seen[y] {
			seen[y] = true
			years = append(years, y)
		}
	}
	sort.Sort(sort.Reverse(sort.IntSlice(years)))
	yopts := make([]app.Opt, len(years))
	for i, y := range years {
		yopts[i] = app.Opt{Value: conv.Str(y), Label: conv.Str(y)}
	}
	pending := conv.Int(db.Scalar("SELECT count(*) FROM kpi_submissions WHERE deleted_at IS NULL AND approved_at IS NULL AND approver_id = ?", u.ID))
	link := func(view string) string {
		var v, q any
		if view != "all" {
			v = view
		}
		if f.Q != "" {
			q = f.Q
		}
		return c.URL("/kpi-submissions", "year", f.Year, "view", v, "q", q)
	}
	type tab struct {
		Key, Label, URL string
	}
	tabs := []tab{{"all", c.T("All"), link("all")}, {"mine", c.T("Mine"), link("mine")}, {"approve", c.T("To approve"), link("approve")}}
	c.View("kpi/index", app.M{"title": c.T("KPI submissions"), "f": f, "groups": groups, "years": yopts, "pending": pending, "tabs": tabs,
		"dueDay": kpisub.DueDay, "approveDay": kpisub.ApproveDay, "year": conv.Str(f.Year)})
}

func kpiCreate(c *app.Ctx) {
	c.Authorize("kpi_submissions", "create")
	u := c.MustUser()
	periods := kpisub.Periods()
	// Default: last month until the 7th (that is what is due now), this month after.
	def := periods[0]
	if conv.Now().Day() <= kpisub.DueDay {
		def = periods[1]
	}
	oldOr := func(k string, d any) string {
		if v, ok := c.Sess.Old(k); ok {
			return conv.Str(v)
		}
		return conv.Str(d)
	}
	y := conv.Int(c.Query("year", oldOr("year", def.Y)))
	m := conv.Int(c.Query("month", oldOr("month", def.M)))
	if !kpisub.InPeriods(y, m) {
		y, m = def.Y, def.M
	}
	approvers := kpisub.Approvers(c, u.ID)
	manager := db.Scalar("SELECT manager_id FROM users WHERE id = ?", u.ID)
	last := db.Scalar("SELECT approver_id FROM kpi_submissions WHERE user_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1", u.ID)
	approver := c.Query("approver", oldOr("approver_id", conv.Or(last, manager, "")))
	if !kpisub.HasOpt(approvers, approver) {
		approver = ""
	}
	tasks := kpisub.Available(u, y, m)
	// years and the months of the chosen year
	var years []app.Opt
	seen := map[int]bool{}
	var months []app.Opt
	for _, p := range periods {
		if !seen[p.Y] {
			seen[p.Y] = true
			years = append(years, app.Opt{Value: conv.Str(p.Y), Label: conv.Str(p.Y)})
		}
	}
	for mm := 1; mm <= 12; mm++ {
		if kpisub.InPeriods(y, mm) {
			months = append(months, app.Opt{Value: conv.Str(mm), Label: app.FormatDate(c, fmt.Sprintf("%04d-%02d-01", y, mm), "F")})
		}
	}
	kind := map[string]string{"kpi": c.T("KPI"), "okr": c.T("OKR")}
	var taskOpts []app.Opt
	criteria := config.NewMap()
	for _, t := range tasks {
		taskOpts = append(taskOpts, app.Opt{Value: conv.Str(t.ID), Label: kind[t.Kind] + " · " + t.Title})
		if t.Criteria != nil {
			criteria.Set(conv.Str(t.ID), conv.NumberClean(t.Criteria))
		} else {
			criteria.Set(conv.Str(t.ID), "—")
		}
	}
	lateDays, pct := kpisub.Timeliness(y, m, kpisub.Today())
	c.View("kpi/create", app.M{"title": c.T("Submit KPIs"), "year": y, "month": m, "years": years, "months": months,
		"period": app.FormatDate(c, fmt.Sprintf("%04d-%02d-01", y, m), "F Y"), "tasks": taskOpts, "criteria": criteria,
		"approvers": approvers, "approver": approver, "due": app.FormatDate(c, conv.Date("Y-m-d", kpisub.DueDate(y, m)), "j M Y"),
		"lateDays": lateDays, "pct": pct, "dueDay": kpisub.DueDay, "approveDay": kpisub.ApproveDay, "maxFiles": kpisub.MaxFiles, "maxMB": kpisub.MaxFileMB})
}

var httpRe = regexp.MustCompile(`(?i)^https?://`)

func validLink(s string) bool {
	u, err := url.ParseRequestURI(s)
	return err == nil && u.Scheme != "" && u.Host != "" && httpRe.MatchString(s) && !strings.ContainsAny(s, " \t\n")
}

func nilIfBlank(s string) any {
	if strings.TrimSpace(s) == "" {
		return nil
	}
	return strings.TrimSpace(s)
}

func kpiStore(c *app.Ctx) {
	c.Authorize("kpi_submissions", "create")
	u := c.MustUser()
	in := c.ValidateInput(
		app.Rule{Field: "year", Rules: "required|integer"}, app.Rule{Field: "month", Rules: "required|integer"},
		app.Rule{Field: "kpi_id", Rules: "required|integer", Label: c.T("KPI / OKR")}, app.Rule{Field: "approver_id", Rules: "required|integer", Label: c.T("Approver")},
		app.Rule{Field: "finished", Rules: "required|numeric", Label: c.T("Finished")}, app.Rule{Field: "total", Rules: "required|numeric", Label: c.T("Total tasks")},
		app.Rule{Field: "note", Rules: "nullable|max:2000"}, app.Rule{Field: "related_link", Rules: "nullable|max:500", Label: c.T("Related link")},
	)
	y, m := in.Int("year"), in.Int("month")
	errs := app.NewErrors()
	if !kpisub.InPeriods(y, m) {
		errs.Add("month", c.T("Choose this month or one of the last eleven."))
	}
	task := kpisub.FindTask(kpisub.Available(u, y, m), in.Int("kpi_id"))
	if task == nil {
		errs.Add("kpi_id", c.T("This KPI is not yours or was already submitted for this month."))
	}
	if !kpisub.HasOpt(kpisub.Approvers(c, u.ID), conv.Str(in.Int("approver_id"))) {
		errs.Add("approver_id", c.T("Choose an approver from the list."))
	}
	for _, k := range []string{"finished", "total"} {
		if v := conv.Float(in.Get(k)); v < 0 || v > 999999999 {
			errs.Add(k, c.T("Enter a number from 0 up."))
		}
	}
	link := strings.TrimSpace(in.Str("related_link"))
	if link != "" && !validLink(link) {
		errs.Add("related_link", c.T("Enter a full link starting with https://"))
	}
	if errs.Len() > 0 {
		app.InvalidErrors(errs)
	}
	files := kpisub.Uploads(c, c.Files("files"))
	lateDays, pct := kpisub.Timeliness(y, m, kpisub.Today())
	var id int
	err := db.Try(func() {
		db.Core.Transaction(func(tx db.Q) {
			id = tx.InsertID("kpi_submissions", db.Cols{
				{Name: "user_id", Value: u.ID}, {Name: "department_id", Value: u.DeptID()}, {Name: "team_id", Value: u.TeamIDOrNil()},
				{Name: "kpi_id", Value: in.Int("kpi_id")}, {Name: "task_title", Value: task.Title}, {Name: "task_kind", Value: task.Kind}, {Name: "criteria", Value: task.Criteria},
				{Name: "period_year", Value: y}, {Name: "period_month", Value: m}, {Name: "approver_id", Value: in.Int("approver_id")},
				{Name: "finished", Value: in.Str("finished")}, {Name: "total", Value: in.Str("total")}, {Name: "note", Value: nilIfBlank(in.Str("note"))}, {Name: "related_link", Value: nilIfBlank(link)},
				{Name: "days_late", Value: lateDays}, {Name: "percentage", Value: pct},
			})
		})
	})
	if err != nil {
		var de *db.Error
		if errors.As(err, &de) && de.Code() == "23505" { // someone (another tab) submitted the same KPI a moment ago
			app.Invalid("kpi_id", c.T("This KPI was already submitted for this month."))
		}
		panic(err)
	}
	kpisub.Store(id, files)

	period := app.FormatDate(c, fmt.Sprintf("%04d-%02d-01", y, m), "F Y")
	app.LogActivity(c, app.ActivityEntry{Action: "created", Type: "kpi_submission", ID: id, Label: task.Title, Text: `Submitted ":label" for :period`,
		Params: []any{"label", task.Title, "period", period}, Props: app.M{"percentage": pct, "kpi_id": in.Int("kpi_id")}, Owner: in.Int("approver_id"), NoNotify: true})
	kpiTellApprover(c, in.Int("approver_id"), id, task.Title, period, in, lateDays, pct)

	msg := c.T("\":task\" submitted for :period.", "task", task.Title, "period", period)
	c.Flash("success", msg)
	if c.Input("next") == "continue" {
		c.Redirect("/kpi-submissions/create?" + fmt.Sprintf("year=%d&month=%d&approver=%d", y, m, in.Int("approver_id")))
	}
	c.Redirect(fmt.Sprintf("/kpi-submissions?year=%d&view=mine#u%d-m%d", y, u.ID, m))
}

// kpiTellApprover: bell and e-mail to the approver for every submission.
func kpiTellApprover(c *app.Ctx, approverID, id int, title, period string, in *app.Clean, lateDays, pct int) {
	u := c.MustUser()
	link := c.URL("/kpi-submissions", "year", in.Int("year"), "view", "approve") + fmt.Sprintf("#u%d-m%d", u.ID, in.Int("month"))
	app.Bell(c, approverID, "core", "kpi_submission", c.T(":name submitted a KPI for :period", "name", u.Name, "period", period), title, link, u.ID)
	a := db.First("SELECT name, email FROM users WHERE id = ? AND deleted_at IS NULL AND is_active", approverID)
	if a == nil {
		return
	}
	if err := app.Catch(func() {
		when := c.T("On time (100%)")
		if lateDays > 0 {
			when = c.T(":n days late (:p%)", "n", lateDays, "p", pct)
		}
		app.Notify(c, a.Str("email"), a.Str("name"), c.T("KPI to approve: :task (:period)", "task", title, "period", period), []template.HTML{
			esc(c.T(":name submitted a KPI result and chose you as approver.", "name", u.Name)),
			esc(c.T("KPI / OKR") + ": " + title),
			esc(c.T("Month") + ": " + period),
			esc(c.T("Finished") + ": " + conv.NumberClean(in.Get("finished")) + " / " + c.T("Total tasks") + ": " + conv.NumberClean(in.Get("total"))),
			esc(c.T("Submitted") + ": " + when),
		}, &app.MailButton{Label: c.T("Review and approve"), URL: link}, c.T("Please approve by day :d of the next month.", "d", kpisub.ApproveDay))
	}); err != nil {
		log.Printf("[kpi] approver e-mail for submission %d failed: %v", id, err)
	}
}

func kpiApprove(c *app.Ctx) {
	u := c.MustUser()
	row := kpisub.Find(u, c.ParamInt("id"))
	if row == nil {
		app.Abort(404)
	}
	if !kpisub.CanApprove(u, row) {
		app.Abort(403, c.T("Only the chosen approver can approve this."))
	}
	kpiApproveRows(c, []db.Row{row})
	c.Back("success", c.T("Approved."))
}

// kpiApproveMonth: approve every submission of one month waiting for me.
func kpiApproveMonth(c *app.Ctx) {
	u := c.MustUser()
	person := conv.Int(c.Input("user_id", "0"))
	sql := "SELECT * FROM kpi_submissions WHERE deleted_at IS NULL AND approved_at IS NULL AND approver_id = ? AND period_year = ? AND period_month = ?"
	params := []any{u.ID, conv.Int(c.Input("year")), conv.Int(c.Input("month"))}
	if person != 0 {
		sql += " AND user_id = ?"
		params = append(params, person)
	}
	rows := db.Select(sql, params...)
	kpiApproveRows(c, rows)
	c.Back("success", c.T(":n submissions approved.", "n", len(rows)))
}

func kpiApproveRows(c *app.Ctx, rows []db.Row) {
	u := c.MustUser()
	for _, row := range rows {
		if row["approved_at"] != nil {
			continue
		}
		db.Exec("UPDATE kpi_submissions SET approved_at = now(), approved_by = ? WHERE id = ? AND approved_at IS NULL", u.ID, row.Int("id"))
		period := app.FormatDate(c, fmt.Sprintf("%04d-%02d-01", row.Int("period_year"), row.Int("period_month")), "F Y")
		app.LogActivity(c, app.ActivityEntry{Action: "approved", Type: "kpi_submission", ID: row.Int("id"), Label: row.Str("task_title"), Text: `Approved ":label" for :period`,
			Params: []any{"label", row["task_title"], "period", period}, Props: app.M{"kpi_id": row.Int("kpi_id")}, Owner: row.Int("user_id"), NoNotify: true})
		app.Bell(c, row.Int("user_id"), "core", "kpi_submission", c.T("KPI approved: :task (:period)", "task", row["task_title"], "period", period), "",
			c.URL("/kpi-submissions", "year", row.Int("period_year"), "view", "mine")+fmt.Sprintf("#u%d-m%d", row.Int("user_id"), row.Int("period_month")), u.ID)
	}
}

func kpiForChange(c *app.Ctx, id int) db.Row {
	u := c.MustUser()
	row := kpisub.Find(u, id)
	if row == nil {
		app.Abort(404)
	}
	if !kpisub.CanEdit(u, row) {
		app.Abort(403, c.T("Only the BU manager of the department can change a submission."))
	}
	return row
}

// kpiEdit: correcting a submission: the BU manager of the department (or an administrator). Every change is logged.
func kpiEdit(c *app.Ctx) {
	row := kpiForChange(c, c.ParamInt("id"))
	approvers := kpisub.Approvers(c, row.Int("user_id"))
	if row["approver_id"] != nil {
		cur := db.Select(`SELECT u.id, u.name, u.avatar, r.name AS role_name, d.name AS department_name FROM users u JOIN roles r ON r.id = u.role_id
            LEFT JOIN departments d ON d.id = u.department_id WHERE u.id = ?`, row["approver_id"])
		for _, o := range kpisub.People(c, cur) {
			if !kpisub.HasOpt(approvers, o.Value) {
				approvers = append(approvers, o)
			}
		}
	}
	oldOr := func(k string) any {
		if v, ok := c.Sess.Old(k); ok {
			return v
		}
		return row[k]
	}
	n := func(x any) string {
		if x == nil || conv.Str(x) == "" {
			return ""
		}
		return conv.NumberClean(x)
	}
	c.View("kpi/edit", app.M{"title": c.T("Correct submission"), "row": row, "approvers": approvers, "submitter": db.Scalar("SELECT name FROM users WHERE id = ?", row["user_id"]),
		"period":   app.FormatDate(c, fmt.Sprintf("%04d-%02d-01", row.Int("period_year"), row.Int("period_month")), "F Y"),
		"approver": conv.Str(oldOr("approver_id")), "criteria": n(oldOr("criteria")), "finished": n(oldOr("finished")), "total": n(oldOr("total")),
		"note": conv.Str(oldOr("note")), "link": conv.Str(oldOr("related_link"))})
}

func kpiUpdate(c *app.Ctx) {
	id := c.ParamInt("id")
	row := kpiForChange(c, id)
	in := c.ValidateInput(
		app.Rule{Field: "approver_id", Rules: "required|integer", Label: c.T("Approver")}, app.Rule{Field: "finished", Rules: "required|numeric", Label: c.T("Finished")},
		app.Rule{Field: "total", Rules: "required|numeric", Label: c.T("Total tasks")}, app.Rule{Field: "criteria", Rules: "nullable|numeric", Label: c.T("Criteria")},
		app.Rule{Field: "note", Rules: "nullable|max:2000"}, app.Rule{Field: "related_link", Rules: "nullable|max:500", Label: c.T("Related link")},
	)
	errs := app.NewErrors()
	if !kpisub.HasOpt(kpisub.Approvers(c, row.Int("user_id")), conv.Str(in.Int("approver_id"))) && in.Int("approver_id") != row.Int("approver_id") {
		errs.Add("approver_id", c.T("Choose an approver from the list."))
	}
	for _, k := range []string{"finished", "total"} {
		if v := conv.Float(in.Get(k)); v < 0 || v > 999999999 {
			errs.Add(k, c.T("Enter a number from 0 up."))
		}
	}
	if in.Get("criteria") != nil && (conv.Float(in.Get("criteria")) < 1 || conv.Float(in.Get("criteria")) > 100) {
		errs.Add("criteria", c.T("Criteria must be between 1 and 100."))
	}
	link := strings.TrimSpace(in.Str("related_link"))
	if link != "" && !validLink(link) {
		errs.Add("related_link", c.T("Enter a full link starting with https://"))
	}
	if errs.Len() > 0 {
		app.InvalidErrors(errs)
	}
	newVals := db.Cols{{Name: "approver_id", Value: in.Int("approver_id")}, {Name: "finished", Value: in.Get("finished")}, {Name: "total", Value: in.Get("total")},
		{Name: "criteria", Value: in.Get("criteria")}, {Name: "note", Value: nilIfBlank(in.Str("note"))}, {Name: "related_link", Value: nilIfBlank(link)}}

	// What changed, in words people read (names, plain numbers)
	name := func(id any) string {
		if conv.Int(id) == 0 {
			return ""
		}
		return conv.Str(db.Scalar("SELECT name FROM users WHERE id = ?", id))
	}
	num := func(v any) string {
		if v == nil || conv.Str(v) == "" {
			return ""
		}
		return conv.NumberClean(v)
	}
	changes := config.NewMap()
	var parts []string
	for _, col := range newVals {
		var from, to string
		switch col.Name {
		case "approver_id":
			from, to = name(row[col.Name]), name(col.Value)
		case "finished", "total", "criteria":
			from, to = num(row[col.Name]), num(col.Value)
		default:
			from, to = conv.Str(row[col.Name]), conv.Str(col.Value)
		}
		if from != to {
			k := col.Name
			if k == "approver_id" {
				k = "approver"
			}
			ch := config.NewMap()
			ch.Set("from", from)
			ch.Set("to", to)
			changes.Set(k, ch)
			parts = append(parts, k+": "+from+" → "+to)
		}
	}
	back := fmt.Sprintf("/kpi-submissions?year=%d#u%d-m%d", row.Int("period_year"), row.Int("user_id"), row.Int("period_month"))
	if changes.Len() == 0 {
		c.Flash("success", c.T("Nothing changed."))
		c.Redirect(back)
	}
	db.Core.Update("kpi_submissions", newVals, db.Cols{{Name: "id", Value: id}})
	period := app.FormatDate(c, fmt.Sprintf("%04d-%02d-01", row.Int("period_year"), row.Int("period_month")), "F Y")
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "kpi_submission", ID: id, Label: row.Str("task_title"), Text: `Corrected the submission ":label" for :period`,
		Params: []any{"label", row["task_title"], "period", period}, Props: app.M{"changes": changes, "kpi_id": row.Int("kpi_id")}, Owner: row.Int("user_id"), NoNotify: true})
	u := c.MustUser()
	if row.Int("user_id") != u.ID {
		app.Bell(c, row.Int("user_id"), "core", "kpi_submission", c.T("Your KPI submission was corrected: :task (:period)", "task", row["task_title"], "period", period),
			strings.Join(parts, ", "), c.URL("/kpi-submissions", "year", row.Int("period_year"), "view", "mine"), u.ID)
	}
	c.Flash("success", c.T("Submission corrected."))
	c.Redirect(back)
}

func kpiDestroy(c *app.Ctx) {
	u := c.MustUser()
	id := c.ParamInt("id")
	row := kpisub.Find(u, id)
	if row == nil {
		app.Abort(404)
	}
	if !kpisub.CanDelete(u, row) {
		app.Abort(403, c.T("Only the BU manager of the department can delete a submission."))
	}
	db.Exec("UPDATE kpi_submissions SET deleted_at = now(), deleted_by = ? WHERE id = ? AND deleted_at IS NULL", u.ID, id)
	period := app.FormatDate(c, fmt.Sprintf("%04d-%02d-01", row.Int("period_year"), row.Int("period_month")), "F Y")
	app.LogActivity(c, app.ActivityEntry{Action: "deleted", Type: "kpi_submission", ID: id, Label: row.Str("task_title"), Text: `Deleted the submission ":label" for :period`,
		Params: []any{"label", row["task_title"], "period", period}, Props: app.M{"kpi_id": row.Int("kpi_id")}, Owner: row.Int("user_id"), NoNotify: true})
	if row.Int("user_id") != u.ID {
		app.Bell(c, row.Int("user_id"), "core", "kpi_submission", c.T("Your KPI submission was deleted: :task (:period)", "task", row["task_title"], "period", period),
			c.T("You can submit it again."), c.URL("/kpi-submissions/create", "year", row.Int("period_year"), "month", row.Int("period_month")), u.ID)
	}
	c.Back("success", c.T("Submission deleted. The KPI can be submitted again for that month."))
}

func kpiFile(c *app.Ctx) {
	c.Authorize("kpi_submissions", "view")
	id := c.ParamInt("id")
	if kpisub.Find(c.MustUser(), id) == nil {
		app.Abort(404)
	}
	f := db.First("SELECT * FROM kpi_submission_files WHERE id = ? AND submission_id = ?", conv.Int(c.Param("file")), id)
	if f == nil {
		app.Abort(404)
	}
	abs := kpisub.Path(f.Str("path"))
	if abs == "" {
		app.Abort(404)
	}
	data, err := os.ReadFile(abs)
	if err != nil {
		app.Abort(404)
	}
	c.W.Header().Set("Content-Length", conv.Str(len(data)))
	c.Download(f.Str("name"), "application/octet-stream", data)
}

func kpiExport(c *app.Ctx) {
	c.Authorize("kpi_submissions", "export")
	f := kpiFilters(c)
	app.LogActivity(c, app.ActivityEntry{Action: "export", Type: "kpi_submission", Text: "Exported KPI submissions for :year", Params: []any{"year", f.Year}, NoNotify: true})
	var rows [][]any
	for _, r := range kpiRows(c, f) {
		status, approved := "On time", "No"
		if r.Int("days_late") != 0 {
			status = "Late"
		}
		if r["approved_at"] != nil {
			approved = "Yes"
		}
		rows = append(rows, []any{r["period_year"], r["period_month"], status, r["days_late"], r["percentage"], r["submitter_name"], r["department_name"],
			strings.ToUpper(r.Str("task_kind")), r["task_title"], r["approver_name"], approved, r["approved_at"], r["finished"], r["total"], r["criteria"],
			r["note"], r["related_link"], r["submitted_at"]})
	}
	c.XlsxDownload(fmt.Sprintf("kpi-submissions-%d.xlsx", f.Year),
		[]string{"Year", "Month", "Status", "Days late", "Percentage", "Submitted by", "Department", "Type", "KPI / OKR", "Approver", "Approved", "Approved at", "Finished", "Total tasks", "Criteria", "Note", "Related link", "Submitted at"}, rows)
}

func init() {
	signedIn(func(r app.Router) {
		// KPI submissions: monthly KPI / OKR results (before the standard screens so /kpi-submissions/create is not taken as an id)
		r.Get("/kpi-submissions", kpiIndex)
		r.Get("/kpi-submissions/create", kpiCreate)
		r.Get("/kpi-submissions/export", kpiExport)
		r.Post("/kpi-submissions", kpiStore)
		r.Post("/kpi-submissions/approve-month", kpiApproveMonth)
		r.Post("/kpi-submissions/{id}/approve", kpiApprove)
		r.Post("/kpi-submissions/{id}/delete", kpiDestroy)
		r.Get("/kpi-submissions/{id}/edit", kpiEdit)
		r.Post("/kpi-submissions/{id}", kpiUpdate)
		r.Get("/kpi-submissions/{id}/files/{file:[0-9]+}", kpiFile)
	})
}
