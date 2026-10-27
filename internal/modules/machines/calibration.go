package machines

import (
	"html/template"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

// Calibration of measuring machines (CalibrationController): the due date, the certificate and what the lab measured.
var calibration = &app.Resource{
	Resource: "machines_calibration", Table: "calibration_record", Type: "machine_calibration", Singular: "calibration", Plural: "calibration",
	Base: "/machines/calibration", Icon: "target", NoSoftDelete: true, Wizard: true, OrderBy: "t.due_date ASC NULLS LAST, t.id", Module: "machines", Conn: conn,
	Searchable: []string{"t.machine_code", "t.machine_name", "t.note", "t.comment"},
	SelectSQL: func(c *app.Ctx) string {
		return "SELECT t.*, m.id AS machine_id, m.department_id, m.responsible_person_id, m.supervisor_id, m.manager_id, m.machine_status, m.machine_name AS m_name FROM calibration_record t LEFT JOIN machine m ON m.machine_code = t.machine_code"
	},
	ScopeSQL: func(c *app.Ctx) (string, []any) {
		u := c.MustUser()
		if u.Scope() == "all" {
			return "NOT t.is_canceled", nil
		}
		s, p := scope(u, "m")
		return "(NOT t.is_canceled AND m.id IS NOT NULL AND " + s + ")", p
	},
	Hydrate: func(c *app.Ctx, rows []db.Row) []db.Row {
		rows = withDept(rows)
		today := php.Today()
		for _, r := range rows {
			switch {
			case !php.Empty(r["certificate_date"]):
				if !php.Empty(r["due_date"]) && r.Str("certificate_date") > r.Str("due_date") {
					r["state"] = "late"
				} else {
					r["state"] = "done"
				}
			case !php.Empty(r["due_date"]) && r.Str("due_date") < today:
				r["state"] = "overdue"
			default:
				r["state"] = "planned"
			}
		}
		return rows
	},
	Label: func(c *app.Ctx, row db.Row) string {
		if !php.Empty(row["due_date"]) {
			return row.Str("machine_code") + " · " + fmtDate(c, row["due_date"])
		}
		return row.Str("machine_code") + " · " + row.Str("years")
	},
	Owner: func(row db.Row) int { return row.Int("responsible_person_id") },
	CanModify: func(c *app.Ctx, row db.Row, action string) bool {
		u := c.MustUser()
		if action == "delete" {
			return u.Scope() == "all" && php.Empty(row["certificate_date"])
		}
		if !php.Empty(row["machine_id"]) {
			return manages(u, row)
		}
		return u.Scope() == "all"
	},
	Filters: func(c *app.Ctx) []app.Filter {
		var years []app.Opt
		for _, r := range mdb().Select("SELECT DISTINCT years FROM calibration_record ORDER BY years DESC") {
			years = append(years, app.Opt{Value: r.Str("years"), Label: r.Str("years")})
		}
		today := php.Today()
		return []app.Filter{
			{Name: "year", Label: c.T("All years"), Options: years, Column: "t.years"},
			{Name: "state", Label: c.T("All states"), Options: []app.Opt{{Value: "planned", Label: c.T("Planned")}, {Value: "overdue", Label: c.T("Overdue")}, {Value: "done", Label: c.T("Done on time")}, {Value: "late", Label: c.T("Done late")}},
				SQL: "(CASE ? WHEN 'planned' THEN t.certificate_date IS NULL AND (t.due_date IS NULL OR t.due_date >= '" + today + "') WHEN 'overdue' THEN t.certificate_date IS NULL AND t.due_date < '" + today + "' WHEN 'done' THEN t.certificate_date IS NOT NULL AND (t.due_date IS NULL OR t.certificate_date <= t.due_date) ELSE t.certificate_date IS NOT NULL AND t.certificate_date > t.due_date END)"},
			{Name: "result", Label: c.T("Any result"), Options: []app.Opt{{Value: "PASS", Label: c.T("Pass")}, {Value: "FAILED", Label: c.T("Failed")}}, Column: "t.results"},
			{Name: "dept", Label: c.T("All departments"), Options: departments(), Column: "m.department_id"},
		}
	},
	Fields: func(c *app.Ctx, row db.Row) []app.Field {
		pf := []app.Opt{{Value: "PASS", Label: c.T("Pass")}, {Value: "FAIL", Label: c.T("Fail")}}
		f := []app.Field{{Section: c.T("Calibration")}}
		if row == nil {
			f = append(f, app.Field{Name: "machine_code", Label: c.T("Machine"), Type: "select", Options: activeMachineOptions(c, true), Rules: "required", Span: 2, Default: queryOrNil(c, "machine"), Help: c.T(`Only machines marked "needs calibration" are listed.`)})
		}
		return append(f,
			app.Field{Name: "due_date", Label: c.T("Due date"), Type: "date", Rules: "required|date"},
			app.Field{Name: "start_date", Label: c.T("Sent for calibration"), Type: "date", Rules: "nullable|date"},
			app.Field{Name: "certificate_date", Label: c.T("Certificate date"), Type: "date", Rules: "nullable|date", Help: c.T("Filling this in closes the round.")},
			app.Field{Name: "calibration_status", Label: c.T("Progress"), Rules: "nullable|max:30", Example: "In progress"},
			app.Field{Name: "results", Label: c.T("Result"), Type: "select", Options: []app.Opt{{Value: "PASS", Label: c.T("Pass")}, {Value: "FAILED", Label: c.T("Failed")}}, Rules: "nullable", Search: boolPtr(false)},
			app.Field{Name: "reason_not_pass", Label: c.T("Why it failed"), Type: "textarea", Rules: "nullable|max:2000", Span: 2, ShowWhen: "results=FAILED"},
			app.Field{Name: "note", Label: c.T("Note"), Type: "textarea", Rules: "nullable|max:3000", Span: 2},
			filesField(c, "docs", c.T("Certificate and reports"), "attachment", row, app.Field{}),

			app.Field{Section: c.T("Measurements")},
			app.Field{Name: "criteria", Label: c.T("Acceptance criteria"), Rules: "nullable|max:500"},
			app.Field{Name: "measuring_range", Label: c.T("Measuring range"), Rules: "nullable|max:500"},
			app.Field{Name: "accuracy", Label: c.T("Accuracy"), Rules: "nullable|max:500"},
			app.Field{Name: "calibration_range", Label: c.T("Calibration range"), Rules: "nullable|max:500"},
			app.Field{Name: "permissible_capacity", Label: c.T("Permissible capacity"), Rules: "nullable|max:500"},
			app.Field{Name: "resolution", Label: c.T("Resolution"), Rules: "nullable|max:500"},
			app.Field{Name: "max_uncertainty", Label: c.T("Max. uncertainty"), Rules: "nullable|max:500"},
			app.Field{Name: "mpe", Label: c.T("MPE (maximum permissible error)"), Rules: "nullable|max:500"},
			app.Field{Name: "check_mpe", Label: c.T("MPE check"), Type: "select", Options: pf, Rules: "nullable", Search: boolPtr(false)},
			app.Field{Name: "check_resolution", Label: c.T("Resolution check"), Type: "select", Options: pf, Rules: "nullable", Search: boolPtr(false)},
			app.Field{Name: "check_result", Label: c.T("Overall check"), Type: "select", Options: pf, Rules: "nullable", Search: boolPtr(false)},
			app.Field{Name: "comment", Label: c.T("Comment"), Type: "textarea", Rules: "nullable|max:3000", Span: 2},
		)
	},
	Columns: func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "machine", Label: c.T("Machine"), Primary: true, Sort: "t.machine_code", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return app.HTML(`<span class="font-medium tabular-nums">` + esc(r["machine_code"]) + `</span><span class="block text-xs text-steel">` + esc(php.Coalesce(r["machine_name"], r["m_name"])) + `</span>`)
			}},
			{Key: "due", Label: c.T("Due"), Sort: "t.due_date", Render: func(c *app.Ctx, r db.Row) template.HTML { return app.HTML(esc(dateOrDash(c, r["due_date"]))) }},
			{Key: "cert", Label: c.T("Certificate"), Sort: "t.certificate_date", Render: func(c *app.Ctx, r db.Row) template.HTML { return app.HTML(esc(dateOrDash(c, r["certificate_date"]))) }},
			{Key: "state", Label: c.T("Status"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				var s string
				switch r.Str("state") {
				case "done":
					s = pill(c, c.T("Done on time"), "ok")
				case "late":
					s = pill(c, c.T("Done late"), "warn")
				case "overdue":
					s = pill(c, c.T("Overdue"), "bad")
				default:
					s = pill(c, c.T("Planned"), "neutral")
				}
				if !php.Empty(r["calibration_status"]) {
					s += `<span class="block text-xs text-steel">` + esc(r["calibration_status"]) + `</span>`
				}
				return app.HTML(s)
			}},
			{Key: "result", Label: c.T("Result"), Sort: "t.results", Render: func(c *app.Ctx, r db.Row) template.HTML {
				if php.Empty(r["results"]) {
					return app.HTML(`<span class="text-steel">—</span>`)
				}
				if r.Str("results") == "PASS" {
					return pillH(c, c.T("Pass"), "ok")
				}
				return pillH(c, c.T("Failed"), "bad")
			}},
			{Key: "dept", Label: c.T("Department"), Render: func(c *app.Ctx, r db.Row) template.HTML { return app.HTML(esc(php.Coalesce(r["department_name"], "—"))) }},
		}
	},
	Prepare: func(c *app.Ctx, data *app.Clean, existing db.Row) *app.Clean {
		if existing == nil {
			m := machine(data.Str("machine_code"))
			if m == nil || !m.Bool("is_calibration") || !isActive(m["machine_status"]) || !canSee(c.MustUser(), m.Str("machine_code")) {
				app.Invalid("machine_code", c.T("Pick a machine."))
			}
			data.Set("machine_name", m["machine_name"])
		}
		data.Set("years", php.Substr(data.Str("due_date"), 0, 4))
		if data.Str("results") == "FAILED" && strings.TrimSpace(data.Str("reason_not_pass")) == "" {
			app.Invalid("reason_not_pass", c.T("Write why the calibration failed."))
		}
		if data.Str("results") != "FAILED" {
			data.Set("reason_not_pass", nil)
		}
		if !php.Empty(data.Get("certificate_date")) && php.Empty(data.Get("results")) {
			app.Invalid("results", c.T("Choose pass or failed for a calibration with a certificate."))
		}
		var cur any
		if existing != nil {
			cur = existing["attachment"]
		}
		data.Set("attachment", filesMerge(cur, keepList(c, "attachment"), filesSaveMany(c, "docs")))
		return stamp(c, data, existing)
	},
	Saved: func(c *app.Ctx, id int, data *app.Clean, existing db.Row) {
		row := mdb().First("SELECT c.*, m.id AS mid, m.machine_name AS mname, m.responsible_person_id, m.supervisor_id, m.manager_id FROM calibration_record c JOIN machine m ON m.machine_code = c.machine_code WHERE c.id = ?", id)
		if row == nil || php.Empty(row["certificate_date"]) || (existing != nil && !php.Empty(existing["certificate_date"])) {
			return // only the moment the certificate arrives
		}
		u := c.MustUser()
		result := c.T("failed")
		if row.Str("results") == "PASS" {
			result = c.T("pass")
		}
		for _, uid := range team(row, true) {
			if uid != u.ID {
				app.Deliver(c, "machines", "updated", uid, app.Text("Calibration of :code: :result", "code", row["machine_code"], "result", result),
					app.Text("The calibration of :code · :name was closed as :result.", "code", row["machine_code"], "name", row["mname"], "result", result), c.URL("/machines/calibration/"+php.Str(id)), u.ID)
			}
		}
	},
	BeforeDelete: func(c *app.Ctx, row db.Row) { filesMerge(row["attachment"], nil, nil) },
}
