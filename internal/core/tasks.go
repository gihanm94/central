package core

import (
	"html/template"
	"net/http"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

// Tasks (TaskController).

// taskStatusKeys / taskPriorityKeys keep PHP's STATUSES / PRIORITIES (English labels).
var taskStatusKeys = []app.Opt{{Value: "todo", Label: "To do"}, {Value: "in_progress", Label: "In progress"}, {Value: "review", Label: "In review"}, {Value: "done", Label: "Done"}}
var taskPriorityKeys = []app.Opt{{Value: "low", Label: "Low"}, {Value: "medium", Label: "Medium"}, {Value: "high", Label: "High"}, {Value: "urgent", Label: "Urgent"}}

// TaskStatuses is TaskController::statuses() (translated labels).
func TaskStatuses(c *app.Ctx) []app.Opt { return translatedOpts(c, taskStatusKeys) }

// TaskPriorities is TaskController::priorities().
func TaskPriorities(c *app.Ctx) []app.Opt { return translatedOpts(c, taskPriorityKeys) }

func optMap(opts []app.Opt) map[string]string {
	m := make(map[string]string, len(opts))
	for _, o := range opts {
		m[o.Value] = o.Label
	}
	return m
}

// TaskPerson is TaskController::person(): photo (or initials) and name, for a person column.
func TaskPerson(c *app.Ctx, name string, avatar any) template.HTML {
	return app.HTML(`<span class="flex items-center gap-2 whitespace-nowrap">` + string(c.Partial("partials/avatar", app.M{"name": name, "avatar": avatar, "size": "size-7", "extra": "text-[11px]"})) + `<span>` + app.E(name) + `</span></span>`)
}

var tasks = &app.Resource{
	Resource: "tasks", Table: "tasks", Type: "task", Singular: "task", Plural: "tasks",
	Base: "/tasks", Icon: "check", OrderBy: "CASE t.status WHEN 'done' THEN 1 ELSE 0 END, t.due_date NULLS LAST, t.id DESC",
	ScopeCols:  app.ScopeCols{Owner: []string{"assigned_to", "created_by"}, Department: "department_id", Team: "team_id"},
	Searchable: []string{"t.title", "a.name"},
	SelectSQL: func(c *app.Ctx) string {
		return `SELECT t.*, a.name AS assignee_name, a.avatar AS assignee_avatar, c.name AS creator_name, d.name AS department_name
                  FROM tasks t
             LEFT JOIN users a ON a.id = t.assigned_to
             LEFT JOIN users c ON c.id = t.created_by
             LEFT JOIN departments d ON d.id = t.department_id`
	},
	Fields: func(c *app.Ctx, row db.Row) []app.Field {
		// People who can only update tasks (Members) change status and notes, nothing else.
		limited := !c.Can("tasks", "create")
		users := app.OptionsPeople(c, c.MustUser(), true)
		var all []app.Opt
		if row != nil && row.Int("assigned_to") != 0 {
			all = []app.Opt{{Value: row.Str("assigned_to"), Label: row.Str("assignee_name"), Img: c.UploadURL(row["assignee_avatar"])}}
		}
		return []app.Field{
			{Name: "title", Label: c.T("Title"), Rules: "required|max:200", Span: 2, Readonly: limited, Example: "Inspect press guards"},
			{Name: "assigned_to", Label: c.T("Assigned to"), Type: "select", Options: users, Rules: "required", Readonly: limited, Rich: true, AllOptions: all},
			{Name: "priority", Label: c.T("Priority"), Type: "select", Options: TaskPriorities(c), Rules: "required", Default: "medium", Readonly: limited},
			{Name: "status", Label: c.T("Status"), Type: "select", Options: TaskStatuses(c), Rules: "required", Default: "todo"},
			{Name: "due_date", Label: c.T("Due date"), Type: "date", Rules: "nullable|date", Readonly: limited},
			{Name: "description", Label: c.T("Notes"), Type: "textarea", Span: 2, Rules: "nullable|max:5000"},
		}
	},
	Columns: func(c *app.Ctx) []app.Column {
		statuses := optMap(TaskStatuses(c))
		return []app.Column{
			{Key: "title", Label: c.T("Task"), Primary: true, Render: func(c *app.Ctx, r db.Row) template.HTML {
				overdue := r.Str("status") != "done" && r.Str("due_date") != "" && php.StrToTime(r.Str("due_date")) < php.StrToTime("today")
				s := `<a href="` + c.URL("/tasks/"+r.Str("id")) + `" class="font-medium hover:text-signal-700">` + app.E(r["title"]) + `</a>`
				if overdue {
					s += ` <span class="badge ml-1 bg-signal-50 text-signal-800">` + c.T("Overdue") + `</span>`
				}
				return app.HTML(s)
			}},
			{Key: "assignee", Label: c.T("Assigned to"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				if r.Str("assignee_name") == "" {
					return "—"
				}
				return TaskPerson(c, r.Str("assignee_name"), r["assignee_avatar"])
			}},
			{Key: "priority", Label: c.T("Priority"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				return c.Partial("partials/priority", app.M{"p": r["priority"]})
			}},
			{Key: "status", Label: c.T("Status"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				return c.Partial("partials/status", app.M{"s": r["status"], "labels": statuses})
			}},
			{Key: "due", Label: c.T("Due"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				return app.HTML(`<span class="tabular-nums text-steel">` + app.FormatDate(c, r["due_date"], "d M") + `</span>`)
			}},
		}
	},
	Filters: func(c *app.Ctx) []app.Filter {
		return []app.Filter{
			{Name: "status", Label: c.T("All statuses"), Options: TaskStatuses(c), Column: "t.status"},
			{Name: "priority", Label: c.T("All priorities"), Options: TaskPriorities(c), Column: "t.priority"},
		}
	},
	Owner: func(row db.Row) int { return row.Int("assigned_to") },
	Prepare: func(c *app.Ctx, data *app.Clean, existing db.Row) *app.Clean {
		if existing == nil {
			data.Set("created_by", c.UserID())
		}
		if !php.Empty(data.Get("assigned_to")) {
			a := db.First("SELECT department_id, team_id FROM users WHERE id = ?", data.Get("assigned_to"))
			var dep, team any
			if a != nil {
				dep, team = a["department_id"], a["team_id"]
			}
			data.Set("department_id", dep)
			data.Set("team_id", team)
		}
		if data.Has("status") && data.Get("status") != nil {
			var before any
			if existing != nil {
				before = existing["status"]
			}
			if before != data.Get("status") {
				if data.Str("status") == "done" {
					data.Set("completed_at", php.NowString())
				} else {
					data.Set("completed_at", nil)
				}
			}
		}
		return data
	},
}

// taskShow is the standard detail page plus the "Move to" buttons (resource/show reads taskStatuses).
func taskShow(c *app.Ctx) {
	r := tasks
	c.Authorize(r.Resource, "view")
	row := r.Find(c, c.ParamInt("id"))
	fields := r.Fields(c, row)
	data := app.M{
		"title": r.LabelOf(c, row), "c": r.Meta(c), "row": row, "fields": fields,
		"canEdit":     c.Can(r.Resource, "edit"),
		"canDelete":   c.Can(r.Resource, "delete"),
		"canDownload": c.Can(r.Resource, "download"),
		"extra":       template.HTML(""), "links": []app.Link(nil),
		"display":      app.ShowFields(c, fields, row),
		"taskStatuses": TaskStatuses(c),
	}
	c.View("resource/show", data)
}

// taskStatus is the one-click status change from the list (anyone who can edit tasks).
func taskStatus(c *app.Ctx) {
	id := c.ParamInt("id")
	row := tasks.FindForChange(c, id, "edit")
	status := c.Input("status")
	en := optMap(taskStatusKeys)
	if _, ok := en[status]; !ok {
		app.Abort(http.StatusUnprocessableEntity, c.T("Unknown status."))
	}
	var done any
	if status == "done" {
		done = php.NowString()
	}
	db.Exec("UPDATE tasks SET status = ?, completed_at = ?, updated_at = now() WHERE id = ?", status, done, id)
	ch := &app.Changes{}
	ch.Set("status", app.Change{From: c.T(en[row.Str("status")]), To: c.T(en[status])})
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "task", ID: id, Label: row.Str("title"), Text: `Moved task ":label" to :status`,
		Params: []any{"label", row.Str("title"), "status", en[status]}, Props: app.M{"changes": ch}, Owner: row.Int("assigned_to")})
	c.Back("success", c.T("Task moved to :status.", "status", c.T(en[status])))
}

func init() {
	signedIn(func(r app.Router) {
		tasks.Mount(r, "show")
		r.Get("/tasks/{id}", taskShow)
		r.Post("/tasks/{id}/status", taskStatus)
	})
}
