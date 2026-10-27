package accounting

import (
	"errors"
	"math"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/php"
)

// ---------------------------------------------------------------- Dashboard (numbers of the Accounting overview)

// invoice line value in THB (the same rule as the e-tax JSON)
const lineSQL = "(coalesce(i.invoiced_quantity, 0) / CASE WHEN coalesce(i.conversion_factor, 0) = 0 THEN 1 ELSE i.conversion_factor END) * coalesce(i.price, 0) * (1 - coalesce(i.discount, 0) / 100) * coalesce(nullif(i.exchange_rate, 0), 1)"
const bkkSQL = "(i.invoice_date AT TIME ZONE 'Asia/Bangkok')"

var (
	cacheMu   sync.Mutex
	dashCache app.M
	dashAt    time.Time
	rankCache = map[string]app.M{}
	rankAt    = map[string]time.Time{}
)

// dashboardGet: heavy sums are kept for five minutes.
func dashboardGet(fresh bool) app.M {
	cacheMu.Lock()
	if !fresh && dashCache != nil && time.Since(dashAt) < 300*time.Second {
		d := dashCache
		cacheMu.Unlock()
		return d
	}
	cacheMu.Unlock()
	d := dashboardBuild()
	cacheMu.Lock()
	dashCache, dashAt = d, time.Now()
	cacheMu.Unlock()
	return d
}

func q(sql string, p ...any) []app.M { return rowsM(acc().Select(sql, p...)) }

func dashboardBuild() app.M {
	line, bkk := lineSQL, bkkSQL
	now := php.Now()
	year := now.Year()
	month := int(now.Month())
	d := app.M{"built": php.Date("c", now), "year": year}

	// ---------- revenue
	d["by_year"] = q("SELECT extract(year FROM " + bkk + ")::int AS y, round(sum(" + line + ")::numeric, 2) AS v FROM erp_invoices i WHERE i.invoice_date IS NOT NULL AND coalesce(i.order_row_type, 1) = 1 GROUP BY 1 ORDER BY 1")
	m := acc().Select(`SELECT extract(year FROM `+bkk+`)::int AS y, extract(month FROM `+bkk+`)::int AS m, round(sum(`+line+`)::numeric, 2) AS v, count(DISTINCT i.invoice_number) AS n
                        FROM erp_invoices i WHERE i.invoice_date >= make_date(?, 1, 1) AND coalesce(i.order_row_type, 1) = 1 GROUP BY 1, 2`, year-1)
	cur := make([]float64, 13)
	prev := make([]float64, 13)
	cnt := make([]int, 13)
	for _, r := range m {
		if r.Int("y") == year {
			cur[r.Int("m")] = r.Float("v")
			cnt[r.Int("m")] = r.Int("n")
		} else {
			prev[r.Int("m")] = r.Float("v")
		}
	}
	mc, mp := []any{}, []any{}
	for i := 1; i <= 12; i++ {
		mc = append(mc, cur[i])
		mp = append(mp, prev[i])
	}
	d["month_cur"], d["month_prev"] = mc, mp
	last := prev[12]
	if month > 1 {
		last = cur[month-1]
	}
	ytd, ytdPrev := 0.0, 0.0
	for i := 1; i <= month; i++ {
		ytd += cur[i]
		ytdPrev += prev[i]
	}
	d["kpi"] = app.M{"month": cur[month], "month_prev_year": prev[month], "month_last": last, "ytd": ytd, "ytd_prev": ytdPrev, "invoices_month": cnt[month]}
	since := php.Date("Y-m-d", now.AddDate(0, -12, 0))
	d["customers"] = q(`SELECT coalesce(nullif(c.name, ''), i.customer_code, '—') AS name, round(sum(`+line+`)::numeric, 2) AS v FROM erp_invoices i LEFT JOIN erp_customers c ON c.id = i.customer_id
                                    WHERE i.invoice_date >= ? AND coalesce(i.order_row_type, 1) = 1 GROUP BY 1 ORDER BY v DESC LIMIT 10`, since)
	d["sellers"] = q("SELECT coalesce(nullif(i.seller, ''), '—') AS name, round(sum("+line+")::numeric, 2) AS v FROM erp_invoices i WHERE i.invoice_date >= ? AND coalesce(i.order_row_type, 1) = 1 GROUP BY 1 ORDER BY v DESC LIMIT 8", since)
	d["products"] = q("SELECT coalesce(nullif(i.part_number, ''), '—') AS name, round(sum("+line+")::numeric, 2) AS v FROM erp_invoices i WHERE i.invoice_date >= ? AND coalesce(i.order_row_type, 1) = 1 GROUP BY 1 ORDER BY v DESC LIMIT 8", since)

	// ---------- orders
	d["orders_month"] = q("SELECT to_char(date_trunc('month', o.order_date AT TIME ZONE 'Asia/Bangkok'), 'YYYY-MM') AS ym, count(*)::int AS n FROM erp_orders o WHERE o.order_date >= date_trunc('month', now()) - interval '11 months' GROUP BY 1 ORDER BY 1")
	d["orders_this_month"] = php.Int(acc().Scalar("SELECT count(*) FROM erp_orders o WHERE (o.order_date AT TIME ZONE 'Asia/Bangkok') >= date_trunc('month', now() AT TIME ZONE 'Asia/Bangkok')"))

	// ---------- receivables
	d["aging"] = q(`SELECT b, round(sum(rest)::numeric, 2) AS v FROM (SELECT coalesce(r.rest_amount, 0) * coalesce(nullif(r.exchange_rate, 0), 1) AS rest,
                                  CASE WHEN r.due_date IS NULL OR r.due_date >= now() THEN '0 not due' WHEN r.due_date >= now() - interval '30 days' THEN '1 1–30' WHEN r.due_date >= now() - interval '60 days' THEN '2 31–60'
                                       WHEN r.due_date >= now() - interval '90 days' THEN '3 61–90' ELSE '4 90+' END AS b FROM erp_receivables r WHERE coalesce(r.rest_amount, 0) > 0 AND coalesce(r.is_credit, false) = false) x GROUP BY b ORDER BY b`)
	d["receivable"] = php.Float(acc().Scalar("SELECT coalesce(sum(coalesce(rest_amount, 0) * coalesce(nullif(exchange_rate, 0), 1)), 0) FROM erp_receivables WHERE coalesce(rest_amount, 0) > 0 AND coalesce(is_credit, false) = false"))

	// ---------- e-tax (inets)
	inet := q(`SELECT
              count(*) FILTER (WHERE coalesce(text_388, text_t01, text_81) IS NOT NULL AND NOT (coalesce(is_388_send, false) OR coalesce(is_t01_send, false) OR coalesce(is_81_send, false)) AND NOT (coalesce(inet_pdf_388, '') <> '' OR coalesce(inet_pdf_t01, '') <> '' OR coalesce(inet_pdf_81, '') <> '')) AS waiting,
              count(*) FILTER (WHERE (coalesce(transaction_388, '') <> '' OR coalesce(transaction_t01, '') <> '' OR coalesce(transaction_81, '') <> '') AND NOT (coalesce(inet_pdf_388, '') <> '' OR coalesce(inet_pdf_t01, '') <> '' OR coalesce(inet_pdf_81, '') <> '')) AS processing,
              count(*) FILTER (WHERE coalesce(inet_pdf_388, '') <> '' OR coalesce(inet_pdf_t01, '') <> '' OR coalesce(inet_pdf_81, '') <> '') AS signed,
              count(*) FILTER (WHERE coalesce(text_388, text_t01, text_81) IS NULL) AS not_generated,
              count(*) AS total FROM inets`)
	if len(inet) > 0 {
		d["inet"] = inet[0]
	} else {
		d["inet"] = []any{}
	}
	d["inet_month"] = q("SELECT to_char(date_trunc('month', created_at AT TIME ZONE 'Asia/Bangkok'), 'YYYY-MM') AS ym, count(*)::int AS n FROM inets WHERE created_at >= date_trunc('month', now()) - interval '5 months' GROUP BY 1 ORDER BY 1")
	d["not_in_inet"] = php.Int(acc().Scalar("SELECT count(*) FROM erp_documents dd WHERE NOT EXISTS (SELECT 1 FROM inets n WHERE n.no_invoice = dd.invoice_number) AND dd.invoice_date >= now() - interval '60 days'"))

	// ---------- billing notes
	d["billing_status"] = q("SELECT CASE WHEN is_complete THEN 'complete' WHEN is_paid THEN 'paid' WHEN remind_date < CURRENT_DATE THEN 'overdue' ELSE 'pending' END AS s, count(*)::int AS n, round(sum(total_amount)::numeric, 2) AS v FROM billing_notes GROUP BY 1")
	d["billing_month"] = q("SELECT to_char(date_trunc('month', billing_at), 'YYYY-MM') AS ym, round(sum(total_amount)::numeric, 2) AS v FROM billing_notes WHERE billing_at >= date_trunc('month', now()) - interval '11 months' GROUP BY 1 ORDER BY 1")
	d["billing_today"] = php.Int(acc().Scalar("SELECT count(*) FROM billing_notes WHERE remind_date = CURRENT_DATE AND NOT is_complete"))
	d["billing_open"] = php.Float(acc().Scalar("SELECT coalesce(sum(total_amount), 0) FROM billing_notes WHERE NOT is_paid AND NOT is_complete"))
	return d
}

// ---------------------------------------------------------------- Ranking ('revenue.ranking')

// rankingGet: revenue rankings between two dates (total, months, top customers, sellers, departments). Kept five minutes per range.
func rankingGet(from, to string) app.M {
	if !ymdRe.MatchString(from) || !ymdRe.MatchString(to) {
		panic(errors.New("Dates must be YYYY-MM-DD."))
	}
	key := from + to
	cacheMu.Lock()
	if r, ok := rankCache[key]; ok && time.Since(rankAt[key]) < 300*time.Second {
		cacheMu.Unlock()
		return r
	}
	cacheMu.Unlock()
	line := lineSQL
	day := "(i.invoice_date AT TIME ZONE 'Asia/Bangkok')::date"
	base := `FROM erp_invoices i LEFT JOIN erp_orders o ON o.id = i.customer_order_id LEFT JOIN erp_sellers s ON s.id = o.seller_id
                 LEFT JOIN erp_customers c ON c.id = i.customer_id
                 WHERE i.invoice_date IS NOT NULL AND ` + day + ` BETWEEN ?::date AND ?::date AND coalesce(i.order_row_type, 1) = 1`
	sum := acc().First("SELECT coalesce(round(sum("+line+")::numeric, 2), 0) AS v, count(DISTINCT i.invoice_number) AS n, count(DISTINCT i.customer_id) AS c "+base, from, to)

	// the same number of days just before, for the change
	tf, _ := time_parse(from)
	tt, _ := time_parse(to)
	days := int(math.Round(tt.Sub(tf).Hours()/24)) + 1
	if days < 1 {
		days = int(math.Round(tf.Sub(tt).Hours()/24)) + 1
	}
	pTo := tf.AddDate(0, 0, -1)
	pFrom := pTo.AddDate(0, 0, -(days - 1))
	prev := php.Float(acc().Scalar("SELECT coalesce(round(sum("+line+")::numeric, 2), 0) "+base, pFrom.Format("2006-01-02"), pTo.Format("2006-01-02")))

	months := config.NewMap()
	for _, r := range acc().Select("SELECT to_char("+day+", 'YYYY-MM') AS ym, round(sum("+line+")::numeric, 2) AS v "+base+" GROUP BY 1 ORDER BY 1", from, to) {
		months.Set(r.Str("ym"), r.Float("v"))
	}
	var customers, sellers, depts []app.M
	for _, r := range acc().Select(`SELECT coalesce(nullif(c.name, ''), i.customer_code, '—') AS name, max(i.customer_code) AS code, round(sum(`+line+`)::numeric, 2) AS v,
                count(DISTINCT i.invoice_number) AS n `+base+` GROUP BY 1 ORDER BY v DESC LIMIT 10`, from, to) {
		customers = append(customers, app.M{"name": r["name"], "code": r["code"], "v": r.Float("v"), "n": r.Int("n")})
	}
	intOrNil := func(v any) any {
		if v == nil {
			return nil
		}
		return php.Int(v)
	}
	for _, r := range acc().Select(`SELECT s.id AS seller_id, coalesce(nullif(trim(concat_ws(' ', s.first_name, s.last_name)), ''), nullif(i.seller, ''), '—') AS name,
                max(s.user_id) AS user_id, max(s.app_department_id) AS department_id, round(sum(`+line+`)::numeric, 2) AS v,
                count(DISTINCT i.invoice_number) AS n, count(DISTINCT i.customer_id) AS customers `+base+` GROUP BY 1, 2 ORDER BY v DESC LIMIT 20`, from, to) {
		sellers = append(sellers, app.M{"seller_id": intOrNil(r["seller_id"]), "name": r["name"], "user_id": intOrNil(r["user_id"]), "department_id": intOrNil(r["department_id"]),
			"v": r.Float("v"), "n": r.Int("n"), "customers": r.Int("customers")})
	}
	for _, r := range acc().Select(`SELECT s.app_department_id AS department_id, round(sum(`+line+`)::numeric, 2) AS v, count(DISTINCT s.id) AS sellers,
                count(DISTINCT i.invoice_number) AS n `+base+` GROUP BY 1 ORDER BY v DESC`, from, to) {
		depts = append(depts, app.M{"department_id": intOrNil(r["department_id"]), "v": r.Float("v"), "sellers": r.Int("sellers"), "n": r.Int("n")})
	}
	if customers == nil {
		customers = []app.M{}
	}
	if sellers == nil {
		sellers = []app.M{}
	}
	if depts == nil {
		depts = []app.M{}
	}
	out := app.M{"total": sum.Float("v"), "invoices": sum.Int("n"), "customers_count": sum.Int("c"), "previous": prev,
		"previous_range": []any{pFrom.Format("2006-01-02"), pTo.Format("2006-01-02")}, "months": months,
		"customers": customers, "sellers": sellers, "departments": depts, "built": php.Date("c", php.Now())}
	cacheMu.Lock()
	rankCache[key], rankAt[key] = out, time.Now()
	cacheMu.Unlock()
	return out
}

func time_parse(s string) (time.Time, error) { return time.Parse("2006-01-02", s) }

// ---------------------------------------------------------------- CustomerLink ('customer.by_tax', 'customer.get', 'customer.invoices', 'customer.quotes')

// Tax IDs are compared on their digits only, so "0-1055-51234-56-7" and "0105551234567" match.
func vatSQL(col string) string { return "regexp_replace(coalesce(" + col + ", ''), '\\D', '', 'g')" }

func customerByTax(taxID string) []app.M {
	d := digits(taxID)
	if len(d) < 5 {
		return []app.M{}
	}
	rows := acc().Select(`SELECT c.id::text AS id, c.name, c.code, c.vat_number,
                (SELECT count(*) FROM erp_documents x WHERE x.customer_id = c.id) AS invoices,
                (SELECT count(*) FROM erp_quotes q WHERE q.customer_id = c.id) AS quotes
            FROM erp_customers c WHERE `+vatSQL("c.vat_number")+` = ? ORDER BY invoices DESC, c.name LIMIT 20`, d)
	return rowsM(rows)
}

func customerGet(id string) any {
	if !custIDRe.MatchString(id) {
		return nil
	}
	r := acc().First("SELECT id::text AS id, name, code, vat_number FROM erp_customers WHERE id = ?::bigint", id)
	if r == nil {
		return nil
	}
	return app.M(r)
}

func customerInvoices(taxID string, limit int) app.M {
	d := digits(taxID)
	if len(d) < 5 {
		return app.M{"rows": []app.M{}, "total": 0, "sum": 0.0}
	}
	where := vatSQL("d.vat_no") + " = ? OR d.customer_id IN (SELECT c.id FROM erp_customers c WHERE " + vatSQL("c.vat_number") + " = ?)"
	tot := acc().First("SELECT count(*) AS n, COALESCE(sum(CASE WHEN d.is_credit THEN -d.amount ELSE d.amount END), 0) AS v FROM erp_documents d WHERE "+where, d, d)
	rows := acc().Select(`SELECT d.invoice_number, d.invoice_date, d.customer_name, d.order_number, d.currency_code, d.amount, d.is_credit,
                coalesce((SELECT i.seller FROM erp_invoices i WHERE i.invoice_number = d.invoice_number AND coalesce(i.seller, '') <> '' LIMIT 1),
                         (SELECT trim(concat_ws(' ', s.first_name, s.last_name)) FROM erp_orders o JOIN erp_sellers s ON s.id = o.seller_id WHERE o.id = d.order_id LIMIT 1)) AS seller
            FROM erp_documents d WHERE `+where+` ORDER BY d.invoice_date DESC NULLS LAST, d.invoice_number DESC LIMIT `+php.Str(limit), d, d)
	return app.M{"rows": rowsM(rows), "total": tot.Int("n"), "sum": tot.Float("v")}
}

func customerQuotes(customerID string, limit int) []app.M {
	if !custIDRe.MatchString(customerID) {
		return []app.M{}
	}
	return rowsM(acc().Select(`SELECT q.id::text AS id, q.order_number, q.quote_date, q.valid_through_date, q.quote_description, q.status, q.life_cycle_state,
                q.edited_order_amount_excluding_vat AS amount, q.our_reference_name,
                (SELECT trim(concat_ws(' ', s.first_name, s.last_name)) FROM erp_sellers s WHERE s.id = q.seller_id) AS seller
            FROM erp_quotes q WHERE q.customer_id = ?::bigint ORDER BY q.quote_date DESC NULLS LAST, q.id DESC LIMIT `+php.Str(limit), customerID))
}

// ---------------------------------------------------------------- ProductSearch ('product.search')

func productSearch(qs string, page, per int) app.M {
	var where []string
	var params []any
	if qs != "" {
		like := "%" + likeEscape(qs) + "%"
		where = append(where, "(part_number ILIKE ? OR description ILIKE ? OR alias ILIKE ?)")
		params = append(params, like, like, like)
	}
	w := ""
	if len(where) > 0 {
		w = " WHERE " + strings.Join(where, " AND ")
	}
	rows := acc().Select("SELECT id, part_number, description, alias, standard_price FROM erp_products"+w+" ORDER BY part_number, id LIMIT "+php.Str(per+1)+" OFFSET "+php.Str((max(1, page)-1)*per), params...)
	items := []app.M{}
	for i, r := range rows {
		if i >= per {
			break
		}
		var price any
		if r["standard_price"] != nil {
			price = phpRound(r.Float("standard_price"), 2)
		}
		name := php.Str(php.Or(r["description"], php.Or(r["alias"], php.Or(r["part_number"], "#"+r.Str("id")))))
		items = append(items, app.M{"id": r.Int("id"), "part_number": r["part_number"], "name": name, "price": price})
	}
	return app.M{"more": len(rows) > per, "items": items}
}

// ---------------------------------------------------------------- DepartmentInvoices ('erp.invoices', 'erp.invoice_pdf')

/*
Stamped (INET-signed) Inets of one department, for ERP → Invoices in User Management. Rules enforced here too: permission
erp_invoices.view, people see only their own department (inets.app_department_id, copied from the seller), administrators see
every department.
*/

var deptDocs = map[string]string{"388": "Tax invoice", "t01": "Receipt", "81": "Credit note"}

const signedSQL = "(t.inet_pdf_388 IS NOT NULL OR t.inet_pdf_t01 IS NOT NULL OR t.inet_pdf_81 IS NOT NULL)"

// deptOf: the department this person may see; all = every department (administrators); ok false = nothing.
func deptOf(u *app.CurrentUser, perm string, pick int) (dept int, all bool, ok bool) {
	if u == nil || !u.Can(perm, "view") {
		return 0, false, false
	}
	if u.IsAdmin() {
		if pick > 0 {
			return pick, false, true
		}
		return 0, true, true
	}
	if u.DepartmentID == 0 {
		return 0, false, false
	}
	return u.DepartmentID, false, true
}

func emptyList() app.M {
	return app.M{"rows": []app.M{}, "total": 0, "page": 1, "pages": 1, "all_departments": false}
}

func filterLike(f app.M) string {
	q := strings.TrimSpace(php.Str(f["q"]))
	if q == "" {
		return ""
	}
	return "%" + likeEscape(php.Substr(q, 0, 100)) + "%"
}

func deptInvoicesList(u *app.CurrentUser, f app.M) app.M {
	per := 25
	page := max(1, php.Int(php.Coalesce(f["page"], 1)))
	dept, all, ok := deptOf(u, "erp_invoices", php.Int(f["department"]))
	if !ok {
		return emptyList()
	}
	w := []string{signedSQL}
	var p []any
	if !all {
		w = append(w, "t.app_department_id = ?")
		p = append(p, dept)
	}
	if like := filterLike(f); like != "" {
		w = append(w, "(t.no_invoice::text ILIKE ? OR t.customer_name ILIKE ? OR t.customer_code ILIKE ? OR t.customer_order_number ILIKE ?)")
		p = append(p, like, like, like, like)
	}
	for _, x := range [][2]string{{"from", ">="}, {"to", "<="}} {
		if v := php.Str(f[x[0]]); ymdRe.MatchString(v) {
			w = append(w, "t.invoice_date "+x[1]+" ?::date")
			p = append(p, v)
		}
	}
	where := strings.Join(w, " AND ")
	total := php.Int(acc().Scalar("SELECT count(*) FROM inets t WHERE "+where, p...))
	rows := acc().Select(`SELECT t.id, t.no_invoice, t.invoice_date, t.is_credit, t.customer_code, t.customer_name, t.customer_order_number, t.seller_name,
                t.total_amount, t.currency_code, t.app_department_id, t.inet_pdf_388 IS NOT NULL AS s388, t.inet_pdf_t01 IS NOT NULL AS st01, t.inet_pdf_81 IS NOT NULL AS s81
            FROM inets t WHERE `+where+` ORDER BY t.invoice_date DESC NULLS LAST, t.no_invoice DESC LIMIT `+php.Str(per)+` OFFSET `+php.Str((page-1)*per), p...)
	out := []app.M{}
	for _, r := range rows {
		docs := []any{}
		for _, d := range []string{"388", "t01", "81"} {
			if r.Bool("s" + d) {
				docs = append(docs, d)
			}
		}
		m := app.M(r)
		delete(m, "s388")
		delete(m, "st01")
		delete(m, "s81")
		m["documents"] = docs
		out = append(out, m)
	}
	return app.M{"rows": out, "total": total, "page": page, "pages": max(1, int(math.Ceil(float64(total)/float64(per)))), "all_departments": all}
}

// safeStored: the absolute path of a stored file under storage/<prefix>, or "" (never outside it).
func safeStored(key, prefix string) string {
	if key == "" || !strings.HasPrefix(key, prefix) || strings.Contains(key, "..") || strings.Contains(key, "\x00") {
		return ""
	}
	base, err1 := filepath.EvalSymlinks(filepath.Join(app.BasePath, "storage", strings.TrimSuffix(prefix, "/")))
	path, err2 := filepath.EvalSymlinks(filepath.Join(app.BasePath, "storage", key))
	if err1 != nil || err2 != nil || !strings.HasPrefix(path, base+string(filepath.Separator)) {
		return ""
	}
	if st, err := os.Stat(path); err != nil || !st.Mode().IsRegular() {
		return ""
	}
	return path
}

// fileM is a file for another module: M{"name", "mime", "body"} (+ the PHP keys "path" and "label").
func fileM(path, name, label string) app.M {
	body, err := os.ReadFile(path)
	if err != nil {
		return nil
	}
	return app.M{"name": name, "mime": "application/pdf", "body": body, "path": path, "label": label}
}

var docRe = regexp.MustCompile(`^(388|t01|81)$`)

func deptInvoicePDF(u *app.CurrentUser, id int, doc string) app.M {
	dept, all, ok := deptOf(u, "erp_invoices", 0)
	if !ok || !docRe.MatchString(doc) {
		return nil
	}
	r := acc().First("SELECT no_invoice, app_department_id, inet_pdf_"+doc+" AS f FROM inets WHERE id = ?", id)
	if r == nil || (!all && r.Int("app_department_id") != dept) {
		return nil // not found, or another department's: same answer
	}
	path := safeStored(r.Str("f"), "inet/")
	if path == "" {
		return nil
	}
	return fileM(path, r.Str("no_invoice")+"_"+strings.ToUpper(doc)+"_signed.pdf", r.Str("no_invoice")+" "+deptDocs[doc])
}

// ---------------------------------------------------------------- DepartmentBilling ('erp.billing', 'erp.billing_pdf')

// A note belongs to a department when one of its invoices does: the stamped Inet's department, or else the department
// set on the order's seller (Accounting → Sellers).
const deptOfSQL = `coalesce(
        (SELECT i.app_department_id FROM inets i WHERE i.no_invoice = bi.invoice_number AND i.app_department_id IS NOT NULL LIMIT 1),
        (SELECT s.app_department_id FROM erp_invoices ei JOIN erp_orders o ON o.id = ei.customer_order_id JOIN erp_sellers s ON s.id = o.seller_id
          WHERE ei.invoice_number = bi.invoice_number AND s.app_department_id IS NOT NULL LIMIT 1))`

func deptBillingList(u *app.CurrentUser, f app.M) app.M {
	per := 25
	page := max(1, php.Int(php.Coalesce(f["page"], 1)))
	dept, all, ok := deptOf(u, "erp_billing", php.Int(f["department"]))
	if !ok {
		return emptyList()
	}
	w := []string{"TRUE"}
	var p []any
	if !all {
		w = append(w, "EXISTS (SELECT 1 FROM billing_note_invoices bi WHERE bi.billing_id = n.id AND "+deptOfSQL+" = ?)")
		p = append(p, dept)
	}
	if like := filterLike(f); like != "" {
		w = append(w, "(n.billing_number ILIKE ? OR n.customer_name ILIKE ? OR n.customer_code ILIKE ? OR EXISTS (SELECT 1 FROM billing_note_invoices x WHERE x.billing_id = n.id AND x.invoice_number::text ILIKE ?))")
		p = append(p, like, like, like, like)
	}
	for _, x := range [][2]string{{"from", ">="}, {"to", "<="}} {
		if v := php.Str(f[x[0]]); ymdRe.MatchString(v) {
			w = append(w, "n.billing_at "+x[1]+" ?::date")
			p = append(p, v)
		}
	}
	where := strings.Join(w, " AND ")
	total := php.Int(acc().Scalar("SELECT count(*) FROM billing_notes n WHERE "+where, p...))
	rows := acc().Select(`SELECT n.id, n.billing_number, n.billing_at, n.remind_date, n.customer_code, n.customer_name, n.total_amount, n.currency_code, n.is_paid, n.is_complete,
                (SELECT count(*) FROM billing_note_invoices c WHERE c.billing_id = n.id) AS invoice_count,
                (SELECT string_agg(c.invoice_number::text, ', ' ORDER BY c.invoice_number) FROM billing_note_invoices c WHERE c.billing_id = n.id) AS invoice_numbers,
                (SELECT `+deptOfSQL+` FROM billing_note_invoices bi WHERE bi.billing_id = n.id ORDER BY bi.id LIMIT 1) AS app_department_id
            FROM billing_notes n WHERE `+where+` ORDER BY n.billing_at DESC, n.id DESC LIMIT `+php.Str(per)+` OFFSET `+php.Str((page-1)*per), p...)
	return app.M{"rows": rowsM(rows), "total": total, "page": page, "pages": max(1, int(math.Ceil(float64(total)/float64(per)))), "all_departments": all}
}

func deptBillingPDF(c *app.Ctx, u *app.CurrentUser, id int) app.M {
	dept, all, ok := deptOf(u, "erp_billing", 0)
	if !ok {
		return nil
	}
	var n db.Row
	if all {
		n = acc().First("SELECT id, billing_number FROM billing_notes n WHERE n.id = ?", id)
	} else {
		n = acc().First("SELECT id, billing_number FROM billing_notes n WHERE n.id = ? AND EXISTS (SELECT 1 FROM billing_note_invoices bi WHERE bi.billing_id = n.id AND "+deptOfSQL+" = ?)", id, dept)
	}
	if n == nil {
		return nil // not found, or another department's: same answer
	}
	path := billingPDF(c, n.Int("id"), false)
	rel, err := filepath.Rel(filepath.Join(app.BasePath, "storage"), path)
	if err != nil || safeStored(filepath.ToSlash(rel), "billing/") == "" {
		return nil
	}
	name := regexp.MustCompile(`[^A-Za-z0-9._-]`).ReplaceAllString(n.Str("billing_number"), "_") + ".pdf"
	return fileM(path, name, n.Str("billing_number"))
}
