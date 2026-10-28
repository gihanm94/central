package core

import (
	"html/template"

	"acme/internal/app"
	"acme/internal/conv"
	"acme/internal/db"
)

// Teams (TeamController).
var teams = &app.Resource{
	Resource: "teams", Table: "teams", Type: "team", Singular: "team", Plural: "teams",
	Base: "/teams", Icon: "team", OrderBy: "d.name, t.name", Searchable: []string{"t.name", "d.name"},
	ScopeSQL: func(c *app.Ctx) (string, []any) {
		u := c.MustUser()
		switch u.Scope() {
		case "all":
			return "TRUE", nil
		case "department":
			return "t.department_id = ?", []any{u.DeptID()}
		}
		return "t.id = ?", []any{u.TeamID}
	},
	SelectSQL: func(c *app.Ctx) string {
		return `SELECT t.*, d.name AS department_name, l.name AS lead_name,
                       (SELECT COUNT(*) FROM users u WHERE u.team_id = t.id AND u.deleted_at IS NULL AND u.is_active) AS members_count
                  FROM teams t JOIN departments d ON d.id = t.department_id LEFT JOIN users l ON l.id = t.lead_id`
	},
	Fields: func(c *app.Ctx, row db.Row) []app.Field {
		u := c.MustUser()
		return []app.Field{
			{Name: "name", Label: c.T("Team name"), Rules: "required|max:120", Example: "Maintenance"},
			{Name: "department_id", Label: c.T("Department"), Type: "select", Options: app.OptionsDepartments(u), Rules: "required"},
			{Name: "lead_id", Label: c.T("Team lead (manager)"), Type: "select", Options: app.OptionsUsers(c, u, true), Rules: "nullable"},
			{Name: "description", Label: c.T("Description"), Type: "textarea", Span: 2, Rules: "nullable|max:1000"},
		}
	},
	Columns: func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "name", Label: c.T("Team"), Primary: true, Render: func(c *app.Ctx, r db.Row) template.HTML {
				return app.HTML(`<a href="` + c.URL("/teams/"+r.Str("id")) + `" class="font-medium hover:text-signal-700">` + app.E(r["name"]) + `</a>`)
			}},
			{Key: "department", Label: c.T("Department"), Render: func(c *app.Ctx, r db.Row) template.HTML { return app.HTML(app.E(r["department_name"])) }},
			{Key: "lead", Label: c.T("Lead"), Render: func(c *app.Ctx, r db.Row) template.HTML { return app.HTML(app.E(conv.Coalesce(r["lead_name"], "—"))) }},
			{Key: "members", Label: c.T("Members"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				return app.HTML(`<span class="tabular-nums">` + conv.Str(r.Int("members_count")) + `</span>`)
			}},
		}
	},
	Owner: func(row db.Row) int { return row.Int("lead_id") },
	BeforeDelete: func(c *app.Ctx, row db.Row) {
		db.Exec("UPDATE users SET team_id = NULL WHERE team_id = ?", row["id"])
	},
	ShowExtra: func(c *app.Ctx, row db.Row) template.HTML {
		members := db.Select("SELECT u.id, u.name, u.avatar, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.team_id = ? AND u.deleted_at IS NULL ORDER BY r.level DESC, u.name", row["id"])
		return c.Partial("partials/department-extra", app.M{"teams": []db.Row{}, "members": members})
	},
}

func init() { signedIn(func(r app.Router) { teams.Mount(r) }) }
