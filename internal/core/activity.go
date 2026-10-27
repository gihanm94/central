package core

import (
	"bytes"
	"encoding/json"
	"strings"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/php"
)

/*
Read-only audit trail (ActivityController). Administrator → Activity log: the activity of members inside the viewer's scope.
User Management → My activity (/my-activity): everyone's own entries only, no permission needed.
*/

func init() {
	signedIn(func(r app.Router) {
		r.Get("/my-activity", activityMine)
		r.Get("/my-activity/export", activityMineExport)
		r.Get("/my-activity/{id}", activityMineShow)
		r.Get("/activity", activityIndex)
		r.Get("/activity/export", activityExport)
		r.Get("/activity/{id}", activityShow)
	})
}

// ActivityActions is ActivityController::ACTIONS (English labels, in order); partials/activity-row keeps the same list.
var ActivityActions = []app.Opt{
	{Value: "created", Label: "Created"}, {Value: "updated", Label: "Updated"}, {Value: "deleted", Label: "Deleted"}, {Value: "login", Label: "Signed in"}, {Value: "logout", Label: "Signed out"},
	{Value: "login_failed", Label: "Failed sign-in"}, {Value: "password_reset", Label: "Password reset"}, {Value: "password_changed", Label: "Password changed"},
	{Value: "import", Label: "Import"}, {Value: "export", Label: "Export"}, {Value: "download", Label: "Download"}, {Value: "passkey_added", Label: "Passkey added"}, {Value: "passkey_removed", Label: "Passkey removed"}, {Value: "password_reset_requested", Label: "Reset link requested"},
}

// ActivityActionLabels is ActivityController::actions(): the labels in the reader's language.
func ActivityActionLabels(c *app.Ctx) []app.Opt {
	out := make([]app.Opt, len(ActivityActions))
	for i, a := range ActivityActions {
		out[i] = app.Opt{Value: a.Value, Label: c.T(a.Label)}
	}
	return out
}

// ActivityActionLabel is actions()[$action] ?? $fallback.
func ActivityActionLabel(c *app.Ctx, action, fallback string) string {
	if l, ok := app.OptLabel(ActivityActions, action); ok {
		return c.T(l)
	}
	return fallback
}

func activityWhere(c *app.Ctx, mine bool) (string, []any) {
	u := c.MustUser()
	var where []string
	var p []any
	if mine {
		where = []string{"l.user_id = ?"}
		p = []any{u.ID}
	} else if u.Scope() == "all" {
		where = []string{"TRUE"}
	} else {
		sql, par := app.ScopeSQL(u, "su", app.ScopeCols{Owner: []string{"id"}, Department: "department_id", Team: "team_id"})
		where = []string{"l.user_id IN (SELECT su.id FROM users su WHERE " + sql + ")"}
		p = par
	}
	if a := c.Query("action"); a != "" {
		if _, ok := app.OptLabel(ActivityActions, a); ok {
			where = append(where, "l.action = ?")
			p = append(p, a)
		}
	}
	if uid := php.Int(c.Query("user")); !mine && uid != 0 {
		where = append(where, "l.user_id = ?")
		p = append(p, uid)
	}
	if m := c.Query("module"); m != "" && app.ModuleExists(m) {
		where = append(where, "l.module = ?")
		p = append(p, m)
	}
	if from := c.Query("from"); from != "" && php.StrToTime(from) != 0 {
		where = append(where, "l.created_at >= ?")
		p = append(p, from+" 00:00:00")
	}
	if to := c.Query("to"); to != "" && php.StrToTime(to) != 0 {
		where = append(where, "l.created_at <= ?")
		p = append(p, to+" 23:59:59")
	}
	if q := c.Query("q"); q != "" {
		where = append(where, "(l.description ILIKE ? OR l.subject_label ILIKE ? OR l.ip_address ILIKE ?)")
		p = append(p, "%"+q+"%", "%"+q+"%", "%"+q+"%")
	}
	return strings.Join(where, " AND "), p
}

func activityIndex(c *app.Ctx) {
	c.Authorize("activity_logs", "view")
	activityList(c, false)
}

// User Management → My activity.
func activityMine(c *app.Ctx) { activityList(c, true) }

func activityList(c *app.Ctx, mine bool) {
	w, p := activityWhere(c, mine)
	page := max(1, php.Int(c.Query("page", "1")))
	total := php.Int(db.Scalar("SELECT COUNT(*) FROM activity_logs l WHERE "+w, p...))
	logs := db.Select(`SELECT l.*, u.name AS user_name, u.avatar FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
                              WHERE `+w+" ORDER BY l.created_at DESC, l.id DESC LIMIT 30 OFFSET "+php.Str((page-1)*30), p...)
	title, base, sub := c.T("Activity log"), "/activity", c.T(":count entries. Every sign-in, change, import, export and download is recorded here.", "count", total)
	var users []app.Opt
	if mine {
		title, base, sub = c.T("My activity"), "/my-activity", c.T(":count entries. Your own sign-ins, changes, imports, exports and downloads.", "count", total)
	} else {
		users = app.OptionsUsers(c, c.MustUser(), false)
	}
	filtered := false
	for _, v := range c.QueryMap() {
		if !php.Empty(v) {
			filtered = true
		}
	}
	c.View("activity/index", app.M{
		"title": title, "sub": sub, "logs": logs, "total": total, "page": page, "perPage": 30, "users": users, "mine": mine, "base": base,
		"canExport": mine || c.Can("activity_logs", "export"), "exportURL": c.URL(base+"/export", c.QueryMap()), "filtered": filtered,
		"actions": ActivityActionLabels(c),
	})
}

func activityShow(c *app.Ctx) {
	c.Authorize("activity_logs", "view")
	activityEntry(c, c.ParamInt("id"), false)
}

func activityMineShow(c *app.Ctx) { activityEntry(c, c.ParamInt("id"), true) }

func activityEntry(c *app.Ctx, id int, mine bool) {
	w, p := activityWhere(c, mine)
	log := db.First("SELECT l.*, u.name AS user_name, u.email AS user_email, u.avatar FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.id = ? AND "+w, append([]any{id}, p...)...)
	if log == nil {
		app.Abort(404, c.T("Log entry not found."))
	}
	base, crumb := "/activity", c.T("Activity log")
	if mine {
		base, crumb = "/my-activity", c.T("My activity")
	}
	props := config.NewMap()
	if log.Str("properties") != "" {
		if v, err := config.Decode([]byte(log.Str("properties"))); err == nil {
			if m, ok := v.(*config.Map); ok {
				props = m
			}
		}
	}
	props.Delete("_t")

	person := php.Str(php.Coalesce(log["user_name"], c.T("System")))
	if log.Str("user_email") != "" {
		person += " (" + log.Str("user_email") + ")"
	}
	module := log.Str("module")
	if app.ModuleExists(module) {
		module = app.ModuleByKey(module).Name
	}
	record := ""
	if log.Str("subject_type") != "" {
		record = c.T(log.Str("subject_type"))
		if log.Str("subject_id") != "" && log.Str("subject_id") != "0" {
			record += " #" + log.Str("subject_id")
		}
		if log.Str("subject_label") != "" {
			record += " — " + log.Str("subject_label")
		}
	}
	facts := [][2]string{
		{c.T("Person"), person}, {c.T("Action"), ActivityActionLabel(c, log.Str("action"), log.Str("action"))}, {c.T("Module"), c.T(module)},
		{c.T("Record"), record}, {c.T("IP address"), log.Str("ip_address")}, {c.T("Device"), log.Str("user_agent")},
	}
	for i := range facts {
		if facts[i][1] == "" || facts[i][1] == "0" {
			facts[i][1] = "—"
		}
	}

	type change struct{ Field, From, To string }
	var changes []change
	if cv, ok := props.Get("changes"); ok {
		if cm, ok := cv.(*config.Map); ok {
			for _, f := range cm.Keys {
				ch, _ := cm.Vals[f].(*config.Map)
				var from, to any
				if ch != nil {
					from, _ = ch.Get("from")
					to, _ = ch.Get("to")
				}
				changes = append(changes, change{Field: c.T(php.Ucfirst(strings.ReplaceAll(f, "_", " "))), From: wsScalar(from), To: wsScalar(to)})
			}
		}
	}
	rest := config.NewMap()
	for _, k := range props.Keys {
		if k != "changes" {
			rest.Set(k, props.Vals[k])
		}
	}
	pretty := ""
	if rest.Len() > 0 {
		pretty = wsPrettyJSON(rest)
	}
	c.View("activity/show", app.M{
		"title": c.T("Activity #:id", "id", id), "log": log, "base": base, "crumb": crumb, "facts": facts,
		"changes": changes, "pretty": pretty, "noProps": props.Len() == 0,
	})
}

// wsScalar: is_scalar($v) ? $v : json_encode($v).
func wsScalar(v any) string {
	switch x := v.(type) {
	case string, int, int64, float64, bool:
		return php.Str(x)
	}
	b, _ := json.Marshal(v)
	return wsUnescapeHTML(string(b))
}

func wsUnescapeHTML(s string) string {
	return strings.NewReplacer(`<`, "<", `>`, ">", `&`, "&").Replace(s)
}

// wsPrettyJSON is json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).
func wsPrettyJSON(v any) string {
	raw, _ := json.Marshal(v)
	var buf bytes.Buffer
	if err := json.Indent(&buf, raw, "", "    "); err != nil {
		return wsUnescapeHTML(string(raw))
	}
	return wsUnescapeHTML(buf.String())
}

func activityExport(c *app.Ctx) {
	c.Authorize("activity_logs", "export")
	activityDownload(c, false)
}

// Everyone may download their own entries.
func activityMineExport(c *app.Ctx) { activityDownload(c, true) }

func activityDownload(c *app.Ctx, mine bool) {
	w, p := activityWhere(c, mine)
	rows := db.Select(`SELECT l.created_at, u.name, l.module, l.action, l.description, l.properties, l.ip_address, l.user_agent
                              FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE `+w+" ORDER BY l.created_at DESC LIMIT 50000", p...)
	filters := app.M{}
	for k, v := range c.QueryMap() {
		if !php.Empty(v) {
			filters[k] = v
		}
	}
	var fv any = filters
	if len(filters) == 0 {
		fv = []any{} // PHP: an empty array is []
	}
	app.LogActivity(c, app.ActivityEntry{Action: "export", Type: "activity", Label: "Activity log", Text: "Exported :count activity log entries",
		Params: []any{"count", len(rows)}, Props: app.M{"count": len(rows), "filters": fv}})
	out := make([][]any, 0, len(rows))
	for _, r := range rows {
		out = append(out, []any{r["created_at"], r["name"], r["module"], ActivityActionLabel(c, r.Str("action"), r.Str("action")), app.ActivityText(c, r), r["ip_address"], r["user_agent"]})
	}
	name := "activity-log-"
	if mine {
		name = "my-activity-"
	}
	c.XlsxDownload(name+php.Date("Ymd-His", php.Now())+".xlsx", []string{c.T("Time"), c.T("Person"), c.T("Module"), c.T("Action"), c.T("Description"), "IP", c.T("Device")}, out)
}
