package app

import (
	"acme/internal/db"
)

// Dropdown options, already limited to what the signed-in person may see (App\Core\Support\Options).

func rowsToOpts(rows []db.Row) []Opt {
	out := make([]Opt, 0, len(rows))
	for _, r := range rows {
		out = append(out, Opt{Value: r.Str("id"), Label: r.Str("name")})
	}
	return out
}

// OptionsUsers are the people the person may see.
func OptionsUsers(c *Ctx, u *CurrentUser, activeOnly bool) []Opt {
	s, p := ScopeSQL(u, "u", ScopeCols{Owner: []string{"id"}, Department: "department_id", Team: "team_id"})
	sql := "SELECT u.id, u.name FROM users u WHERE u.deleted_at IS NULL"
	if activeOnly {
		sql += " AND u.is_active"
	}
	return rowsToOpts(db.Select(sql+" AND "+s+" ORDER BY u.name", p...))
}

// OptionsPeople: the same people with photo, role and department (the picture dropdown).
func OptionsPeople(c *Ctx, u *CurrentUser, activeOnly bool) []Opt {
	s, p := ScopeSQL(u, "u", ScopeCols{Owner: []string{"id"}, Department: "department_id", Team: "team_id"})
	sql := `SELECT u.id, u.name, u.avatar, r.name AS role_name, d.name AS department_name FROM users u
            JOIN roles r ON r.id = u.role_id LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.deleted_at IS NULL`
	if activeOnly {
		sql += " AND u.is_active"
	}
	var out []Opt
	for _, r := range db.Select(sql+" AND "+s+" ORDER BY u.name", p...) {
		sub := r.Str("role_name")
		if r.Str("department_name") != "" {
			sub += " · " + r.Str("department_name")
		}
		out = append(out, Opt{Value: r.Str("id"), Label: r.Str("name"), Sub: sub, Img: c.UploadURL(r["avatar"])})
	}
	return out
}

// OptionsDepartments: all for whole-company people, else their own.
func OptionsDepartments(u *CurrentUser) []Opt {
	if u.Scope() == "all" {
		return rowsToOpts(db.Select("SELECT id, name FROM departments WHERE deleted_at IS NULL ORDER BY name"))
	}
	return rowsToOpts(db.Select("SELECT id, name FROM departments WHERE deleted_at IS NULL AND id = ?", u.DepartmentID))
}

// OptionsTeams by scope.
func OptionsTeams(u *CurrentUser) []Opt {
	sql := `SELECT t.id, d.code || ' / ' || t.name AS name FROM teams t JOIN departments d ON d.id = t.department_id WHERE t.deleted_at IS NULL`
	switch u.Scope() {
	case "all":
		return rowsToOpts(db.Select(sql + " ORDER BY name"))
	case "department":
		return rowsToOpts(db.Select(sql+" AND t.department_id = ? ORDER BY name", u.DeptID()))
	}
	return rowsToOpts(db.Select(sql+" AND t.id = ?", u.TeamID))
}

// OptionsRoles this person may hand out: admins any, others only roles below their own level.
func OptionsRoles(u *CurrentUser) []Opt {
	if u.IsAdmin() {
		return rowsToOpts(db.Select("SELECT id, name FROM roles ORDER BY level DESC"))
	}
	return rowsToOpts(db.Select("SELECT id, name FROM roles WHERE level < ? ORDER BY level DESC", u.RoleLevel))
}

// OptionsAllRoles is every role.
func OptionsAllRoles() []Opt {
	return rowsToOpts(db.Select("SELECT id, name FROM roles ORDER BY level DESC"))
}
