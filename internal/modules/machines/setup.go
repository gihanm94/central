package machines

import (
	"fmt"
	"html/template"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

// Machine groups and their types (TypeController), e.g. "02 Press" → "01 Hydraulic press". A machine picks one type.
var types = &app.Resource{
	Resource: "machines_types", Table: "machine_type", Type: "machine_type", Singular: "machine type", Plural: "machine types",
	Base: "/machines/types", Icon: "gear", OrderBy: "t.machine_group_id, t.machine_type_id", Module: "machines", Conn: conn,
	Searchable: []string{"t.machine_group_name", "t.machine_type_name", "t.machine_group_id", "t.machine_type_id"},
	ScopeSQL:   func(c *app.Ctx) (string, []any) { return "TRUE", nil },
	Label: func(c *app.Ctx, row db.Row) string {
		return row.Str("machine_group_name") + " / " + row.Str("machine_type_name")
	},
	Filters: func(c *app.Ctx) []app.Filter {
		return []app.Filter{
			{Name: "group", Label: c.T("All groups"), Options: groupOptions(), Column: "t.machine_group_id"},
			{Name: "status", Label: c.T("All statuses"), Options: []app.Opt{{Value: "ACTIVE", Label: c.T("Active")}, {Value: "INACTIVE", Label: c.T("Inactive")}}, Column: "t.status"},
		}
	},
	Fields: func(c *app.Ctx, row db.Row) []app.Field {
		groups := append([]app.Opt{{Value: "__new", Label: c.T("+ New group")}}, groupOptions()...)
		isNew := row == nil
		groupRule, groupHelp, showWhen, nameHelp := "nullable", "", "", c.T("Renames the whole group.")
		var groupDefault any = ""
		if isNew {
			groupRule, groupHelp, showWhen, nameHelp = "required", c.T("Pick a group, or create a new one."), "group=__new", c.T("Needed only for a new group.")
		} else {
			groupDefault = row["machine_group_id"]
		}
		return []app.Field{
			{Name: "group", Label: c.T("Group"), Type: "select", Options: groups, Rules: groupRule, NoTable: true, NoImport: true, Readonly: !isNew,
				Default: groupDefault, Help: groupHelp, Display: func(r db.Row) any { return r["machine_group_name"] }},
			{Name: "machine_group_name", Label: c.T("Group name"), Rules: "nullable|max:160", ShowWhen: showWhen, Example: "Press", Help: nameHelp},
			{Name: "machine_type_name", Label: c.T("Type name"), Rules: "required|max:160", Example: "Hydraulic press"},
			{Name: "status", Label: c.T("Status"), Type: "select", Options: []app.Opt{{Value: "ACTIVE", Label: c.T("Active")}, {Value: "INACTIVE", Label: c.T("Inactive")}}, Rules: "required", Default: "ACTIVE", Search: boolPtr(false)},
		}
	},
	Columns: func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "group", Label: c.T("Group"), Primary: true, Sort: "t.machine_group_id", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return app.HTML(`<span class="tabular-nums text-steel">` + esc(r["machine_group_id"]) + `</span> ` + esc(r["machine_group_name"]))
			}},
			{Key: "type", Label: c.T("Type"), Sort: "t.machine_type_id", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return app.HTML(`<span class="tabular-nums text-steel">` + esc(r["machine_type_id"]) + `</span> ` + esc(r["machine_type_name"]))
			}},
			{Key: "status", Label: c.T("Status"), Sort: "t.status", Render: func(c *app.Ctx, r db.Row) template.HTML {
				if r.Str("status") == "ACTIVE" {
					return pillH(c, "ACTIVE", "ok")
				}
				return pillH(c, "INACTIVE", "neutral")
			}},
			{Key: "count", Label: c.T("Machines"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				return app.HTML(php.Str(mdb().Scalar("SELECT count(*) FROM machine WHERE deleted_at IS NULL AND machine_group_id = ? AND machine_type_id = ?", r["machine_group_id"], r["machine_type_id"])))
			}},
		}
	},
	Prepare: func(c *app.Ctx, data *app.Clean, existing db.Row) *app.Clean {
		name := strings.TrimSpace(data.Str("machine_type_name"))
		if existing == nil {
			group := data.Str("group")
			if group == "__new" {
				gname := strings.TrimSpace(data.Str("machine_group_name"))
				if gname == "" {
					app.Invalid("machine_group_name", c.T("Give the new group a name."))
				}
				if mdb().Scalar("SELECT 1 FROM machine_type WHERE lower(machine_group_name) = lower(?) AND deleted_at IS NULL", gname) != nil {
					app.Invalid("machine_group_name", c.T("This group name already exists. Pick it from the list."))
				}
				next := php.Int(mdb().Scalar("SELECT COALESCE(MAX(machine_group_id::int), 0) FROM machine_type WHERE machine_group_id ~ '^[0-9]+$'")) + 1
				data.Set("machine_group_id", fmt.Sprintf("%02d", next))
				data.Set("machine_group_name", gname)
				data.Set("machine_type_id", "01")
			} else {
				g := mdb().First("SELECT machine_group_id, machine_group_name FROM machine_type WHERE machine_group_id = ? AND deleted_at IS NULL LIMIT 1", group)
				if g == nil {
					app.Invalid("group", c.T("This group does not exist."))
				}
				data.Set("machine_group_id", g["machine_group_id"])
				data.Set("machine_group_name", g["machine_group_name"])
				nextT := php.Int(mdb().Scalar("SELECT COALESCE(MAX(machine_type_id::int), 0) FROM machine_type WHERE machine_group_id = ? AND machine_type_id ~ '^[0-9]+$'", group)) + 1
				data.Set("machine_type_id", fmt.Sprintf("%02d", nextT))
			}
			if mdb().Scalar("SELECT 1 FROM machine_type WHERE machine_group_id = ? AND lower(machine_type_name) = lower(?) AND deleted_at IS NULL", data.Get("machine_group_id"), name) != nil {
				app.Invalid("machine_type_name", c.T("This type already exists in the group."))
			}
		} else {
			if mdb().Scalar("SELECT 1 FROM machine_type WHERE machine_group_id = ? AND lower(machine_type_name) = lower(?) AND id <> ? AND deleted_at IS NULL", existing["machine_group_id"], name, existing["id"]) != nil {
				app.Invalid("machine_type_name", c.T("This type already exists in the group."))
			}
			if data.Has("machine_group_name") && strings.TrimSpace(data.Str("machine_group_name")) == "" {
				data.Delete("machine_group_name")
			}
		}
		data.Delete("group")
		return stamp(c, data, existing)
	},
	Saved: func(c *app.Ctx, id int, data *app.Clean, existing db.Row) {
		// renaming a group renames every row of it
		if existing != nil && !php.Empty(data.Get("machine_group_name")) && data.Str("machine_group_name") != existing.Str("machine_group_name") {
			mdb().Exec("UPDATE machine_type SET machine_group_name = ? WHERE machine_group_id = ?", data.Get("machine_group_name"), existing["machine_group_id"])
		}
	},
	BeforeDelete: func(c *app.Ctx, row db.Row) {
		if mdb().Scalar("SELECT 1 FROM machine WHERE deleted_at IS NULL AND machine_group_id = ? AND machine_type_id = ?", row["machine_group_id"], row["machine_type_id"]) != nil {
			c.Back("error", c.T("Machines still use this type. Set it to Inactive instead of deleting it."))
		}
	},
}

// The question bank (QuestionController): every checklist item of every machine is one of these.
var questions = &app.Resource{
	Resource: "machines_questions", Table: "question", Type: "question", Singular: "question", Plural: "questions",
	Base: "/machines/questions", Icon: "list", OrderBy: "t.id DESC", Module: "machines", Conn: conn,
	Searchable: []string{"t.detail", "t.description"},
	ScopeSQL:   func(c *app.Ctx) (string, []any) { return "TRUE", nil },
	Label:      func(c *app.Ctx, row db.Row) string { return php.Limit(row.Str("detail"), 60) },
	Filters: func(c *app.Ctx) []app.Filter {
		return []app.Filter{{Name: "kind", Label: c.T("All answer kinds"), Options: []app.Opt{{Value: "1", Label: c.T("OK / NG")}, {Value: "0", Label: c.T("Text or number")}}, SQL: "t.is_choice = (? = '1')"}}
	},
	Fields: func(c *app.Ctx, row db.Row) []app.Field {
		return []app.Field{
			{Name: "detail", Label: c.T("Question"), Rules: "required|max:255", Span: 2, Example: "Is the emergency stop working?"},
			{Name: "description", Label: c.T("How to check"), Type: "textarea", Rules: "nullable|max:2000", Span: 2, Help: c.T("Shown under the question on the checklist.")},
			{Name: "is_choice", Label: c.T("OK / NG answer"), Type: "checkbox", Default: true, Help: c.T("Untick when the answer is a number or text (e.g. a temperature).")},
		}
	},
	Columns: func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "detail", Label: c.T("Question"), Primary: true, Sort: "t.detail", Render: func(c *app.Ctx, r db.Row) template.HTML {
				s := `<span class="font-medium">` + esc(r["detail"]) + `</span>`
				if !php.Empty(r["description"]) {
					s += `<span class="block max-w-xl truncate text-xs text-steel">` + esc(r["description"]) + `</span>`
				}
				return app.HTML(s)
			}},
			{Key: "kind", Label: c.T("Answer"), Sort: "t.is_choice", Render: func(c *app.Ctx, r db.Row) template.HTML {
				if r.Bool("is_choice") {
					return pillH(c, c.T("OK / NG"), "neutral")
				}
				return pillH(c, c.T("Text / number"), "neutral")
			}},
			{Key: "used", Label: c.T("Used on machines"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				return app.HTML(php.Str(mdb().Scalar("SELECT count(DISTINCT machine_code) FROM (SELECT machine_code FROM machine_checklist WHERE question_id = ? UNION SELECT machine_code FROM maintenance_checklist WHERE question_id = ?) x", r["id"], r["id"])))
			}},
		}
	},
	Prepare: func(c *app.Ctx, data *app.Clean, existing db.Row) *app.Clean {
		detail := strings.TrimSpace(data.Str("detail"))
		sql, params := "SELECT 1 FROM question WHERE lower(detail) = lower(?) AND deleted_at IS NULL", []any{detail}
		if existing != nil {
			sql += " AND id <> ?"
			params = append(params, existing["id"])
		}
		if mdb().Scalar(sql, params...) != nil {
			app.Invalid("detail", c.T("This question already exists."))
		}
		data.Set("detail", detail)
		return stamp(c, data, existing)
	},
	BeforeDelete: func(c *app.Ctx, row db.Row) {
		if mdb().Scalar("SELECT 1 FROM machine_checklist WHERE question_id = ? UNION SELECT 1 FROM maintenance_checklist WHERE question_id = ? LIMIT 1", row["id"], row["id"]) != nil {
			c.Back("error", c.T("This question is on a machine's checklist. Take it off the machines first."))
		}
	},
}
