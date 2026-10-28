package management

import (
	"html/template"
	"math"
	"sort"
	"strconv"
	"time"

	"acme/internal/app"
	"acme/internal/conv"
)

// shortPlain is the account report's $short (no currency sign).
func shortPlain(v float64) string { return shortMoney("", v) }

// delta is the "▲ +4.2% vs last year" line (nil p: no data last year).
func delta(c *app.Ctx, p *float64, upIsGood bool) template.HTML {
	if p == nil {
		return template.HTML(`<span class="text-steel">` + app.E(c.T("no data last year")) + `</span>`)
	}
	good := *p >= 0
	if !upIsGood {
		good = *p <= 0
	}
	tone := "text-signal-700"
	if good {
		tone = "text-emerald-700"
	}
	arrow := "▼ "
	if *p >= 0 {
		arrow = "▲ +"
	}
	return template.HTML(`<span class="font-medium ` + tone + `">` + arrow + conv.NF(*p, 1) + `%</span> <span class="text-steel">` + app.E(c.T("vs last year")) + `</span>`)
}

func pctChange(now, before float64) *float64 {
	if before == 0 {
		return nil
	}
	v := (now - before) / math.Abs(before) * 100
	return &v
}

func accountReportView(c *app.Ctx, data app.M, view app.M, years []any, deptColor func(string) string) {
	y := data["year"]
	d := data["department"]
	t := asMap(data["totals"])
	income, expense := f(t["income"]), f(t["expense"])
	net := income - expense
	yLabel := c.T("All years")
	if y != nil {
		yLabel = conv.Str(y)
	}
	dLabel := c.T("All departments")
	if d != nil && conv.Str(d) != "" {
		dLabel = conv.Str(d)
	}
	scope := yLabel + " · " + dLabel
	fy, py := asMap(data["focusYear"]), asMap(data["prevYear"])
	view["scope"], view["yLabel"], view["dLabel"] = scope, yLabel, dLabel
	view["filtered"] = y != nil || (d != nil && conv.Str(d) != "")
	view["year"], view["department"] = conv.Str(y), conv.Str(d)

	// filters
	yo := []app.Opt{{Value: "", Label: c.T("All years")}}
	for i := len(years) - 1; i >= 0; i-- {
		yo = append(yo, app.Opt{Value: conv.Str(years[i]), Label: conv.Str(years[i])})
	}
	do := []app.Opt{{Value: "", Label: c.T("All departments")}}
	for _, x := range values(data["departments"]) {
		do = append(do, app.Opt{Value: conv.Str(x), Label: conv.Str(x)})
	}
	view["yearOpts"], view["deptOpts"] = yo, do
	view["lines"] = conv.NF(n(t["lines"]), 0)

	// headline figures
	view["income"], view["incomeFull"] = shortPlain(income), conv.NF(income, 2)
	view["expense"], view["expenseFull"] = shortPlain(expense), conv.NF(expense, 2)
	view["net"], view["netFull"], view["netNeg"] = shortPlain(net), conv.NF(net, 2), net < 0
	if y != nil {
		view["incomeDelta"] = delta(c, pctChange(f(fy["income"]), f(py["income"])), true)
		view["expenseDelta"] = delta(c, pctChange(f(fy["expense"]), f(py["expense"])), false)
	} else {
		s := template.HTML(`<span class="text-steel">` + app.E(scope) + `</span>`)
		view["incomeDelta"], view["expenseDelta"] = s, s
	}
	if income > 0 {
		view["margin"] = c.T("Margin :p%", "p", conv.NF(net/income*100, 1))
		view["per100"] = conv.NF(expense/income*100, 1)
	} else {
		view["margin"] = c.T("No income")
		view["per100"] = "—"
	}
	ratio := 100.0
	if income > 0 {
		ratio = math.Min(100, expense/income*100)
	}
	view["ratio"], view["ratioInt"] = conv.NumberFormat(ratio, 1, ".", ""), int(math.Round(ratio))

	// years
	byYear := list(data["byYear"])
	var netYears []app.M
	for i := len(byYear) - 1; i >= 0; i-- {
		r := byYear[i]
		nn := f(r["income"]) - f(r["expense"])
		m := ""
		if f(r["income"]) > 0 {
			m = c.T("Margin :p%", "p", conv.NF(nn/f(r["income"])*100, 1))
		}
		netYears = append(netYears, app.M{"year": n(r["year"]), "on": y != nil && n(r["year"]) == n(y), "margin": m, "short": shortPlain(nn), "neg": nn < 0,
			"url": c.URL("/management/account-report", "year", r["year"], "department", d)})
	}
	view["netYears"] = netYears

	// types
	types := entries(data["types"])
	typeLabels := map[string]string{}
	for _, e := range types {
		typeLabels[e.K] = c.T(conv.Str(e.V))
	}
	byType := list(data["byType"])
	view["typesHeight"] = 60 + len(byType)*38
	view["hasTypes"] = len(byType) > 0
	view["focus"] = n(data["focus"])
	view["focusPrev"] = n(data["focus"]) - 1

	// departments
	byDept := list(data["byDepartment"])
	view["hasDepts"] = len(byDept) > 0
	view["deptsHeight"] = 70 + len(byDept)*52
	depts := append([]app.M{}, byDept...)
	sort.SliceStable(depts, func(i, j int) bool {
		return f(depts[i]["income"])-f(depts[i]["expense"]) > f(depts[j]["income"])-f(depts[j]["expense"])
	})
	netMax := 0.0
	for _, r := range depts {
		netMax = math.Max(netMax, math.Abs(f(r["income"])-f(r["expense"])))
	}
	var rank []app.M
	for i, r := range depts {
		nn := f(r["income"]) - f(r["expense"])
		w := 0.0
		if netMax > 0 {
			w = math.Abs(nn) / netMax * 100
		}
		color := "#1baf7a"
		if nn < 0 {
			color = "#eb6834"
		}
		rank = append(rank, app.M{"i": i + 1, "name": r["department"], "dot": deptColor(conv.Str(r["department"])), "on": conv.Str(d) == conv.Str(r["department"]) && d != nil,
			"url": c.URL("/management/account-report", "year", y, "department", r["department"]), "short": shortPlain(nn), "neg": nn < 0,
			"w": conv.NumberFormat(w, 1, ".", ""), "bar": color})
	}
	view["rank"] = rank

	// department × type
	matrix := list(data["matrix"])
	grid := map[string]map[string]float64{}
	var gridDepts []string
	used := map[string]bool{}
	gridMax := 0.0
	for _, r := range matrix {
		dep, ty := conv.Str(r["department"]), conv.Str(r["type"])
		if grid[dep] == nil {
			grid[dep] = map[string]float64{}
			gridDepts = append(gridDepts, dep)
		}
		grid[dep][ty] = f(r["amount"])
		used[ty] = true
		gridMax = math.Max(gridMax, f(r["amount"]))
	}
	var gridTypes []string
	var gridHead []string
	for _, e := range types {
		if used[e.K] {
			gridTypes = append(gridTypes, e.K)
			gridHead = append(gridHead, typeLabels[e.K])
		}
	}
	var rows []app.M
	for _, dep := range gridDepts {
		var cells []app.M
		for _, k := range gridTypes {
			v, ok := grid[dep][k]
			if !ok {
				cells = append(cells, app.M{"has": false})
				continue
			}
			a := 0.0
			if gridMax > 0 {
				a = 0.08 + 0.72*math.Abs(v)/gridMax
			}
			style := "background: rgba(42,120,214," + conv.NumberFormat(a, 2, ".", "") + ");"
			if a > 0.5 {
				style += " color: #fff;"
			}
			cells = append(cells, app.M{"has": true, "title": conv.NF(v, 2), "style": template.CSS(style), "short": shortPlain(v)})
		}
		rows = append(rows, app.M{"name": dep, "dot": deptColor(dep), "on": d != nil && conv.Str(d) == dep, "cells": cells})
	}
	view["gridHead"], view["gridRows"] = gridHead, rows

	// biggest accounts
	top := func(key, label, color string) app.M {
		rs := list(data[key])
		mx := 0.0
		for _, r := range rs {
			mx = math.Max(mx, math.Abs(f(r["amount"])))
		}
		var items []app.M
		for _, r := range rs {
			w := "0"
			if mx > 0 {
				w = conv.NumberFormat(math.Abs(f(r["amount"]))/mx*100, 1, ".", "")
			}
			items = append(items, app.M{"account": r["account"], "name": r["name"], "full": conv.NF(f(r["amount"]), 2), "short": shortPlain(f(r["amount"])), "w": w})
		}
		return app.M{"label": label, "color": color, "rows": items}
	}
	view["tops"] = []app.M{top("topIncome", c.T("Biggest income accounts"), "#2a78d6"), top("topExpense", c.T("Biggest expense accounts"), "#eb6834")}

	// chart data
	var monthLabels []string
	for m := 1; m <= 12; m++ {
		monthLabels = append(monthLabels, c.T(time.Month(m).String()[:3]))
	}
	monthVals := func(v any) []app.M {
		out := []app.M{}
		for _, e := range entries(v) {
			mm := asMap(e.V)
			out = append(out, app.M{"income": f(mm["income"]), "expense": f(mm["expense"])})
		}
		return out
	}
	yearsJS := []app.M{}
	for _, r := range byYear {
		yearsJS = append(yearsJS, app.M{"year": n(r["year"]), "income": f(r["income"]), "expense": f(r["expense"])})
	}
	typesJS := []app.M{}
	for _, r := range byType {
		typesJS = append(typesJS, app.M{"type": r["type"], "kind": r["kind"], "amount": f(r["amount"])})
	}
	deptsJS := []app.M{}
	for _, r := range byDept {
		deptsJS = append(deptsJS, app.M{"department": r["department"], "income": f(r["income"]), "expense": f(r["expense"])})
	}
	view["js"] = app.M{
		"L":          jsonJS(app.M{"income": c.T("Income"), "expense": c.T("Expenses"), "net": c.T("Net")}),
		"years":      jsonJS(yearsJS),
		"types":      jsonJS(typesJS),
		"typeLabels": jsonJS(typeLabels),
		"depts":      jsonJS(deptsJS),
		"months":     jsonJS(monthLabels),
		"cur":        jsonJS(monthVals(data["months"])),
		"prev":       jsonJS(monthVals(data["prevMonths"])),
		"focus":      template.JS(strconv.Itoa(n(data["focus"]))),
	}
}
