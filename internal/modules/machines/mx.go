package machines

import (
	"html/template"
	"net/url"
	"regexp"
	"sort"
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

// Shared facts of the Machine Checklist module (Support\Mx): statuses, who sees which machine, people and department look-ups, codes.

const conn = "machines"

func mdb() db.Q { return db.On(conn) }

// pair is one entry of a PHP constant array (key => label), kept in order.
type pair struct{ k, v string }

// Machine statuses. The first two are "in use": they are checked, maintained and calibrated.
var statuses = []pair{{"OPERATIONAL", "Operational"}, {"UNDER MAINTENANCE", "Under maintenance"}, {"SCRAPPED", "Scrapped"}, {"CANCELLED", "Cancelled"}}
var active = []string{"OPERATIONAL", "UNDER MAINTENANCE"}
var periods = []pair{{"WEEKLY", "Weekly"}, {"MONTHLY", "Monthly"}}
var maintBy = []pair{{"INTERNAL", "Internal"}, {"EXTERNAL", "External"}}
var maintTypes = []pair{{"PREVENTIVE", "Preventive"}, {"CORRECTIVE", "Corrective"}, {"PREDICTIVE", "Predictive"}, {"CONDITION_BASED", "Condition based"}, {"PRESCRIPTIVE", "Prescriptive"}}

// The day rule of a checklist item (cron "0 0 0 <day-of-month> * <day-of-week>"): label per preset. '0 0 0 * * 1' = general check, done at every use.
const general = "0 0 0 * * 1"

var resets = []pair{
	{"0 0 0 * * 1", "General (at every use)"}, {"0 0 0 * * *", "Every day"}, {"0 0 0 * * 2", "Every Tuesday"}, {"0 0 0 * * 3", "Every Wednesday"}, {"0 0 0 * * 4", "Every Thursday"},
	{"0 0 0 * * 5", "Every Friday"}, {"0 0 0 * * 6", "Every Saturday"}, {"0 0 0 * * 7", "Every Sunday"}, {"0 0 0 1 * *", "Monthly (1st)"}, {"0 0 0 15 * *", "Monthly (15th)"},
}

// Readable (translatable) names of the status codes stored in the database.
var labels = map[string]string{"OPERATIONAL": "Operational", "UNDER MAINTENANCE": "Under maintenance", "SCRAPPED": "Scrapped", "CANCELLED": "Cancelled", "OUT OF SERVICE": "Out of service",
	"PENDING": "Pending", "PENDING SUPERVISOR": "Pending supervisor", "PENDING MANAGER": "Pending manager", "COMPLETED": "Completed", "COMPLETED (LATE)": "Completed (late)",
	"PENDING SUPERVISOR-OVERDUE": "Supervisor overdue", "PENDING MANAGER-OVERDUE": "Manager overdue", "ACTIVE": "Active", "INACTIVE": "Inactive", "PASS": "Pass", "FAILED": "Failed"}

func lookup(list []pair, k string) (string, bool) {
	for _, p := range list {
		if p.k == k {
			return p.v, true
		}
	}
	return "", false
}

// trOpts is array_map(fn ($l) => __($l), CONST).
func trOpts(c *app.Ctx, list []pair) []app.Opt {
	out := make([]app.Opt, 0, len(list))
	for _, p := range list {
		out = append(out, app.Opt{Value: p.k, Label: c.T(p.v)})
	}
	return out
}

// label of a constant (translated), or the stored value itself
func trLabel(c *app.Ctx, list []pair, k any) string {
	if l, ok := lookup(list, php.Str(k)); ok {
		return c.T(l)
	}
	return php.Str(k)
}

func statusTone(s string) string {
	switch s {
	case "OPERATIONAL", "COMPLETED", "COMPLETED (LATE)", "PASS", "Pass":
		return "ok"
	case "UNDER MAINTENANCE", "PENDING", "PENDING SUPERVISOR", "PENDING MANAGER":
		return "warn"
	case "SCRAPPED", "CANCELLED", "OUT OF SERVICE", "REJECTED", "FAILED":
		return "bad"
	}
	if strings.HasSuffix(s, "-OVERDUE") {
		return "bad"
	}
	return "neutral"
}

var pillClasses = map[string]string{"ok": "bg-emerald-50 text-emerald-800 ring-emerald-600/20", "warn": "bg-amber-50 text-amber-800 ring-amber-600/20", "bad": "bg-red-50 text-red-800 ring-red-600/20",
	"neutral": "bg-graphite-900/5 text-graphite-700 ring-graphite-900/10", "info": "bg-sky-50 text-sky-800 ring-sky-600/20"}

// pill is a small coloured pill (Mx::pill); tone "" picks it from the status.
func pill(c *app.Ctx, text any, tone string) string {
	s := php.Str(text)
	if text == nil || s == "" {
		return `<span class="text-steel">—</span>`
	}
	if tone == "" {
		tone = statusTone(s)
	}
	show := s
	if l, ok := labels[s]; ok {
		show = c.T(l)
	}
	return `<span class="inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ` + pillClasses[tone] + `">` + app.E(show) + `</span>`
}

func pillH(c *app.Ctx, text any, tone string) template.HTML { return template.HTML(pill(c, text, tone)) }

func isActive(status any) bool { return php.InList(php.Str(status), active...) && status != nil }

func activeIn() string { return "'" + strings.Join(active, "','") + "'" }

/* ------------------------------------------------------------------- who sees what */

// scope: machines a person may see in the lists: a whole-company role sees all, a department head sees the department's,
// everybody else sees the machines they are responsible for, supervise or manage.
// (Scanning a machine's QR code is open to every signed-in person: anyone who uses a machine can do its general check.)
func scope(u *app.CurrentUser, m string) (string, []any) {
	sc := u.Scope()
	if sc == "all" {
		return "TRUE", nil
	}
	mine := m + ".responsible_person_id = ? OR " + m + ".supervisor_id = ? OR " + m + ".manager_id = ?"
	if sc == "department" && u.DepartmentID != 0 {
		return "(" + m + ".department_id = ? OR " + mine + ")", []any{u.DepartmentID, u.ID, u.ID, u.ID}
	}
	return "(" + mine + ")", []any{u.ID, u.ID, u.ID}
}

// canSee: may this person see the machine with this code in the lists?
func canSee(u *app.CurrentUser, code string) bool {
	s, p := scope(u, "m")
	return php.Bool(mdb().Scalar("SELECT 1 FROM machine m WHERE m.machine_code = ? AND m.deleted_at IS NULL AND "+s, append([]any{code}, p...)...))
}

// manages: admin, whole-company roles and department heads can change any record they see; others only where they are responsible.
func manages(u *app.CurrentUser, m db.Row) bool {
	if u.Scope() == "all" {
		return true
	}
	if u.Scope() == "department" && u.DepartmentID != 0 && m.Int("department_id") == u.DepartmentID {
		return true
	}
	for _, k := range []string{"responsible_person_id", "supervisor_id", "manager_id"} {
		if v := m.Int(k); v != 0 && v == u.ID {
			return true
		}
	}
	return false
}

/* ----------------------------------------------------------------------- people */

// names is [id => name] for the ids.
func names(ids ...any) map[int]string {
	seen := map[int]bool{}
	var list []any
	for _, v := range ids {
		if n := php.Int(v); n != 0 && !seen[n] {
			seen[n] = true
			list = append(list, n)
		}
	}
	out := map[int]string{}
	if len(list) == 0 {
		return out
	}
	marks := strings.TrimSuffix(strings.Repeat("?,", len(list)), ",")
	for _, r := range db.Select("SELECT id, name FROM users WHERE id IN ("+marks+")", list...) {
		out[r.Int("id")] = r.Str("name")
	}
	return out
}

// nameOr is $names[$id] ?? $fallback.
func nameOr(n map[int]string, id any, fallback any) any {
	if s, ok := n[php.Int(id)]; ok {
		return s
	}
	return fallback
}

// people: active people for the pickers: [id => "Name · Department"].
func people() []app.Opt {
	var out []app.Opt
	for _, r := range db.Select("SELECT u.id, u.name, d.name AS department FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.deleted_at IS NULL AND u.is_active ORDER BY u.name") {
		l := r.Str("name")
		if r.Str("department") != "" {
			l += " · " + r.Str("department")
		}
		out = append(out, app.Opt{Value: r.Str("id"), Label: l})
	}
	return out
}

func departments() []app.Opt {
	var out []app.Opt
	for _, r := range db.Select("SELECT id, name FROM departments WHERE deleted_at IS NULL AND is_active ORDER BY name") {
		out = append(out, app.Opt{Value: r.Str("id"), Label: r.Str("name")})
	}
	return out
}

func optMap(opts []app.Opt) map[string]string {
	m := make(map[string]string, len(opts))
	for _, o := range opts {
		m[o.Value] = o.Label
	}
	return m
}

func departmentCodes() map[int]string {
	out := map[int]string{}
	for _, r := range db.Select("SELECT id, code FROM departments WHERE deleted_at IS NULL") {
		out[r.Int("id")] = r.Str("code")
	}
	return out
}

// team: people to tell about a machine: [user id, …] without duplicates.
func team(m db.Row, managers bool) []int {
	keys := []string{"responsible_person_id", "supervisor_id"}
	if managers {
		keys = append(keys, "manager_id")
	}
	var out []int
	seen := map[int]bool{}
	for _, k := range keys {
		if n := m.Int(k); n != 0 && !seen[n] {
			seen[n] = true
			out = append(out, n)
		}
	}
	return out
}

/* ----------------------------------------------------------------------- machines */

func machine(code string) db.Row {
	return mdb().First(`SELECT m.*, t.machine_group_name, t.machine_type_name FROM machine m
                          LEFT JOIN machine_type t ON t.machine_group_id = m.machine_group_id AND t.machine_type_id = m.machine_type_id AND t.deleted_at IS NULL
                          WHERE m.machine_code = ? AND m.deleted_at IS NULL`, code)
}

// qr is the QR content. Same JSON the old system printed, so existing labels keep working.
func qr(code string) string { return phpJSON(om("status", true, "code", code), flagSlashes) }

var codeLink = regexp.MustCompile(`/machines/m/([^/?#]+)`)
var bareCode = regexp.MustCompile(`^[A-Za-z0-9._\-]{3,40}$`)

// codeFromScan: whatever a scanner read (JSON from our labels, or just the code) → machine code ("" = none).
func codeFromScan(text string) string {
	text = strings.TrimSpace(text)
	if text == "" {
		return ""
	}
	if text[0] == '{' {
		j := jsonObject(text)
		if j == nil || php.Empty(j["code"]) {
			return ""
		}
		return php.Str(j["code"])
	}
	if m := codeLink.FindStringSubmatch(text); m != nil {
		if s, err := url.PathUnescape(m[1]); err == nil {
			return s
		}
		return m[1]
	}
	if bareCode.MatchString(text) {
		return text
	}
	return ""
}

var nonAlnum = regexp.MustCompile(`[^A-Za-z0-9]`)

// nextCode: "<dept code>-<group><type>-<4 digit running number>"
func nextCode(departmentID int, group, typ string) string {
	dept := ""
	if departmentID != 0 {
		dept = departmentCodes()[departmentID]
	}
	p := nonAlnum.ReplaceAllString(dept, "")
	if p == "" {
		p = "MC"
	}
	prefix := strings.ToUpper(p) + "-" + group + typ
	max := php.Int(mdb().Scalar(`SELECT COALESCE(MAX(NULLIF(regexp_replace(substring(machine_code from '[0-9]+$'), '^0+', ''), '')::int), 0) FROM machine WHERE machine_code LIKE ?`, prefix+"-%"))
	n := php.Str(max + 1)
	for len(n) < 4 {
		n = "0" + n
	}
	return prefix + "-" + n
}

var spaces = regexp.MustCompile(`\s+`)

// resetsOn: does a cron-style day rule fall on this day? ("0 0 0 <day-of-month> * <day-of-week>", day-of-week 1 = Monday … 7 = Sunday)
func resetsOn(rule string, day time.Time) bool {
	p := spaces.Split(strings.TrimSpace(rule), -1)
	if len(p) < 6 {
		return false
	}
	dom, dow := p[3], p[5]
	dowNow := int(day.Weekday())
	if dowNow == 0 {
		dowNow = 7
	}
	domOk := dom == "*" || php.Int(dom) == day.Day()
	dowOk := dow == "*" || php.Int(dow) == dowNow || (dow == "0" && dowNow == 7)
	return domOk && dowOk
}

func resetLabel(c *app.Ctx, rule any) string {
	if l, ok := lookup(resets, php.Str(rule)); ok {
		return c.T(l)
	}
	return c.T(php.Str(rule))
}

// sortedKeys are the keys of a posted list in their numeric order (old input comes back as a map).
func sortedKeys(m map[string]any) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.SliceStable(keys, func(i, j int) bool {
		a, b := keys[i], keys[j]
		if php.Int(a) != php.Int(b) {
			return php.Int(a) < php.Int(b)
		}
		return a < b
	})
	return keys
}
