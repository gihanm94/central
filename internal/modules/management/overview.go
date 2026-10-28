package management

import (
	"encoding/json"
	"html/template"
	"math"
	"regexp"
	"sort"
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/conv"
	"acme/internal/db"
)

/*
Management → Overview (OverviewController): revenue for a period, top customers, the seller ranking and the department
ranking. Management → CRM overview: opportunities for the same periods (pipeline, won / lost, ranking), from CRM's
'management.crm' hook. The figures come from Accounting through the 'revenue.ranking' hook; when Accounting is off or
broken the page says so and the rest of the module keeps working. Names, photos and departments come from the core database.
*/

var periodKeys = []string{"month", "last_month", "quarter", "ytd", "year", "custom"}
var periodLabels = map[string]string{"month": "This month", "last_month": "Last month", "quarter": "This quarter", "ytd": "Year to date", "year": "Full year", "custom": "Custom"}

var ymdRe = regexp.MustCompile(`^\d{4}-\d{2}-\d{2}$`)

func dayOf(t time.Time) time.Time {
	return time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, conv.Loc)
}

// rangeOf: from, to, period key.
func rangeOf(c *app.Ctx) (string, string, string) {
	period := c.Query("period", "ytd")
	if _, ok := periodLabels[period]; !ok {
		period = "ytd"
	}
	now := conv.Now()
	year := conv.Int(c.Query("year", conv.Str(now.Year())))
	if year < 2000 || year > 2100 {
		year = now.Year()
	}
	today := dayOf(now)
	first := time.Date(today.Year(), today.Month(), 1, 0, 0, 0, 0, conv.Loc)
	date := func(v string, fb time.Time) time.Time {
		if ymdRe.MatchString(v) {
			if t, ok := conv.ParseTime(v); ok {
				return dayOf(t)
			}
		}
		return fb
	}
	var from, to time.Time
	switch period {
	case "month":
		from, to = first, today
	case "last_month":
		from, to = first.AddDate(0, -1, 0), first.AddDate(0, 0, -1)
	case "quarter":
		from, to = time.Date(today.Year(), time.Month((int(today.Month())-1)/3*3+1), 1, 0, 0, 0, 0, conv.Loc), today
	case "year":
		from, to = time.Date(year, 1, 1, 0, 0, 0, 0, conv.Loc), time.Date(year, 12, 31, 0, 0, 0, 0, conv.Loc)
	case "custom":
		from, to = date(c.Query("from"), first), date(c.Query("to"), today)
	default:
		from, to = time.Date(today.Year(), 1, 1, 0, 0, 0, 0, conv.Loc), today
	}
	if from.After(to) {
		from, to = to, from
	}
	return from.Format("2006-01-02"), to.Format("2006-01-02"), period
}

type periodTab struct {
	Label, URL string
	On         bool
}

func periodTabs(c *app.Ctx, base, period string, year int) []periodTab {
	var out []periodTab
	for _, k := range periodKeys {
		if k == "custom" {
			continue
		}
		var p, y any
		if k != "ytd" {
			p = k
		}
		if k == "year" {
			y = year
		}
		out = append(out, periodTab{c.T(periodLabels[k]), c.URL(base, "period", p, "year", y), period == k})
	}
	return out
}

func yearOpts(from, to int) []app.Opt {
	var out []app.Opt
	for y := from; y >= to; y-- {
		out = append(out, app.Opt{Value: conv.Str(y), Label: conv.Str(y)})
	}
	return out
}

// shortMoney is the $short closure: ฿1.23M, ฿4.5K.
func shortMoney(sym string, v float64) string {
	a := math.Abs(v)
	switch {
	case a >= 1e9:
		return sym + conv.NF(v/1e9, 2) + "B"
	case a >= 1e6:
		return sym + conv.NF(v/1e6, 2) + "M"
	case a >= 1e3:
		return sym + conv.NF(v/1e3, 1) + "K"
	}
	return sym + conv.NF(v, 0)
}

func medal(i int) string {
	switch i {
	case 0:
		return "bg-amber-400 text-white"
	case 1:
		return "bg-graphite-300 text-graphite-900"
	case 2:
		return "bg-amber-700 text-white"
	}
	return "bg-graphite-900/6 text-graphite-700"
}

func pctOf(v, total float64) string {
	if total > 0 {
		return conv.NF(v/total*100, 1) + "%"
	}
	return "—"
}

func width(v, total float64) string {
	if total > 0 {
		return conv.Str(math.Round(math.Max(0, v)/total*1000) / 10)
	}
	return "0"
}

func departmentsByID() map[int]db.Row {
	out := map[int]db.Row{}
	for _, r := range db.Select("SELECT id, name, color FROM departments WHERE deleted_at IS NULL") {
		out[r.Int("id")] = r
	}
	return out
}

func peopleByID(ids []int) map[int]db.Row {
	out := map[int]db.Row{}
	seen := map[int]bool{}
	var s []string
	for _, id := range ids {
		if id != 0 && !seen[id] {
			seen[id] = true
			s = append(s, conv.Str(id))
		}
	}
	if len(s) > 0 {
		for _, r := range db.Select("SELECT id, name, avatar, department_id FROM users WHERE id IN (" + strings.Join(s, ",") + ")") {
			out[r.Int("id")] = r
		}
	}
	return out
}

func overviewIndex(c *app.Ctx) {
	c.Authorize("management", "view")
	from, to, period := rangeOf(c)
	var data app.M
	if app.ModuleUsable("accounting") {
		data = asMap(app.Hook("revenue.ranking", "accounting", func(fn any) any {
			if h, ok := fn.(app.HookPeriod); ok {
				return h(c, from, to)
			}
			return nil
		}))
	}
	year := conv.Int(c.Query("year", conv.Str(conv.Now().Year())))
	kpi := db.First("SELECT count(*) AS n, round(avg(score), 2) AS avg FROM kpi_scores WHERE year = ?", conv.Int(from[:4]))
	view := app.M{"title": c.T("Management overview"), "from": from, "to": to, "period": period, "year": year, "fromYear": from[:4],
		"tabs": periodTabs(c, "/management", period, year), "years": yearOpts(conv.Now().Year(), conv.Now().Year()-5), "data": data != nil}
	if data != nil {
		baht := func(v float64) string { return "฿" + conv.NF(v, 0) }
		short := func(v float64) string { return shortMoney("฿", v) }
		total := f(data["total"])
		prev := f(data["previous"])
		view["total"], view["totalFull"] = short(total), baht(total)
		if prev > 0 {
			ch := (total - prev) / prev * 100
			pr := values(data["previous_range"])
			arrow := "▼ "
			if ch >= 0 {
				arrow = "▲ "
			}
			var p0, p1 any
			if len(pr) == 2 {
				p0, p1 = pr[0], pr[1]
			}
			view["change"] = arrow + conv.NF(math.Abs(ch), 1) + "% " + c.T("vs :from – :to", "from", app.FormatDate(c, p0, "j M"), "to", app.FormatDate(c, p1, "j M Y"))
			view["changeUp"] = ch >= 0
		}
		invoices := n(data["invoices"])
		view["invoices"] = conv.NF(invoices, 0)
		view["perInvoice"] = "—"
		if invoices > 0 {
			view["perInvoice"] = short(total / float64(invoices))
		}
		view["customersCount"] = conv.NF(n(data["customers_count"]), 0)
		customers := list(data["customers"])
		top := 0.0
		for _, x := range customers {
			top += f(x["v"])
		}
		view["topShare"] = "0"
		if total > 0 {
			view["topShare"] = conv.NF(top/total*100, 1)
		}
		// months
		ms := entries(data["months"])
		maxM := 0.0
		for _, e := range ms {
			maxM = math.Max(maxM, math.Abs(f(e.V)))
		}
		var bars []app.M
		for _, e := range ms {
			v := f(e.V)
			h := 2.0
			if maxM > 0 {
				h = math.Max(2, math.Abs(v)/maxM*100)
			}
			bars = append(bars, app.M{"title": app.FormatDate(c, e.K+"-01", "F Y") + ": " + baht(v), "short": short(v), "neg": v < 0,
				"h": conv.Str(math.Round(h*10) / 10), "label": app.FormatDate(c, e.K+"-01", "M")})
		}
		view["months"] = bars
		deps := departmentsByID()
		sellers := list(data["sellers"])
		var ids []int
		for _, s := range sellers {
			ids = append(ids, n(s["user_id"]))
		}
		people := peopleByID(ids)
		var sl []app.M
		for i, s := range sellers {
			var name, avatar any = s["name"], nil
			if u, ok := people[n(s["user_id"])]; ok && n(s["user_id"]) != 0 {
				name, avatar = u["name"], u["avatar"]
			}
			depName := c.T("No department set")
			if d, ok := deps[n(s["department_id"])]; ok {
				depName = d.Str("name")
			}
			v := f(s["v"])
			sl = append(sl, app.M{"i": i + 1, "medal": medal(i), "avName": name, "avatar": avatar, "name": s["name"],
				"line": depName + " · " + c.T(":n invoices · :c customers", "n", s["n"], "c", s["customers"]),
				"w":    width(v, total), "short": short(v), "full": baht(v), "pct": pctOf(v, total)})
		}
		view["sellers"] = sl
		var dl []app.M
		rank := 0
		for _, d := range list(data["departments"]) {
			dep, named := deps[n(d["department_id"])]
			named = named && n(d["department_id"]) != 0
			v := f(d["v"])
			x := app.M{"named": named, "color": "#9ca3af", "name": c.T("Sellers without a department"),
				"line": c.T(":s sellers · :n invoices", "s", d["sellers"], "n", d["n"]), "w": width(v, total), "short": short(v), "full": baht(v), "pct": pctOf(v, total)}
			if named {
				x["medal"] = medal(rank)
				rank++
				x["rank"] = rank
				x["name"] = dep.Str("name")
				if dep.Str("color") != "" {
					x["color"] = dep.Str("color")
				}
			} else {
				x["line"] = conv.Str(x["line"]) + " · " + c.T("set it in Accounting → Sellers")
			}
			dl = append(dl, x)
		}
		view["departments"] = dl
		var cl []app.M
		for i, x := range customers {
			v := f(x["v"])
			code := conv.Str(x["code"])
			if code == "" {
				code = "—"
			}
			cl = append(cl, app.M{"i": i + 1, "medal": medal(i), "name": x["name"], "line": code + " · " + c.T(":n invoices", "n", x["n"]),
				"w": width(v, total), "short": short(v), "full": baht(v), "pct": pctOf(v, total)})
		}
		view["customers"] = cl
	}
	view["kpiN"] = kpi.Int("n")
	view["kpiAvg"] = "—"
	if kpi.Int("n") > 0 {
		view["kpiAvg"] = conv.NF(kpi.Float("avg"), 2)
	}
	c.View("management/overview", view)
}

var stageOrder = []string{"QUALIFICATION", "SURVEY_PROPOSAL", "EVALUATION_TESTING", "NEGOTIATION", "ON_HOLD"}
var stageTone = map[string]string{"QUALIFICATION": "bg-sky-400", "SURVEY_PROPOSAL": "bg-sky-500", "EVALUATION_TESTING": "bg-sky-600", "NEGOTIATION": "bg-violet-600", "ON_HOLD": "bg-amber-500"}

func overviewCRM(c *app.Ctx) {
	c.Authorize("management", "view")
	from, to, period := rangeOf(c)
	var data app.M
	if app.ModuleUsable("crm") {
		data = asMap(app.Hook("management.crm", "crm", func(fn any) any {
			if h, ok := fn.(app.HookPeriod); ok {
				return h(c, from, to)
			}
			return nil
		}))
	}
	year := conv.Int(c.Query("year", conv.Str(conv.Now().Year())))
	cur := "THB"
	if data != nil && conv.Str(data["cur"]) != "" {
		cur = conv.Str(data["cur"])
	}
	sym := cur + " "
	if cur == "THB" {
		sym = "฿"
	}
	money := func(v float64) string { return sym + conv.NF(v, 0) }
	short := func(v float64) string { return shortMoney(sym, v) }
	view := app.M{"title": c.T("CRM overview"), "from": from, "to": to, "period": period, "year": year, "cur": cur,
		"tabs": periodTabs(c, "/management/crm", period, year), "years": yearOpts(conv.Now().Year(), conv.Now().Year()-5), "data": data != nil}
	if data == nil {
		c.View("management/crm", view)
	}
	t := asMap(data["totals"])
	cards := app.M{
		"openV": short(f(t["open_v"])), "openFull": money(f(t["open_v"])), "openLine": c.T(":n open · weighted :w", "n", n(t["open_n"]), "w", short(f(t["weighted"]))),
		"wonV": short(f(t["won_v"])), "wonFull": money(f(t["won_v"])),
		"winRate": "—", "lostLine": c.T(":w won · :l lost (:v)", "w", n(t["won_n"]), "l", n(t["lost_n"]), "v", short(f(t["lost_v"]))),
		"newN": n(t["new_n"]), "stale": n(t["stale_n"]) > 0, "newLine": c.T("worth :v · :s open more than 90 days in one stage", "v", short(f(t["new_v"])), "s", n(t["stale_n"])),
	}
	avg := "—"
	if t["avg_won"] != nil {
		avg = short(f(t["avg_won"]))
	}
	cards["wonLine"] = c.T(":n deals · average :a", "n", n(t["won_n"]), "a", avg)
	if t["win_rate"] != nil {
		cards["winRate"] = conv.NF(f(t["win_rate"]), 1) + "%"
	}
	view["cards"] = cards
	if tg := asMap(data["target"]); tg != nil && f(tg["total"]) > 0 {
		p := f(tg["actual"]) / f(tg["total"]) * 100
		view["target"] = app.M{"year": tg["year"], "actual": short(f(tg["actual"])), "total": short(f(tg["total"])), "pct": conv.NF(p, 1), "done": p >= 100,
			"w": conv.Str(math.Round(math.Min(100, p)*10) / 10)}
	}
	months := list(data["months"])
	maxM := 0.0
	for _, m := range months {
		maxM = math.Max(maxM, f(m["won_v"]))
	}
	var bars []app.M
	for _, m := range months {
		v := f(m["won_v"])
		h := 2.0
		if maxM > 0 {
			h = math.Max(2, v/maxM*100)
		}
		ym := conv.Str(m["ym"])
		s := ""
		if v > 0 {
			s = short(v)
		}
		bars = append(bars, app.M{"title": app.FormatDate(c, ym+"-01", "F Y") + ": " + money(v) + " · " + c.T(":n new", "n", m["new_n"]),
			"short": s, "won": v > 0, "h": conv.Str(math.Round(h*10) / 10), "label": app.FormatDate(c, ym+"-01", "M"), "newN": n(m["new_n"])})
	}
	view["months"] = bars
	// stages in CRM's order
	stages := asMap(data["stages"])
	var keys []string
	if cm := entries(data["stages"]); len(cm) > 0 {
		if _, ordered := data["stages"].(app.M); !ordered {
			for _, e := range cm {
				keys = append(keys, e.K)
			}
		}
	}
	if keys == nil {
		seen := map[string]bool{}
		for _, k := range stageOrder {
			if _, ok := stages[k]; ok {
				keys = append(keys, k)
				seen[k] = true
			}
		}
		var rest []string
		for k := range stages {
			if !seen[k] {
				rest = append(rest, k)
			}
		}
		sort.Strings(rest)
		keys = append(keys, rest...)
	}
	maxS := 0.0
	for _, k := range keys {
		maxS = math.Max(maxS, f(asMap(stages[k])["v"]))
	}
	var sl []app.M
	stageLabel := map[string]any{}
	for _, k := range keys {
		s := asMap(stages[k])
		stageLabel[k] = s["label"]
		w := "0"
		if maxS > 0 {
			w = conv.Str(math.Round(f(s["v"])/maxS*1000) / 10)
		}
		tone := stageTone[k]
		if tone == "" {
			tone = "bg-graphite-600"
		}
		sl = append(sl, app.M{"label": s["label"], "n": n(s["n"]), "short": short(f(s["v"])), "full": money(f(s["v"])), "w": w, "tone": tone})
	}
	view["stages"] = sl
	deps := departmentsByID()
	sellers := list(data["sellers"])
	top := list(data["top"])
	var ids []int
	for _, s := range sellers {
		ids = append(ids, n(s["id"]))
	}
	for _, o := range top {
		ids = append(ids, n(o["owner_id"]))
	}
	people := peopleByID(ids)
	winPart := func(x app.M) string {
		if x["win_rate"] != nil {
			return " · " + c.T("win :p%", "p", x["win_rate"])
		}
		return ""
	}
	var sel []app.M
	for i, s := range sellers {
		u, ok := people[n(s["id"])]
		who := c.T("No owner")
		var avatar any
		dep := "—"
		if ok && n(s["id"]) != 0 {
			who, avatar = u.Str("name"), u["avatar"]
			if d, ok := deps[u.Int("department_id")]; ok {
				dep = d.Str("name")
			}
		} else if n(s["id"]) != 0 {
			who = c.T("Deleted user") + " #" + conv.Str(n(s["id"]))
		}
		v := f(s["won_v"])
		sel = append(sel, app.M{"i": i + 1, "medal": medal(i), "who": who, "avatar": avatar, "known": ok,
			"line": dep + " · " + c.T(":w won · open :o (:n)", "w", s["won_n"], "o", short(f(s["open_v"])), "n", s["open_n"]) + winPart(s),
			"won":  v > 0, "short": short(v), "full": money(v)})
	}
	view["sellers"] = sel
	wonAll := 0.0
	dlist := list(data["departments"])
	for _, d := range dlist {
		wonAll += f(d["won_v"])
	}
	wonAll = math.Max(1, wonAll)
	var dl []app.M
	for i, d := range dlist {
		dep, ok := deps[n(d["id"])]
		name, color := c.T("No department"), "#9ca3af"
		if ok && n(d["id"]) != 0 {
			name = dep.Str("name")
			if dep.Str("color") != "" {
				color = dep.Str("color")
			}
		}
		v := f(d["won_v"])
		dl = append(dl, app.M{"i": i + 1, "medal": medal(i), "name": name, "color": color,
			"line": c.T(":w won · open :o (:n)", "w", d["won_n"], "o", short(f(d["open_v"])), "n", d["open_n"]) + winPart(d),
			"w":    conv.Str(math.Round(v/wonAll*1000) / 10), "won": v > 0, "short": short(v), "full": money(v)})
	}
	view["departments"] = dl
	var tl []app.M
	for _, o := range top {
		days := int(math.Floor(float64(time.Now().Unix()-conv.StrToTime(o["since"])) / 86400))
		owner := "—"
		if u, ok := people[n(o["owner_id"])]; ok {
			owner = u.Str("name")
		} else if n(o["owner_id"]) != 0 {
			owner = c.T("Deleted user") + " #" + conv.Str(n(o["owner_id"]))
		}
		dep := "—"
		if d, ok := deps[n(o["department_id"])]; ok {
			dep = d.Str("name")
		}
		sub := conv.Str(o["code"])
		if conv.Str(o["customer"]) != "" {
			sub += " · " + conv.Str(o["customer"])
		}
		stage := conv.Str(o["stage"])
		label := stageLabel[stage]
		if label == nil {
			label = stage
		}
		tone := stageTone[stage]
		if tone == "" {
			tone = "bg-graphite-400"
		}
		age := "bg-mist text-steel"
		if days >= 90 {
			age = "bg-signal-50 text-signal-800"
		} else if days >= 30 {
			age = "bg-amber-50 text-amber-800"
		}
		tl = append(tl, app.M{"id": n(o["id"]), "name": o["name"], "sub": strings.Trim(sub, " ·"), "stage": label, "tone": tone, "owner": owner, "dep": dep,
			"since": app.FormatDate(c, o["since"], "d M Y"), "days": days, "age": age, "short": short(f(o["v"])), "full": money(f(o["v"]))})
	}
	view["top"] = tl
	c.View("management/crm", view)
}

// accountReport: Management → Account report, from Accounting's 'account.report' hook.
func accountReport(c *app.Ctx) {
	c.Authorize("management", "view")
	var data app.M
	if app.ModuleUsable("accounting") {
		var y, d any
		if yy := conv.Int(c.Query("year", "0")); yy != 0 {
			y = yy
		}
		if dd := c.Query("department"); dd != "" {
			d = dd
		}
		data = asMap(app.Hook("account.report", "accounting", func(fn any) any {
			if h, ok := fn.(app.HookFilters); ok {
				return h(c, app.M{"year": y, "department": d})
			}
			return nil
		}))
	}
	colors := map[string]db.Row{}
	for _, r := range db.Select("SELECT name, color, id FROM departments WHERE deleted_at IS NULL") {
		colors[r.Str("name")] = r
	}
	deptColor := func(name string) string {
		if r, ok := colors[name]; ok {
			return app.DeptColor(r.Int("id"), r.Str("color"))
		}
		return "#94a3b8"
	}
	view := app.M{"title": c.T("Account report"), "data": data != nil, "canOpen": c.Can("accounting_reports", "view")}
	if data == nil {
		c.View("management/account-report", view)
	}
	years := values(data["years"])
	view["hasYears"] = len(years) > 0
	if len(years) == 0 {
		c.View("management/account-report", view)
	}
	accountReportView(c, data, view, years, deptColor)
	c.View("management/account-report", view)
}

func jsonJS(v any) template.JS {
	b, _ := json.Marshal(v)
	return template.JS(b)
}
