package core

import (
	"math"
	"sort"
	"time"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/oil"
	"acme/internal/php"
)

// Dashboards (DashboardController): /dashboard picks the person's dashboard, My workspace and the control room.

func init() {
	signedIn(func(r app.Router) {
		r.Get("/", dashboardIndex)
		r.Get("/dashboard", dashboardIndex)
		r.Get("/dashboard/personal", dashboardPersonal)
		r.Get("/dashboard/admin", dashboardAdmin)
	})
}

// /dashboard opens the person's chosen dashboard (Preferences), else the sensible default.
func dashboardIndex(c *app.Ctx) {
	u := c.MustUser()
	var boards []app.Opt
	for _, b := range DashboardsFor(c, u) {
		if b.Module == "core" {
			boards = b.Boards
		}
	}
	pick := ""
	if u.DefaultModule == "core" || u.DefaultModule == "" {
		pick = u.DefaultDashboard
	}
	key := "personal" // User Management opens My workspace (the control room is in Administrator)
	if pick != "" {
		if _, ok := app.OptLabel(boards, pick); ok {
			key = pick
		}
	}
	to := ""
	for _, d := range app.ModuleByKey("core").Dashboards {
		if d.Key == key {
			to = d.URL
		}
	}
	c.Redirect(to)
}

// ModuleBoards are the dashboards of one module a person may pick (key → label).
type ModuleBoards struct {
	Module string
	Boards []app.Opt
}

// DashboardsFor (ProfileController::dashboardsFor): dashboards per enabled module that this person may open.
func DashboardsFor(c *app.Ctx, u *app.CurrentUser) []ModuleBoards {
	var out []ModuleBoards
	for _, m := range app.Modules() {
		if !m.Enabled {
			continue
		}
		var boards []app.Opt
		for _, d := range m.Dashboards {
			if app.AdminOnlyDashboards[m.Key+"."+d.Key] && !u.IsAdmin() {
				continue
			}
			boards = append(boards, app.Opt{Value: d.Key, Label: c.T(d.Label)})
		}
		if len(boards) > 0 {
			out = append(out, ModuleBoards{Module: m.Key, Boards: boards})
		}
	}
	return out
}

// ---------------------------------------------------------------- control room

// wsPeriod: the period the control room shows: from, to (inclusive), preset key. Default: this month.
func wsPeriod(c *app.Ctx) (time.Time, time.Time, string) {
	n := php.Now()
	today := time.Date(n.Year(), n.Month(), n.Day(), 0, 0, 0, 0, php.Loc)
	first := time.Date(today.Year(), today.Month(), 1, 0, 0, 0, 0, php.Loc)
	key := c.Query("range", "month")
	from, to := first, today
	switch key {
	case "today":
		from = today
	case "7d":
		from = today.AddDate(0, 0, -6)
	case "30d":
		from = today.AddDate(0, 0, -29)
	case "90d":
		from = today.AddDate(0, 0, -89)
	case "last_month":
		from, to = first.AddDate(0, -1, 0), first.AddDate(0, 0, -1)
	case "year":
		from = time.Date(today.Year(), 1, 1, 0, 0, 0, 0, php.Loc)
	case "custom":
		f, e1 := time.ParseInLocation("2006-01-02", c.Query("from"), php.Loc)
		t, e2 := time.ParseInLocation("2006-01-02", c.Query("to"), php.Loc)
		if e1 == nil && e2 == nil {
			from, to = f, t
			if t.Before(f) {
				from, to = t, f
			}
		} else {
			key = "month"
		}
	default:
		key = "month"
		to = first.AddDate(0, 1, -1) // the whole month: days still to come show as empty
	}
	if from.After(to) {
		from = to
	}
	if wsDays(from, to) > 730 {
		from = to.AddDate(0, 0, -730)
	}
	return from, to, key
}

// wsDays is the number of calendar days between two midnights.
func wsDays(a, b time.Time) int {
	ua := time.Date(a.Year(), a.Month(), a.Day(), 0, 0, 0, 0, time.UTC)
	ub := time.Date(b.Year(), b.Month(), b.Day(), 0, 0, 0, 0, time.UTC)
	d := int(ub.Sub(ua).Hours() / 24)
	if d < 0 {
		d = -d
	}
	return d
}

type wsDept struct {
	ID         int
	Name, Code string
	Color      string
}

type wsTile struct {
	Label, Txt, Tone, Sub, Href string
	Value                       int
}

type wsSeries struct {
	ID    int    `json:"id"`
	Name  string `json:"name"`
	Color string `json:"color"`
	Total int    `json:"total"`
	Data  []int  `json:"data"`
}

// Company-wide control room for administrators.
func dashboardAdmin(c *app.Ctx) {
	if !c.MustUser().IsAdmin() {
		app.Abort(403, c.T("Only administrators can open the control room."))
	}
	from, to, rng := wsPeriod(c)
	tzName := config.String("app.timezone", "Asia/Bangkok")
	days := wsDays(from, to) + 1
	dept := php.Int(c.Query("department", "0"))
	unit := "month"
	if days <= 62 {
		unit = "day"
	} else if days <= 250 {
		unit = "week"
	}

	depts := map[int]wsDept{}
	var deptOpts []app.Opt
	deptOpts = append(deptOpts, app.Opt{Value: "0", Label: c.T("All departments")})
	for _, d := range db.Select("SELECT id, name, code, color FROM departments WHERE deleted_at IS NULL ORDER BY name") {
		depts[d.Int("id")] = wsDept{ID: d.Int("id"), Name: d.Str("name"), Code: d.Str("code"), Color: app.DeptColor(d.Int("id"), d.Str("color"))}
		deptOpts = append(deptOpts, app.Opt{Value: d.Str("id"), Label: d.Str("name")})
	}
	depts[0] = wsDept{ID: 0, Name: c.T("No department"), Code: "—", Color: app.DeptNone}
	if _, ok := depts[dept]; dept != 0 && !ok {
		dept = 0
	}
	onlyDept := ""
	var dp []any
	if dept != 0 {
		onlyDept = "AND u.department_id = ?"
		dp = []any{dept}
	}

	// window [from 00:00, to+1 day 00:00) in the company time zone, plus the period just before it for the arrows
	lo, hi := from.Format("2006-01-02"), to.AddDate(0, 0, 1).Format("2006-01-02")
	plo := from.AddDate(0, 0, -days).Format("2006-01-02")
	win := func(a, b string) (string, []any) {
		return "(l.created_at AT TIME ZONE '" + tzName + "') >= ?::date AND (l.created_at AT TIME ZONE '" + tzName + "') < ?::date", []any{a, b}
	}
	wSQL, wPar := win(lo, hi)
	pSQL, pPar := win(plo, lo)
	join := func(a []any, b ...any) []any { return append(append([]any{}, a...), b...) }

	figures := func(sql string, par []any) db.Row {
		extra := ""
		if dept != 0 {
			extra = "AND (u.department_id = ?)"
		}
		return db.First(`SELECT COUNT(*) FILTER (WHERE l.action = 'login') AS logins,
                                     COUNT(DISTINCT l.user_id) FILTER (WHERE l.action = 'login') AS signers,
                                     COUNT(*) FILTER (WHERE l.action NOT IN ('login', 'login_failed')) AS changes,
                                     COUNT(*) FILTER (WHERE l.action = 'login_failed') AS failed
                                FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
                               WHERE `+sql+" "+extra, join(par, dp...)...)
	}
	now := figures(wSQL, wPar)
	prev := figures(pSQL, pPar)
	members := php.Int(db.Scalar("SELECT COUNT(*) FROM users u WHERE u.deleted_at IS NULL AND u.is_active "+onlyDept, dp...))

	// sign-ins per bucket and department (stacked chart)
	rows := db.Select(`SELECT to_char(date_trunc('`+unit+`', l.created_at AT TIME ZONE '`+tzName+`'), 'YYYY-MM-DD') AS b, COALESCE(u.department_id, 0) AS d, COUNT(*) AS n
                              FROM activity_logs l JOIN users u ON u.id = l.user_id
                             WHERE l.action = 'login' AND `+wSQL+" "+onlyDept+" GROUP BY 1, 2", join(wPar, dp...)...)
	var buckets []string
	cur := from
	switch unit {
	case "week":
		wd := (int(cur.Weekday()) + 6) % 7 // Monday = 0
		cur = cur.AddDate(0, 0, -wd)
	case "month":
		cur = time.Date(cur.Year(), cur.Month(), 1, 0, 0, 0, 0, php.Loc)
	}
	for !cur.After(to) {
		buckets = append(buckets, cur.Format("2006-01-02"))
		switch unit {
		case "day":
			cur = cur.AddDate(0, 0, 1)
		case "week":
			cur = cur.AddDate(0, 0, 7)
		default:
			cur = cur.AddDate(0, 1, 0)
		}
	}
	grid := map[int]map[string]int{}
	totals := map[int]int{}
	var order []int
	for _, r := range rows {
		d := r.Int("d")
		if grid[d] == nil {
			grid[d] = map[string]int{}
		}
		grid[d][r.Str("b")] = r.Int("n")
		if _, ok := totals[d]; !ok {
			order = append(order, d)
		}
		totals[d] += r.Int("n")
	}
	sort.SliceStable(order, func(i, j int) bool { return totals[order[i]] > totals[order[j]] })
	series := []wsSeries{}
	for _, id := range order {
		data := make([]int, len(buckets))
		for i, b := range buckets {
			data[i] = grid[id][b]
		}
		series = append(series, wsSeries{ID: id, Name: depts[id].Name, Color: depts[id].Color, Total: totals[id], Data: data})
	}

	// busiest hours
	hours := make([]int, 24)
	for _, h := range db.Select(`SELECT EXTRACT(HOUR FROM l.created_at AT TIME ZONE '`+tzName+`')::int AS h, COUNT(*) AS n FROM activity_logs l JOIN users u ON u.id = l.user_id
                              WHERE l.action = 'login' AND `+wSQL+" "+onlyDept+" GROUP BY 1", join(wPar, dp...)...) {
		if i := h.Int("h"); i >= 0 && i < 24 {
			hours[i] = h.Int("n")
		}
	}

	// most active people (all actions except failed sign-ins)
	people := db.Select(`SELECT u.id, u.name, u.avatar, COALESCE(u.department_id, 0) AS d, COUNT(*) AS total,
                                     COUNT(*) FILTER (WHERE l.action = 'login') AS logins, COUNT(*) FILTER (WHERE l.action <> 'login') AS changes, MAX(l.created_at) AS last_at
                                FROM activity_logs l JOIN users u ON u.id = l.user_id
                               WHERE l.action <> 'login_failed' AND `+wSQL+" "+onlyDept+" GROUP BY u.id ORDER BY total DESC, u.name LIMIT 8", join(wPar, dp...)...)

	// each department: ranking + its most frequent sign-ins
	rank := db.Select(`SELECT COALESCE(u.department_id, 0) AS d, COUNT(*) FILTER (WHERE l.action = 'login') AS logins, COUNT(*) FILTER (WHERE l.action NOT IN ('login', 'login_failed')) AS changes,
                                   COUNT(*) FILTER (WHERE l.action <> 'login_failed') AS total, COUNT(DISTINCT l.user_id) FILTER (WHERE l.action <> 'login_failed') AS people
                              FROM activity_logs l JOIN users u ON u.id = l.user_id WHERE `+wSQL+" "+onlyDept+" GROUP BY 1 ORDER BY total DESC", join(wPar, dp...)...)
	heads := map[int]int{}
	for _, h := range db.Select("SELECT COALESCE(department_id, 0) AS d, COUNT(*) AS n FROM users WHERE deleted_at IS NULL AND is_active GROUP BY 1") {
		heads[h.Int("d")] = h.Int("n")
	}
	top := db.Select(`SELECT * FROM (SELECT COALESCE(u.department_id, 0) AS d, u.id, u.name, u.avatar, COUNT(*) AS logins, MAX(l.created_at) AS last_at,
                                  ROW_NUMBER() OVER (PARTITION BY COALESCE(u.department_id, 0) ORDER BY COUNT(*) DESC, u.name) AS rk
                             FROM activity_logs l JOIN users u ON u.id = l.user_id WHERE l.action = 'login' AND `+wSQL+" "+onlyDept+" GROUP BY u.id) x WHERE rk <= 3 ORDER BY d, rk", join(wPar, dp...)...)
	topBy := map[int][]db.Row{}
	for _, t := range top {
		topBy[t.Int("d")] = append(topBy[t.Int("d")], t)
	}

	recentDept := ""
	if dept != 0 {
		recentDept = "AND u.department_id = ?"
	}
	recent := db.Select("SELECT l.*, u.name AS user_name, u.avatar FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE "+wSQL+" "+recentDept+" ORDER BY l.created_at DESC LIMIT 8", join(wPar, dp...)...)

	// ---- what the view printed inline
	name := func(d int) string {
		if x, ok := depts[d]; ok {
			return x.Name
		}
		return "—"
	}
	color := func(d int, def string) string {
		if x, ok := depts[d]; ok {
			return x.Color
		}
		return def
	}
	keep := map[string]any{}
	if dept != 0 {
		keep["department"] = dept
	}
	var presets []app.M
	for _, p := range app.PeriodPresets {
		q := map[string]any{"range": p.Value}
		for k, v := range keep {
			q[k] = v
		}
		presets = append(presets, app.M{"key": p.Value, "label": c.T(p.Label), "url": c.URL("/dashboard/admin", q)})
	}
	delta := func(a, b int) (string, string) {
		if b == 0 {
			if a > 0 {
				return "+new", "up"
			}
			return "—", "flat"
		}
		p := math.Round(float64(a-b) / float64(b) * 100)
		sign := ""
		if p > 0 {
			sign = "+"
		}
		dir := "flat"
		if p > 0 {
			dir = "up"
		} else if p < 0 {
			dir = "down"
		}
		return sign + php.Str(p) + "%", dir
	}
	tones := map[string]string{"up": "bg-emerald-50 text-emerald-700", "down": "bg-amber-50 text-amber-700", "flat": "bg-graphite-900/6 text-steel"}
	share := 0.0
	if members > 0 {
		share = math.Round(float64(now.Int("signers")) / float64(members) * 100)
	}
	type tileDef struct {
		label     string
		v, p      int
		sub, href string
		inverse   bool
	}
	defs := []tileDef{
		{c.T("Sign-ins"), now.Int("logins"), prev.Int("logins"), c.T(":n different people", "n", now.Int("signers")), c.URL("/activity", "action", "login"), false},
		{c.T("Active people"), now.Int("signers"), prev.Int("signers"), c.T("of :n members", "n", members) + " · " + php.Str(share) + "%", "", false},
		{c.T("Changes made"), now.Int("changes"), prev.Int("changes"), c.T("created, edited, deleted, imported …"), c.URL("/activity"), false},
		{c.T("Failed sign-ins"), now.Int("failed"), prev.Int("failed"), c.T("wrong password or locked"), c.URL("/activity", "action", "login_failed"), true},
	}
	var tiles []wsTile
	for _, t := range defs {
		txt, dir := delta(t.v, t.p)
		if t.inverse && dir != "flat" {
			if dir == "up" {
				dir = "down"
			} else {
				dir = "up"
			}
		}
		tiles = append(tiles, wsTile{Label: t.label, Txt: txt, Tone: tones[dir], Sub: t.sub, Href: t.href, Value: t.v})
	}

	maxRank := 1
	for _, r := range rank {
		maxRank = max(maxRank, r.Int("total"))
	}
	maxPeople := 1
	for _, r := range people {
		maxPeople = max(maxPeople, r.Int("total"))
	}
	var best app.M
	var rankView []app.M
	var cards []app.M
	anyTop := false
	for i, r := range rank {
		d := r.Int("d")
		item := app.M{
			"pos": i + 1, "name": name(d), "color": color(d, "#9ca3af"), "total": r.Int("total"),
			"width": php.Str(math.Max(2, float64(r.Int("total"))/float64(maxRank)*100)),
			"line":  c.T(":l sign-ins · :c changes · :p of :h people active", "l", r.Int("logins"), "c", r.Int("changes"), "p", r.Int("people"), "h", heads[d]),
		}
		rankView = append(rankView, item)
		if i == 0 {
			best = app.M{"name": name(d), "color": color(d, "#475569"), "line": c.T(":a actions · :b people", "a", php.NF(r.Int("total"), 0), "b", r.Int("people"))}
		}
		if list := topBy[d]; len(list) > 0 {
			anyTop = true
			cards = append(cards, app.M{"name": name(d), "color": color(d, "#9ca3af"), "logins": c.T(":n sign-ins", "n", r.Int("logins")), "list": list})
		}
	}
	if len(topBy) > 0 {
		anyTop = true
	}
	var peopleView []app.M
	for i, p := range people {
		d := p.Int("d")
		peopleView = append(peopleView, app.M{
			"pos": i + 1, "row": p, "color": color(d, "#9ca3af"), "dept": name(d),
			"lw": php.Str(float64(p.Int("logins")) / float64(maxPeople) * 100), "cw": php.Str(float64(p.Int("changes")) / float64(maxPeople) * 100),
		})
	}

	sub := app.FormatDate(c, lo, "d M Y")
	if lo != to.Format("2006-01-02") {
		sub += " – " + app.FormatDate(c, to.Format("2006-01-02"), "d M Y")
	}
	sub += " · " + c.T(":n days", "n", days) + " · "
	if dept != 0 {
		sub += name(dept)
	} else {
		sub += c.T("All departments")
	}
	unitLabel := map[string]string{"day": c.T("per day"), "week": c.T("per week"), "month": c.T("per month")}[unit]

	c.View("dashboard/admin", app.M{
		"title": c.T("Control room"), "from": from, "to": to, "range": rng, "days": days, "unit": unit, "dept": php.Str(dept),
		"deptOpts": deptOpts, "presets": presets, "sub": sub, "tiles": tiles, "unitLabel": unitLabel,
		"loginsTotal": c.T(":count total", "count", php.NF(now.Int("logins"), 0)), "compared": c.T("compared with the :n days before", "n", days),
		"series": series, "best": best, "rank": rankView, "people": peopleView, "cards": cards, "anyTop": anyTop, "recent": recent,
		"chart": app.M{"unit": unit, "buckets": buckets, "series": series, "hours": hours,
			"label": app.M{"signins": c.T("sign-ins"), "hour": c.T("Hour")}},
	})
}

// ---------------------------------------------------------------- My workspace

// wsTaskStatuses is TaskController::STATUSES.
var wsTaskStatuses = map[string]string{"todo": "To do", "in_progress": "In progress", "review": "In review", "done": "Done"}

// Personal workspace; figures widen with the user's data scope.
func dashboardPersonal(c *app.Ctx) {
	u := c.MustUser()
	scope := u.Scope()

	myTasks := db.Select(`SELECT * FROM tasks WHERE assigned_to = ? AND deleted_at IS NULL AND status <> 'done'
                                ORDER BY CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END, due_date NULLS LAST LIMIT 8`, u.ID)
	myKpis := db.Select("SELECT * FROM kpis WHERE user_id = ? AND deleted_at IS NULL AND status = 'active' ORDER BY period_end LIMIT 6", u.ID)

	type figure struct {
		Label string
		Value int
		Alert bool
	}
	var figures []figure
	userCols := app.ScopeCols{Owner: []string{"id"}, Department: "department_id", Team: "team_id"}
	if c.Can("members", "view") {
		sql, par := app.ScopeSQL(u, "u", userCols)
		figures = append(figures, figure{Label: c.T("People you can see"), Value: php.Int(db.Scalar("SELECT COUNT(*) FROM users u WHERE u.deleted_at IS NULL AND u.is_active AND "+sql, par...))})
	}
	if c.Can("tasks", "view") {
		sql, par := app.ScopeSQL(u, "t", app.ScopeCols{Owner: []string{"assigned_to", "created_by"}, Department: "department_id", Team: "team_id"})
		row := db.First(`SELECT
                    COUNT(*) FILTER (WHERE status <> 'done') AS open,
                    COUNT(*) FILTER (WHERE status <> 'done' AND due_date < CURRENT_DATE) AS overdue,
                    COUNT(*) FILTER (WHERE status = 'done' AND completed_at >= date_trunc('month', now())) AS done_month
                  FROM tasks t WHERE t.deleted_at IS NULL AND `+sql, par...)
		figures = append(figures, figure{Label: c.T("Open tasks"), Value: row.Int("open")},
			figure{Label: c.T("Overdue"), Value: row.Int("overdue"), Alert: row.Int("overdue") > 0},
			figure{Label: c.T("Done this month"), Value: row.Int("done_month")})
	}
	lg := map[int]string{1: "lg:grid-cols-1", 2: "lg:grid-cols-2", 3: "lg:grid-cols-3", 4: "lg:grid-cols-4"}[len(figures)]
	if lg == "" {
		lg = "lg:grid-cols-4"
	}

	var teammates []db.Row
	if scope != "own" && c.Can("members", "view") {
		sql, par := app.ScopeSQL(u, "u", userCols)
		teammates = db.Select(`SELECT u.id, u.name, u.avatar, p.job_title FROM users u LEFT JOIN employee_profiles p ON p.user_id = u.id
                                      WHERE u.deleted_at IS NULL AND u.is_active AND u.id <> ? AND `+sql+" ORDER BY u.name LIMIT 8", append([]any{u.ID}, par...)...)
	}

	var activity []db.Row
	if scope == "all" {
		activity = db.Select(`SELECT l.*, u.name AS user_name, u.avatar FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
                                 WHERE l.action <> 'login_failed' AND TRUE ORDER BY l.created_at DESC LIMIT 8`)
	} else {
		sql, par := app.ScopeSQL(u, "su", userCols)
		activity = db.Select(`SELECT l.*, u.name AS user_name, u.avatar FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
                                 WHERE l.action <> 'login_failed' AND l.user_id IN (SELECT su.id FROM users su WHERE `+sql+`)
                                 ORDER BY l.created_at DESC LIMIT 8`, par...)
	}

	// This year's appraisal score so far (saved by Management → KPI calculator); not calculated yet = 0
	year := php.Now().Year()
	kpiScore := db.First("SELECT month, score, details, calculated_at FROM kpi_scores WHERE user_id = ? AND year = ?", u.ID, year)
	score := 0.0
	var sections []app.M
	deduction := 0.0
	scoreNote := c.T("Not calculated yet. Your score shows here once management calculates it.")
	tone := "text-graphite-400"
	if kpiScore != nil {
		score = kpiScore.Float("score")
		details := config.NewMap()
		if v, err := config.Decode([]byte(kpiScore.Str("details"))); err == nil {
			if m, ok := v.(*config.Map); ok {
				details = m
			}
		}
		secName := config.NewMap()
		for _, p := range [][2]string{{"kpi", "1. KPI"}, {"ontime", "2. KPI on time"}, {"qo", "3. Department quality objective (QO)"}, {"sales", "4. Sales performance"},
			{"qms", "5. QMS (NCR / CAR)"}, {"leader", "6. Evaluation from leader"}, {"absence", "7. Absence rate"}} {
			secName.Set(p[0], p[1])
		}
		raw, _ := details.Get("sections")
		secs, _ := raw.(*config.Map)
		if secs != nil {
			// 1–7 order (the database does not keep it), then anything else in stored order
			var keys []string
			for _, k := range secName.Keys {
				if _, ok := secs.Get(k); ok {
					keys = append(keys, k)
				}
			}
			for _, k := range secs.Keys {
				if _, ok := secName.Get(k); !ok {
					keys = append(keys, k)
				}
			}
			for _, k := range keys {
				sv, _ := secs.Get(k)
				s, _ := sv.(*config.Map)
				if s == nil {
					continue
				}
				ptsV, _ := s.Get("points")
				pts := php.Int(ptsV)
				if pts <= 0 {
					continue
				}
				gotV, _ := s.Get("earned")
				got := php.Float(gotV)
				label := k
				if l, ok := secName.Get(k); ok {
					label = php.Str(l)
				}
				pct := 0
				if pts != 0 {
					pct = int(math.Round(got / float64(pts) * 100))
				}
				sections = append(sections, app.M{"label": c.T(label), "got": php.NumberClean(math.Round(got*100) / 100), "pts": pts, "pct": pct})
			}
		}
		dv, _ := details.Get("deduction")
		deduction = php.Float(dv)
		scoreNote = c.T("Calculated up to :month · :date", "month", app.FormatDate(c, php.Str(year)+"-"+wsTwoDigits(kpiScore.Int("month"))+"-01", "F"),
			"date", app.FormatDate(c, kpiScore["calculated_at"], "j M Y"))
		switch {
		case score >= 80:
			tone = "text-emerald-700"
		case score >= 60:
			tone = "text-graphite-900"
		default:
			tone = "text-signal-700"
		}
	}

	oilRows := oil.Current()
	var oilSync app.M
	if len(oilRows) > 0 {
		oilSync = oil.LastSync()
	}

	h := php.Now().Hour()
	greeting := "Good evening"
	if h < 12 {
		greeting = "Good morning"
	} else if h < 17 {
		greeting = "Good afternoon"
	}
	who := c.T(u.RoleName)
	if u.Row["job_title"] != nil {
		who = u.JobTitle
	}
	if u.DepartmentName != "" {
		who += ", " + u.DepartmentName
	}
	if u.TeamName != "" {
		who += " / " + u.TeamName
	}

	bar := map[string]string{"urgent": "bg-signal-600", "high": "bg-signal-500/70", "medium": "bg-graphite-600", "low": "bg-graphite-300"}
	today := php.StrToTime(php.Today())
	var tasks []app.M
	for _, t := range myTasks {
		pr := php.Ucfirst(t.Str("priority"))
		b := bar[t.Str("priority")]
		if b == "" {
			b = "bg-graphite-300"
		}
		due := ""
		overdue := false
		if t.Str("due_date") != "" {
			overdue = php.StrToTime(t["due_date"]) < today
			key := "Due :date"
			if overdue {
				key = "Overdue, :date"
			}
			due = c.T(key, "date", app.FormatDate(c, t["due_date"], "d M"))
		}
		tasks = append(tasks, app.M{"row": t, "bar": b, "priority": c.T(pr), "status": c.T(wsTaskStatuses[t.Str("status")]), "due": due, "overdue": overdue})
	}
	var kpis []app.M
	for _, k := range myKpis {
		pct := 0
		if k.Float("target") > 0 {
			pct = int(math.Round(k.Float("actual") / k.Float("target") * 100))
		}
		kpis = append(kpis, app.M{"title": k.Str("title"), "pct": pct,
			"line": c.T(":actual of :target :unit · ends :date", "actual", php.NumberClean(k["actual"]), "target", php.NumberClean(k["target"]), "unit", k.Str("unit"), "date", app.FormatDate(c, k["period_end"], "d M"))})
	}

	c.View("dashboard/employee", app.M{
		"title": c.T("My workspace"), "u": u, "scope": scope, "greeting": c.T(greeting) + ", " + u.FirstName(), "who": who,
		"figures": figures, "lg": lg, "tasks": tasks, "kpis": kpis, "teammates": teammates, "activity": activity,
		"score": php.NF(score, 2), "scorePct": int(math.Round(score)), "tone": tone, "scoreNote": scoreNote, "sections": sections,
		"deduction": deduction > 0, "deductionText": c.T("Warnings deducted: −:n", "n", php.NumberClean(deduction)),
		"year": year, "oil": oilRows, "oilSync": oilSync,
	})
}

func wsTwoDigits(n int) string {
	if n < 10 && n >= 0 {
		return "0" + php.Str(n)
	}
	return php.Str(n)
}
