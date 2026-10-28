package crm

import (
	"fmt"
	"strings"

	"acme/internal/app"
	"acme/internal/conv"
	"acme/internal/db"
)

// ---------------------------------------------------------------- Revenue (from Accounting)

// erpID: ERP department id of a CRM/core department, 0 when it has none.
func erpID(departmentID int) int {
	return conv.Int(db.Scalar("SELECT erp_department_id FROM departments WHERE id = ?", departmentID))
}

// anyMapped: at least one department is tied to an ERP department.
func anyMapped() bool {
	return db.Scalar("SELECT 1 FROM departments WHERE erp_department_id IS NOT NULL AND deleted_at IS NULL LIMIT 1") != nil
}

// revenueQuarters: invoiced revenue per quarter in the base currency. erpDep 0 = the whole company.
func revenueQuarters(erpDep, year int) [5]float64 {
	var out [5]float64
	if !app.ModuleUsable("accounting") {
		return out // Accounting off or unavailable: targets show 0
	}
	var rows []db.Row
	err := db.Try(func() {
		join, where := "", ""
		params := []any{year}
		if erpDep != 0 {
			join = "JOIN erp_orders o ON o.id = d.order_id JOIN erp_sellers s ON s.id = o.seller_id "
			where = " AND s.department_id = ?"
			params = append(params, erpDep)
		}
		rows = db.On("accounting").Select(`SELECT extract(quarter FROM (d.invoice_date AT TIME ZONE 'Asia/Bangkok'))::int AS q, COALESCE(NULLIF(d.currency_code, ''), 'THB') AS cur,
                           COALESCE(sum(CASE WHEN d.is_credit THEN -d.amount ELSE d.amount END), 0) AS v
                      FROM erp_documents d `+join+`
                     WHERE d.invoice_date IS NOT NULL AND extract(year FROM (d.invoice_date AT TIME ZONE 'Asia/Bangkok')) = ?`+where+` GROUP BY 1, 2`, params...)
	})
	if err != nil {
		return out // Accounting not set up yet
	}
	rates := map[string]float64{}
	for _, r := range db.On("crm").Select("SELECT code, rate_to_base FROM currencies") {
		rates[r.Str("code")] = r.Float("rate_to_base")
	}
	for _, r := range rows {
		rate, ok := rates[strings.ToUpper(r.Str("cur"))]
		if !ok {
			rate = 1
		}
		q := r.Int("q")
		if q >= 1 && q <= 4 {
			out[q] += r.Float("v") * rate
		}
	}
	return out
}

// ---------------------------------------------------------------- Targets

func baseCurrency() string {
	v := db.On("crm").Scalar("SELECT code FROM currencies WHERE is_base LIMIT 1")
	if conv.Empty(v) {
		return "THB"
	}
	return conv.Str(v)
}

// targetSource: "accounting" (revenue of the department's ERP department / the whole company) or "crm" (opportunities won).
func targetSource(departmentID int) string {
	if departmentID != 0 {
		if erpID(departmentID) != 0 {
			return "accounting"
		}
		return "crm"
	}
	if anyMapped() {
		return "accounting"
	}
	return "crm"
}

func targetLive(departmentID, year int) [5]float64 {
	if targetSource(departmentID) == "accounting" {
		e := 0
		if departmentID != 0 {
			e = erpID(departmentID)
		}
		return revenueQuarters(e, year)
	}
	return targetWon(departmentID, year)
}

// targetWon: won value per quarter of a year; departmentID 0 = every department.
func targetWon(departmentID, year int) [5]float64 {
	sql := `SELECT extract(quarter FROM COALESCE(t.close_at, t.updated_at))::int AS q, COALESCE(sum(COALESCE(t.amount, 0) * COALESCE(c.rate_to_base, 1)), 0) AS v
                  FROM opportunities t LEFT JOIN currencies c ON c.code = t.currency
                 WHERE t.deleted_at IS NULL AND t.opportunity_stage = 'CLOSED_WON' AND extract(year FROM COALESCE(t.close_at, t.updated_at)) = ?`
	params := []any{year}
	if departmentID != 0 {
		sql += " AND t.department_id = ?"
		params = append(params, departmentID)
	}
	var out [5]float64
	for _, r := range db.On("crm").Select(sql+" GROUP BY 1", params...) {
		if q := r.Int("q"); q >= 1 && q <= 4 {
			out[q] = r.Float("v")
		}
	}
	return out
}

// targetRow: the saved row for a department and year (or an empty one).
func targetRow(departmentID, year int) db.Row {
	if r := db.On("crm").First("SELECT * FROM sales_targets WHERE department_id = ? AND year = ?", departmentID, year); r != nil {
		return r
	}
	return db.Row{"department_id": departmentID, "year": year, "q1": 0, "q2": 0, "q3": 0, "q4": 0, "total": 0, "a1": nil, "a2": nil, "a3": nil, "a4": nil, "actuals_saved_at": nil}
}

type targetSummary struct {
	Target      [5]float64
	Total       float64
	Actual      [5]float64
	ActualTotal float64
	Source      string
}

// targetSummaryOf: target and actual for the dashboard. One department, or (0) all of them added up.
func targetSummaryOf(departmentID, year int) targetSummary {
	var rows []db.Row
	if departmentID != 0 {
		rows = db.On("crm").Select("SELECT q1, q2, q3, q4, total FROM sales_targets WHERE department_id = ? AND year = ?", departmentID, year)
	} else {
		rows = db.On("crm").Select("SELECT q1, q2, q3, q4, total FROM sales_targets WHERE year = ?", year)
	}
	var s targetSummary
	for _, r := range rows {
		for q := 1; q <= 4; q++ {
			s.Target[q] += r.Float(fmt.Sprintf("q%d", q))
		}
		s.Total += r.Float("total")
	}
	s.Actual = targetLive(departmentID, year)
	for q := 1; q <= 4; q++ {
		s.ActualTotal += s.Actual[q]
	}
	s.Source = targetSource(departmentID)
	return s
}
