package accounting

import (
	"errors"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

/*
Billing notes (ใบวางบิล, Billing\BillingService): pick a customer, tick its unbilled invoices, give an address and dates →
a numbered note (BI + yy + mm + 001, 002 …) with its PDF. The invoices come from the prepared erp_documents (+ the ERP
receivable when there is one).
*/

var billingSymbols = map[string]string{"THB": "฿", "USD": "$", "SGD": "$", "NZD": "$", "CAD": "$", "AUD": "$", "EUR": "€", "GBP": "£", "RUB": "₽", "TWD": "NT$", "JPY": "¥", "CNY": "¥"}

var billingBanks = map[string][][4]string{
	"th": {{"ธนาคาร กสิกรไทย", "สาขาอ่อนนุช 39", "ออมทรัพย์", "746-2-14207-7"}, {"ธนาคาร ไทยพาณิชย์", "สาขาบางจาก", "ออมทรัพย์", "089-2-17954-4"}, {"ธนาคาร กรุงเทพ", "สาขาบางจาก", "ออมทรัพย์", "179-0-85992-8"}},
	"en": {{"Kasikorn Bank", "On Nut 39 Branch", "Savings", "746-2-14207-7"}, {"Siam Commercial Bank", "Bang Chak Branch", "Savings", "089-2-17954-4"}, {"Bangkok Bank", "Bang Chak Branch", "Savings", "179-0-85992-8"}},
}

func billingSymbol(cur string) string {
	if s, ok := billingSymbols[strings.ToUpper(cur)]; ok {
		return s
	}
	return cur
}

func billingBanksFor(thai bool) [][4]string {
	if thai {
		return billingBanks["th"]
	}
	return billingBanks["en"]
}

// billingCustomers: customers to pick from, the ones with unbilled invoices first.
func billingCustomers(q string) []app.M {
	var par []any
	where := ""
	if q != "" {
		like := "%" + likeEscape(strings.ToLower(q)) + "%"
		where = "WHERE lower(c.name) LIKE ? OR lower(c.code) LIKE ? OR lower(coalesce(c.vat_number, '')) LIKE ?"
		par = []any{like, like, like}
	}
	rows := acc().Select(`SELECT c.id, c.name, c.code, coalesce(x.n, 0)::int AS invoices FROM erp_customers c
                           LEFT JOIN (SELECT d.customer_id, count(*) AS n FROM erp_documents d WHERE NOT EXISTS (SELECT 1 FROM billing_note_invoices b WHERE b.invoice_number = d.invoice_number) GROUP BY d.customer_id) x ON x.customer_id = c.id
                           `+where+` ORDER BY coalesce(x.n, 0) DESC, c.name LIMIT 40`, par...)
	out := make([]app.M, len(rows))
	for i, r := range rows {
		m := app.M(r)
		m["id"] = r.Str("id") // ERP ids can be larger than a browser can count exactly, so they travel as strings
		out[i] = m
	}
	return out
}

var custIDRe = regexp.MustCompile(`^\d{1,19}$`)

func billingCustomer(id string) db.Row {
	if !custIDRe.MatchString(id) {
		return nil
	}
	c := acc().First("SELECT c.*, a.addressee, a.field1, a.field2, a.field3, a.field4, a.field5, a.locality, a.region, a.postal_code FROM erp_customers c LEFT JOIN erp_addresses a ON a.id = c.mailing_address_id WHERE c.id = ?::bigint", id)
	if c == nil {
		return nil
	}
	var parts []string
	for _, k := range []string{"addressee", "field1", "field2", "field3", "field4", "field5", "locality", "region", "postal_code"} {
		if v := strings.TrimSpace(c.Str(k)); v != "" {
			parts = append(parts, v)
		}
	}
	c["address"] = strings.Join(parts, ", ")
	return c
}

type billInv struct {
	InvoiceNumber          int     `json:"invoice_number"`
	OrderNo                string  `json:"order_no"`
	InvoiceDate            any     `json:"invoice_date"`
	DueDate                any     `json:"due_date"`
	Amount                 float64 `json:"amount"`
	VatAmount              float64 `json:"vat_amount"`
	IsCredit               bool    `json:"is_credit"`
	Currency               string  `json:"currency"`
	HasReceivable          bool    `json:"has_receivable"`
	OnNote                 bool    `json:"-"`
}

// billingInvoices: invoices of a customer that are not on any billing note yet.
func billingInvoices(customerID string) []billInv {
	rows := acc().Select(`SELECT d.invoice_number, d.order_number, d.invoice_date, d.currency_code, d.amount AS net, d.is_credit AS doc_credit,
                                   r.invoice_amount, r.vat_amount, r.due_date, r.is_credit AS r_credit, pt.grace_period_in_days AS grace
                              FROM erp_documents d
                              LEFT JOIN erp_receivables r ON r.invoice_number = d.invoice_number
                              LEFT JOIN erp_orders o ON o.id = d.order_id LEFT JOIN erp_payment_terms pt ON pt.id = o.payment_term_id
                             WHERE d.customer_id = ?::bigint AND NOT EXISTS (SELECT 1 FROM billing_note_invoices b WHERE b.invoice_number = d.invoice_number)
                             ORDER BY d.invoice_number DESC LIMIT 300`, customerID)
	out := []billInv{}
	for _, r := range rows {
		credit := r.Bool("doc_credit")
		if r["r_credit"] != nil {
			credit = r.Bool("r_credit")
		}
		vat := phpRound(r.Float("net")*0.07, 2)
		if r["vat_amount"] != nil {
			vat = r.Float("vat_amount")
		}
		amount := phpRound(r.Float("net")+vat, 2)
		if r["invoice_amount"] != nil {
			amount = r.Float("invoice_amount")
		}
		var date, due any
		if php.Truthy(r["invoice_date"]) {
			date = php.Substr(r.Str("invoice_date"), 0, 10)
		}
		if php.Truthy(r["due_date"]) {
			due = php.Substr(r.Str("due_date"), 0, 10)
		} else if date != nil {
			if t, ok := php.ParseTime(php.Str(date)); ok {
				due = php.Date("Y-m-d", t.AddDate(0, 0, r.Int("grace")))
			}
		}
		if amount < 0 {
			amount = -amount
		}
		if vat < 0 {
			vat = -vat
		}
		out = append(out, billInv{InvoiceNumber: r.Int("invoice_number"), OrderNo: r.Str("order_number"), InvoiceDate: date, DueDate: due, Amount: amount, VatAmount: vat,
			IsCredit: credit, Currency: php.OrStr(r.Str("currency_code"), "THB"), HasReceivable: r["invoice_amount"] != nil})
	}
	return out
}

// billingNextNumber: BI + yy + mm + 001, 002 … per month.
func billingNextNumber(date string) string {
	prefix := "BI" + php.DateOf("ym", date)
	m := php.Int(acc().Scalar("SELECT coalesce(max(substring(billing_number FROM ?)::int), 0) FROM billing_notes WHERE billing_number ~ ?", len(prefix)+1, "^"+prefix+"[0-9]+$"))
	n := php.Str(m + 1)
	for len(n) < 3 {
		n = "0" + n
	}
	return prefix + n
}

type billingInput struct {
	CustomerID  string
	Invoices    []string
	Address     string
	BillingDate string
	RemindDate  string
	IsThai      bool
	Override    string
	Note        string
	Remark      string
	Number      string
}

func wantInvoices(in []string) []int {
	seen := map[int]bool{}
	var out []int
	for _, s := range in {
		n := php.Int(s)
		if !seen[n] {
			seen[n] = true
			out = append(out, n)
		}
	}
	return out
}

func insertBillRows(tx db.Q, id int, rows []billInv) {
	for _, r := range rows {
		tx.Insert("billing_note_invoices", db.Cols{{Name: "billing_id", Value: id}, {Name: "invoice_number", Value: r.InvoiceNumber}, {Name: "order_no", Value: r.OrderNo}, {Name: "amount", Value: r.Amount},
			{Name: "vat_amount", Value: r.VatAmount}, {Name: "is_credit", Value: bstr(r.IsCredit)}, {Name: "invoice_date", Value: r.InvoiceDate}, {Name: "due_date", Value: r.DueDate}})
	}
}

// billingCreate makes a note and its PDF; returns the new id.
func billingCreate(c *app.Ctx, in billingInput, by int, isAdmin bool) int {
	cu := billingCustomer(in.CustomerID)
	if cu == nil {
		panic(errors.New(c.T("Choose a customer.")))
	}
	want := wantInvoices(in.Invoices)
	if len(want) == 0 {
		panic(errors.New(c.T("Choose at least one invoice.")))
	}
	avail := map[int]billInv{}
	for _, r := range billingInvoices(cu.Str("id")) {
		avail[r.InvoiceNumber] = r
	}
	var rows []billInv
	for _, n := range want {
		r, ok := avail[n]
		if !ok {
			panic(errors.New(c.T("Invoice :no is not available (already billed, or not this customer's).", "no", n)))
		}
		rows = append(rows, r)
	}
	address := strings.TrimSpace(in.Address)
	if address == "" {
		address = cu.Str("address")
	}
	date := in.BillingDate
	if !ymdRe.MatchString(date) {
		date = php.Date("Y-m-d", php.Now())
	}
	remind := in.RemindDate
	if !ymdRe.MatchString(remind) {
		remind = date
	}
	cur := rows[0].Currency
	total := 0.0
	for _, r := range rows {
		if r.IsCredit {
			total -= r.Amount
		} else {
			total += r.Amount
		}
	}
	var id int
	var number string
	acc().Transaction(func(tx db.Q) {
		tx.Exec("SELECT pg_advisory_xact_lock(7001)") // two people generating at once get different numbers
		if isAdmin && strings.TrimSpace(in.Override) != "" {
			number = strings.TrimSpace(in.Override)
		} else {
			number = billingNextNumber(date)
		}
		if tx.Scalar("SELECT 1 FROM billing_notes WHERE billing_number = ?", number) != nil {
			panic(errors.New(c.T("The number :n is already used.", "n", number)))
		}
		id = php.Int(tx.Insert("billing_notes", db.Cols{{Name: "billing_number", Value: number}, {Name: "is_thai", Value: bstr(in.IsThai)}, {Name: "customer_id", Value: cu["id"]},
			{Name: "customer_code", Value: cu["code"]}, {Name: "customer_name", Value: cu["name"]}, {Name: "vat_number", Value: php.Or(cu["vat_number"], php.Coalesce(cu["corporation_identification_number"], nil))},
			{Name: "address", Value: address}, {Name: "total_amount", Value: phpRound(total, 2)}, {Name: "currency_code", Value: cur}, {Name: "billing_at", Value: date}, {Name: "remind_date", Value: remind},
			{Name: "created_by", Value: by}, {Name: "updated_by", Value: by}}))
		insertBillRows(tx, id, rows)
	})
	logInfo("billing", number+": created", om("customer", cu["code"], "invoices", len(rows), "total", phpRound(total, 2), "by", by))
	if err := app.Catch(func() { billingPDF(c, id, true) }); err != nil {
		logException("billing", err, number+" PDF not made")
	}
	return id
}

// billingPDF: the PDF of a note, made once and kept (storage/billing/<number>.pdf); force builds it again. Returns the absolute path.
func billingPDF(c *app.Ctx, id int, force bool) string {
	n := acc().First("SELECT * FROM billing_notes WHERE id = ?", id)
	if n == nil {
		panic(errors.New(c.T("Billing note not found.")))
	}
	key := "billing/" + regexp.MustCompile(`[^A-Za-z0-9._-]`).ReplaceAllString(n.Str("billing_number"), "_") + ".pdf"
	abs := filepath.Join(app.BasePath, "storage", key)
	if !force && n.Str("file_key") != "" {
		if st, err := os.Stat(filepath.Join(app.BasePath, "storage", n.Str("file_key"))); err == nil && st.Mode().IsRegular() {
			return filepath.Join(app.BasePath, "storage", n.Str("file_key"))
		}
	}
	rows := acc().Select("SELECT * FROM billing_note_invoices WHERE billing_id = ? ORDER BY invoice_number", id)
	company := companyRequire(c)
	html := billingHTML(n, rows, company, billingBanksFor(n.Bool("is_thai")))
	pdf := renderPDF(c, html)
	_ = os.MkdirAll(filepath.Dir(abs), 0o775)
	if err := os.WriteFile(abs, pdf, 0o664); err != nil {
		panic(err)
	}
	acc().Exec("UPDATE billing_notes SET file_key = ?, updated_at = now() WHERE id = ?", key, id)
	return abs
}

// billingGet: a note with its invoices (n["rows"]), for the detail and edit pages.
func billingGet(id int) db.Row {
	n := acc().First("SELECT * FROM billing_notes WHERE id = ?", id)
	if n == nil {
		return nil
	}
	n["rows"] = acc().Select("SELECT * FROM billing_note_invoices WHERE billing_id = ? ORDER BY invoice_number", id)
	return n
}

// billingChoices: what can be on this note: its own invoices plus the customer's invoices that are not billed yet (newest first).
func billingChoices(note db.Row) []billInv {
	byNo := map[int]billInv{}
	for _, r := range note["rows"].([]db.Row) {
		byNo[r.Int("invoice_number")] = billInv{InvoiceNumber: r.Int("invoice_number"), OrderNo: r.Str("order_no"), InvoiceDate: r["invoice_date"], DueDate: r["due_date"], Amount: r.Float("amount"),
			VatAmount: r.Float("vat_amount"), IsCredit: r.Bool("is_credit"), Currency: note.Str("currency_code"), OnNote: true}
	}
	if php.Truthy(note["customer_id"]) {
		for _, r := range billingInvoices(note.Str("customer_id")) {
			if _, ok := byNo[r.InvoiceNumber]; !ok {
				byNo[r.InvoiceNumber] = r
			}
		}
	}
	out := make([]billInv, 0, len(byNo))
	for _, r := range byNo {
		out = append(out, r)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].InvoiceNumber > out[j].InvoiceNumber })
	return out
}

// billingUpdate changes a note (address, dates, language, remark, its invoices, and its number for administrators) and builds the PDF again.
func billingUpdate(c *app.Ctx, id int, in billingInput, by int, isAdmin bool) {
	n := billingGet(id)
	if n == nil {
		panic(errors.New(c.T("Billing note not found.")))
	}
	if n.Bool("is_complete") && !isAdmin {
		panic(errors.New(c.T("A complete billing note can only be changed by an administrator.")))
	}
	choices := map[int]billInv{}
	for _, r := range billingChoices(n) {
		choices[r.InvoiceNumber] = r
	}
	want := wantInvoices(in.Invoices)
	if len(want) == 0 {
		panic(errors.New(c.T("Choose at least one invoice.")))
	}
	var rows []billInv
	for _, no := range want {
		r, ok := choices[no]
		if !ok {
			panic(errors.New(c.T("Invoice :no is not available (already billed, or not this customer's).", "no", no)))
		}
		rows = append(rows, r)
	}
	date := in.BillingDate
	if !ymdRe.MatchString(date) {
		date = n.Str("billing_at")
	}
	remind := in.RemindDate
	if !ymdRe.MatchString(remind) {
		remind = date
	}
	address := strings.TrimSpace(in.Address)
	if address == "" {
		address = n.Str("address")
	}
	total := 0.0
	for _, r := range rows {
		if r.IsCredit {
			total -= r.Amount
		} else {
			total += r.Amount
		}
	}
	number := n.Str("billing_number")
	if isAdmin && strings.TrimSpace(in.Number) != "" && strings.TrimSpace(in.Number) != number {
		number = strings.TrimSpace(in.Number)
		if acc().Scalar("SELECT 1 FROM billing_notes WHERE billing_number = ? AND id <> ?", number, id) != nil {
			panic(errors.New(c.T("The number :n is already used.", "n", number)))
		}
	}
	nullIf := func(s string) any {
		if strings.TrimSpace(s) == "" {
			return nil
		}
		return strings.TrimSpace(s)
	}
	cur := php.OrStr(rows[0].Currency, n.Str("currency_code"))
	acc().Transaction(func(tx db.Q) {
		tx.Exec("DELETE FROM billing_note_invoices WHERE billing_id = ?", id)
		insertBillRows(tx, id, rows)
		tx.Update("billing_notes", db.Cols{{Name: "billing_number", Value: number}, {Name: "is_thai", Value: bstr(in.IsThai)}, {Name: "address", Value: address}, {Name: "billing_at", Value: date},
			{Name: "remind_date", Value: remind}, {Name: "total_amount", Value: phpRound(total, 2)}, {Name: "currency_code", Value: cur}, {Name: "note", Value: nullIf(in.Note)},
			{Name: "remark", Value: nullIf(in.Remark)}, {Name: "updated_by", Value: by}, {Name: "updated_at", Value: php.NowString()}}, db.Cols{{Name: "id", Value: id}})
	})
	if number != n.Str("billing_number") && n.Str("file_key") != "" {
		_ = os.Remove(filepath.Join(app.BasePath, "storage", n.Str("file_key")))
		acc().Exec("UPDATE billing_notes SET file_key = NULL WHERE id = ?", id)
	}
	logInfo("billing", number+": edited", om("invoices", len(rows), "total", phpRound(total, 2), "by", by))
	if err := app.Catch(func() { billingPDF(c, id, true) }); err != nil {
		logException("billing", err, number+" PDF not made")
	}
}

// ---------------------------------------------------------------- the billing note page (accounting/pdf/billing.php)

func billingHTML(n db.Row, rows []db.Row, company db.Row, banks [][4]string) string {
	th := n.Bool("is_thai")
	total := 0.0
	for _, r := range rows {
		if r.Bool("is_credit") {
			total -= r.Float("amount")
		} else {
			total += r.Float("amount")
		}
	}
	dfmt := func(v any) string {
		if php.Empty(v) {
			return ""
		}
		t, ok := php.ParseTime(php.Str(v))
		if !ok {
			t = php.Unix(0)
		}
		if th {
			return php.Date("d/m/", t) + php.Str(t.Year()+543)
		}
		return php.Date("d/m/Y", t)
	}
	p := func(a, b string) string {
		if th {
			return a
		}
		return b
	}
	fmtN := func(v float64) string { return nf(v, 2, ".", ",") }
	cs := func(k string) string { return company.Str(k) }
	logo := pdfData("logo.jpg", "image/jpeg")
	payNote := cs("payment_note_en")
	if th {
		payNote = cs("payment_note_th")
	}
	var w strings.Builder
	W := func(s ...string) {
		for _, x := range s {
			w.WriteString(x)
		}
	}
	W(`<!DOCTYPE html>
<html lang="`, p("th", "en"), `"><head><meta charset="UTF-8"><title>`, pe(n["billing_number"]), `</title>
<style>`, pdfFonts(), `</style>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
html{width:210mm}
body{width:210mm;background:#fff;color:var(--text);font-family:'Sarabun',sans-serif;font-size:11px}
.flex{display:flex}.gap-3{gap:10px}.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:8px}.w-full{width:100%}
.text-center{text-align:center}.text-right{text-align:right}.text-left{text-align:left}
.font-medium{font-weight:500}.font-bold{font-weight:700}.font-semibold{font-weight:600}
.uppercase{text-transform:uppercase}.tracking-widest{letter-spacing:.12em}.leading-tight{line-height:1.2}.leading-relaxed{line-height:1.5}
.whitespace-pre{white-space:pre-line}.shrink-0{flex-shrink:0}.nowrap{white-space:nowrap}
.px-2{padding-left:6px;padding-right:6px}.px-3{padding-left:10px;padding-right:10px}.px-5{padding-left:18px;padding-right:18px}
.py-2{padding-top:5px;padding-bottom:5px}.py-3{padding-top:8px;padding-bottom:8px}.mt-1{margin-top:2px}.mt-2{margin-top:5px}
.text-sm{font-size:12px}.text-10px{font-size:10px}.text-11px{font-size:11px}
.c-text{color:var(--text)}.c-head{color:var(--header-text)}.bg-band{background:var(--bg)}.bg-white{background:#fff}
.bd{border:1px solid var(--border)}.bd-r{border-right:1px solid var(--border)}.bd-b{border-bottom:1px solid var(--border)}.bd-dash{border:2px dashed var(--border)}
.h-16{height:110px}.w-16{width:110px}.border-collapse{border-collapse:collapse}
@page{size:A4;margin:0}
.avoid-break{page-break-inside:avoid;break-inside:avoid}
tr{page-break-inside:avoid}
</style></head>
<body style="--header-text:#333333;--text:#1a1a1a;--border:#CCCCCC;--bg:#F2F2F2;">
<div style="display:flex;flex-direction:column;min-height:297mm;">

<!-- header -->
<div class="flex" style="min-height:108px;background:#fff;align-items:flex-start;margin-top:5mm;">
  <div class="flex gap-3 px-5 py-2" style="align-items:flex-start;width:calc(100% - 250px);">
    `)
	if logo != "" {
		W(`<img src="`, logo, `" alt="" class="h-16 w-16 shrink-0" style="object-fit:contain;">`)
	}
	W(`    <div style="display:flex;flex-direction:column;gap:1px;">
      <div style="width:max-content;max-width:100%;">
        <p class="font-semibold text-sm leading-tight c-text" style="text-align:justify;text-align-last:justify;">`, pe(cs("name_th")), `</p>
        `)
	if strings.TrimSpace(cs("name_en")) != "" {
		W(`<p class="font-semibold text-sm leading-tight c-text" style="text-align:justify;text-align-last:justify;">`, pe(cs("name_en")), `</p>`)
	}
	W(`      </div>
      <p class="text-11px leading-tight mt-1 c-text">`, pe(cs("address_th")), `</p>
      `)
	if strings.TrimSpace(cs("address_en")) != "" {
		W(`<p class="text-11px leading-tight c-text">`, pe(cs("address_en")), `</p>`)
	}
	W(`      <div style="display:grid;grid-template-columns:1fr 1fr;column-gap:12px;margin-top:1px;">
        <p class="text-11px c-text">`, p("โทร/TEL", "Tel"), `: `, pe(cs("phone")), `</p>
        <p class="text-11px c-text" style="text-align:right;">`, p("โทรสาร/FAX", "Fax"), `: `, pe(cs("fax")), `</p>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;column-gap:12px;">
        <p class="text-11px c-text">`, p("WEB SITE", "Web"), `: `, pe(cs("website")), `</p>
        <p class="text-11px c-text" style="text-align:right;">`, p("E-mail", "Email"), `: `, pe(cs("email")), `</p>
      </div>
      <p class="text-11px font-medium c-text" style="text-align:right;">เลขประจำตัวผู้เสียภาษีอากร/TAX NO: `, pe(php.Or(company["tax_no"], company["tax_id"])), ` (`, p("สำนักงานใหญ่", "Head Office"), `)</p>
    </div>
  </div>
  <div style="width:250px;flex-shrink:0;padding-right:18px;display:flex;flex-direction:column;gap:8px;">
    <div style="text-align:center;">
      <p class="font-semibold leading-tight c-text" style="font-size:13px;">`, p("ใบวางบิล", "BILLING NOTE"), `</p>
      <p class="font-semibold leading-tight c-text" style="font-size:11px;">BILLING NOTE</p>
    </div>
    <div class="bd" style="border-radius:4px;overflow:hidden;">
      <div class="bd-b" style="display:grid;grid-template-columns:1fr 1fr;">
        <div class="bd-r" style="padding:5px 8px;"><p class="text-10px font-medium leading-tight c-text">เลขที่ใบวางบิล</p><p class="text-10px leading-tight c-text">BIL.NO.</p></div>
        <div style="padding:5px 8px;display:flex;align-items:center;"><p class="c-text" style="font-size:15px;">`, pe(n["billing_number"]), `</p></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;">
        <div class="bd-r" style="padding:5px 8px;"><p class="text-10px font-medium leading-tight c-text">วันที่</p><p class="text-10px leading-tight c-text">DATE</p></div>
        <div style="padding:5px 8px;display:flex;align-items:center;"><p class="c-text" style="font-size:15px;">`, pe(dfmt(n["billing_at"])), `</p></div>
      </div>
    </div>
  </div>
</div>
<div class="bg-band" style="height:3px;"></div>
<div class="px-5 py-3 mt-2" style="display:flex;flex-direction:column;gap:7px;flex:1;padding-bottom:70mm;">
  <div class="bd" style="display:flex;border-radius:6px;overflow:hidden;font-size:10px;">
    <div class="bg-white bd-r"><p class="text-11px font-medium c-head bg-band" style="padding:4px 10px;">`, p("รหัสลูกค้า", "Customer Code"), `</p><p class="text-11px c-text" style="padding:5px 10px;">`, pe(n["customer_code"]), `</p></div>
    <div class="bg-white bd-r" style="flex:1;"><p class="text-11px font-medium c-head bg-band" style="padding:4px 10px;">`, p("ชื่อลูกค้า", "Customer Name"), `</p><p class="text-11px c-text" style="padding:5px 10px;">`, pe(n["customer_name"]), `</p></div>
    <div class="bg-white"><p class="text-11px font-medium c-head bg-band" style="padding:4px 10px;">`, p("เลขประจำตัวผู้เสียภาษี", "Tax No"), `</p><p class="text-11px c-text" style="padding:5px 10px;">`, pe(n["vat_number"]), `</p></div>
  </div>
  <div class="bd" style="border-radius:6px;overflow:hidden;"><div class="bg-band" style="padding:4px 10px;"><p class="c-head font-semibold text-10px uppercase tracking-widest">`, p("ที่อยู่", "BILL TO"), `</p></div>
    <div class="px-3 py-2 bg-white" style="min-height:48px;"><p class="text-11px whitespace-pre leading-relaxed c-text">`, pe(strings.ReplaceAll(strings.ReplaceAll(n.Str("address"), `\n`, "\n"), ", ", ",\n")), `</p></div></div>
  <div class="bd" style="border-radius:6px;overflow:hidden;">
    <table class="w-full border-collapse" style="border-spacing:0;font-size:10px;">
      <thead><tr class="c-head bg-band">
        <th class="px-2 py-2 text-left font-semibold text-11px" style="width:100px;">`, p("เลขที่ใบแจ้งหนี้", "Invoice number"), `</th>
        <th class="px-2 py-2 text-left font-semibold text-11px" style="width:100px;">`, p("เลขที่คำสั่งซื้อ", "Order number"), `</th>
        <th class="px-2 py-2 text-left font-semibold text-11px" style="width:100px;">`, p("วันที่ใบแจ้งหนี้", "Invoice date"), `</th>
        <th class="px-2 py-2 text-left font-semibold text-11px" style="width:100px;">`, p("วันครบกำหนด", "Due date"), `</th>
        <th class="px-2 py-2 text-right font-semibold text-11px" style="width:100px;">`, p("จำนวนเงิน", "Amount"), `</th></tr></thead>
      <tbody>
      `)
	for _, r := range rows {
		minus := ""
		if r.Bool("is_credit") {
			minus = "-"
		}
		W(`        <tr class="bg-white">
          <td class="px-2 py-2 text-11px text-left c-text" style="white-space:nowrap;vertical-align:top;">`, pe(r["invoice_number"]), `</td>
          <td class="px-2 py-2 text-11px text-left c-text" style="white-space:nowrap;vertical-align:top;">`, pe(r["order_no"]), `</td>
          <td class="px-2 py-2 text-11px text-left c-text" style="white-space:nowrap;vertical-align:top;">`, pe(dfmt(r["invoice_date"])), `</td>
          <td class="px-2 py-2 text-11px text-left c-text" style="white-space:nowrap;vertical-align:top;">`, pe(dfmt(r["due_date"])), `</td>
          <td class="px-2 py-2 text-11px text-right c-text" style="white-space:nowrap;vertical-align:top;">`, minus, fmtN(r.Float("amount")), `</td>
        </tr>
      `)
	}
	W(`      </tbody>
    </table>
  </div>
  <div class="flex" style="gap:10px;margin-top:4px;align-items:stretch;">
    <div class="flex" style="gap:10px;flex:0 0 auto;">
      `)
	for _, b := range banks {
		W(`      <div class="bd bg-white px-3 py-2" style="border-radius:6px;width:150px;"><div class="font-semibold c-text text-11px">`, pe(b[0]), `</div><div class="c-text text-10px mt-1">`, pe(b[1]+" · "+b[2]), `</div><div class="c-text text-11px mt-1">`, pe(b[3]), `</div></div>
      `)
	}
	W(`    </div>
    <div style="flex:1 1 auto;"></div>
    <div class="bd bg-white px-5 py-3 text-right shrink-0" style="border-radius:6px;min-width:170px;display:flex;flex-direction:column;justify-content:center;">
      <div class="c-text text-10px">`, p("รวมเงินทั้งสิ้น", "Total"), `</div>
      <div class="font-semibold c-text" style="font-size:18px;margin-top:4px;">`, pe(billingSymbol(n.Str("currency_code"))), fmtN(total), `</div>
    </div>
  </div>
  <div class="avoid-break" style="position:fixed;left:0;right:0;bottom:10mm;background:#fff;padding:0 18px 4px;">
    `)
	if strings.TrimSpace(payNote) != "" {
		W(`<div style="width:100%;background:#fff;border:1px solid #f0a8a8;border-radius:6px;padding:7px 12px;margin-bottom:10px;text-align:center;"><p class="text-11px font-semibold leading-relaxed" style="color:#dc2626;margin:0;">`, pe(payNote), `</p></div>`)
	}
	W(`    <div style="display:flex;gap:16px;align-items:stretch;">
      <div class="bd" style="flex:1;border-radius:6px;padding:8px;display:flex;flex-direction:column;"><div style="flex:1;min-height:56px;"></div><div style="border-bottom:1px dotted var(--border);margin-bottom:4px;"></div><p class="text-11px text-center c-text">`, p("ผู้วางบิล", "Biller"), `</p>
        <div style="display:flex;align-items:flex-end;gap:6px;margin-top:12px;"><span class="text-11px c-text">`, p("วันที่", "Date"), `</span><span style="flex:1;border-bottom:1px dotted var(--border);height:1px;"></span></div></div>
      <div class="bd" style="flex:2;border-radius:6px;overflow:hidden;"><table class="border-collapse" style="width:100%;table-layout:fixed;">
        <tr><td colspan="2" class="bd-b" style="text-align:center;padding:6px 8px;"><span class="text-11px c-text">`, p("ข้าพเจ้าได้รับเอกสาร / บริการ ดังรายการข้างต้นครบถ้วนและอยู่ในสภาพเรียบร้อย", "I have received the documents / services listed above in complete and good condition"), `</span></td></tr>
        <tr><td class="bd-r" style="padding:8px;vertical-align:bottom;height:84px;width:50%;"><div style="border-bottom:1px dotted var(--border);margin-bottom:4px;margin-top:32px;"></div><p class="text-11px text-center c-text">`, p("ผู้รับวางบิล", "Bill Receiver"), `</p>
          <div style="display:flex;align-items:flex-end;gap:6px;margin-top:12px;"><span class="text-11px c-text">`, p("วันที่", "Date"), `</span><span style="flex:1;border-bottom:1px dotted var(--border);height:1px;"></span></div></td>
          <td style="padding:8px;vertical-align:bottom;height:84px;width:50%;"><div style="display:flex;align-items:flex-end;gap:6px;margin-top:12px;"><span class="text-11px c-text">`, p("วันที่ชำระเงิน", "Payment Date"), `</span><span style="flex:1;border-bottom:1px dotted var(--border);height:1px;"></span></div></td></tr>
      </table></div>
    </div>
  </div>
</div>
</div>
</body></html>
`)
	return w.String()
}
