package crm

import (
	"html/template"
	"net/http"
	"net/url"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

// ---------------------------------------------------------------- stages (StageController)
// Which opportunity stages each department can use, and the colour of each stage.
// The stages and their names are fixed; nobody edits those. Whole-company roles set colours and any department;
// a BU Manager sets only their own department.

func stagesIndex(c *app.Ctx) {
	c.Authorize("crm_stages", "view")
	u := c.MustUser()
	deps := departments()
	canSet := c.Can("crm_stages", "edit")
	var mine []string
	if seesAll(u) {
		for _, d := range deps {
			mine = append(mine, d.Value)
		}
	} else if u.DepartmentID != 0 {
		mine = []string{php.Str(u.DepartmentID)}
	}
	hidden := map[string]bool{}
	for _, r := range db.On("crm").Select("SELECT stage, department_id FROM stage_hidden") {
		hidden[r.Str("stage")+"|"+r.Str("department_id")] = true
	}
	var editable []string
	if canSet {
		editable = mine
	}
	type srow struct {
		Code, Color, Mobile, Label, Badge string
		Use                               map[string]bool
	}
	colors := stageColors(c)
	mobile := stageMobileNames()
	var rows []srow
	for _, s := range stagesDef {
		use := map[string]bool{}
		for _, d := range deps {
			use[d.Value] = !hidden[s.Code+"|"+d.Value]
		}
		rows = append(rows, srow{Code: s.Code, Color: colors[s.Code], Mobile: mobile[s.Code], Label: stageLabel(c, s.Code), Badge: uiStageBadge(c, s.Code), Use: use})
	}
	ed := map[string]bool{}
	for _, e := range editable {
		ed[e] = true
	}
	c.View("crm/stages", app.M{"title": c.T("Opportunity stages"), "stages": rows, "deps": deps, "editable": ed, "anyEdit": len(editable) > 0 || (canSet && seesAll(u)),
		"canColor": canSet && seesAll(u), "tabs": settingsTabs(c)})
}

func stagesSave(c *app.Ctx) {
	c.Authorize("crm_stages", "edit")
	u := c.MustUser()
	deps := departments()
	all := seesAll(u)
	var mine []string
	if all {
		for _, d := range deps {
			mine = append(mine, d.Value)
		}
	} else if u.DepartmentID != 0 {
		mine = []string{php.Str(u.DepartmentID)}
	}
	if all {
		mob := app.InMap(c.InputAny("mobile"))
		for _, code := range mob.Keys {
			name := mob.Vals[code]
			n := strings.TrimSpace(stripTags(app.InStr(name)))
			if isStage(code) {
				if php.Len(n) > 24 {
					app.Invalid("mobile", c.T("A mobile name can have at most 24 characters."))
				}
				var v any
				if n != "" {
					v = n
				}
				db.On("crm").Exec("UPDATE crm_stages SET mobile_name = ? WHERE code = ?", v, code)
			}
		}
		cm := app.InMap(c.InputAny("color"))
		for _, code := range cm.Keys {
			hex := cm.Vals[code]
			if isStage(code) && hex6.MatchString(app.InStr(hex)) {
				db.On("crm").Exec("UPDATE crm_stages SET color = ? WHERE code = ?", strings.ToLower(app.InStr(hex)), code)
			}
		}
	}
	posted := app.InMap(c.InputAny("use")) // use[DEPT][CODE] = 1 when the department uses the stage
	for _, dep := range mine {
		d, _ := posted.Get(dep)
		var use []string
		dm := app.InMap(d)
		for _, k := range dm.Keys {
			if isStage(k) && !php.Empty(dm.Vals[k]) {
				use = append(use, k)
			}
		}
		if len(use) == 0 {
			name, _ := app.OptLabel(deps, dep)
			if name == "" {
				name = dep
			}
			app.Invalid("use", c.T("Every department needs at least one stage (:dept).", "dept", name))
		}
		db.On("crm").Exec("DELETE FROM stage_hidden WHERE department_id = ?", php.Int(dep))
		for _, code := range stageCodes() {
			if !inStrs(code, use) {
				db.On("crm").Exec("INSERT INTO stage_hidden (stage, department_id, updated_by) VALUES (?, ?, ?)", code, php.Int(dep), u.ID)
			}
		}
	}
	forgetStageCache(c)
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "stage", Label: "Opportunity stages", Text: "Updated the opportunity stage settings", NoNotify: true, Module: "crm"})
	c.Flash("success", c.T("Stage settings saved."))
	c.Redirect("/crm/settings/stages")
}

func stripTags(s string) string {
	var b strings.Builder
	in := false
	for _, r := range s {
		switch {
		case r == '<':
			in = true
		case r == '>' && in:
			in = false
		case !in:
			b.WriteRune(r)
		}
	}
	return b.String()
}

// ---------------------------------------------------------------- sales targets (TargetController)
// Yearly targets (per quarter and total) and the actuals saved for each year. Admins pick any department, BU Managers set their own.

// targetDepartment: the department being looked at: whole-company roles choose, everyone else gets their own.
func targetDepartment(c *app.Ctx) int {
	u := c.MustUser()
	deps := departments()
	if seesAll(u) {
		pick := php.Int(c.Input("department", "0"))
		if optHas(deps, php.Str(pick)) {
			return pick
		}
		if len(deps) > 0 {
			return php.Int(deps[0].Value)
		}
		return 0
	}
	if u.DepartmentID == 0 {
		app.Abort(http.StatusForbidden, c.T("You are not part of a department."))
	}
	return u.DepartmentID
}

func targetYear(c *app.Ctx) int {
	y := php.Int(c.Input("year", php.Date("Y", php.Now())))
	if y >= 2000 && y <= 2100 {
		return y
	}
	return php.Now().Year()
}

// targetsMoved: sales targets used to live under CRM settings.
func targetsMoved(c *app.Ctx) {
	q := url.Values{}
	for _, k := range []string{"year", "department"} {
		if v := c.Query(k); v != "" {
			q.Set(k, v)
		}
	}
	to := "/crm/targets"
	if len(q) > 0 {
		to += "?" + q.Encode()
	}
	c.Redirect(to)
}

func targetsIndex(c *app.Ctx) {
	c.Authorize("crm_targets", "view")
	dep := targetDepartment(c)
	year := targetYear(c)
	deps := departments()
	row := targetRow(dep, year)
	u := c.MustUser()
	history := db.On("crm").Select("SELECT year, total, a1, a2, a3, a4, actuals_saved_at FROM sales_targets WHERE department_id = ? AND actuals_saved_at IS NOT NULL ORDER BY year DESC LIMIT 6", dep)
	live := targetLive(dep, year)

	num := func(v any) string {
		if v == nil || php.Str(v) == "" {
			return ""
		}
		return strings.TrimRight(strings.TrimRight(php.NumberFormat(php.Float(v), 2, ".", ""), "0"), ".")
	}
	pct := func(a, t float64) (int, bool) {
		if t > 0 {
			return int(phpRound(a/t*100, 0)), true
		}
		return 0, false
	}
	type qrow struct {
		Q                           int
		Months, Target, Saved, Live string
		Meter                       template.HTML
		HasMeter                    bool
	}
	var qs []qrow
	months := []string{"Jan–Mar", "Apr–Jun", "Jul–Sep", "Oct–Dec"}
	qsum := 0.0
	for q := 1; q <= 4; q++ {
		t := row.Float("q" + php.Str(q))
		qsum += t
		x := qrow{Q: q, Months: months[q-1], Target: num(t), Saved: num(row["a"+php.Str(q)]), Live: php.NF(live[q], 2)}
		if p, ok := pct(live[q], t); ok {
			x.Meter, x.HasMeter = meterHTML(c, float64(p), php.Str(p)+"%"), true
		}
		qs = append(qs, x)
	}
	liveT := live[1] + live[2] + live[3] + live[4]
	yearTarget := row.Float("total")
	if yearTarget == 0 {
		yearTarget = qsum
	}
	var yearMeter template.HTML
	if p, ok := pct(liveT, yearTarget); ok {
		yearMeter = meterHTML(c, float64(p), php.Str(p)+"%")
	}
	savedTotal := "—"
	if row["a1"] != nil {
		savedTotal = php.NF(row.Float("a1")+row.Float("a2")+row.Float("a3")+row.Float("a4"), 2)
	}
	type hrow struct {
		Year, Total, Actual, URL string
		A                        []string
		Meter                    template.HTML
		HasMeter                 bool
	}
	var hs []hrow
	for _, hh := range history {
		act := hh.Float("a1") + hh.Float("a2") + hh.Float("a3") + hh.Float("a4")
		x := hrow{Year: hh.Str("year"), Total: php.NF(hh.Float("total"), 2), Actual: php.NF(act, 2), URL: c.URL("/crm/targets", "year", hh["year"], "department", dep)}
		for _, k := range []string{"a1", "a2", "a3", "a4"} {
			if hh[k] == nil {
				x.A = append(x.A, "—")
			} else {
				x.A = append(x.A, php.NF(hh.Float(k), 2))
			}
		}
		if p, ok := pct(act, hh.Float("total")); ok {
			x.Meter, x.HasMeter = meterHTML(c, float64(p), php.Str(p)+"%"), true
		}
		hs = append(hs, x)
	}
	source := targetSource(dep)
	erp := erpID(dep)
	cur := baseCurrency()
	var note string
	if source == "accounting" {
		if erp != 0 {
			note = c.T("Achieved = invoiced revenue of ERP department #:id from Accounting.", "id", erp)
		} else {
			note = c.T("Achieved = invoiced revenue of the whole company from Accounting.")
		}
	} else {
		note = c.T("Achieved = opportunities won in the CRM. Set this department's ERP department under Departments to use Accounting revenue instead.")
	}
	savedAt := c.T("Actuals have not been saved for this year yet.")
	if row["actuals_saved_at"] != nil {
		savedAt = c.T("Actuals last saved :time.", "time", app.TimeAgo(c, row["actuals_saved_at"]))
	}
	depName, _ := app.OptLabel(deps, php.Str(dep))
	c.View("crm/targets", app.M{"title": c.T("Sales targets"), "deps": deps, "dep": php.Str(dep), "depName": depName, "year": year, "cur": cur,
		"canEdit": c.Can("crm_targets", "edit") && (seesAll(u) || dep == u.DepartmentID), "pickDept": seesAll(u), "source": source, "note": note,
		"prev": c.URL("/crm/targets", "year", year-1, "department", dep), "next": c.URL("/crm/targets", "year", year+1, "department", dep),
		"rows": qs, "total": num(row["total"]), "qsum": php.NF(qsum, 2), "liveT": php.NF(liveT, 2), "savedTotal": savedTotal, "yearMeter": yearMeter,
		"savedAt": savedAt, "history": hs})
}

func targetMoney(c *app.Ctx, key string) float64 {
	v := strings.ReplaceAll(strings.TrimSpace(c.Input(key, "0")), ",", "")
	if v == "" {
		return 0
	}
	if !app.IsNumeric(v) || php.Float(v) < 0 || php.Float(v) > 1e13 {
		app.Invalid(key, c.T("Enter an amount of zero or more."))
	}
	return phpRound(php.Float(v), 2)
}

func targetsSave(c *app.Ctx) {
	c.Authorize("crm_targets", "edit")
	u := c.MustUser()
	dep := targetDepartment(c)
	year := targetYear(c)
	if !seesAll(u) && dep != u.DepartmentID {
		app.Abort(http.StatusForbidden, c.T("You can only set targets for your own department."))
	}
	action := c.Input("action", "targets")
	row := targetRow(dep, year)
	exists := row["id"] != nil
	var data db.Cols
	var msg string
	if action == "targets" {
		q := [5]float64{}
		sum := 0.0
		for i := 1; i <= 4; i++ {
			q[i] = targetMoney(c, "q"+php.Str(i))
			sum += q[i]
		}
		total := targetMoney(c, "total")
		if total <= 0 {
			total = sum
		}
		data = db.Cols{{Name: "q1", Value: q[1]}, {Name: "q2", Value: q[2]}, {Name: "q3", Value: q[3]}, {Name: "q4", Value: q[4]}, {Name: "total", Value: total},
			{Name: "updated_by", Value: u.ID}, {Name: "updated_at", Value: php.NowString()}}
		msg = c.T("Targets for :year saved.", "year", year)
	} else {
		// actuals: take the live figures, or the numbers typed in the "saved" column
		live := targetLive(dep, year)
		data = db.Cols{}
		for i := 1; i <= 4; i++ {
			var v float64
			if action == "actuals_live" {
				v = phpRound(live[i], 2)
			} else {
				v = targetMoney(c, "a"+php.Str(i))
			}
			data = append(data, db.Col{Name: "a" + php.Str(i), Value: v})
		}
		data = append(data, db.Col{Name: "actuals_saved_at", Value: php.NowString()}, db.Col{Name: "actuals_saved_by", Value: u.ID}, db.Col{Name: "updated_by", Value: u.ID}, db.Col{Name: "updated_at", Value: php.NowString()})
		msg = c.T("Actuals for :year saved.", "year", year)
	}
	if exists {
		db.On("crm").Update("sales_targets", data, db.Cols{{Name: "id", Value: row["id"]}})
	} else {
		db.On("crm").Insert("sales_targets", append(data, db.Col{Name: "department_id", Value: dep}, db.Col{Name: "year", Value: year}, db.Col{Name: "created_by", Value: u.ID}), "")
	}
	dn, _ := app.OptLabel(departments(), php.Str(dep))
	if dn == "" {
		dn = php.Str(dep)
	}
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "sales_target", Label: php.Str(year) + " · " + dn, Text: "Updated sales targets for :year", Params: []any{"year", year},
		Props: app.M{"department_id": dep}, NoNotify: true, Module: "crm"})
	c.Flash("success", msg)
	c.Redirect("/crm/targets?year=" + php.Str(year) + "&department=" + php.Str(dep))
}

// ---------------------------------------------------------------- dropdown search (SearchController)
// Feeds the searchable dropdowns (leads, contacts, opportunities): 20 at a time, only what the person may see,
// so a long list can be searched and scrolled without loading it all.
//   GET /crm/search/leads?q=siam&page=2        (contacts and opportunities can be narrowed with &lead=ID)

const searchPerPage = 20

func likePattern(q string) string {
	return "%" + strings.NewReplacer(`\`, `\\`, "%", `\%`, "_", `\_`).Replace(q) + "%"
}

func searchFind(c *app.Ctx) {
	kind := c.Param("kind")
	if kind == "erp-products" {
		searchErpProducts(c)
	}
	var perm, typ string
	switch kind {
	case "leads":
		perm, typ = "crm_leads", "lead"
	case "contacts":
		perm, typ = "crm_contacts", "contact"
	case "opportunities":
		perm, typ = "crm_opportunities", "opportunity"
	case "projects":
		perm, typ = "crm_projects", "project"
	default:
		app.Abort(http.StatusNotFound)
	}
	c.Authorize(perm, "view")
	q := strings.TrimSpace(c.Query("q"))
	page := max(1, php.Int(c.Query("page", "1")))
	lead := php.Int(c.Query("lead", "0"))
	vs, vp := visible(c.MustUser(), "t", typ)
	where := []string{"t.deleted_at IS NULL", vs}
	params := append([]any{}, vp...)
	like := likePattern(q)
	var sel, from, order string
	switch kind {
	case "leads":
		sel, from, order = "t.id, t.name_en AS label, t.name_th AS sub, t.image AS img", "leads t", "t.name_en, t.id"
		if q != "" {
			where = append(where, "(t.name_en ILIKE ? OR t.name_th ILIKE ? OR t.code ILIKE ? OR t.tax_id ILIKE ?)")
			params = append(params, like, like, like, like)
		}
	case "contacts":
		sel = "t.id, trim(coalesce(t.salutation || ' ', '') || t.name_en) AS label, concat_ws(' · ', l.name_en, t.job_title) AS sub, t.avatar AS img"
		from, order = "contacts t LEFT JOIN leads l ON l.id = t.lead_id", "t.name_en, t.id"
		if lead != 0 {
			where = append(where, "t.lead_id = ?")
			params = append(params, lead)
		}
		if q != "" {
			where = append(where, "(t.name_en ILIKE ? OR t.name_th ILIKE ? OR t.email ILIKE ? OR l.name_en ILIKE ?)")
			params = append(params, like, like, like, like)
		}
	case "projects":
		sel, from, order = "t.id, t.name AS label, t.code AS sub, NULL AS img", "projects t", "(t.status IN ('DONE','CANCELLED')), t.name, t.id"
		if q != "" {
			where = append(where, "(t.name ILIKE ? OR t.code ILIKE ?)")
			params = append(params, like, like)
		}
	default:
		sel = "t.id, t.name AS label, concat_ws(' · ', t.code, l.name_en) AS sub, NULL AS img"
		from, order = "opportunities t LEFT JOIN leads l ON l.id = t.lead_id", "t.id DESC"
		if lead != 0 {
			where = append(where, "t.lead_id = ?")
			params = append(params, lead)
		}
		if q != "" {
			where = append(where, "(t.name ILIKE ? OR t.code ILIKE ? OR l.name_en ILIKE ?)")
			params = append(params, like, like, like)
		}
	}
	rows := db.On("crm").Select("SELECT "+sel+" FROM "+from+" WHERE "+strings.Join(where, " AND ")+" ORDER BY "+order+" LIMIT "+php.Str(searchPerPage+1)+" OFFSET "+php.Str((page-1)*searchPerPage), params...)
	more := len(rows) > searchPerPage
	if more {
		rows = rows[:searchPerPage]
	}
	items := []app.M{}
	for _, r := range rows {
		var sub any
		if r.Str("sub") != "" {
			sub = r.Str("sub")
		}
		var img any
		if u := c.UploadURL(r["img"]); u != "" {
			img = u
		}
		items = append(items, app.M{"id": r.Int("id"), "label": r.Str("label"), "sub": sub, "img": img})
	}
	c.JSON(app.M{"items": items, "more": more})
}

// searchErpProducts: products of Accounting (copied from the ERP) for the opportunity form; nothing when Accounting is off.
func searchErpProducts(c *app.Ctx) {
	c.Authorize("crm_opportunities", "view")
	var r app.M
	if app.ModuleUsable("accounting") {
		if v, ok := app.Hook("product.search", "accounting", func(fn any) any {
			if f, ok := fn.(app.HookSearch); ok {
				return f(c, strings.TrimSpace(c.Query("q")), max(1, php.Int(c.Query("page", "1"))), searchPerPage)
			}
			return nil
		}).(app.M); ok {
			r = v
		}
	}
	items := []app.M{}
	for _, p := range toMaps(r["items"]) {
		sub := strings.Trim(php.Str(p["part_number"])+func() string {
			if p["price"] != nil {
				return " · " + php.NF(php.Float(p["price"]), 2)
			}
			return ""
		}(), " ·")
		var subv any
		if sub != "" {
			subv = sub
		}
		items = append(items, app.M{"id": p["id"], "label": p["name"], "sub": subv, "img": nil,
			"extra": app.M{"no": p["part_number"], "name": p["name"], "price": p["price"]}})
	}
	c.JSON(app.M{"items": items, "more": php.Bool(r["more"])})
}
