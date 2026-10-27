package machines

import (
	"html/template"
	"net/url"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

// The machines (MachineController): one row per machine with code + QR label, people, plans, documents and its checklist items.
var machines = &app.Resource{
	Resource: "machines_machines", Table: "machine", Type: "machine", Singular: "machine", Plural: "machines",
	Base: "/machines/machines", Icon: "gear", Wizard: true, OrderBy: "t.machine_code", Module: "machines", Conn: conn,
	Searchable: []string{"t.machine_code", "t.machine_name", "t.brand", "t.model", "t.serial_number", "t.responsible_person_name"},
	ScopeSQL:   func(c *app.Ctx) (string, []any) { return scope(c.MustUser(), "t") },
	SelectSQL: func(c *app.Ctx) string {
		return `SELECT t.*, ty.machine_group_name, ty.machine_type_name FROM machine t
                LEFT JOIN machine_type ty ON ty.machine_group_id = t.machine_group_id AND ty.machine_type_id = t.machine_type_id AND ty.deleted_at IS NULL`
	},
	Hydrate: func(c *app.Ctx, rows []db.Row) []db.Row {
		return withDept(withPeople(rows, "responsible_person_id", "supervisor_id", "manager_id"))
	},
	Label: func(c *app.Ctx, row db.Row) string { return row.Str("machine_code") + " · " + row.Str("machine_name") },
	Owner: func(row db.Row) int { return row.Int("responsible_person_id") },
	CanModify: func(c *app.Ctx, row db.Row, action string) bool {
		u := c.MustUser()
		if action == "delete" {
			return u.Scope() == "all"
		}
		return manages(u, row)
	},
	Filters: func(c *app.Ctx) []app.Filter {
		return []app.Filter{
			{Name: "status", Label: c.T("All statuses"), Options: trOpts(c, statuses), Column: "t.machine_status"},
			{Name: "check", Label: c.T("All check states"), Options: []app.Opt{{Value: "PENDING", Label: c.T("Pending")}, {Value: "PENDING SUPERVISOR", Label: c.T("Pending supervisor")}, {Value: "PENDING MANAGER", Label: c.T("Pending manager")}, {Value: "COMPLETED", Label: c.T("Completed")}, {Value: "OUT OF SERVICE", Label: c.T("Out of service")}}, Column: "t.check_status"},
			{Name: "dept", Label: c.T("All departments"), Options: departments(), Column: "t.department_id"},
			{Name: "group", Label: c.T("All groups"), Options: groupOptions(), Column: "t.machine_group_id"},
			{Name: "period", Label: c.T("Any check period"), Options: trOpts(c, periods), Column: "t.reset_period"},
			{Name: "mine", Label: c.T("All machines"), Options: []app.Opt{{Value: "1", Label: c.T("Mine (responsible)")}}, SQL: "t.responsible_person_id = ?", Bind: func(string) any { return c.MustUser().ID }},
		}
	},
	Columns: func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "code", Label: c.T("Machine"), Primary: true, Sort: "t.machine_code", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return app.HTML(`<span class="font-medium tabular-nums">` + esc(r["machine_code"]) + `</span><span class="block text-xs text-steel">` + esc(r["machine_name"]) + `</span>`)
			}},
			{Key: "type", Label: c.T("Type"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				t := strings.Trim(r.Str("machine_group_name")+" / "+r.Str("machine_type_name"), " /")
				if t == "" {
					t = "—"
				}
				return app.HTML(esc(t) + `<span class="block text-xs text-steel">` + esc(strings.TrimSpace(r.Str("brand")+" "+r.Str("model"))) + `</span>`)
			}},
			{Key: "dept", Label: c.T("Department"), Render: func(c *app.Ctx, r db.Row) template.HTML { return app.HTML(esc(php.Coalesce(r["department_name"], "—"))) }},
			{Key: "resp", Label: c.T("Responsible"), Sort: "t.responsible_person_name", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return app.HTML(esc(php.Coalesce(r["responsible_person_id_name"], r["responsible_person_name"], "—")))
			}},
			{Key: "status", Label: c.T("Status"), Sort: "t.machine_status", Render: func(c *app.Ctx, r db.Row) template.HTML { return pillH(c, r["machine_status"], "") }},
			{Key: "check", Label: c.T("Check"), Sort: "t.check_status", Render: func(c *app.Ctx, r db.Row) template.HTML { return pillH(c, r["check_status"], "") }},
		}
	},
	RowLinks: func(c *app.Ctx, row db.Row) []app.Link {
		var l []app.Link
		if isActive(row["machine_status"]) && c.Can("machines_checklists", "create") {
			l = append(l, app.Link{URL: "/machines/checklists/new?machine=" + url.PathEscape(row.Str("machine_code")), Label: c.T("Check now"), Icon: "check"})
		}
		return append(l, app.Link{URL: "/machines/label?ids=" + row.Str("id"), Label: c.T("QR label"), Icon: "qr"})
	},
	Fields:       machineFields,
	Prepare:      machinePrepare,
	Saved:        machineSaved,
	BeforeDelete: machineBeforeDelete,
}

// groupOptions: [group id => group name]
func groupOptions() []app.Opt {
	var out []app.Opt
	idx := map[string]int{}
	for _, r := range mdb().Select("SELECT DISTINCT machine_group_id, machine_group_name FROM machine_type WHERE deleted_at IS NULL ORDER BY 1") {
		if i, ok := idx[r.Str("machine_group_id")]; ok {
			out[i].Label = r.Str("machine_group_name")
			continue
		}
		idx[r.Str("machine_group_id")] = len(out)
		out = append(out, app.Opt{Value: r.Str("machine_group_id"), Label: r.Str("machine_group_name")})
	}
	return out
}

// machineRegister: the register request this machine is being made from (?register=ID), if the person may see it.
func machineRegister(c *app.Ctx) db.Row {
	if v, ok := c.Shared["machines.reg"]; ok {
		r, _ := v.(db.Row)
		return r
	}
	var id int
	if c.QueryHas("register") {
		id = php.Int(c.Query("register"))
	} else {
		id = php.Int(c.Input("register_id", "0"))
	}
	var r db.Row
	if id != 0 {
		s, p := registerVisibleSQL(c.MustUser()) // only a request this person may see
		r = mdb().First("SELECT * FROM register_request t WHERE t.id = ? AND t.deleted_at IS NULL AND "+s, append([]any{id}, p...)...)
	}
	c.Shared["machines.reg"] = r
	return r
}

func typeOptions() []app.Opt {
	var out []app.Opt
	for _, r := range mdb().Select("SELECT machine_group_id g, machine_group_name gn, machine_type_id t, machine_type_name tn FROM machine_type WHERE deleted_at IS NULL AND status = 'ACTIVE' ORDER BY 1, 3") {
		out = append(out, app.Opt{Value: r.Str("g") + "." + r.Str("t"), Label: r.Str("g") + "-" + r.Str("t") + " · " + r.Str("gn") + " / " + r.Str("tn")})
	}
	return out
}

func questionOptions() []app.Opt {
	var out []app.Opt
	for _, r := range mdb().Select("SELECT id, detail FROM question WHERE deleted_at IS NULL ORDER BY detail") {
		out = append(out, app.Opt{Value: r.Str("id"), Label: r.Str("detail")})
	}
	return out
}

// formRows: the rows a repeated field shows: the last post (after a failed save), else these.
func formRows(c *app.Ctx, name string, rows []map[string]any) []map[string]any {
	if o, ok := c.Sess.Old(name); ok {
		switch o.(type) {
		case map[string]any, []any:
			var out []map[string]any
			for _, v := range postedList(o) {
				m, _ := rowOf(v)
				if m == nil {
					m = map[string]any{}
				}
				out = append(out, m)
			}
			return out
		}
	}
	return rows
}

func rowsToMaps(rows []db.Row) []map[string]any {
	out := make([]map[string]any, 0, len(rows))
	for _, r := range rows {
		out = append(out, map[string]any(r))
	}
	return out
}

// filesField: a documents field (machines/fields/files): the saved files of column col, ticked unless the last post unticked them.
func filesField(c *app.Ctx, name, label, col string, row db.Row, extra app.Field) app.Field {
	var cur any
	if row != nil {
		cur = row[col]
	}
	list := filesView(filesDecode(cur))
	if o, ok := c.Sess.Old("keep_" + col); ok {
		post := app.InStrings(o)
		for i := range list {
			list[i].On = php.InList(list[i].F, post...)
		}
	}
	f := extra
	f.Name, f.Label, f.Type, f.Partial, f.NoTable, f.NoImport, f.Span, f.Rules = name, label, "custom", "machines/fields/files", true, true, 2, "nullable"
	f.Display = func(db.Row) any { return "" }
	f.Extra = app.M{"column": col, "cur": list, "max": filesMaxBytes / 1048576, "types": strings.Join(fileExt, ", ")}
	return f
}

// planField: maintenance rounds or calibration due dates (machines/fields/plan).
func planField(c *app.Ctx, name, label, kind string, rows []map[string]any, help string) app.Field {
	rs := formRows(c, name, rows)
	var view []app.M
	for i, p := range rs {
		view = append(view, app.M{"i": php.Str(i), "id": php.Str(p["id"]), "due_date": php.Substr(php.Str(p["due_date"]), 0, 10), "plan_date": php.Substr(php.Str(p["plan_date"]), 0, 10), "note": php.Str(p["note"])})
	}
	return app.Field{Name: name, Label: label, Type: "custom", Partial: "machines/fields/plan", NoTable: true, NoImport: true, Span: 2, Rules: "nullable", Help: help,
		Display: func(db.Row) any { return "" }, Extra: app.M{"maint": kind == "maintenance", "rows": view, "next": len(rs)}}
}

// itemsField: checklist items (machines/fields/items).
func itemsField(c *app.Ctx, name, label string, qs []app.Opt, withResets bool, rows []map[string]any, help string) app.Field {
	rs := formRows(c, name, rows)
	var view []app.M
	for i, p := range rs {
		rt := php.Str(php.Coalesce(p["reset_time"], generalRule))
		view = append(view, app.M{"i": php.Str(i), "question_id": php.Str(p["question_id"]), "reset_time": rt})
	}
	return app.Field{Name: name, Label: label, Type: "custom", Partial: "machines/fields/items", NoTable: true, NoImport: true, Span: 2, Rules: "nullable", Help: help,
		Display: func(db.Row) any { return "" }, Extra: app.M{"questions": qs, "resets": withResets, "resetOpts": trOpts(c, resets), "rows": view, "next": len(rs)}}
}

func machineFields(c *app.Ctx, row db.Row) []app.Field {
	ppl := people()
	var reg db.Row
	if row == nil {
		reg = machineRegister(c)
	}
	d := func(k string, fallback any) any {
		if row != nil && row[k] != nil {
			return row[k]
		}
		if reg != nil && reg[k] != nil {
			return reg[k]
		}
		return fallback
	}
	regv := func(k string) any {
		if reg == nil {
			return nil
		}
		return reg[k]
	}
	edit := row != nil
	planRows := func(kind string) []map[string]any {
		if row != nil {
			if kind == "maintenance" {
				return rowsToMaps(mdb().Select("SELECT id, due_date, plan_date, note FROM maintenance_record WHERE machine_code = ? AND actual_date IS NULL AND NOT is_canceled ORDER BY due_date", row["machine_code"]))
			}
			return rowsToMaps(mdb().Select("SELECT id, due_date, note FROM calibration_record WHERE machine_code = ? AND certificate_date IS NULL AND NOT is_canceled ORDER BY due_date", row["machine_code"]))
		}
		return jsonList(regv(kind))
	}
	items := func(tbl string) []map[string]any {
		if row == nil {
			return nil
		}
		return rowsToMaps(mdb().Select("SELECT question_id, reset_time FROM "+tbl+" WHERE machine_code = ? ORDER BY id", row["machine_code"]))
	}
	q := questionOptions()

	f := []app.Field{{Section: c.T("Machine")}}
	if edit {
		f = append(f, app.Field{Name: "machine_code", Label: c.T("Machine code"), Readonly: true, Rules: "nullable", Help: c.T("Printed on the QR label. It never changes.")})
	}
	typeKey, typeHelp := any(""), c.T("The code is made from the department and the type.")
	if edit {
		typeKey, typeHelp = row.Str("machine_group_id")+"."+row.Str("machine_type_id"), ""
	}
	respDefault := d("responsible_id", nil)
	if respDefault == nil && row != nil {
		respDefault = row["responsible_person_id"]
	}
	regID := regv("id")
	if regID == nil && row != nil {
		regID = row["register_id"]
	}
	if regID == nil {
		regID = ""
	}
	isCal := false
	if reg != nil {
		isCal = !php.Empty(reg["calibration"])
	}
	maintHelp := ""
	if edit {
		maintHelp = c.T("Rounds that are not done yet. Finished ones stay in the history and are not changed here.")
	}
	yesNo := []app.Opt{{Value: "YES", Label: c.T("Yes")}, {Value: "NO", Label: c.T("No")}}
	var regRow db.Row
	if reg != nil {
		regRow = db.Row{"work_instruction": reg["work_instruction"]}
	}
	_ = regRow
	f = append(f,
		app.Field{Name: "machine_name", Label: c.T("Machine name"), Rules: "required|max:200", Span: 2, Example: "Hydraulic press 200 t", Default: regv("machine_name")},
		app.Field{Name: "type_key", Label: c.T("Machine type"), Type: "select", Options: typeOptions(), Rules: "required", NoTable: true, NoImport: true, Default: typeKey,
			Display: func(r db.Row) any { return r.Str("machine_group_name") + " / " + r.Str("machine_type_name") }, Help: typeHelp},
		app.Field{Name: "department_id", Label: c.T("Department"), Type: "select", Options: departments(), Rules: "required", Default: d("department_id", c.MustUser().DeptID())},
		app.Field{Name: "machine_status", Label: c.T("Status"), Type: "select", Options: trOpts(c, statuses), Rules: "required", Default: "OPERATIONAL", Search: boolPtr(false),
			Help: c.T("Scrapped or cancelled machines leave the checklists, maintenance and calibration.")},
		app.Field{Name: "brand", Label: c.T("Brand"), Rules: "nullable|max:120", Default: d("brand", nil)},
		app.Field{Name: "model", Label: c.T("Model"), Rules: "nullable|max:120", Default: d("model", nil)},
		app.Field{Name: "serial_number", Label: c.T("Serial number"), Rules: "nullable|max:120", Default: d("serial_number", nil)},
		app.Field{Name: "machine_number", Label: c.T("Asset number"), Rules: "nullable|max:60"},
		app.Field{Name: "is_new", Label: c.T("Bought new"), Type: "checkbox", Default: d("is_new", true)},
		app.Field{Name: "image", Label: c.T("Photo"), Type: "file", NoImport: true, Span: 2, Rules: "nullable", Help: c.T("PNG, JPG or WebP, up to 2 MB.")},
		app.Field{Name: "note", Label: c.T("Note"), Type: "textarea", Rules: "nullable|max:3000", Span: 2, Default: regv("note")},
		app.Field{Name: "register_id", Label: c.T("Register request"), Type: "custom", Partial: "machines/fields/hidden", Rules: "nullable|integer", Default: regID, NoImport: true,
			Display: func(r db.Row) any {
				if php.Empty(r["register_id"]) {
					return ""
				}
				return "#" + r.Str("register_id")
			}},

		app.Field{Section: c.T("Who looks after it")},
		app.Field{Name: "responsible_person_id", Label: c.T("Responsible person"), Type: "select", Options: ppl, Rules: "required", Default: respDefault, Help: c.T("Does the weekly or monthly check."),
			Display: func(r db.Row) any { return php.Coalesce(r["responsible_person_id_name"], "") }},
		app.Field{Name: "supervisor_id", Label: c.T("Supervisor"), Type: "select", Options: ppl, Rules: "nullable", Default: d("supervisor_id", nil), Help: c.T("Approves the check first. Without one, the manager approves."),
			Display: func(r db.Row) any { return php.Coalesce(r["supervisor_id_name"], "") }},
		app.Field{Name: "manager_id", Label: c.T("Manager"), Type: "select", Options: ppl, Rules: "nullable", Default: d("manager_id", nil), Help: c.T("Gives the final approval."),
			Display: func(r db.Row) any { return php.Coalesce(r["manager_id_name"], "") }},
		app.Field{Name: "reset_period", Label: c.T("Responsible re-checks"), Type: "select", Options: trOpts(c, periods), Rules: "required", Default: "WEEKLY", Search: boolPtr(false), Help: c.T("How often the responsible person checks and sends it for approval.")},

		app.Field{Section: c.T("Maintenance and calibration")},
		app.Field{Name: "maintenance_period", Label: c.T("Maintenance every"), Rules: "nullable|max:20", Example: "6 months"},
		app.Field{Name: "is_calibration", Label: c.T("This machine needs calibration"), Type: "checkbox", Default: isCal},
		app.Field{Name: "certificate_period", Label: c.T("Calibration certificate valid for"), Rules: "nullable|max:20", Example: "12 months"},
		planField(c, "plan_maint", c.T("Maintenance rounds"), "maintenance", planRows("maintenance"), maintHelp),
		planField(c, "plan_cal", c.T("Calibration due dates"), "calibration", planRows("calibration"), ""),

		app.Field{Section: c.T("Documents")},
		filesField(c, "wi_files", c.T("Work instruction"), "work_instruction", row, app.Field{}),
		app.Field{Name: "has_warranty", Label: c.T("Warranty"), Type: "select", Options: yesNo, Rules: "nullable", Default: d("has_warranty", nil), Search: boolPtr(false)},
		app.Field{Name: "warranty_expire_date", Label: c.T("Warranty ends"), Type: "date", Rules: "nullable|date", Default: d("warranty_expire_date", nil), ShowWhen: "has_warranty=YES"},
		app.Field{Name: "warranty_note", Label: c.T("Warranty note"), Type: "textarea", Rules: "nullable|max:2000", Span: 2, Default: d("warranty_note", nil), ShowWhen: "has_warranty=YES"},
		filesField(c, "war_files", c.T("Warranty papers"), "warranty_files", row, app.Field{ShowWhen: "has_warranty=YES"}),

		app.Field{Section: c.T("Checklists")},
		itemsField(c, "items_general", c.T("Checklist (what is checked, and when it starts again)"), q, true, items("machine_checklist"),
			c.T(`"General" items are checked by whoever uses the machine. The others are the responsible person's own re-check and start again on their day.`)),
		itemsField(c, "items_maint", c.T("Maintenance checklist"), q, false, items("maintenance_checklist"), c.T("Checked by the person who does the maintenance.")),
	)
	return f
}

func machinePrepare(c *app.Ctx, data *app.Clean, existing db.Row) *app.Clean {
	g, t, _ := strings.Cut(data.Str("type_key"), ".")
	typ := mdb().First("SELECT machine_group_id, machine_type_id FROM machine_type WHERE machine_group_id = ? AND machine_type_id = ? AND deleted_at IS NULL", g, t)
	if typ == nil {
		app.Invalid("type_key", c.T("Pick a machine type."))
	}
	data.Set("machine_group_id", typ["machine_group_id"])
	data.Set("machine_type_id", typ["machine_type_id"])
	data.Delete("type_key")
	data.Delete("machine_code")

	resp := data.Int("responsible_person_id")
	if n, ok := names(resp)[resp]; ok {
		data.Set("responsible_person_name", n)
	} else {
		data.Set("responsible_person_name", nil)
	}
	for _, k := range []string{"supervisor_id", "manager_id"} {
		if php.Empty(data.Get(k)) {
			data.Set(k, nil)
		}
	}
	if data.Get("supervisor_id") == nil && data.Get("manager_id") == nil {
		app.Invalid("manager_id", c.T("Pick a supervisor or a manager: somebody has to approve the checks."))
	}
	reg := machineRegister(c)
	if !php.Empty(data.Get("register_id")) && reg != nil {
		data.Set("register_id", reg.Int("id"))
	} else if existing != nil {
		data.Set("register_id", existing["register_id"])
	} else {
		data.Set("register_id", nil)
	}

	if file := c.File("image"); file != nil {
		data.Set("image", app.UploadImage(c, file, "machines", 2048, false))
		if existing != nil && existing.Str("image") != "" {
			app.DeleteUpload(existing.Str("image"))
		}
	} else {
		data.Delete("image")
	}

	for _, kc := range [][2]string{{"wi_files", "work_instruction"}, {"war_files", "warranty_files"}} {
		key, col := kc[0], kc[1]
		var cur any
		if existing != nil {
			cur = existing[col]
		} else if reg != nil {
			cur = reg[col]
		}
		keep := keepList(c, col)
		if existing == nil {
			keep = filesNames(filesDecode(cur))
		}
		data.Set(col, filesMerge(cur, keep, filesSaveMany(c, key)))
	}
	if data.Str("has_warranty") != "YES" {
		data.Set("warranty_expire_date", nil)
		data.Set("warranty_note", nil)
		filesMerge(data.Get("warranty_files"), nil, nil)
		data.Set("warranty_files", nil)
	}

	act := isActive(data.Get("machine_status"))
	if !act {
		data.Set("check_status", "OUT OF SERVICE")
		if existing != nil && existing["cancel_date"] != nil {
			data.Set("cancel_date", existing["cancel_date"])
		} else {
			data.Set("cancel_date", php.Today())
		}
	} else {
		data.Set("cancel_date", nil)
		if existing != nil && existing.Str("check_status") == "OUT OF SERVICE" {
			data.Set("check_status", "PENDING")
		}
	}
	data.Set("is_calibration", !php.Empty(data.Get("is_calibration")))

	if existing == nil {
		code := nextCode(data.Int("department_id"), g, t)
		data.Set("machine_code", code)
		data.Set("qr_code", qr(code))
		if act {
			data.Set("check_status", "PENDING")
		} else {
			data.Set("check_status", "OUT OF SERVICE")
		}
		if !php.Empty(data.Get("register_id")) {
			data.Set("register_date", php.Today())
		} else {
			data.Set("register_date", nil)
		}
	}
	return stamp(c, data, existing)
}

func machineSaved(c *app.Ctx, id int, data *app.Clean, existing db.Row) {
	m := mdb().First("SELECT * FROM machine WHERE id = ?", id)
	code := m["machine_code"]
	u := c.MustUser()

	// who was responsible when (history)
	open := mdb().First("SELECT id, responsible_person_id FROM responsible_history WHERE machine_code = ? AND effective_to IS NULL", code)
	act := isActive(m["machine_status"])
	if open != nil && (!act || open.Int("responsible_person_id") != m.Int("responsible_person_id")) {
		mdb().Exec("UPDATE responsible_history SET effective_to = CURRENT_DATE - 1 WHERE id = ?", open["id"])
		open = nil
	}
	if open == nil && act && !php.Empty(m["responsible_person_id"]) {
		mdb().Insert("responsible_history", db.Cols{{Name: "machine_code", Value: code}, {Name: "responsible_person_id", Value: m["responsible_person_id"]}, {Name: "effective_from", Value: php.Today()}})
	}

	if !act {
		// a machine that is out of use leaves the plans
		mdb().Exec("UPDATE maintenance_record SET is_canceled = TRUE, canceled_at = CURRENT_DATE WHERE machine_code = ? AND actual_date IS NULL AND NOT is_canceled", code)
		mdb().Exec("UPDATE calibration_record SET is_canceled = TRUE, canceled_at = CURRENT_DATE WHERE machine_code = ? AND certificate_date IS NULL AND NOT is_canceled", code)
	} else {
		syncMaintenance(c, m, postedList(c.InputAny("plan_maint")))
		var cal []any
		if m.Bool("is_calibration") {
			cal = postedList(c.InputAny("plan_cal"))
		}
		syncCalibration(c, m, cal)
	}
	syncItems(c, "machine_checklist", php.Str(code), postedList(c.InputAny("items_general")), true)
	syncItems(c, "maintenance_checklist", php.Str(code), postedList(c.InputAny("items_maint")), false)

	// the new responsible person hears about it
	if !php.Empty(m["responsible_person_id"]) && m.Int("responsible_person_id") != u.ID && (existing == nil || existing.Int("responsible_person_id") != m.Int("responsible_person_id")) {
		app.Deliver(c, "machines", "assigned", m.Int("responsible_person_id"), app.Text("You look after machine :code", "code", code),
			app.Text(":who made you responsible for :code · :name. Do its checklist when it is due.", "who", u.Name, "code", code, "name", m["machine_name"]), c.URL("/machines/machines/"+php.Str(id)), u.ID)
	}
}

// validDate is ! empty($v) && strtotime($v)
func validDate(v any) bool { return !php.Empty(v) && php.StrToTime(app.InStr(v)) != 0 }

func syncMaintenance(c *app.Ctx, m db.Row, rows []any) {
	code := m["machine_code"]
	uid := c.MustUser().ID
	var keep []int
	perYear := map[string]int{}
	for _, raw := range rows {
		p, ok := rowOf(raw)
		if !ok || !validDate(p["due_date"]) {
			continue
		}
		due := php.Substr(app.InStr(p["due_date"]), 0, 10)
		year := php.Substr(due, 0, 4)
		if _, ok := perYear[year]; !ok {
			perYear[year] = php.Int(mdb().Scalar("SELECT count(*) FROM maintenance_record WHERE machine_code = ? AND years = ? AND actual_date IS NOT NULL", code, year))
		}
		perYear[year]++
		var planDate, note any
		if validDate(p["plan_date"]) {
			planDate = php.Substr(app.InStr(p["plan_date"]), 0, 10)
		}
		if n := strings.TrimSpace(app.InStr(p["note"])); n != "" {
			note = n
		}
		vals := db.Cols{{Name: "machine_name", Value: m["machine_name"]}, {Name: "years", Value: year}, {Name: "round", Value: perYear[year]}, {Name: "due_date", Value: due},
			{Name: "plan_date", Value: planDate}, {Name: "note", Value: note}, {Name: "updated_by", Value: uid}, {Name: "updated_at", Value: php.NowString()}}
		id := php.Int(app.InStr(p["id"]))
		if id != 0 && mdb().Scalar("SELECT 1 FROM maintenance_record WHERE id = ? AND machine_code = ? AND actual_date IS NULL", id, code) != nil {
			mdb().Update("maintenance_record", vals, db.Cols{{Name: "id", Value: id}})
			keep = append(keep, id)
		} else {
			keep = append(keep, mdb().InsertID("maintenance_record", append(vals, db.Col{Name: "machine_code", Value: code}, db.Col{Name: "created_by", Value: uid})))
		}
	}
	dropPending("maintenance_record", "actual_date", code, keep)
}

func syncCalibration(c *app.Ctx, m db.Row, rows []any) {
	code := m["machine_code"]
	uid := c.MustUser().ID
	var keep []int
	for _, raw := range rows {
		p, ok := rowOf(raw)
		if !ok || !validDate(p["due_date"]) {
			continue
		}
		due := php.Substr(app.InStr(p["due_date"]), 0, 10)
		var note any
		if n := strings.TrimSpace(app.InStr(p["note"])); n != "" {
			note = n
		}
		vals := db.Cols{{Name: "machine_name", Value: m["machine_name"]}, {Name: "years", Value: php.Substr(due, 0, 4)}, {Name: "due_date", Value: due}, {Name: "note", Value: note},
			{Name: "updated_by", Value: uid}, {Name: "updated_at", Value: php.NowString()}}
		id := php.Int(app.InStr(p["id"]))
		if id != 0 && mdb().Scalar("SELECT 1 FROM calibration_record WHERE id = ? AND machine_code = ? AND certificate_date IS NULL", id, code) != nil {
			mdb().Update("calibration_record", vals, db.Cols{{Name: "id", Value: id}})
			keep = append(keep, id)
		} else {
			keep = append(keep, mdb().InsertID("calibration_record", append(vals, db.Col{Name: "machine_code", Value: code}, db.Col{Name: "created_by", Value: uid})))
		}
	}
	dropPending("calibration_record", "certificate_date", code, keep)
}

// dropPending: planned rows that are not in the form any more disappear; ones that already hold work are only cancelled.
func dropPending(table, doneCol string, code any, keep []int) {
	not := ""
	if len(keep) > 0 {
		ids := make([]string, len(keep))
		for i, k := range keep {
			ids[i] = php.Str(k)
		}
		not = " AND id NOT IN (" + strings.Join(ids, ",") + ")"
	}
	mdb().Exec("DELETE FROM "+table+" WHERE machine_code = ? AND "+doneCol+" IS NULL AND NOT is_canceled"+not, code)
}

func syncItems(c *app.Ctx, table, code string, rows []any, withReset bool) {
	type want struct {
		qid   int
		reset any
	}
	wanted := map[string]want{}
	var order []string
	for _, raw := range rows {
		p, _ := rowOf(raw)
		qid := php.Int(app.InStr(p["question_id"]))
		if qid == 0 || mdb().Scalar("SELECT 1 FROM question WHERE id = ? AND deleted_at IS NULL", qid) == nil {
			continue
		}
		var reset any
		if withReset {
			rt := app.InStr(p["reset_time"])
			if _, ok := lookup(resets, rt); ok {
				reset = rt
			} else {
				reset = generalRule
			}
		}
		k := php.Str(qid) + "|" + php.Str(reset)
		if _, ok := wanted[k]; !ok {
			order = append(order, k)
		}
		wanted[k] = want{qid, reset}
	}
	for _, h := range mdb().Select("SELECT id, question_id, reset_time FROM "+table+" WHERE machine_code = ?", code) {
		k := h.Str("question_id") + "|"
		if withReset {
			k += h.Str("reset_time")
		}
		if _, ok := wanted[k]; ok {
			delete(wanted, k)
		} else {
			mdb().Exec("DELETE FROM "+table+" WHERE id = ?", h["id"])
		}
	}
	for _, k := range order {
		w, ok := wanted[k]
		if !ok {
			continue
		}
		if withReset {
			mdb().Exec("INSERT INTO "+table+" (machine_code,question_id,created_by,reset_time) VALUES (?,?,?,?) ON CONFLICT DO NOTHING", code, w.qid, c.MustUser().ID, w.reset)
		} else {
			mdb().Exec("INSERT INTO "+table+" (machine_code,question_id,created_by) VALUES (?,?,?) ON CONFLICT DO NOTHING", code, w.qid, c.MustUser().ID)
		}
	}
}

func machineBeforeDelete(c *app.Ctx, row db.Row) {
	code := row["machine_code"]
	if mdb().Scalar("SELECT 1 FROM checklist_record WHERE machine_code = ? LIMIT 1", code) != nil {
		c.Back("error", c.T("This machine has checklist history. Set its status to Scrapped or Cancelled instead of deleting it."))
	}
	for _, t := range []string{"maintenance_record", "calibration_record", "machine_checklist", "maintenance_checklist", "responsible_history"} {
		mdb().Exec("DELETE FROM "+t+" WHERE machine_code = ?", code)
	}
	filesMerge(row["work_instruction"], nil, nil)
	filesMerge(row["warranty_files"], nil, nil)
}

/* ------------------------------------------------------------ detail page */

func machineShow(c *app.Ctx) {
	r := machines
	c.Authorize(r.Resource, "view")
	m := r.Find(c, c.ParamInt("id"))
	code := m["machine_code"]
	items := func(tbl string) []db.Row {
		return mdb().Select("SELECT i.*, q.detail, q.description, q.is_choice FROM "+tbl+" i JOIN question q ON q.id = i.question_id WHERE i.machine_code = ? ORDER BY i.id", code)
	}
	hist := mdb().Select("SELECT h.* FROM responsible_history h WHERE h.machine_code = ? ORDER BY h.effective_from DESC, h.id DESC", code)
	var ids []any
	for _, h := range hist {
		ids = append(ids, h["responsible_person_id"])
	}
	n := names(ids...)
	for _, h := range hist {
		h["name"] = nameOr(n, h["responsible_person_id"], "—")
	}
	canEdit := c.Can(r.Resource, "edit") && manages(c.MustUser(), m)
	today := php.Today()
	maintRecords := mdb().Select("SELECT * FROM maintenance_record WHERE machine_code = ? AND NOT is_canceled ORDER BY years DESC, round, due_date", code)
	for _, x := range maintRecords {
		late := php.Empty(x["actual_date"]) && !php.Empty(x["due_date"]) && x.Str("due_date") < today
		switch {
		case !php.Empty(x["status"]):
			x["_pill"] = pillH(c, x["status"], "")
		case late:
			x["_pill"] = pillH(c, c.T("Overdue"), "bad")
		default:
			x["_pill"] = pillH(c, c.T("Planned"), "neutral")
		}
	}
	calRecords := mdb().Select("SELECT * FROM calibration_record WHERE machine_code = ? AND NOT is_canceled ORDER BY due_date DESC", code)
	for _, x := range calRecords {
		late := php.Empty(x["certificate_date"]) && !php.Empty(x["due_date"]) && x.Str("due_date") < today
		switch {
		case !php.Empty(x["results"]):
			x["_pill"] = pillH(c, x["results"], "")
		case late:
			x["_pill"] = pillH(c, c.T("Overdue"), "bad")
		default:
			x["_pill"] = pillH(c, c.T("Planned"), "neutral")
		}
	}
	general := items("machine_checklist")
	for _, i := range general {
		tone := "neutral"
		if i.Str("reset_time") == generalRule {
			tone = "info"
		}
		i["_reset"] = pillH(c, resetLabel(c, i["reset_time"]), tone)
		i["_state"] = template.HTML("")
		if i.Str("reset_time") != generalRule {
			if i.Bool("check_status") {
				i["_state"] = pillH(c, c.T("Checked"), "ok")
			} else {
				i["_state"] = pillH(c, c.T("Not yet"), "warn")
			}
		}
	}
	checks := mdb().Select("SELECT id, check_type, recheck, checklist_status, machine_status, user_name, created_at FROM checklist_record WHERE machine_code = ? ORDER BY created_at DESC LIMIT 12", code)
	for _, k := range checks {
		k["_pill"] = pillH(c, k["checklist_status"], "")
	}
	listURL := func(perm, path string) string {
		if !c.Can(perm, "view") {
			return ""
		}
		return c.URL(path + "?q=" + url.PathEscape(m.Str("machine_code")))
	}
	warranty := ""
	switch m.Str("has_warranty") {
	case "YES":
		warranty = c.T("Yes")
		if !php.Empty(m["warranty_expire_date"]) {
			warranty += " · " + c.T("ends :date", "date", fmtDate(c, m["warranty_expire_date"]))
		}
	case "NO":
		warranty = c.T("No")
	}
	var isNew any
	if m["is_new"] != nil {
		isNew = c.T("No")
		if m.Bool("is_new") {
			isNew = c.T("Yes")
		}
	}
	cal := c.T("No")
	if m.Bool("is_calibration") {
		cal = c.T("Yes")
		if !php.Empty(m["certificate_period"]) {
			cal += " · " + m.Str("certificate_period")
		}
	}
	var registered, reviewed any
	if !php.Empty(m["register_id"]) {
		s := "#" + m.Str("register_id")
		if !php.Empty(m["register_date"]) {
			s += " · " + fmtDate(c, m["register_date"])
		}
		registered = s
	}
	if !php.Empty(m["last_review"]) {
		s := fmtDate(c, m["last_review"])
		if !php.Empty(m["review_by"]) {
			s += " · " + m.Str("review_by")
		}
		reviewed = s
	}
	info := []infoItem{
		{c.T("Department"), m["department_name"], 1, false}, {c.T("Brand"), m["brand"], 1, false}, {c.T("Model"), m["model"], 1, false},
		{c.T("Serial number"), m["serial_number"], 1, false}, {c.T("Asset number"), m["machine_number"], 1, false}, {c.T("Bought new"), isNew, 1, false},
		{c.T("Responsible person"), php.Coalesce(m["responsible_person_id_name"], m["responsible_person_name"]), 1, false}, {c.T("Supervisor"), m["supervisor_id_name"], 1, false}, {c.T("Manager"), m["manager_id_name"], 1, false},
		{c.T("Responsible re-checks"), trLabel(c, periods, m["reset_period"]), 1, false}, {c.T("Maintenance every"), m["maintenance_period"], 1, false}, {c.T("Calibration"), cal, 1, false},
		{c.T("Warranty"), nilIfEmpty(warranty), 1, false},
		{c.T("Registered"), registered, 1, false}, {c.T("Last reviewed"), reviewed, 1, false},
		{c.T("Note"), m["note"], 3, false},
		{c.T("Work instruction"), filesHTML(c, m["work_instruction"]), 2, true}, {c.T("Warranty papers"), filesHTML(c, m["warranty_files"]), 1, true},
	}
	c.View("machines/machine", app.M{
		"title": r.LabelOf(c, m), "m": m, "c": r.Meta(c),
		"canEdit": canEdit, "canDelete": c.Can(r.Resource, "delete") && c.MustUser().Scope() == "all",
		"canCheck": isActive(m["machine_status"]) && c.Can("machines_checklists", "create"),
		"general":  general, "maint": items("maintenance_checklist"),
		"maintRecords": maintRecords, "calRecords": calRecords,
		"checks":  checks,
		"history": hist, "people": people(), "canChange": canEdit && isActive(m["machine_status"]),
		"qrText": qr(m.Str("machine_code")), "link": c.URL("/machines/m/" + url.PathEscape(m.Str("machine_code"))),
		"info": info, "pill": func(v any, tone string) template.HTML { return pillH(c, v, tone) },
		"typeLine": strings.Trim(m.Str("machine_group_name")+" / "+m.Str("machine_type_name"), " /"),
		"checkURL": c.URL("/machines/checklists/new?machine=" + url.PathEscape(m.Str("machine_code"))), "labelURL": c.URL("/machines/label?ids=" + m.Str("id")),
		"maintListURL": listURL("machines_maintenance", "/machines/maintenance"), "calListURL": listURL("machines_calibration", "/machines/calibration"), "checkListURL": listURL("machines_checklists", "/machines/checklists"),
	})
}

func nilIfEmpty(s string) any {
	if s == "" {
		return nil
	}
	return s
}

// infoItem is one label / value pair of machines/_info.
type infoItem struct {
	Label string
	Val   any // string, nil or template.HTML (Raw)
	Span  int
	Raw   bool
}

// Empty: $val === null || $val === ''
func (i infoItem) Empty() bool {
	if i.Val == nil {
		return true
	}
	return php.Str(i.Val) == ""
}

func (i infoItem) Text() string { return php.Str(i.Val) }

func (i infoItem) HTML() template.HTML {
	if h, ok := i.Val.(template.HTML); ok {
		return h
	}
	return template.HTML(php.Str(i.Val))
}

// filesHTML is partial('machines/_files', ['list' => Files::decode($json)]).
func filesHTML(c *app.Ctx, v any) template.HTML {
	return c.Partial("machines/_files", app.M{"list": filesView(filesDecode(v))})
}

// machineChangeResponsible hands the machine to another responsible person (history is kept, the new person is told).
func machineChangeResponsible(c *app.Ctx) {
	r := machines
	id := c.ParamInt("id")
	m := r.FindForChange(c, id, "edit")
	u := c.MustUser()
	nw := php.Int(c.Input("responsible_person_id"))
	name, ok := names(nw)[nw]
	if !ok {
		c.Back("error", c.T("Pick a person."))
	}
	if nw == m.Int("responsible_person_id") {
		c.Back("success", c.T("Nothing changed."))
	}
	mdb().Update("machine", db.Cols{{Name: "responsible_person_id", Value: nw}, {Name: "responsible_person_name", Value: name}, {Name: "updated_at", Value: php.NowString()}, {Name: "updated_by", Value: u.ID}}, db.Cols{{Name: "id", Value: id}})
	mdb().Exec("UPDATE responsible_history SET effective_to = CURRENT_DATE - 1 WHERE machine_code = ? AND effective_to IS NULL", m["machine_code"])
	mdb().Insert("responsible_history", db.Cols{{Name: "machine_code", Value: m["machine_code"]}, {Name: "responsible_person_id", Value: nw}, {Name: "effective_from", Value: php.Today()}})
	ch := &app.Changes{}
	ch.Set("responsible", app.Change{From: m["responsible_person_name"], To: name})
	label := r.LabelOf(c, m)
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: r.Type, ID: id, Label: label, Text: `Changed the person responsible for ":label"`, Params: []any{"label", label},
		Props: app.M{"changes": ch, "_url": c.URL(r.Base + "/" + php.Str(id))}, Owner: nw, Module: "machines"})
	if nw != u.ID {
		app.Deliver(c, "machines", "assigned", nw, app.Text("You look after machine :code", "code", m["machine_code"]),
			app.Text(":who made you responsible for :code · :name. Do its checklist when it is due.", "who", u.Name, "code", m["machine_code"], "name", m["machine_name"]), c.URL("/machines/machines/"+php.Str(id)), u.ID)
	}
	c.Flash("success", c.T("Responsible person changed."))
	c.Redirect(r.Base + "/" + php.Str(id))
}

// machineLabels: printable QR labels for the machines in ?ids=1,2,3 (or every machine in the list when empty).
func machineLabels(c *app.Ctx) {
	c.Authorize(machines.Resource, "view")
	var ids []string
	for _, s := range strings.Split(c.Query("ids", ""), ",") {
		if n := php.Int(s); n != 0 {
			ids = append(ids, php.Str(n))
		}
	}
	s, par := scope(c.MustUser(), "t")
	sql := "SELECT t.id, t.machine_code, t.machine_name, t.department_id FROM machine t WHERE t.deleted_at IS NULL AND " + s
	if len(ids) > 0 {
		sql += " AND t.id IN (" + strings.Join(ids, ",") + ")"
	}
	rows := mdb().Select(sql+" ORDER BY t.machine_code LIMIT 200", par...)
	depts := optMap(departments())
	for _, r := range rows {
		r["qr"] = qr(r.Str("machine_code"))
		r["department"] = depts[r.Str("department_id")]
	}
	c.View("machines/labels", app.M{"title": c.T("QR labels"), "rows": rows, "layout": ""})
}
