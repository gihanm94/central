package crm

import (
	"math"
	"regexp"
	"strconv"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

// Fixed lists of the CRM (Catalog): values are stored; labels are translated when shown.

type pair struct{ K, L string }

type stageDef struct {
	Code  string
	Label string
	Prob  *float64
	Tone  string
}

func pf(v float64) *float64 { return &v }

// stagesDef: the first four are the open pipeline, in order.
var stagesDef = []stageDef{
	{"QUALIFICATION", "Qualification", pf(10), "info"},
	{"SURVEY_PROPOSAL", "Survey & proposal", pf(30), "info"},
	{"EVALUATION_TESTING", "Evaluation & testing", pf(50), "info"},
	{"NEGOTIATION", "Negotiation", pf(75), "violet"},
	{"CLOSED_WON", "Closed won", pf(100), "success"},
	{"CLOSED_LOST", "Closed lost", pf(0), "danger"},
	{"ON_HOLD", "On hold", nil, "warn"},
	{"CANCEL", "Cancelled", pf(0), "neutral"},
}

var (
	stagePipeline = []string{"QUALIFICATION", "SURVEY_PROPOSAL", "EVALUATION_TESTING", "NEGOTIATION"}
	stageOpen     = []string{"QUALIFICATION", "SURVEY_PROPOSAL", "EVALUATION_TESTING", "NEGOTIATION", "ON_HOLD"}
	// closed for good: the stage can no longer be changed (duplicate the opportunity to start again)
	stageFinal = []string{"CLOSED_WON", "CLOSED_LOST", "CANCEL"}
	// stages that need a written reason
	stageNeedsReason = []string{"CLOSED_LOST", "CANCEL", "ON_HOLD"}
)

var (
	priorities       = []pair{{"LOW", "Low"}, {"MEDIUM", "Medium"}, {"HIGH", "High"}}
	activityTypes    = []pair{{"CALL", "Call"}, {"MEETING", "Meeting"}, {"EMAIL", "E-mail"}, {"TASK", "Task"}}
	activityStatuses = []pair{{"PLANNED", "Planned"}, {"DONE", "Done"}, {"CANCELLED", "Cancelled"}}
	callDirections   = []pair{{"OUTBOUND", "Outbound"}, {"INBOUND", "Inbound"}}
	meetingTypes     = []pair{{"ONSITE", "At our office"}, {"CUSTOMER_SITE", "At the customer"}, {"ONLINE", "Online"}}
	campaignTypes    = []pair{{"EMAIL", "E-mail"}, {"EVENT", "Event"}, {"WEBINAR", "Webinar"}, {"TRADE_SHOW", "Trade show"}, {"SOCIAL", "Social media"}, {"ADVERTISING", "Advertising"}, {"REFERRAL", "Referral"}, {"OTHER", "Other"}}
	campaignStatuses = []pair{{"DRAFT", "Draft"}, {"PLANNED", "Planned"}, {"ACTIVE", "Active"}, {"COMPLETED", "Completed"}, {"CANCELLED", "Cancelled"}}
	projectStatuses  = []pair{{"PLANNING", "Planning"}, {"ACTIVE", "Active"}, {"ON_HOLD", "On hold"}, {"DONE", "Done"}, {"CANCELLED", "Cancelled"}}
	taskStatuses     = []pair{{"TODO", "To do"}, {"IN_PROGRESS", "In progress"}, {"BLOCKED", "Blocked"}, {"DONE", "Done"}, {"CANCELLED", "Cancelled"}}
	projectTones     = map[string]string{"PLANNING": "neutral", "ACTIVE": "info", "ON_HOLD": "warn", "DONE": "success", "CANCELLED": "danger"}
	taskTones        = map[string]string{"TODO": "neutral", "IN_PROGRESS": "info", "BLOCKED": "warn", "DONE": "success", "CANCELLED": "danger"}
	salutations      = []string{"Mr.", "Mrs.", "Ms.", "Miss", "Dr.", "Prof.", "Khun"}
)

// pairLabel is the untranslated label of a code ("", false when unknown).
func pairLabel(list []pair, k string) (string, bool) {
	for _, p := range list {
		if p.K == k {
			return p.L, true
		}
	}
	return "", false
}

// trLabel: the translated label of a code, or the code itself (__(LIST[$k] ?? $k)).
func trLabel(c *app.Ctx, list []pair, k string) string {
	if l, ok := pairLabel(list, k); ok {
		return c.T(l)
	}
	return c.T(k)
}

// tr is Catalog::tr: options with translated labels.
func tr(c *app.Ctx, list []pair) []app.Opt {
	out := make([]app.Opt, 0, len(list))
	for _, p := range list {
		out = append(out, app.Opt{Value: p.K, Label: c.T(p.L)})
	}
	return out
}

func hasPair(list []pair, k string) bool { _, ok := pairLabel(list, k); return ok }

func stageDefOf(code string) (stageDef, bool) {
	for _, s := range stagesDef {
		if s.Code == code {
			return s, true
		}
	}
	return stageDef{}, false
}

func isStage(code string) bool { _, ok := stageDefOf(code); return ok }

func stageCodes() []string {
	out := make([]string, len(stagesDef))
	for i, s := range stagesDef {
		out[i] = s.Code
	}
	return out
}

// stageOptions is Catalog::stages().
func stageOptions(c *app.Ctx) []app.Opt {
	out := make([]app.Opt, 0, len(stagesDef))
	for _, s := range stagesDef {
		out = append(out, app.Opt{Value: s.Code, Label: c.T(s.Label)})
	}
	return out
}

func stageLabel(c *app.Ctx, s string) string {
	if d, ok := stageDefOf(s); ok {
		return c.T(d.Label)
	}
	return s
}

func inStrs(v string, list []string) bool {
	for _, x := range list {
		if x == v {
			return true
		}
	}
	return false
}

func sqlList(list []string) string { return "('" + strings.Join(list, "','") + "')" }

// ---------------------------------------------------------------- Stages: colour and which departments use a stage

var defaultStageColors = map[string]string{
	"QUALIFICATION": "#0ea5e9", "SURVEY_PROPOSAL": "#8b5cf6", "EVALUATION_TESTING": "#f59e0b", "NEGOTIATION": "#ea580c",
	"CLOSED_WON": "#16a34a", "CLOSED_LOST": "#dc2626", "ON_HOLD": "#64748b", "CANCEL": "#94a3b8",
}

var hex6 = regexp.MustCompile(`(?i)^#[0-9a-f]{6}$`)

// stageColors is Stages::colors(): [code => '#rrggbb'], read once per request (the PHP static cache).
func stageColors(c *app.Ctx) map[string]string {
	if c != nil {
		if m, ok := c.Shared["_crm_stage_colors"].(map[string]string); ok {
			return m
		}
	}
	out := map[string]string{}
	for k, v := range defaultStageColors {
		out[k] = v
	}
	_ = db.Try(func() {
		for _, r := range db.On("crm").Select("SELECT code, color FROM crm_stages") {
			if hex6.MatchString(r.Str("color")) {
				out[r.Str("code")] = strings.ToLower(r.Str("color"))
			}
		}
	})
	if c != nil && c.Shared != nil {
		c.Shared["_crm_stage_colors"] = out
	}
	return out
}

func forgetStageCache(c *app.Ctx) { delete(c.Shared, "_crm_stage_colors") }

func stageColor(c *app.Ctx, code string) string {
	if col, ok := stageColors(c)[code]; ok {
		return col
	}
	return "#94a3b8"
}

// stageMobileNames: [code => short name for the mobile app] (only stages that have one).
func stageMobileNames() map[string]string {
	out := map[string]string{}
	_ = db.Try(func() {
		for _, r := range db.On("crm").Select("SELECT code, mobile_name FROM crm_stages WHERE coalesce(mobile_name, '') <> ''") {
			out[r.Str("code")] = r.Str("mobile_name")
		}
	})
	return out
}

// stagesForMobile: every stage for the mobile app: code, name, short name, colour, open or closed.
func stagesForMobile(c *app.Ctx) []app.M {
	short := stageMobileNames()
	var out []app.M
	for _, s := range stagesDef {
		sh, ok := short[s.Code]
		if !ok {
			sh = stageLabel(c, s.Code)
		}
		out = append(out, app.M{"code": s.Code, "label": stageLabel(c, s.Code), "short": sh, "color": stageColor(c, s.Code), "open": inStrs(s.Code, stageOpen)})
	}
	return out
}

// stageHiddenFor: stage codes a department does NOT use.
func stageHiddenFor(departmentID int) []string {
	if departmentID == 0 {
		return nil
	}
	var out []string
	for _, v := range db.On("crm").Column("SELECT stage FROM stage_hidden WHERE department_id = ?", departmentID) {
		out = append(out, php.Str(v))
	}
	return out
}

// stagesAvailableFor: stage codes the person may pick / see in the pipeline, in stage order. Whole-company roles see every stage.
func stagesAvailableFor(u *app.CurrentUser) []string {
	all := stageCodes()
	if seesAll(u) {
		return all
	}
	hidden := stageHiddenFor(u.DepartmentID)
	var out []string
	for _, s := range all {
		if !inStrs(s, hidden) {
			out = append(out, s)
		}
	}
	return out
}

func stageAllowed(u *app.CurrentUser, code string) bool { return inStrs(code, stagesAvailableFor(u)) }

// stageOptionsFor is Stages::options(): a record's current stage stays in the list even if its department no longer uses it.
func stageOptionsFor(c *app.Ctx, u *app.CurrentUser, current string) []app.Opt {
	var out []app.Opt
	seen := map[string]bool{}
	for _, code := range stagesAvailableFor(u) {
		out = append(out, app.Opt{Value: code, Label: stageLabel(c, code)})
		seen[code] = true
	}
	if current != "" && !seen[current] && isStage(current) {
		out = append(out, app.Opt{Value: current, Label: stageLabel(c, current)})
	}
	return out
}

// ---------------------------------------------------------------- small helpers

// optsFrom: options from rows (value column, label column).
func optsFrom(rows []db.Row, v, l string) []app.Opt {
	out := make([]app.Opt, 0, len(rows))
	for _, r := range rows {
		out = append(out, app.Opt{Value: r.Str(v), Label: r.Str(l)})
	}
	return out
}

func optHas(opts []app.Opt, v string) bool { _, ok := app.OptLabel(opts, v); return ok }

// fmtFloat prints a float like PHP echo does (no trailing .0).
func fmtFloat(f float64) string {
	if f == math.Trunc(f) && math.Abs(f) < 1e15 {
		return strconv.FormatInt(int64(f), 10)
	}
	return strconv.FormatFloat(f, 'f', -1, 64)
}

// phpRound is round($n, $dec) (half away from zero).
func phpRound(n float64, dec int) float64 {
	p := math.Pow(10, float64(dec))
	v := n * p
	if v < 0 {
		return -math.Floor(-v+0.5) / p
	}
	return math.Floor(v+0.5) / p
}

var digitsOnly = regexp.MustCompile(`^\d+$`)

func ctypeDigit(s string) bool { return digitsOnly.MatchString(s) }

var nonDigit = regexp.MustCompile(`\D`)
