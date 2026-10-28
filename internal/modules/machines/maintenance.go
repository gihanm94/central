package machines

import (
	"html/template"
	"strings"

	"acme/internal/app"
	"acme/internal/conv"
	"acme/internal/db"
)

// Planned maintenance rounds of every machine, and doing them with the machine's maintenance checklist (MaintenanceController).
var maintenance = &app.Resource{
	Resource: "machines_maintenance", Table: "maintenance_record", Type: "machine_maintenance", Singular: "maintenance", Plural: "maintenance",
	Base: "/machines/maintenance", Icon: "tools", NoSoftDelete: true, OrderBy: "t.due_date ASC NULLS LAST, t.id", Module: "machines", Conn: conn,
	Searchable: []string{"t.machine_code", "t.machine_name", "t.note"},
	SelectSQL: func(c *app.Ctx) string {
		return "SELECT t.*, m.id AS machine_id, m.department_id, m.responsible_person_id, m.supervisor_id, m.manager_id, m.machine_status, m.machine_name AS m_name FROM maintenance_record t LEFT JOIN machine m ON m.machine_code = t.machine_code"
	},
	ScopeSQL: func(c *app.Ctx) (string, []any) {
		u := c.MustUser()
		if u.Scope() == "all" {
			return "NOT t.is_canceled", nil
		}
		s, p := scope(u, "m")
		return "(NOT t.is_canceled AND (t.responsible_maintenance = ? OR (m.id IS NOT NULL AND " + s + ")))", append([]any{u.ID}, p...)
	},
	Hydrate: func(c *app.Ctx, rows []db.Row) []db.Row {
		rows = withDept(withPeople(rows, "responsible_maintenance"))
		for _, r := range rows {
			r["state"] = maintState(r)
		}
		return rows
	},
	Label: func(c *app.Ctx, row db.Row) string {
		return row.Str("machine_code") + " · " + row.Str("years") + " #" + conv.Str(conv.Coalesce(row["round"], "?"))
	},
	Owner:     func(row db.Row) int { return row.Int("responsible_person_id") },
	CanModify: maintCanModify,
	Filters: func(c *app.Ctx) []app.Filter {
		var years []app.Opt
		for _, r := range mdb().Select("SELECT DISTINCT years FROM maintenance_record ORDER BY years DESC") {
			years = append(years, app.Opt{Value: r.Str("years"), Label: r.Str("years")})
		}
		today := conv.Today()
		return []app.Filter{
			{Name: "year", Label: c.T("All years"), Options: years, Column: "t.years"},
			{Name: "state", Label: c.T("All states"), Options: []app.Opt{{Value: "planned", Label: c.T("Planned")}, {Value: "overdue", Label: c.T("Overdue")}, {Value: "completed", Label: c.T("Done on time")}, {Value: "late", Label: c.T("Done late")}},
				SQL: "(CASE ? WHEN 'planned' THEN t.actual_date IS NULL AND (t.due_date IS NULL OR t.due_date >= '" + today + "') WHEN 'overdue' THEN t.actual_date IS NULL AND t.due_date < '" + today + "' WHEN 'completed' THEN t.actual_date IS NOT NULL AND (t.due_date IS NULL OR t.actual_date <= t.due_date) ELSE t.actual_date IS NOT NULL AND t.actual_date > t.due_date END)"},
			{Name: "dept", Label: c.T("All departments"), Options: departments(), Column: "m.department_id"},
			{Name: "by", Label: c.T("Internal and external"), Options: trOpts(c, maintBy), Column: "t.maintenance_by"},
			{Name: "type", Label: c.T("All types"), Options: trOpts(c, maintTypes), Column: "t.maintenance_type"},
			{Name: "mine", Label: c.T("All rounds"), Options: []app.Opt{{Value: "1", Label: c.T("Machines I look after")}}, SQL: "(m.responsible_person_id = ? OR t.responsible_maintenance = ?)", Bind: func(string) any { return c.MustUser().ID }},
		}
	},
	Fields: func(c *app.Ctx, row db.Row) []app.Field {
		var f []app.Field
		if row == nil {
			f = append(f, app.Field{Name: "machine_code", Label: c.T("Machine"), Type: "select", Options: activeMachineOptions(c, false), Rules: "required", Default: queryOrNil(c, "machine"), Span: 2})
		}
		return append(f,
			app.Field{Name: "maintenance_type", Label: c.T("Type"), Type: "select", Options: trOpts(c, maintTypes), Rules: "required", Default: "PREVENTIVE", Search: boolPtr(false)},
			app.Field{Name: "due_date", Label: c.T("Due date"), Type: "date", Rules: "required|date"},
			app.Field{Name: "plan_date", Label: c.T("Planned date"), Type: "date", Rules: "nullable|date"},
			app.Field{Name: "start_date", Label: c.T("Started"), Type: "date", Rules: "nullable|date"},
			app.Field{Name: "maintenance_by", Label: c.T("Done by"), Type: "select", Options: trOpts(c, maintBy), Rules: "nullable", Search: boolPtr(false)},
			app.Field{Name: "responsible_maintenance", Label: c.T("Person doing the maintenance"), Type: "select", Options: people(), Rules: "nullable", Display: func(r db.Row) any { return conv.Coalesce(r["responsible_maintenance_name"], "") }},
			app.Field{Name: "note", Label: c.T("Note"), Type: "textarea", Rules: "nullable|max:3000", Span: 2},
			filesField(c, "docs", c.T("Documents"), "attachment", row, app.Field{}),
		)
	},
	Columns: func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "machine", Label: c.T("Machine"), Primary: true, Sort: "t.machine_code", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return app.HTML(`<span class="font-medium tabular-nums">` + esc(r["machine_code"]) + `</span><span class="block text-xs text-steel">` + esc(conv.Coalesce(r["machine_name"], r["m_name"])) + `</span>`)
			}},
			{Key: "round", Label: c.T("Round"), Sort: "t.years", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return app.HTML(esc(r["years"]) + ` <span class="text-steel">#` + esc(conv.Coalesce(r["round"], "—")) + `</span><span class="block text-xs text-steel">` + esc(trLabel(c, maintTypes, r["maintenance_type"])) + `</span>`)
			}},
			{Key: "due", Label: c.T("Due"), Sort: "t.due_date", Render: func(c *app.Ctx, r db.Row) template.HTML {
				s := esc(dateOrDash(c, r["due_date"]))
				if !conv.Empty(r["plan_date"]) {
					s += `<span class="block text-xs text-steel">` + esc(c.T("plan")) + ` ` + esc(fmtDate(c, r["plan_date"])) + `</span>`
				}
				return app.HTML(s)
			}},
			{Key: "done", Label: c.T("Done"), Sort: "t.actual_date", Render: func(c *app.Ctx, r db.Row) template.HTML { return app.HTML(esc(dateOrDash(c, r["actual_date"]))) }},
			{Key: "state", Label: c.T("Status"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				switch r.Str("state") {
				case "completed":
					return pillH(c, c.T("Done on time"), "ok")
				case "late":
					return pillH(c, c.T("Done late"), "warn")
				case "overdue":
					return pillH(c, c.T("Overdue"), "bad")
				}
				return pillH(c, c.T("Planned"), "neutral")
			}},
			{Key: "by", Label: c.T("Done by"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				s := "—"
				if !conv.Empty(r["maintenance_by"]) {
					s = trLabel(c, maintBy, r["maintenance_by"])
				}
				h := esc(s)
				if !conv.Empty(r["responsible_maintenance_name"]) {
					h += `<span class="block text-xs text-steel">` + esc(r["responsible_maintenance_name"]) + `</span>`
				}
				return app.HTML(h)
			}},
			{Key: "dept", Label: c.T("Department"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				return app.HTML(esc(conv.Coalesce(r["department_name"], "—")))
			}},
		}
	},
	RowLinks: func(c *app.Ctx, row db.Row) []app.Link {
		if conv.Empty(row["actual_date"]) && maintCanModify(c, row, "edit") && c.Can("machines_maintenance", "edit") {
			return []app.Link{{URL: "/machines/maintenance/" + row.Str("id") + "/perform", Label: c.T("Do the maintenance"), Icon: "tools"}}
		}
		return nil
	},
	Prepare: func(c *app.Ctx, data *app.Clean, existing db.Row) *app.Clean {
		if existing == nil {
			m := machine(data.Str("machine_code"))
			if m == nil || !isActive(m["machine_status"]) || !canSee(c.MustUser(), m.Str("machine_code")) {
				app.Invalid("machine_code", c.T("Pick a machine."))
			}
			data.Set("machine_name", m["machine_name"])
		}
		data.Set("years", conv.Substr(data.Str("due_date"), 0, 4))
		if existing == nil {
			data.Set("round", 1+conv.Int(mdb().Scalar("SELECT COALESCE(MAX(round), 0) FROM maintenance_record WHERE machine_code = ? AND years = ?", data.Get("machine_code"), data.Get("years"))))
		}
		if conv.Empty(data.Get("responsible_maintenance")) {
			data.Set("responsible_maintenance", nil)
		}
		var cur any
		if existing != nil {
			cur = existing["attachment"]
		}
		data.Set("attachment", filesMerge(cur, keepList(c, "attachment"), filesSaveMany(c, "docs")))
		return stamp(c, data, existing)
	},
	BeforeDelete: func(c *app.Ctx, row db.Row) { filesMerge(row["attachment"], nil, nil) },
}

// maintState: planned | overdue | completed | late
func maintState(r db.Row) string {
	if !conv.Empty(r["actual_date"]) {
		if r.Str("status") == "COMPLETED (LATE)" || (!conv.Empty(r["due_date"]) && r.Str("actual_date") > r.Str("due_date")) {
			return "late"
		}
		return "completed"
	}
	if !conv.Empty(r["due_date"]) && r.Str("due_date") < conv.Today() {
		return "overdue"
	}
	return "planned"
}

func maintCanModify(c *app.Ctx, row db.Row, action string) bool {
	u := c.MustUser()
	if action == "delete" {
		return u.Scope() == "all" && conv.Empty(row["actual_date"])
	}
	if row.Int("responsible_maintenance") == u.ID {
		return true
	}
	if !conv.Empty(row["machine_id"]) {
		return manages(u, row)
	}
	return u.Scope() == "all"
}

func dateOrDash(c *app.Ctx, v any) string {
	if conv.Empty(v) {
		return "—"
	}
	return fmtDate(c, v)
}

// queryOrNil is Request::query($k) (null when missing).
func queryOrNil(c *app.Ctx, k string) any {
	if !c.QueryHas(k) {
		return nil
	}
	return c.Query(k)
}

// activeMachineOptions: machines in use the person sees (calibration: only those marked for calibration).
func activeMachineOptions(c *app.Ctx, calibration bool) []app.Opt {
	s, p := scope(c.MustUser(), "m")
	cal := ""
	if calibration {
		cal = " AND m.is_calibration"
	}
	var out []app.Opt
	for _, r := range mdb().Select("SELECT m.machine_code, m.machine_code || ' · ' || m.machine_name AS label FROM machine m WHERE m.deleted_at IS NULL"+cal+" AND m.machine_status IN ("+activeIn()+") AND "+s+" ORDER BY m.machine_code", p...) {
		out = append(out, app.Opt{Value: r.Str("machine_code"), Label: r.Str("label")})
	}
	return out
}

/* ---------------------------------------------------------------- do it */

func maintItems(code any, withReset bool) []db.Row {
	cols := "i.id, i.question_id, q.detail, q.description, q.is_choice"
	if withReset {
		cols = "i.id, i.question_id, i.reset_time, q.detail, q.description, q.is_choice"
	}
	return mdb().Select("SELECT "+cols+" FROM maintenance_checklist i JOIN question q ON q.id = i.question_id WHERE i.machine_code = ? AND q.deleted_at IS NULL ORDER BY i.id", code)
}

func maintPerform(c *app.Ctx) {
	r := maintenance.FindForChange(c, c.ParamInt("id"), "edit")
	if !conv.Empty(r["actual_date"]) {
		c.Back("error", c.T("This maintenance is already done."))
	}
	m := machine(r.Str("machine_code"))
	if m == nil {
		app.Abort(404)
	}
	items := maintItems(m["machine_code"], false)
	today := conv.Today()
	by := conv.Str(oldOr(c, "maintenance_by", conv.Or(r["maintenance_by"], "INTERNAL")))
	person := conv.Str(oldOr(c, "responsible_maintenance", conv.Or(r["responsible_maintenance"], c.MustUser().ID)))
	c.View("machines/maintenance-perform", app.M{"title": c.T("Do the maintenance"), "r": r, "m": m, "items": questionsView(c, items),
		"people": people(), "byOpts": trOpts(c, maintBy), "by": by, "person": person,
		"late": !conv.Empty(r["due_date"]) && r.Str("due_date") < today, "today": today,
		"mstat": conv.Str(oldOr(c, "machine_status", "OPERATIONAL")), "statusChoices": statusChoices(c, "h-12")})
}

func oldOr(c *app.Ctx, k string, def any) any {
	if v, ok := c.Sess.Old(k); ok {
		return v
	}
	return def
}

func maintComplete(c *app.Ctx) {
	r := maintenance.FindForChange(c, c.ParamInt("id"), "edit")
	if !conv.Empty(r["actual_date"]) {
		c.Back("error", c.T("This maintenance is already done."))
	}
	u := c.MustUser()
	m := machine(r.Str("machine_code"))
	if m == nil {
		app.Abort(404)
	}
	items := maintItems(m["machine_code"], true)
	ans := []answer{}
	if len(items) > 0 {
		ans = answers(c, items, c.InputAny("answers"))
	}
	date := c.Input("actual_date")
	if date == "" {
		date = conv.Today()
	}
	if conv.StrToTime(date) == 0 || date > conv.Today() {
		app.Invalid("actual_date", c.T("The date cannot be in the future."))
	}
	by := c.Input("maintenance_by")
	if _, ok := lookup(maintBy, by); !ok {
		app.Invalid("maintenance_by", c.T("Choose internal or external."))
	}
	status := c.Input("machine_status")
	if !isActive(status) {
		app.Invalid("machine_status", c.T("Choose the machine status after the maintenance."))
	}
	job := strings.TrimSpace(c.Input("job_detail"))
	if job == "" {
		app.Invalid("job_detail", c.T("Write what was done."))
	}
	person := conv.Int(c.Input("responsible_maintenance"))
	if person == 0 {
		person = u.ID
	}
	late := !conv.Empty(r["due_date"]) && date > r.Str("due_date")
	var image any
	if file := c.File("image"); file != nil {
		image = app.UploadImage(c, file, "machines", 2048, false)
	}
	docs := filesMerge(r["attachment"], filesNames(filesDecode(r["attachment"])), filesSaveMany(c, "docs"))
	var note any
	if n := strings.TrimSpace(c.Input("note")); n != "" {
		note = n
	}
	status2 := "COMPLETED"
	if late {
		status2 = "COMPLETED (LATE)"
	}
	var cid int
	mdb().Transaction(func(tx db.Q) {
		cid = tx.InsertID("checklist_record", db.Cols{
			{Name: "check_type", Value: "MAINTENANCE"}, {Name: "recheck", Value: false}, {Name: "machine_code", Value: m["machine_code"]}, {Name: "machine_name", Value: m["machine_name"]}, {Name: "machine_status", Value: status},
			{Name: "machine_checklist", Value: jsonCompat(ans, flagUnicode)}, {Name: "machine_note", Value: note}, {Name: "image", Value: image}, {Name: "user_id", Value: u.Email}, {Name: "user_name", Value: u.Name},
			{Name: "supervisor", Value: m["supervisor_id"]}, {Name: "manager", Value: m["manager_id"]}, {Name: "checklist_status", Value: "COMPLETED"}, {Name: "job_detail", Value: job}, {Name: "created_by", Value: u.ID}, {Name: "updated_by", Value: u.ID},
		})
		tx.Update("maintenance_record", db.Cols{{Name: "actual_date", Value: date}, {Name: "start_date", Value: conv.Or(r["start_date"], date)}, {Name: "status", Value: status2}, {Name: "maintenance_by", Value: by}, {Name: "responsible_maintenance", Value: person},
			{Name: "note", Value: conv.Coalesce(note, r["note"])}, {Name: "attachment", Value: docs}, {Name: "checklist_record_id", Value: cid}, {Name: "updated_by", Value: u.ID}, {Name: "updated_at", Value: conv.NowString()}}, db.Cols{{Name: "id", Value: r["id"]}})
		tx.Exec("UPDATE machine SET machine_status = ?, updated_at = now() WHERE id = ?", status, m["id"])
	})
	ch := &app.Changes{}
	ch.Set("status", app.Change{From: conv.Or(r["status"], "planned"), To: status2})
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: maintenance.Type, ID: r.Int("id"), Label: maintenance.LabelOf(c, r), Text: "Finished the maintenance of :code", Params: []any{"code", m["machine_code"]},
		Props: app.M{"changes": ch, "_url": c.URL("/machines/checklists/" + conv.Str(cid))}, Owner: m.Int("responsible_person_id"), Module: "machines"})
	lateTxt := ""
	if late {
		lateTxt = " (" + c.T("late") + ")"
	}
	for _, uid := range team(m, true) {
		if uid != u.ID {
			app.Deliver(c, "machines", "updated", uid, app.Text("Maintenance of :code is done", "code", m["machine_code"]),
				app.Text(":who finished round :n of :code · :name:late.", "who", u.Name, "n", conv.Str(conv.Coalesce(r["round"], "?")), "code", m["machine_code"], "name", m["machine_name"], "late", lateTxt), c.URL("/machines/checklists/"+conv.Str(cid)), u.ID)
		}
	}
	c.Flash("success", c.T("Maintenance saved."))
	c.Redirect("/machines/checklists/" + conv.Str(cid))
}
