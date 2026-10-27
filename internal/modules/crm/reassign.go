package crm

import (
	"regexp"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

// Reassign: hand records to somebody else (somebody left, changed team, is overloaded) without losing who had them before:
// every change is written to ownership_history and the record keeps its comments, files, steps and logs.

type reassignType struct {
	Key      string
	Table    string
	Label    string
	Open     string
	Status   string // "" = no status column
	Statuses []app.Opt
	Name     string
	Resp     string
}

func reassignTypes(c *app.Ctx) []reassignType {
	return []reassignType{
		{"opportunity", "opportunities", "name", "t.opportunity_stage IN ('QUALIFICATION','SURVEY_PROPOSAL','EVALUATION_TESTING','NEGOTIATION','ON_HOLD')", "t.opportunity_stage", stageOptions(c), c.T("Opportunities"), ""},
		{"activity", "activities", "topic", "t.status = 'PLANNED'", "t.status", tr(c, activityStatuses), c.T("Activities"), ""},
		{"project", "projects", "name", "t.status IN ('PLANNING','ACTIVE','ON_HOLD')", "t.status", tr(c, projectStatuses), c.T("Projects"), "project"},
		{"task", "tasks", "name", "t.status NOT IN ('DONE','CANCELLED')", "t.status", tr(c, taskStatuses), c.T("Tasks"), "task"},
		{"campaign", "campaigns", "name", "t.status IN ('DRAFT','PLANNED','ACTIVE')", "t.status", tr(c, campaignStatuses), c.T("Campaigns"), ""},
		{"lead", "leads", "name_en", "TRUE", "", nil, c.T("Leads"), ""},
		{"contact", "contacts", "name_en", "TRUE", "", nil, c.T("Contacts"), ""},
	}
}

func reassignType1(c *app.Ctx, key string) (reassignType, bool) {
	for _, t := range reassignTypes(c) {
		if t.Key == key {
			return t, true
		}
	}
	return reassignType{}, false
}

// reassignInScope: may this person reassign / bulk-change this record? Whole-company roles: any; department heads: their own department.
func reassignInScope(c *app.Ctx, u *app.CurrentUser, row db.Row) bool {
	return c.Can("crm_reassign", "edit") && (seesAll(u) || (u.DepartmentID != 0 && row.Int("department_id") == u.DepartmentID))
}

func mineSQL(t reassignType) string {
	switch t.Resp {
	case "project":
		return "(t.owner_id = ? OR EXISTS (SELECT 1 FROM project_members x WHERE x.project_id = t.id AND x.user_id = ?))"
	case "task":
		return "(t.owner_id = ? OR EXISTS (SELECT 1 FROM task_assignees x WHERE x.task_id = t.id AND x.user_id = ?))"
	}
	return "t.owner_id = ?"
}

func mineParams(t reassignType, userID int) []any {
	if t.Resp != "" {
		return []any{userID, userID}
	}
	return []any{userID}
}

// recordsOf: records of one person that this viewer may change.
func recordsOf(viewer *app.CurrentUser, t reassignType, userID int, openOnly bool, limit int) []db.Row {
	where := []string{"t.deleted_at IS NULL", mineSQL(t)}
	par := mineParams(t, userID)
	if openOnly {
		where = append(where, t.Open)
	}
	if !seesAll(viewer) {
		where = append(where, "t.department_id = ?")
		par = append(par, viewer.DepartmentID)
	}
	status := ", NULL AS status"
	if t.Status != "" {
		status = ", " + t.Status + " AS status"
	}
	return db.On("crm").Select("SELECT t.id, t."+t.Label+" AS label, t.owner_id, t.department_id, t.updated_at"+status+" FROM "+t.Table+" t WHERE "+strings.Join(where, " AND ")+" ORDER BY t.updated_at DESC, t.id DESC LIMIT "+php.Str(limit), par...)
}

// reassignCounts: how many of each type a person has (open / all).
func reassignCounts(c *app.Ctx, viewer *app.CurrentUser, userID int) map[string][2]int {
	out := map[string][2]int{}
	for _, t := range reassignTypes(c) {
		par := mineParams(t, userID)
		scope := ""
		if !seesAll(viewer) {
			scope = " AND t.department_id = ?"
			par = append(par, viewer.DepartmentID)
		}
		row := db.On("crm").First("SELECT count(*) AS total, count(*) FILTER (WHERE "+t.Open+") AS open FROM "+t.Table+" t WHERE t.deleted_at IS NULL AND "+mineSQL(t)+scope, par...)
		out[t.Key] = [2]int{row.Int("open"), row.Int("total")}
	}
	return out
}

// reassignApply gives records to `to`. from (when known) is the person who is being replaced — for projects and tasks their place
// among the responsible people is taken over too. Returns moved, skipped.
func reassignApply(c *app.Ctx, by *app.CurrentUser, typ string, ids []int, to, from int, note string, moveDepartment bool) (int, int) {
	t, ok := reassignType1(c, typ)
	if !ok {
		panic("Unknown type")
	}
	all := everyone()
	pm := peopleMap(all)
	target, ok := pm[to]
	if !ok || !target.Active {
		app.Invalid("to", c.T("Pick somebody who is active."))
	}
	if !seesAll(by) && target.DepartmentID != by.DepartmentID {
		app.Invalid("to", c.T("You can only hand records to people in your own department."))
	}
	moved, skipped := 0, 0
	var first db.Row
	seen := map[int]bool{}
	for _, id := range ids {
		if seen[id] {
			continue
		}
		seen[id] = true
		row := db.On("crm").First("SELECT * FROM "+t.Table+" WHERE id = ? AND deleted_at IS NULL", id)
		if row == nil || !reassignInScope(c, by, row) {
			skipped++
			continue
		}
		label := row.Str(t.Label)
		oldOwner := row.Int("owner_id")
		changed := false
		if oldOwner != to {
			set := db.Cols{{Name: "owner_id", Value: to}, {Name: "updated_by", Value: by.ID}, {Name: "updated_at", Value: php.NowString()}}
			if moveDepartment && target.DepartmentID != 0 && target.DepartmentID != row.Int("department_id") {
				set = append(set, db.Col{Name: "department_id", Value: target.DepartmentID})
			}
			db.On("crm").Update(t.Table, set, db.Cols{{Name: "id", Value: id}})
			respRemember(typ, id, oldOwner, to, by.ID, note, "owner")
			changed = true
		}
		if t.Resp != "" {
			for _, replaced := range []int{from, oldOwner} {
				if replaced != 0 && replaced != to && respReplace(typ, id, replaced, to, by.ID, note) {
					changed = true
				}
			}
		}
		if !changed {
			skipped++
			continue
		}
		moved++
		if first == nil {
			first = db.Row{"id": id, "label": label}
		}
		fromName := "—"
		if p, ok := pm[oldOwner]; ok {
			fromName = p.Name
		}
		app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: typ, ID: id, Label: label, Text: `Reassigned :type ":label" from :from to :to`,
			Params: []any{"type", typ, "label", label, "from", fromName, "to", target.Name},
			Props:  app.M{"changes": app.M{"owner": app.M{"from": fromName, "to": target.Name}}, "_url": c.URL("/crm/" + entities[typ].Path + "/" + php.Str(id))},
			Owner:  to, NoNotify: true, Module: "crm"})
	}
	if moved > 0 && to != by.ID {
		url := c.URL("/crm/" + entities[typ].Path + "?scope=mine")
		if moved == 1 && first != nil {
			url = c.URL("/crm/" + entities[typ].Path + "/" + first.Str("id"))
		}
		tp := strings.ToLower(t.Name)
		lbl := note
		if lbl == "" {
			lbl = t.Name
		}
		if moved == 1 {
			tp = typ
			if first != nil {
				lbl = first.Str("label")
			}
		}
		app.Deliver(c, "crm", "assigned", to, app.Text(":name gave you :n :type", "name", by.Name, "n", moved, "type", tp),
			app.Text(":label", "label", lbl), url, by.ID)
	}
	return moved, skipped
}

// ---------------------------------------------------------------- StageReview

type stageFilter struct {
	Department, Owner int
	From, To, Date    string
}

var ymd = regexp.MustCompile(`^\d{4}-\d{2}-\d{2}$`)

func stageDates(c *app.Ctx) []pair {
	return []pair{{"since", c.T("In stage since")}, {"created", c.T("Created")}, {"updated", c.T("Last updated")}}
}

var stageDateSQL = map[string]string{
	"since":   "coalesce((SELECT max(s.done_at) FROM opportunity_steps s WHERE s.opportunity_id = t.id AND s.kind = 'stage'), t.created_at)",
	"created": "t.created_at",
	"updated": "t.updated_at",
}

func stageWhere(u *app.CurrentUser, f stageFilter) (string, []any) {
	w := []string{"t.deleted_at IS NULL"}
	var p []any
	if !seesAll(u) {
		w = append(w, "t.department_id = ?")
		p = append(p, u.DepartmentID)
	} else if f.Department != 0 {
		w = append(w, "t.department_id = ?")
		p = append(p, f.Department)
	}
	if f.Owner != 0 {
		w = append(w, "t.owner_id = ?")
		p = append(p, f.Owner)
	}
	date, ok := stageDateSQL[f.Date]
	if !ok {
		date = stageDateSQL["since"]
	}
	if ymd.MatchString(f.From) {
		w = append(w, "("+date+") >= ?::date")
		p = append(p, f.From)
	}
	if ymd.MatchString(f.To) {
		w = append(w, "("+date+") < ?::date + 1")
		p = append(p, f.To)
	}
	return strings.Join(w, " AND "), p
}

func stageCounts(u *app.CurrentUser, f stageFilter) map[string][2]float64 {
	w, p := stageWhere(u, f)
	out := map[string][2]float64{}
	for _, r := range db.On("crm").Select("SELECT t.opportunity_stage AS stage, count(*) AS n, coalesce(sum(t.amount), 0) AS amount FROM opportunities t WHERE "+w+" GROUP BY 1", p...) {
		if isStage(r.Str("stage")) {
			out[r.Str("stage")] = [2]float64{r.Float("n"), r.Float("amount")}
		}
	}
	return out
}

func stageRows(u *app.CurrentUser, stage string, f stageFilter, limit int) []db.Row {
	w, p := stageWhere(u, f)
	p = append(p, stage)
	return db.On("crm").Select(`SELECT t.id, t.code, t.name, t.amount, t.currency, t.owner_id, t.department_id, t.created_at, t.updated_at, t.follow_at,
                (`+stageDateSQL["since"]+`) AS since, l.name_en AS customer
            FROM opportunities t LEFT JOIN leads l ON l.id = t.lead_id
            WHERE `+w+` AND t.opportunity_stage = ? ORDER BY since ASC, t.id ASC LIMIT `+php.Str(limit), p...)
}
