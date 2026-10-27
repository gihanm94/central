package machines

import (
	"html/template"
	"net/url"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

/*
Checklists (ChecklistController). Anybody who uses a machine scans its QR code and does the GENERAL check.
The responsible person also does a periodic re-check (weekly or monthly) that goes to the supervisor and then the manager for approval.
*/
var checklists = &app.Resource{
	Resource: "machines_checklists", Table: "checklist_record", Type: "machine_checklist", Singular: "checklist", Plural: "checklists",
	Base: "/machines/checklists", Icon: "check", NoSoftDelete: true, OrderBy: "t.created_at DESC, t.id DESC", Module: "machines", Conn: conn,
	Readonly:   true, // no generic Add / Import: checks are made by scanning
	Searchable: []string{"t.machine_code", "t.machine_name", "t.user_name", "t.machine_note"},
	SelectSQL: func(c *app.Ctx) string {
		return "SELECT t.*, m.id AS machine_id, m.image AS machine_image FROM checklist_record t LEFT JOIN machine m ON m.machine_code = t.machine_code"
	},
	// records of machines the person sees, plus everything they did or must approve
	ScopeSQL: func(c *app.Ctx) (string, []any) {
		u := c.MustUser()
		if u.Scope() == "all" {
			return "TRUE", nil
		}
		s, p := scope(u, "m")
		return "(t.created_by = ? OR t.supervisor = ? OR t.manager = ? OR (m.id IS NOT NULL AND " + s + "))", append([]any{u.ID, u.ID, u.ID}, p...)
	},
	Hydrate:   func(c *app.Ctx, rows []db.Row) []db.Row { return withPeople(rows, "supervisor", "manager") },
	Label:     func(c *app.Ctx, row db.Row) string { return row.Str("machine_code") + " · " + fmtDate(c, row["created_at"], "d M Y H:i") },
	Owner:     func(row db.Row) int { return row.Int("created_by") },
	CanModify: func(c *app.Ctx, row db.Row, action string) bool { return c.MustUser().IsAdmin() },
	Filters: func(c *app.Ctx) []app.Filter {
		me := php.Str(c.MustUser().ID)
		return []app.Filter{
			{Name: "view", Label: c.T("All checklists"), Options: []app.Opt{{Value: "mine", Label: c.T("Done by me")}, {Value: "approve", Label: c.T("Waiting for my approval")}},
				SQL: "(CASE ? WHEN 'mine' THEN t.created_by = " + me + " ELSE ((t.checklist_status = 'PENDING SUPERVISOR' AND t.supervisor = " + me + ") OR (t.checklist_status = 'PENDING MANAGER' AND t.manager = " + me + ")) END)"},
			{Name: "status", Label: c.T("All statuses"), Options: []app.Opt{{Value: "COMPLETED", Label: c.T("Completed")}, {Value: "PENDING SUPERVISOR", Label: c.T("Pending supervisor")}, {Value: "PENDING MANAGER", Label: c.T("Pending manager")},
				{Value: "PENDING SUPERVISOR-OVERDUE", Label: c.T("Supervisor overdue")}, {Value: "PENDING MANAGER-OVERDUE", Label: c.T("Manager overdue")}}, Column: "t.checklist_status"},
			{Name: "kind", Label: c.T("All kinds"), Options: []app.Opt{{Value: "general", Label: c.T("General check")}, {Value: "recheck", Label: c.T("Re-check (approval)")}, {Value: "maintenance", Label: c.T("Maintenance")}},
				SQL: "(CASE ? WHEN 'maintenance' THEN t.check_type = 'MAINTENANCE' WHEN 'recheck' THEN t.recheck ELSE (t.check_type = 'GENERAL' AND NOT t.recheck) END)"},
			{Name: "mstat", Label: c.T("Any machine status"), Options: trOpts(c, statuses), Column: "t.machine_status"},
			{Name: "from", Label: c.T("Any date"), Options: []app.Opt{{Value: "today", Label: c.T("Today")}, {Value: "week", Label: c.T("Last 7 days")}, {Value: "month", Label: c.T("Last 30 days")}},
				SQL: "t.created_at >= (CASE ? WHEN 'today' THEN CURRENT_DATE WHEN 'week' THEN CURRENT_DATE - 6 ELSE CURRENT_DATE - 29 END)"},
		}
	},
	Fields: func(c *app.Ctx, row db.Row) []app.Field { return nil }, // checks are not typed into a generic form
	Columns: func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "date", Label: c.T("Date"), Primary: true, Sort: "t.created_at", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return app.HTML(`<span class="tabular-nums">` + esc(fmtDate(c, r["created_at"], "d M Y")) + `</span><span class="block text-xs text-steel">` + esc(fmtDate(c, r["created_at"], "H:i")) + `</span>`)
			}},
			{Key: "machine", Label: c.T("Machine"), Sort: "t.machine_code", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return app.HTML(`<span class="font-medium tabular-nums">` + esc(r["machine_code"]) + `</span><span class="block text-xs text-steel">` + esc(r["machine_name"]) + `</span>`)
			}},
			{Key: "kind", Label: c.T("Kind"), Render: func(c *app.Ctx, r db.Row) template.HTML { return kindPill(c, r, c.T("General")) }},
			{Key: "by", Label: c.T("Checked by"), Sort: "t.user_name", Render: func(c *app.Ctx, r db.Row) template.HTML { return app.HTML(esc(php.Coalesce(r["user_name"], "—"))) }},
			{Key: "mstatus", Label: c.T("Machine"), Render: func(c *app.Ctx, r db.Row) template.HTML { return pillH(c, r["machine_status"], "") }},
			{Key: "status", Label: c.T("Approval"), Sort: "t.checklist_status", Render: func(c *app.Ctx, r db.Row) template.HTML {
				s := pill(c, r["checklist_status"], "")
				if strings.HasPrefix(r.Str("checklist_status"), "PENDING") {
					who := php.Coalesce(r["manager_name"], "")
					if r.Str("checklist_status") == "PENDING SUPERVISOR" {
						who = php.Coalesce(r["supervisor_name"], "")
					}
					s += `<span class="mt-0.5 block text-xs text-steel">` + esc(c.T("waiting for :n", "n", who)) + `</span>`
				}
				return app.HTML(s)
			}},
		}
	},
	HeaderActions: func(c *app.Ctx) template.HTML {
		if !c.Can("machines_checklists", "create") {
			return ""
		}
		return app.HTML(`<a href="` + esc(c.URL("/machines/scan")) + `" class="btn-dark">` + string(app.Icon("qr", "size-4")) + ` ` + esc(c.T("Scan QR")) + `</a>`)
	},
	TopExtra: func(c *app.Ctx) template.HTML {
		me := c.MustUser().ID
		n := php.Int(mdb().Scalar("SELECT count(*) FROM checklist_record WHERE (checklist_status = 'PENDING SUPERVISOR' AND supervisor = ?) OR (checklist_status = 'PENDING MANAGER' AND manager = ?)", me, me))
		if n == 0 {
			return ""
		}
		return app.HTML(`<a href="` + esc(c.URL("/machines/checklists", "view", "approve")) + `" class="mt-4 flex items-center gap-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-600/20 hover:bg-amber-100">` +
			string(app.Icon("clock", "size-5")) + `<span><strong>` + esc(c.T(":n waiting for your approval", "n", n)) + `</strong> · ` + esc(c.T("Open them")) + `</span></a>`)
	},
}

func kindPill(c *app.Ctx, r db.Row, generalLabel string) template.HTML {
	if r.Str("check_type") == "MAINTENANCE" {
		return pillH(c, c.T("Maintenance"), "info")
	}
	if r.Bool("recheck") {
		return pillH(c, c.T("Re-check"), "warn")
	}
	return pillH(c, generalLabel, "neutral")
}

func checklistExport(c *app.Ctx) {
	r := checklists
	c.Authorize(r.Resource, "export")
	rows, _ := r.Query(c, false)
	app.LogActivity(c, app.ActivityEntry{Action: "export", Type: r.Type, Label: "Checklists", Text: "Exported :count :type", Params: []any{"count", len(rows), "type", r.Plural}, Props: app.M{"count": len(rows)}, Module: "machines"})
	out := make([][]any, 0, len(rows))
	for _, x := range rows {
		kind := "general"
		if x.Str("check_type") == "MAINTENANCE" {
			kind = "maintenance"
		} else if x.Bool("recheck") {
			kind = "recheck"
		}
		out = append(out, []any{x["id"], x["created_at"], x["machine_code"], x["machine_name"], kind, x["user_name"], x["machine_status"], x["checklist_status"], php.Coalesce(x["supervisor_name"], ""), php.Coalesce(x["manager_name"], ""), x["machine_note"]})
	}
	c.XlsxDownload("checklists-"+php.Date("Ymd-His", php.Now())+".xlsx", []string{"id", "date", "machine_code", "machine_name", "kind", "checked_by", "machine_status", "approval_status", "supervisor", "manager", "note"}, out)
}

/* ----------------------------------------------------------------- do a check */

// statusChoice is one big machine-status button of a check form.
type statusChoice struct{ Value, Label, Class string }

func statusChoices(c *app.Ctx, _ string) []statusChoice {
	return []statusChoice{
		{"OPERATIONAL", c.T("Operational"), "peer-checked:bg-emerald-600 peer-checked:text-white peer-checked:ring-emerald-600"},
		{"UNDER MAINTENANCE", c.T("Under maintenance"), "peer-checked:bg-amber-500 peer-checked:text-white peer-checked:ring-amber-500"},
	}
}

// questionView is one question of machines/_questions with the answer posted before.
type questionView struct {
	N, ID              int
	Detail, Desc       string
	Choice             bool
	Ans, Remark        string
}

func questionsView(c *app.Ctx, items []db.Row) []questionView {
	old := map[string]any{}
	if o, ok := c.Sess.Old("answers"); ok {
		if m, ok := o.(map[string]any); ok {
			old = m
		}
	}
	out := make([]questionView, 0, len(items))
	for n, i := range items {
		o, _ := rowOf(old[i.Str("id")])
		out = append(out, questionView{N: n + 1, ID: i.Int("id"), Detail: i.Str("detail"), Desc: i.Str("description"), Choice: i.Bool("is_choice"), Ans: php.Str(o["answer"]), Remark: php.Str(o["remark"])})
	}
	return out
}

// checklistCreate: pick a machine (search or scan) or, with ?machine=CODE, the check form.
func checklistCreate(c *app.Ctx) {
	c.Authorize("machines_checklists", "create")
	code := strings.TrimSpace(c.Query("machine", ""))
	if code == "" {
		c.Redirect("/machines/scan")
	}
	m := machine(code)
	if m == nil || !isActive(m["machine_status"]) {
		c.Flash("error", c.T("This machine was not found or is no longer in use."))
		c.Redirect("/machines/scan")
	}
	u := c.MustUser()
	p := plan(m, u, php.Now())
	ppl := names(m["responsible_person_id"], m["supervisor_id"], m["manager_id"])
	who := ""
	if !php.Empty(m["supervisor_id"]) {
		who = php.Str(nameOr(ppl, m["supervisor_id"], c.T("your supervisor")))
	} else {
		who = php.Str(nameOr(ppl, m["manager_id"], c.T("your manager")))
	}
	c.View("machines/check-new", app.M{"title": c.T("Check :code", "code", m["machine_code"]), "m": m, "plan": p, "who": who,
		"weekendNote": m.Int("responsible_person_id") == u.ID && p.Weekend,
		"typeLine":    strings.Trim(m.Str("machine_group_name")+" / "+m.Str("machine_type_name"), " /"),
		"mstat":       php.Str(oldOr(c, "machine_status", m["machine_status"])), "statusChoices": statusChoices(c, ""),
		"questions": questionsView(c, p.Items), "err": c.Sess.Errors().Get("answers"), "note": php.Str(oldOr(c, "machine_note", ""))})
}

// saveCheck stores a check from the request (machine_code, machine_status, answers, machine_note, image) and returns its id.
func saveCheck(c *app.Ctx) int {
	c.Authorize("machines_checklists", "create")
	u := c.MustUser()
	m := machine(c.Input("machine_code"))
	if m == nil {
		app.Invalid("machine_code", c.T("Machine not found."))
	}
	if !isActive(m["machine_status"]) {
		app.Invalid("machine_code", c.T("This machine is no longer in use."))
	}
	status := c.Input("machine_status")
	if !isActive(status) {
		app.Invalid("machine_status", c.T("Choose the machine status: operational or under maintenance."))
	}
	p := plan(m, u, php.Now())
	ans := answers(c, p.Items, c.InputAny("answers"))

	var image any
	if file := c.File("image"); file != nil {
		image = app.UploadImage(c, file, "machines", 2048, false)
	}
	cstatus := "COMPLETED"
	if p.Recheck {
		cstatus = "PENDING MANAGER"
		if !php.Empty(m["supervisor_id"]) {
			cstatus = "PENDING SUPERVISOR"
		}
	}
	var note any
	if n := strings.TrimSpace(c.Input("machine_note")); n != "" {
		note = n
	}
	var id int
	mdb().Transaction(func(tx db.Q) {
		id = tx.InsertID("checklist_record", db.Cols{
			{Name: "check_type", Value: "GENERAL"}, {Name: "recheck", Value: p.Recheck}, {Name: "machine_code", Value: m["machine_code"]}, {Name: "machine_name", Value: m["machine_name"]}, {Name: "machine_status", Value: status},
			{Name: "machine_checklist", Value: phpJSON(ans, flagUnicode)}, {Name: "machine_note", Value: note}, {Name: "image", Value: image},
			{Name: "user_id", Value: u.Email}, {Name: "user_name", Value: u.Name}, {Name: "created_by", Value: u.ID}, {Name: "updated_by", Value: u.ID},
			{Name: "supervisor", Value: m["supervisor_id"]}, {Name: "manager", Value: m["manager_id"]},
			{Name: "checklist_status", Value: cstatus}, {Name: "reason_not_checked", Value: nil}, {Name: "job_detail", Value: nil},
		})
		if p.Recheck {
			var ids []string
			for _, a := range ans {
				if php.Str(a.ResetTime) != general && a.ID != 0 {
					ids = append(ids, php.Str(a.ID))
				}
			}
			if len(ids) > 0 {
				tx.Exec("UPDATE machine_checklist SET check_status = TRUE, updated_at = now() WHERE id IN (" + strings.Join(ids, ",") + ")")
			}
			tx.Exec("UPDATE machine SET check_status = ?, machine_status = ?, updated_at = now() WHERE id = ?", cstatus, status, m["id"])
		} else {
			tx.Exec("UPDATE machine SET machine_status = ?, updated_at = now() WHERE id = ?", status, m["id"])
		}
	})
	kind := "general"
	if p.Recheck {
		kind = "recheck"
	}
	app.LogActivity(c, app.ActivityEntry{Action: "created", Type: "machine_checklist", ID: id, Label: m.Str("machine_code"), Text: "Checked machine :code", Params: []any{"code", m["machine_code"]},
		Props: app.M{"_url": c.URL("/machines/checklists/" + php.Str(id)), "attributes": om("machine_status", status, "kind", kind)}, Owner: m.Int("responsible_person_id"), Module: "machines"})
	bad := 0
	for _, a := range ans {
		if a.Answer == "NG" {
			bad++
		}
	}
	notifyAfterCheck(c, m, p.Recheck, cstatus, status, id, bad, u)
	if p.Recheck {
		c.Flash("success", c.T("Saved. It is now waiting for approval."))
	} else {
		c.Flash("success", c.T("Checklist saved. Thank you!"))
	}
	return id
}

func checklistStore(c *app.Ctx) {
	id := saveCheck(c)
	if c.IsJSON() {
		c.JSON(app.M{"ok": true, "id": id, "url": c.URL("/machines/checklists/" + php.Str(id))})
	}
	c.Redirect("/machines/checklists/" + php.Str(id))
}

/* ------------------------------------------------------------------ detail */

// shownAnswer is one saved answer for the detail page.
type shownAnswer struct {
	Detail, Remark, V string
	Choice            bool
}

func checklistShow(c *app.Ctx) {
	r := checklists
	c.Authorize(r.Resource, "view")
	rec := r.Find(c, c.ParamInt("id"))
	u := c.MustUser()
	var ans []shownAnswer
	ng := 0
	for _, a := range jsonList(rec["machine_checklist"]) {
		v := php.Str(a["answer"])
		if v == "NG" {
			ng++
		}
		ans = append(ans, shownAnswer{Detail: php.Str(a["detail"]), Remark: php.Str(a["remark"]), V: v, Choice: !php.Empty(a["is_choice"])})
	}
	m := machine(rec.Str("machine_code"))
	title := r.LabelOf(c, rec)
	steps := chain(c, rec)
	var stepsView []app.M
	tone := map[string]string{"done": "bg-emerald-600 text-white", "now": "bg-amber-500 text-white", "late": "bg-red-600 text-white", "waiting": "bg-graphite-900/10 text-steel"}
	for i, s := range steps {
		when := map[string]string{"now": c.T("waiting"), "late": c.T("overdue"), "waiting": "—"}[s.State]
		if !php.Empty(s.At) {
			when = fmtDate(c, s.At, "d M H:i")
		}
		stepsView = append(stepsView, app.M{"n": i + 1, "label": s.Label, "who": s.Who, "when": when, "state": s.State, "tone": tone[s.State]})
	}
	mlink := "#"
	if m != nil {
		mlink = c.URL("/machines/m/" + url.PathEscape(rec.Str("machine_code")))
	}
	by := nameOr(names(rec["created_by"]), rec["created_by"], rec["user_name"])
	var pills []template.HTML
	pills = append(pills, kindPill(c, rec, c.T("General check")), pillH(c, rec["checklist_status"], ""), pillH(c, rec["machine_status"], ""))
	c.View("machines/check-show", app.M{
		"title": title, "r": rec, "answers": ans, "c": r.Meta(c), "mlink": mlink, "pills": pills, "ng": ng,
		"ngPill":     pillH(c, c.T(":n NG", "n", ng), "bad"),
		"canApprove": canApprove(rec, u), "canDelete": c.Can(r.Resource, "delete") && u.IsAdmin(),
		"chain": stepsView, "by": php.Str(by),
		"emptyText":  php.Str(php.Or(rec["reason_not_checked"], c.T("No answers were saved."))),
		"showNotes":  !php.Empty(rec["machine_note"]) || !php.Empty(rec["job_detail"]) || !php.Empty(rec["image"]) || (len(ans) > 0 && !php.Empty(rec["reason_not_checked"])),
		"choicePill": func(v string) template.HTML { return answerPill(c, v) },
	})
}

func answerPill(c *app.Ctx, v string) template.HTML {
	tone := "neutral"
	if v == "OK" {
		tone = "ok"
	} else if v == "NG" {
		tone = "bad"
	}
	if v == "NA" {
		v = "N/A"
	}
	return pillH(c, v, tone)
}

// approve: the supervisor, then the manager, approves a re-check. Returns the new status.
func approveCheck(c *app.Ctx, id int, rec db.Row) string {
	u := c.MustUser()
	note := strings.TrimSpace(c.Input("note"))
	mdb().Transaction(func(tx db.Q) {
		var next string
		if rec.Str("checklist_status") == "PENDING SUPERVISOR" {
			next = "COMPLETED"
			if !php.Empty(rec["manager"]) {
				next = "PENDING MANAGER"
			}
			tx.Exec("UPDATE checklist_record SET checklist_status = ?, date_supervisor_checked = now(), updated_by = ?, updated_at = now(), reason_not_checked = COALESCE(NULLIF(?, ''), reason_not_checked) WHERE id = ?", next, u.ID, note, rec["id"])
		} else {
			next = "COMPLETED"
			tx.Exec("UPDATE checklist_record SET checklist_status = ?, date_manager_checked = now(), updated_by = ?, updated_at = now(), reason_not_checked = COALESCE(NULLIF(?, ''), reason_not_checked) WHERE id = ?", next, u.ID, note, rec["id"])
		}
		// the machine follows the latest re-check while it is still waiting for approval
		tx.Exec("UPDATE machine SET check_status = ?, updated_at = now() WHERE machine_code = ? AND check_status IN ('PENDING SUPERVISOR', 'PENDING MANAGER') AND NOT EXISTS (SELECT 1 FROM checklist_record c WHERE c.machine_code = machine.machine_code AND c.recheck AND c.id > ?)", next, rec["machine_code"], rec["id"])
	})
	after := mdb().First("SELECT checklist_status FROM checklist_record WHERE id = ?", id)
	ch := &app.Changes{}
	ch.Set("status", app.Change{From: rec["checklist_status"], To: after["checklist_status"]})
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "machine_checklist", ID: id, Label: rec.Str("machine_code"), Text: "Approved the check of :code", Params: []any{"code", rec["machine_code"]},
		Props: app.M{"changes": ch, "_url": c.URL("/machines/checklists/" + php.Str(id))}, Owner: rec.Int("created_by"), Module: "machines"})
	notifyApproval(c, rec, after.Str("checklist_status"), u)
	if after.Str("checklist_status") == "COMPLETED" {
		c.Flash("success", c.T("Approved. The checklist is complete."))
	} else {
		c.Flash("success", c.T("Approved. It now goes to the manager."))
	}
	return after.Str("checklist_status")
}

func checklistApprove(c *app.Ctx) {
	r := checklists
	c.Authorize(r.Resource, "view")
	id := c.ParamInt("id")
	rec := r.Find(c, id)
	if !canApprove(rec, c.MustUser()) {
		c.Back("error", c.T("This checklist is not waiting for you."))
	}
	approveCheck(c, id, rec)
	c.Redirect("/machines/checklists/" + php.Str(id))
}
