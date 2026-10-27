package machines

import (
	"html/template"
	"sort"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

/*
"I want a new machine registered" (RegisterController): anybody who buys or receives a machine fills this in
(what it is, who looks after it, its maintenance and calibration plan, papers). An administrator or manager
then turns the request into a machine (it gets a code and a QR label).
*/
var register = &app.Resource{
	Resource: "machines_register", Table: "register_request", Type: "machine_register", Singular: "register request", Plural: "register requests",
	Base: "/machines/register", Icon: "doc", Wizard: true, Module: "machines", Conn: conn,
	Searchable: []string{"t.machine_name", "t.brand", "t.model", "t.serial_number", "t.note"},
	ScopeSQL:   func(c *app.Ctx) (string, []any) { return registerVisibleSQL(c.MustUser()) },
	SelectSQL: func(c *app.Ctx) string {
		return `SELECT t.*, (SELECT string_agg(m.machine_code, ', ' ORDER BY m.id) FROM machine m WHERE m.register_id = t.id AND m.deleted_at IS NULL) AS machine_codes,
                       (SELECT count(*) FROM machine m WHERE m.register_id = t.id AND m.deleted_at IS NULL) AS machine_count FROM register_request t`
	},
	Hydrate: func(c *app.Ctx, rows []db.Row) []db.Row {
		return withDept(withPeople(rows, "created_by", "responsible_id", "supervisor_id", "manager_id"))
	},
	Label: func(c *app.Ctx, row db.Row) string {
		if s := row.Str("machine_name"); s != "" {
			return s
		}
		return "#" + row.Str("id")
	},
	Owner: func(row db.Row) int { return row.Int("created_by") },
	CanModify: func(c *app.Ctx, row db.Row, action string) bool {
		u := c.MustUser()
		if row.Int("machine_count") > 0 && action == "delete" {
			return false // a machine was made from it
		}
		return u.Scope() == "all" || row.Int("created_by") == u.ID || (u.Scope() == "department" && row.Int("department_id") == u.DepartmentID)
	},
	Filters: func(c *app.Ctx) []app.Filter {
		return []app.Filter{
			{Name: "dept", Label: c.T("All departments"), Options: departments(), Column: "t.department_id"},
			{Name: "state", Label: c.T("All requests"), Options: []app.Opt{{Value: "waiting", Label: c.T("Waiting for a machine")}, {Value: "done", Label: c.T("Machine created")}},
				SQL: "(CASE ? WHEN 'done' THEN EXISTS (SELECT 1 FROM machine m WHERE m.register_id = t.id AND m.deleted_at IS NULL) ELSE NOT EXISTS (SELECT 1 FROM machine m WHERE m.register_id = t.id AND m.deleted_at IS NULL) END)"},
		}
	},
	Columns: func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "machine", Label: c.T("Machine"), Primary: true, Sort: "t.machine_name", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return app.HTML(`<span class="font-medium">` + esc(r["machine_name"]) + `</span><span class="block text-xs text-steel">` + esc(strings.TrimSpace(r.Str("brand")+" "+r.Str("model"))) + `</span>`)
			}},
			{Key: "dept", Label: c.T("Department"), Render: func(c *app.Ctx, r db.Row) template.HTML { return app.HTML(esc(php.Coalesce(r["department_name"], "—"))) }},
			{Key: "by", Label: c.T("Requested by"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				return app.HTML(esc(php.Coalesce(r["created_by_name"], "—")) + `<span class="block text-xs text-steel">` + esc(fmtDate(c, r["created_at"])) + `</span>`)
			}},
			{Key: "resp", Label: c.T("Responsible"), Render: func(c *app.Ctx, r db.Row) template.HTML { return app.HTML(esc(php.Coalesce(r["responsible_id_name"], "—"))) }},
			{Key: "state", Label: c.T("Status"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				if r.Int("machine_count") > 0 {
					return app.HTML(pill(c, c.T("Machine created"), "ok") + `<span class="mt-0.5 block text-xs text-steel">` + esc(r["machine_codes"]) + `</span>`)
				}
				return pillH(c, c.T("Waiting"), "warn")
			}},
		}
	},
	RowLinks: func(c *app.Ctx, row db.Row) []app.Link {
		if c.Can("machines_machines", "create") && row.Int("machine_count") == 0 {
			return []app.Link{{URL: "/machines/machines/create?register=" + row.Str("id"), Label: c.T("Create the machine"), Icon: "gear"}}
		}
		return nil
	},
	Fields:  registerFields,
	Prepare: registerPrepare,
	Saved:   registerSaved,
	BeforeDelete: func(c *app.Ctx, row db.Row) {
		for _, col := range []string{"attachment", "work_instruction", "warranty_files"} {
			filesMerge(row[col], nil, nil)
		}
	},
}

// registerVisibleSQL: register requests a person may see (also used when a machine is made from one).
func registerVisibleSQL(u *app.CurrentUser) (string, []any) {
	if u.Scope() == "all" {
		return "TRUE", nil
	}
	mine := "t.created_by = ? OR t.supervisor_id = ? OR t.manager_id = ? OR t.responsible_id = ?"
	if u.Scope() == "department" && u.DepartmentID != 0 {
		return "(t.department_id = ? OR " + mine + ")", []any{u.DepartmentID, u.ID, u.ID, u.ID, u.ID}
	}
	return "(" + mine + ")", []any{u.ID, u.ID, u.ID, u.ID}
}

func registerFields(c *app.Ctx, row db.Row) []app.Field {
	ppl := people()
	rows := func(col string) []map[string]any {
		if row == nil {
			return nil
		}
		return jsonList(row[col])
	}
	yesNo := []app.Opt{{Value: "YES", Label: c.T("Yes")}, {Value: "NO", Label: c.T("No")}}
	return []app.Field{
		{Section: c.T("Machine")},
		{Name: "machine_name", Label: c.T("Machine name"), Rules: "required|max:200", Span: 2, Example: "Hydraulic press 200 t"},
		{Name: "department_id", Label: c.T("Department"), Type: "select", Options: departments(), Rules: "required", Default: c.MustUser().DeptID(), Example: "1"},
		{Name: "is_new", Label: c.T("Bought new"), Type: "checkbox", Default: true, Help: c.T("Untick for a second-hand or transferred machine.")},
		{Name: "brand", Label: c.T("Brand"), Rules: "nullable|max:120"},
		{Name: "model", Label: c.T("Model"), Rules: "nullable|max:120"},
		{Name: "serial_number", Label: c.T("Serial number"), Rules: "nullable|max:120"},
		{Name: "quantity", Label: c.T("Quantity"), Type: "number", Rules: "nullable|integer", Default: 1},
		{Name: "price", Label: c.T("Price"), Type: "number", Rules: "nullable|numeric"},
		{Name: "watt", Label: c.T("Power (watt)"), Type: "number", Rules: "nullable|integer"},
		{Name: "horse_power", Label: c.T("Power (horse power)"), Type: "number", Rules: "nullable|integer"},
		{Name: "note", Label: c.T("Note"), Type: "textarea", Rules: "nullable|max:3000", Span: 2},

		{Section: c.T("Who looks after it")},
		{Name: "responsible_id", Label: c.T("Responsible person"), Type: "select", Options: ppl, Rules: "nullable", Help: c.T("Does the daily and weekly check.")},
		{Name: "supervisor_id", Label: c.T("Supervisor"), Type: "select", Options: ppl, Rules: "nullable", Help: c.T("Approves the check first.")},
		{Name: "manager_id", Label: c.T("Manager"), Type: "select", Options: ppl, Rules: "nullable", Help: c.T("Approves last.")},

		{Section: c.T("Maintenance and calibration plan")},
		planField(c, "plan_maint", c.T("Maintenance rounds"), "maintenance", rows("maintenance"), c.T("One row for each planned maintenance of the year.")),
		planField(c, "plan_cal", c.T("Calibration due dates"), "calibration", rows("calibration"), c.T("Leave empty when the machine is not calibrated.")),

		{Section: c.T("Documents")},
		filesField(c, "att_files", c.T("Quotation, drawings, photos"), "attachment", row, app.Field{}),
		filesField(c, "wi_files", c.T("Work instruction"), "work_instruction", row, app.Field{}),
		{Name: "has_warranty", Label: c.T("Warranty"), Type: "select", Options: yesNo, Rules: "nullable", Search: boolPtr(false)},
		{Name: "warranty_expire_date", Label: c.T("Warranty ends"), Type: "date", Rules: "nullable|date", ShowWhen: "has_warranty=YES"},
		{Name: "warranty_note", Label: c.T("Warranty note"), Type: "textarea", Rules: "nullable|max:2000", Span: 2, ShowWhen: "has_warranty=YES"},
		filesField(c, "war_files", c.T("Warranty papers"), "warranty_files", row, app.Field{ShowWhen: "has_warranty=YES"}),
	}
}

// planItem is one row of the JSON plan kept on the request (key order as PHP wrote it).
type planItem struct {
	DueDate  string `json:"due_date"`
	Note     string `json:"note"`
	PlanDate any    `json:"plan_date,omitempty"`
	withPlan bool
}

func registerPrepare(c *app.Ctx, data *app.Clean, existing db.Row) *app.Clean {
	for _, kc := range [][2]string{{"att_files", "attachment"}, {"wi_files", "work_instruction"}, {"war_files", "warranty_files"}} {
		var cur any
		if existing != nil {
			cur = existing[kc[1]]
		}
		data.Set(kc[1], filesMerge(cur, keepList(c, kc[1]), filesSaveMany(c, kc[0])))
	}
	if data.Str("has_warranty") != "YES" {
		data.Set("warranty_expire_date", nil)
		data.Set("warranty_note", nil)
		filesMerge(data.Get("warranty_files"), nil, nil) // a machine without warranty keeps no warranty papers
		data.Set("warranty_files", nil)
	}
	for _, kp := range []struct {
		key, col string
		withPlan bool
	}{{"plan_maint", "maintenance", true}, {"plan_cal", "calibration", false}} {
		var list []*config_item
		for _, raw := range postedList(c.InputAny(kp.key)) {
			p, ok := rowOf(raw)
			if !ok || php.Empty(p["due_date"]) {
				continue
			}
			if php.StrToTime(app.InStr(p["due_date"])) == 0 {
				app.Invalid(kp.key, c.T("A due date is not a valid date."))
			}
			it := &config_item{due: php.Substr(app.InStr(p["due_date"]), 0, 10), note: php.Substr(strings.TrimSpace(app.InStr(p["note"])), 0, 500), withPlan: kp.withPlan}
			if kp.withPlan && validDate(p["plan_date"]) {
				it.plan = php.Substr(app.InStr(p["plan_date"]), 0, 10)
			}
			list = append(list, it)
		}
		sort.SliceStable(list, func(i, j int) bool { return list[i].due < list[j].due })
		if len(list) == 0 {
			data.Set(kp.col, nil)
			continue
		}
		var out []any
		for _, it := range list {
			if it.withPlan {
				out = append(out, om("due_date", it.due, "note", it.note, "plan_date", it.plan))
			} else {
				out = append(out, om("due_date", it.due, "note", it.note))
			}
		}
		data.Set(kp.col, phpJSON(out, 0))
	}
	return stamp(c, data, existing)
}

type config_item struct {
	due, note string
	plan      any
	withPlan  bool
}

func registerSaved(c *app.Ctx, id int, data *app.Clean, existing db.Row) {
	if existing != nil {
		return
	}
	// tell the people who turn requests into machines
	row := mdb().First("SELECT * FROM register_request WHERE id = ?", id)
	u := c.MustUser()
	seen := map[int]bool{}
	for _, r := range db.Select("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active AND u.deleted_at IS NULL AND r.slug = 'admin'") {
		uid := r.Int("id")
		if seen[uid] {
			continue
		}
		seen[uid] = true
		if uid != u.ID {
			app.Deliver(c, "machines", "created", uid, app.Text("New machine register request: :name", "name", row["machine_name"]),
				app.Text(`:who asks to register ":name". Open the request to create the machine.`, "who", u.Name, "name", row["machine_name"]), c.URL("/machines/register/"+php.Str(id)), u.ID)
		}
	}
}

func registerShow(c *app.Ctx) {
	r := register
	c.Authorize(r.Resource, "view")
	row := r.Find(c, c.ParamInt("id"))
	title := r.LabelOf(c, row)
	files := func(col string) template.HTML { return filesHTML(c, row[col]) }
	var isNew any
	if row["is_new"] != nil {
		isNew = c.T("No")
		if row.Bool("is_new") {
			isNew = c.T("Yes")
		}
	}
	var price any
	if row["price"] != nil {
		price = php.NF(row["price"], 2)
	}
	power := ""
	if !php.Empty(row["watt"]) {
		power += row.Str("watt") + " W "
	}
	if !php.Empty(row["horse_power"]) {
		power += row.Str("horse_power") + " HP"
	}
	warranty := ""
	switch row.Str("has_warranty") {
	case "YES":
		warranty = c.T("Yes")
		if !php.Empty(row["warranty_expire_date"]) {
			warranty += " · " + c.T("ends :date", "date", fmtDate(c, row["warranty_expire_date"]))
		}
	case "NO":
		warranty = c.T("No")
	}
	var maint, cal []app.M
	for i, p := range jsonList(row["maintenance"]) {
		m := app.M{"n": i + 1, "due": fmtDate(c, p["due_date"]), "plan": "", "note": php.Str(p["note"])}
		if !php.Empty(p["plan_date"]) {
			m["plan"] = fmtDate(c, p["plan_date"])
		}
		maint = append(maint, m)
	}
	for _, p := range jsonList(row["calibration"]) {
		cal = append(cal, app.M{"due": fmtDate(c, p["due_date"]), "note": php.Str(p["note"])})
	}
	c.View("machines/register-show", app.M{"title": title, "row": row, "c": r.Meta(c),
		"canEdit":   c.Can(r.Resource, "edit") && r.CanModify(c, row, "edit"),
		"canDelete": c.Can(r.Resource, "delete") && r.CanModify(c, row, "delete"),
		"canMake":   c.Can("machines_machines", "create") && row.Int("machine_count") == 0,
		"info1": []infoItem{
			{c.T("Machine name"), row["machine_name"], 1, false}, {c.T("Department"), row["department_name"], 1, false}, {c.T("Bought new"), isNew, 1, false},
			{c.T("Brand"), row["brand"], 1, false}, {c.T("Model"), row["model"], 1, false}, {c.T("Serial number"), row["serial_number"], 1, false},
			{c.T("Quantity"), row["quantity"], 1, false}, {c.T("Price"), price, 1, false}, {c.T("Power"), strings.TrimSpace(power), 1, false},
			{c.T("Note"), row["note"], 3, false},
		},
		"info2": []infoItem{{c.T("Responsible person"), row["responsible_id_name"], 1, false}, {c.T("Supervisor"), row["supervisor_id_name"], 1, false}, {c.T("Manager"), row["manager_id_name"], 1, false}},
		"info3": []infoItem{
			{c.T("Quotation, drawings, photos"), files("attachment"), 1, true}, {c.T("Work instruction"), files("work_instruction"), 1, true},
			{c.T("Warranty"), nilIfEmpty(warranty), 1, false},
			{c.T("Warranty note"), row["warranty_note"], 2, false}, {c.T("Warranty papers"), files("warranty_files"), 1, true},
		},
		"maint": maint, "cal": cal,
	})
}
