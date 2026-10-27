package crm

import (
	"html/template"
	"math"
	"sort"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

// CRM dashboard (DashboardController).
// Everyone sees the data of their own department. Whole-company roles (Admin, Management …) see all departments
// and can pick one department to look at; "My records" narrows any view to the person's own records.

func dashboardIndex(c *app.Ctx) {
	c.Authorize("crm_dashboard", "view")
	u := c.MustUser()
	all := seesAll(u)
	deps := departments()
	pick := 0
	if all {
		pick = php.Int(c.Query("department", "0"))
		if !optHas(deps, php.Str(pick)) {
			pick = 0
		}
	}
	mine := c.Query("mine") == "1"
	from, to, rng := c.PeriodFromRequest()
	lo, hi := from.Format("2006-01-02"), to.Format("2006-01-02") // validated dates: safe to put in the SQL

	// who can be picked: everybody with CRM access for whole-company roles, the own department's for BU managers and managers, nobody for members
	people := crmMembers(u, pick)
	member := php.Int(c.Query("member", "0"))
	if !optHas(people, php.Str(member)) {
		member = 0
	}
	if len(people) > 0 {
		mine = false
	}

	// Which records feed the numbers (alias t = the CRM table)
	var sql string
	var params []any
	var scopeName string
	switch {
	case all:
		sql, scopeName = "TRUE", c.T("All departments")
		if pick != 0 {
			sql, params = "t.department_id = ?", []any{pick}
			scopeName, _ = app.OptLabel(deps, php.Str(pick))
		}
	case u.DepartmentID != 0:
		sql, params = "t.department_id = ?", []any{u.DepartmentID}
		scopeName, _ = app.OptLabel(deps, php.Str(u.DepartmentID))
		if scopeName == "" {
			scopeName = u.DepartmentName
		}
	default:
		sql, params = "t.owner_id = ?", []any{u.ID}
		scopeName = c.T("My records")
	}
	if mine || member != 0 {
		who := member
		if who == 0 {
			who = u.ID
		}
		sql = "(" + sql + ") AND t.owner_id = ?"
		params = append(append([]any{}, params...), who)
	}
	rep := func(n int) []any {
		var out []any
		for i := 0; i < n; i++ {
			out = append(out, params...)
		}
		return out
	}
	q := db.On("crm")
	cur := baseCurrency()
	val := "COALESCE(t.amount, 0) * COALESCE(c.rate_to_base, 1)"
	opp := "FROM opportunities t LEFT JOIN currencies c ON c.code = t.currency WHERE t.deleted_at IS NULL AND "
	when := "COALESCE(t.close_at, t.updated_at)"
	open := sqlList(stagePipeline)

	// ----- pipeline by stage (open stages in order)
	byStage := map[string]db.Row{}
	for _, r := range q.Select("SELECT t.opportunity_stage AS stage, count(*) AS n, sum("+val+") AS v, sum("+val+" * COALESCE(t.probability, 0) / 100) AS w "+
		opp+sql+" AND t.opportunity_stage IN "+open+" GROUP BY t.opportunity_stage", params...) {
		byStage[r.Str("stage")] = r
	}
	type stageRow struct {
		Code, Label, Color string
		N                  int
		V, W               float64
		Width              float64
	}
	var stages []stageRow
	maxStage := 1.0
	for _, s := range stagePipeline {
		r := byStage[s]
		stages = append(stages, stageRow{Code: s, Label: stageLabel(c, s), Color: stageColor(c, s), N: r.Int("n"), V: r.Float("v"), W: r.Float("w")})
		maxStage = math.Max(maxStage, r.Float("v"))
	}
	hold := q.First("SELECT count(*) AS n, COALESCE(sum("+val+"), 0) AS v "+opp+sql+" AND t.opportunity_stage = 'ON_HOLD'", params...)

	// ----- won / lost
	won := q.First(`SELECT
              count(*) FILTER (WHERE t.opportunity_stage = 'CLOSED_WON' AND `+when+`::date BETWEEN '`+lo+`' AND '`+hi+`') AS month_n,
              COALESCE(sum(`+val+`) FILTER (WHERE t.opportunity_stage = 'CLOSED_WON' AND `+when+`::date BETWEEN '`+lo+`' AND '`+hi+`'), 0) AS month_v,
              COALESCE(sum(`+val+`) FILTER (WHERE t.opportunity_stage = 'CLOSED_WON' AND date_trunc('year', `+when+`) = date_trunc('year', now())), 0) AS year_v,
              count(*) FILTER (WHERE t.opportunity_stage = 'CLOSED_WON' AND `+when+` >= now() - interval '12 months') AS won12,
              count(*) FILTER (WHERE t.opportunity_stage = 'CLOSED_LOST' AND `+when+` >= now() - interval '12 months') AS lost12
            `+opp+sql, params...)
	closed := won.Int("won12") + won.Int("lost12")
	winRate := "—"
	if closed > 0 {
		winRate = fmtFloat(phpRound(float64(won.Int("won12"))/float64(closed)*100, 0)) + "%"
	}

	months := q.Select(`SELECT to_char(m, 'YYYY-MM') AS ym, to_char(m, 'Mon') AS label, COALESCE(sum(`+val+`), 0) AS v, count(t.id) AS n
            FROM generate_series(LEAST(date_trunc('month', '`+lo+`'::date), date_trunc('month', '`+hi+`'::date) - interval '5 months'), date_trunc('month', '`+hi+`'::date), interval '1 month') m
            LEFT JOIN opportunities t ON t.deleted_at IS NULL AND t.opportunity_stage = 'CLOSED_WON' AND date_trunc('month', `+when+`) = m AND `+sql+`
            LEFT JOIN currencies c ON c.code = t.currency GROUP BY m ORDER BY m`, params...)

	// ----- leads, contacts
	counts := q.First(`SELECT
              (SELECT count(*) FROM leads t WHERE t.deleted_at IS NULL AND `+sql+`) AS leads,
              (SELECT count(*) FROM leads t WHERE t.deleted_at IS NULL AND t.created_at::date BETWEEN '`+lo+`' AND '`+hi+`' AND `+sql+`) AS leads_new,
              (SELECT count(*) FROM contacts t WHERE t.deleted_at IS NULL AND `+sql+`) AS contacts`, rep(3)...)
	industries := q.Select(`SELECT i.name, i.color, count(DISTINCT t.id) AS n FROM industries i JOIN lead_industries x ON x.industry_id = i.id
            JOIN leads t ON t.id = x.lead_id AND t.deleted_at IS NULL AND `+sql+` GROUP BY i.id ORDER BY n DESC, i.name LIMIT 6`, params...)
	sources := q.Select(`SELECT s.name, s.color, count(DISTINCT t.id) AS n FROM lead_sources s JOIN lead_lead_sources x ON x.lead_source_id = s.id
            JOIN leads t ON t.id = x.lead_id AND t.deleted_at IS NULL AND `+sql+` GROUP BY s.id ORDER BY n DESC, s.name LIMIT 6`, params...)

	// ----- activities
	actCount := q.First(`SELECT
              count(*) FILTER (WHERE t.status = 'PLANNED' AND t.start_at < now()) AS overdue,
              count(*) FILTER (WHERE t.status = 'PLANNED' AND t.start_at >= now() AND t.start_at < now() + interval '7 days') AS week,
              count(*) FILTER (WHERE t.status = 'DONE' AND t.start_at::date BETWEEN '`+lo+`' AND '`+hi+`') AS done_month
            FROM activities t WHERE t.deleted_at IS NULL AND `+sql, params...)
	upcoming := q.Select(`SELECT t.id, t.topic, t.activity_type, t.start_at, t.status, t.owner_id, l.name_en AS lead_name
            FROM activities t LEFT JOIN leads l ON l.id = t.lead_id
            WHERE t.deleted_at IS NULL AND t.status = 'PLANNED' AND `+sql+` ORDER BY (t.start_at < now()) DESC, t.start_at NULLS LAST LIMIT 7`, params...)
	reminders := q.Select(`SELECT t.id, t.topic, t.activity_type, t.start_at FROM activities t
            WHERE t.deleted_at IS NULL AND t.status = 'PLANNED' AND t.notify_me AND t.owner_id = ? AND t.start_at IS NOT NULL
              AND t.start_at - (t.notify_before * interval '1 minute') <= now() AND t.start_at > now() - interval '1 day' ORDER BY t.start_at LIMIT 5`, u.ID)

	// ----- top deals, campaigns
	top := q.Select(`SELECT t.id, t.name, t.opportunity_stage AS stage, t.owner_id, t.close_at, t.probability, t.currency, t.amount, `+val+` AS base_value, l.name_en AS lead_name
            FROM opportunities t LEFT JOIN currencies c ON c.code = t.currency LEFT JOIN leads l ON l.id = t.lead_id
            WHERE t.deleted_at IS NULL AND t.opportunity_stage IN `+open+` AND `+sql+` ORDER BY base_value DESC NULLS LAST LIMIT 5`, params...)
	camps := q.Select("SELECT t.id, t.name, t.budget, t.actual_cost, t.end_date FROM campaigns t WHERE t.deleted_at IS NULL AND t.status = 'ACTIVE' AND "+sql+" ORDER BY t.end_date NULLS LAST LIMIT 4", params...)

	owners := names("users", append(col(top, "owner_id"), col(upcoming, "owner_id")...))

	// ----- department comparison (whole-company roles looking at everything)
	var compare []compareRow
	if all && pick == 0 {
		own := 0
		if mine {
			own = u.ID
		}
		compare = dashCompare(c, deps, val, own)
	}

	// yearly target of the department being looked at (all departments added up for whole-company roles)
	year := to.Year()
	depFor := u.DepartmentID
	if all {
		depFor = pick
	}
	var target *targetSummary
	if all || depFor != 0 { // PHP: $depFor === 0 means no department → no target
		t := targetSummaryOf(depFor, year)
		target = &t
	}

	money := func(n float64) string { return cur + " " + uiCompact(n) }
	pl := func(n int, one, many string) string {
		if n == 1 {
			return c.T(one, "n", n)
		}
		return c.T(many, "n", n)
	}
	pipeline, forecast, openCount := 0.0, 0.0, 0
	for _, s := range stages {
		pipeline += s.V
		forecast += s.W
		openCount += s.N
	}
	for i := range stages {
		if stages[i].V > 0 {
			stages[i].Width = math.Max(2, stages[i].V/maxStage*100)
		}
	}
	memberName := ""
	if member != 0 {
		l, _ := app.OptLabel(people, php.Str(member))
		memberName = strings.SplitN(l, " · ", 2)[0]
	}
	link := func(over app.M) string {
		qm := app.M{"department": nilZero(pick), "member": nilZero(member), "mine": nil, "range": rng, "from": nil, "to": nil}
		if mine {
			qm["mine"] = 1
		}
		if rng == "custom" {
			qm["from"], qm["to"] = lo, hi
		}
		for k, v := range over {
			qm[k] = v
		}
		return c.URL("/crm/dashboard", map[string]any(qm))
	}

	type tile struct {
		Label, Figure, Note, Tone string
	}
	overdue := ""
	if actCount.Int("overdue") > 0 {
		overdue = "text-signal-700"
	}
	forecastNote := ""
	if forecast != 0 {
		forecastNote = " · " + c.T("forecast :v", "v", money(forecast))
	}
	tiles := []tile{
		{c.T("Open pipeline"), money(pipeline), pl(openCount, ":n open opportunity", ":n open opportunities") + forecastNote, ""},
		{c.T("Won in period"), money(won.Float("month_v")), pl(won.Int("month_n"), ":n deal", ":n deals") + " · " + c.T("this year :v", "v", money(won.Float("year_v"))), ""},
		{c.T("Win rate, 12 months"), winRate, c.T(":w won · :l lost", "w", won.Int("won12"), "l", won.Int("lost12")), ""},
		{c.T("Activities to do"), php.Str(actCount.Int("overdue")), c.T("overdue") + " · " + c.T(":n in the next 7 days", "n", actCount.Int("week")), overdue},
		{c.T("Leads"), php.NF(counts.Float("leads"), 0), c.T(":n new in period", "n", counts.Int("leads_new")) + " · " + pl(counts.Int("contacts"), ":n contact", ":n contacts"), ""},
	}

	// filter bar
	var selects []app.RangeSelect
	if all {
		selects = append(selects, app.RangeSelect{Name: "department", Label: c.T("Department"), Options: deps, Value: ternaryStr(pick != 0, php.Str(pick), ""), Placeholder: c.T("All departments")})
	}
	if len(people) > 0 {
		selects = append(selects, app.RangeSelect{Name: "member", Label: c.T("Member"), Options: people, Value: ternaryStr(member != 0, php.Str(member), ""), Placeholder: c.T("All members")})
	}
	keep := map[string]any{"department": nilZero(pick), "member": nilZero(member), "mine": nil}
	if mine {
		keep["mine"] = 1
	}

	// Revenue vs target
	var tgt app.M
	if target != nil {
		tt := target.Total
		if tt <= 0 {
			tt = target.Target[1] + target.Target[2] + target.Target[3] + target.Target[4]
		}
		pt := 0
		if tt > 0 {
			pt = int(phpRound(target.ActualTotal/tt*100, 0))
		}
		curQ := int(math.Ceil(float64(php.Now().Month()) / 3))
		type qcell struct {
			Q                      int
			Now                    bool
			Pct, Actual, Of, Color string
			Width                  int
		}
		var qcells []qcell
		for i := 1; i <= 4; i++ {
			t, a := target.Target[i], target.Actual[i]
			pc := "—"
			w := 0
			col := "#2a78d6"
			if t > 0 {
				p := int(phpRound(a/t*100, 0))
				pc, w = php.Str(p)+"%", min(100, p)
				if p >= 100 {
					col = "#16a34a"
				}
			}
			qcells = append(qcells, qcell{i, i == curQ, pc, money(a), c.T("of :t", "t", money(t)), col, w})
		}
		ycol := "#2a78d6"
		if pt >= 100 {
			ycol = "#16a34a"
		}
		sub := c.T("Won opportunities in the CRM (no ERP department linked)")
		if target.Source == "accounting" {
			sub = c.T("Revenue from Accounting invoices")
		}
		setURL := ""
		if c.Can("crm_targets", "view") {
			setURL = c.URL("/crm/targets", "year", year, "department", nilZero(depFor))
		}
		tgt = app.M{"year": year, "sub": sub, "setURL": setURL, "cells": qcells, "yearPct": php.Str(pt) + "%", "yearActual": money(target.ActualTotal),
			"yearOf": c.T("of :t", "t", money(tt)), "yearColor": ycol, "yearWidth": min(100, pt), "none": tt <= 0}
	}

	// bars: stages
	type stageBar struct {
		Label, Color, URL, Counts, Title string
		Width                            string
	}
	var bars []stageBar
	for _, s := range stages {
		qm := app.M{"stage": s.Code, "department": nilZero(pick), "scope": nil}
		if mine {
			qm["scope"] = "mine"
		}
		bars = append(bars, stageBar{s.Label, s.Color, c.URL("/crm/opportunities", map[string]any(qm)),
			pl(s.N, ":n deal", ":n deals") + " · " + money(s.V), s.Label + ": " + php.NF(s.V, 0) + " " + cur, fmtFloat(phpRound(s.Width, 1))})
	}
	// bars: months
	maxMonth := 1.0
	sumMonths := 0.0
	for _, m := range months {
		maxMonth = math.Max(maxMonth, m.Float("v"))
		sumMonths += m.Float("v")
	}
	type monthBar struct {
		Label, Compact, Title, Height string
		HasV                          bool
	}
	var mbars []monthBar
	for _, m := range months {
		hh := 0.0
		if m.Float("v") > 0 {
			hh = math.Max(3, m.Float("v")/maxMonth*100)
		}
		mbars = append(mbars, monthBar{c.T(m.Str("label")), uiCompact(m.Float("v")), m.Str("label") + ": " + php.NF(m.Float("v"), 0) + " " + cur + " (" + m.Str("n") + ")", fmtFloat(phpRound(hh, 1)), m.Float("v") > 0})
	}
	// lists
	type upRow struct {
		URL, Topic, Meta, When, Late string
		IsLate                       bool
	}
	var ups []upRow
	for _, a := range upcoming {
		late := a["start_at"] != nil && php.StrToTime(a["start_at"]) < php.Now().Unix()
		meta := trLabel(c, activityTypes, a.Str("activity_type"))
		if a.Str("lead_name") != "" {
			meta += " · " + a.Str("lead_name")
		}
		if n, ok := owners[a.Int("owner_id")]; ok {
			meta += " · " + n
		}
		when := "—"
		if a["start_at"] != nil {
			when = app.FormatDate(c, a["start_at"], "d M H:i")
		}
		ups = append(ups, upRow{c.URL("/crm/activities/" + a.Str("id")), a.Str("topic"), meta, when, c.T("Overdue"), late})
	}
	type topRow struct {
		URL, Name, Meta string
		Money, Stage    template.HTML
	}
	var tops []topRow
	for _, o := range top {
		meta := php.OrStr(o.Str("lead_name"), "—")
		if n, ok := owners[o.Int("owner_id")]; ok {
			meta += " · " + n
		}
		tops = append(tops, topRow{c.URL("/crm/opportunities/" + o.Str("id")), o.Str("name"), meta, h(uiMoney(o["amount"], o.Str("currency"))), h(uiStageBadge(c, o.Str("stage")))})
	}
	type remRow struct{ URL, Topic, Meta string }
	var rems []remRow
	for _, r := range reminders {
		rems = append(rems, remRow{c.URL("/crm/activities/" + r.Str("id")), r.Str("topic"),
			trLabel(c, activityTypes, r.Str("activity_type")) + " · " + app.FormatDate(c, r["start_at"], "d M H:i") + " (" + app.TimeAgo(c, r["start_at"]) + ")"})
	}
	type distRow struct {
		Name, Color, Width string
		N                  int
	}
	dist := func(list []db.Row) []distRow {
		mx := 1
		for _, i := range list {
			mx = max(mx, i.Int("n"))
		}
		var out []distRow
		for _, i := range list {
			out = append(out, distRow{i.Str("name"), i.Str("color"), fmtFloat(phpRound(float64(i.Int("n"))/float64(mx)*100, 0)), i.Int("n")})
		}
		return out
	}
	type campRow struct {
		URL, Name, Ends string
		HasMeter        bool
		Meter           template.HTML
	}
	var cps []campRow
	for _, k := range camps {
		cr := campRow{URL: c.URL("/crm/campaigns/" + k.Str("id")), Name: k.Str("name"), Ends: c.T("Ends :date", "date", app.FormatDate(c, k["end_date"], "d M Y"))}
		if k.Float("budget") > 0 {
			used := k.Float("actual_cost") / k.Float("budget") * 100
			cr.HasMeter, cr.Meter = true, meterHTML(c, used, fmtFloat(phpRound(used, 0))+"%")
		}
		cps = append(cps, cr)
	}
	type cmpOut struct {
		ID                                             int
		Name, URL, Leads, Open, Pipeline, Won, Overdue string
		HasOverdue                                     bool
	}
	var cmps []cmpOut
	for _, d := range compare {
		x := cmpOut{ID: d.ID, Name: d.Name, Leads: php.Str(d.Leads), Open: php.Str(d.Open), Pipeline: money(d.Pipeline), Won: money(d.WonYear), Overdue: php.Str(d.Overdue), HasOverdue: d.Overdue > 0}
		if d.ID != 0 {
			x.URL = link(app.M{"department": d.ID})
		}
		cmps = append(cmps, x)
	}

	showing := scopeName
	data := app.M{"title": c.T("CRM overview"), "scopeName": showing, "memberName": memberName, "mine": mine, "hasPeople": len(people) > 0,
		"periodFrom": app.FormatDate(c, from.Format("2006-01-02"), "d M Y"), "periodTo": app.FormatDate(c, to.Format("2006-01-02"), "d M Y"),
		"linkAll": link(app.M{"mine": nil}), "linkMine": link(app.M{"mine": 1}),
		"rangeAction": c.URL("/crm/dashboard"), "range": rng, "from": from, "to": to, "keep": keep, "selects": selects,
		"reminders": rems, "tiles": tiles, "target": tgt, "bars": bars, "holdN": hold.Int("n"), "holdText": c.T("On hold: :n, worth :v", "n", hold.Int("n"), "v", money(hold.Float("v"))),
		"cur": cur, "months": mbars, "monthsEmpty": sumMonths == 0, "ups": ups, "tops": tops,
		"industries": dist(industries), "sources": dist(sources), "camps": cps, "compare": cmps,
		"footnote": c.T("Totals are converted to :cur with the rates in CRM settings → Currencies.", "cur", cur)}
	c.View("crm/dashboard", data)
}

func nilZero(n int) any {
	if n == 0 {
		return nil
	}
	return n
}

type compareRow struct {
	ID                   int
	Name                 string
	Leads, Open, Overdue int
	Pipeline, WonYear    float64
}

// dashCompare: one row per department for the whole-company view.
func dashCompare(c *app.Ctx, deps []app.Opt, val string, mineID int) []compareRow {
	own := ""
	if mineID != 0 {
		own = " AND t.owner_id = " + php.Str(mineID)
	}
	rows := map[int]*compareRow{}
	touch := func(d any) *compareRow {
		k := php.Int(d)
		if rows[k] == nil {
			name := c.T("No department")
			if k != 0 {
				name = "#" + php.Str(k)
				if l, ok := app.OptLabel(deps, php.Str(k)); ok {
					name = l
				}
			}
			rows[k] = &compareRow{ID: k, Name: name}
		}
		return rows[k]
	}
	q := db.On("crm")
	for _, r := range q.Select("SELECT t.department_id AS d, count(*) AS n FROM leads t WHERE t.deleted_at IS NULL" + own + " GROUP BY 1") {
		touch(r["d"]).Leads = r.Int("n")
	}
	open := sqlList(stagePipeline)
	for _, r := range q.Select(`SELECT t.department_id AS d, count(*) FILTER (WHERE t.opportunity_stage IN ` + open + `) AS open_n,
                COALESCE(sum(` + val + `) FILTER (WHERE t.opportunity_stage IN ` + open + `), 0) AS pipe,
                COALESCE(sum(` + val + `) FILTER (WHERE t.opportunity_stage = 'CLOSED_WON' AND date_trunc('year', COALESCE(t.close_at, t.updated_at)) = date_trunc('year', now())), 0) AS won
            FROM opportunities t LEFT JOIN currencies c ON c.code = t.currency WHERE t.deleted_at IS NULL` + own + ` GROUP BY 1`) {
		x := touch(r["d"])
		x.Open, x.Pipeline, x.WonYear = r.Int("open_n"), r.Float("pipe"), r.Float("won")
	}
	for _, r := range q.Select("SELECT t.department_id AS d, count(*) AS n FROM activities t WHERE t.deleted_at IS NULL AND t.status = 'PLANNED' AND t.start_at < now()" + own + " GROUP BY 1") {
		touch(r["d"]).Overdue = r.Int("n")
	}
	var out []compareRow
	for _, r := range rows {
		out = append(out, *r)
	}
	sort.SliceStable(out, func(i, j int) bool {
		if out[i].Pipeline != out[j].Pipeline {
			return out[i].Pipeline > out[j].Pipeline
		}
		return out[i].Name < out[j].Name
	})
	return out
}
