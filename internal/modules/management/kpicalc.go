package management

import (
	"encoding/json"
	"fmt"
	"math"
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/conv"
	"acme/internal/db"
	"acme/internal/kpisub"
)

// Management → KPI calculator (KpiCalculatorController): everyone's yearly appraisal score (0 until calculated), and
// per person the calculation with every figure. Sections 1 and 2 come from KPIs & OKRs and KPI submissions; 3–7 and
// warnings are typed in. Who: the Management module (administrators and Management); saving needs kpi_calculator.edit.

func kcYear(c *app.Ctx) int {
	def := conv.Str(conv.Now().Year())
	y := conv.Int(c.Query("year", c.Input("year", def)))
	if y >= 2000 && y <= 2100 {
		return y
	}
	return conv.Now().Year()
}

// kcMonth: calculate up to this month: by default this month for the current year, December for earlier years.
func kcMonth(c *app.Ctx, year int, saved int) int {
	def := 12
	if year == conv.Now().Year() {
		def = int(conv.Now().Month())
	}
	if saved != 0 {
		def = saved
	}
	m := conv.Int(c.Query("month", c.Input("month", conv.Str(def))))
	return max(1, min(12, m))
}

func kcPerson(c *app.Ctx, id int) db.Row {
	sql, p := kpisub.ScopeSQL(c.MustUser(), "u", "id")
	row := db.First(`SELECT u.id, u.name, u.email, u.avatar, u.department_id, d.name AS department_name, r.name AS role_name
            FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.id = ? AND u.deleted_at IS NULL AND `+sql, append([]any{id}, p...)...)
	if row == nil {
		app.Abort(404, c.T("This person is not found, or is outside what you can see."))
	}
	return row
}

func kcList(c *app.Ctx, year int, q string) []db.Row {
	sql, sp := kpisub.ScopeSQL(c.MustUser(), "u", "id")
	where := []string{"u.deleted_at IS NULL", "u.is_active", sql}
	params := append([]any{year}, sp...)
	if q != "" {
		where = append(where, "(u.name ILIKE ? OR d.name ILIKE ?)")
		like := "%" + strings.NewReplacer(`\`, `\\`, "%", `\%`, "_", `\_`).Replace(q) + "%"
		params = append(params, like, like)
	}
	return db.Select(`SELECT u.id, u.name, u.avatar, d.name AS department_name, r.name AS role_name, ks.month, ks.score, ks.emp_group, ks.calculated_at, cb.name AS calculated_by_name
              FROM users u JOIN roles r ON r.id = u.role_id
         LEFT JOIN departments d ON d.id = u.department_id
         LEFT JOIN kpi_scores ks ON ks.user_id = u.id AND ks.year = ?
         LEFT JOIN users cb ON cb.id = ks.calculated_by
             WHERE `+strings.Join(where, " AND ")+` ORDER BY d.name NULLS LAST, u.name`, params...)
}

func scoreTone(s float64) string {
	switch {
	case s >= 80:
		return "bg-emerald-50 text-emerald-800"
	case s >= 60:
		return "bg-amber-50 text-amber-800"
	case s > 0:
		return "bg-signal-50 text-signal-800"
	}
	return "bg-graphite-900/6 text-graphite-500"
}

func kcIndex(c *app.Ctx) {
	c.Authorize("kpi_calculator", "view")
	year := kcYear(c)
	q := conv.Substr(strings.TrimSpace(c.Query("q")), 0, 100)
	rows := kcList(c, year, q)
	done, sum := 0, 0.0
	for _, r := range rows {
		if !r.Nil("score") {
			done++
			sum += r.Float("score")
		}
	}
	avg := 0.0
	if done > 0 {
		avg = sum / float64(done)
	}
	var out []app.M
	for _, r := range rows {
		score := r.Float("score")
		calc := c.T("Not calculated")
		if !r.Nil("calculated_at") {
			calc = app.FormatDate(c, r["calculated_at"], "j M Y") + " · " + conv.Str(conv.Coalesce(r["calculated_by_name"], ""))
		}
		month := ""
		if r.Int("month") != 0 {
			month = app.FormatDate(c, fmt.Sprintf("%04d-%02d-01", year, r.Int("month")), "F")
		}
		grp := "—"
		if r.Str("emp_group") != "" {
			g := r.Str("emp_group")
			if l, ok := groupLabels[g]; ok {
				grp = c.T(l)
			} else {
				grp = c.T(g)
			}
		}
		out = append(out, app.M{"r": r, "link": c.URL(fmt.Sprintf("/management/kpi-calculator/%d", r.Int("id")), "year", year), "tone": scoreTone(score), "score": conv.NF(score, 2),
			"calc": calc, "month": month, "group": grp})
	}
	c.View("management/kpi-calc/index", app.M{"title": c.T("KPI calculator"), "year": year, "yearS": conv.Str(year), "q": q, "rows": out, "n": len(rows), "done": done, "avg": conv.NF(avg, 2),
		"years": yearOpts(conv.Now().Year(), conv.Now().Year()-4)})
}

func kcLastGroup(id int) string {
	if g := conv.Str(db.Scalar("SELECT emp_group FROM kpi_scores WHERE user_id = ? ORDER BY year DESC LIMIT 1", id)); g != "" {
		return g
	}
	return "bu"
}

func kcSaved(id, year int) db.Row {
	return db.First("SELECT ks.*, cb.name AS calculated_by_name FROM kpi_scores ks LEFT JOIN users cb ON cb.id = ks.calculated_by WHERE ks.user_id = ? AND ks.year = ?", id, year)
}

// mayEdit: saving: kpi_calculator.edit, and a role that sees everyone (Management) or the BU manager of the department / an administrator.
func kcMayEdit(c *app.Ctx, p db.Row) bool {
	u := c.MustUser()
	return c.Can("kpi_calculator", "edit") && (u.IsAdmin() || u.Scope() == "all" || kpisub.CanManage(u, p["department_id"]))
}

func kcShow(c *app.Ctx) {
	c.Authorize("kpi_calculator", "view")
	id := c.ParamInt("id")
	p := kcPerson(c, id)
	year := kcYear(c)
	saved := kcSaved(id, year)
	in := cleanInputs(map[string]any{})
	savedMonth := 0
	group := kcLastGroup(id)
	if saved != nil {
		in = cleanInputs(inputsMap(saved["inputs"]))
		savedMonth = saved.Int("month")
		group = saved.Str("emp_group")
	}
	group = c.Query("group", group)
	kcPage(c, p, year, kcMonth(c, year, savedMonth), group, in, saved, nil, false)
}

// kcCalculate: POST: 'calculate' shows the result with the typed figures; 'save' keeps it as the person's score for the year.
func kcCalculate(c *app.Ctx) {
	c.Authorize("kpi_calculator", "edit")
	id := c.ParamInt("id")
	p := kcPerson(c, id)
	if !kcMayEdit(c, p) {
		app.Abort(403, c.T("You can view this score but not change it."))
	}
	year := kcYear(c)
	month := kcMonth(c, year, 0)
	group := c.Input("group", "bu")
	if _, ok := points[group]; !ok {
		group = "bu"
	}
	raw := map[string]any{}
	for _, k := range c.All().Keys {
		raw[k], _ = c.All().Get(k)
	}
	in := cleanInputs(raw)
	r := calculate(id, year, month, group, in)
	if c.Input("action") == "save" {
		before := db.Scalar("SELECT score FROM kpi_scores WHERE user_id = ? AND year = ?", id, year)
		inJSON, _ := json.Marshal(in)
		detJSON, _ := json.Marshal(r)
		db.Exec(`INSERT INTO kpi_scores (user_id, year, month, emp_group, inputs, details, score, calculated_by, calculated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, now())
                ON CONFLICT (user_id, year) DO UPDATE SET month = EXCLUDED.month, emp_group = EXCLUDED.emp_group, inputs = EXCLUDED.inputs, details = EXCLUDED.details,
                score = EXCLUDED.score, calculated_by = EXCLUDED.calculated_by, calculated_at = now()`,
			id, year, month, group, string(inJSON), string(detJSON), r.Score, c.UserID())
		from := ""
		if before != nil {
			from = conv.NF(conv.Float(before), 2)
		}
		ch := config.NewMap()
		sc := config.NewMap()
		sc.Set("from", from)
		sc.Set("to", conv.NF(r.Score, 2))
		ch.Set("score", sc)
		app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "kpi_score", ID: id, Label: p.Str("name"), Text: "Calculated the :year KPI score of :name: :score",
			Params: []any{"year", year, "name", p["name"], "score", conv.NF(r.Score, 2)}, Props: app.M{"changes": ch, "month": month, "group": group}, Owner: id, NoNotify: true})
		c.Flash("success", c.T("Score saved: :score of 100.", "score", conv.NF(r.Score, 2)))
		c.Redirect(fmt.Sprintf("/management/kpi-calculator/%d?year=%d", id, year))
	}
	kcPage(c, p, year, month, group, in, kcSaved(id, year), &r, true)
}

func kcPage(c *app.Ctx, p db.Row, year, month int, group string, in map[string]any, saved db.Row, r *calcRes, preview bool) {
	if _, ok := points[group]; !ok {
		group = "bu"
	}
	var res calcRes
	if r != nil {
		res = *r
	} else {
		res = calculate(p.Int("id"), year, month, group, in)
	}
	canEdit := kcMayEdit(c, p)
	pts := points[group]
	pct := func(v *float64) string {
		if v == nil {
			return "—"
		}
		return conv.NF(*v, 2) + "%"
	}
	pctV := func(v float64) string { return conv.NF(v, 2) + "%" }
	months := make([]app.Opt, 12)
	var monthNames [13]string
	for i := 1; i <= 12; i++ {
		monthNames[i] = app.FormatDate(c, fmt.Sprintf("%04d-%02d-01", year, i), "F")
		months[i-1] = app.Opt{Value: conv.Str(i), Label: monthNames[i]}
	}
	grp := []app.Opt{}
	for _, k := range groupKeys {
		grp = append(grp, app.Opt{Value: k, Label: c.T(groupLabels[k])})
	}
	dirLabel := map[string]string{"min": c.T("Not less than"), "max": c.T("Not more than")}
	measureLabel := map[string]string{"percent": c.T("Finished ÷ total %"), "count": c.T("Count")}
	tone := func(v float64) string {
		switch {
		case v >= 80:
			return "text-emerald-700"
		case v >= 60:
			return "text-amber-700"
		}
		return "text-signal-700"
	}
	chip := func(v float64) string {
		switch {
		case v >= 100:
			return "bg-emerald-50 text-emerald-800"
		case v >= 70:
			return "bg-amber-50 text-amber-800"
		}
		return "bg-signal-50 text-signal-800"
	}
	bar := "bg-signal-600"
	if res.Score >= 80 {
		bar = "bg-emerald-600"
	} else if res.Score >= 60 {
		bar = "bg-amber-500"
	}
	// sections table
	var secRows []app.M
	for _, k := range sectionKeys {
		s := res.Sections[k]
		secRows = append(secRows, app.M{"key": k, "label": c.T(sectionLabels[k]), "has": s.Points > 0, "rate": pctV(s.Rate), "points": s.Points, "earned": conv.NF(s.Earned, 2)})
	}
	// kpi cards
	var kpis []app.M
	for _, k := range res.KPIs {
		crit := "—"
		if k.Criteria != nil {
			crit = conv.NumberClean(*k.Criteria)
		}
		head := dirLabel[k.Direction] + " " + crit + " · " + measureLabel[k.Measure] + " · " + c.T(periodNames[k.Period])
		var rows []app.M
		for _, pr := range k.Periods {
			x := app.M{"label": c.T(pr.Label), "fin": conv.NumberClean(pr.Finished), "tot": conv.NumberClean(pr.Total), "skipped": pr.Skipped}
			if !pr.Skipped {
				if k.Measure == "percent" {
					x["actual"] = pctV(pr.Actual)
				} else {
					x["actual"] = conv.NumberClean(pr.Actual)
				}
				sign := "≥ "
				if k.Direction == "max" {
					sign = "≤ "
				}
				suffix := ""
				if k.Measure == "percent" {
					suffix = "%"
				}
				x["target"] = sign + conv.NumberClean(pr.Target) + suffix
				x["ach"] = pct(pr.Achievement)
				switch {
				case pr.Multiplier != nil:
					x["mult"] = conv.NumberClean(*pr.Multiplier) + "%"
				case k.Direction == "max":
					x["mult"] = c.T("limit")
				default:
					x["mult"] = "—"
				}
				x["result"] = pctV(pr.Result)
			}
			rows = append(rows, x)
		}
		weight := ""
		if k.HasData {
			weight = c.T("Weight") + ` <strong class="tabular-nums text-graphite-900">` + app.E(conv.NumberClean(k.UsedWeight)) + `%</strong>`
			if k.Weight != nil && math.Abs(*k.Weight-k.UsedWeight) > 0.01 {
				weight += ` <span class="text-graphite-400">(` + app.E(conv.NumberClean(*k.Weight)) + `%)</span>`
			}
		} else {
			weight = app.E(c.T("Not counted: no data this year"))
		}
		resText, resTone := c.T("No data"), "bg-graphite-900/6 text-graphite-500"
		if k.HasData {
			resText, resTone = pctV(k.Result), chip(k.Result)
		}
		kpis = append(kpis, app.M{"id": k.ID, "title": k.Title, "kind": strings.ToUpper(k.Kind), "okr": k.Kind == "okr", "head": head, "weight": rawHTML(weight),
			"resText": resText, "resTone": resTone, "weighted": pctV(k.Weighted), "rows": rows})
	}
	// on-time
	var ot []app.M
	for _, m := range res.OnTime.Months {
		x := res.OnTime.By[m]
		ot = append(ot, app.M{"month": monthNames[m], "sub": pct(x.Submitter), "app": pct(x.Approver), "rate": pctV(x.Rate), "chip": chip(x.Rate)})
	}
	// typed inputs
	num := func(name, label, hint, step string) app.M {
		v := ""
		if in[name] != nil {
			v = conv.NumberClean(in[name])
		}
		if step == "" {
			step = "any"
		}
		return app.M{"name": name, "label": label, "hint": hint, "step": step, "value": v}
	}
	ptsHint := func(v float64) string { return c.T("Points :p", "p", conv.NumberClean(v)) }
	qi := res.QMS.Items
	qo, sales := "", ""
	if res.QO != nil {
		qo = c.T("Achievement :a × tier :m", "a", pct(res.QO.Achievement), "m", conv.NumberClean(*res.QO.Multiplier)+"%")
	} else {
		qo = c.T("Enter the department QO actual and target.")
	}
	if res.Sales != nil {
		sales = c.T("Achievement :a × tier :m", "a", pct(res.Sales.Achievement), "m", conv.NumberClean(*res.Sales.Multiplier)+"%")
	} else {
		sales = c.T("Enter sales actual and target (same unit).")
	}
	resultOf := func(x *rateRes) float64 {
		if x == nil {
			return 0
		}
		return x.Result
	}
	view := app.M{"title": c.T("KPI calculator") + " · " + p.Str("name"), "p": p, "year": year, "yearS": conv.Str(year), "month": conv.Str(month), "group": group, "preview": preview, "canEdit": canEdit,
		"ro": !canEdit, "years": yearOpts(conv.Now().Year(), conv.Now().Year()-4), "months": months, "groups": grp,
		"score": conv.NF(res.Score, 2), "scoreTone": tone(res.Score), "bar": bar, "barW": conv.Str(math.Min(100, res.Score)),
		"groupLine": c.T(":g · January – :m :y", "g", c.T(groupLabels[group]), "m", monthNames[month], "y", year), "secRows": secRows, "deduction": res.Deduction, "deductionS": conv.NF(res.Deduction, 2), "deductionN": conv.NumberClean(res.Deduction),
		"noRaise": res.Absence.NoRaise, "severe": conv.Int(in["severe"]) != 0, "kpis": kpis, "openKPIs": len(kpis) <= 6,
		"kpiRate": pctV(res.KPI.Rate), "waiting": res.Waiting, "scaled": res.KPI.Scaled, "kpiPoints": pts["kpi"], "ontimePoints": pts["ontime"],
		"ontimeRate": pctV(res.OnTime.Rate), "ontime": ot, "tiers": true,
		"qoDim": pts["qo"] == 0, "salesDim": pts["sales"] == 0, "qmsDim": pts["qms"] == 0, "qoPoints": pts["qo"], "salesPoints": pts["sales"], "qmsPoints": pts["qms"], "leaderPoints": pts["leader"], "absencePoints": pts["absence"],
		"qoRate": pctV(resultOf(res.QO)), "salesRate": pctV(resultOf(res.Sales)), "qoLine": qo, "salesLine": sales,
		"qmsPts":   conv.NumberClean(res.QMS.Points),
		"qoActual": num("qo_actual", c.T("Actual"), "", ""), "qoTarget": num("qo_target", c.T("Target"), "", ""),
		"salesActual": num("sales_actual", c.T("Actual"), "", ""), "salesTarget": num("sales_target", c.T("Target"), "", ""),
		"qms": []app.M{num("qms_open_car", c.T("CARs still open"), ptsHint(qi["open_car"]), "1"), num("qms_repeat_car", c.T("Repeated CAR"), ptsHint(qi["repeat_car"]), "1"),
			num("qms_docs", c.T("Controlled documents"), ptsHint(qi["docs"]), "1"), num("qms_projects", c.T("Ever Better projects"), ptsHint(qi["projects"]), "1"),
			num("qms_plan_done", c.T("Action plans done"), "", ""), num("qms_plan_assigned", c.T("Action plans assigned"), ptsHint(qi["plan"]), "")},
		"leaderRate": pctV(f(res.Leader)), "leader": num("leader", c.T("Rate from the direct supervisor (0–100%)"), c.T("Competency, behavior and attitude."), ""),
		"absRate":     pctV(res.Absence.Rate),
		"abs":         []app.M{num("abs_unpaid_personal", c.T("Unpaid personal leave (hours)"), "", ""), num("abs_sick_no_cert", c.T("Sick leave without certificate (hours)"), "", ""), num("abs_absent", c.T("Absence (hours)"), "", ""), num("abs_late", c.T("Lateness (hours)"), "", "")},
		"absLine":     c.T(":h hours = :d days counted. ≤4 days 100%, ≤8 90%, ≤12 80%, then −10% per day.", "h", conv.NumberClean(res.Absence.Hours), "d", conv.NumberClean(res.Absence.Days)),
		"warnWritten": num("warn_written", c.T("Written warnings (−2 each)"), "", "1"), "warnFinal": num("warn_final", c.T("Final warnings (−5 each)"), "", "1"), "note": conv.Str(in["note"]),
	}
	if saved != nil {
		by := "—"
		if !saved.Nil("calculated_by_name") {
			by = saved.Str("calculated_by_name")
		}
		view["savedLine"] = c.T("Saved score :s on :d by :n", "s", conv.NF(saved.Float("score"), 2), "d", app.FormatDate(c, saved["calculated_at"], "j M Y H:i"), "n", by)
	}
	_ = time.Now
	c.View("management/kpi-calc/show", view)
}

func kcExport(c *app.Ctx) {
	c.Authorize("kpi_calculator", "export")
	year := kcYear(c)
	app.LogActivity(c, app.ActivityEntry{Action: "export", Type: "kpi_score", Text: "Exported the :year KPI scores", Params: []any{"year", year}, NoNotify: true})
	var rows [][]any
	for _, r := range kcList(c, year, "") {
		grp := ""
		if g := r.Str("emp_group"); g != "" {
			grp = g
			if l, ok := groupLabels[g]; ok {
				grp = l
			}
		}
		score := "0.00"
		if !r.Nil("score") {
			score = conv.NumberFormat(r.Float("score"), 2, ".", "")
		}
		rows = append(rows, []any{r["name"], r["department_name"], r["role_name"], year, r["month"], grp, score, r["calculated_at"], r["calculated_by_name"]})
	}
	c.XlsxDownload(fmt.Sprintf("kpi-scores-%d.xlsx", year), []string{"Name", "Department", "Role", "Year", "Up to month", "Group", "Score", "Calculated at", "Calculated by"}, rows)
}
