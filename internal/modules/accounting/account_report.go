package accounting

import (
	"html/template"
	"regexp"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

/*
Accounting → Management → Account report (AccountReportController): one amount per account, department, month and type.
Filled mainly by import (CSV or Excel); a line that is already there is skipped, never added twice.
BU managers view, add and import; Management → Account report draws the charts from it (hook 'account.report').
*/

var arKinds = [][2]string{{"income", "Income"}, {"expense", "Expenses"}}
var arTypes = [][2]string{
	{"central_cost", "Central cost"}, {"cogs", "Cost of goods sold"}, {"deposit", "Deposit"}, {"domestic_revenue", "Domestic revenue"},
	{"international_revenue", "International revenue"}, {"operating_expenses", "Operating expenses"}, {"revenue", "Revenue"},
}

func pairsOpts(c *app.Ctx, p [][2]string) []app.Opt {
	out := make([]app.Opt, len(p))
	for i, x := range p {
		out[i] = app.Opt{Value: x[0], Label: c.T(x[1])}
	}
	return out
}

func pairLabel(c *app.Ctx, p [][2]string, k string) (string, bool) {
	for _, x := range p {
		if x[0] == k {
			return c.T(x[1]), true
		}
	}
	return "", false
}

func arMonths(c *app.Ctx) []app.Opt {
	names := []string{"January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"}
	out := make([]app.Opt, 12)
	for i, n := range names {
		out[i] = app.Opt{Value: php.Str(i + 1), Label: c.T(n)}
	}
	return out
}

// appDepartmentNames: department names of the app (User Management → Departments).
func appDepartmentNames() []string { return column(db.Select("SELECT name FROM departments WHERE deleted_at IS NULL ORDER BY name"), "name") }

func arYears() []app.Opt {
	var out []app.Opt
	for _, r := range acc().Select("SELECT DISTINCT year FROM account_reports WHERE deleted_at IS NULL ORDER BY year DESC") {
		out = append(out, app.Opt{Value: r.Str("year"), Label: r.Str("year")})
	}
	return out
}

func arDepartments() []app.Opt {
	var out []app.Opt
	for _, r := range acc().Select("SELECT DISTINCT department FROM account_reports WHERE deleted_at IS NULL ORDER BY department") {
		out = append(out, app.Opt{Value: r.Str("department"), Label: r.Str("department")})
	}
	return out
}

func optHas(o []app.Opt, v string) bool { _, ok := app.OptLabel(o, v); return ok }

// arPicked: year and department picked at the top of the page (only values that exist).
func arPicked(c *app.Ctx) (string, string) {
	y, d := c.Query("year"), c.Query("department")
	if !optHas(arYears(), y) {
		y = ""
	}
	if !optHas(arDepartments(), d) {
		d = ""
	}
	return y, d
}

// arAppDepartment: "sales" → "Sales" when the app has that department, so the same department is never two names.
func arAppDepartment(name string) string {
	for _, d := range appDepartmentNames() {
		if strings.ToLower(d) == strings.ToLower(name) {
			return d
		}
	}
	return name
}

func arAlreadyStored(data *app.Clean, existing db.Row) bool {
	id := 0
	if existing != nil {
		id = existing.Int("id")
	}
	return acc().Scalar("SELECT 1 FROM account_reports WHERE deleted_at IS NULL AND account = ? AND department = ? AND year = ? AND month = ? AND kind = ? AND type = ? AND id <> ?",
		strings.TrimSpace(data.Str("account")), arAppDepartment(strings.TrimSpace(data.Str("department"))), data.Int("year"), data.Int("month"), data.Str("kind"), data.Str("type"), id) != nil
}

var arKeyRe = regexp.MustCompile(`[^a-z0-9ก-๙]+`)

func arKey(v string) string { return strings.Trim(arKeyRe.ReplaceAllString(strings.ToLower(strings.TrimSpace(v)), "_"), "_") }

var accountReports = &app.Resource{
	Resource: "accounting_reports", Table: "account_reports", Type: "account_report", Singular: "account line", Plural: "account report",
	Base: "/accounting/account-report", Icon: "chart", Module: "accounting", Conn: "accounting", PerPage: 20,
	OrderBy:    "t.year DESC, t.month DESC, t.department, t.account",
	Searchable: []string{"t.account", "t.name", "t.department"},
	// Lines are company figures, not owned by anyone: everyone with the permission sees all of them.
	ScopeSQL: func(c *app.Ctx) (string, []any) { return "TRUE", nil },
	// BU managers import too (the role's import box decides), not only administrators.
	MayImport: func(c *app.Ctx) bool { return c.Can("accounting_reports", "import") },
	ExtraMeta: func(c *app.Ctx) app.M { return app.M{"selectable": c.Can("accounting_reports", "delete")} },
	Label:     func(c *app.Ctx, row db.Row) string { return row.Str("account") + " · " + row.Str("name") },
	Fields: func(c *app.Ctx, row db.Row) []app.Field {
		deps := appDepartmentNames()
		ex := "Sales"
		if len(deps) > 0 {
			ex = deps[0]
		}
		year := php.Date("Y", php.Now())
		f := []app.Field{
			{Name: "account", Label: c.T("Account"), Rules: "required|max:60", Example: "410100"},
			{Name: "name", Label: c.T("Name"), Rules: "required|max:200", Example: "Sales – local customers"},
			{Name: "amount", Label: c.T("Amount"), Type: "number", Rules: "required|numeric", Example: "125000.00", Display: func(r db.Row) any { return php.NF(php.Coalesce(r["amount"], 0), 2) }},
			{Name: "department", Label: c.T("Department"), Rules: "required|max:120", Example: ex, Help: c.T("The department name as in User Management → Departments.")},
			{Name: "kind", Label: c.T("Income / Expenses"), Type: "select", Options: pairsOpts(c, arKinds), Rules: "required"},
			{Name: "year", Label: c.T("Year"), Rules: "required|integer|max:4", Display: func(r db.Row) any { return php.Str(php.Int(r["year"])) }, Default: year, Example: year},
			{Name: "month", Label: c.T("Month"), Type: "select", Options: arMonths(c), Rules: "required", Default: php.Str(int(php.Now().Month()))},
			{Name: "type", Label: c.T("Type"), Type: "select", Options: pairsOpts(c, arTypes), Rules: "required", Span: 2},
		}
		if row != nil {
			f = append(f,
				app.Field{Name: "created_by_name", Label: c.T("Created by"), Readonly: true, NoImport: true, NoTable: true, Display: func(r db.Row) any {
					return strings.TrimSpace(php.Str(php.Coalesce(r["created_by_name"], "—")) + " · " + app.FormatDate(c, r["created_at"], "d M Y H:i"))
				}},
				app.Field{Name: "updated_by_name", Label: c.T("Updated by"), Readonly: true, NoImport: true, NoTable: true, Display: func(r db.Row) any {
					return strings.TrimSpace(php.Str(php.Coalesce(r["updated_by_name"], "—")) + " · " + app.FormatDate(c, r["updated_at"], "d M Y H:i"))
				}})
		}
		return f
	},
	Columns: func(c *app.Ctx) []app.Column {
		kindTone := map[string]string{"income": "bg-emerald-50 text-emerald-800", "expense": "bg-signal-50 text-signal-700"}
		months := arMonths(c)
		return []app.Column{
			{Key: "account", Label: c.T("Account"), Primary: true, Sort: "t.account", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return h(`<a href="` + c.URL("/accounting/account-report/"+r.Str("id")) + `" class="font-medium tabular-nums hover:text-signal-700">` + app.E(r["account"]) + `</a>`)
			}},
			{Key: "name", Label: c.T("Name"), Sort: "t.name", Render: func(c *app.Ctx, r db.Row) template.HTML { return h(app.E(r["name"])) }},
			{Key: "amount", Label: c.T("Amount"), Sort: "t.amount", Render: func(c *app.Ctx, r db.Row) template.HTML {
				cls := "text-emerald-700"
				if r.Str("kind") == "expense" {
					cls = "text-signal-700"
				}
				return h(`<span class="block text-right font-medium tabular-nums ` + cls + `">` + php.NF(r["amount"], 2) + `</span>`)
			}},
			{Key: "department", Label: c.T("Department"), Sort: "t.department", Render: func(c *app.Ctx, r db.Row) template.HTML { return h(app.E(r["department"])) }},
			{Key: "kind", Label: c.T("Income / Expenses"), Sort: "t.kind", Render: func(c *app.Ctx, r db.Row) template.HTML {
				l, ok := pairLabel(c, arKinds, r.Str("kind"))
				if !ok {
					l = r.Str("kind")
				}
				return h(`<span class="badge ` + kindTone[r.Str("kind")] + `">` + app.E(l) + `</span>`)
			}},
			{Key: "year", Label: c.T("Year"), Sort: "t.year", Render: func(c *app.Ctx, r db.Row) template.HTML { return h(`<span class="tabular-nums">` + php.Str(r.Int("year")) + `</span>`) }},
			{Key: "month", Label: c.T("Month"), Sort: "t.month", Render: func(c *app.Ctx, r db.Row) template.HTML {
				l, ok := app.OptLabel(months, php.Str(r.Int("month")))
				if !ok {
					l = r.Str("month")
				}
				return h(app.E(l))
			}},
			{Key: "type", Label: c.T("Type"), Sort: "t.type", Render: func(c *app.Ctx, r db.Row) template.HTML {
				l, ok := pairLabel(c, arTypes, r.Str("type"))
				if !ok {
					l = r.Str("type")
				}
				return h(app.E(l))
			}},
			{Key: "created", Label: c.T("Created by"), Sort: "t.created_at", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return h(app.E(php.Coalesce(r["created_by_name"], "—")) + `<span class="block text-xs text-steel">` + app.E(app.FormatDate(c, r["created_at"], "d M Y H:i")) + `</span>`)
			}},
			{Key: "updated", Label: c.T("Updated by"), Sort: "t.updated_at", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return h(app.E(php.Coalesce(r["updated_by_name"], "—")) + `<span class="block text-xs text-steel">` + app.E(app.FormatDate(c, r["updated_at"], "d M Y H:i")) + `</span>`)
			}},
		}
	},
	Filters: func(c *app.Ctx) []app.Filter {
		return []app.Filter{
			{Name: "year", Label: c.T("Year"), Column: "t.year", Options: arYears()},
			{Name: "department", Label: c.T("Department"), Column: "t.department", Options: arDepartments()},
			{Name: "kind", Label: c.T("Income / Expenses"), Column: "t.kind", Options: pairsOpts(c, arKinds)},
			{Name: "type", Label: c.T("Type"), Column: "t.type", Options: pairsOpts(c, arTypes)},
		}
	},
	// Year and department filter right at the top of the page, the totals for them below it.
	TopExtra: func(c *app.Ctx) template.HTML {
		y, d := arPicked(c)
		keep := ""
		for _, k := range []string{"q", "kind", "type", "sort", "dir", "per_page"} {
			if c.QueryHas(k) && c.Query(k) != "" {
				keep += `<input type="hidden" name="` + k + `" value="` + app.E(c.Query(k)) + `">`
			}
		}
		years := append([]app.Opt{{Value: "", Label: c.T("All years")}}, arYears()...)
		depts := append([]app.Opt{{Value: "", Label: c.T("All departments")}}, arDepartments()...)
		clear := ""
		if y != "" || d != "" {
			clear = `<a href="` + app.E(c.URL("/accounting/account-report")) + `" class="btn-ghost">` + app.E(c.T("Clear")) + `</a>`
		}
		return h(`<form method="GET" class="mt-4 flex flex-wrap items-end gap-3 rounded-xl bg-white p-3 shadow-sm ring-1 ring-graphite-900/8" aria-label="` + app.E(c.T("Year and department")) + `">` + keep +
			`<div class="w-36"><label class="label !mb-1 text-xs text-steel">` + app.E(c.T("Year")) + `</label>` + string(app.SelectField(c, "year", years, y, app.M{"placeholder": c.T("All years"), "submit": true, "aria": c.T("Year"), "search": false})) + `</div>` +
			`<div class="w-60"><label class="label !mb-1 text-xs text-steel">` + app.E(c.T("Department")) + `</label>` + string(app.SelectField(c, "department", depts, d, app.M{"placeholder": c.T("All departments"), "submit": true, "aria": c.T("Department")})) + `</div>` +
			clear + `</form>` + string(c.Partial("crm/stats", app.M{"cards": arTotals(c)})))
	},
	AlreadyStored: func(c *app.Ctx, data *app.Clean, existing db.Row) bool { return arAlreadyStored(data, existing) },
	Prepare: func(c *app.Ctx, data *app.Clean, existing db.Row) *app.Clean {
		for _, k := range []string{"account", "name", "department"} {
			data.Set(k, strings.TrimSpace(data.Str(k)))
		}
		data.Set("department", arAppDepartment(data.Str("department")))
		data.Set("year", data.Int("year"))
		data.Set("month", data.Int("month"))
		data.Set("amount", phpRound(php.Float(data.Get("amount")), 2))
		if data.Int("year") < 2000 || data.Int("year") > 2100 {
			app.Invalid("year", c.T("Enter a year between 2000 and 2100."))
		}
		if arAlreadyStored(data, existing) {
			app.Invalid("account", c.T("This line is already in the report (same account, department, year, month, income / expenses and type)."))
		}
		u := c.MustUser()
		if existing == nil {
			data.Set("created_by", u.ID)
			data.Set("created_by_name", u.Name)
		}
		data.Set("updated_by", u.ID)
		data.Set("updated_by_name", u.Name)
		return data
	},
	// Import accepts what accountants type: 1,250.00 / (500) / Jan / Expenses / "Cost of goods solds" …
	ImportDefaults: func(c *app.Ctx, raw app.CsvRow) app.CsvRow {
		columns := map[string]string{"income_expenses": "kind", "income_expense": "kind", "department_name": "department", "account_no": "account", "account_number": "account",
			"account_name": "name", "created_by": "", "updated_by": ""}
		out := app.CsvRow{Vals: map[string]string{}}
		for _, col := range raw.Keys {
			v := raw.Vals[col]
			ck := arKey(col)
			to, mapped := columns[ck]
			if !mapped {
				to = ck
			}
			if to == col {
				if _, ok := out.Vals[col]; !ok {
					out.Keys = append(out.Keys, col)
				}
				out.Vals[col] = v
				continue
			}
			if to != "" {
				if _, ok := out.Vals[to]; !ok {
					out.Keys = append(out.Keys, to)
					out.Vals[to] = v
				}
			}
		}
		raw = out
		if a, ok := raw.Vals["amount"]; ok {
			a = strings.NewReplacer(",", "", " ", "", "฿", "", "THB", "", "thb", "").Replace(strings.TrimSpace(a))
			if m := regexp.MustCompile(`^\((.*)\)$`).FindStringSubmatch(a); m != nil {
				a = "-" + m[1]
			}
			raw.Vals["amount"] = a
		}
		if k, ok := raw.Vals["kind"]; ok {
			kk := arKey(k)
			switch {
			case inStrs(kk, []string{"income", "incomes", "revenue", "in", "i", "รายได้", "รายรับ"}):
				raw.Vals["kind"] = "income"
			case inStrs(kk, []string{"expense", "expenses", "cost", "out", "e", "ค่าใช้จ่าย", "รายจ่าย"}):
				raw.Vals["kind"] = "expense"
			}
		}
		if t, ok := raw.Vals["type"]; ok {
			kk := arKey(t)
			alias := map[string]string{"cost_of_goods_sold": "cogs", "cost_of_goods_solds": "cogs", "cost_of_good_sold": "cogs", "operating_expense": "operating_expenses", "opex": "operating_expenses",
				"central_costs": "central_cost", "domestic": "domestic_revenue", "international": "international_revenue", "deposits": "deposit"}
			if _, known := pairLabel(c, arTypes, kk); known {
				raw.Vals["type"] = kk
			} else if a, ok := alias[kk]; ok {
				raw.Vals["type"] = a
			} else {
				for _, x := range arTypes {
					if arKey(c.T(x[1])) == kk {
						raw.Vals["type"] = x[0]
						break
					}
				}
			}
		}
		if m, ok := raw.Vals["month"]; ok {
			m = strings.TrimSpace(m)
			if m != "" && strings.Trim(m, "0123456789") == "" {
				raw.Vals["month"] = php.Str(php.Int(m))
			} else if n := monthOf(m); n > 0 {
				raw.Vals["month"] = php.Str(n)
			} else {
				for _, o := range arMonths(c) {
					if strings.ToLower(o.Label) == strings.ToLower(m) {
						raw.Vals["month"] = o.Value
						break
					}
				}
			}
		}
		if y, ok := raw.Vals["year"]; ok {
			raw.Vals["year"] = strings.TrimSpace(y)
		}
		return raw
	},
	Intro: func(c *app.Ctx) string { return c.T("Import a CSV or Excel file; lines already in the report are skipped.") },
}

// monthOf is date('n', strtotime('1 '.$m.' 2000')) for English month names and their short forms (0 = not a month).
func monthOf(m string) int {
	l := strings.ToLower(strings.TrimSpace(strings.TrimSuffix(strings.TrimSpace(m), ".")))
	names := []string{"january", "february", "march", "april", "may", "june", "july", "august", "september", "october", "november", "december"}
	for i, n := range names {
		if l == n || (len(l) >= 3 && strings.HasPrefix(n, l) && len(l) == 3) || (l == "sept" && i == 8) {
			return i + 1
		}
	}
	return 0
}

// arTotals: income, expenses and the difference for the year / department picked.
func arTotals(c *app.Ctx) []app.Stat {
	y, d := arPicked(c)
	where := []string{"deleted_at IS NULL"}
	var params []any
	if y != "" {
		where = append(where, "year = ?")
		params = append(params, php.Int(y))
	}
	if d != "" {
		where = append(where, "department = ?")
		params = append(params, d)
	}
	t := acc().First(`SELECT COALESCE(SUM(amount) FILTER (WHERE kind = 'income'), 0) AS income, COALESCE(SUM(amount) FILTER (WHERE kind = 'expense'), 0) AS expense, COUNT(*) AS n
                          FROM account_reports WHERE `+strings.Join(where, " AND "), params...)
	net := t.Float("income") - t.Float("expense")
	sub := php.OrStr(y, c.T("All years")) + " · " + php.OrStr(d, c.T("All departments"))
	netLabel, netTone := c.T("Net profit"), "info"
	if net < 0 {
		netLabel, netTone = c.T("Net loss"), "warn"
	}
	margin := ""
	if t.Float("income") > 0 {
		margin = c.T("Margin :p%", "p", php.NF(net/t.Float("income")*100, 1))
	}
	return []app.Stat{
		{Label: c.T("Total income"), Value: php.NF(t.Float("income"), 2), Sub: sub, Tone: "success", Icon: "chart"},
		{Label: c.T("Total expenses"), Value: php.NF(t.Float("expense"), 2), Sub: sub, Tone: "danger", Icon: "calc"},
		{Label: netLabel, Value: php.NF(net, 2), Sub: margin, Tone: netTone, Icon: "bolt"},
		{Label: c.T("Lines"), Value: php.NF(t.Int("n"), 0), Sub: c.T("Imported or added"), Tone: "neutral", Icon: "doc"},
	}
}

// accountReportSummary is AccountReport::summary ('account.report'): income and expenses by year, by type, by department,
// by month, department × type and the biggest accounts. filters: year (int|nil), department (string|nil).
func accountReportSummary(filters app.M) app.M {
	var years []int
	for _, r := range acc().Select("SELECT DISTINCT year FROM account_reports WHERE deleted_at IS NULL ORDER BY year") {
		years = append(years, r.Int("year"))
	}
	departments := column(acc().Select("SELECT DISTINCT department FROM account_reports WHERE deleted_at IS NULL ORDER BY department"), "department")
	var year, dept any
	fy := php.Int(filters["year"])
	for _, y := range years {
		if y == fy && fy != 0 {
			year = fy
		}
	}
	if fd := php.Str(filters["department"]); inStrs(fd, departments) {
		dept = fd
	}
	// months and the "vs last year" tile need one year: the one picked, else the latest
	focus := php.Now().Year()
	if year != nil {
		focus = year.(int)
	} else if len(years) > 0 {
		focus = years[len(years)-1]
		for _, y := range years {
			focus = max(focus, y)
		}
	}
	sum := "COALESCE(SUM(amount) FILTER (WHERE kind = 'income'), 0)::float AS income, COALESCE(SUM(amount) FILTER (WHERE kind = 'expense'), 0)::float AS expense"
	where := func(byYear, byDept bool) (string, []any) {
		w := []string{"deleted_at IS NULL"}
		var p []any
		if byYear && year != nil {
			w = append(w, "year = ?")
			p = append(p, year)
		}
		if byDept && dept != nil {
			w = append(w, "department = ?")
			p = append(p, dept)
		}
		return strings.Join(w, " AND "), p
	}
	with := func(p []any, x ...any) []any { return append(append([]any{}, p...), x...) }

	w, p := where(true, true)
	totals := acc().First("SELECT "+sum+", COUNT(*) AS lines FROM account_reports WHERE "+w, p...)
	w, p = where(false, true)
	byYear := acc().Select("SELECT year, "+sum+" FROM account_reports WHERE "+w+" GROUP BY year ORDER BY year", p...)
	focusRow := acc().First("SELECT "+sum+" FROM account_reports WHERE "+w+" AND year = ?", with(p, focus)...)
	prevRow := acc().First("SELECT "+sum+", COUNT(*) AS lines FROM account_reports WHERE "+w+" AND year = ?", with(p, focus-1)...)
	byMonth := acc().Select("SELECT month, "+sum+" FROM account_reports WHERE "+w+" AND year = ? GROUP BY month ORDER BY month", with(p, focus)...)
	prevMonth := acc().Select("SELECT month, "+sum+" FROM account_reports WHERE "+w+" AND year = ? GROUP BY month ORDER BY month", with(p, focus-1)...)
	w, p = where(true, true)
	byType := acc().Select("SELECT type, kind, SUM(amount)::float AS amount FROM account_reports WHERE "+w+" GROUP BY type, kind ORDER BY SUM(amount) DESC", p...)
	top := acc().Select(`SELECT * FROM (SELECT account, MIN(name) AS name, kind, SUM(amount)::float AS amount, ROW_NUMBER() OVER (PARTITION BY kind ORDER BY SUM(amount) DESC) AS rn
                             FROM account_reports WHERE `+w+` GROUP BY account, kind) x WHERE rn <= 6 ORDER BY amount DESC`, p...)
	w, p = where(true, false)
	byDept := acc().Select("SELECT department, "+sum+" FROM account_reports WHERE "+w+" GROUP BY department ORDER BY department", p...)
	matrix := acc().Select("SELECT department, type, SUM(amount)::float AS amount FROM account_reports WHERE "+w+" GROUP BY department, type", p...)

	monthsOf := func(rows []db.Row) app.M {
		m := app.M{}
		for i := 1; i <= 12; i++ {
			m[php.Str(i)] = app.M{"income": 0.0, "expense": 0.0}
		}
		for _, r := range rows {
			m[php.Str(r.Int("month"))] = app.M{"income": r.Float("income"), "expense": r.Float("expense")}
		}
		return m
	}
	topIncome, topExpense := []app.M{}, []app.M{}
	for _, r := range top {
		if r.Str("kind") == "income" {
			topIncome = append(topIncome, app.M(r))
		} else if r.Str("kind") == "expense" {
			topExpense = append(topExpense, app.M(r))
		}
	}
	types, kinds := app.M{}, app.M{}
	for _, x := range arTypes {
		types[x[0]] = x[1]
	}
	for _, x := range arKinds {
		kinds[x[0]] = x[1]
	}
	ys := []any{}
	for _, y := range years {
		ys = append(ys, y)
	}
	ds := []any{}
	for _, d := range departments {
		ds = append(ds, d)
	}
	return app.M{
		"years": ys, "departments": ds, "year": year, "department": dept, "focus": focus,
		"types": types, "kinds": kinds,
		"totals":    app.M{"income": totals.Float("income"), "expense": totals.Float("expense"), "lines": totals.Int("lines")},
		"focusYear": app.M{"income": focusRow.Float("income"), "expense": focusRow.Float("expense")},
		"prevYear":  app.M{"income": prevRow.Float("income"), "expense": prevRow.Float("expense"), "lines": prevRow.Int("lines")},
		"byYear":    rowsM(byYear), "byType": rowsM(byType), "byDepartment": rowsM(byDept), "months": monthsOf(byMonth), "prevMonths": monthsOf(prevMonth),
		"matrix":    rowsM(matrix),
		"topIncome": topIncome, "topExpense": topExpense,
	}
}
