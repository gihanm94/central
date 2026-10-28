package core

import (
	"html/template"

	"acme/internal/app"
	"acme/internal/conv"
	"acme/internal/db"
)

// Departments (DepartmentController).
var departments = &app.Resource{
	Resource: "departments", Table: "departments", Type: "department", Singular: "department", Plural: "departments",
	Base: "/departments", Icon: "building", OrderBy: "t.name", Searchable: []string{"t.name", "t.code"},
	ScopeSQL: func(c *app.Ctx) (string, []any) {
		u := c.MustUser()
		if u.Scope() == "all" {
			return "TRUE", nil
		}
		return "t.id = ?", []any{u.DepartmentID}
	},
	SelectSQL: func(c *app.Ctx) string {
		return `SELECT t.*, h.name AS head_name,
                       (SELECT COUNT(*) FROM users u WHERE u.department_id = t.id AND u.deleted_at IS NULL AND u.is_active) AS members_count,
                       (SELECT COUNT(*) FROM teams tm WHERE tm.department_id = t.id AND tm.deleted_at IS NULL) AS teams_count
                  FROM departments t LEFT JOIN users h ON h.id = t.head_id`
	},
	Fields: func(c *app.Ctx, row db.Row) []app.Field {
		var heads []app.Opt
		for _, r := range db.Select(`SELECT u.id, u.name || ' (' || r.name || ')' AS name FROM users u JOIN roles r ON r.id = u.role_id
                                           WHERE u.deleted_at IS NULL AND u.is_active AND r.level >= 40 ORDER BY u.name`) {
			heads = append(heads, app.Opt{Value: r.Str("id"), Label: r.Str("name")})
		}
		codeRule := "required|max:20|unique:departments,code"
		color := ""
		var allErp []app.Opt
		if row != nil {
			codeRule += "," + row.Str("id")
			color = app.DeptColor(row.Int("id"), "")
			if row.Int("erp_department_id") > 0 {
				allErp = []app.Opt{{Value: row.Str("erp_department_id"), Label: "#" + row.Str("erp_department_id")}}
			}
		} else {
			color = app.DeptColor(conv.Int(db.Scalar("SELECT COALESCE(MAX(id), 0) + 1 FROM departments")), "")
		}
		return []app.Field{
			{Name: "name", Label: c.T("Name"), Rules: "required|max:120", Example: "Operations"},
			{Name: "code", Label: c.T("Short code"), Rules: codeRule, Help: c.T("e.g. OPS, FIN"), Example: "OPS"},
			{Name: "color", Label: c.T("Colour"), Type: "color", Rules: "nullable|max:9", Default: color, Help: c.T("Used in the control room charts.")},
			{Name: "erp_department_id", Label: c.T("ERP department"), Type: "select", Options: ErpDepartments(), Rules: "nullable",
				Help: c.T("Revenue of this ERP department counts as this department's revenue (CRM sales targets). Fill the list with Accounting → ERP connection."), AllOptions: allErp},
			{Name: "head_id", Label: c.T("Head (BU manager)"), Type: "select", Options: heads, Rules: "nullable"},
			{Name: "is_active", Label: c.T("Active"), Type: "checkbox", Default: true},
			{Name: "description", Label: c.T("Description"), Type: "textarea", Span: 2, Rules: "nullable|max:1000"},
		}
	},
	Columns: func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "name", Label: c.T("Department"), Primary: true, Render: func(c *app.Ctx, r db.Row) template.HTML {
				return app.HTML(`<span class="mr-2 inline-block size-2.5 rounded-full align-middle" style="background:` + app.E(app.DeptColor(r.Int("id"), r.Str("color"))) + `"></span><a href="` + c.URL("/departments/"+r.Str("id")) + `" class="font-medium hover:text-signal-700">` + app.E(r["name"]) + `</a> <span class="ml-1 text-xs text-steel">` + app.E(r["code"]) + `</span>`)
			}},
			{Key: "erp", Label: c.T("ERP department"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				if r.Int("erp_department_id") > 0 {
					return app.HTML(`<span class="tabular-nums">#` + r.Str("erp_department_id") + `</span>`)
				}
				return app.HTML(`<span class="text-graphite-400">—</span>`)
			}},
			{Key: "head", Label: c.T("Head"), Render: func(c *app.Ctx, r db.Row) template.HTML { return app.HTML(app.E(conv.Coalesce(r["head_name"], "—"))) }},
			{Key: "members", Label: c.T("Members"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				return app.HTML(`<span class="tabular-nums">` + conv.Str(r.Int("members_count")) + `</span>`)
			}},
			{Key: "teams", Label: c.T("Teams"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				return app.HTML(`<span class="tabular-nums">` + conv.Str(r.Int("teams_count")) + `</span>`)
			}},
			{Key: "status", Label: c.T("Status"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				if r.Bool("is_active") {
					return app.HTML(`<span class="badge bg-emerald-50 text-emerald-800">` + c.T("Active") + `</span>`)
				}
				return app.HTML(`<span class="badge bg-graphite-900/6 text-steel">` + c.T("Inactive") + `</span>`)
			}},
		}
	},
	Owner: func(row db.Row) int { return row.Int("head_id") },
	Prepare: func(c *app.Ctx, data *app.Clean, existing db.Row) *app.Clean {
		if data.Has("code") {
			data.Set("code", toUpper(data.Str("code")))
		}
		return data
	},
	BeforeDelete: func(c *app.Ctx, row db.Row) {
		if row.Int("members_count") > 0 {
			app.Invalid("department", c.T("Move the :n members out of this department first.", "n", row["members_count"]))
		}
	},
	ShowExtra: func(c *app.Ctx, row db.Row) template.HTML {
		teams := db.Select("SELECT t.id, t.name, l.name AS lead_name FROM teams t LEFT JOIN users l ON l.id = t.lead_id WHERE t.department_id = ? AND t.deleted_at IS NULL ORDER BY t.name", row["id"])
		members := db.Select("SELECT u.id, u.name, u.avatar, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.department_id = ? AND u.deleted_at IS NULL ORDER BY r.level DESC, u.name LIMIT 50", row["id"])
		return c.Partial("partials/department-extra", app.M{"teams": teams, "members": members})
	},
}

// ErpDepartments: departments copied from the ERP, "CODE · Name" (empty until the ERP has been synced).
func ErpDepartments() []app.Opt {
	if !app.ModuleUsable("accounting") {
		return nil
	}
	var out []app.Opt
	_ = db.Try(func() {
		for _, r := range db.On("accounting").Select("SELECT id, concat_ws(' · ', NULLIF(code, ''), COALESCE(NULLIF(name, ''), NULLIF(description, ''))) AS label FROM erp_departments ORDER BY 2") {
			out = append(out, app.Opt{Value: r.Str("id"), Label: r.Str("label")})
		}
	})
	return out
}

func init() { signedIn(func(r app.Router) { departments.Mount(r) }) }
