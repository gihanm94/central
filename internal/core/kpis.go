package core

import (
	"encoding/json"
	"fmt"
	"html/template"
	"math"
	"net/http"
	"strings"

	"acme/internal/app"
	"acme/internal/conv"
	"acme/internal/db"
)

// KPIs & OKRs (KpiController).

var kpiStatusKeys = []app.Opt{{Value: "active", Label: "Active"}, {Value: "achieved", Label: "Achieved"}, {Value: "missed", Label: "Missed"}, {Value: "archived", Label: "Archived"}}

// kpiKinds: KPIs and OKRs are what people submit a monthly result for (KPI submissions).
var kpiKinds = []app.Opt{{Value: "kpi", Label: "KPI"}, {Value: "okr", Label: "OKR"}}

// kpiPeriods: measuring period, as the KPI calculator (Management) reads it.
var kpiPeriods = []app.Opt{{Value: "month", Label: "Monthly"}, {Value: "quarter", Label: "Quarterly"}, {Value: "half", Label: "Half-year"}, {Value: "year", Label: "Yearly"}}

// KpiStatusTones is KpiController::STATUS_TONES.
var KpiStatusTones = map[string]string{"active": "bg-sky-600 text-white", "achieved": "bg-emerald-600 text-white", "missed": "bg-signal-600 text-white", "archived": "bg-graphite-500 text-white"}

// KpiStatuses is KpiController::statuses().
func KpiStatuses(c *app.Ctx) []app.Opt { return translatedOpts(c, kpiStatusKeys) }

// KpiKinds is KpiController::kinds().
func KpiKinds(c *app.Ctx) []app.Opt { return translatedOpts(c, kpiKinds) }

// The KpiSubmissions rules the KPI screen uses (App\Core\Support\KpiSubmissions).

// kpiResScope: administrators and Management see all, a BU manager the department and their own, everyone else their own.
func kpiResScope(u *app.CurrentUser, alias string) (string, []any) {
	scope := u.Scope()
	if u.IsAdmin() {
		scope = "all"
	}
	if scope == "all" {
		return "TRUE", nil
	}
	if scope == "department" && u.DepartmentID != 0 {
		return "(" + alias + ".department_id = ? OR " + alias + ".user_id = ?)", []any{u.DepartmentID, u.ID}
	}
	return alias + ".user_id = ?", []any{u.ID}
}

// kpiResCanManage: adding, changing and deleting KPIs & OKRs: administrators, and the BU manager of that department.
func kpiResCanManage(u *app.CurrentUser, departmentID any) bool {
	if u.IsAdmin() {
		return true
	}
	return u.Scope() == "department" && u.DepartmentID != 0 && conv.Int(departmentID) == u.DepartmentID
}

// kpiResCanAdd: may this person add KPIs & OKRs at all (administrator or a department role)?
func kpiResCanAdd(u *app.CurrentUser) bool {
	return u.IsAdmin() || (u.Scope() == "department" && u.DepartmentID != 0)
}

func kpiKindLabel(kind string) string {
	if l, ok := app.OptLabel(kpiKinds, kind); ok {
		return l
	}
	return "KPI"
}

func kpiKindBadge(kind string) string {
	if kind == "okr" {
		return "bg-violet-50 text-violet-800"
	}
	return "bg-sky-50 text-sky-800"
}

func kpiPct(row db.Row) int {
	if conv.Float(row["target"]) > 0 {
		return int(math.Round(conv.Float(row["actual"]) / conv.Float(row["target"]) * 100))
	}
	return 0
}

var kpis = &app.Resource{
	Resource: "kpis", Table: "kpis", Type: "kpi", Singular: "KPI / OKR", Plural: "KPIs & OKRs",
	Base: "/kpis", Icon: "chart", OrderBy: "CASE t.status WHEN 'active' THEN 0 ELSE 1 END, t.period_end DESC, t.id DESC",
	Searchable: []string{"t.title", "o.name"},
	SelectSQL: func(c *app.Ctx) string {
		return "SELECT t.*, o.name AS owner_name, o.avatar AS owner_avatar FROM kpis t LEFT JOIN users o ON o.id = t.user_id"
	},
	ScopeSQL: func(c *app.Ctx) (string, []any) { return kpiResScope(c.MustUser(), "t") },
	CanModify: func(c *app.Ctx, row db.Row, action string) bool {
		return kpiResCanManage(c.MustUser(), row["department_id"])
	},
	Fields: kpiFields,
	Columns: func(c *app.Ctx) []app.Column {
		statuses := optMap(KpiStatuses(c))
		return []app.Column{
			{Key: "title", Label: c.T("KPI / OKR"), Primary: true, Render: func(c *app.Ctx, r db.Row) template.HTML {
				return app.HTML(`<span class="badge mr-1 ` + kpiKindBadge(r.Str("kind")) + `">` + app.E(kpiKindLabel(r.Str("kind"))) + `</span>` +
					`<a href="` + c.URL("/kpis/"+r.Str("id")) + `" class="font-medium hover:text-signal-700">` + app.E(r["title"]) + `</a>`)
			}},
			{Key: "criteria", Label: c.T("Criteria"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				v := "—"
				if r["criteria"] != nil {
					v = app.E(conv.NumberClean(r["criteria"]))
				}
				return app.HTML(`<span class="tabular-nums">` + v + `</span>`)
			}},
			{Key: "owner", Label: c.T("Owner"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				if r.Str("owner_name") == "" {
					return "—"
				}
				return TaskPerson(c, r.Str("owner_name"), r["owner_avatar"])
			}},
			{Key: "progress", Label: c.T("Progress"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				pct := kpiPct(r)
				return app.HTML(`<div class="flex min-w-40 items-center gap-3">` + string(c.Partial("partials/progress", app.M{"value": pct})) + `<span class="w-10 text-right text-xs tabular-nums">` + conv.Str(pct) + `%</span></div>` +
					`<p class="mt-1 text-xs text-steel">` + conv.NumberClean(r["actual"]) + ` / ` + conv.NumberClean(r["target"]) + ` ` + app.E(r["unit"]) + `</p>`)
			}},
			{Key: "period", Label: c.T("Period"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				return app.HTML(`<span class="text-xs text-steel">` + app.FormatDate(c, r["period_start"], "d M") + ` – ` + app.FormatDate(c, r["period_end"], "d M Y") + `</span>`)
			}},
			{Key: "status", Label: c.T("Status"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				tone, ok := KpiStatusTones[r.Str("status")]
				if !ok {
					tone = "bg-graphite-900/6 text-graphite-800"
				}
				l, ok := statuses[r.Str("status")]
				if !ok {
					l = r.Str("status")
				}
				return app.HTML(`<span class="badge ` + tone + `">` + app.E(l) + `</span>`)
			}},
		}
	},
	Filters: func(c *app.Ctx) []app.Filter {
		return []app.Filter{
			{Name: "kind", Label: c.T("KPIs and OKRs"), Options: KpiKinds(c), Column: "t.kind"},
			{Name: "status", Label: c.T("All statuses"), Options: KpiStatuses(c), Column: "t.status"},
		}
	},
	Owner:     func(row db.Row) int { return row.Int("user_id") },
	Prepare:   kpiPrepare,
	ShowExtra: kpiShowExtra,
}

func kpiFields(c *app.Ctx, row db.Row) []app.Field {
	no := false
	var all []app.Opt
	if row != nil {
		all = []app.Opt{{Value: row.Str("user_id"), Label: row.Str("owner_name"), Img: c.UploadURL(row["owner_avatar"])}}
	}
	y := conv.Date("Y", conv.Now())
	return []app.Field{
		{Name: "title", Label: c.T("KPI / OKR"), Rules: "required|max:160", Span: 2, Example: "Finish assigned work on time at least 90% per month"},
		{Name: "kind", Label: c.T("Type"), Type: "select", Options: KpiKinds(c), Rules: "required", Default: "kpi", Search: &no, Example: "kpi"},
		{Name: "criteria", Label: c.T("Criteria"), Type: "number", Rules: "nullable|numeric", Example: "90",
			Help: c.T("Target or weight from 1 to 100, e.g. 90 for \"at least 90%\". Copied into each monthly submission.")},
		{Name: "direction", Label: c.T("Direction"), Type: "select", Options: []app.Opt{{Value: "min", Label: c.T("Not less than (higher is better)")}, {Value: "max", Label: c.T("Not more than (a limit)")}},
			Rules: "required", Default: "min", Search: &no, Example: "min"},
		{Name: "measure", Label: c.T("Monthly figure"), Type: "select", Options: []app.Opt{{Value: "percent", Label: c.T("Finished ÷ total, in %")}, {Value: "count", Label: c.T("Count of finished")}},
			Rules: "required", Default: "percent", Search: &no, Example: "percent",
			Help: c.T("How the KPI calculator reads each monthly submission against the criteria.")},
		{Name: "period", Label: c.T("Measured"), Type: "select", Options: translatedOpts(c, kpiPeriods), Rules: "required", Default: "month", Search: &no, Example: "month"},
		{Name: "weight", Label: c.T("Weight %"), Type: "number", Rules: "nullable|numeric", Example: "30",
			Help: c.T("Share of this KPI in the KPI section; the weights of one person should add up to 100.")},
		{Name: "user_id", Label: c.T("Owner"), Type: "select", Options: app.OptionsPeople(c, c.MustUser(), true), Rules: "required", Rich: true, AllOptions: all},
		{Name: "status", Label: c.T("Status"), Type: "select", Options: KpiStatuses(c), Rules: "required", Default: "active"},
		{Name: "target", Label: c.T("Target"), Type: "number", Rules: "required|numeric", Example: "95"},
		{Name: "actual", Label: c.T("Actual"), Type: "number", Rules: "required|numeric", Default: "0", Example: "80"},
		{Name: "unit", Label: c.T("Unit"), Rules: "required|max:20", Default: "%", Help: c.T("e.g. %, hrs, THB, units"), Example: "%"},
		{Name: "period_start", Label: c.T("Period start"), Type: "date", Rules: "required|date", Default: y + "-01-01", Example: y + "-01-01",
			Help: c.T("People submit a result every month inside this period.")},
		{Name: "period_end", Label: c.T("Period end"), Type: "date", Rules: "required|date", Default: y + "-12-31", Example: y + "-12-31"},
		{Name: "description", Label: c.T("How it is measured"), Type: "textarea", Span: 2, Rules: "nullable|max:2000"},
	}
}

func kpiPrepare(c *app.Ctx, data *app.Clean, existing db.Row) *app.Clean {
	if data.Get("period_start") != nil && data.Get("period_end") != nil && conv.StrToTime(data.Str("period_end")) < conv.StrToTime(data.Str("period_start")) {
		app.Invalid("period_end", c.T("Period end must be on or after the start."))
	}
	if v := data.Get("criteria"); v != nil && conv.Str(v) != "" && (conv.Float(v) < 1 || conv.Float(v) > 100) {
		app.Invalid("criteria", c.T("Criteria must be between 1 and 100."))
	}
	if v := data.Get("weight"); v != nil && conv.Str(v) != "" && (conv.Float(v) <= 0 || conv.Float(v) > 100) {
		app.Invalid("weight", c.T("Weight must be between 1 and 100."))
	}
	if existing == nil {
		data.Set("created_by", c.UserID())
	}
	if !conv.Empty(data.Get("user_id")) {
		o := db.First("SELECT department_id, team_id FROM users WHERE id = ?", data.Get("user_id"))
		var dep, team any
		if o != nil {
			dep, team = o["department_id"], o["team_id"]
		}
		if !kpiResCanManage(c.MustUser(), dep) {
			app.Invalid("user_id", c.T("You can only give KPIs & OKRs to people in your department."))
		}
		data.Set("department_id", dep)
		data.Set("team_id", team)
	}
	return data
}

func kpiGuardAdd(c *app.Ctx) {
	if !kpiResCanAdd(c.MustUser()) {
		app.Abort(http.StatusForbidden, c.T("Only a BU manager or an administrator can add KPIs & OKRs."))
	}
}

// kpiTone / kpiBar colour a percentage (kpi/kpi-extra).
func kpiTone(p float64) string {
	switch {
	case p >= 100:
		return "text-emerald-700"
	case p >= 70:
		return "text-amber-700"
	}
	return "text-signal-700"
}

func kpiBar(p float64) string {
	switch {
	case p >= 100:
		return "bg-emerald-600"
	case p >= 70:
		return "bg-amber-500"
	}
	return "bg-signal-600"
}

type kpiChange struct{ Label, From, To string }

// kpiShowExtra: status, progress against the target, this year's submissions and every change, on the KPI's page.
func kpiShowExtra(c *app.Ctx, row db.Row) template.HTML {
	subs := db.Select(`SELECT s.*, ap.name AS approver_name, ap.avatar AS approver_avatar FROM kpi_submissions s LEFT JOIN users ap ON ap.id = s.approver_id
            WHERE s.kpi_id = ? AND s.deleted_at IS NULL ORDER BY s.period_year DESC, s.period_month DESC LIMIT 36`, row["id"])
	history := db.Select(`SELECT l.*, u.name AS user_name, u.avatar FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
            WHERE (l.subject_type = 'kpi' AND l.subject_id = ?) OR (l.subject_type = 'kpi_submission' AND l.properties->>'kpi_id' = ?)
            ORDER BY l.created_at DESC, l.id DESC LIMIT 100`, row["id"], row.Str("id"))

	pct := kpiPct(row)
	approved := 0
	sum := 0.0
	for _, s := range subs {
		if s.Str("approved_at") != "" {
			approved++
		}
		sum += conv.Float(s["percentage"])
		pc := conv.Int(s["percentage"])
		s["_month"] = app.FormatDate(c, fmt.Sprintf("%04d-%02d-01", conv.Int(s["period_year"]), conv.Int(s["period_month"])), "F Y")
		switch {
		case pc >= 100:
			s["_pctTone"] = "bg-emerald-50 text-emerald-800"
		case pc >= 60:
			s["_pctTone"] = "bg-amber-50 text-amber-800"
		default:
			s["_pctTone"] = "bg-signal-50 text-signal-800"
		}
		s["_pct"] = pc
		lateTone := "bg-signal-50 text-signal-800"
		if !conv.Empty(s["percentage"]) {
			lateTone = "bg-amber-50 text-amber-800"
		}
		s["_lateTone"] = lateTone
	}
	avg := ""
	avgTone := "text-steel"
	if len(subs) > 0 {
		a := sum / float64(len(subs))
		avg = conv.NF(a, 0) + "%"
		avgTone = kpiTone(a)
	}

	// Labels for the fields named in the history
	labels := map[string]string{}
	for _, f := range kpiFields(c, row) {
		labels[f.Name] = f.Label
	}
	for k, v := range map[string]string{"approver": c.T("Approver"), "finished": c.T("Finished"), "total": c.T("Total tasks"), "criteria": c.T("Criteria"),
		"note": c.T("Note"), "related_link": c.T("Related link"), "percentage": c.T("Percentage"), "days_late": c.T("Days late")} {
		if _, ok := labels[k]; !ok {
			labels[k] = v
		}
	}
	actionTone := map[string]string{"created": "bg-emerald-50 text-emerald-800", "updated": "bg-sky-50 text-sky-800", "deleted": "bg-signal-50 text-signal-800", "approved": "bg-violet-50 text-violet-800"}
	for _, log := range history {
		var props map[string]json.RawMessage
		_ = json.Unmarshal([]byte(log.Str("properties")), &props)
		var changes []kpiChange
		if raw, ok := props["changes"]; ok {
			for _, kv := range orderedObject(raw) {
				var ch map[string]any
				_ = json.Unmarshal(kv.val, &ch)
				l, ok := labels[kv.key]
				if !ok {
					l = conv.Ucfirst(strings.ReplaceAll(kv.key, "_", " "))
				}
				from, to := conv.Str(ch["from"]), conv.Str(ch["to"])
				if from == "" {
					from = "—"
				}
				if to == "" {
					to = "—"
				}
				changes = append(changes, kpiChange{Label: l, From: conv.Limit(from, 80), To: conv.Limit(to, 80)})
			}
		}
		log["_changes"] = changes
		tone, ok := actionTone[log.Str("action")]
		if !ok {
			tone = "bg-graphite-900/6 text-graphite-800"
		}
		log["_tone"] = tone
		log["_iso"] = conv.DateOf("c", log["created_at"])
	}
	statusTone, ok := KpiStatusTones[row.Str("status")]
	if !ok {
		statusTone = "bg-graphite-900 text-white"
	}
	statusLabel, ok := app.OptLabel(KpiStatuses(c), row.Str("status"))
	if !ok {
		statusLabel = row.Str("status")
	}
	return c.Partial("kpi/kpi-extra", app.M{
		"row": row, "subs": subs, "history": history, "pct": pct, "pctTone": kpiTone(float64(pct)), "pctBar": kpiBar(float64(pct)),
		"pctWidth": min(100, max(0, pct)), "avg": avg, "avgTone": avgTone, "approved": approved,
		"statusTone": statusTone, "statusLabel": statusLabel, "kindBadge": kpiKindBadge(row.Str("kind")), "kindLabel": kpiKindLabel(row.Str("kind")),
		"criteria": func() string {
			if row["criteria"] == nil {
				return "—"
			}
			return conv.NumberClean(row["criteria"])
		}(),
		"amount": conv.NumberClean(row["actual"]) + " / " + conv.NumberClean(row["target"]) + " " + row.Str("unit"),
	})
}

type jsonKV struct {
	key string
	val json.RawMessage
}

// orderedObject reads a JSON object keeping its key order (PHP arrays keep it).
func orderedObject(raw json.RawMessage) []jsonKV {
	dec := json.NewDecoder(strings.NewReader(string(raw)))
	t, err := dec.Token()
	if err != nil {
		return nil
	}
	if d, ok := t.(json.Delim); !ok || d != '{' {
		return nil
	}
	var out []jsonKV
	for dec.More() {
		kt, err := dec.Token()
		if err != nil {
			return out
		}
		var v json.RawMessage
		if err := dec.Decode(&v); err != nil {
			return out
		}
		out = append(out, jsonKV{key: conv.Str(kt), val: v})
	}
	return out
}

func init() {
	signedIn(func(r app.Router) {
		kpis.Mount(r, "create", "store")
		r.Get("/kpis/create", func(c *app.Ctx) { kpiGuardAdd(c); kpis.Create(c) })
		r.Post("/kpis", func(c *app.Ctx) { kpiGuardAdd(c); kpis.Store(c) })
	})
}
