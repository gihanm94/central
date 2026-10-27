package crm

import (
	"html/template"
	"math"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

// A piece of work with dates, several responsible people, files, comments and a list of tasks (ProjectController).

var projects = (&screen{
	entity: "project", prefix: "PJ", dateCode: true, files: true, comments: true,
	res: &app.Resource{Resource: "crm_projects", Table: "projects", Type: "project", Singular: "project", Plural: "projects", Base: "/crm/projects", Icon: "folder",
		OrderBy:    "CASE t.status WHEN 'ACTIVE' THEN 0 WHEN 'PLANNING' THEN 1 WHEN 'ON_HOLD' THEN 2 ELSE 3 END, t.end_date NULLS LAST, t.id DESC",
		Searchable: []string{"t.name", "t.code", "t.description"},
		SelectSQL: func(c *app.Ctx) string {
			return `SELECT t.*,
                  (SELECT count(*) FROM tasks k WHERE k.project_id = t.id AND k.deleted_at IS NULL) AS task_total,
                  (SELECT count(*) FROM tasks k WHERE k.project_id = t.id AND k.deleted_at IS NULL AND k.status = 'DONE') AS task_done
                FROM projects t`
		},
		Label:     func(c *app.Ctx, row db.Row) string { return row.Str("name") },
		ExtraMeta: func(c *app.Ctx) app.M { return app.M{"wizard_style": "dots"} },
	},
}).setup()

type peopleRow struct {
	ID                  int
	Name, Department, Q string
	On                  bool
}

// peopleExtra: the "pick several people" field; selected = ids ticked at the start (old input wins).
func peopleExtra(c *app.Ctx, name string, selected []int) app.M {
	picked := map[string]bool{}
	if o, ok := oldVal(c, name); ok {
		for _, v := range app.InStrings(o) {
			picked[v] = true
		}
	} else {
		for _, i := range selected {
			picked[php.Str(i)] = true
		}
	}
	var rows []peopleRow
	for _, p := range people() {
		rows = append(rows, peopleRow{ID: p.ID, Name: p.Name, Department: p.Department, Q: strings.ToLower(p.Name + " " + p.Department), On: picked[php.Str(p.ID)]})
	}
	return app.M{"people": rows}
}

func dateRange(c *app.Ctx, a, b any) string {
	return app.FormatDate(c, a, "d M Y") + " → " + app.FormatDate(c, b, "d M Y")
}

func endOfDayPast(v any) bool {
	return php.Str(v) != "" && php.StrToTime(php.Str(v)+" 23:59:59") < php.Now().Unix()
}

func init() {
	s := projects
	r := s.res
	r.Filters = func(c *app.Ctx) []app.Filter {
		uid := c.MustUser().ID
		return append([]app.Filter{
			{Name: "status", Label: c.T("All statuses"), Options: tr(c, projectStatuses), Column: "t.status"},
			{Name: "resp", Label: c.T("Everyone"), Options: []app.Opt{{Value: "me", Label: c.T("I am responsible")}},
				SQL: "EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = t.id AND pm.user_id = ?)", Bind: func(string) any { return uid }},
		}, commonFilters(c)...)
	}
	r.Fields = func(c *app.Ctx, row db.Row) []app.Field {
		no := false
		sel := []int{c.MustUser().ID}
		if row != nil {
			sel = respIDs("project", row.Int("id"))
		}
		f := []app.Field{
			{Section: c.T("Basic info"), Icon: "folder", Help: c.T("Name, status and dates")},
			{Name: "name", Label: c.T("Project name"), Rules: "required|max:200", Span: 2, Placeholder: c.T("e.g. Factory line upgrade"), Example: "Factory line upgrade"},
			{Name: "status", Label: c.T("Status"), Type: "select", Options: tr(c, projectStatuses), Rules: "required", Default: "PLANNING", Search: &no, Span: 2},
			{Name: "start_date", Label: c.T("Start date"), Type: "date", Rules: "nullable|date"},
			{Name: "end_date", Label: c.T("End date"), Type: "date", Rules: "nullable|date"},
			{Name: "code", Label: c.T("Code"), Rules: "nullable|max:30", HideForm: true},

			{Section: c.T("Responsible"), Icon: "users", Help: c.T("Who works on the project")},
			{Name: "members", Label: c.T("People responsible"), Type: "custom", Partial: "crm/fields/people", Span: 2, NoTable: true, NoImport: true, Rules: "nullable",
				Extra: peopleExtra(c, "members", sel), Help: c.T("Everyone ticked here sees the project and its tasks and can update them.")},

			{Section: c.T("Description"), Icon: "list", Help: c.T("Rich text notes about this project")},
			{Name: "description", Label: c.T("Description"), Type: "richtext", Span: 2, Rules: "nullable", Counter: true},
		}
		f = append(f, s.filesFields(c, row)...)
		return append(f, s.ownershipFields(c, row)...)
	}
	s.hydrateMore = func(c *app.Ctx, rows []db.Row) []db.Row {
		ids := respIDsFor("project", col(rows, "id"))
		pm := peopleMap(people())
		for _, r := range rows {
			m := ids[r.Int("id")]
			r["member_ids"] = m
			var ns []string
			for _, u := range m {
				if p, ok := pm[u]; ok {
					ns = append(ns, p.Name)
				}
			}
			r["member_names"] = ns
		}
		return rows
	}
	r.Columns = func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "name", Label: c.T("Project"), Primary: true, Sort: "t.name", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return h(uiTitle(c, r.Str("name"), r.Str("code"), "/crm/projects/"+r.Str("id")))
			}},
			{Key: "status", Label: c.T("Status"), Sort: "t.status", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return h(uiBadge(trLabel(c, projectStatuses, r.Str("status")), php.OrStr(projectTones[r.Str("status")], "neutral")))
			}},
			{Key: "period", Label: c.T("Period"), Sort: "t.start_date", Render: func(c *app.Ctx, r db.Row) template.HTML {
				if r["start_date"] == nil && r["end_date"] == nil {
					return h(uiDash())
				}
				return h(`<span class="tabular-nums text-steel">` + app.E(app.FormatDate(c, r["start_date"], "d M")+" → "+app.FormatDate(c, r["end_date"], "d M Y")) + `</span>`)
			}},
			{Key: "people", Label: c.T("Responsible"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				n, _ := r["member_names"].([]string)
				return h(uiAvatars(n, 4))
			}},
			{Key: "tasks", Label: c.T("Tasks"), Sort: "task_total", Render: func(c *app.Ctx, r db.Row) template.HTML {
				if r.Int("task_total") > 0 {
					return h(meter(c, float64(r.Int("task_done"))/float64(r.Int("task_total"))*100, php.Str(r.Int("task_done"))+"/"+php.Str(r.Int("task_total"))))
				}
				return h(uiDash())
			}},
			createdColumn(c),
		}
	}
	s.prepareMore = func(c *app.Ctx, data *app.Clean, existing db.Row) *app.Clean {
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
		return data
	}
	s.afterSave = func(c *app.Ctx, id int, data *app.Clean, existing db.Row) {
		var ids []int
		for _, v := range app.InList(data.Get("members")) {
			ids = append(ids, php.Int(v))
		}
		if existing == nil {
			ids = append(ids, c.MustUser().ID) // whoever creates it is responsible for it
		}
		name := data.Str("name")
		if name == "" && existing != nil {
			name = existing.Str("name")
		}
		respSync(c, "project", id, ids, c.MustUser().ID, name, true)
	}
	r.BeforeDelete = func(c *app.Ctx, row db.Row) {
		// tasks go with the project (ON DELETE CASCADE only fires on a hard delete)
		db.On("crm").Exec("UPDATE tasks SET deleted_at = now() WHERE project_id = ? AND deleted_at IS NULL", row["id"])
	}
	s.badges = func(c *app.Ctx, r db.Row) []string {
		b := []string{uiBadge(trLabel(c, projectStatuses, r.Str("status")), php.OrStr(projectTones[r.Str("status")], "neutral"))}
		if r.Str("end_date") != "" && !inStrs(r.Str("status"), []string{"DONE", "CANCELLED"}) && endOfDayPast(r["end_date"]) {
			b = append(b, uiBadge(c.T("Overdue"), "danger"))
		}
		return b
	}
	s.subtitle = func(c *app.Ctx, r db.Row) string {
		p := ""
		if r["start_date"] != nil || r["end_date"] != nil {
			p = dateRange(c, r["start_date"], r["end_date"])
		}
		return joinNonEmpty(" · ", r.Str("code"), p)
	}
	s.showTop = func(c *app.Ctx, row db.Row) string {
		tasks := projectTasks(c, row)
		done, late := 0, 0
		open := 0
		for _, t := range tasks {
			if t.Str("status") == "DONE" {
				done++
			}
			if !inStrs(t.Str("status"), []string{"DONE", "CANCELLED"}) {
				open++
				if endOfDayPast(t["end_date"]) {
					late++
				}
			}
		}
		pct := 0
		if len(tasks) > 0 {
			pct = int(phpRound(float64(done)/float64(len(tasks))*100, 0))
		}
		data := app.M{"row": row, "total": len(tasks), "done": done, "late": late, "pct": pct}
		if row.Str("end_date") != "" {
			left := int(math.Floor(float64(php.StrToTime(row.Str("end_date")+" 23:59:59")-php.Now().Unix()) / 86400))
			if !inStrs(row.Str("status"), []string{"DONE", "CANCELLED"}) {
				data["showLeft"], data["left"], data["leftAbs"] = true, left, int(math.Abs(float64(left)))
				if left >= 0 {
					data["leftLabel"] = c.T("Days left")
				} else {
					data["leftLabel"] = c.T("Days late")
				}
			}
		}
		return string(c.Partial("crm/panels/project-summary", data))
	}
	s.showMain = func(c *app.Ctx, row db.Row) string {
		tasks := projectTasks(c, row)
		for _, t := range tasks {
			closed := inStrs(t.Str("status"), []string{"DONE", "CANCELLED"})
			late := !closed && endOfDayPast(t["end_date"])
			t["_closed"], t["_late"] = closed, late
			if t["start_date"] != nil || t["end_date"] != nil {
				t["_period"] = app.FormatDate(c, t["start_date"], "d M") + " → " + app.FormatDate(c, t["end_date"], "d M Y")
			} else {
				t["_period"] = ""
			}
			n, _ := t["assignee_names"].([]string)
			t["_avatars"] = h(uiAvatars(n, 4))
			t["_badge"] = h(uiBadge(trLabel(c, taskStatuses, t.Str("status")), php.OrStr(taskTones[t.Str("status")], "neutral")))
		}
		return string(c.Partial("crm/panels/project-tasks", app.M{"project": row, "tasks": tasks, "canAdd": canAddTasks(c, c.MustUser(), row),
			"canEdit": c.Can("crm_tasks", "edit"), "statuses": tr(c, taskStatuses)}))
	}
	s.showSide = func(c *app.Ctx, row db.Row) string { return responsiblePanel(c, "project", row) }
	s.statCards = func(c *app.Ctx) []app.Stat {
		open := "t.status IN ('PLANNING','ACTIVE','ON_HOLD')"
		return []app.Stat{
			card(c.T("Active projects"), s.figure(c, "count(*)", "t.status = 'ACTIVE'", nil, ""), "", "info", "folder", "/crm/projects?status=ACTIVE"),
			card(c.T("Overdue"), s.figure(c, "count(*)", open+" AND t.end_date < CURRENT_DATE", nil, ""), "", "danger", "clock", ""),
			card(c.T("Open tasks"), s.figure(c, "count(*)", "k.status NOT IN ('DONE','CANCELLED') AND k.deleted_at IS NULL", nil, "projects t JOIN tasks k ON k.project_id = t.id"), "", "neutral", "tasks", ""),
			card(c.T("Done"), s.figure(c, "count(*)", "t.status = 'DONE'", nil, ""), "", "success", "check", ""),
		}
	}
}

func projectTasks(c *app.Ctx, row db.Row) []db.Row {
	sq, sp := visible(c.MustUser(), "t", "task")
	tasks := db.On("crm").Select("SELECT t.* FROM tasks t WHERE t.project_id = ? AND t.deleted_at IS NULL AND "+sq+" ORDER BY (t.status IN ('DONE','CANCELLED')), t.end_date NULLS LAST, t.id", append([]any{row.Int("id")}, sp...)...)
	ids := respIDsFor("task", col(tasks, "id"))
	pm := peopleMap(people())
	for _, k := range tasks {
		var ns []string
		for _, u := range ids[k.Int("id")] {
			if p, ok := pm[u]; ok {
				ns = append(ns, p.Name)
			}
		}
		k["assignee_names"] = ns
	}
	return tasks
}

func canAddTasks(c *app.Ctx, u *app.CurrentUser, project db.Row) bool {
	return c.Can("crm_tasks", "create") && (canChange(u, "project", project) || (u.DepartmentID != 0 && project.Int("department_id") == u.DepartmentID))
}

// responsiblePanel: the people responsible for a project / task and the history of changes.
func responsiblePanel(c *app.Ctx, typ string, row db.Row) string {
	ids := respIDs(typ, row.Int("id"))
	pm := peopleMap(people())
	var list []app.M
	for _, i := range ids {
		if p, ok := pm[i]; ok {
			list = append(list, app.M{"name": p.Name, "department": p.Department})
		} else {
			list = append(list, app.M{"name": c.T("Former member"), "department": nil})
		}
	}
	history := respHistory(typ, row.Int("id"))
	title := c.T("Responsible")
	var seed []string
	for _, h := range history {
		seed = append(seed, h.Str("id"))
	}
	dlg := "resp-history-" + md5Short(title+"["+strings.Join(seed, ",")+"]")
	data := app.M{"title": title, "people": list, "history": history, "dlg": dlg}
	if len(history) > 0 {
		data["historyHTML"] = historyDialog(c, dlg, c.T(":title · history", "title", title), history, nil)
	}
	return string(c.Partial("crm/panels/responsible", data))
}
