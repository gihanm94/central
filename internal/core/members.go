package core

import (
	"html/template"
	"net/http"
	"regexp"
	"strings"
	"sync"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/php"
)

// Members = employees (users + employee_profiles) (MemberController).
// Non-admins can only manage people whose role is below their own, inside their scope.

var memberProfileCols = []string{"employee_code", "job_title", "phone", "joined_at", "date_of_birth", "gender", "employee_level", "employment_status", "employment_type"}

var memberUsernameRe = regexp.MustCompile(`^[a-z0-9._-]{3,60}$`)

// memberInvites: prepare() marks a new member who gets the "set your password" e-mail; saved() sends it
// (PHP kept the flag as $data['_invite'], which tableData() dropped).
var memberInvites sync.Map

func translatedOpts(c *app.Ctx, opts []app.Opt) []app.Opt {
	out := make([]app.Opt, len(opts))
	for i, o := range opts {
		o.Label = c.T(o.Label)
		out[i] = o
	}
	return out
}

// optsMissing: the entries of all that opts does not offer. The form puts AllOptions before Options when the value is
// not offered; PHP's `all_options + options` (a key union) never listed a value twice.
func optsMissing(all, opts []app.Opt) []app.Opt {
	var out []app.Opt
	for _, o := range all {
		if _, ok := app.OptLabel(opts, o.Value); !ok {
			out = append(out, o)
		}
	}
	return out
}

var members = &app.Resource{
	Resource: "members", Table: "users", Type: "user", Singular: "member", Plural: "members",
	Base: "/members", Icon: "users", OrderBy: "t.is_active DESC, r.level DESC, t.name",
	ScopeCols:  app.ScopeCols{Owner: []string{"id"}, Department: "department_id", Team: "team_id"},
	Searchable: []string{"t.name", "t.username", "t.email", "p.employee_code", "p.job_title"},
	SelectSQL: func(c *app.Ctx) string {
		return `SELECT t.*, r.name AS role_name, r.slug AS role_slug, r.level AS role_level,
                       d.name AS department_name, tm.name AS team_name, m.name AS manager_name,
                       p.employee_code, p.job_title, p.phone, p.joined_at, p.date_of_birth,
                       p.gender, p.employee_level, p.employment_status, p.employment_type
                  FROM users t
                  JOIN roles r ON r.id = t.role_id
             LEFT JOIN departments d ON d.id = t.department_id
             LEFT JOIN teams tm ON tm.id = t.team_id
             LEFT JOIN users m ON m.id = t.manager_id
             LEFT JOIN employee_profiles p ON p.user_id = t.id`
	},
	Fields: memberFields,
	Columns: func(c *app.Ctx) []app.Column {
		statusTone := map[string]string{"ACTIVE": "bg-emerald-50 text-emerald-800", "PROBATION": "bg-amber-50 text-amber-800", "SUSPENDED": "bg-signal-50 text-signal-800"}
		return []app.Column{
			{Key: "name", Label: c.T("Name"), Primary: true, Render: func(c *app.Ctx, r db.Row) template.HTML {
				who := r.Str("email")
				if r.Str("username") != "" {
					who = "@" + r.Str("username") + " · " + who
				}
				return app.HTML(`<a href="` + c.URL("/members/"+r.Str("id")) + `" class="flex items-center gap-3">` +
					string(c.Partial("partials/avatar", app.M{"name": r["name"], "avatar": r["avatar"], "size": "size-8"})) +
					`<span class="min-w-0"><span class="block truncate font-medium hover:text-signal-700">` + app.E(r["name"]) + `</span><span class="block truncate text-xs text-steel">` + app.E(who) + `</span></span></a>`)
			}},
			{Key: "role", Label: c.T("Role"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				s := app.E(c.T(r.Str("role_name")))
				if r.Str("data_scope") != "" {
					s += ` <span class="badge ml-1 bg-amber-50 text-amber-800" title="` + app.E(c.T("Custom data scope")) + `">` + app.E(c.T("custom")) + `</span>`
				}
				return app.HTML(s)
			}},
			{Key: "where", Label: c.T("Department / team"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				s := app.E(php.Coalesce(r["department_name"], "—"))
				if r.Str("team_name") != "" {
					s += `<span class="block text-xs text-steel">` + app.E(r["team_name"]) + `</span>`
				}
				return app.HTML(s)
			}},
			{Key: "job", Label: c.T("Job title"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				s := app.E(php.Coalesce(r["job_title"], "—"))
				if r.Str("employee_level") != "" {
					s += ` <span class="badge ml-1 bg-graphite-900/6 text-graphite-800">` + app.E(app.EnumLabel(c, "levels", r.Str("employee_level"))) + `</span>`
				}
				return app.HTML(s)
			}},
			{Key: "status", Label: c.T("Employment"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				tone, ok := statusTone[r.Str("employment_status")]
				if !ok {
					tone = "bg-graphite-900/6 text-steel"
				}
				s := `<span class="badge ` + tone + `">` + app.E(app.EnumLabel(c, "statuses", r.Str("employment_status"))) + `</span>`
				if !r.Bool("is_active") {
					s += ` <span class="badge bg-graphite-900 text-white">` + app.E(c.T("Blocked")) + `</span>`
				}
				s += `<span class="mt-0.5 block text-xs text-steel">` + app.E(app.EnumLabel(c, "types", r.Str("employment_type"))) + `</span>`
				return app.HTML(s)
			}},
			{Key: "login", Label: c.T("Last sign-in"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				return app.HTML(`<span class="text-xs text-steel">` + app.E(app.TimeAgo(c, r["last_login_at"])) + `</span>`)
			}},
		}
	},
	Filters: func(c *app.Ctx) []app.Filter {
		return []app.Filter{
			{Name: "role", Label: c.T("All roles"), Options: translatedOpts(c, app.OptionsAllRoles()), Column: "t.role_id"},
			{Name: "department", Label: c.T("All departments"), Options: app.OptionsDepartments(c.MustUser()), Column: "t.department_id"},
			{Name: "status", Label: c.T("Any employment status"), Options: app.EnumOptions(c, "statuses"), Column: "p.employment_status"},
			{Name: "active", Label: c.T("Can and cannot sign in"), Options: []app.Opt{{Value: "true", Label: c.T("Can sign in")}, {Value: "false", Label: c.T("Blocked")}},
				Column: "t.is_active", Bind: func(v string) any { return v == "true" }},
		}
	},
	Owner: func(row db.Row) int { return row.Int("id") },
	// Admin can manage anyone (but not delete themselves); others only people ranked below them.
	CanModify: func(c *app.Ctx, row db.Row, action string) bool {
		u := c.MustUser()
		if row.Int("id") == u.ID {
			return action == "edit"
		}
		return u.IsAdmin() || row.Int("role_level") < u.RoleLevel
	},
	Prepare: memberPrepare,
	Saved:   memberSaved,
	BeforeDelete: func(c *app.Ctx, row db.Row) {
		db.Exec("UPDATE users SET is_active = FALSE, session_version = session_version + 1 WHERE id = ?", row["id"])
		db.Exec("DELETE FROM remember_tokens WHERE user_id = ?", row["id"])
		// Free the e-mail so it can be used again
		db.Exec("UPDATE users SET email = email || '.deleted.' || id WHERE id = ?", row["id"])
	},
	RowLinks: func(c *app.Ctx, row db.Row) []app.Link {
		if c.Can("roles", "view") {
			return []app.Link{{Label: c.T("Access rules"), URL: "/members/" + row.Str("id") + "/access", Icon: "shield"}}
		}
		return nil
	},
	TopExtra: func(c *app.Ctx) template.HTML {
		n := 0
		if accessRequestReviewer(c) {
			n = AccessRequestsPending()
		}
		if n == 0 {
			return ""
		}
		return app.HTML(`<a href="` + app.E(c.URL("/members/requests")) + `" class="mt-4 flex items-center gap-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-600/20 hover:bg-amber-100">` +
			string(app.Icon("user", "size-5")) + `<span><strong>` + app.E(c.T(":n account requests waiting", "n", n)) + `</strong> · ` + app.E(c.T("Review them")) + `</span></a>`)
	},
	Intro: func(c *app.Ctx) string {
		u := c.MustUser()
		if u.IsAdmin() {
			return ""
		}
		return c.T("You can manage people in your :scope view whose role is below yours.", "scope", strings.ToLower(c.T(scopeLabel(u.Scope()))))
	},
}

// scopeLabel is config('core.scopes')[$key].
func scopeLabel(key string) string { return config.String("core.scopes."+key, "") }

func memberFields(c *app.Ctx, row db.Row) []app.Field {
	u := c.MustUser()
	id := ""
	self := false
	if row != nil {
		id = row.Str("id")
		self = row.Int("id") == u.ID
	}
	sfx := func(s string) string {
		if id != "" {
			return s
		}
		return ""
	}
	pwLabel, pwHelp := c.T("Password"), c.T("Leave empty to e-mail them a link to set their own.")
	if row != nil {
		pwLabel, pwHelp = c.T("New password"), c.T("Leave empty to keep the current password.")
	}
	deptRule := "required"
	if u.Scope() == "all" {
		deptRule = "nullable"
	}
	roleOpts := translatedOpts(c, app.OptionsRoles(u))
	var managers []app.Opt
	for _, o := range app.OptionsUsers(c, u, true) {
		if id == "" || o.Value != id {
			managers = append(managers, o)
		}
	}
	var managerAll []app.Opt
	if row != nil && row.Int("manager_id") != 0 {
		managerAll = []app.Opt{{Value: row.Str("manager_id"), Label: row.Str("manager_name")}}
	}
	return []app.Field{
		{Name: "name", Label: c.T("Full name"), Rules: "required|max:120", Example: "Jane Perera"},
		{Name: "avatar", Label: c.T("Photo"), Type: "file", NoImport: true, Rules: "nullable", Help: c.T("PNG, JPG or WebP, up to 2 MB.")},
		{Name: "username", Label: c.T("Username"), Rules: "nullable|max:60", Example: "jane.p",
			Help: c.T("Used to sign in. Letters, numbers, dot, dash, underscore. Leave empty to make one from the e-mail.")},
		{Name: "email", Label: c.T("Work e-mail"), Type: "email", Rules: "required|email|max:190|unique:users,email" + sfx(","+id), Example: "jane@acmeinter.com"},
		{Name: "password", Label: pwLabel, Type: "password", NoImport: true, Rules: "nullable|password", Help: pwHelp},
		{Name: "role_id", Label: c.T("Role"), Type: "select", Options: roleOpts, Rules: "required",
			AllOptions: optsMissing(translatedOpts(c, app.OptionsAllRoles()), roleOpts), Readonly: self},
		{Name: "is_active", Label: c.T("Can sign in"), Type: "checkbox", Default: true, Help: c.T("Untick to block access without deleting the record."), Readonly: self},

		{Section: c.T("Employment")},
		{Name: "employee_code", Label: c.T("Employee code"), NoTable: true, Rules: "nullable|max:30|unique:employee_profiles,employee_code" + sfx(","+id+",user_id"), Example: "EMP-0100"},
		{Name: "job_title", Label: c.T("Job title"), NoTable: true, Rules: "nullable|max:120", Example: "Technician"},
		{Name: "department_id", Label: c.T("Department"), Type: "select", Options: app.OptionsDepartments(u), Rules: deptRule},
		{Name: "team_id", Label: c.T("Team"), Type: "select", Options: app.OptionsTeams(u), Rules: "nullable"},
		{Name: "manager_id", Label: c.T("Reports to"), Type: "select", Options: managers, Rules: "nullable", AllOptions: managerAll},
		{Name: "employee_level", Label: c.T("Employee level"), Type: "select", NoTable: true, Options: app.EnumOptions(c, "levels"), Rules: "nullable", Example: "C3"},
		{Name: "employment_status", Label: c.T("Employment status"), Type: "select", NoTable: true, Options: app.EnumOptions(c, "statuses"), Rules: "required", Default: "ACTIVE"},
		{Name: "employment_type", Label: c.T("Employment type"), Type: "select", NoTable: true, Options: app.EnumOptions(c, "types"), Rules: "required", Default: "FULL_TIME"},
		{Name: "joined_at", Label: c.T("Joined on"), Type: "date", NoTable: true, Rules: "nullable|date", Example: php.Date("Y-m-d", php.Now())},

		{Section: c.T("Personal")},
		{Name: "gender", Label: c.T("Gender"), Type: "select", NoTable: true, Options: app.EnumOptions(c, "genders"), Rules: "nullable"},
		{Name: "date_of_birth", Label: c.T("Date of birth"), Type: "date", NoTable: true, Rules: "nullable|date"},
		{Name: "phone", Label: c.T("Phone"), NoTable: true, Rules: "nullable|max:30"},
	}
}

func memberPrepare(c *app.Ctx, data *app.Clean, existing db.Row) *app.Clean {
	// Photo: a new upload replaces the old one; no upload keeps it
	if file := c.File("avatar"); file != nil {
		data.Set("avatar", app.UploadImage(c, file, "avatars", 2048, false))
		if existing != nil && existing.Str("avatar") != "" {
			app.DeleteUpload(existing.Str("avatar"))
		}
	} else {
		data.Delete("avatar")
	}
	if data.Has("username") {
		name := strings.ToLower(strings.TrimSpace(data.Str("username")))
		switch {
		case name == "":
			if existing != nil {
				data.Delete("username")
			} else {
				data.Set("username", nil) // new: made from the e-mail
			}
		case !memberUsernameRe.MatchString(name):
			app.Invalid("username", c.T("Use 3–60 letters, numbers, dots, dashes or underscores (no spaces)."))
		default:
			exID := 0
			if existing != nil {
				exID = existing.Int("id")
			}
			if db.Scalar("SELECT 1 FROM users WHERE lower(username) = ? AND id <> ?", name, exID) != nil {
				app.Invalid("username", c.T("This username is already taken."))
			}
			data.Set("username", name)
		}
	}
	memberInvites.Delete(c)
	if !php.Empty(data.Get("password")) {
		data.Set("password", app.PasswordHash(data.Str("password")))
	} else if existing != nil {
		data.Delete("password")
	} else {
		data.Set("password", app.PasswordHash(randHex(16))) // they set their own via e-mail link
		memberInvites.Store(c, true)
	}

	if !php.Empty(data.Get("team_id")) {
		teamDept := db.Scalar("SELECT department_id FROM teams WHERE id = ?", data.Get("team_id"))
		if !php.Empty(data.Get("department_id")) && php.Int(teamDept) != data.Int("department_id") {
			app.Invalid("team_id", c.T("This team belongs to another department."))
		}
		if data.Get("department_id") == nil {
			data.Set("department_id", teamDept)
		}
	}
	return data
}

func memberSaved(c *app.Ctx, id int, data *app.Clean, existing db.Row) {
	var cols []string
	params := []any{id}
	for _, k := range data.Keys {
		if php.InList(k, memberProfileCols...) {
			cols = append(cols, k)
			v := data.Get(k)
			if s, ok := v.(string); ok && s == "" {
				v = nil
			}
			params = append(params, v)
		}
	}
	if len(cols) > 0 {
		sets := make([]string, len(cols))
		for i, col := range cols {
			sets[i] = col + " = EXCLUDED." + col
		}
		db.Exec("INSERT INTO employee_profiles (user_id, "+strings.Join(cols, ", ")+") VALUES (?"+strings.Repeat(", ?", len(cols))+")"+
			" ON CONFLICT (user_id) DO UPDATE SET "+strings.Join(sets, ", ")+", updated_at = now()", params...)
	}

	// Losing access or getting a new password ends their other sessions.
	if existing != nil && (data.Get("password") != nil || (data.Has("is_active") && !data.Bool("is_active"))) {
		db.Exec("UPDATE users SET session_version = session_version + 1 WHERE id = ?", id)
		db.Exec("DELETE FROM remember_tokens WHERE user_id = ?", id)
	}
	if id == c.UserID() {
		c.MustUser().ForgetPermissions()
	}

	if _, invite := memberInvites.LoadAndDelete(c); invite {
		memberSendInvite(c, id)
	}
}

func memberSendInvite(c *app.Ctx, id int) {
	u := db.First("SELECT name, email, username, locale FROM users WHERE id = ?", id)
	token := randHex(32)
	db.Exec(`INSERT INTO password_resets (email, token_hash, created_at) VALUES (lower(?), ?, now())
                  ON CONFLICT (email) DO UPDATE SET token_hash = EXCLUDED.token_hash, created_at = now()`, u["email"], sha256Hex(token))
	by := c.MustUser().Name
	c.With(u.Str("locale"), func() {
		app.Notify(c, u.Str("email"), u.Str("name"), c.T("Your account is ready"), []template.HTML{
			app.HTML(c.T("An account was created for you by :name.", "name", app.E(by))),
			app.HTML(c.T("Your username for signing in: :u", "u", app.E(u.Str("username")))),
			app.HTML(c.T("Choose your password with the button below. The link works for :minutes minutes; after that, use “Forgot password” on the sign-in page.", "minutes", config.Int("security.reset_minutes", 60))),
		}, &app.MailButton{Label: c.T("Set my password"), URL: c.URL("/reset-password", "token", token, "email", u.Str("email"))}, "")
	})
}

/* ------------------------------------------------- per-member access */

type accessRow struct {
	Label string
	Cells []template.HTML
}

func memberAccess(c *app.Ctx) {
	c.Authorize("roles", "view")
	id := c.ParamInt("id")
	row := members.Find(c, id)

	perms := db.Select("SELECT * FROM permissions ORDER BY module, sort")
	roleMap := map[int]db.Row{}
	for _, r := range db.Select("SELECT * FROM role_permissions WHERE role_id = ?", row["role_id"]) {
		roleMap[r.Int("permission_id")] = r
	}
	userMap := map[int]db.Row{}
	for _, r := range db.Select("SELECT * FROM user_permissions WHERE user_id = ?", id) {
		userMap[r.Int("permission_id")] = r
	}
	canChange := c.Can("roles", "edit") && members.CanModify(c, row, "edit") && row.Str("role_slug") != "admin"
	roleScope := db.Scalar("SELECT data_scope FROM roles WHERE id = ?", row["role_id"])

	actions := app.Actions()
	var scopes []app.Opt
	for _, p := range config.AsPairs(config.Get("core.scopes")) {
		scopes = append(scopes, app.Opt{Value: p.Key, Label: c.T(p.Label)})
	}
	roleScopeLabel, ok := app.OptLabel(scopes, php.Str(roleScope))
	if !ok {
		roleScopeLabel = php.Str(roleScope)
	}
	on := "bg-graphite-800 text-white ring-graphite-800"
	off := "bg-white text-steel ring-graphite-900/15"
	var rows []accessRow
	for _, p := range perms {
		rp := roleMap[p.Int("id")]
		up := userMap[p.Int("id")]
		ar := accessRow{Label: c.T(p.Str("label"))}
		for _, a := range actions {
			roleOn := rp != nil && rp.Bool("can_"+a)
			val := ""
			if up != nil && up["can_"+a] != nil {
				if up.Bool("can_" + a) {
					val = "allow"
				} else {
					val = "deny"
				}
			}
			yes := c.T("no")
			cls := off
			if roleOn {
				yes, cls = c.T("yes"), on
			}
			ar.Cells = append(ar.Cells, app.SelectField(c, "perm["+p.Str("key")+"]["+a+"]", []app.Opt{{Value: "allow", Label: c.T("Allow")}, {Value: "deny", Label: c.T("Deny")}}, val, app.M{
				"placeholder": c.T("Role (:v)", "v", yes),
				"size":        "sm", "wrap": "mx-auto w-28", "disabled": !canChange, "aria": c.T(p.Str("label")) + " " + c.T(a),
				"class": "rounded-md px-2 py-1 text-xs ring-1 " + cls,
				"tones": map[string]string{"allow": "!bg-signal-600 !text-white !ring-signal-600", "deny": "!bg-signal-600 !text-white !ring-signal-600"},
			}))
		}
		rows = append(rows, ar)
	}
	var actionLabels []string
	for _, a := range actions {
		actionLabels = append(actionLabels, c.T(php.Ucfirst(a)))
	}
	scopeSelect := app.SelectField(c, "data_scope", scopes, row.Str("data_scope"), app.M{
		"placeholder": c.T("Role default (:scope)", "scope", roleScopeLabel), "wrap": "sm:w-72", "disabled": !canChange, "aria": c.T("Which records they can see"),
	})
	c.View("members/access", app.M{
		"title": c.T("Access rules for :name", "name", row["name"]), "row": row, "canChange": canChange, "isAdmin": row.Str("role_slug") == "admin",
		"rows": rows, "actionLabels": actionLabels, "scopeSelect": scopeSelect,
		"intro": app.HTML(c.T("Starts from the <strong class=\"text-graphite-900\">:role</strong> role. Pick <em>Allow</em> or <em>Deny</em> to make an exception for this person only.", "role", app.E(c.T(row.Str("role_name"))))),
	})
}

func memberSaveAccess(c *app.Ctx) {
	c.Authorize("roles", "edit")
	id := c.ParamInt("id")
	row := members.Find(c, id)
	if !members.CanModify(c, row, "edit") || row.Str("role_slug") == "admin" {
		app.Abort(http.StatusForbidden, c.T("This member's access can't be changed."))
	}

	scope := c.Input("data_scope")
	if scope != "" && scopeLabel(scope) == "" {
		app.Invalid("data_scope", c.T("Choose a valid data scope."))
	}

	actions := app.Actions()
	input := app.InMap(c.InputAny("perm"))
	changes := &app.Changes{}
	label := func(x any) string {
		if x == nil {
			return "inherit"
		}
		if x.(bool) {
			return "allow"
		}
		return "deny"
	}

	db.Core.Transaction(func(tx db.Q) {
		if row.Str("data_scope") != scope {
			var sv any
			if scope != "" {
				sv = scope
			}
			tx.Exec("UPDATE users SET data_scope = ?, updated_at = now() WHERE id = ?", sv, id)
			from, to := row.Str("data_scope"), scope
			if from == "" {
				from = "role default"
			}
			if to == "" {
				to = "role default"
			}
			changes.Set("data scope", app.Change{From: from, To: to})
		}
		old := map[string]db.Row{}
		for _, r := range tx.Select("SELECT up.*, p.key FROM user_permissions up JOIN permissions p ON p.id = up.permission_id WHERE user_id = ?", id) {
			old[r.Str("key")] = r
		}
		tx.Exec("DELETE FROM user_permissions WHERE user_id = ?", id)

		for _, p := range tx.Select("SELECT id, key FROM permissions") {
			key := p.Str("key")
			cols := db.Cols{{Name: "user_id", Value: id}, {Name: "permission_id", Value: p["id"]}}
			set := false
			for _, a := range actions {
				v := app.InStr(mapGet(app.InMap(mapGet(input, key)), a))
				var val any
				if v == "allow" {
					val = true
				} else if v == "deny" {
					val = false
				}
				var before any
				if o := old[key]; o != nil && o["can_"+a] != nil {
					before = o.Bool("can_" + a)
				}
				if before != val {
					changes.Set(key+"."+a, app.Change{From: label(before), To: label(val)})
				}
				if val != nil {
					set = true
				}
				cols = append(cols, db.Col{Name: "can_" + a, Value: val})
			}
			if set {
				tx.Insert("user_permissions", cols, "")
			}
		}
	})

	if id == c.UserID() {
		c.MustUser().ForgetPermissions()
	}
	if changes.Len() > 0 {
		app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "user", ID: id, Label: row.Str("name"), Text: `Changed access rules for ":label"`,
			Params: []any{"label", row.Str("name")}, Props: app.M{"changes": changes}, Owner: id})
		c.Flash("success", c.T("Access rules saved."))
	} else {
		c.Flash("success", c.T("Nothing changed."))
	}
	c.Redirect("/members/" + php.Str(id) + "/access")
}

func mapGet(m *config.Map, k string) any {
	if m == nil {
		return nil
	}
	v, _ := m.Get(k)
	return v
}

func init() {
	signedIn(func(r app.Router) {
		members.Mount(r)
		r.Get("/members/{id}/access", memberAccess)
		r.Post("/members/{id}/access", memberSaveAccess)
	})
}
