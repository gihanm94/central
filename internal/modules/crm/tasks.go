package crm

import (
	"crypto/md5"
	"encoding/hex"
	"html/template"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

// A task inside a project, with its own dates, files, comments and several responsible people (TaskController).

func md5Short(s string) string {
	sum := md5.Sum([]byte(s))
	return hex.EncodeToString(sum[:])[:8]
}

var tasks = (&screen{
	entity: "task", prefix: "TK", dateCode: true, files: true, comments: true, remote: []pair{{"project_id", "projects"}},
	res: &app.Resource{Resource: "crm_tasks", Table: "tasks", Type: "task", Singular: "task", Plural: "tasks", Base: "/crm/tasks", Icon: "tasks",
		OrderBy:    "(t.status IN ('DONE','CANCELLED')), t.end_date NULLS LAST, t.id DESC",
		Searchable: []string{"t.name", "t.code", "t.description", "p.name"},
		SelectSQL: func(c *app.Ctx) string {
			return "SELECT t.*, p.name AS project_name, p.code AS project_code FROM tasks t JOIN projects p ON p.id = t.project_id AND p.deleted_at IS NULL"
		},
		Label:     func(c *app.Ctx, row db.Row) string { return row.Str("name") },
		ExtraMeta: func(c *app.Ctx) app.M { return app.M{"wizard_style": "dots"} },
	},
}).setup()

func init() {
	s := tasks
	r := s.res
	s.canModify = func(c *app.Ctx, row db.Row, action string) bool {
		u := c.MustUser()
		if action != "delete" {
			return canChange(u, "task", row)
		}
		project := db.On("crm").First("SELECT * FROM projects WHERE id = ?", row.Int("project_id"))
		return isManager(u, row) || (project != nil && isManager(u, project))
	}
	r.Filters = func(c *app.Ctx) []app.Filter {
		uid := c.MustUser().ID
		return append([]app.Filter{
			{Name: "status", Label: c.T("All statuses"), Options: tr(c, taskStatuses), Column: "t.status"},
			{Name: "resp", Label: c.T("Everyone"), Options: []app.Opt{{Value: "me", Label: c.T("Assigned to me")}},
				SQL: "EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id = ?)", Bind: func(string) any { return uid }},
			{Name: "when", Label: c.T("Any time"), Options: []app.Opt{{Value: "overdue", Label: c.T("Overdue")}},
				SQL: "t.status NOT IN ('DONE','CANCELLED') AND t.end_date < CURRENT_DATE AND ? = 'overdue'"},
		}, commonFilters(c)...)
	}
	r.Fields = func(c *app.Ctx, row db.Row) []app.Field {
		no := false
		sel := []int{c.MustUser().ID}
		if row != nil {
			sel = respIDs("task", row.Int("id"))
		}
		f := []app.Field{
			{Section: c.T("Basic info"), Icon: "tasks", Help: c.T("Project, name, status and dates")},
			s.remoteField(c, "project_id", row, app.Field{Label: c.T("Project"), Rules: "required", Span: 2, Placeholder: c.T("Select project"),
				Display: func(r db.Row) any { return r["project_name"] }, Extra: app.M{"href": func(r db.Row) string { return "/crm/projects/" + r.Str("project_id") }}}),
			{Name: "name", Label: c.T("Task name"), Rules: "required|max:200", Span: 2, Placeholder: c.T("e.g. Order the sensors"), Example: "Order the sensors"},
			{Name: "status", Label: c.T("Status"), Type: "select", Options: tr(c, taskStatuses), Rules: "required", Default: "TODO", Search: &no, Span: 2},
			{Name: "start_date", Label: c.T("Start date"), Type: "date", Rules: "nullable|date"},
			{Name: "end_date", Label: c.T("Due date"), Type: "date", Rules: "nullable|date"},
			{Name: "code", Label: c.T("Code"), Rules: "nullable|max:30", HideForm: true},

			{Section: c.T("Responsible"), Icon: "users", Help: c.T("Who does the task")},
			{Name: "assignees", Label: c.T("People responsible"), Type: "custom", Partial: "crm/fields/people", Span: 2, NoTable: true, NoImport: true, Rules: "nullable",
				Extra: peopleExtra(c, "assignees", sel), Help: c.T("Everyone ticked here sees the task and can update it.")},

			{Section: c.T("Description"), Icon: "list", Help: c.T("Rich text notes about this task")},
			{Name: "description", Label: c.T("Description"), Type: "richtext", Span: 2, Rules: "nullable", Counter: true},
		}
		f = append(f, s.filesFields(c, row)...)
		if row != nil { // a task stays in its project
			var out []app.Field
			for _, x := range f {
				if x.Name != "project_id" {
					out = append(out, x)
				}
			}
			f = out
		}
		return f
	}
	s.hydrateMore = func(c *app.Ctx, rows []db.Row) []db.Row {
		ids := respIDsFor("task", col(rows, "id"))
		pm := peopleMap(people())
		for _, r := range rows {
			m := ids[r.Int("id")]
			r["assignee_ids"] = m
			var ns []string
			for _, u := range m {
				if p, ok := pm[u]; ok {
					ns = append(ns, p.Name)
				}
			}
			r["assignee_names"] = ns
		}
		return rows
	}
	r.Columns = func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "name", Label: c.T("Task"), Primary: true, Sort: "t.name", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return h(uiTitle(c, r.Str("name"), r.Str("code"), "/crm/tasks/"+r.Str("id")))
			}},
			{Key: "project", Label: c.T("Project"), Sort: "p.name", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return h(uiLink(c, "/crm/projects/"+r.Str("project_id"), r.Str("project_name")))
			}},
			{Key: "people", Label: c.T("Responsible"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				n, _ := r["assignee_names"].([]string)
				return h(uiAvatars(n, 4))
			}},
			{Key: "period", Label: c.T("Period"), Sort: "t.end_date", Render: func(c *app.Ctx, r db.Row) template.HTML {
				if r["start_date"] == nil && r["end_date"] == nil {
					return h(uiDash())
				}
				late := !inStrs(r.Str("status"), []string{"DONE", "CANCELLED"}) && endOfDayPast(r["end_date"])
				cl := "text-steel"
				if late {
					cl = "font-medium text-signal-700"
				}
				return h(`<span class="tabular-nums ` + cl + `">` + app.E(app.FormatDate(c, r["start_date"], "d M")+" → "+app.FormatDate(c, r["end_date"], "d M Y")) + `</span>`)
			}},
			{Key: "status", Label: c.T("Status"), Sort: "t.status", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return h(uiBadge(trLabel(c, taskStatuses, r.Str("status")), php.OrStr(taskTones[r.Str("status")], "neutral")))
			}},
			createdColumn(c),
		}
	}
	s.prepareMore = func(c *app.Ctx, data *app.Clean, existing db.Row) *app.Clean {
		data.Delete("access")
		start, end := data.Get("start_date"), data.Get("end_date")
		if start == nil && existing != nil {
			start = existing["start_date"]
		}
		if end == nil && existing != nil {
			end = existing["end_date"]
		}
		if !php.Empty(start) && !php.Empty(end) && php.StrToTime(end) < php.StrToTime(start) {
			app.Invalid("end_date", c.T("The end cannot be before the start."))
		}
		if existing == nil {
			project := db.On("crm").First("SELECT * FROM projects WHERE id = ? AND deleted_at IS NULL", data.Int("project_id"))
			if project == nil || !canAddTasks(c, c.MustUser(), project) {
				app.Invalid("project_id", c.T("You cannot add tasks to this project."))
			}
			data.Set("department_id", project["department_id"]) // a task belongs to its project's department
		} else {
			data.Delete("project_id")
		}
		return data
	}
	s.afterSave = func(c *app.Ctx, id int, data *app.Clean, existing db.Row) {
		var ids []int
		for _, v := range app.InList(data.Get("assignees")) {
			ids = append(ids, php.Int(v))
		}
		name := data.Str("name")
		if name == "" && existing != nil {
			name = existing.Str("name")
		}
		respSync(c, "task", id, ids, c.MustUser().ID, name, true)
	}
	s.subtitle = func(c *app.Ctx, r db.Row) string {
		p := ""
		if r["start_date"] != nil || r["end_date"] != nil {
			p = dateRange(c, r["start_date"], r["end_date"])
		}
		return joinNonEmpty(" · ", r.Str("code"), r.Str("project_name"), p)
	}
	s.badges = func(c *app.Ctx, r db.Row) []string {
		b := []string{uiBadge(trLabel(c, taskStatuses, r.Str("status")), php.OrStr(taskTones[r.Str("status")], "neutral"))}
		if !inStrs(r.Str("status"), []string{"DONE", "CANCELLED"}) && r.Str("end_date") != "" && endOfDayPast(r["end_date"]) {
			b = append(b, uiBadge(c.T("Overdue"), "danger"))
		}
		return b
	}
	s.links = func(c *app.Ctx, r db.Row) []recLink {
		if c.Can("crm_projects", "view") {
			return []recLink{{URL: "/crm/projects/" + r.Str("project_id"), Label: c.T("Project") + ": " + r.Str("project_name"), Icon: "folder"}}
		}
		return nil
	}
	s.showSide = func(c *app.Ctx, row db.Row) string { return responsiblePanel(c, "task", row) }
	s.statCards = func(c *app.Ctx) []app.Stat {
		open := "t.status NOT IN ('DONE','CANCELLED')"
		return []app.Stat{
			card(c.T("Open"), s.figure(c, "count(*)", open, nil, ""), "", "neutral", "tasks", ""),
			card(c.T("In progress"), s.figure(c, "count(*)", "t.status = 'IN_PROGRESS'", nil, ""), "", "info", "tasks", "/crm/tasks?status=IN_PROGRESS"),
			card(c.T("Overdue"), s.figure(c, "count(*)", open+" AND t.end_date < CURRENT_DATE", nil, ""), "", "danger", "clock", "/crm/tasks?when=overdue"),
			card(c.T("Blocked"), s.figure(c, "count(*)", "t.status = 'BLOCKED'", nil, ""), "", "warn", "tasks", "/crm/tasks?status=BLOCKED"),
			card(c.T("Done this week"), s.figure(c, "count(*)", "t.status = 'DONE' AND t.updated_at >= date_trunc('week', now())", nil, ""), "", "success", "check", ""),
		}
	}
}

// taskSetStatus: one click status change from the project page.
func taskSetStatus(c *app.Ctx) {
	id := c.ParamInt("id")
	row := tasks.res.FindForChange(c, id, "edit")
	status := c.Input("status")
	if !hasPair(taskStatuses, status) {
		app.Invalid("status", c.T("Unknown status."))
	}
	db.On("crm").Exec("UPDATE tasks SET status = ?, updated_by = ?, updated_at = now() WHERE id = ?", status, c.MustUser().ID, id)
	to, _ := pairLabel(taskStatuses, status)
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "task", ID: id, Label: row.Str("name"), Text: `Marked task ":label" as :status`,
		Params: []any{"label", row.Str("name"), "status", to},
		Props:  app.M{"changes": app.M{"status": app.M{"from": trLabel(c, taskStatuses, row.Str("status")), "to": c.T(to)}}}, Owner: row.Int("owner_id"), Module: "crm"})
	c.Flash("success", c.T("Marked as :status.", "status", strings.ToLower(c.T(to))))
	c.Back("", "")
}
