package management

import (
	"math"
	"sort"
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/conv"
	"acme/internal/db"
	"acme/internal/kpisub"
)

/*
Yearly appraisal score out of 100 (ACME-INTER 2569 rules), used by Management → KPI calculator.

  Final = Σ (section rate × section points) − warning deductions

The rules themselves are plain functions (tier, rate, kpi, onTime, qms, absence, total) so they can be checked against
the worked examples. gather() reads a person's KPIs and submissions. Choices where the rule book is unclear: exactly 55%
is in the 50% tier; decimals fall to the lower tier; absence over 12 days loses 10% per day counted per hour; no action
plan assigned = full 3 points; the leader's evaluation is a 0–100% rate; only approved submissions count towards the KPI result.
*/

var groupKeys = []string{"bu", "iso", "non_iso", "no_qo"}
var groupLabels = map[string]string{"bu": "6 BU", "iso": "ISO", "non_iso": "Non-ISO (Acc, DX)", "no_qo": "No QO (MM, MKT)"}

var sectionKeys = []string{"kpi", "ontime", "qo", "sales", "qms", "leader", "absence"}

// points of each section per employee group (sum 100).
var points = map[string]map[string]int{
	"bu":      {"kpi": 30, "ontime": 5, "qo": 10, "sales": 5, "qms": 15, "leader": 20, "absence": 15},
	"iso":     {"kpi": 30, "ontime": 5, "qo": 15, "sales": 0, "qms": 15, "leader": 20, "absence": 15},
	"non_iso": {"kpi": 40, "ontime": 5, "qo": 15, "sales": 0, "qms": 0, "leader": 25, "absence": 15},
	"no_qo":   {"kpi": 55, "ontime": 5, "qo": 0, "sales": 0, "qms": 0, "leader": 25, "absence": 15},
}

var sectionLabels = map[string]string{"kpi": "1. KPI", "ontime": "2. KPI on time", "qo": "3. Department quality objective (QO)", "sales": "4. Sales performance",
	"qms": "5. QMS (NCR / CAR)", "leader": "6. Evaluation from leader", "absence": "7. Absence rate"}

var periodNames = map[string]string{"month": "Monthly", "quarter": "Quarterly", "half": "Half-year", "year": "Yearly"}

// inputs typed in by HR / the BU manager, with their defaults (nil = empty).
var inputKeys = []string{"qo_actual", "qo_target", "sales_actual", "sales_target", "qms_open_car", "qms_repeat_car", "qms_docs", "qms_projects", "qms_plan_done", "qms_plan_assigned",
	"leader", "abs_unpaid_personal", "abs_sick_no_cert", "abs_absent", "abs_late", "warn_written", "warn_final", "severe", "note"}

var inputDefaults = map[string]any{"qo_actual": nil, "qo_target": nil, "sales_actual": nil, "sales_target": nil,
	"qms_open_car": 0.0, "qms_repeat_car": 0.0, "qms_docs": 0.0, "qms_projects": 0.0, "qms_plan_done": 0.0, "qms_plan_assigned": 0.0,
	"leader": nil, "abs_unpaid_personal": 0.0, "abs_sick_no_cert": 0.0, "abs_absent": 0.0, "abs_late": 0.0, "warn_written": 0.0, "warn_final": 0.0, "severe": 0, "note": ""}

// ------------------------------------------------------------------ rules

// tier: multiplier (%) for an achievement (%).
func tier(a float64) float64 {
	switch {
	case a >= 100:
		return 100
	case a >= 85:
		return 85
	case a >= 70:
		return 70
	case a >= 55:
		return 50
	}
	return 0
}

type rateRes struct {
	Achievement, Multiplier *float64
	Result                  float64
}

func fp(v float64) *float64 { return &v }

func round2(v float64) float64 { return math.Round(v*100) / 100 }

// rate: one measurement against its target.
//
//	min (not less than): result = min(achievement, 100%) × tier multiplier
//	max (not more than): 100% when within the limit, else 0%
func rate(actual, target float64, direction string) rateRes {
	if direction == "max" {
		if actual <= target {
			return rateRes{Result: 100}
		}
		return rateRes{Result: 0}
	}
	if target <= 0 {
		if actual > 0 {
			return rateRes{Result: 100}
		}
		return rateRes{Result: 0}
	}
	a := math.Floor(actual/target*10000+1e-9) / 100 // cut to 2 decimals, as the rule book does (88.888… → 88.88)
	m := tier(a)
	return rateRes{Achievement: fp(round2(a)), Multiplier: fp(m), Result: round2(math.Min(a, 100) * m / 100)}
}

type monthFig struct{ Finished, Total float64 }

// periods: months of each measuring period up to upTo, in order.
type periodDef struct {
	Label  string
	Months []int
}

func periodsOf(period string, upTo int) []periodDef {
	size := map[string]int{"month": 1, "quarter": 3, "half": 6, "year": 12}[period]
	if size == 0 {
		size = 1
	}
	var out []periodDef
	for start := 1; start <= upTo; start += size {
		var months []int
		for m := start; m <= min(start+size-1, upTo); m++ {
			months = append(months, m)
		}
		label := time.Month(start).String()[:3]
		switch period {
		case "quarter":
			label = "Q" + conv.Str((start-1)/3+1)
		case "half":
			label = "H" + conv.Str((start-1)/6+1)
		case "year":
			label = "Year"
		}
		out = append(out, periodDef{label, months})
	}
	return out
}

type periodRow struct {
	Label           string
	Finished, Total float64
	Skipped         bool
	Actual, Target  float64
	rateRes
}

type kpiRes struct {
	Result  float64
	Periods []periodRow
	HasData bool
}

type kpiDef struct {
	Criteria                   *float64
	Direction, Measure, Period string
}

// kpi: result of one KPI over the year: per period the months are summed and rated; 0/0 periods are skipped; the KPI
// result is the average of the periods that have data.
func kpiResult(k kpiDef, months map[int]monthFig, upTo int) kpiRes {
	target := 0.0
	if k.Criteria != nil {
		target = *k.Criteria
	}
	dir, measure, per := k.Direction, k.Measure, k.Period
	if per == "" {
		per = "month"
	}
	if measure == "" {
		measure = "percent"
	}
	var rows []periodRow
	sum, n := 0.0, 0
	for _, p := range periodsOf(per, upTo) {
		fin, tot, seen := 0.0, 0.0, false
		for _, m := range p.Months {
			if f, ok := months[m]; ok {
				fin += f.Finished
				tot += f.Total
				seen = true
			}
		}
		if !seen || (fin == 0 && tot == 0) {
			rows = append(rows, periodRow{Label: p.Label, Finished: fin, Total: tot, Skipped: true})
			continue // no data (0/0): not in the count
		}
		var actual float64
		if measure == "percent" {
			if tot <= 0 {
				rows = append(rows, periodRow{Label: p.Label, Finished: fin, Total: tot, Skipped: true})
				continue
			}
			actual = fin / tot * 100
		} else {
			actual = fin
		}
		r := rate(actual, target, dir)
		rows = append(rows, periodRow{Label: p.Label, Finished: fin, Total: tot, Actual: round2(actual), Target: target, rateRes: r})
		sum += r.Result
		n++
	}
	res := 0.0
	if n > 0 {
		res = round2(sum / float64(n))
	}
	return kpiRes{Result: res, Periods: rows, HasData: n > 0}
}

type kpiItem struct {
	Result  float64
	Weight  *float64
	HasData bool
}

type kpiSection struct {
	Rate    float64
	Weights []float64
	Scaled  bool
}

// kpiSection: Σ (KPI result × weight). A KPI with no data in the whole period (only 0/0) is left out, like a 0/0 month,
// and its weight goes to the others; missing weights share the rest equally; weights that do not add up to 100 are
// scaled to 100.
func kpiSectionOf(kpis []kpiItem) kpiSection {
	weights := make([]float64, len(kpis))
	var use []int
	for i, k := range kpis {
		if k.HasData {
			use = append(use, i)
		}
	}
	if len(use) == 0 {
		return kpiSection{0, weights, false}
	}
	wv := func(k kpiItem) float64 {
		if k.Weight == nil {
			return 0
		}
		return *k.Weight
	}
	given, missing := 0.0, 0
	for _, i := range use {
		given += wv(kpis[i])
		if kpis[i].Weight == nil || *kpis[i].Weight <= 0 {
			missing++
		}
	}
	share := 0.0
	if missing > 0 {
		share = math.Max(0, 100-given) / float64(missing)
	}
	w := map[int]float64{}
	total := 0.0
	for _, i := range use {
		if kpis[i].Weight != nil && *kpis[i].Weight > 0 {
			w[i] = *kpis[i].Weight
		} else {
			w[i] = share
		}
		total += w[i]
	}
	// Warn only when the weights typed in KPIs & OKRs do not add up to 100 (a KPI left out for lack of data is not a mistake)
	typedSum, anyZero := 0.0, false
	for _, k := range kpis {
		typedSum += wv(k)
		if wv(k) == 0 {
			anyZero = true
		}
	}
	scaled := !anyZero && math.Abs(typedSum-100) > 0.01
	if total <= 0 {
		for _, i := range use {
			w[i] = 100 / float64(len(use))
		}
		total = 100
	}
	r := 0.0
	for _, i := range use {
		weights[i] = w[i] / total * 100
		r += kpis[i].Result * weights[i] / 100
	}
	for i := range weights {
		weights[i] = round2(weights[i])
	}
	return kpiSection{round2(r), weights, scaled}
}

// lateRate: on-time rate for a deadline: 100% − 20% per day late, never below 0.
func lateRate(daysLate int) float64 { return math.Max(0, 100-20*float64(max(0, daysLate))) }

type onTimeMonth struct {
	Submitter, Approver *float64
	Rate                float64
}

type onTimeRes struct {
	Rate   float64
	Months []int
	By     map[int]onTimeMonth
}

// onTime — section 2: per month the submitter's rate (average over the KPIs due that month; missing = 0%, corrected =
// 0%) and, for approvers, the approving rate; both → average of the two. Year = average of the months.
func onTimeOf(submitter, approver map[int]float64) onTimeRes {
	months := map[int]onTimeMonth{}
	keys := map[int]bool{}
	for m := range submitter {
		keys[m] = true
	}
	for m := range approver {
		keys[m] = true
	}
	var order []int
	for m := range keys {
		order = append(order, m)
	}
	sort.Ints(order)
	sum := 0.0
	for _, m := range order {
		var parts []float64
		om := onTimeMonth{}
		if v, ok := submitter[m]; ok {
			parts = append(parts, v)
			om.Submitter = fp(v)
		}
		if v, ok := approver[m]; ok {
			parts = append(parts, v)
			om.Approver = fp(v)
		}
		t := 0.0
		for _, p := range parts {
			t += p
		}
		om.Rate = round2(t / float64(len(parts)))
		months[m] = om
		sum += om.Rate
	}
	r := 0.0
	if len(order) > 0 {
		r = round2(sum / float64(len(order)))
	}
	return onTimeRes{r, order, months}
}

type qmsRes struct {
	Points float64
	Items  map[string]float64
}

// qms: section 5: five items, 3 points each (15).
func qmsOf(in map[string]any) qmsRes {
	steps := func(n int, m []float64) float64 { return m[min(n, len(m)-1)] }
	plan := 3.0
	if f(in["qms_plan_assigned"]) > 0 {
		plan = round2(math.Min(1, f(in["qms_plan_done"])/f(in["qms_plan_assigned"])) * 3)
	}
	items := map[string]float64{
		"open_car":   steps(n(in["qms_open_car"]), []float64{3, 2.5, 2, 1.25, 0}),
		"repeat_car": steps(n(in["qms_repeat_car"]), []float64{3, 1.5, 0}),
		"docs":       steps(n(in["qms_docs"]), []float64{3, 2.5, 2, 1.25, 0}),
		"projects":   steps(n(in["qms_projects"]), []float64{0, 1.5, 3}),
		"plan":       plan,
	}
	s := 0.0
	for _, v := range items {
		s += v
	}
	return qmsRes{round2(s), items}
}

type absenceRes struct {
	Hours, Days, Rate float64
	NoRaise           bool
}

// absence — section 7: counted hours → rate.
func absenceOf(in map[string]any) absenceRes {
	h := f(in["abs_unpaid_personal"]) + f(in["abs_sick_no_cert"]) + f(in["abs_absent"]) + f(in["abs_late"])
	d := h / 8
	var r float64
	switch {
	case d <= 4:
		r = 100
	case d <= 8:
		r = 90
	case d <= 12:
		r = 80
	default:
		r = math.Max(0, 80-10*(d-12)) // −10% per day over 12, counted per hour (−1.25%/h)
	}
	return absenceRes{round2(h), round2(d), round2(r), h > 96}
}

type sectionRes struct {
	Rate, Earned float64
	Points       int
}

type totalRes struct {
	Sections  map[string]sectionRes
	Deduction float64
	Score     float64
}

// total — the whole score. rates holds the section rates in % (qms in points of 15).
func totalOf(group string, rates map[string]float64, in map[string]any) totalRes {
	pts, ok := points[group]
	if !ok {
		pts = points["bu"]
	}
	secs := map[string]sectionRes{}
	sum := 0.0
	for _, k := range sectionKeys {
		p := pts[k]
		rt := rates[k]
		var earned, shown float64
		if k == "qms" {
			earned = rt / 15 * float64(p)
			shown = rt / 15 * 100
		} else {
			earned = rt / 100 * float64(p)
			shown = rt
		}
		secs[k] = sectionRes{round2(shown), round2(earned), p}
		sum += earned
	}
	ded := 2*float64(n(in["warn_written"])) + 5*float64(n(in["warn_final"]))
	return totalRes{secs, ded, round2(math.Max(0, sum-ded))}
}

// ------------------------------------------------------------------ data

// cleanInputs: inputs from a form / saved row, cleaned to numbers.
func cleanInputs(raw map[string]any) map[string]any {
	out := map[string]any{}
	for _, k := range inputKeys {
		v, has := raw[k]
		if !has {
			v = inputDefaults[k]
		}
		switch {
		case k == "note":
			out[k] = conv.Substr(strings.TrimSpace(conv.Str(v)), 0, 2000)
		case k == "severe":
			if !conv.Empty(v) {
				out[k] = 1
			} else {
				out[k] = 0
			}
		case v == nil || conv.Str(v) == "":
			out[k] = inputDefaults[k]
		default:
			out[k] = math.Max(0, math.Min(1e9, conv.Float(v)))
		}
	}
	if out["leader"] != nil {
		out["leader"] = math.Min(100, f(out["leader"]))
	}
	return out
}

type kpiRow struct {
	ID                 int
	Title, Kind        string
	Criteria           *float64
	Direction, Measure string
	Period             string
	Weight             *float64
	kpiRes
	UsedWeight, Weighted float64
}

type calcRes struct {
	Year, Month int
	Group       string
	KPIs        []kpiRow
	KPI         kpiSection
	Waiting     int
	OnTime      onTimeRes
	QO, Sales   *rateRes
	QMS         qmsRes
	Absence     absenceRes
	Leader      any
	totalRes
}

func nullF(v any) *float64 {
	if v == nil || conv.Str(v) == "" {
		return nil
	}
	return fp(conv.Float(v))
}

// calculate: everything for one person and year, up to upTo: KPI results from approved submissions, on-time months,
// and the typed-in sections.
func calculate(userID, year, upTo int, group string, in map[string]any) calcRes {
	if _, ok := points[group]; !ok {
		group = "bu"
	}
	first := time.Date(year, 1, 1, 0, 0, 0, 0, conv.Loc).Format("2006-01-02")
	last := time.Date(year, time.Month(upTo)+1, 0, 0, 0, 0, 0, conv.Loc).Format("2006-01-02")
	kpis := db.Select(`SELECT id, title, kind, criteria, direction, measure, period, weight, period_start, period_end FROM kpis
            WHERE user_id = ? AND deleted_at IS NULL AND status <> 'archived' AND period_start <= ?::date AND period_end >= ?::date ORDER BY kind, title`, userID, last, first)
	subs := db.Select(`SELECT s.id, s.kpi_id, s.period_month, s.finished, s.total, s.percentage, s.approved_at,
                EXISTS (SELECT 1 FROM activity_logs l WHERE l.subject_type = ? AND l.subject_id = s.id AND l.action = ?) AS corrected
            FROM kpi_submissions s WHERE s.user_id = ? AND s.period_year = ? AND s.period_month <= ? AND s.deleted_at IS NULL`, "kpi_submission", "updated", userID, year, upTo)
	byKpi := map[int]map[int]db.Row{}
	for _, s := range subs {
		if byKpi[s.Int("kpi_id")] == nil {
			byKpi[s.Int("kpi_id")] = map[int]db.Row{}
		}
		byKpi[s.Int("kpi_id")][s.Int("period_month")] = s
	}
	// 1. KPI
	var items []kpiRow
	waiting := 0
	for _, k := range kpis {
		months := map[int]monthFig{}
		for m, s := range byKpi[k.Int("id")] {
			if !s.Nil("approved_at") {
				months[m] = monthFig{s.Float("finished"), s.Float("total")}
			} else {
				waiting++
			}
		}
		def := kpiDef{Criteria: nullF(k["criteria"]), Direction: k.Str("direction"), Measure: k.Str("measure"), Period: k.Str("period")}
		items = append(items, kpiRow{ID: k.Int("id"), Title: k.Str("title"), Kind: k.Str("kind"), Criteria: def.Criteria, Direction: k.Str("direction"), Measure: k.Str("measure"),
			Period: k.Str("period"), Weight: nullF(k["weight"]), kpiRes: kpiResult(def, months, upTo)})
	}
	ki := make([]kpiItem, len(items))
	for i, it := range items {
		ki[i] = kpiItem{it.Result, it.Weight, it.HasData}
	}
	section := kpiSectionOf(ki)
	for i := range items {
		items[i].UsedWeight = section.Weights[i]
		items[i].Weighted = round2(items[i].Result * section.Weights[i] / 100)
	}
	// 2. On time — months whose deadline has passed (or that were sent anyway)
	today := kpisub.Today()
	submitter := map[int]float64{}
	for m := 1; m <= upTo; m++ {
		due := kpisub.DueDate(year, m)
		mEnd := time.Date(year, time.Month(m)+1, 0, 0, 0, 0, 0, conv.Loc).Format("2006-01-02")
		mStart := time.Date(year, time.Month(m), 1, 0, 0, 0, 0, conv.Loc).Format("2006-01-02")
		var active []db.Row
		for _, k := range kpis {
			if k.Str("period_start") <= mEnd && k.Str("period_end") >= mStart {
				active = append(active, k)
			}
		}
		if len(active) == 0 {
			continue
		}
		any := false
		for _, k := range active {
			if _, ok := byKpi[k.Int("id")][m]; ok {
				any = true
			}
		}
		if !due.Before(today) && !any { // $due >= $today
			continue
		}
		sum := 0.0
		for _, k := range active {
			s, ok := byKpi[k.Int("id")][m]
			switch {
			case !ok:
			case s.Bool("corrected"):
			default:
				sum += s.Float("percentage")
			}
		}
		submitter[m] = round2(sum / float64(len(active)))
	}
	apprList := map[int][]float64{}
	for _, a := range db.Select("SELECT period_month, period_year, approved_at FROM kpi_submissions WHERE approver_id = ? AND period_year = ? AND period_month <= ? AND deleted_at IS NULL", userID, year, upTo) {
		by := kpisub.ApproveBy(year, a.Int("period_month"))
		var at time.Time
		switch {
		case !a.Nil("approved_at"):
			at, _ = conv.ParseTime(conv.Substr(a.Str("approved_at"), 0, 10))
		case today.After(by):
			at = today
		default:
			continue // still in time to approve
		}
		apprList[a.Int("period_month")] = append(apprList[a.Int("period_month")], lateRate(kpisub.DaysBetween(by, at)))
	}
	approver := map[int]float64{}
	for m, l := range apprList {
		s := 0.0
		for _, v := range l {
			s += v
		}
		approver[m] = round2(s / float64(len(l)))
	}
	ontime := onTimeOf(submitter, approver)
	// 3, 4, 5, 6, 7
	var qo, sales *rateRes
	if in["qo_actual"] != nil && in["qo_target"] != nil {
		r := rate(f(in["qo_actual"]), f(in["qo_target"]), "min")
		qo = &r
	}
	if in["sales_actual"] != nil && in["sales_target"] != nil {
		r := rate(f(in["sales_actual"]), f(in["sales_target"]), "min")
		sales = &r
	}
	qm := qmsOf(in)
	ab := absenceOf(in)
	rates := map[string]float64{"kpi": section.Rate, "ontime": ontime.Rate, "qms": qm.Points, "leader": f(in["leader"]), "absence": ab.Rate}
	if qo != nil {
		rates["qo"] = qo.Result
	}
	if sales != nil {
		rates["sales"] = sales.Result
	}
	t := totalOf(group, rates, in)
	return calcRes{Year: year, Month: upTo, Group: group, KPIs: items, KPI: section, Waiting: waiting, OnTime: ontime, QO: qo, Sales: sales, QMS: qm, Absence: ab, Leader: in["leader"], totalRes: t}
}

// inputsMap reads saved inputs (JSON text) into a plain map.
func inputsMap(raw any) map[string]any {
	out := map[string]any{}
	if s := conv.Str(raw); s != "" {
		if v, err := config.Decode([]byte(s)); err == nil {
			for k, x := range asMap(v) {
				out[k] = x
			}
		}
	}
	return out
}

var _ = app.M{}
