package crm

import (
	"html/template"
	"regexp"
	"strings"

	"acme/internal/app"
	"acme/internal/conv"
	"acme/internal/db"
	"acme/internal/google"
)

// Calls, meetings, e-mails and tasks — linked to a company, a contact and/or an opportunity. Has files and comments (ActivityController).

var activities = (&screen{
	entity: "activity", prefix: "AC", dateCode: true, files: true, comments: true,
	remote: []pair{{"lead_id", "leads"}, {"contact_id", "contacts"}, {"opportunity_id", "opportunities"}},
	res: &app.Resource{Resource: "crm_activities", Table: "activities", Type: "activity", Singular: "activity", Plural: "activities", Base: "/crm/activities", Icon: "clock",
		OrderBy: "t.start_at DESC NULLS LAST, t.id DESC", Searchable: []string{"t.topic", "t.code", "l.name_en", "c.name_en", "o.name"},
		SelectSQL: func(c *app.Ctx) string {
			return `SELECT t.*, l.name_en AS lead_name, l.name_th AS lead_name_th, l.image AS lead_image, c.name_en AS contact_name, o.name AS opportunity_name FROM activities t
                LEFT JOIN leads l ON l.id = t.lead_id
                LEFT JOIN contacts c ON c.id = t.contact_id
                LEFT JOIN opportunities o ON o.id = t.opportunity_id`
		},
		Label:     func(c *app.Ctx, row db.Row) string { return row.Str("topic") },
		ExtraMeta: func(c *app.Ctx) app.M { return app.M{"wizard_style": "dots"} },
	},
}).setup()

var startQuery = regexp.MustCompile(`^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2})?$`)

var activityTones = map[string]string{"PLANNED": "info", "DONE": "success", "CANCELLED": "neutral"}

func gcalSync(c *app.Ctx, id int) {
	google.SyncActivity(c, id)
}

func init() {
	s := activities
	r := s.res
	// Every saved activity is also put on its owner's Google Calendar (when connected); the local calendar reads the table itself.
	s.savedAfter = gcalSync
	r.BeforeDelete = func(c *app.Ctx, row db.Row) {
		google.RemoveActivity(c, row)
	}
	r.Filters = func(c *app.Ctx) []app.Filter {
		return append([]app.Filter{
			{Name: "type", Label: c.T("All types"), Options: tr(c, activityTypes), Column: "t.activity_type"},
			{Name: "status", Label: c.T("All statuses"), Options: tr(c, activityStatuses), Column: "t.status"},
			{Name: "when", Label: c.T("Any time"), Options: []app.Opt{{Value: "overdue", Label: c.T("Overdue")}, {Value: "today", Label: c.T("Today")}, {Value: "upcoming", Label: c.T("Upcoming")}},
				SQL: "CASE ? WHEN 'overdue' THEN t.status = 'PLANNED' AND t.start_at < now() WHEN 'today' THEN t.start_at::date = CURRENT_DATE ELSE t.status = 'PLANNED' AND t.start_at >= now() END"},
		}, commonFilters(c)...)
	}
	r.Fields = func(c *app.Ctx, row db.Row) []app.Field {
		types := tr(c, activityTypes)
		want := c.Query("type")
		def := "MEETING"
		if optHas(types, want) {
			def = want
		}
		m := must(c)
		no := false
		var start any
		if q := c.Query("start"); startQuery.MatchString(q) {
			start = strings.ReplaceAll(q, " ", "T")
		}
		f := []app.Field{
			{Section: c.T("Basic info"), Icon: "pulse", Help: c.T("Type, topic, timing and references")},
			{Name: "activity_type", Label: c.T("Activity type"), Type: "select", Options: types, Rules: "required", Default: def, Search: &no},
			{Name: "start_at", Label: c.T("Start date"), Type: "datetime", Rules: m + "|date", Placeholder: c.T("Select start date/time"), Default: start},
			{Name: "topic", Label: c.T("Topic"), Rules: "required|max:200", Span: 2, Placeholder: c.T("e.g. Product demo call with Acme"), Example: "Follow-up call about the quotation"},
			// meeting: type | location, status | duration — call: direction (full width), status | duration
			{Name: "meeting_type", Label: c.T("Meeting type"), Type: "select", Options: tr(c, meetingTypes), Rules: "nullable", ShowWhen: "activity_type=MEETING", Placeholder: c.T("Select type"), Search: &no},
			{Name: "meeting_location", Label: c.T("Meeting location"), Rules: "nullable|max:255", ShowWhen: "activity_type=MEETING", Placeholder: c.T("e.g. Office / Zoom link")},
			{Name: "call_direction", Label: c.T("Call direction"), Type: "select", Options: tr(c, callDirections), Rules: "nullable", ShowWhen: "activity_type=CALL", Span: 2, Placeholder: c.T("Select direction"), Search: &no},
			{Name: "status", Label: c.T("Status"), Type: "select", Options: tr(c, activityStatuses), Rules: "required", Default: "PLANNED", Search: &no,
				WideWhen: "activity_type=EMAIL,TASK"}, // e-mail and task have no fields beside it
			{Name: "meeting_duration", Label: c.T("Meeting duration (min)"), Type: "number", Rules: "nullable|integer", ShowWhen: "activity_type=MEETING", Placeholder: "60"},
			{Name: "call_duration", Label: c.T("Call duration (min)"), Type: "number", Rules: "nullable|integer", ShowWhen: "activity_type=CALL"},
			{Name: "code", Label: c.T("Code"), Rules: "nullable|max:30", HideForm: true},

			s.remoteField(c, "lead_id", row, app.Field{Label: c.T("Lead"), Rules: "required", Span: 2, Placeholder: c.T("Reference lead"),
				Display: func(r db.Row) any { return r["lead_name"] }, Extra: app.M{"href": hrefIf("lead_id", "/crm/leads/")}}),
			s.remoteField(c, "contact_id", row, app.Field{Label: c.T("Contact"), Rules: "nullable", Depends: "lead_id", Span: 2, Placeholder: c.T("Select contact"),
				Display: func(r db.Row) any { return r["contact_name"] }, Extra: app.M{"href": hrefIf("contact_id", "/crm/contacts/")}}),
			s.remoteField(c, "opportunity_id", row, app.Field{Label: c.T("Opportunity"), Rules: "nullable", Depends: "lead_id", Span: 2, Placeholder: c.T("Select opportunity"),
				Display: func(r db.Row) any { return r["opportunity_name"] }, Extra: app.M{"href": hrefIf("opportunity_id", "/crm/opportunities/")}}),
			{Name: "notify_me", Label: c.T("Notify me"), Type: "checkbox", Help: c.T("The reminder shows on your CRM dashboard.")},
			{Name: "notify_before", Label: c.T("Notify before"), Type: "select", Rules: "nullable", Default: "15", Search: &no, Placeholder: c.T("Select time"), ShowWhen: "notify_me=1",
				Options: []app.Opt{{Value: "0", Label: c.T("At the start")}, {Value: "5", Label: c.T("5 minutes")}, {Value: "15", Label: c.T("15 minutes")}, {Value: "30", Label: c.T("30 minutes")}, {Value: "60", Label: c.T("1 hour")}, {Value: "1440", Label: c.T("1 day")}},
				Display: func(r db.Row) any {
					if !r.Bool("notify_me") {
						return nil
					}
					if r.Int("notify_before") != 0 {
						return r.Str("notify_before") + " min"
					}
					return c.T("At the start")
				}},

			{Section: c.T("Description"), Icon: "list", Help: c.T("Rich text notes about this activity")},
			{Name: "description", Label: c.T("Description"), Type: "richtext", Span: 2, Rules: "nullable", Counter: true},
		}
		f = append(f, s.filesFields(c, row)...)
		return append(f, s.ownershipFields(c, row)...)
	}
	r.Columns = func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "topic", Label: c.T("Activity"), Primary: true, Sort: "t.topic", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return h(uiTitle(c, r.Str("topic"), strings.Trim(trLabel(c, activityTypes, r.Str("activity_type"))+" · "+r.Str("code"), " ·"), "/crm/activities/"+r.Str("id")))
			}},
			{Key: "when", Label: c.T("When"), Sort: "t.start_at", Render: func(c *app.Ctx, r db.Row) template.HTML {
				if r["start_at"] == nil {
					return h(uiDash())
				}
				late := r.Str("status") == "PLANNED" && conv.StrToTime(r["start_at"]) < conv.Now().Unix()
				cl := "text-steel"
				if late {
					cl = "font-medium text-signal-700"
				}
				out := `<span class="tabular-nums ` + cl + `">` + app.E(app.FormatDate(c, r["start_at"], "d M Y H:i")) + `</span>`
				if late {
					out += ` <span class="badge bg-signal-50 text-signal-800">` + app.E(c.T("Overdue")) + `</span>`
				}
				return h(out)
			}},
			{Key: "lead", Label: c.T("Lead"), Sort: "l.name_en", Tone: "amber", Render: func(c *app.Ctx, r db.Row) template.HTML {
				if r.Int("lead_id") == 0 {
					return h(uiDash())
				}
				return h(uiPerson(c, r.Str("lead_name"), conv.OrStr(r.Str("opportunity_name"), r.Str("contact_name")), r.Str("lead_image"), "/crm/leads/"+r.Str("lead_id"), ""))
			}},
			{Key: "status", Label: c.T("Status"), Sort: "t.status", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return h(uiBadge(trLabel(c, activityStatuses, r.Str("status")), conv.OrStr(activityTones[r.Str("status")], "neutral")))
			}},
			ownerColumn(c),
			createdColumn(c),
		}
	}
	s.prepareMore = func(c *app.Ctx, data *app.Clean, existing db.Row) *app.Clean {
		typ := data.Str("activity_type")
		if data.Get("activity_type") == nil {
			typ = "CALL"
			if existing != nil {
				typ = existing.Str("activity_type")
			}
		}
		if typ != "CALL" {
			data.Set("call_direction", nil)
			data.Set("call_duration", nil)
		}
		if typ != "MEETING" {
			data.Set("meeting_type", nil)
			data.Set("meeting_duration", nil)
			data.Set("meeting_location", nil)
		}
		data.Set("notify_before", data.Int("notify_before"))
		// a changed time or reminder setting means the reminder has not been sent yet
		same := existing != nil
		if same {
			var a, b int64 = -1, -1
			if existing["start_at"] != nil {
				a = conv.StrToTime(existing["start_at"])
			}
			if !conv.Empty(data.Get("start_at")) {
				b = conv.StrToTime(data.Get("start_at"))
			}
			same = a == b && existing.Int("notify_before") == data.Int("notify_before") && existing.Bool("notify_me") == !conv.Empty(data.Get("notify_me"))
		}
		if !same {
			data.Set("reminder_sent_at", nil)
		}
		// Fill in the chain: opportunity → its company and contact; contact → its company
		if !conv.Empty(data.Get("opportunity_id")) {
			o := db.On("crm").First("SELECT lead_id, contact_id FROM opportunities WHERE id = ?", data.Int("opportunity_id"))
			if conv.Empty(data.Get("lead_id")) {
				data.Set("lead_id", o["lead_id"])
			}
			if conv.Empty(data.Get("contact_id")) {
				data.Set("contact_id", o["contact_id"])
			}
		}
		if !conv.Empty(data.Get("contact_id")) && conv.Empty(data.Get("lead_id")) {
			data.Set("lead_id", db.On("crm").Scalar("SELECT lead_id FROM contacts WHERE id = ?", data.Int("contact_id")))
		}
		return data
	}
	s.badges = func(c *app.Ctx, r db.Row) []string {
		b := []string{uiBadge(trLabel(c, activityTypes, r.Str("activity_type")), "violet"), uiBadge(trLabel(c, activityStatuses, r.Str("status")), conv.OrStr(activityTones[r.Str("status")], "neutral"))}
		if r.Str("status") == "PLANNED" && r["start_at"] != nil && conv.StrToTime(r["start_at"]) < conv.Now().Unix() {
			b = append(b, uiBadge(c.T("Overdue"), "danger"))
		}
		return b
	}
	s.subtitle = func(c *app.Ctx, r db.Row) string {
		when := ""
		if r["start_at"] != nil {
			when = app.FormatDate(c, r["start_at"], "d M Y H:i")
		}
		return joinNonEmpty(" · ", r.Str("code"), when, conv.OrStr(r.Str("opportunity_name"), r.Str("contact_name"), r.Str("lead_name")))
	}
	s.showTop = func(c *app.Ctx, row db.Row) string {
		edit := c.Can(s.res.Resource, "edit") && s.res.CanModify(c, row, "edit")
		if !edit || row.Str("status") != "PLANNED" {
			return ""
		}
		late := row["start_at"] != nil && conv.StrToTime(row["start_at"]) < conv.Now().Unix()
		text := c.T("This activity is planned.")
		if late {
			text = c.T("This activity was due :time.", "time", app.TimeAgo(c, row["start_at"]))
		}
		return string(c.Partial("crm/panels/activity-actions", app.M{"row": row, "late": late, "text": text}))
	}
	s.statCards = func(c *app.Ctx) []app.Stat {
		return []app.Stat{
			card(c.T("Planned"), s.figure(c, "count(*)", "t.status = 'PLANNED'", nil, ""), "", "info", "clock", ""),
			card(c.T("Today"), s.figure(c, "count(*)", "t.status = 'PLANNED' AND t.start_at::date = CURRENT_DATE", nil, ""), "", "neutral", "clock", "/crm/activities?when=today"),
			card(c.T("Overdue"), s.figure(c, "count(*)", "t.status = 'PLANNED' AND t.start_at < now()", nil, ""), "", "danger", "clock", "/crm/activities?when=overdue"),
			card(c.T("Done this month"), s.figure(c, "count(*)", "t.status = 'DONE' AND date_trunc('month', t.updated_at) = date_trunc('month', now())", nil, ""), "", "success", "check", ""),
		}
	}
}

// activitySetStatus: mark done / cancel / re-open with one click.
func activitySetStatus(c *app.Ctx) {
	id := c.ParamInt("id")
	row := activities.res.FindForChange(c, id, "edit")
	status := c.Input("status")
	if !hasPair(activityStatuses, status) {
		app.Invalid("status", c.T("Unknown status."))
	}
	db.On("crm").Exec("UPDATE activities SET status = ?, updated_by = ?, updated_at = now() WHERE id = ?", status, c.MustUser().ID, id)
	gcalSync(c, id)
	to, _ := pairLabel(activityStatuses, status)
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "activity", ID: id, Label: row.Str("topic"), Text: `Marked activity ":label" as :status`,
		Params: []any{"label", row.Str("topic"), "status", to},
		Props:  app.M{"changes": app.M{"status": app.M{"from": trLabel(c, activityStatuses, row.Str("status")), "to": c.T(to)}}}, Owner: row.Int("owner_id"), Module: "crm"})
	c.Flash("success", c.T("Marked as :status.", "status", strings.ToLower(c.T(to))))
	c.Redirect("/crm/activities/" + conv.Str(id))
}
