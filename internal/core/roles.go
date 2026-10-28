package core

import (
	"html/template"
	"net/http"
	"regexp"
	"strings"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/conv"
	"acme/internal/db"
)

// Roles and the permission matrix (view / create / edit / delete / import / export / download) (RoleController).

func init() {
	signedIn(func(r app.Router) {
		r.Get("/roles", roleIndex)
		r.Get("/roles/create", roleCreate)
		r.Post("/roles", roleStore)
		r.Get("/roles/{id}/edit", roleEdit)
		r.Post("/roles/{id}", roleUpdate)
		r.Post("/roles/{id}/delete", roleDestroy)
		r.Get("/roles/{id}/permissions", rolePermissions)
		r.Post("/roles/{id}/permissions", roleSavePermissions)
	})
}

func scopeOpts(c *app.Ctx) []app.Opt {
	var out []app.Opt
	for _, p := range config.AsPairs(config.Get("core.scopes")) {
		out = append(out, app.Opt{Value: p.Key, Label: c.T(p.Label)})
	}
	return out
}

func roleIndex(c *app.Ctx) {
	c.Authorize("roles", "view")
	roles := db.Select(`SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id AND u.deleted_at IS NULL) AS users_count
                               FROM roles r ORDER BY r.level DESC`)
	summary := map[int]map[string][]string{}
	for _, rp := range db.Select("SELECT rp.*, p.key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id") {
		for _, a := range app.Actions() {
			if rp.Bool("can_" + a) {
				rid := rp.Int("role_id")
				if summary[rid] == nil {
					summary[rid] = map[string][]string{}
				}
				summary[rid][rp.Str("key")] = append(summary[rid][rp.Str("key")], a)
			}
		}
	}
	resources := config.AsPairs(config.Get("core.resources"))
	for _, r := range roles {
		if r.Str("slug") == "admin" {
			r["_summary"] = template.HTML(app.E(c.T("Everything, always.")))
			continue
		}
		s := summary[r.Int("id")]
		var parts []string
		for _, res := range resources {
			if acts := s[res.Key]; len(acts) > 0 {
				names := make([]string, len(acts))
				for i, a := range acts {
					names[i] = strings.ToLower(c.T(conv.Ucfirst(a)))
				}
				parts = append(parts, `<span class="text-graphite-900">`+app.E(c.T(res.Label))+`</span> `+app.E(strings.Join(names, ", ")))
			}
		}
		if len(parts) > 0 {
			r["_summary"] = template.HTML(strings.Join(parts, " &nbsp;·&nbsp; "))
		} else {
			r["_summary"] = template.HTML(app.E(c.T("No permissions yet.")))
		}
	}
	c.View("roles/index", app.M{"title": c.T("Roles & access"), "roles": roles})
}

func roleCreate(c *app.Ctx) {
	c.Authorize("roles", "create")
	c.View("roles/form", app.M{"title": c.T("New role"), "role": db.Row(nil), "scopes": scopeOpts(c)})
}

func roleStore(c *app.Ctx) {
	c.Authorize("roles", "create")
	data := roleValidated(c, nil)
	roleGuardRank(c, nil, data.Get("level"))
	cols := cleanCols(data)
	cols = append(cols, db.Col{Name: "is_system", Value: false})
	id := db.Core.InsertID("roles", cols)
	app.LogActivity(c, app.ActivityEntry{Action: "created", Type: "role", ID: id, Label: data.Str("name"), Text: `Created :type ":label"`,
		Params: []any{"type", "role", "label", data.Str("name")}, Props: app.M{"attributes": cleanMap(data)}})
	c.Flash("success", c.T("Role created. Now choose what it can do."))
	c.Redirect("/roles/" + conv.Str(id) + "/permissions")
}

func roleEdit(c *app.Ctx) {
	c.Authorize("roles", "edit")
	c.View("roles/form", app.M{"title": c.T("Edit role"), "role": roleFind(c, c.ParamInt("id")), "scopes": scopeOpts(c)})
}

// roleGuardRank: only an administrator may change their own role, a role ranked as high as theirs, or raise a role to their rank;
// otherwise "edit roles" would be a way to make yourself an administrator.
func roleGuardRank(c *app.Ctx, role db.Row, newLevel any) {
	u := c.MustUser()
	if u.IsAdmin() {
		return
	}
	if role != nil && (role.Int("id") == u.RoleID || role.Int("level") >= u.RoleLevel) {
		app.Abort(http.StatusForbidden, c.T("You can only change roles ranked below your own."))
	}
	if newLevel != nil && conv.Int(newLevel) >= u.RoleLevel {
		app.Invalid("level", c.T("Choose a level below your own (:n).", "n", u.RoleLevel))
	}
}

func roleUpdate(c *app.Ctx) {
	c.Authorize("roles", "edit")
	id := c.ParamInt("id")
	role := roleFind(c, id)
	roleGuardRank(c, role, nil)
	data := roleValidated(c, role)
	roleGuardRank(c, nil, data.Get("level"))
	if role.Str("slug") == "admin" {
		data.Set("data_scope", "all")
		data.Set("level", 100)
	}
	cols := cleanCols(data)
	cols = append(cols, db.Col{Name: "updated_at", Value: conv.NowString()})
	db.Core.Update("roles", cols, db.Cols{{Name: "id", Value: id}})
	c.MustUser().ForgetPermissions()
	changes := app.Diff(map[string]any(role), data.Map(), data.Keys)
	if changes.Len() > 0 {
		app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "role", ID: id, Label: data.Str("name"), Text: `Updated :type ":label"`,
			Params: []any{"type", "role", "label", data.Str("name")}, Props: app.M{"changes": changes}})
	}
	c.Flash("success", c.T("Role saved."))
	c.Redirect("/roles")
}

func roleDestroy(c *app.Ctx) {
	c.Authorize("roles", "delete")
	id := c.ParamInt("id")
	role := roleFind(c, id)
	roleGuardRank(c, role, nil)
	if role.Bool("is_system") {
		app.Invalid("role", c.T("Built-in roles cannot be deleted."))
	}
	if conv.Int(db.Scalar("SELECT COUNT(*) FROM users WHERE role_id = ? AND deleted_at IS NULL", id)) > 0 {
		app.Invalid("role", c.T("Move everyone out of this role before deleting it."))
	}
	db.Exec("DELETE FROM roles WHERE id = ?", id)
	app.LogActivity(c, app.ActivityEntry{Action: "deleted", Type: "role", ID: id, Label: role.Str("name"), Text: `Deleted :type ":label"`,
		Params: []any{"type", "role", "label", role.Str("name")}})
	c.Flash("success", c.T("Role deleted."))
	c.Redirect("/roles")
}

type roleMatrixRow struct {
	Key, Label string
	Cells      []roleMatrixCell
}

type roleMatrixCell struct {
	Action, Aria string
	On           bool
}

func rolePermissions(c *app.Ctx) {
	c.Authorize("roles", "view")
	id := c.ParamInt("id")
	role := roleFind(c, id)
	isAdmin := role.Str("slug") == "admin"
	var actions []string
	for _, a := range app.Actions() {
		if isAdmin || a != "import" { // importing data is for administrators only
			actions = append(actions, a)
		}
	}
	m := map[int]db.Row{}
	for _, rp := range db.Select("SELECT * FROM role_permissions WHERE role_id = ?", id) {
		m[rp.Int("permission_id")] = rp
	}
	var rows []roleMatrixRow
	for _, p := range db.Select("SELECT * FROM permissions ORDER BY module, sort") {
		rp := m[p.Int("id")]
		mr := roleMatrixRow{Key: p.Str("key"), Label: c.T(p.Str("label"))}
		for _, a := range actions {
			mr.Cells = append(mr.Cells, roleMatrixCell{Action: a, Aria: c.T(p.Str("label")) + " " + c.T(a), On: isAdmin || (rp != nil && rp.Bool("can_"+a))})
		}
		rows = append(rows, mr)
	}
	var actionLabels []string
	for _, a := range actions {
		actionLabels = append(actionLabels, c.T(conv.Ucfirst(a)))
	}
	c.View("roles/permissions", app.M{
		"title": c.T(":role permissions", "role", c.T(role.Str("name"))), "role": role, "isAdmin": isAdmin,
		"rows": rows, "actionLabels": actionLabels, "canChange": c.Can("roles", "edit") && !isAdmin,
	})
}

func roleSavePermissions(c *app.Ctx) {
	c.Authorize("roles", "edit")
	id := c.ParamInt("id")
	role := roleFind(c, id)
	if role.Str("slug") == "admin" {
		app.Abort(http.StatusForbidden, c.T("Admin always has full access."))
	}
	roleGuardRank(c, role, nil)

	input := app.InMap(c.InputAny("perm"))
	changes := &app.Changes{}
	yn := func(b bool) string {
		if b {
			return "yes"
		}
		return "no"
	}
	db.Core.Transaction(func(tx db.Q) {
		old := map[string]db.Row{}
		for _, r := range tx.Select("SELECT rp.*, p.key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE role_id = ?", id) {
			old[r.Str("key")] = r
		}
		for _, p := range tx.Select("SELECT id, key FROM permissions") {
			key := p.Str("key")
			var cols []string
			params := []any{id, p["id"]}
			for _, a := range app.Actions() {
				v := !conv.Empty(mapGet(app.InMap(mapGet(input, key)), a))
				before := old[key] != nil && old[key].Bool("can_"+a)
				if before != v {
					changes.Set(key+"."+a, app.Change{From: yn(before), To: yn(v)})
				}
				cols = append(cols, "can_"+a)
				params = append(params, v)
			}
			sets := make([]string, len(cols))
			for i, col := range cols {
				sets[i] = col + " = EXCLUDED." + col
			}
			tx.Exec("INSERT INTO role_permissions (role_id, permission_id, "+strings.Join(cols, ", ")+") VALUES (?, ?"+strings.Repeat(", ?", len(cols))+")"+
				" ON CONFLICT (role_id, permission_id) DO UPDATE SET "+strings.Join(sets, ", "), params...)
		}
	})

	c.MustUser().ForgetPermissions()
	if changes.Len() > 0 {
		app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "role", ID: id, Label: role.Str("name"), Text: `Changed permissions of role ":label"`,
			Params: []any{"label", role.Str("name")}, Props: app.M{"changes": changes}})
		c.Flash("success", c.T(":n permission(s) changed for :role.", "n", changes.Len(), "role", c.T(role.Str("name"))))
	} else {
		c.Flash("success", c.T("Nothing changed."))
	}
	c.Redirect("/roles/" + conv.Str(id) + "/permissions")
}

func roleFind(c *app.Ctx, id int) db.Row {
	r := db.First("SELECT * FROM roles WHERE id = ?", id)
	if r == nil {
		app.Abort(http.StatusNotFound, c.T("Role not found."))
	}
	return r
}

var roleSlugRe = regexp.MustCompile(`[^a-z0-9]+`)

func roleValidated(c *app.Ctx, role db.Row) *app.Clean {
	var keys []string
	for _, p := range config.AsPairs(config.Get("core.scopes")) {
		keys = append(keys, p.Key)
	}
	data := c.ValidateInput(
		app.Rule{Field: "name", Rules: "required|max:80", Label: c.T("Role name")},
		app.Rule{Field: "description", Rules: "nullable|max:255"},
		app.Rule{Field: "level", Rules: "required|integer", Label: c.T("Rank")},
		app.Rule{Field: "data_scope", Rules: "required|in:" + strings.Join(keys, ","), Label: c.T("Records they can see")},
	)
	data.Set("level", max(1, min(99, data.Int("level"))))
	if role == nil {
		slug := strings.Trim(roleSlugRe.ReplaceAllString(strings.ToLower(data.Str("name")), "_"), "_")
		if slug == "" {
			slug = "role"
		}
		if db.Scalar("SELECT 1 FROM roles WHERE slug = ?", slug) != nil {
			slug += "_" + randHex(2)[:4]
		}
		data.Set("slug", slug)
	}
	return data
}

func cleanCols(d *app.Clean) db.Cols {
	var out db.Cols
	for _, k := range d.Keys {
		out = append(out, db.Col{Name: k, Value: d.Get(k)})
	}
	return out
}

func cleanMap(d *app.Clean) *config.Map {
	m := config.NewMap()
	for _, k := range d.Keys {
		m.Set(k, d.Get(k))
	}
	return m
}
