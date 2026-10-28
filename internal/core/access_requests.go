package core

import (
	"html/template"
	"math"
	"net/http"
	"strings"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/conv"
	"acme/internal/db"
)

/*
Account requests (AccessRequestController). The sign-in page has a "Request" form (employee id, name, e-mail, phone, gender,
department as typed text, password). An administrator opens the request, chooses the real department and role, and approves it:
the member is created with the password hash the person chose, so the administrator never learns it. Or rejects it with a reason.
*/

// AccessRequestsEnabled: the sign-in page offers the "Request" form unless the setting is off.
func AccessRequestsEnabled() bool { return app.Setting("access_requests", "") != "0" }

// AccessRequestsPending is the number of requests waiting.
func AccessRequestsPending() int {
	return conv.Int(db.Scalar("SELECT count(*) FROM access_requests WHERE status = 'pending'"))
}

// accessRequestReply is json_response(['ok' => true, 'message' => …]) with PHP's key order.
func accessRequestReply(c *app.Ctx, message string) {
	m := config.NewMap()
	m.Set("ok", true)
	m.Set("message", message)
	c.JSON(m)
}

func init() {
	app.AccessRequestsEnabled = AccessRequestsEnabled
	guest(func(r app.Router) { r.Post("/request-access", accessRequestSubmit) })
	signedIn(func(r app.Router) {
		r.Get("/members/requests", accessRequestIndex)
		r.Get("/members/requests/{id}", accessRequestReview)
		r.Post("/members/requests/{id}/approve", accessRequestApprove)
		r.Post("/members/requests/{id}/reject", accessRequestReject)
		r.Post("/members/requests/{id}/delete", accessRequestDestroy)
	})
}

func genderKeys() string {
	var keys []string
	for _, p := range config.AsPairs(config.Get("core.employee.genders")) {
		keys = append(keys, p.Key)
	}
	return strings.Join(keys, ",")
}

// accessRequestRateHit is RateLimit::hit(): fixed one-minute windows in api_rate; false when over the limit.
func accessRequestRateHit(key string, perMinute int) bool {
	window := conv.Date("Y-m-d H:i:00", conv.Now())
	hits := conv.Int(db.Scalar(`INSERT INTO api_rate (key, window_start, hits) VALUES (?, ?, 1)
            ON CONFLICT (key, window_start) DO UPDATE SET hits = api_rate.hits + 1 RETURNING hits`, conv.Substr(key, 0, 120), window))
	if hits > perMinute {
		return false
	}
	if randInt(1, 500) == 1 { // now and then, forget old windows
		db.Exec("DELETE FROM api_rate WHERE window_start < now() - interval '10 minutes'")
	}
	return true
}

func randInt(lo, hi int) int {
	b := randHex(4)
	n := 0
	for _, ch := range b {
		n = n*16 + strings.IndexRune("0123456789abcdef", ch)
	}
	return lo + n%(hi-lo+1)
}

/* ------------------------------------------------------------ the public form */

func accessRequestSubmit(c *app.Ctx) {
	if !AccessRequestsEnabled() {
		app.Abort(http.StatusNotFound)
	}
	if strings.TrimSpace(c.Input("website")) != "" { // honeypot: bots fill every field
		accessRequestReply(c, c.T("Thank you. Your request was sent."))
	}
	d := c.ValidateInput(
		app.Rule{Field: "employee_code", Rules: "required|max:30", Label: c.T("Employee ID")},
		app.Rule{Field: "name", Rules: "required|max:120", Label: c.T("Full name")},
		app.Rule{Field: "email", Rules: "required|email|max:190", Label: c.T("Work e-mail")},
		app.Rule{Field: "phone", Rules: "required|max:30", Label: c.T("Phone")},
		app.Rule{Field: "gender", Rules: "required|in:" + genderKeys(), Label: c.T("Gender")},
		app.Rule{Field: "department_text", Rules: "required|max:120", Label: c.T("Department")},
		app.Rule{Field: "password", Rules: "required|password", Label: c.T("Password")},
		app.Rule{Field: "password_confirmation", Rules: "required|same:password", Label: c.T("Confirm password")},
	)

	// Every try counts (not only stored ones), before anything is looked up.
	if !accessRequestRateHit("access-request:"+c.IP(), 3) {
		app.Abort(http.StatusTooManyRequests, c.T("Too many requests from this network. Try again later."))
	}
	if conv.Int(db.Scalar("SELECT count(*) FROM access_requests WHERE ip_address = ? AND created_at > now() - interval '1 hour'", c.IP())) >= 5 {
		app.Abort(http.StatusTooManyRequests, c.T("Too many requests from this network. Try again later."))
	}
	thanks := c.T("Thank you. Your request was sent. An administrator will review it and e-mail you.")
	email := strings.ToLower(d.Str("email"))
	// An existing account, a waiting request or a taken employee ID get the same answer as a new request,
	// so the form cannot be used to find out who works here. The administrator sees nothing new; the person
	// with an account can use "Forgot password".
	if db.Scalar("SELECT 1 FROM users WHERE lower(email) = ? AND deleted_at IS NULL", email) != nil ||
		db.Scalar("SELECT 1 FROM access_requests WHERE lower(email) = ? AND status = 'pending'", email) != nil ||
		db.Scalar("SELECT 1 FROM employee_profiles WHERE lower(employee_code) = lower(?)", d.Str("employee_code")) != nil {
		accessRequestReply(c, thanks)
	}

	id := db.Core.InsertID("access_requests", db.Cols{
		{Name: "employee_code", Value: d.Str("employee_code")}, {Name: "name", Value: d.Str("name")}, {Name: "email", Value: email},
		{Name: "phone", Value: d.Str("phone")}, {Name: "gender", Value: d.Str("gender")}, {Name: "department_text", Value: d.Str("department_text")},
		{Name: "password_hash", Value: app.PasswordHash(d.Str("password"))}, {Name: "ip_address", Value: c.IP()}, {Name: "locale", Value: c.Locale()},
	})
	app.LogActivity(c, app.ActivityEntry{Action: "created", Type: "access_request", ID: id, Label: d.Str("name"), Text: "New account request from :name",
		Params: []any{"name", d.Str("name")}, Props: app.M{"_url": c.URL("/members/requests")}, NoNotify: true})

	// tell the people who can decide
	for _, a := range db.Select("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active AND u.deleted_at IS NULL AND r.slug = 'admin'") {
		if err := app.Catch(func() {
			app.Deliver(c, "core", "created", a.Int("id"), app.Text("New account request: :name", "name", d.Str("name")),
				app.Text(":name (:code) asked for an account. Open the request to choose department and role.", "name", d.Str("name"), "code", d.Str("employee_code")), c.URL("/members/requests"), 0)
		}); err != nil {
			app.WriteErrorLog("[access-request] notify: " + err.Error())
		}
	}
	accessRequestReply(c, thanks)
}

/* ------------------------------------------------------------------ admin */

func accessRequestReviewer(c *app.Ctx) bool {
	u := c.MustUser()
	return u.IsAdmin() || (u.Scope() == "all" && c.Can("members", "create"))
}

func accessRequestGuard(c *app.Ctx) {
	if !accessRequestReviewer(c) {
		app.Abort(http.StatusForbidden, c.T("Only an administrator can review account requests."))
	}
}

func accessRequestIndex(c *app.Ctx) {
	accessRequestGuard(c)
	status := c.Query("status")
	if !conv.InList(status, "pending", "approved", "rejected") {
		status = "pending"
	}
	q := strings.TrimSpace(c.Query("q"))
	where := "r.status = ?"
	par := []any{status}
	if q != "" {
		where += " AND (r.name ILIKE ? OR r.email ILIKE ? OR r.employee_code ILIKE ? OR r.department_text ILIKE ?)"
		for i := 0; i < 4; i++ {
			par = append(par, "%"+q+"%")
		}
	}
	per := conv.Int(c.Query("per_page"))
	if per != 10 && per != 20 && per != 50 {
		per = 10
	}
	page := max(1, conv.Int(c.Query("page", "1")))
	total := conv.Int(db.Scalar("SELECT count(*) FROM access_requests r WHERE "+where, par...))
	rows := db.Select("SELECT r.*, u.name AS reviewer FROM access_requests r LEFT JOIN users u ON u.id = r.reviewed_by WHERE "+where+
		" ORDER BY r.created_at DESC LIMIT "+conv.Str(per)+" OFFSET "+conv.Str((page-1)*per), par...)
	counts := map[string]int{}
	for _, r := range db.Select("SELECT status, count(*) n FROM access_requests GROUP BY status") {
		counts[r.Str("status")] = r.Int("n")
	}
	pages := max(1, int(math.Ceil(float64(total)/float64(per))))
	link := func(p int) string {
		args := []any{"status", status}
		if q != "" {
			args = append(args, "q", q)
		}
		if per != 10 {
			args = append(args, "per_page", per)
		}
		args = append(args, "page", p)
		return c.URL("/members/requests", args...)
	}
	type tab struct {
		Key, Label, URL string
		N               int
	}
	var tabs []tab
	for _, t := range [][2]string{{"pending", c.T("Waiting")}, {"approved", c.T("Approved")}, {"rejected", c.T("Rejected")}} {
		tabs = append(tabs, tab{Key: t[0], Label: t[1], URL: c.URL("/members/requests", "status", t[0]), N: counts[t[0]]})
	}
	c.View("members/requests", app.M{"title": c.T("Account requests"), "rows": rows, "total": total, "status": status, "q": q, "per": per, "page": page,
		"pages": pages, "tabs": tabs, "prevURL": link(page - 1), "nextURL": link(page + 1)})
}

func accessRequestReview(c *app.Ctx) {
	accessRequestGuard(c)
	r := db.First("SELECT * FROM access_requests WHERE id = ?", c.ParamInt("id"))
	if r == nil {
		app.Abort(http.StatusNotFound)
	}
	u := c.MustUser()
	// pre-select the department whose name or code matches what the person typed
	guess := ""
	t := strings.ToLower(strings.TrimSpace(r.Str("department_text")))
	for _, dep := range db.Select("SELECT id, name, code FROM departments WHERE deleted_at IS NULL") {
		n := strings.ToLower(dep.Str("name"))
		if t != "" && (n == t || strings.ToLower(dep.Str("code")) == t || strings.Contains(n, t) || strings.Contains(t, n)) {
			guess = dep.Str("id")
			break
		}
	}
	c.View("members/request-review", app.M{"title": c.T("Account request"), "r": r, "departments": app.OptionsDepartments(u), "guess": guess,
		"roles": translatedOpts(c, app.OptionsRoles(u)), "teams": app.OptionsTeams(u), "people": app.OptionsUsers(c, u, true), "genders": app.EnumOptions(c, "genders")})
}

func accessRequestApprove(c *app.Ctx) {
	accessRequestGuard(c)
	r := db.First("SELECT * FROM access_requests WHERE id = ? AND status = 'pending'", c.ParamInt("id"))
	if r == nil {
		app.Abort(http.StatusNotFound, c.T("This request was already reviewed."))
	}
	u := c.MustUser()
	d := c.ValidateInput(
		app.Rule{Field: "name", Rules: "required|max:120", Label: c.T("Full name")},
		app.Rule{Field: "email", Rules: "required|email|max:190", Label: c.T("Work e-mail")},
		app.Rule{Field: "employee_code", Rules: "required|max:30", Label: c.T("Employee ID")},
		app.Rule{Field: "phone", Rules: "nullable|max:30"},
		app.Rule{Field: "gender", Rules: "nullable|in:" + genderKeys()},
		app.Rule{Field: "department_id", Rules: "required", Label: c.T("Department")},
		app.Rule{Field: "role_id", Rules: "required", Label: c.T("Role")},
		app.Rule{Field: "team_id", Rules: "nullable"},
		app.Rule{Field: "manager_id", Rules: "nullable"},
		app.Rule{Field: "job_title", Rules: "nullable|max:120"},
	)

	if _, ok := app.OptLabel(app.OptionsDepartments(u), conv.Str(d.Int("department_id"))); !ok {
		app.Invalid("department_id", c.T("Choose a department from the list."))
	}
	if _, ok := app.OptLabel(app.OptionsRoles(u), conv.Str(d.Int("role_id"))); !ok {
		app.Invalid("role_id", c.T("Choose a role from the list."))
	}
	email := strings.ToLower(d.Str("email"))
	if db.Scalar("SELECT 1 FROM users WHERE lower(email) = ? AND deleted_at IS NULL", email) != nil {
		app.Invalid("email", c.T("This e-mail already has an account."))
	}
	if db.Scalar("SELECT 1 FROM employee_profiles WHERE lower(employee_code) = lower(?)", d.Str("employee_code")) != nil {
		app.Invalid("employee_code", c.T("This employee ID already belongs to a member."))
	}
	if !conv.Empty(d.Get("team_id")) {
		td := db.Scalar("SELECT department_id FROM teams WHERE id = ? AND deleted_at IS NULL", d.Get("team_id"))
		if conv.Int(td) != d.Int("department_id") {
			app.Invalid("team_id", c.T("This team belongs to another department."))
		}
	}
	orNil := func(v any) any {
		if conv.Empty(v) {
			return nil
		}
		return v
	}

	uid := 0
	db.Core.Transaction(func(tx db.Q) {
		uid = tx.InsertID("users", db.Cols{
			{Name: "name", Value: d.Str("name")}, {Name: "email", Value: email}, {Name: "password", Value: r["password_hash"]},
			{Name: "role_id", Value: d.Int("role_id")}, {Name: "department_id", Value: d.Int("department_id")},
			{Name: "team_id", Value: orNil(d.Get("team_id"))}, {Name: "manager_id", Value: orNil(d.Get("manager_id"))}, {Name: "is_active", Value: true},
			{Name: "locale", Value: orNil(r["locale"])},
		})
		tx.Exec("INSERT INTO employee_profiles (user_id, employee_code, job_title, phone, gender) VALUES (?, ?, ?, ?, ?)",
			uid, d.Str("employee_code"), orNil(d.Get("job_title")), orNil(d.Get("phone")), orNil(d.Get("gender")))
		tx.Exec("UPDATE access_requests SET status = 'approved', user_id = ?, reviewed_by = ?, reviewed_at = now(), password_hash = NULL WHERE id = ?", uid, u.ID, r["id"])
	})
	app.LogActivity(c, app.ActivityEntry{Action: "created", Type: "member", ID: uid, Label: d.Str("name"), Text: `Created member ":label" from an account request`,
		Params: []any{"label", d.Str("name")}, Props: app.M{"_url": c.URL("/members/" + conv.Str(uid))}})
	accessRequestMail(c, r, d.Str("name"), email, c.T("Your account is ready"),
		[]template.HTML{app.HTML(c.T("Your request was approved. You can sign in now with the e-mail and password you chose."))},
		&app.MailButton{Label: c.T("Sign in"), URL: c.URL("/login")})

	c.Flash("success", c.T(":name is now a member.", "name", d.Str("name")))
	c.Redirect("/members/requests")
}

func accessRequestReject(c *app.Ctx) {
	accessRequestGuard(c)
	r := db.First("SELECT * FROM access_requests WHERE id = ? AND status = 'pending'", c.ParamInt("id"))
	if r == nil {
		app.Abort(http.StatusNotFound, c.T("This request was already reviewed."))
	}
	why := strings.TrimSpace(c.Input("reason"))
	var reason any
	if why != "" {
		reason = conv.Substr(why, 0, 300)
	}
	db.Exec("UPDATE access_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = now(), reject_reason = ?, password_hash = NULL WHERE id = ?", c.UserID(), reason, r["id"])
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "access_request", ID: r.Int("id"), Label: r.Str("name"), Text: "Rejected the account request of :name",
		Params: []any{"name", r.Str("name")}, NoNotify: true})
	lines := []template.HTML{app.HTML(c.T("Your account request was not approved."))}
	if why != "" {
		lines = append(lines, app.HTML(app.E(why)))
	}
	lines = append(lines, app.HTML(c.T("Please contact your administrator if you think this is a mistake.")))
	accessRequestMail(c, r, r.Str("name"), r.Str("email"), c.T("About your account request"), lines, nil)
	c.Flash("success", c.T("Request rejected."))
	c.Redirect("/members/requests")
}

func accessRequestDestroy(c *app.Ctx) {
	accessRequestGuard(c)
	db.Exec("DELETE FROM access_requests WHERE id = ? AND status <> 'pending'", c.ParamInt("id"))
	c.Flash("success", c.T("Removed."))
	c.Redirect("/members/requests?status=" + conv.OrStr(c.Input("status"), "approved"))
}

// accessRequestMail: the texts come translated by the caller (as in PHP); the mail's frame uses the person's language.
func accessRequestMail(c *app.Ctx, r db.Row, name, email, subject string, lines []template.HTML, button *app.MailButton) {
	if err := app.Catch(func() {
		c.With(r.Str("locale"), func() { app.Notify(c, email, name, subject, lines, button, "") })
	}); err != nil {
		app.WriteErrorLog("[access-request] mail: " + err.Error())
	}
}
