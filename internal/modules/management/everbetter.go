package management

import (
	"fmt"
	"sort"
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/conv"
	"acme/internal/db"
	"acme/internal/everbetter"
)

// Management → Everbetter (EverbetterController): who presents on this quarter's day, every request (acknowledge, then
// record the result after the presentation), all projects, and the presentation day of each quarter.

func ebCards(c *app.Ctx, rows []db.Row, show int) {
	kinds := everbetter.KindsT(c)
	for _, p := range rows {
		ms := everbetter.Members(p)
		n := min(show, len(ms))
		p["shown"] = ms[:n]
		p["more"] = len(ms) - n
		if last, ok := p["last_request"].(db.Row); ok && last != nil {
			l, tone := everbetter.StateOf(c, last)
			p["last_label"], p["last_tone"], p["last_kind"] = l, tone, kinds[last.Str("kind")]
		}
	}
}

var kindTone = map[string]string{"initiation": "bg-sky-50 text-sky-800", "closure": "bg-emerald-50 text-emerald-800", "extension": "bg-violet-50 text-violet-800"}

func ebRequestRows(c *app.Ctx, rows []db.Row) {
	kinds := everbetter.KindsT(c)
	for _, x := range rows {
		x["state"], x["tone"] = everbetter.StateOf(c, x)
		x["kind_label"] = kinds[x.Str("kind")]
		x["kind_tone"] = kindTone[x.Str("kind")]
		x["waiting"] = x.Str("status") == "submitted"
		x["decidable"] = x.Str("status") != "withdrawn" && x["result"] == nil
	}
}

func ebYears(thisYear int) []int {
	first := thisYear
	if v := conv.Int(db.Scalar("SELECT min(EXTRACT(YEAR FROM requested_at))::int FROM everbetter_presentations WHERE status <> 'withdrawn'")); v != 0 {
		first = v
	}
	var out []int
	for y := thisYear; y >= max(first, thisYear-5); y-- {
		out = append(out, y)
	}
	return out
}

func ebIndex(c *app.Ctx) {
	c.Authorize("management", "view")
	u := c.MustUser()
	y, q := everbetter.QuarterOf("")
	year := conv.Int(c.Query("year", conv.Str(y)))
	if year < 2020 || year > 2100 {
		year = y
	}
	status := c.Query("status", "open")
	session := everbetter.Session(y, q)

	w := []string{"p.deleted_at IS NULL"}
	pp := []any{}
	switch status {
	case "open":
		w = append(w, "x.status <> 'withdrawn' AND x.result IS NULL")
	case "submitted":
		w = append(w, "x.status = 'submitted'")
	case "decided":
		w = append(w, "x.result IS NOT NULL")
	}
	if sid := conv.Int(c.Query("session", "0")); sid != 0 {
		w = append(w, "x.session_id = ?")
		pp = append(pp, sid)
	}
	requests := db.Select(`SELECT x.*, s.present_on, s.year, s.quarter, p.title, p.owner_name, p.start_date, p.end_date, p.status AS project_status, d.name AS department_name, u.avatar
            FROM everbetter_presentations x JOIN everbetter_sessions s ON s.id = x.session_id JOIN everbetter_projects p ON p.id = x.project_id
            LEFT JOIN departments d ON d.id = p.department_id LEFT JOIN users u ON u.id = x.requested_by
            WHERE `+strings.Join(w, " AND ")+` ORDER BY s.present_on, x.requested_at LIMIT 300`, pp...)
	ebRequestRows(c, requests)
	var thisQuarter []db.Row
	if session != nil {
		thisQuarter = db.Select(`SELECT x.*, p.title, p.owner_name, u.avatar FROM everbetter_presentations x JOIN everbetter_projects p ON p.id = x.project_id
            LEFT JOIN users u ON u.id = x.requested_by WHERE x.session_id = ? AND x.status <> 'withdrawn' AND p.deleted_at IS NULL ORDER BY x.kind, x.requested_at`, session["id"])
		ebRequestRows(c, thisQuarter)
		for _, x := range thisQuarter {
			x["note_short"] = conv.Substr(x.Str("note"), 0, 120)
		}
	}
	for _, x := range requests {
		x["note_short"] = conv.Substr(x.Str("note"), 0, 200)
	}
	tab := c.Query("tab", "projects")
	if !conv.InList(tab, "projects", "presentations", "departments", "ranking") {
		tab = "projects"
	}
	counts := db.First(`SELECT count(*) FILTER (WHERE x.status = 'submitted') AS waiting, count(*) FILTER (WHERE x.status = 'acknowledged' AND x.result IS NULL) AS accepted
                FROM everbetter_presentations x JOIN everbetter_projects p ON p.id = x.project_id WHERE p.deleted_at IS NULL`)
	var tabs []app.M
	for _, t := range [][2]string{{"projects", c.T("All projects")}, {"presentations", c.T("Presentations")}, {"departments", c.T("By department")}, {"ranking", c.T("Ranking")}} {
		var tv any
		if t[0] != "projects" {
			tv = t[0]
		}
		tabs = append(tabs, app.M{"url": c.URL("/management/everbetter", "tab", tv), "label": t[1], "on": tab == t[0]})
	}
	view := app.M{"title": c.T("Everbetter"), "tab": tab, "tabs": tabs, "counts": counts, "waitingN": counts.Int("waiting"), "acceptedN": counts.Int("accepted")}
	switch tab {
	case "projects":
		pq, ps := c.Query("q"), c.Query("pstatus")
		projects := everbetter.Projects(u, pq, ps, false)
		ebCards(c, projects, 5)
		var stOpts []app.Opt
		for _, k := range everbetter.ProjectKeys {
			stOpts = append(stOpts, app.Opt{Value: k, Label: c.T(everbetter.Project[k])})
		}
		view["projects"], view["pq"], view["ps"], view["stOpts"] = projects, pq, ps, stOpts
	case "presentations":
		sessions := db.Select("SELECT * FROM everbetter_sessions WHERE year = ?", year)
		byQ := map[int]db.Row{}
		for _, s := range sessions {
			byQ[s.Int("quarter")] = s
		}
		type qrow struct {
			Q          int
			Day, Place string
			Min, Max   string
			Err        string
		}
		var qs []qrow
		for qq := 1; qq <= 4; qq++ {
			r := qrow{Q: qq, Min: fmt.Sprintf("%04d-%02d-01", year, (qq-1)*3+1)}
			t := time.Date(year, time.Month(qq*3)+1, 0, 0, 0, 0, 0, conv.Loc)
			r.Max = t.Format("2006-01-02")
			s := byQ[qq]
			r.Day, r.Place = conv.Str(oldOrRow(c, "day_"+conv.Str(qq), s, "present_on")), conv.Str(oldOrRow(c, "place_"+conv.Str(qq), s, "place"))
			qs = append(qs, r)
		}
		var all []app.Opt
		for _, s := range db.Select("SELECT id, to_char(present_on, 'DD Mon YYYY') || ' (Q' || quarter || '/' || year || ')' AS label FROM everbetter_sessions ORDER BY present_on DESC LIMIT 12") {
			all = append(all, app.Opt{Value: s.Str("id"), Label: s.Str("label")})
		}
		sel := ""
		if sid := conv.Int(c.Query("session", "0")); sid != 0 {
			sel = conv.Str(sid)
		}
		link := func(st string, yr int) string {
			return c.URL("/management/everbetter", "tab", "presentations", "status", st, "year", yr)
		}
		type st struct {
			URL, Label string
			On         bool
		}
		var sts []st
		for _, t := range [][2]string{{"open", c.T("Waiting")}, {"submitted", c.T("Not accepted yet")}, {"decided", c.T("Decided")}, {"all", c.T("All")}} {
			sts = append(sts, st{link(t[0], year), t[1], status == t[0]})
		}
		view["session"], view["y"], view["q"] = session, y, q
		if session != nil {
			view["sessionDay"] = app.FormatDate(c, session["present_on"], "l d M Y")
		}
		view["thisQuarter"], view["requests"], view["status"], view["sts"] = thisQuarter, requests, status, sts
		view["year"], view["qs"], view["allSessions"], view["selSession"], view["status"] = year, qs, all, sel, status
		view["prevYear"], view["nextYear"] = link(status, year-1), link(status, year+1)
	case "departments":
		view["bd"] = ebByDepartment(c, y)
	default:
		view["rk"] = ebRanking(c, y, year)
	}
	c.View("management/everbetter", view)
}

func oldOrRow(c *app.Ctx, key string, row db.Row, col string) any {
	if v, ok := c.Sess.Old(key); ok {
		return v
	}
	if row == nil {
		return ""
	}
	return row[col]
}

type ebDept struct {
	Name, Color string
	Cells       []app.M
	Sum         int
}

// ebByDepartment: projects submitted per department and year. A project counts in the year it first asked to present
// (its first request that was not withdrawn), in the department it belongs to.
func ebByDepartment(c *app.Ctx, thisYear int) app.M {
	years := ebYears(thisYear)
	data := db.Select(`SELECT p.department_id, EXTRACT(YEAR FROM f.first_at)::int AS yr, count(*) AS submitted,
                count(*) FILTER (WHERE p.status IN ('approved', 'closed')) AS approved, count(*) FILTER (WHERE p.status = 'closed') AS closed
            FROM everbetter_projects p
            JOIN LATERAL (SELECT min(x.requested_at) AS first_at FROM everbetter_presentations x WHERE x.project_id = p.id AND x.status <> 'withdrawn') f ON f.first_at IS NOT NULL
            WHERE p.deleted_at IS NULL GROUP BY 1, 2`)
	type cell struct{ Submitted, Approved int }
	type row struct {
		ID          int
		Name, Color string
		Years       map[int]cell
		Order       int
	}
	deps := map[int]db.Row{}
	var depOrder []int
	for _, d := range db.Select("SELECT id, name, color FROM departments WHERE deleted_at IS NULL ORDER BY name") {
		deps[d.Int("id")] = d
		depOrder = append(depOrder, d.Int("id"))
	}
	rows := map[int]*row{}
	var order []int
	totals := map[int]int{}
	for _, r := range data {
		d := r.Int("department_id")
		if rows[d] == nil {
			name, color := c.T("No department"), "#9ca3af"
			if dd, ok := deps[d]; ok {
				name = dd.Str("name")
				if dd.Str("color") != "" {
					color = dd.Str("color")
				}
			}
			rows[d] = &row{ID: d, Name: name, Color: color, Years: map[int]cell{}}
			order = append(order, d)
		}
		rows[d].Years[r.Int("yr")] = cell{r.Int("submitted"), r.Int("approved")}
		totals[r.Int("yr")] += r.Int("submitted")
	}
	for _, id := range depOrder { // departments without a project yet show as zero
		if rows[id] == nil {
			color := "#9ca3af"
			if deps[id].Str("color") != "" {
				color = deps[id].Str("color")
			}
			rows[id] = &row{ID: id, Name: deps[id].Str("name"), Color: color, Years: map[int]cell{}}
			order = append(order, id)
		}
	}
	sum := func(r *row) int {
		s := 0
		for _, c := range r.Years {
			s += c.Submitted
		}
		return s
	}
	list := make([]*row, 0, len(order))
	for _, id := range order {
		list = append(list, rows[id])
	}
	sort.SliceStable(list, func(i, j int) bool {
		a, b := list[i], list[j]
		if a.Years[thisYear].Submitted != b.Years[thisYear].Submitted {
			return a.Years[thisYear].Submitted > b.Years[thisYear].Submitted
		}
		if sum(a) != sum(b) {
			return sum(a) > sum(b)
		}
		return a.Name < b.Name
	})
	maxCell := 1
	for _, r := range list {
		for _, cl := range r.Years {
			maxCell = max(maxCell, cl.Submitted)
		}
	}
	var out []app.M
	for _, r := range list {
		var cells []app.M
		for _, yr := range years {
			cl, ok := r.Years[yr]
			x := app.M{"n": cl.Submitted}
			if ok && cl.Submitted > 0 {
				x["style"] = fmt.Sprintf("background: rgba(209,26,42,%s); color: %s", conv.Str(roundTo(0.08+0.5*float64(cl.Submitted)/float64(maxCell), 2)), map[bool]string{true: "#fff", false: "#7f1d1d"}[float64(cl.Submitted)/float64(maxCell) > 0.6])
				x["approved"] = c.T(":a approved", "a", cl.Approved)
			}
			cells = append(cells, x)
		}
		out = append(out, app.M{"name": r.Name, "color": r.Color, "cells": cells, "sum": sum(r)})
	}
	var yh, tt []app.M
	all := 0
	for _, yr := range years {
		yh = append(yh, app.M{"y": yr, "this": yr == thisYear})
		tt = append(tt, app.M{"n": totals[yr]})
		all += totals[yr]
	}
	drafts := conv.Int(db.Scalar(`SELECT count(*) FROM everbetter_projects p WHERE p.deleted_at IS NULL
            AND NOT EXISTS (SELECT 1 FROM everbetter_presentations x WHERE x.project_id = p.id AND x.status <> 'withdrawn')`))
	return app.M{"years": yh, "rows": out, "totals": tt, "all": all, "drafts": drafts}
}

func roundTo(f float64, d int) float64 {
	p := 1.0
	for i := 0; i < d; i++ {
		p *= 10
	}
	return float64(int(f*p+0.5)) / p
}

// ebRanking: the 1st, 2nd and 3rd project of every year, and the projects that can be ranked in the chosen year.
func ebRanking(c *app.Ctx, thisYear, year int) app.M {
	winners := map[int]map[int]db.Row{}
	wyears := map[int]bool{}
	for _, w := range db.Select(`SELECT p.id, p.title, p.owner_name, p.award_year, p.award_rank, p.status, d.name AS department_name, u.avatar
                FROM everbetter_projects p LEFT JOIN departments d ON d.id = p.department_id LEFT JOIN users u ON u.id = p.owner_id
                WHERE p.award_rank IS NOT NULL AND p.deleted_at IS NULL ORDER BY p.award_year DESC, p.award_rank`) {
		if winners[w.Int("award_year")] == nil {
			winners[w.Int("award_year")] = map[int]db.Row{}
		}
		winners[w.Int("award_year")][w.Int("award_rank")] = w
		wyears[w.Int("award_year")] = true
	}
	candidates := db.Select(`SELECT DISTINCT p.id, p.title, p.owner_name, d.name AS department_name FROM everbetter_projects p
            JOIN everbetter_presentations x ON x.project_id = p.id AND x.status <> 'withdrawn' JOIN everbetter_sessions s ON s.id = x.session_id
            LEFT JOIN departments d ON d.id = p.department_id WHERE s.year = ? AND p.deleted_at IS NULL ORDER BY p.title`, year)
	seen := map[int]bool{}
	var years []int
	for _, y := range ebYears(thisYear) {
		seen[y] = true
		years = append(years, y)
	}
	var extra []int
	for y := range wyears {
		if !seen[y] {
			extra = append(extra, y)
		}
	}
	sort.Sort(sort.Reverse(sort.IntSlice(extra)))
	years = append(years, extra...)
	medals := map[int][3]string{1: {"🥇", "bg-amber-50 ring-amber-400/60", c.T("1st")}, 2: {"🥈", "bg-slate-50 ring-slate-300", c.T("2nd")}, 3: {"🥉", "bg-orange-50 ring-orange-300/70", c.T("3rd")}}
	var ylist []app.M
	for _, y := range years {
		w := winners[y]
		var places []app.M
		for r := 1; r <= 3; r++ {
			m := medals[r]
			x := app.M{"icon": m[0], "tone": m[1], "label": m[2]}
			if p, ok := w[r]; ok {
				x["p"] = p
				x["sub"] = p.Str("owner_name") + " · " + conv.Or(p["department_name"], "—").(string)
			}
			places = append(places, x)
		}
		ylist = append(ylist, app.M{"y": y, "has": len(w) > 0, "places": places, "url": c.URL("/management/everbetter", "tab", "ranking", "year", y) + "#rank-form"})
	}
	var opts []app.Opt
	for _, cd := range candidates {
		opts = append(opts, app.Opt{Value: cd.Str("id"), Label: cd.Str("title") + " · " + conv.Str(conv.Or(cd["department_name"], "—"))})
	}
	var picks []app.M
	for r := 1; r <= 3; r++ {
		cur := ""
		if p, ok := winners[year][r]; ok {
			cur = p.Str("id")
		}
		picks = append(picks, app.M{"n": r, "icon": medals[r][0], "label": medals[r][2], "cur": cur})
	}
	return app.M{"years": ylist, "year": year, "hasCandidates": len(candidates) > 0, "opts": opts, "picks": picks,
		"prev": c.URL("/management/everbetter", "tab", "ranking", "year", year-1) + "#rank-form", "next": c.URL("/management/everbetter", "tab", "ranking", "year", year+1) + "#rank-form"}
}

// ebSaveRanking saves the 1st, 2nd and 3rd project of a year (empty = nobody in that place).
func ebSaveRanking(c *app.Ctx) {
	c.Authorize("management", "view")
	year := conv.Int(c.Input("year"))
	if year < 2020 || year > 2100 {
		app.Invalid("year", c.T("Choose a year."))
	}
	ok := map[int]bool{}
	for _, r := range db.Select(`SELECT DISTINCT p.id FROM everbetter_projects p JOIN everbetter_presentations x ON x.project_id = p.id AND x.status <> 'withdrawn'
            JOIN everbetter_sessions s ON s.id = x.session_id WHERE s.year = ? AND p.deleted_at IS NULL`, year) {
		ok[r.Int("id")] = true
	}
	pick := map[int]int{}
	used := map[int]bool{}
	for r := 1; r <= 3; r++ {
		id := conv.Int(c.Input("rank_"+conv.Str(r), "0"))
		if id == 0 {
			continue
		}
		if !ok[id] {
			app.Invalid("rank_"+conv.Str(r), c.T("Choose a project that presented in :y.", "y", year))
		}
		if used[id] {
			app.Invalid("rank_"+conv.Str(r), c.T("A project can only have one place."))
		}
		used[id] = true
		pick[r] = id
	}
	db.Exec("UPDATE everbetter_projects SET award_year = NULL, award_rank = NULL WHERE award_year = ?", year)
	for r := 1; r <= 3; r++ {
		if id, has := pick[r]; has {
			db.Exec("UPDATE everbetter_projects SET award_year = ?, award_rank = ? WHERE id = ?", year, r, id)
		}
	}
	titles := map[int]string{}
	var ids []string
	for _, id := range pick {
		ids = append(ids, conv.Str(id))
	}
	if len(ids) > 0 {
		for _, r := range db.Select("SELECT id, title FROM everbetter_projects WHERE id IN (" + strings.Join(ids, ",") + ")") {
			titles[r.Int("id")] = r.Str("title")
		}
	}
	me := c.UserID()
	for r := 1; r <= 3; r++ { // tell the winning teams
		id, has := pick[r]
		if !has {
			continue
		}
		to := []int{conv.Int(db.Scalar("SELECT owner_id FROM everbetter_projects WHERE id = ?", id))}
		for _, m := range db.Select("SELECT user_id FROM everbetter_members WHERE project_id = ?", id) {
			to = append(to, m.Int("user_id"))
		}
		seen := map[int]bool{}
		for _, uid := range to {
			if uid != 0 && !seen[uid] && uid != me {
				seen[uid] = true
				app.Deliver(c, "core", "updated", uid, app.Text("Everbetter :y: \":title\" is ranked #:r", "y", year, "title", titles[id], "r", r),
					app.Text("Congratulations to the team!"), c.URL("/everbetter/"+conv.Str(id)), me)
			}
		}
	}
	rank := app.M{}
	for r := 1; r <= 3; r++ {
		if id, has := pick[r]; has {
			rank[conv.Str(r)] = titles[id]
		}
	}
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "everbetter", Label: "Everbetter", Text: "Set the Everbetter ranking of :year", Params: []any{"year", year},
		Props: app.M{"ranking": rank}, NoNotify: true, Module: "management"})
	c.Flash("success", c.T("Ranking of :year saved.", "year", year))
	c.Redirect("/management/everbetter?tab=ranking&year=" + conv.Str(year))
}

// ebProject: one project in Management: details, team, every presentation, and the actions on a waiting request.
func ebProject(c *app.Ctx) {
	c.Authorize("management", "view")
	id := c.ParamInt("id")
	p := everbetter.Find(c.MustUser(), id)
	if p == nil {
		app.Abort(404, c.T("This project is not available."))
	}
	presentations := everbetter.Presentations(id)
	ebRequestRows(c, presentations)
	presented := 0
	for _, x := range presentations {
		if x["result"] != nil {
			presented++
		}
		x["title"] = p["title"]
	}
	days := 1.0
	if s, e := conv.StrToTime(p["start_date"]), conv.StrToTime(p["end_date"]); float64(e-s)/86400 > 1 {
		days = float64(e-s) / 86400
	}
	done := int(float64(time.Now().Unix()-conv.StrToTime(p["start_date"]))/86400/days*100 + 0.5)
	done = min(100, max(0, done))
	c.View("management/everbetter-project", app.M{"title": p["title"], "p": p, "presentations": presentations, "presented": presented, "members": everbetter.Members(p), "done": done})
}

func ebAcknowledge(c *app.Ctx) {
	c.Authorize("management", "view")
	x := everbetter.Acknowledge(c, c.MustUser(), c.ParamInt("id"))
	c.Flash("success", c.T("Accepted. :name has been told.", "name", x["requested_name"]))
	c.Back("", "")
}

func ebDecide(c *app.Ctx) {
	c.Authorize("management", "view")
	x := everbetter.Decide(c, c.MustUser(), c.ParamInt("id"), c.Input("result"), c.Input("note"))
	c.Flash("success", c.T("Result saved for \":title\".", "title", x["title"]))
	c.Back("", "")
}

// ebSessions saves the presentation day of each quarter of one year.
func ebSessions(c *app.Ctx) {
	c.Authorize("management", "view")
	year := conv.Int(c.Input("year"))
	if year < 2020 || year > 2100 {
		app.Invalid("year", c.T("Choose a year."))
	}
	for q := 1; q <= 4; q++ {
		day := strings.TrimSpace(c.Input("day_" + conv.Str(q)))
		place := conv.Substr(strings.TrimSpace(c.Input("place_"+conv.Str(q))), 0, 200)
		var placeV any
		if place != "" {
			placeV = place
		}
		old := everbetter.Session(year, q)
		if day == "" {
			if old != nil && db.Scalar("SELECT 1 FROM everbetter_presentations WHERE session_id = ? LIMIT 1", old["id"]) == nil {
				db.Exec("DELETE FROM everbetter_sessions WHERE id = ?", old["id"])
			}
			continue
		}
		from := fmt.Sprintf("%04d-%02d-01", year, (q-1)*3+1)
		to := time.Date(year, time.Month(q*3)+1, 0, 0, 0, 0, 0, conv.Loc).Format("2006-01-02")
		if !ymdRe.MatchString(day) || day < from || day > to {
			app.Invalid("day_"+conv.Str(q), c.T("The Q:q day must fall between :from and :to.", "q", q, "from", app.FormatDate(c, from, "d M Y"), "to", app.FormatDate(c, to, "d M Y")))
		}
		db.Exec(`INSERT INTO everbetter_sessions (year, quarter, present_on, place, created_by) VALUES (?, ?, ?, ?, ?)
                      ON CONFLICT (year, quarter) DO UPDATE SET present_on = EXCLUDED.present_on, place = EXCLUDED.place, updated_at = now()`, year, q, day, placeV, c.UserID())
	}
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "everbetter", Label: "Everbetter", Text: "Set the Everbetter presentation days of :year", Params: []any{"year", year}, NoNotify: true, Module: "management"})
	c.Flash("success", c.T("Presentation days of :year saved.", "year", year))
	c.Redirect("/management/everbetter?tab=presentations&year=" + conv.Str(year) + "#days")
}
