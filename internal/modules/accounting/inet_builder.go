package accounting

import (
	"strings"

	"acme/internal/db"
	"acme/internal/php"
)

// InetBuilder: builds (or refreshes) the e-tax row of an invoice from the copied ERP data.

// inetCompute: the values of the inets row in column order, nil when the invoice is not in the copy.
func inetCompute(invoiceNumber int) db.Cols {
	lines := acc().Select("SELECT * FROM erp_invoices WHERE invoice_number = ?", invoiceNumber)
	if len(lines) == 0 {
		return nil
	}
	f := lines[0]
	var order, cust, seller db.Row
	if php.Truthy(f["customer_order_id"]) {
		order = acc().First("SELECT * FROM erp_orders WHERE id = ?", f["customer_order_id"])
	}
	if php.Truthy(f["customer_id"]) {
		cust = acc().First("SELECT * FROM erp_customers WHERE id = ?", f["customer_id"])
	}
	if order != nil && php.Truthy(order["seller_id"]) {
		seller = acc().First("SELECT * FROM erp_sellers WHERE id = ?", order["seller_id"])
	}
	var dept any
	if seller != nil && php.Truthy(seller["department_id"]) {
		dept = acc().Scalar("SELECT coalesce(nullif(name, ''), description) FROM erp_departments WHERE id = ?", seller["department_id"])
	}
	amount := 0.0
	for _, l := range lines {
		amount += l.Float("invoiced_quantity") * l.Float("price") * (1 - l.Float("discount")/100)
	}
	var rate any
	if order != nil {
		rate = acc().Scalar("SELECT v.percentage FROM erp_order_rows r JOIN erp_vats v ON v.id = r.vat_rate_id WHERE r.parent_order_id = ? AND r.vat_rate_id IS NOT NULL ORDER BY r.row_index LIMIT 1", order["id"])
	}
	rateF := 0.0
	if rate != nil {
		rateF = php.Float(rate)
	}
	var email, note any
	if cust != nil {
		email = acc().Scalar("SELECT communication_address_value FROM erp_delivery_contacts WHERE customer_id = ? AND communication_address_value LIKE '%@%' ORDER BY id LIMIT 1", cust["id"])
	}
	if order != nil {
		note = acc().Scalar("SELECT delivery_note_number FROM erp_order_invoices WHERE business_contact_order_id = ? ORDER BY id LIMIT 1", order["id"])
	}
	cur := php.OrStr(f.Str("currency_code"), "THB")
	isCredit := isCreditNo(php.Str(invoiceNumber), order != nil && order.Bool("is_credit"))
	g := func(r db.Row, k string) any {
		if r == nil {
			return nil
		}
		return r[k]
	}
	var sellerName any = php.Or(f["seller"], nil)
	if seller != nil {
		sellerName = strings.TrimSpace(seller.Str("first_name") + " " + seller.Str("last_name"))
	}
	var appDept any
	if seller != nil && seller["app_department_id"] != nil {
		appDept = seller.Int("app_department_id")
	}
	var taxID any
	if cust != nil {
		taxID = php.Or(cust["vat_number"], cust["corporation_identification_number"])
	} else {
		taxID = g(order, "vat_number")
	}
	return db.Cols{
		{Name: "no_invoice", Value: invoiceNumber}, {Name: "invoice_date", Value: f["invoice_date"]}, {Name: "is_credit", Value: bstr(isCredit)}, {Name: "is_international", Value: bstr(strings.ToUpper(cur) != "THB")},
		{Name: "order_id", Value: g(order, "id")}, {Name: "customer_order_number", Value: php.Or(f["customer_order_number"], g(order, "order_number"))}, {Name: "delivery_note_number", Value: note},
		{Name: "seller_name", Value: sellerName}, {Name: "department_name", Value: dept},
		{Name: "app_department_id", Value: appDept}, // the seller's department in this app
		{Name: "customer_id", Value: f["customer_id"]}, {Name: "customer_code", Value: php.Or(f["customer_code"], g(cust, "code"))}, {Name: "customer_name", Value: g(cust, "name")},
		{Name: "currency_code", Value: cur}, {Name: "tax_id", Value: taxID}, {Name: "email", Value: email},
		{Name: "amount", Value: phpRound(amount, 6)}, {Name: "tax_amount", Value: phpRound(amount*rateF/100, 6)}, {Name: "total_amount", Value: phpRound(amount*(1+rateF/100), 6)},
		{Name: "exchange_rate", Value: f["exchange_rate"]}, {Name: "tax_rate", Value: rateF}, {Name: "product_count", Value: len(lines)},
	}
}

// inetSave inserts, or refreshes the figures of an existing row (the document states stay).
func inetSave(invoiceNumber int, autoPdf bool, by int) bool {
	v := inetCompute(invoiceNumber)
	if v == nil {
		return false
	}
	v = append(v, db.Col{Name: "auto_pdf", Value: bstr(autoPdf)})
	var cols, sets, marks []string
	var par []any
	for _, c := range v {
		cols = append(cols, c.Name)
		marks = append(marks, "?")
		par = append(par, c.Value)
		if c.Name != "no_invoice" && c.Name != "is_credit" {
			sets = append(sets, c.Name+" = EXCLUDED."+c.Name)
		}
	}
	set := strings.Join(sets, ", ") + ", updated_by = EXCLUDED.updated_by, updated_at = now()"
	acc().Exec("INSERT INTO inets ("+strings.Join(cols, ",")+", created_by, updated_by) VALUES ("+strings.Join(marks, ",")+", ?, ?)\n                  ON CONFLICT (no_invoice, is_credit) DO UPDATE SET "+set, append(par, by, by)...)
	return true
}
