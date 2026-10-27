package accounting

import (
	"errors"
	"regexp"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

// Everything one invoice needs, read from the ERP copy (Inet\DocumentData): the header (customer, addresses, seller, VAT,
// order …) and the lines. This replaces the old database views v_documents / v_inet_line.

type invLine struct {
	PartNumber, PartName                                         string
	OrderedQty, InvoicedQty, Price, Discount, Conversion, FxRate float64
	ItemType                                                     string
	OrderRowType                                                 any
	UnitCode                                                     string
	DeliveryDate                                                 any
}

func one(sql string, p ...any) db.Row { return acc().First(sql, p...) }

// docLoad: the document header (PHP array keys) and its lines.
func docLoad(c *app.Ctx, invoiceNumber int) (app.M, []invLine) {
	lines := acc().Select(`SELECT i.*, p.description AS part_name, p.part_number AS product_part_number
                               FROM erp_invoices i LEFT JOIN erp_products p ON p.id = i.part_id
                              WHERE i.invoice_number = ? AND coalesce(i.order_row_type, 1) = 1 ORDER BY i.id`, invoiceNumber)
	if len(lines) == 0 {
		panic(errors.New(c.T("Invoice :no is not in the ERP copy. Press Sync first.", "no", invoiceNumber)))
	}
	f := lines[0]
	var order, cust, seller, dept db.Row
	if php.Truthy(f["customer_order_id"]) {
		order = one("SELECT * FROM erp_orders WHERE id = ?", f["customer_order_id"])
	}
	if php.Truthy(f["customer_id"]) {
		cust = one("SELECT * FROM erp_customers WHERE id = ?", f["customer_id"])
	}
	if order != nil && php.Truthy(order["seller_id"]) {
		seller = one("SELECT * FROM erp_sellers WHERE id = ?", order["seller_id"])
	}
	if seller != nil && php.Truthy(seller["department_id"]) {
		dept = one("SELECT * FROM erp_departments WHERE id = ?", seller["department_id"])
	}
	g := func(r db.Row, k string) any {
		if r == nil {
			return nil
		}
		return r[k]
	}
	mailID := php.Coalesce(g(order, "mailing_address_id"), g(cust, "mailing_address_id"))
	var mail, del db.Row
	if php.Truthy(mailID) {
		mail = one("SELECT * FROM erp_addresses WHERE id = ?", mailID)
	}
	delID := g(order, "delivery_address_id")
	if php.Truthy(delID) {
		del = one("SELECT * FROM erp_addresses WHERE id = ?", delID)
		if del == nil {
			del = one("SELECT * FROM erp_delivery_addresses WHERE id = ?", delID)
		}
	}
	var country any
	if mail != nil && php.Truthy(mail["country_id"]) {
		country = acc().Scalar("SELECT code FROM erp_countries WHERE id = ?", mail["country_id"])
	}
	var vatGroup, vatRow db.Row
	if order != nil && php.Truthy(order["vat_group_id"]) {
		vatGroup = one("SELECT * FROM erp_vat_groups WHERE id = ?", order["vat_group_id"])
	}
	if vatGroup != nil && php.Truthy(vatGroup["default_vat_rate_id"]) {
		vatRow = one("SELECT * FROM erp_vats WHERE id = ?", vatGroup["default_vat_rate_id"])
	}
	if vatRow == nil && order != nil {
		vatRow = one("SELECT v.* FROM erp_order_rows r JOIN erp_vats v ON v.id = r.vat_rate_id WHERE r.parent_order_id = ? ORDER BY r.row_index LIMIT 1", order["id"])
	}
	var term, dm db.Row
	var wh any
	if order != nil && php.Truthy(order["payment_term_id"]) {
		term = one("SELECT * FROM erp_payment_terms WHERE id = ?", order["payment_term_id"])
	}
	if order != nil && php.Truthy(order["warehouse_id"]) {
		wh = acc().Scalar("SELECT name FROM erp_warehouses WHERE id = ?", order["warehouse_id"])
	}
	if order != nil && php.Truthy(order["delivery_method_id"]) {
		dm = one("SELECT * FROM erp_delivery_methods WHERE id = ?", order["delivery_method_id"])
	}
	note := acc().Scalar("SELECT oi.delivery_note_number FROM erp_invoices i JOIN erp_order_deliveries d ON d.id = i.delivery_row_id JOIN erp_order_invoices oi ON oi.id = d.invoice_id WHERE i.invoice_number = ? LIMIT 1", invoiceNumber)
	if note == nil && order != nil {
		note = acc().Scalar("SELECT delivery_note_number FROM erp_order_invoices WHERE business_contact_order_id = ? ORDER BY id LIMIT 1", order["id"])
	}
	var ext any
	if order != nil && php.Truthy(order["external_comment_id"]) {
		ext = acc().Scalar("SELECT raw_text FROM erp_comments WHERE id = ?", order["external_comment_id"])
	}
	var contacts []db.Row
	if cust != nil {
		contacts = acc().Select("SELECT * FROM erp_delivery_contacts WHERE customer_id = ? ORDER BY id", cust["id"])
	}
	email, phone, fax := "", "", ""
	phoneRe := regexp.MustCompile(`\d{5,}`)
	for _, ct := range contacts {
		v := strings.TrimSpace(ct.Str("communication_address_value"))
		tn := strings.ToLower(ct.Str("type_name"))
		if v == "" {
			continue
		}
		if email == "" && strings.Contains(v, "@") {
			email = v
		} else if strings.Contains(tn, "fax") {
			fax = php.OrStr(fax, v)
		} else if phone == "" && !strings.Contains(v, "@") && phoneRe.MatchString(v) {
			phone = v
		}
	}
	countryS := php.Str(country)
	isThai := strings.ToUpper(php.OrStr(countryS, "TH")) == "TH"
	vat := php.OrStr(strings.TrimSpace(php.Str(g(cust, "vat_number"))), strings.TrimSpace(php.Str(g(order, "vat_number"))))
	cur := php.OrStr(strings.ToUpper(strings.TrimSpace(f.Str("currency_code"))), "THB")
	if vat == "" {
		vat = "0000000000000"
	}
	orderNumber := php.Coalesce(g(order, "order_number"), php.Coalesce(f["customer_order_number"], ""))
	sellerName := php.Str(php.Coalesce(f["seller"], ""))
	if seller != nil {
		sellerName = strings.TrimSpace(seller.Str("first_name") + " " + seller.Str("last_name"))
	}
	deptName, deptCode := "", ""
	if dept != nil {
		deptName = php.Str(php.Or(dept["name"], dept["description"]))
		deptCode = php.Str(php.Or(dept["code"], php.Or(dept["name"], dept["description"])))
	}
	var vatPct, vatName any
	if vatRow != nil {
		vatPct = vatRow.Float("percentage")
	}
	vatName = php.Coalesce(g(vatGroup, "description"), g(vatRow, "description"))
	whCode := ""
	if order != nil && php.Truthy(order["warehouse_id"]) {
		whCode = php.Str(acc().Scalar("SELECT coalesce(nullif(code, ''), name) FROM erp_warehouses WHERE id = ?", order["warehouse_id"]))
	}
	var grace, due, deliveryBy, noteS any
	if term != nil {
		grace = term["grace_period_in_days"]
	}
	if order != nil && php.Truthy(order["order_date"]) && term != nil && term["grace_period_in_days"] != nil {
		if t, ok := php.ParseTime(order.Str("order_date")); ok {
			due = php.Date("d-m-Y", t.AddDate(0, 0, term.Int("grace_period_in_days")))
		}
	}
	if dm != nil {
		deliveryBy = php.Or(dm["description"], dm["code"])
	}
	if note != nil {
		noteS = php.Str(note)
	}
	isPrivate := false
	if cust != nil {
		isPrivate = cust.Bool("is_private_customer")
	}
	var custID any = php.Coalesce(g(cust, "id"), f["customer_id"])
	doc := app.M{
		"invoice_no": invoiceNumber, "invoice_date": f["invoice_date"], "currency_code": cur, "exchange_rate": f["exchange_rate"],
		"is_credit": isCreditNo(php.Str(invoiceNumber), order != nil && order.Bool("is_credit")), "is_foreign": !isThai,
		"order_id": g(order, "id"), "order_number": orderNumber, "order_date": g(order, "order_date"),
		"po_number": g(order, "business_contact_order_number"), "external_comment_id": g(order, "external_comment_id"),
		"customer_id": custID, "customer_code": php.Coalesce(g(cust, "code"), f["customer_code"]), "customer_name": php.Coalesce(g(cust, "name"), ""),
		"customer_vat_no": vat, "is_private": isPrivate,
		"phone": phone, "fax": fax, "email": email, "mailing_address": addrLine(mail), "delivery_address": php.OrStr(addrLine(del), addrLine(mail)),
		"postal_code": php.Coalesce(g(mail, "postal_code"), ""), "country_code": php.OrStr(countryS, "TH"),
		"vat_percentage": vatPct, "vat_name": vatName,
		"seller_name": sellerName, "seller_department": deptName,
		"warehouse": wh, "warehouse_code": whCode,
		"seller_department_code": deptCode, "grace_days": grace,
		"due_date": due, "delivery_by": deliveryBy, "delivery_note_number": noteS,
		"external_comment": ext, "payment_remark": paymentRemark(php.Coalesce(g(cust, "id"), f["customer_id"], g(order, "customer_id"))),
	}
	var out []invLine
	for _, l := range lines {
		fx := 1.0
		if l["exchange_rate"] != nil {
			fx = l.Float("exchange_rate")
		}
		out = append(out, invLine{
			PartNumber: php.Str(php.Or(l["part_number"], php.Coalesce(l["product_part_number"], ""))), PartName: php.Str(php.Coalesce(l["part_name"], "")),
			OrderedQty: php.Float(php.Or(l["ordered_quantity"], l["invoiced_quantity"])), InvoicedQty: l.Float("invoiced_quantity"), Price: l.Float("price"),
			Discount: l.Float("discount"), Conversion: l.Float("conversion_factor"), FxRate: fx, ItemType: "OTHER", OrderRowType: l["order_row_type"],
			UnitCode: php.Str(php.Coalesce(l["unit_code"], "")), DeliveryDate: l["invoice_date"],
		})
	}
	return doc, out
}

// paymentRemark: the comment of the customer's extra field; several such comments are joined.
func paymentRemark(customerID any) any {
	if !php.Truthy(customerID) {
		return nil
	}
	t := strings.TrimSpace(php.Str(acc().Scalar(`SELECT string_agg(trim(c.raw_text), E'\n' ORDER BY ef.id) FROM erp_extra_fields ef JOIN erp_comments c ON c.id = ef.comment_id
                                        WHERE ef.parent_id = ? AND trim(coalesce(c.raw_text, '')) <> ''`, php.Int(customerID))))
	if t == "" {
		return nil
	}
	return t
}

type pdfRow struct {
	No                    int
	PartNumber, PartName  string
	Batch                 string
	DeliveryDate          any
	Qty                   float64
	Unit                  string
	Price, Discount, Conv float64
	Sub                   []string
}

var nlRe = regexp.MustCompile(`\r?\n`)

// pdfRows: the order's own rows that were delivered on this invoice, in order, with free-text rows (type 4) attached to the
// product above them and the batch numbers of the stock movements (port of DocumentDTO.mergeRows). Empty = no order rows.
func pdfRows(doc app.M) []*pdfRow {
	if !php.Truthy(doc["order_id"]) {
		return nil
	}
	rows := acc().Select(`SELECT r.*, p.part_number, p.description AS part_name, u.code AS unit_code FROM erp_order_rows r
                              LEFT JOIN erp_products p ON p.id = r.part_id LEFT JOIN erp_units u ON u.id = r.unit_id
                             WHERE r.parent_order_id = ? ORDER BY r.row_index, r.id`, doc["order_id"])
	if len(rows) == 0 {
		return nil
	}
	// the delivery rows of this invoice's delivery note: only those products belong on this document
	oi := acc().Scalar("SELECT d.invoice_id FROM erp_invoices i JOIN erp_order_deliveries d ON d.id = i.delivery_row_id WHERE i.invoice_number = ? AND d.invoice_id IS NOT NULL LIMIT 1", doc["invoice_no"])
	delivered := map[int]bool{}
	if php.Truthy(oi) {
		for _, r := range acc().Select("SELECT customer_order_row_id AS id FROM erp_order_deliveries WHERE invoice_id = ?", oi) {
			delivered[r.Int("id")] = true
		}
	}
	var out []*pdfRow
	var cur *pdfRow
	seq := 0
	for _, r := range rows {
		if r.Int("order_row_type") == 4 { // free text: belongs to the product above
			var parts []string
			for _, p := range nlRe.Split(r.Str("free_text"), -1) {
				p = strings.TrimSpace(p)
				if p != "" && p != "0" {
					parts = append(parts, p)
				}
			}
			txt := strings.Join(parts, "\n")
			if cur != nil && txt != "" {
				cur.Sub = append(cur.Sub, txt)
			}
			continue
		}
		if len(delivered) > 0 && !delivered[r.Int("id")] {
			cur = nil
			continue
		}
		var batch any
		if php.Truthy(r["part_id"]) {
			batch = acc().Scalar("SELECT string_agg(DISTINCT batch_number, ', ') FROM erp_stock_transactions WHERE part_id = ? AND order_number = ? AND coalesce(batch_number, '') <> ''", r["part_id"], doc["order_number"])
		}
		seq += 10
		cur = &pdfRow{No: seq, PartNumber: r.Str("part_number"), PartName: php.Str(php.Or(r["additional_row_description"], r["part_name"])), Batch: php.Str(batch),
			DeliveryDate: r["delivery_date"], Qty: r.Float("ordered_quantity"), Unit: r.Str("unit_code"), Price: r.Float("price"), Discount: r.Float("discount"), Conv: r.Float("conversion_factor")}
		out = append(out, cur)
	}
	return out
}

// addrLine: one line of address text: lines 1–5, city, region, post code.
func addrLine(a db.Row) string {
	if a == nil {
		return ""
	}
	var parts []string
	for _, k := range []string{"field1", "field2", "field3", "field4", "field5", "locality", "region", "postal_code"} {
		if v := strings.TrimSpace(a.Str(k)); v != "" {
			parts = append(parts, v)
		}
	}
	return strings.Join(parts, " ")
}

// ------------------------------------------------------------------ credit note (81)

var refTH = regexp.MustCompile(`การเครดิตเลขที่ใบกำกับสินค้า\s*(\d{6})`)
var refEN = regexp.MustCompile(`(?i)Crediting\s+of\s+invoice\s+number[\s\S]{0,60}?(\d{6})`)
var amountLineRe = regexp.MustCompile(`^-?[\d,]+\.\d+$`)

type creditInfo struct {
	Reference, ReferenceDate, Purpose, Original, Adjusted string
	RefDate                                               string // d/m/y, for the PDF
}

// creditInfoOf: which invoice a credit note credits, how much it was and what it is now (from the order's texts).
func creditInfoOf(doc app.M) *creditInfo {
	ref := ""
	if php.Truthy(doc["order_id"]) {
		txt := acc().Scalar("SELECT free_text FROM erp_order_rows WHERE parent_order_id = ? AND order_row_type = 4 ORDER BY row_index LIMIT 1", doc["order_id"])
		ref = creditExtract(php.Str(txt))
		if ref == "" {
			ref = creditExtract(php.Str(doc["external_comment"]))
		}
	}
	comment := php.Str(doc["external_comment"])
	var cl []string
	if comment != "" {
		cl = nlRe.Split(comment, -1)
	}
	last := len(cl) - 1
	for i := len(cl) - 1; i >= 0; i-- {
		if amountLineRe.MatchString(strings.TrimSpace(cl[i])) {
			last = i
			break
		}
	}
	clean := func(i int) string {
		if i < 0 || i >= len(cl) || strings.TrimSpace(cl[i]) == "" {
			return "0.00"
		}
		return strings.ReplaceAll(strings.TrimSpace(cl[i]), ",", "")
	}
	var head []string
	for i := 0; i < max(0, last-1) && i < len(cl); i++ {
		head = append(head, strings.TrimSpace(cl[i]))
	}
	ci := &creditInfo{Reference: ref, Purpose: strings.TrimSpace(strings.Join(head, " ")), Original: clean(last - 1), Adjusted: clean(last)}
	if ref != "" {
		ci.ReferenceDate = php.Str(acc().Scalar("SELECT max(invoice_date) FROM erp_invoices WHERE invoice_number = ?", php.Int(ref)))
	}
	return ci
}

func creditExtract(t string) string {
	if m := refTH.FindStringSubmatch(t); m != nil {
		return m[1]
	}
	if m := refEN.FindStringSubmatch(t); m != nil {
		return m[1]
	}
	return ""
}
