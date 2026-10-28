package accounting

import (
	"acme/internal/api"
	"acme/internal/config"
	"html/template"
	"os"
	"path/filepath"
	"regexp"
	"strings"

	"acme/internal/app"
	"acme/internal/conv"
	"acme/internal/db"
)

/*
Accounting for the mobile app (Api\AccountingApi) — READ ONLY. Look up customers, products, orders, invoices and sellers; view
e-tax documents (Inet) and billing notes and download their stored PDFs; see the overview figures. Nothing here generates, sends,
syncs or rebuilds anything: a PDF that was never generated is a 404, and the ERP schedule is not started by API requests.
Permissions are the website's (accounting_* view).
*/

func payloadJS(s string) template.JS { return template.JS(s) }

func apiNeed(c *app.Ctx, res string) { api.Need(c, res) }
func apiNotFound(c *app.Ctx)         { api.NotFound(c) }
func apiQ(c *app.Ctx) string         { return api.Q(c) }
func apiLike(q string) string        { return api.Like(q) }

// apiPaged answers one page of sql (see api.Paged); mapRow (optional) turns a row into what the app gets.
func apiPaged(c *app.Ctx, sql string, params []any, order string, mapRow func(db.Row) any) {
	var mf func(*config.Map) any
	if mapRow != nil {
		mf = func(m *config.Map) any {
			r := db.Row{}
			for _, k := range m.Keys {
				r[k] = m.Vals[k]
			}
			return mapRow(r)
		}
	}
	api.Reply(c, api.Paged(c, sql, params, order, C, mf))
}

// ordRow is the first row of a query as an ordered object (nil when there is none).
func ordRow(sql string, params ...any) *config.Map {
	rows := acc().SelectOrdered(sql, params...)
	if len(rows) == 0 {
		return nil
	}
	return api.RowObj(rows[0])
}

func ordRows(sql string, params ...any) []any {
	out := []any{}
	for _, r := range acc().SelectOrdered(sql, params...) {
		out = append(out, api.RowObj(r))
	}
	return out
}

func pickKeys(r db.Row, keys ...string) *config.Map { return api.Pick(r, keys...) }

func apiStats(c *app.Ctx) {
	u := api.User(c)
	show := map[string]bool{"finance": u.Can("accounting_invoices", "view"), "orders": u.Can("accounting_orders", "view"), "inet": u.Can("accounting_inet", "view"), "billing": u.Can("accounting_billing", "view")}
	if !show["finance"] && !show["orders"] && !show["inet"] && !show["billing"] {
		api.Fail(403, "forbidden", c.T("You don't have permission to view the accounting overview."))
	}
	d := dashboardGet(false)
	blocks := []struct {
		name string
		keys []string
	}{{"finance", []string{"year", "by_year", "month_cur", "month_prev", "kpi", "customers", "sellers", "products", "aging", "receivable"}},
		{"orders", []string{"orders_month", "orders_this_month"}}, {"inet", []string{"inet", "inet_month", "not_in_inet"}},
		{"billing", []string{"billing_status", "billing_month", "billing_today", "billing_open"}}}
	out := api.Obj("built", d["built"])
	for _, b := range blocks {
		if show[b.name] {
			m := config.NewMap()
			for _, k := range b.keys {
				m.Set(k, d[k])
			}
			out.Set(b.name, m)
		}
	}
	api.Reply(c, api.Obj("data", out))
}

const apiCustomerSQL = `SELECT t.id, t.code, t.name, t.alternative_name, t.vat_number, t.corporation_identification_number, t.is_private_customer, t.credit_limit,
            concat_ws(' ', a.addressee, a.field1, a.field2, a.field3, a.locality, a.region, a.postal_code) AS address,
            nullif(trim(concat_ws(' ', s.first_name, s.last_name)), '') AS seller_name
        FROM erp_customers t LEFT JOIN erp_addresses a ON a.id = t.mailing_address_id LEFT JOIN erp_sellers s ON s.id = t.seller_id`

func apiCustomers(c *app.Ctx) {
	apiNeed(c, "accounting_customers")
	w, p := "TRUE", []any{}
	if q := apiQ(c); q != "" {
		w = "(t.name ILIKE ? OR t.code ILIKE ? OR t.alternative_name ILIKE ? OR t.vat_number ILIKE ? OR t.corporation_identification_number ILIKE ?)"
		for i := 0; i < 5; i++ {
			p = append(p, apiLike(q))
		}
	}
	apiPaged(c, apiCustomerSQL+" WHERE "+w, p, "t.name, t.id", nil)
}

// apiCustomer: a customer and everything linked to it that the person may see.
func apiCustomer(c *app.Ctx) {
	apiNeed(c, "accounting_customers")
	id := c.ParamInt("id")
	cu := ordRow(apiCustomerSQL+" WHERE t.id = ?", id)
	if cu == nil {
		apiNotFound(c)
	}
	links := config.NewMap()
	if c.Can("accounting_invoices", "view") {
		links.Set("invoices", ordRows(`SELECT invoice_number, max(invoice_date) AS invoice_date, max(currency_code) AS currency_code,
                sum(coalesce(invoiced_quantity, 0) * coalesce(price, 0) * (1 - coalesce(discount, 0) / 100)) AS amount
                FROM erp_invoices WHERE customer_id = ? AND invoice_number IS NOT NULL GROUP BY invoice_number ORDER BY max(invoice_date) DESC, invoice_number DESC LIMIT 20`, id))
	}
	if c.Can("accounting_orders", "view") {
		links.Set("orders", ordRows("SELECT id, order_number, business_contact_order_number, order_date, status, is_credit FROM erp_orders WHERE customer_id = ? ORDER BY order_date DESC NULLS LAST, id DESC LIMIT 20", id))
	}
	if c.Can("accounting_inet", "view") {
		links.Set("inets", ordRows("SELECT id, no_invoice, invoice_date, is_credit, total_amount, currency_code FROM inets WHERE customer_id = ? ORDER BY invoice_date DESC NULLS LAST, id DESC LIMIT 20", id))
	}
	if c.Can("accounting_billing", "view") {
		links.Set("billing_notes", ordRows("SELECT id, billing_number, billing_at, total_amount, currency_code, is_paid, is_complete FROM billing_notes WHERE customer_id = ? ORDER BY id DESC LIMIT 20", conv.Str(id)))
	}
	// CRM leads with the same tax id, through the CRM's own visibility (only when the CRM is on and the person may see leads)
	if conv.Str(mget(cu, "vat_number")) != "" && app.ModuleUsable("crm") && c.Can("crm_leads", "view") {
		links.Set("crm_leads", crmLeadsFor(c, conv.Str(mget(cu, "vat_number"))))
	}
	api.Reply(c, api.Obj("data", cu, "links", links))
}

func apiProducts(c *app.Ctx) {
	apiNeed(c, "accounting_products")
	w, p := "TRUE", []any{}
	if q := apiQ(c); q != "" {
		w = "(t.part_number ILIKE ? OR t.description ILIKE ? OR t.alias ILIKE ?)"
		for i := 0; i < 3; i++ {
			p = append(p, apiLike(q))
		}
	}
	apiPaged(c, "SELECT t.id, t.part_number, t.description, t.alias, t.standard_price FROM erp_products t WHERE "+w, p, "t.part_number, t.id", nil)
}

func apiProduct(c *app.Ctx) {
	apiNeed(c, "accounting_products")
	r := ordRow("SELECT id, part_number, description, alias, standard_price, part_template_id, part_code_id FROM erp_products WHERE id = ?", c.ParamInt("id"))
	if r == nil {
		apiNotFound(c)
	}
	api.Reply(c, api.Obj("data", r))
}

const apiOrderSQL = `SELECT t.id, t.order_number, t.business_contact_order_number, t.order_date, t.vat_number, t.is_credit, t.status, t.customer_id,
            c.code AS customer_code, c.name AS customer_name, nullif(trim(concat_ws(' ', s.first_name, s.last_name)), '') AS seller_name,
            (SELECT sum(coalesce(r.ordered_quantity, 0) * coalesce(r.price, 0) * (1 - coalesce(r.discount, 0) / 100)) FROM erp_order_rows r WHERE r.parent_order_id = t.id) AS total
        FROM erp_orders t LEFT JOIN erp_customers c ON c.id = t.customer_id LEFT JOIN erp_sellers s ON s.id = t.seller_id`

// apiPeriod: ?from=YYYY-MM-DD ?to=YYYY-MM-DD (both days included) on column.
func apiPeriod(c *app.Ctx, column, w string, p []any) (string, []any) {
	for _, x := range [][2]string{{"from", ">="}, {"to", "<="}} {
		v := c.Query(x[0])
		if ymdRe.MatchString(v) {
			if t, ok := conv.ParseTime(v); ok && conv.Date("Y-m-d", t) == v {
				w += " AND " + column + "::date " + x[1] + " ?::date"
				p = append(p, v)
			}
		}
	}
	return w, p
}

// apiOrders: ?q= ?customer_id= ?credit=0|1 ?from= ?to=
func apiOrders(c *app.Ctx) {
	apiNeed(c, "accounting_orders")
	w, p := "TRUE", []any{}
	if q := apiQ(c); q != "" {
		w += " AND (t.order_number::text ILIKE ? OR t.business_contact_order_number ILIKE ? OR t.vat_number ILIKE ? OR c.name ILIKE ? OR c.code ILIKE ?)"
		for i := 0; i < 5; i++ {
			p = append(p, apiLike(q))
		}
	}
	if cid := conv.Int(c.Query("customer_id")); cid != 0 {
		w += " AND t.customer_id = ?"
		p = append(p, cid)
	}
	if cr := c.Query("credit"); cr == "0" || cr == "1" {
		w += " AND coalesce(t.is_credit, false) = ?"
		p = append(p, cr == "1")
	}
	w, p = apiPeriod(c, "t.order_date", w, p)
	apiPaged(c, apiOrderSQL+" WHERE "+w, p, "t.order_date DESC NULLS LAST, t.id DESC", nil)
}

func apiOrder(c *app.Ctx) {
	apiNeed(c, "accounting_orders")
	id := c.ParamInt("id")
	o := ordRow(apiOrderSQL+" WHERE t.id = ?", id)
	if o == nil {
		apiNotFound(c)
	}
	o.Set("rows", ordRows(`SELECT r.row_index, p.part_number, coalesce(r.additional_row_description, p.description) AS description, r.ordered_quantity, r.price, r.discount, r.delivery_date
            FROM erp_order_rows r LEFT JOIN erp_products p ON p.id = r.part_id WHERE r.parent_order_id = ? ORDER BY r.row_index, r.id`, id))
	api.Reply(c, api.Obj("data", o))
}

const apiInvoiceSQL = `SELECT i.invoice_number, max(i.invoice_date) AS invoice_date, max(i.customer_id) AS customer_id, max(i.customer_code) AS customer_code,
            max(c.name) AS customer_name, max(i.customer_order_number) AS customer_order_number, max(i.currency_code) AS currency_code, max(i.seller) AS seller, count(*) AS line_count,
            sum(coalesce(i.invoiced_quantity, 0) * coalesce(i.price, 0) * (1 - coalesce(i.discount, 0) / 100)) AS amount
        FROM erp_invoices i LEFT JOIN erp_customers c ON c.id = i.customer_id WHERE i.invoice_number IS NOT NULL %s GROUP BY i.invoice_number`

// apiInvoices: ?q= ?customer_id= ?from= ?to=
func apiInvoices(c *app.Ctx) {
	apiNeed(c, "accounting_invoices")
	w, p := "", []any{}
	if q := apiQ(c); q != "" {
		w += " AND (i.invoice_number::text ILIKE ? OR i.customer_code ILIKE ? OR c.name ILIKE ? OR i.customer_order_number ILIKE ?)"
		for i := 0; i < 4; i++ {
			p = append(p, apiLike(q))
		}
	}
	if cid := conv.Int(c.Query("customer_id")); cid != 0 {
		w += " AND i.customer_id = ?"
		p = append(p, cid)
	}
	w, p = apiPeriod(c, "i.invoice_date", w, p)
	apiPaged(c, strings.Replace(apiInvoiceSQL, "%s", w, 1), p, "invoice_date DESC NULLS LAST, invoice_number DESC", nil)
}

var invNoRe = regexp.MustCompile(`^[A-Za-z0-9._-]{1,40}$`)

func apiInvoice(c *app.Ctx) {
	apiNeed(c, "accounting_invoices")
	no := c.Param("number")
	if !invNoRe.MatchString(no) {
		apiNotFound(c)
	}
	inv := ordRow(strings.Replace(apiInvoiceSQL, "%s", " AND i.invoice_number::text = ?", 1), no)
	if inv == nil {
		apiNotFound(c)
	}
	inv.Set("lines", ordRows("SELECT part_number, invoiced_quantity, price, discount, unit_code FROM erp_invoices WHERE invoice_number::text = ? ORDER BY id", no))
	api.Reply(c, api.Obj("data", inv))
}

func apiSellers(c *app.Ctx) {
	apiNeed(c, "accounting_sellers")
	w, p := "TRUE", []any{}
	if q := apiQ(c); q != "" {
		w = "(concat_ws(' ', t.first_name, t.last_name) ILIKE ? OR t.employee_number ILIKE ? OR t.email ILIKE ? OR t.email_address ILIKE ?)"
		for i := 0; i < 4; i++ {
			p = append(p, apiLike(q))
		}
	}
	apiPaged(c, `SELECT t.id, nullif(trim(concat_ws(' ', t.first_name, t.last_name)), '') AS name, t.employee_number, t.phone_number, coalesce(t.email, t.email_address) AS email, d.name AS department
            FROM erp_sellers t LEFT JOIN erp_departments d ON d.id = t.department_id WHERE `+w, p, "t.first_name, t.last_name, t.id", nil)
}

var inetList = []string{"id", "no_invoice", "custom_invoice_no", "invoice_date", "is_credit", "is_international", "customer_id", "customer_code", "customer_name",
	"customer_order_number", "seller_name", "currency_code", "amount", "tax_amount", "total_amount"}

// apiInets: ?q= ?credit=1 (credit notes) ?send=sent|unsent ?customer_id=
func apiInets(c *app.Ctx) {
	apiNeed(c, "accounting_inet")
	credit := c.Query("credit") == "1"
	w := "t.is_credit = ?"
	p := []any{credit}
	if q := apiQ(c); q != "" {
		w += " AND (t.no_invoice::text ILIKE ? OR t.customer_code ILIKE ? OR t.customer_name ILIKE ? OR t.customer_order_number ILIKE ? OR t.tax_id ILIKE ?)"
		for i := 0; i < 5; i++ {
			p = append(p, apiLike(q))
		}
	}
	sent := "(t.is_388_send AND t.is_t01_send)"
	if credit {
		sent = "t.is_81_send"
	}
	if c.Query("send") == "sent" {
		w += " AND coalesce(" + sent + ", false)"
	}
	if c.Query("send") == "unsent" {
		w += " AND NOT coalesce(" + sent + ", false)"
	}
	if cid := conv.Int(c.Query("customer_id")); cid != 0 {
		w += " AND t.customer_id = ?"
		p = append(p, cid)
	}
	apiPaged(c, "SELECT t.* FROM inets t WHERE "+w, p, "t.no_invoice DESC", func(r db.Row) any { return apiInetOut(c, r, false) })
}

func apiInet(c *app.Ctx) {
	apiNeed(c, "accounting_inet")
	r := acc().First("SELECT * FROM inets WHERE id = ?", c.ParamInt("id"))
	if r == nil {
		apiNotFound(c)
	}
	api.Reply(c, api.Obj("data", apiInetOut(c, r, true)))
}

func apiInetOut(c *app.Ctx, r db.Row, full bool) *config.Map {
	keys := inetList
	if full {
		keys = append(append([]string{}, inetList...), "tax_id", "branch", "email", "delivery_note_number", "department_name", "exchange_rate", "tax_rate", "product_count", "remark")
	}
	out := pickKeys(r, keys...)
	docs := []any{}
	types := []string{"388", "t01"}
	if r.Bool("is_credit") {
		types = []string{"81"}
	}
	for _, d := range types {
		var msg any
		if full {
			msg = r["message_"+d]
		}
		var pdfURL, signedURL any
		if r.Str("pdf_"+d) != "" {
			pdfURL = c.URL("/api/v1/accounting/inets/" + r.Str("id") + "/pdf/" + d)
		}
		if r.Str("inet_pdf_"+d) != "" {
			signedURL = c.URL("/api/v1/accounting/inets/"+r.Str("id")+"/pdf/"+d, "signed", 1)
		}
		docs = append(docs, api.Obj("type", d, "label", c.T(deptDocs[d]), "generated", r.Str("pdf_"+d) != "", "sent", r.Bool("is_"+d+"_send"), "signed", r.Str("inet_pdf_"+d) != "",
			"message", msg, "pdf_url", pdfURL, "signed_pdf_url", signedURL))
	}
	out.Set("documents", docs)
	return out
}

// apiFile sends a stored PDF.
func apiFile(c *app.Ctx, path, name string) {
	b, err := os.ReadFile(path)
	if err != nil {
		apiNotFound(c)
	}
	c.Inline(name, "application/pdf", b)
}

// apiInetPDF: the stored PDF of one document of an Inet (?signed=1: the copy signed by INET). Never generates one.
func apiInetPDF(c *app.Ctx) {
	apiNeed(c, "accounting_inet")
	doc := c.Param("doc")
	if _, ok := deptDocs[doc]; !ok {
		apiNotFound(c)
	}
	r := acc().First("SELECT * FROM inets WHERE id = ?", c.ParamInt("id"))
	if r == nil || (doc == "81") != r.Bool("is_credit") {
		apiNotFound(c)
	}
	signed := c.Query("signed") == "1"
	k := "pdf_" + doc
	if signed {
		k = "inet_pdf_" + doc
	}
	path := safeStored(r.Str(k), "inet/")
	if path == "" {
		msg := c.T("This document has not been generated yet.")
		if signed {
			msg = c.T("INET has not returned a signed copy yet.")
		}
		api.Fail(404, "pdf_not_available", msg)
	}
	suffix := ""
	if signed {
		suffix = "_signed"
	}
	apiFile(c, path, r.Str("no_invoice")+"_"+strings.ToUpper(doc)+suffix+".pdf")
}

// apiBillingNotes: ?q= ?status=pending|overdue|paid|complete ?customer_id=
func apiBillingNotes(c *app.Ctx) {
	apiNeed(c, "accounting_billing")
	status := "CASE WHEN t.is_complete THEN 'complete' WHEN t.is_paid THEN 'paid' WHEN t.remind_date < CURRENT_DATE THEN 'overdue' ELSE 'pending' END"
	w, p := "TRUE", []any{}
	if q := apiQ(c); q != "" {
		w += " AND (t.billing_number ILIKE ? OR t.customer_code ILIKE ? OR t.customer_name ILIKE ? OR t.vat_number ILIKE ?)"
		for i := 0; i < 4; i++ {
			p = append(p, apiLike(q))
		}
	}
	if s := c.Query("status"); inStrs(s, []string{"pending", "overdue", "paid", "complete"}) {
		w += " AND " + status + " = ?"
		p = append(p, s)
	}
	if cid := conv.Int(c.Query("customer_id")); cid != 0 {
		w += " AND t.customer_id = ?"
		p = append(p, conv.Str(cid))
	}
	apiPaged(c, `SELECT t.id, t.billing_number, t.billing_at, t.remind_date, t.customer_id, t.customer_code, t.customer_name, t.vat_number, t.total_amount, t.currency_code,
            t.is_paid, t.is_complete, `+status+` AS status, (SELECT count(*) FROM billing_note_invoices i WHERE i.billing_id = t.id) AS invoice_count, t.file_key IS NOT NULL AS has_pdf
            FROM billing_notes t WHERE `+w, p, "t.id DESC", func(r db.Row) any {
		m := config.NewMap()
		for _, k := range []string{"id", "billing_number", "billing_at", "remind_date", "customer_id", "customer_code", "customer_name", "vat_number", "total_amount", "currency_code", "is_paid", "is_complete", "status", "invoice_count", "has_pdf"} {
			m.Set(k, r[k])
		}
		if r.Bool("has_pdf") {
			m.Set("pdf_url", c.URL("/api/v1/accounting/billing-notes/"+r.Str("id")+"/pdf"))
		} else {
			m.Set("pdf_url", nil)
		}
		return m
	})
}

func apiBillingNote(c *app.Ctx) {
	apiNeed(c, "accounting_billing")
	id := c.ParamInt("id")
	n := billingGet(id)
	if n == nil {
		apiNotFound(c)
	}
	out := pickKeys(n, "id", "billing_number", "is_thai", "billing_at", "remind_date", "customer_id", "customer_code", "customer_name", "vat_number", "address",
		"total_amount", "currency_code", "note", "remark", "is_paid", "is_complete", "created_at")
	inv := []any{}
	for _, r := range n["rows"].([]db.Row) {
		inv = append(inv, pickKeys(r, "invoice_number", "order_no", "invoice_date", "due_date", "amount", "vat_amount", "is_credit"))
	}
	out.Set("invoices", inv)
	if n.Str("file_key") != "" {
		out.Set("pdf_url", c.URL("/api/v1/accounting/billing-notes/"+conv.Str(id)+"/pdf"))
	} else {
		out.Set("pdf_url", nil)
	}
	api.Reply(c, api.Obj("data", out))
}

// apiBillingPDF: the stored PDF of a billing note. Unlike the website, never rebuilds it.
func apiBillingPDF(c *app.Ctx) {
	apiNeed(c, "accounting_billing")
	n := acc().First("SELECT billing_number, file_key FROM billing_notes WHERE id = ?", c.ParamInt("id"))
	if n == nil {
		apiNotFound(c)
	}
	path := safeStored(n.Str("file_key"), "billing/")
	if path == "" {
		api.Fail(404, "pdf_not_available", c.T("The PDF of this billing note has not been made yet. Open it once on the website."))
	}
	apiFile(c, path, filepath.Base(n.Str("billing_number"))+".pdf")
}

// api registers the module's endpoints, paths relative to /api/v1 (api.php).
func apiRoutes(r app.Router) {
	r.Get("/accounting/stats", apiStats)
	r.Get("/accounting/customers", apiCustomers)
	r.Get("/accounting/customers/{id}", apiCustomer)
	r.Get("/accounting/products", apiProducts)
	r.Get("/accounting/products/{id}", apiProduct)
	r.Get("/accounting/orders", apiOrders)
	r.Get("/accounting/orders/{id}", apiOrder)
	r.Get("/accounting/invoices", apiInvoices)
	r.Get("/accounting/invoices/{number}", apiInvoice)
	r.Get("/accounting/sellers", apiSellers)
	r.Get("/accounting/inets", apiInets)
	r.Get("/accounting/inets/{id}", apiInet)
	r.Get("/accounting/inets/{id}/pdf/{doc}", apiInetPDF)
	r.Get("/accounting/billing-notes", apiBillingNotes)
	r.Get("/accounting/billing-notes/{id}", apiBillingNote)
	r.Get("/accounting/billing-notes/{id}/pdf", apiBillingPDF)
}
