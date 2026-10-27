package crm

import (
	"math"
	"net/http"
	"strings"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/php"
)

// CRM for the mobile app (Api/CrmApi.php; paths relative to /api/v1). Every list and record goes through the same visibility
// as the website (visible: own, department, shared, project member …) and the same permissions.
// Writes reuse the website's own save actions (validation, owner, linked-record checks, Google
// Calendar sync, activity log) so mobile can never do more than the website.

func api(r app.Router) {
	r.Get("/crm/stats", apiStats)
	r.Get("/crm/search", apiSearch)
	r.Get("/crm/options", apiOptions)
	r.Get("/crm/leads", apiLeads)
	r.Post("/crm/leads", apiCreateLead)
	r.Get("/crm/leads/{id}", apiLead)
	r.Get("/crm/contacts", apiContacts)
	r.Post("/crm/contacts", apiCreateContact)
	r.Get("/crm/contacts/{id}", apiContact)
	r.Get("/crm/opportunities", apiOpportunities)
	r.Post("/crm/opportunities", apiCreateOpportunity)
	r.Get("/crm/opportunities/{id}", apiOpportunity)
	r.Get("/crm/activities", apiActivities)
	r.Post("/crm/activities", apiCreateActivity)
	r.Get("/crm/activities/{id}", apiActivity)
	r.Post("/crm/activities/{id}", apiUpdateActivity)
	r.Post("/crm/activities/{id}/status", apiActivityStatus)
	r.Get("/crm/tasks", apiTasks)
	r.Get("/crm/tasks/{id}", apiTask)
	r.Post("/crm/tasks/{id}/status", apiTaskStatus)
}

var (
	apiLeadKeys = []string{"id", "code", "name_en", "name_th", "tax_id", "phone", "mobile", "fax", "address", "city", "province", "country", "zipcode",
		"is_register", "is_government", "owner_id", "owner", "department_id", "department", "created_at", "updated_at"}
	apiContactKeys = []string{"id", "code", "salutation", "name_en", "name_th", "job_title", "department", "phone", "phone_ext", "email", "lead_id", "lead_name",
		"owner_id", "owner", "updated_at"}
	apiOppKeys = []string{"id", "code", "name", "opportunity_stage", "stage_label", "probability", "amount", "currency", "priority", "follow_at", "close_at",
		"cancel_reason", "lead_id", "lead_name", "contact_id", "contact_name", "owner_id", "owner", "updated_at"}
	apiActKeys = []string{"id", "code", "activity_type", "topic", "status", "start_at", "call_direction", "call_duration", "meeting_type", "meeting_duration",
		"meeting_location", "notify_me", "notify_before", "description", "lead_id", "lead_name", "contact_id", "contact_name", "opportunity_id", "opportunity_name",
		"owner_id", "owner", "updated_at"}
	apiTaskKeys = []string{"id", "code", "name", "description", "status", "start_date", "end_date", "project_id", "project_name", "assignees", "owner_id", "owner", "updated_at"}
)

func apiNeed(c *app.Ctx, resource string) {
	if !c.MustUser().Can(resource, "view") {
		apiError(c, 403, "forbidden", c.T("You don't have permission to :a :r.", "a", "view", "r", strings.ReplaceAll(resource, "_", " ")))
	}
}

func apiError(c *app.Ctx, status int, code, msg string) {
	c.JSON(app.M{"error": app.M{"code": code, "message": msg}}, status)
}

func apiNotFound(c *app.Ctx) { apiError(c, 404, "not_found", c.T("Not found.")) }

// apiScope: visibility + not deleted, for table alias t.
func apiScope(c *app.Ctx, typ, resource string) (string, []any) {
	apiNeed(c, resource)
	s, p := visible(c.MustUser(), "t", typ)
	return "t.deleted_at IS NULL AND (" + s + ")", p
}

// apiNames adds owner / department names (core database) to rows.
func apiNames(rows []db.Row) []db.Row {
	users := names("users", col(rows, "owner_id"))
	deps := names("departments", col(rows, "department_id"))
	for _, r := range rows {
		if n, ok := users[r.Int("owner_id")]; ok {
			r["owner"] = n
		} else {
			r["owner"] = nil
		}
		if _, has := r["department_id"]; has {
			if r["department"] == nil {
				if n, ok := deps[r.Int("department_id")]; ok {
					r["department"] = n
				} else {
					r["department"] = nil
				}
			}
		}
	}
	return rows
}

func apiPick(r db.Row, keys []string) app.M {
	out := app.M{}
	for _, k := range keys {
		out[k] = r[k]
	}
	return out
}

func apiQ(c *app.Ctx) string { return php.Substr(strings.TrimSpace(c.Query("q")), 0, 100) }

// apiPaged: one page of sql (no ORDER BY / LIMIT): ?page= (from 1) and ?per_page= (1–100, default 25).
func apiPaged(c *app.Ctx, sql string, params []any, order string) ([]db.Row, app.M) {
	per := max(1, min(100, php.Int(c.Query("per_page", "25"))))
	page := max(1, min(10000, php.Int(c.Query("page", "1"))))
	total := php.Int(db.On("crm").Scalar("SELECT count(*) FROM ("+sql+") x", params...))
	rows := db.On("crm").Select(sql+" ORDER BY "+order+" LIMIT "+php.Str(per)+" OFFSET "+php.Str((page-1)*per), params...)
	return rows, app.M{"page": page, "per_page": per, "total": total, "pages": int(math.Ceil(float64(total) / float64(per)))}
}

func apiList(c *app.Ctx, sql string, params []any, order string, keys []string, post func([]db.Row) []db.Row) {
	rows, meta := apiPaged(c, sql, params, order)
	rows = apiNames(rows)
	if post != nil {
		rows = post(rows)
	}
	data := []app.M{}
	for _, r := range rows {
		data = append(data, apiPick(r, keys))
	}
	c.JSON(app.M{"data": data, "meta": meta})
}

func apiOne(c *app.Ctx, sql string, params []any, keys []string) app.M {
	row := db.On("crm").First(sql, params...)
	if row == nil {
		apiNotFound(c)
	}
	return apiPick(apiNames([]db.Row{row})[0], keys)
}

func apiLike(q string) string { return likePattern(q) }

func repeatArgs(v any, n int) []any {
	out := make([]any, n)
	for i := range out {
		out[i] = v
	}
	return out
}

// ---------------------------------------------------------------- leads

func apiLeads(c *app.Ctx) {
	w, p := apiScope(c, "lead", "crm_leads")
	if q := apiQ(c); q != "" {
		w += " AND (t.name_en ILIKE ? OR t.name_th ILIKE ? OR t.code ILIKE ? OR t.tax_id ILIKE ? OR t.phone ILIKE ? OR t.mobile ILIKE ?)"
		p = append(p, repeatArgs(apiLike(q), 6)...)
	}
	if c.Query("mine") == "1" {
		w += " AND t.owner_id = ?"
		p = append(p, c.MustUser().ID)
	}
	apiList(c, "SELECT t.* FROM leads t WHERE "+w, p, "t.updated_at DESC, t.id DESC", apiLeadKeys, nil)
}

// apiLead: a lead with its visible contacts, open opportunities and latest activities.
func apiLead(c *app.Ctx) { apiLeadBody(c, c.ParamInt("id")) }

func apiLeadBody(c *app.Ctx, id int) {
	w, p := apiScope(c, "lead", "crm_leads")
	out := app.M{"data": apiOne(c, "SELECT t.* FROM leads t WHERE t.id = ? AND "+w, append([]any{id}, p...), apiLeadKeys)}
	u := c.MustUser()
	if u.Can("crm_contacts", "view") {
		cw, cp := apiScope(c, "contact", "crm_contacts")
		list := []app.M{}
		for _, r := range apiNames(db.On("crm").Select("SELECT t.* FROM contacts t WHERE t.lead_id = ? AND "+cw+" ORDER BY t.name_en LIMIT 50", append([]any{id}, cp...)...)) {
			list = append(list, apiPick(r, apiContactKeys))
		}
		out["contacts"] = list
	}
	if u.Can("crm_opportunities", "view") {
		ow, op := apiScope(c, "opportunity", "crm_opportunities")
		list := []app.M{}
		for _, r := range apiNames(db.On("crm").Select("SELECT t.* FROM opportunities t WHERE t.lead_id = ? AND "+ow+" ORDER BY t.updated_at DESC LIMIT 50", append([]any{id}, op...)...)) {
			list = append(list, apiOppOut(c, r))
		}
		out["opportunities"] = list
	}
	if u.Can("crm_activities", "view") {
		aw, ap := apiScope(c, "activity", "crm_activities")
		list := []app.M{}
		for _, r := range apiNames(db.On("crm").Select("SELECT t.* FROM activities t WHERE t.lead_id = ? AND "+aw+" ORDER BY t.start_at DESC NULLS LAST LIMIT 20", append([]any{id}, ap...)...)) {
			list = append(list, apiPick(r, apiActKeys))
		}
		out["activities"] = list
	}
	c.JSON(out)
}

// ---------------------------------------------------------------- contacts

// apiContacts: ?q= ?lead_id= ?mine=1
func apiContacts(c *app.Ctx) {
	w, p := apiScope(c, "contact", "crm_contacts")
	if q := apiQ(c); q != "" {
		w += " AND (t.name_en ILIKE ? OR t.name_th ILIKE ? OR t.email ILIKE ? OR t.phone ILIKE ? OR t.code ILIKE ?)"
		p = append(p, repeatArgs(apiLike(q), 5)...)
	}
	if lead := php.Int(c.Query("lead_id")); lead != 0 {
		w += " AND t.lead_id = ?"
		p = append(p, lead)
	}
	if c.Query("mine") == "1" {
		w += " AND t.owner_id = ?"
		p = append(p, c.MustUser().ID)
	}
	apiList(c, "SELECT t.*, l.name_en AS lead_name FROM contacts t LEFT JOIN leads l ON l.id = t.lead_id WHERE "+w, p, "t.name_en, t.id", apiContactKeys, nil)
}

func apiContact(c *app.Ctx) { apiContactBody(c, c.ParamInt("id")) }

func apiContactBody(c *app.Ctx, id int) {
	w, p := apiScope(c, "contact", "crm_contacts")
	ct := apiOne(c, "SELECT t.*, l.name_en AS lead_name FROM contacts t LEFT JOIN leads l ON l.id = t.lead_id WHERE t.id = ? AND "+w, append([]any{id}, p...), apiContactKeys)
	mobiles := []app.M{}
	for _, m := range db.On("crm").Select("SELECT number, ext, label, is_primary FROM contact_mobiles WHERE contact_id = ? ORDER BY is_primary DESC, id", id) {
		mobiles = append(mobiles, app.M(m))
	}
	ct["mobiles"] = mobiles
	c.JSON(app.M{"data": ct})
}

// ---------------------------------------------------------------- opportunities

func apiOppOut(c *app.Ctx, r db.Row) app.M {
	if d, ok := stageDefOf(r.Str("opportunity_stage")); ok {
		r["stage_label"] = c.T(d.Label)
	} else {
		r["stage_label"] = c.T(r.Str("opportunity_stage"))
	}
	return apiPick(r, apiOppKeys)
}

func apiOpportunities(c *app.Ctx) {
	w, p := apiScope(c, "opportunity", "crm_opportunities")
	if q := apiQ(c); q != "" {
		w += " AND (t.name ILIKE ? OR t.code ILIKE ? OR l.name_en ILIKE ?)"
		p = append(p, repeatArgs(apiLike(q), 3)...)
	}
	if stage := c.Query("stage"); stage != "" && isStage(stage) {
		w += " AND t.opportunity_stage = ?"
		p = append(p, stage)
	}
	if c.Query("open") == "1" {
		w += " AND t.opportunity_stage IN ('" + strings.Join(stageOpen, "', '") + "')"
	}
	if lead := php.Int(c.Query("lead_id")); lead != 0 {
		w += " AND t.lead_id = ?"
		p = append(p, lead)
	}
	if c.Query("mine") == "1" {
		w += " AND t.owner_id = ?"
		p = append(p, c.MustUser().ID)
	}
	rows, meta := apiPaged(c, "SELECT t.*, l.name_en AS lead_name, c.name_en AS contact_name FROM opportunities t LEFT JOIN leads l ON l.id = t.lead_id LEFT JOIN contacts c ON c.id = t.contact_id WHERE "+w,
		p, "t.updated_at DESC, t.id DESC")
	data := []app.M{}
	for _, r := range apiNames(rows) {
		data = append(data, apiOppOut(c, r))
	}
	c.JSON(app.M{"data": data, "meta": meta})
}

func apiOpportunity(c *app.Ctx) { apiOpportunityBody(c, c.ParamInt("id")) }

func apiOpportunityBody(c *app.Ctx, id int) {
	w, p := apiScope(c, "opportunity", "crm_opportunities")
	row := db.On("crm").First("SELECT t.*, l.name_en AS lead_name, c.name_en AS contact_name FROM opportunities t LEFT JOIN leads l ON l.id = t.lead_id LEFT JOIN contacts c ON c.id = t.contact_id WHERE t.id = ? AND "+w,
		append([]any{id}, p...)...)
	if row == nil {
		apiNotFound(c)
	}
	c.JSON(app.M{"data": apiOppOut(c, apiNames([]db.Row{row})[0])})
}

// ---------------------------------------------------------------- activities

const apiActivitySQL = `SELECT t.*, l.name_en AS lead_name, c.name_en AS contact_name, o.name AS opportunity_name FROM activities t
        LEFT JOIN leads l ON l.id = t.lead_id LEFT JOIN contacts c ON c.id = t.contact_id LEFT JOIN opportunities o ON o.id = t.opportunity_id`

// apiActivities: ?status= ?type= ?from=YYYY-MM-DD ?to=YYYY-MM-DD ?mine=1 ?lead_id=
func apiActivities(c *app.Ctx) {
	w, p := apiScope(c, "activity", "crm_activities")
	if q := apiQ(c); q != "" {
		w += " AND (t.topic ILIKE ? OR l.name_en ILIKE ?)"
		p = append(p, apiLike(q), apiLike(q))
	}
	if hasPair(activityStatuses, c.Query("status")) {
		w += " AND t.status = ?"
		p = append(p, c.Query("status"))
	}
	if hasPair(activityTypes, c.Query("type")) {
		w += " AND t.activity_type = ?"
		p = append(p, c.Query("type"))
	}
	for _, k := range []struct{ k, op string }{{"from", ">="}, {"to", "<"}} {
		if ymd.MatchString(c.Query(k.k)) {
			add := ""
			if k.k == "to" {
				add = " + 1"
			}
			w += " AND t.start_at " + k.op + " ?::date" + add
			p = append(p, c.Query(k.k))
		}
	}
	if c.Query("mine") == "1" {
		w += " AND t.owner_id = ?"
		p = append(p, c.MustUser().ID)
	}
	if lead := php.Int(c.Query("lead_id")); lead != 0 {
		w += " AND t.lead_id = ?"
		p = append(p, lead)
	}
	apiList(c, apiActivitySQL+" WHERE "+w, p, "t.start_at DESC NULLS LAST, t.id DESC", apiActKeys, nil)
}

func apiActivityData(c *app.Ctx, id int) app.M {
	w, p := apiScope(c, "activity", "crm_activities")
	return apiOne(c, apiActivitySQL+" WHERE t.id = ? AND "+w, append([]any{id}, p...), apiActKeys)
}

func apiActivity(c *app.Ctx) { c.JSON(app.M{"data": apiActivityData(c, c.ParamInt("id"))}) }

var (
	apiActivityInput    = []string{"activity_type", "status", "topic", "start_at", "call_direction", "call_duration", "meeting_type", "meeting_duration", "meeting_location", "lead_id", "contact_id", "opportunity_id", "notify_me", "notify_before", "description"}
	apiLeadInput        = []string{"name_en", "name_th", "code", "tax_id", "phone", "mobile", "fax", "address", "city", "province", "country", "zipcode", "is_register", "is_government", "description"}
	apiContactInput     = []string{"lead_id", "salutation", "name_en", "name_th", "job_title", "department", "code", "email", "phone", "phone_ext", "additional_contact", "description"}
	apiOpportunityInput = []string{"name", "lead_id", "contact_id", "opportunity_stage", "priority", "code", "amount", "currency", "probability", "follow_at", "close_at", "description"}
)

// apiInput: only these keys of the request body (JSON or form), as the delegated web action's input.
func apiInput(c *app.Ctx, allowed []string) *config.Map {
	in := c.All()
	out := config.NewMap()
	for _, k := range allowed {
		if v, ok := in.Get(k); ok {
			out.Set(k, v)
		}
	}
	return out
}

// delegate runs a web action with the request limited to input; it returns the id the action saved.
func delegate(c *app.Ctx, input *config.Map, action func()) int {
	c.Replace(input)
	delete(c.Shared, "_crm_saved_id")
	action()
	id, _ := c.Shared["_crm_saved_id"].(int)
	return id
}

// Log a call / meeting. Same rules as the website form (lead required, linked records must be visible to you …).
func apiCreateActivity(c *app.Ctx) {
	apiNeed(c, "crm_activities")
	id := delegate(c, apiInput(c, apiActivityInput), func() { activities.res.Store(c) })
	c.JSON(app.M{"data": apiActivityData(c, id)}, http.StatusCreated)
}

// apiUpdateActivity: change an activity; fields left out keep their value.
func apiUpdateActivity(c *app.Ctx) {
	id := c.ParamInt("id")
	old := apiActivityData(c, id)
	in := apiInput(c, apiActivityInput)
	for _, k := range apiActivityInput {
		if _, ok := in.Get(k); !ok {
			if v, has := old[k]; has {
				in.Set(k, v)
			}
		}
	}
	delegate(c, in, func() { activities.res.Update(c) })
	c.JSON(app.M{"data": apiActivityData(c, id)})
}

func apiCreateLead(c *app.Ctx) {
	id := delegate(c, apiInput(c, apiLeadInput), func() { leads.res.Store(c) })
	c.Replace(nil)
	apiLeadBody(c, id)
}

func apiCreateContact(c *app.Ctx) {
	id := delegate(c, apiInput(c, apiContactInput), func() { contacts.res.Store(c) })
	c.Replace(nil)
	apiContactBody(c, id)
}

func apiCreateOpportunity(c *app.Ctx) {
	id := delegate(c, apiInput(c, apiOpportunityInput), func() { opportunities.res.Store(c) })
	c.Replace(nil)
	apiOpportunityBody(c, id)
}

// apiActivityStatus: {status: PLANNED|DONE|CANCELLED}
func apiActivityStatus(c *app.Ctx) {
	id := c.ParamInt("id")
	apiActivityData(c, id) // 404 when not visible
	delegate(c, apiInput(c, []string{"status"}), func() { activitySetStatus(c) })
	c.JSON(app.M{"data": apiActivityData(c, id)})
}

// ---------------------------------------------------------------- tasks

// apiTasks: ?mine=1 (assigned to me) ?status= ?project_id=
func apiTasks(c *app.Ctx) {
	w, p := apiScope(c, "task", "crm_tasks")
	if q := apiQ(c); q != "" {
		w += " AND (t.name ILIKE ? OR pr.name ILIKE ?)"
		p = append(p, apiLike(q), apiLike(q))
	}
	if hasPair(taskStatuses, c.Query("status")) {
		w += " AND t.status = ?"
		p = append(p, c.Query("status"))
	}
	if c.Query("open") == "1" {
		w += " AND t.status NOT IN ('DONE', 'CANCELLED')"
	}
	if c.Query("mine") == "1" {
		w += " AND EXISTS (SELECT 1 FROM task_assignees a WHERE a.task_id = t.id AND a.user_id = ?)"
		p = append(p, c.MustUser().ID)
	}
	if pr := php.Int(c.Query("project_id")); pr != 0 {
		w += " AND t.project_id = ?"
		p = append(p, pr)
	}
	apiList(c, "SELECT t.*, pr.name AS project_name FROM tasks t LEFT JOIN projects pr ON pr.id = t.project_id WHERE "+w, p, "t.end_date NULLS LAST, t.id DESC", apiTaskKeys, apiAssignees)
}

func apiAssignees(rows []db.Row) []db.Row {
	if len(rows) == 0 {
		return rows
	}
	ids := col(rows, "id")
	links := db.On("crm").Select("SELECT task_id, user_id FROM task_assignees WHERE task_id IN ("+inPlaceholders(len(ids))+")", ids...)
	ns := names("users", col(links, "user_id"))
	for _, r := range rows {
		list := []app.M{}
		for _, l := range links {
			if l.Int("task_id") == r.Int("id") {
				var n any
				if v, ok := ns[l.Int("user_id")]; ok {
					n = v
				}
				list = append(list, app.M{"id": l.Int("user_id"), "name": n})
			}
		}
		r["assignees"] = list
	}
	return rows
}

func apiTaskData(c *app.Ctx, id int) app.M {
	w, p := apiScope(c, "task", "crm_tasks")
	row := db.On("crm").First("SELECT t.*, pr.name AS project_name FROM tasks t LEFT JOIN projects pr ON pr.id = t.project_id WHERE t.id = ? AND "+w, append([]any{id}, p...)...)
	if row == nil {
		apiNotFound(c)
	}
	return apiPick(apiAssignees(apiNames([]db.Row{row}))[0], apiTaskKeys)
}

func apiTask(c *app.Ctx) { c.JSON(app.M{"data": apiTaskData(c, c.ParamInt("id"))}) }

// apiTaskStatus: {status: TODO|IN_PROGRESS|BLOCKED|DONE|CANCELLED}
func apiTaskStatus(c *app.Ctx) {
	id := c.ParamInt("id")
	apiTaskData(c, id)
	delegate(c, apiInput(c, []string{"status"}), func() { taskSetStatus(c) })
	c.JSON(app.M{"data": apiTaskData(c, id)})
}

// ---------------------------------------------------------------- options, search, stats

// apiOptions: choices for the forms: the stages this person may use, priorities, currencies, salutations.
func apiOptions(c *app.Ctx) {
	apiNeed(c, "crm_leads")
	pairs := func(l []app.Opt) []app.M {
		out := []app.M{}
		for _, o := range l {
			out = append(out, app.M{"value": o.Value, "label": o.Label})
		}
		return out
	}
	c.JSON(app.M{"data": app.M{
		"stages": pairs(stageOptionsFor(c, c.MustUser(), "")), "priorities": pairs(tr(c, priorities)), "currencies": pairs(currencyOptions()),
		"salutations": salutations, "stage_names": stagesForMobile(c),
	}})
}

// apiSearch: one box for the app: visible leads, contacts and opportunities matching ?q= (5 each).
func apiSearch(c *app.Ctx) {
	q := apiQ(c)
	if php.Len(q) < 2 {
		c.JSON(app.M{"data": []any{}})
	}
	like := apiLike(q)
	out := app.M{}
	u := c.MustUser()
	sets := []struct {
		key, typ, res, sql string
		n                  int
	}{
		{"leads", "lead", "crm_leads", "SELECT t.id, t.name_en AS label, t.code AS sub FROM leads t WHERE %s AND (t.name_en ILIKE ? OR t.name_th ILIKE ? OR t.code ILIKE ? OR t.tax_id ILIKE ?) ORDER BY t.name_en LIMIT 5", 4},
		{"contacts", "contact", "crm_contacts", "SELECT t.id, t.name_en AS label, coalesce(t.email, t.phone) AS sub FROM contacts t WHERE %s AND (t.name_en ILIKE ? OR t.name_th ILIKE ? OR t.email ILIKE ? OR t.phone ILIKE ?) ORDER BY t.name_en LIMIT 5", 4},
		{"opportunities", "opportunity", "crm_opportunities", "SELECT t.id, t.name AS label, t.opportunity_stage AS sub FROM opportunities t WHERE %s AND (t.name ILIKE ? OR t.code ILIKE ?) ORDER BY t.updated_at DESC LIMIT 5", 2},
	}
	for _, s := range sets {
		if !u.Can(s.res, "view") {
			continue
		}
		w, p := apiScope(c, s.typ, s.res)
		list := []app.M{}
		for _, r := range db.On("crm").Select(strings.Replace(s.sql, "%s", w, 1), append(append([]any{}, p...), repeatArgs(like, s.n)...)...) {
			list = append(list, app.M(r))
		}
		out[s.key] = list
	}
	c.JSON(app.M{"data": out})
}

// apiStats: my CRM at a glance — counted over what I may see.
func apiStats(c *app.Ctx) {
	u := c.MustUser()
	out := app.M{}
	q := db.On("crm")
	rowsOf := func(rs []db.Row) []app.M {
		l := []app.M{}
		for _, r := range rs {
			l = append(l, app.M(r))
		}
		return l
	}
	if u.Can("crm_opportunities", "view") {
		w, p := apiScope(c, "opportunity", "crm_opportunities")
		base := "coalesce(c.rate_to_base, 1)"
		out["base_currency"] = baseCurrency()
		out["pipeline"] = rowsOf(q.Select(`SELECT t.opportunity_stage AS stage, t.currency, count(*) AS count, coalesce(sum(t.amount), 0) AS amount,
                coalesce(sum(t.amount * coalesce(t.probability, 0) / 100), 0) AS weighted,
                round(coalesce(sum(t.amount * `+base+`), 0), 2) AS base_amount, round(coalesce(sum(t.amount * coalesce(t.probability, 0) / 100 * `+base+`), 0), 2) AS base_weighted
                FROM opportunities t LEFT JOIN currencies c ON c.code = t.currency
                WHERE `+w+` AND t.opportunity_stage IN ('`+strings.Join(stageOpen, "', '")+`') GROUP BY 1, 2 ORDER BY 1, 2`, p...))
		out["won_this_year"] = rowsOf(q.Select(`SELECT t.currency, count(*) AS count, coalesce(sum(t.amount), 0) AS amount, round(coalesce(sum(t.amount * `+base+`), 0), 2) AS base_amount
                FROM opportunities t LEFT JOIN currencies c ON c.code = t.currency
                WHERE `+w+` AND t.opportunity_stage = 'CLOSED_WON' AND t.close_at >= date_trunc('year', now()) GROUP BY 1`, p...))
	}
	if u.Can("crm_activities", "view") {
		w, p := apiScope(c, "activity", "crm_activities")
		out["activities"] = q.First(`SELECT
                count(*) FILTER (WHERE t.status = 'PLANNED' AND t.start_at < now()) AS overdue,
                count(*) FILTER (WHERE t.status = 'PLANNED' AND t.start_at::date = CURRENT_DATE) AS today,
                count(*) FILTER (WHERE t.status = 'PLANNED' AND t.start_at >= now() AND t.start_at < now() + interval '7 days') AS next_7_days,
                count(*) FILTER (WHERE t.status = 'DONE' AND t.start_at >= date_trunc('month', now())) AS done_this_month
                FROM activities t WHERE `+w+` AND t.owner_id = ?`, append(append([]any{}, p...), u.ID)...)
	}
	if u.Can("crm_tasks", "view") {
		w, p := apiScope(c, "task", "crm_tasks")
		out["my_tasks"] = q.First(`SELECT count(*) FILTER (WHERE t.status NOT IN ('DONE', 'CANCELLED')) AS open,
                count(*) FILTER (WHERE t.status NOT IN ('DONE', 'CANCELLED') AND t.end_date < CURRENT_DATE) AS late
                FROM tasks t WHERE `+w+` AND EXISTS (SELECT 1 FROM task_assignees a WHERE a.task_id = t.id AND a.user_id = ?)`, append(append([]any{}, p...), u.ID)...)
	}
	// this quarter for the Home "performance" card: new leads, deals won / lost, and activities per day (last 8 days)
	qt := app.M{}
	if u.Can("crm_leads", "view") {
		w, p := apiScope(c, "lead", "crm_leads")
		qt["leads"] = php.Int(q.Scalar("SELECT count(*) FROM leads t WHERE "+w+" AND t.created_at >= date_trunc('quarter', now())", p...))
	}
	if u.Can("crm_opportunities", "view") {
		w, p := apiScope(c, "opportunity", "crm_opportunities")
		r := q.First(`SELECT count(*) FILTER (WHERE t.opportunity_stage = 'CLOSED_WON') AS won, count(*) FILTER (WHERE t.opportunity_stage = 'CLOSED_LOST') AS lost
                FROM opportunities t WHERE `+w+` AND COALESCE(t.close_at, t.updated_at::date) >= date_trunc('quarter', now())`, p...)
		qt["won"], qt["lost"] = r.Int("won"), r.Int("lost")
	}
	if u.Can("crm_activities", "view") {
		w, p := apiScope(c, "activity", "crm_activities")
		byDay := map[string]int{}
		for _, r := range q.Select(`SELECT t.start_at::date AS day, count(*) AS n FROM activities t WHERE `+w+` AND t.owner_id = ?
                AND t.start_at >= CURRENT_DATE - 7 AND t.start_at < CURRENT_DATE + 1 GROUP BY 1`, append(append([]any{}, p...), u.ID)...) {
			byDay[r.Str("day")[:10]] = r.Int("n")
		}
		var days []app.M
		for k := 7; k >= 0; k-- {
			d := php.Date("Y-m-d", php.Now().AddDate(0, 0, -k))
			days = append(days, app.M{"date": d, "count": byDay[d]})
		}
		qt["activities"] = days
	}
	if len(qt) > 0 {
		out["quarter"] = qt
	}
	// this year's sales target, the same figure as the website's CRM dashboard: own department, or every department
	// added up for whole-company roles; nothing for someone outside any department
	if u.Can("crm_dashboard", "view") {
		dep := u.DepartmentID
		if seesAll(u) {
			dep = 0
		}
		if seesAll(u) || dep != 0 {
			year := php.Now().Year()
			t := targetSummaryOf(dep, year)
			var qs []app.M
			for i := 1; i <= 4; i++ {
				qs = append(qs, app.M{"q": i, "target": phpRound(t.Target[i], 2), "actual": phpRound(t.Actual[i], 2)})
			}
			out["target"] = app.M{"year": year, "currency": baseCurrency(), "source": t.Source, "total": phpRound(t.Total, 2), "actual": phpRound(t.ActualTotal, 2), "quarters": qs}
		}
	}
	out["stage_names"] = stagesForMobile(c)
	c.JSON(app.M{"data": out})
}
