package crm

import (
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

/*
Access: who may see and change CRM records.

SEE:    whole-company roles (Admin, Management …) see everything.
        Everyone else sees records of their own department, records they own,
        and records that were shared with them (a person) or with their department.
CHANGE: the owner, whoever created it, the head of the department (a department-scope role),
        people it was shared with as "can edit", and whole-company roles — always on top of the
        role's own create / edit / delete permission.
SHARE:  the owner, the creator, the department head and whole-company roles decide who else gets in.
*/

type entityDef struct{ Path, Table, Perm, Label string }

var entities = map[string]entityDef{
	"lead":        {"leads", "leads", "crm_leads", "Lead"},
	"contact":     {"contacts", "contacts", "crm_contacts", "Contact"},
	"opportunity": {"opportunities", "opportunities", "crm_opportunities", "Opportunity"},
	"campaign":    {"campaigns", "campaigns", "crm_campaigns", "Campaign"},
	"activity":    {"activities", "activities", "crm_activities", "Activity"},
	"project":     {"projects", "projects", "crm_projects", "Project"},
	"task":        {"tasks", "tasks", "crm_tasks", "Task"},
}

func typeForPath(path string) string {
	for t, e := range entities {
		if e.Path == path {
			return t
		}
	}
	return ""
}

func seesAll(u *app.CurrentUser) bool { return u.Scope() == "all" }

// visible: SQL limiting alias (a CRM table with department_id + owner_id) to what u may see.
func visible(u *app.CurrentUser, alias, typ string) (string, []any) {
	if seesAll(u) {
		return "TRUE", nil
	}
	parts := []string{alias + ".owner_id = ?", alias + ".created_by = ?"}
	params := []any{u.ID, u.ID}
	if u.DepartmentID != 0 {
		parts = append(parts, alias+".department_id = ?")
		params = append(params, u.DepartmentID)
	}
	share := "EXISTS (SELECT 1 FROM record_shares rs WHERE rs.entity_type = ? AND rs.entity_id = " + alias + ".id AND ((rs.target_type = 'user' AND rs.target_id = ?)"
	params = append(params, typ, u.ID)
	if u.DepartmentID != 0 {
		share += " OR (rs.target_type = 'department' AND rs.target_id = ?)"
		params = append(params, u.DepartmentID)
	}
	parts = append(parts, share+"))")

	// People who are responsible for a project / task always see it; tasks follow the project they belong to.
	if typ == "project" {
		parts = append(parts, "EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = "+alias+".id AND pm.user_id = ?)",
			"EXISTS (SELECT 1 FROM tasks k JOIN task_assignees ta ON ta.task_id = k.id WHERE k.project_id = "+alias+".id AND k.deleted_at IS NULL AND ta.user_id = ?)")
		params = append(params, u.ID, u.ID)
	} else if typ == "task" {
		parts = append(parts, "EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = "+alias+".id AND ta.user_id = ?)",
			"EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = "+alias+".project_id AND pm.user_id = ?)")
		params = append(params, u.ID, u.ID)
		pshare := "EXISTS (SELECT 1 FROM record_shares rs2 WHERE rs2.entity_type = 'project' AND rs2.entity_id = " + alias + ".project_id AND ((rs2.target_type = 'user' AND rs2.target_id = ?)"
		params = append(params, u.ID)
		if u.DepartmentID != 0 {
			pshare += " OR (rs2.target_type = 'department' AND rs2.target_id = ?)"
			params = append(params, u.DepartmentID)
		}
		parts = append(parts, pshare+"))")
	}
	return "(" + strings.Join(parts, " OR ") + ")", params
}

// isManager: owner, creator, department head or whole-company role: may share, re-assign and (with permission) change the record.
func isManager(u *app.CurrentUser, row db.Row) bool {
	return seesAll(u) || row.Int("owner_id") == u.ID || row.Int("created_by") == u.ID ||
		(u.Scope() == "department" && u.DepartmentID != 0 && row.Int("department_id") == u.DepartmentID)
}

func canChange(u *app.CurrentUser, typ string, row db.Row) bool {
	if isManager(u, row) {
		return true
	}
	if typ == "project" && isMember(u, row.Int("id")) {
		return true
	}
	if typ == "task" {
		project := db.On("crm").First("SELECT * FROM projects WHERE id = ?", row.Int("project_id"))
		if project != nil && (isManager(u, project) || isMember(u, project.Int("id")) || shareLevel(u, "project", project.Int("id")) == "edit") {
			return true
		}
		if db.On("crm").Scalar("SELECT 1 FROM task_assignees WHERE task_id = ? AND user_id = ?", row.Int("id"), u.ID) != nil {
			return true
		}
	}
	return shareLevel(u, typ, row.Int("id")) == "edit"
}

func isMember(u *app.CurrentUser, projectID int) bool {
	return db.On("crm").Scalar("SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ?", projectID, u.ID) != nil
}

// shareLevel: best access the user got through shares: "edit", "view" or "".
func shareLevel(u *app.CurrentUser, typ string, id int) string {
	sql := "SELECT access FROM record_shares WHERE entity_type = ? AND entity_id = ? AND ((target_type = 'user' AND target_id = ?)"
	params := []any{typ, id, u.ID}
	if u.DepartmentID != 0 {
		sql += " OR (target_type = 'department' AND target_id = ?)"
		params = append(params, u.DepartmentID)
	}
	levels := db.On("crm").Column(sql+")", params...)
	for _, l := range levels {
		if php.Str(l) == "edit" {
			return "edit"
		}
	}
	if len(levels) > 0 {
		return "view"
	}
	return ""
}

// ---------------------------------------------------------------- shares

func shares(typ string, id int) []db.Row {
	rows := db.On("crm").Select("SELECT * FROM record_shares WHERE entity_type = ? AND entity_id = ? ORDER BY target_type DESC, id", typ, id)
	var uids, dids []any
	for _, r := range rows {
		if r.Str("target_type") == "user" {
			uids = append(uids, r["target_id"])
		} else if r.Str("target_type") == "department" {
			dids = append(dids, r["target_id"])
		}
	}
	users := names("users", uids)
	deps := names("departments", dids)
	for _, r := range rows {
		m := deps
		if r.Str("target_type") == "user" {
			m = users
		}
		if n, ok := m[r.Int("target_id")]; ok {
			r["target_name"] = n
		} else {
			r["target_name"] = "—"
		}
	}
	return rows
}

func addShare(typ string, id int, targetType string, targetID int, access string, by int) {
	db.On("crm").Exec(`INSERT INTO record_shares (entity_type, entity_id, target_type, target_id, access, shared_by) VALUES (?, ?, ?, ?, ?, ?)
                  ON CONFLICT (entity_type, entity_id, target_type, target_id) DO UPDATE SET access = EXCLUDED.access`,
		typ, id, targetType, targetID, access, by)
}

// ---------------------------------------------------------------- core look-ups (other database)

// names: [id => name] for users or departments.
func names(table string, ids []any) map[int]string {
	out := map[int]string{}
	seen := map[int]bool{}
	var list []any
	for _, v := range ids {
		n := php.Int(v)
		if n != 0 && !seen[n] {
			seen[n] = true
			list = append(list, n)
		}
	}
	if len(list) == 0 || (table != "users" && table != "departments") {
		return out
	}
	in := strings.TrimSuffix(strings.Repeat("?,", len(list)), ",")
	for _, r := range db.Select("SELECT id, name FROM "+table+" WHERE id IN ("+in+")", list...) {
		out[r.Int("id")] = r.Str("name")
	}
	return out
}

func col(rows []db.Row, k string) []any {
	out := make([]any, 0, len(rows))
	for _, r := range rows {
		out = append(out, r[k])
	}
	return out
}

// crmMembers: people whose records can be picked on the CRM dashboard: [id => "Name · Department"] (ordered).
// Whole-company roles: everybody who may open the CRM (optionally of one department). BU managers and managers: the same, in their own department.
// Everybody else: nobody (empty list = no member filter).
func crmMembers(u *app.CurrentUser, departmentID int) []app.Opt {
	own := false
	if !seesAll(u) {
		if !php.InList(u.RoleSlug, "bu_manager", "manager") && u.Scope() != "department" {
			return nil
		}
		if u.DepartmentID == 0 {
			return nil
		}
		departmentID = u.DepartmentID
		own = true
	}
	sql := `SELECT u.id, u.name, d.name AS department
                              FROM users u LEFT JOIN departments d ON d.id = u.department_id
                              JOIN permissions p ON p.key IN ('crm_dashboard', 'crm_leads')
                              LEFT JOIN role_permissions rp ON rp.role_id = u.role_id AND rp.permission_id = p.id
                              LEFT JOIN user_permissions up ON up.user_id = u.id AND up.permission_id = p.id
                             WHERE u.deleted_at IS NULL AND u.is_active AND COALESCE(up.can_view, rp.can_view, FALSE)`
	var params []any
	if departmentID != 0 {
		sql += " AND u.department_id = ?"
		params = append(params, departmentID)
	}
	sql += `
                             GROUP BY u.id, d.name ORDER BY u.name`
	var out []app.Opt
	for _, r := range db.Select(sql, params...) {
		l := r.Str("name")
		if !own && r.Str("department") != "" {
			l += " · " + r.Str("department")
		}
		out = append(out, app.Opt{Value: r.Str("id"), Label: l})
	}
	return out
}

type person struct {
	ID           int
	Name         string
	DepartmentID int
	Department   string
	Active       bool
}

// people: active people with their department, by name.
func people() []person {
	var out []person
	for _, r := range db.Select(`SELECT u.id, u.name, u.department_id, d.name AS department FROM users u LEFT JOIN departments d ON d.id = u.department_id
                             WHERE u.deleted_at IS NULL AND u.is_active ORDER BY u.name`) {
		out = append(out, person{ID: r.Int("id"), Name: r.Str("name"), DepartmentID: r.Int("department_id"), Department: r.Str("department"), Active: true})
	}
	return out
}

func peopleMap(list []person) map[int]person {
	m := make(map[int]person, len(list))
	for _, p := range list {
		m[p.ID] = p
	}
	return m
}

// everyone: everybody who still has a login record, also people switched off (they may have left).
func everyone() []person {
	var out []person
	for _, r := range db.Select(`SELECT u.id, u.name, u.department_id, u.is_active AS active, d.name AS department FROM users u LEFT JOIN departments d ON d.id = u.department_id
                             WHERE u.deleted_at IS NULL ORDER BY u.is_active DESC, u.name`) {
		out = append(out, person{ID: r.Int("id"), Name: r.Str("name"), DepartmentID: r.Int("department_id"), Department: r.Str("department"), Active: r.Bool("active")})
	}
	return out
}

// assignable: who this user may hand a record to: everyone for whole-company roles, otherwise the own department.
func assignable(u *app.CurrentUser) []app.Opt {
	var out []app.Opt
	all := seesAll(u)
	for _, p := range people() {
		if all || p.ID == u.ID || (u.DepartmentID != 0 && p.DepartmentID == u.DepartmentID) {
			l := p.Name
			if p.Department != "" && all {
				l += " · " + p.Department
			}
			out = append(out, app.Opt{Value: php.Str(p.ID), Label: l})
		}
	}
	return out
}

// departments: active departments, by name.
func departments() []app.Opt {
	return optsFrom(db.Select("SELECT id, name FROM departments WHERE deleted_at IS NULL AND is_active ORDER BY name"), "id", "name")
}

func withoutOpt(opts []app.Opt, v string) []app.Opt {
	var out []app.Opt
	for _, o := range opts {
		if o.Value != v {
			out = append(out, o)
		}
	}
	return out
}
