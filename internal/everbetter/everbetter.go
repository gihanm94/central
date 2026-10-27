// Package everbetter is App\Core\Support\Everbetter: improvement projects.
//   - People (User Management → Everbetter) run projects: title, dates, members. Owner and members see them.
//   - To present, they pick the quarter's presentation day and say what it is: Project Initiation, Closure or Extend time.
//     Management is told at once.
//   - Management (Management → Everbetter) sets each quarter's day, acknowledges requests (the person sees "Accepted")
//     and records the result after the presentation: an approved initiation approves the project, an approved closure
//     closes it, an approved extension moves its end date.
package everbetter

import (
	"encoding/json"
	"regexp"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

// KindKeys / Kinds: what a presentation is (KINDS).
var KindKeys = []string{"initiation", "closure", "extension"}
var Kinds = map[string]string{"initiation": "Project Initiation", "closure": "Project Closure", "extension": "Extend time"}

// ProjectKeys / Project: the project statuses (PROJECT).
var ProjectKeys = []string{"draft", "approved", "rejected", "closed"}
var Project = map[string]string{"draft": "Not approved yet", "approved": "Approved", "rejected": "Not approved", "closed": "Closed"}

// KindsT is Everbetter::kinds(): the kinds in the current language.
func KindsT(c *app.Ctx) map[string]string {
	out := map[string]string{}
	for k, l := range Kinds {
		out[k] = c.T(l)
	}
	return out
}

// Manages: Management and administrators run the presentations.
func Manages(u *app.CurrentUser) bool { return u.IsAdmin() || u.Can("management", "view") }

/* -------------------------------------------------------------- projects */

// VisibleSQL is Everbetter::visibleSql().
func VisibleSQL(u *app.CurrentUser, alias string) (string, []any) {
	if Manages(u) {
		return alias + ".deleted_at IS NULL", nil
	}
	return alias + ".deleted_at IS NULL AND (" + alias + ".owner_id = ? OR EXISTS (SELECT 1 FROM everbetter_members m WHERE m.project_id = " + alias + ".id AND m.user_id = ?))", []any{u.ID, u.ID}
}

func likeOf(q string) string {
	return "%" + strings.NewReplacer(`\`, `\\`, "%", `\%`, "_", `\_`).Replace(php.Substr(q, 0, 100)) + "%"
}

// Projects with their members and latest presentation. f: q, status.
func Projects(u *app.CurrentUser, q, status string, mineOnly bool) []db.Row {
	var sql string
	var p []any
	if mineOnly {
		sql, p = "p.deleted_at IS NULL AND (p.owner_id = ? OR EXISTS (SELECT 1 FROM everbetter_members m WHERE m.project_id = p.id AND m.user_id = ?))", []any{u.ID, u.ID}
	} else {
		sql, p = VisibleSQL(u, "p")
	}
	w := []string{sql}
	if q = strings.TrimSpace(q); q != "" {
		w = append(w, "(p.title ILIKE ? OR p.owner_name ILIKE ?)")
		l := likeOf(q)
		p = append(p, l, l)
	}
	if _, ok := Project[status]; status != "" && ok {
		w = append(w, "p.status = ?")
		p = append(p, status)
	}
	rows := db.Select(`SELECT p.*, d.name AS department_name,
                (SELECT count(*) FROM everbetter_presentations x WHERE x.project_id = p.id AND x.result IS NOT NULL) AS presented,
                (SELECT row_to_json(l) FROM (SELECT x.id, x.kind, x.status, x.result, s.present_on FROM everbetter_presentations x JOIN everbetter_sessions s ON s.id = x.session_id
                    WHERE x.project_id = p.id AND x.status <> 'withdrawn' ORDER BY x.requested_at DESC LIMIT 1) l) AS last_request
            FROM everbetter_projects p LEFT JOIN departments d ON d.id = p.department_id
            WHERE `+strings.Join(w, " AND ")+` ORDER BY p.updated_at DESC, p.id DESC LIMIT 500`, p...)
	return WithMembers(rows)
}

// WithMembers adds members ([]db.Row: project_id, id, name, avatar) and decodes last_request (db.Row or nil).
func WithMembers(rows []db.Row) []db.Row {
	if len(rows) == 0 {
		return rows
	}
	ids := make([]string, len(rows))
	for i, r := range rows {
		ids[i] = php.Str(r.Int("id"))
	}
	all := db.Select(`SELECT m.project_id, u.id, u.name, u.avatar FROM everbetter_members m JOIN users u ON u.id = m.user_id
                           WHERE m.project_id IN (` + strings.Join(ids, ",") + `) ORDER BY u.name`)
	for _, r := range rows {
		members := []db.Row{}
		for _, m := range all {
			if m.Int("project_id") == r.Int("id") {
				members = append(members, m)
			}
		}
		r["members"] = members
		var last db.Row
		if s, ok := r["last_request"].(string); ok && s != "" {
			var m map[string]any
			if json.Unmarshal([]byte(s), &m) == nil && m != nil {
				last = db.Row{}
				for k, v := range m {
					if f, ok := v.(float64); ok && f == float64(int(f)) {
						v = int(f)
					}
					last[k] = v
				}
			}
		}
		if last == nil {
			r["last_request"] = nil
		} else {
			r["last_request"] = last
		}
	}
	return rows
}

// Find is one project this person may see (nil when not).
func Find(u *app.CurrentUser, id int) db.Row {
	sql, p := VisibleSQL(u, "p")
	row := db.First(`SELECT p.*, d.name AS department_name, NULL AS last_request FROM everbetter_projects p LEFT JOIN departments d ON d.id = p.department_id
                          WHERE p.id = ? AND `+sql, append([]any{id}, p...)...)
	if row == nil {
		return nil
	}
	return WithMembers([]db.Row{row})[0]
}

// Members of a project row (after WithMembers).
func Members(p db.Row) []db.Row {
	m, _ := p["members"].([]db.Row)
	return m
}

// CanEdit: owner or member, and the project is not closed.
func CanEdit(u *app.CurrentUser, p db.Row) bool {
	if p.Str("status") == "closed" {
		return false
	}
	if p.Int("owner_id") == u.ID {
		return true
	}
	for _, m := range Members(p) {
		if m.Int("id") == u.ID {
			return true
		}
	}
	return false
}

// SetMembers replaces the members of a project.
func SetMembers(projectID int, memberIDs []int) {
	seen := map[int]bool{}
	ids := []string{}
	for _, id := range memberIDs {
		if id != 0 && !seen[id] {
			seen[id] = true
			ids = append(ids, php.Str(id))
		}
	}
	var valid []int
	if len(ids) > 0 {
		for _, r := range db.Select("SELECT id FROM users WHERE deleted_at IS NULL AND id IN (" + strings.Join(ids, ",") + ")") {
			valid = append(valid, r.Int("id"))
		}
	}
	db.Exec("DELETE FROM everbetter_members WHERE project_id = ?", projectID)
	for _, uid := range valid {
		db.Exec("INSERT INTO everbetter_members (project_id, user_id) VALUES (?, ?) ON CONFLICT DO NOTHING", projectID, uid)
	}
}

/* ---------------------------------------------------- presentation days */

// QuarterOf is the year and quarter of a date ("" = today).
func QuarterOf(date string) (int, int) {
	t := php.Now()
	if date != "" {
		t = php.Unix(php.StrToTime(date))
	}
	return t.Year(), (int(t.Month())-1)/3 + 1
}

// UpcomingSessions are presentation days from today on (to choose from), oldest first.
func UpcomingSessions() []db.Row {
	return db.Select("SELECT * FROM everbetter_sessions WHERE present_on >= CURRENT_DATE ORDER BY present_on LIMIT 8")
}

func Session(year, q int) db.Row {
	return db.First("SELECT * FROM everbetter_sessions WHERE year = ? AND quarter = ?", year, q)
}

/* --------------------------------------------------------- presentations */

func Presentations(projectID int) []db.Row {
	return db.Select(`SELECT x.*, s.present_on, s.year, s.quarter, s.place FROM everbetter_presentations x JOIN everbetter_sessions s ON s.id = x.session_id
                           WHERE x.project_id = ? ORDER BY x.requested_at DESC, x.id DESC`, projectID)
}

// Open is a request still waiting to be presented (submitted or acknowledged, no result).
func Open(projectID int) db.Row {
	return db.First(`SELECT x.*, s.present_on FROM everbetter_presentations x JOIN everbetter_sessions s ON s.id = x.session_id
                          WHERE x.project_id = ? AND x.status <> 'withdrawn' AND x.result IS NULL ORDER BY x.requested_at DESC LIMIT 1`, projectID)
}

// AllowedKinds is what this project may present now.
func AllowedKinds(p db.Row) []string {
	switch p.Str("status") {
	case "draft", "rejected":
		return []string{"initiation"}
	case "approved":
		return []string{"closure", "extension"}
	}
	return []string{}
}

// StateOf is the label and tone of a request for people to read.
func StateOf(c *app.Ctx, x db.Row) (string, string) {
	switch {
	case x.Str("status") == "withdrawn":
		return c.T("Withdrawn"), "bg-graphite-900/6 text-steel"
	case php.Str(x["result"]) == "approved":
		return c.T("Approved"), "bg-emerald-50 text-emerald-800"
	case php.Str(x["result"]) == "rejected":
		return c.T("Not approved"), "bg-signal-50 text-signal-800"
	case x.Str("status") == "acknowledged":
		return c.T("Accepted by Management"), "bg-sky-50 text-sky-800"
	}
	return c.T("Waiting for Management"), "bg-amber-50 text-amber-800"
}

var dateRe = regexp.MustCompile(`^\d{4}-\d{2}-\d{2}$`)

func noteOrNil(note string) any {
	if strings.TrimSpace(note) == "" {
		return nil
	}
	return php.Substr(strings.TrimSpace(note), 0, 4000)
}

// Request asks to present on a day.
func Request(c *app.Ctx, u *app.CurrentUser, p db.Row, sessionID int, kind, newEnd, note string) int {
	if !php.InList(kind, AllowedKinds(p)...) {
		if p.Str("status") == "approved" {
			app.Invalid("kind", c.T("An approved project can present its closure or ask to extend its time."))
		}
		app.Invalid("kind", c.T("Present the project initiation first."))
	}
	if Open(p.Int("id")) != nil {
		app.Invalid("kind", c.T("This project already has a presentation waiting. Withdraw it first to change it."))
	}
	s := db.First("SELECT * FROM everbetter_sessions WHERE id = ? AND present_on >= CURRENT_DATE", sessionID)
	if s == nil {
		app.Invalid("session_id", c.T("Choose a presentation day."))
	}
	var end any
	if kind == "extension" {
		if newEnd == "" || !dateRe.MatchString(newEnd) || newEnd <= p.Str("end_date") {
			app.Invalid("new_end_date", c.T("Choose a new end date after :d.", "d", app.FormatDate(c, p["end_date"], "d M Y")))
		}
		end = newEnd
	}
	id := db.Core.InsertID("everbetter_presentations", db.Cols{{Name: "project_id", Value: p["id"]}, {Name: "session_id", Value: sessionID}, {Name: "kind", Value: kind},
		{Name: "new_end_date", Value: end}, {Name: "note", Value: noteOrNil(note)}, {Name: "requested_by", Value: u.ID}, {Name: "requested_name", Value: u.Name}})
	db.Exec("UPDATE everbetter_projects SET updated_at = now() WHERE id = ?", p["id"])
	app.LogActivity(c, app.ActivityEntry{Action: "created", Type: "everbetter", ID: p.Int("id"), Label: p.Str("title"), Text: `Asked to present ":label" (:kind) on :date`,
		Params: []any{"label", p["title"], "kind", Kinds[kind], "date", app.FormatDate(c, s["present_on"], "d M Y")}, Props: app.M{"kind": kind, "session": sessionID}, NoNotify: true})
	for _, mid := range Managers() {
		if mid != u.ID {
			app.Deliver(c, "core", "created", mid, app.Text("Everbetter: :name will present \":title\"", "name", u.Name, "title", p["title"]),
				app.Text(":kind · :date", "kind", Kinds[kind], "date", app.FormatDate(c, s["present_on"], "d M Y")), c.URL("/management/everbetter"), u.ID)
		}
	}
	return id
}

// Withdraw takes back a request Management has not accepted yet.
func Withdraw(c *app.Ctx, x db.Row) {
	if x.Str("status") != "submitted" || x["result"] != nil {
		app.Invalid("request", c.T("Only a request Management has not accepted yet can be withdrawn."))
	}
	db.Exec("UPDATE everbetter_presentations SET status = 'withdrawn' WHERE id = ?", x["id"])
}

// Acknowledge: Management accepts a request.
func Acknowledge(c *app.Ctx, u *app.CurrentUser, id int) db.Row {
	x := Presentation(id)
	if x == nil {
		app.Abort(404)
	}
	if x.Str("status") != "submitted" {
		app.Invalid("request", c.T("This request was already handled."))
	}
	db.Exec("UPDATE everbetter_presentations SET status = 'acknowledged', acknowledged_by = ?, acknowledged_name = ?, acknowledged_at = now() WHERE id = ?", u.ID, u.Name, id)
	tell(c, x, app.Text("Everbetter: your presentation of \":title\" is accepted", "title", x["title"]),
		app.Text(":kind · :date", "kind", Kinds[x.Str("kind")], "date", app.FormatDate(c, x["present_on"], "d M Y")), u.ID)
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "everbetter", ID: x.Int("project_id"), Label: x.Str("title"), Text: `Accepted the presentation of ":label" (:kind)`,
		Params: []any{"label", x["title"], "kind", Kinds[x.Str("kind")]}, NoNotify: true})
	return x
}

// Decide records the result after the presentation, and what that does to the project.
func Decide(c *app.Ctx, u *app.CurrentUser, id int, result, note string) db.Row {
	x := Presentation(id)
	if x == nil {
		app.Abort(404)
	}
	if result != "approved" && result != "rejected" {
		app.Invalid("result", c.T("Choose approved or not approved."))
	}
	if x.Str("status") == "withdrawn" || x["result"] != nil {
		app.Invalid("request", c.T("This request was already handled."))
	}
	db.Exec(`UPDATE everbetter_presentations SET result = ?, result_note = ?, decided_by = ?, decided_name = ?, decided_at = now(),
                  status = 'acknowledged', acknowledged_by = COALESCE(acknowledged_by, ?), acknowledged_name = COALESCE(acknowledged_name, ?), acknowledged_at = COALESCE(acknowledged_at, now())
                  WHERE id = ?`, result, noteOrNil(note), u.ID, u.Name, u.ID, u.Name, id)
	kind := x.Str("kind")
	set := ""
	switch {
	case kind == "initiation" && result == "approved":
		set = "status = 'approved', approved_at = now()"
	case kind == "initiation":
		set = "status = 'rejected'"
	case kind == "closure" && result == "approved":
		set = "status = 'closed', closed_at = now()"
	case kind == "extension" && result == "approved":
		set = "end_date = ?::date"
	}
	params := []any{x["project_id"]}
	if set == "end_date = ?::date" {
		params = []any{x["new_end_date"], x["project_id"]}
	}
	if set != "" {
		set += ", "
	}
	db.Exec("UPDATE everbetter_projects SET "+set+"updated_at = now() WHERE id = ?", params...)
	key := `Everbetter: ":title" not approved (:kind)`
	if result == "approved" {
		key = `Everbetter: ":title" approved (:kind)`
	}
	shown := note
	if strings.TrimSpace(note) == "" {
		shown = "—"
	}
	tell(c, x, app.Text(key, "title", x["title"], "kind", Kinds[kind]), app.Text(":note", "note", shown), u.ID)
	text := `Did not approve ":label" (:kind)`
	if result == "approved" {
		text = `Approved ":label" (:kind)`
	}
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "everbetter", ID: x.Int("project_id"), Label: x.Str("title"), Text: text,
		Params: []any{"label", x["title"], "kind", Kinds[kind]}, Props: app.M{"note": note}, NoNotify: true})
	return x
}

// Presentation is one request with its day and project.
func Presentation(id int) db.Row {
	return db.First(`SELECT x.*, s.present_on, p.title, p.owner_id, p.end_date FROM everbetter_presentations x JOIN everbetter_sessions s ON s.id = x.session_id
                          JOIN everbetter_projects p ON p.id = x.project_id WHERE x.id = ? AND p.deleted_at IS NULL`, id)
}

// tell: owner, members and whoever asked — one message each.
func tell(c *app.Ctx, x db.Row, title, body app.M, actor int) {
	to := []int{}
	for _, r := range db.Select("SELECT user_id FROM everbetter_members WHERE project_id = ?", x["project_id"]) {
		to = append(to, r.Int("user_id"))
	}
	to = append(to, x.Int("owner_id"), x.Int("requested_by"))
	seen := map[int]bool{}
	for _, uid := range to {
		if uid == 0 || seen[uid] {
			continue
		}
		seen[uid] = true
		if uid != actor {
			app.Deliver(c, "core", "updated", uid, title, body, c.URL("/everbetter/"+php.Str(x.Int("project_id"))), actor)
		}
	}
}

// Managers are active people whose role opens Management, and administrators.
func Managers() []int {
	var out []int
	for _, r := range db.Select(`SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
            WHERE u.is_active AND u.deleted_at IS NULL AND (r.slug = 'admin' OR EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions pm ON pm.id = rp.permission_id
                WHERE rp.role_id = r.id AND pm.key = 'management' AND rp.can_view))`) {
		out = append(out, r.Int("id"))
	}
	return out
}
