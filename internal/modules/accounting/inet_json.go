package accounting

import (
	"errors"
	"math"
	"strings"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/php"
)

/*
The INET text file (JSON) of one document (Inet\JsonBuilder): header C/H/B, lines L, footer F/T.
A straight port of InetTextBuilder + InetLineBuilder + InetValidator of the old system.
Amounts are always written in THB (each line is converted with its exchange rate), like before.
*/

const defaultContact = "Boonyawat"
const defaultDept = "Acme"

type docTotals struct{ Basis, Discount, Tax, Grand float64 }

type builtDoc struct {
	JSON   string
	Totals docTotals
}

type vatInfo struct {
	Rate float64
	Type string
}

func docVat(doc app.M) vatInfo {
	if doc["vat_percentage"] == nil {
		return vatInfo{0, "VAT"}
	}
	u := strings.ToUpper(php.Str(doc["vat_name"]))
	t := "VAT"
	if strings.Contains(u, "FREE") || strings.Contains(u, "FRE") {
		t = "FRE"
	} else if strings.Contains(u, "ZERO") || strings.Contains(u, "0%") {
		t = "ZERO"
	}
	return vatInfo{php.Float(doc["vat_percentage"]), t}
}

func clean(s any) string { return strings.TrimSpace(php.Str(s)) }

func orDef(v any, d string) string {
	if t := strings.TrimSpace(php.Str(v)); t != "" {
		return t
	}
	return d
}

// dtm: 2026-10-07T00:00:00 — the date and time as written in the ERP, no zone conversion.
func dtm(v any) string {
	s := php.Str(v)
	if s == "" {
		return ""
	}
	t, ok := php.ParseTime(s)
	if !ok {
		return s
	}
	return php.Date("Y-m-d", t) + "T" + php.Date("H:i:s", t)
}

func jsonBuild(c *app.Ctx, doc app.M, lines []invLine, docType string, company db.Row, credit *creditInfo) builtDoc {
	if len(lines) == 0 {
		panic(errors.New(c.T("Invoice :no has no lines.", "no", doc["invoice_no"])))
	}
	vat := docVat(doc)
	var rows []any
	for i, l := range lines {
		rows = append(rows, inetLine(i+1, l, php.Bool(doc["is_foreign"]), vat))
	}
	t := docTotalsOf(lines, vat)

	main := config.Strings("inet_schema.main")
	cm := config.NewMap()
	for _, k := range main {
		cm.Set(k, "")
	}
	set := func(k, v string) { cm.Set(k, v) }
	inv := php.Str(doc["invoice_no"])
	date := dtm(doc["invoice_date"])

	set("C01-SELLER_TAX_ID", clean(company["tax_id"]))
	set("C02-SELLER_BRANCH_ID", clean(company["branch_id"]))
	set("C03-FILE_NAME", inv+".txt")
	set("H01-DOCUMENT_TYPE_CODE", docType)
	set("H02-DOCUMENT_NAME", "acme-tax-"+inv)
	set("H03-DOCUMENT_ID", inv)
	set("H04-DOCUMENT_ISSUE_DTM", date)
	if docType == "81" {
		if credit == nil {
			credit = &creditInfo{}
		}
		ref := clean(credit.Reference)
		set("H05-CREATE_PURPOSE_CODE", "CDNS99")
		set("H06-CREATE_PURPOSE", credit.Purpose)
		if credit.ReferenceDate != "" {
			set("H08-ADDITIONAL_REF_ISSUE_DTM", dtm(credit.ReferenceDate))
		} else {
			set("H08-ADDITIONAL_REF_ISSUE_DTM", date)
		}
		set("H07-ADDITIONAL_REF_ASSIGN_ID", ref)
		if ref == "" {
			set("H10-ADDITIONAL_REF_DOCUMENT_NAME", "")
		} else {
			set("H10-ADDITIONAL_REF_DOCUMENT_NAME", ref+".txt")
		}
		set("H09-ADDITIONAL_REF_TYPE_CODE", "388")
	}
	set("H12-BUYER_ORDER_ASSIGN_ID", clean(doc["po_number"]))
	set("H17-SELLER_CONTACT_PERSON_NAME", clean(doc["seller_name"]))
	set("H18-SELLER_CONTACT_DEPARTMENT_NAME", clean(doc["seller_department"]))
	set("H26-SEND_MAIL_IND", "N")

	taxNo := clean(doc["customer_vat_no"])
	set("B01-BUYER_ID", clean(doc["customer_code"]))
	set("B02-BUYER_NAME", clean(doc["customer_name"]))
	switch {
	case taxNo == "":
		set("B03-BUYER_TAX_ID_TYPE", "OTHR")
	case php.Bool(doc["is_private"]):
		set("B03-BUYER_TAX_ID_TYPE", "NIDN")
	case taxNo == "0000000000000":
		set("B03-BUYER_TAX_ID_TYPE", "OTHR")
	default:
		set("B03-BUYER_TAX_ID_TYPE", "TXID")
	}
	if taxNo == "0000000000000" || taxNo == "" {
		set("B04-BUYER_TAX_ID", "N/A")
	} else {
		set("B04-BUYER_TAX_ID", taxNo)
	}
	set("B05-BUYER_BRANCH_ID", "00000")
	set("B06-BUYER_CONTACT_PERSON_NAME", orDef(doc["seller_name"], defaultContact))
	set("B07-BUYER_CONTACT_DEPARTMENT_NAME", orDef(doc["seller_department"], defaultDept))
	set("B10-BUYER_POST_CODE", orDef(doc["postal_code"], "00000"))
	if clean(doc["mailing_address"]) != "" {
		set("B13-BUYER_ADDRESS_LINE1", php.Str(doc["mailing_address"]))
	}
	set("B25-BUYER_COUNTRY_ID", orDef(doc["country_code"], "TH"))

	cur := "THB"
	set("F01-LINE_TOTAL_COUNT", php.Str(len(lines)))
	set("F03-INVOICE_CURRENCY_CODE", cur)
	set("F04-TAX_TYPE_CODE1", vat.Type)
	set("F05-TAX_CAL_RATE1", s2(math.Abs(vat.Rate)))
	set("F06-BASIS_AMOUNT1", s2(t.Basis))
	set("F07-BASIS_CURRENCY_CODE1", cur)
	set("F08-TAX_CAL_AMOUNT1", s2(t.Tax))
	set("F09-TAX_CAL_CURRENCY_CODE1", cur)
	set("F38-LINE_TOTAL_AMOUNT", s2(t.Grand))
	set("F39-LINE_TOTAL_CURRENCY_CODE", cur)
	set("F42-ALLOWANCE_TOTAL_AMOUNT", s2(t.Discount))
	set("F43-ALLOWANCE_TOTAL_CURRENCY_CODE", cur)
	if vat.Type == "FRE" {
		set("F46-TAX_BASIS_TOTAL_AMOUNT", "0.00")
	} else {
		set("F46-TAX_BASIS_TOTAL_AMOUNT", s2(t.Basis))
	}
	set("F47-TAX_BASIS_TOTAL_CURRENCY_CODE", cur)
	set("F48-TAX_TOTAL_AMOUNT", s2(t.Tax))
	set("F49-TAX_TOTAL_CURRENCY_CODE", cur)
	set("F50-GRAND_TOTAL_AMOUNT", s2(t.Grand))
	set("F51-GRAND_TOTAL_CURRENCY_CODE", cur)
	if docType == "81" {
		orig := clean(credit.Original)
		set("F36-ORIGINAL_TOTAL_AMOUNT", orig)
		if orig == "" {
			set("F37-ORIGINAL_TOTAL_CURRENCY_CODE", "")
		} else {
			set("F37-ORIGINAL_TOTAL_CURRENCY_CODE", cur)
		}
		adj := strings.TrimSpace(credit.Adjusted)
		if adj == "" {
			adj = "0.00"
		}
		set("F40-ADJUSTED_INFORMATION_AMOUNT", adj)
		set("F41-ADJUSTED_INFORMATION_CURRENCY_CODE", cur)
	}
	set("T01-TOTAL_DOCUMENT_COUNT", "1")

	jsonValidate(cm, docType)

	// the lines sit between the buyer (B) and the footer (F) fields, like the old file
	ordered := config.NewMap()
	for _, k := range main {
		if k == "F01-LINE_TOTAL_COUNT" {
			ordered.Set("LINE_ITEM_INFORMATION", rows)
		}
		ordered.Set(k, mget(cm, k))
	}
	// keys set but not in the schema come after it (array_fill_keys + assignments in PHP)
	for _, k := range cm.Keys {
		if _, ok := ordered.Get(k); !ok {
			ordered.Set(k, cm.Vals[k])
		}
	}
	return builtDoc{JSON: jsonEnc(ordered, true), Totals: t}
}

func lineQty(l invLine) float64 {
	if l.Conversion != 0 {
		return r2(l.InvoicedQty / l.Conversion)
	}
	return l.InvoicedQty
}

func inetLine(no int, l invLine, foreign bool, vat vatInfo) *config.Map {
	fx := l.FxRate
	if fx == 0 {
		fx = 1
	}
	qty := lineQty(l)
	gross := l.Price * qty
	disc := r2(gross * l.Discount / 100)
	basis := math.Abs(r2(r2(gross-disc) * fx))
	rateVal := moneyR(vat.Rate/100, 4)
	tax := math.Abs(r2(basis * rateVal))
	net := math.Abs(r2(basis + tax))
	discOut := r2(math.Abs(disc) * fx)
	cur := "THB"

	s := config.NewMap()
	for _, k := range config.Strings("inet_schema.line") {
		s.Set(k, "")
	}
	for _, k := range config.Strings("inet_schema.line_zero") {
		s.Set(k, "0.00")
	}
	s.Set("L01-LINE_ID", php.Str(no))
	s.Set("L02-PRODUCT_ID", clean(l.PartNumber))
	s.Set("L10-PRODUCT_CHARGE_AMOUNT", s2(basis))
	s.Set("L11-PRODUCT_CHARGE_CURRENCY_CODE", cur)
	s.Set("L17-PRODUCT_QUANTITY", nf(math.Abs(l.OrderedQty), 4, ".", ""))
	s.Set("L20-LINE_TAX_TYPE_CODE", vat.Type)
	s.Set("L21-LINE_TAX_CAL_RATE", s2(vat.Rate))
	typ := php.OrStr(l.ItemType, "OTHER")
	if typ == "DEPOSIT" || typ == "DISCOUNT" {
		if typ == "DEPOSIT" {
			s.Set("L03-PRODUCT_NAME", "Deposit from customer")
		} else {
			s.Set("L03-PRODUCT_NAME", "Discount")
		}
		s.Set("L22-LINE_BASIS_AMOUNT", "0.00")
		s.Set("L24-LINE_TAX_CAL_AMOUNT", "0.00")
		s.Set("L26-LINE_ALLOWANCE_CHARGE_IND", "false")
		s.Set("L27-LINE_ALLOWANCE_ACTUAL_AMOUNT", s2(basis))
		s.Set("L28-LINE_ALLOWANCE_ACTUAL_CURRENCY_CODE", "")
	} else {
		s.Set("L03-PRODUCT_NAME", clean(l.PartName))
		s.Set("L22-LINE_BASIS_AMOUNT", s2(basis))
		s.Set("L24-LINE_TAX_CAL_AMOUNT", s2(tax))
		s.Set("L26-LINE_ALLOWANCE_CHARGE_IND", "")
		switch {
		case foreign:
			s.Set("L27-LINE_ALLOWANCE_ACTUAL_AMOUNT", s2(basis))
		case discOut == 0:
			s.Set("L27-LINE_ALLOWANCE_ACTUAL_AMOUNT", "0.00")
		default:
			s.Set("L27-LINE_ALLOWANCE_ACTUAL_AMOUNT", s2(discOut))
		}
		s.Set("L28-LINE_ALLOWANCE_ACTUAL_CURRENCY_CODE", cur)
	}
	s.Set("L31-LINE_TAX_TOTAL_AMOUNT", s2(tax))
	s.Set("L33-LINE_NET_TOTAL_AMOUNT", s2(basis))
	s.Set("L35-LINE_NET_INCLUDE_TAX_TOTAL_AMOUNT", s2(net))
	for _, k := range []string{"L23-LINE_BASIS_CURRENCY_CODE", "L25-LINE_TAX_CAL_CURRENCY_CODE", "L32-LINE_TAX_TOTAL_CURRENCY_CODE", "L34-LINE_NET_TOTAL_CURRENCY_CODE", "L36-LINE_NET_INCLUDE_TAX_TOTAL_CURRENCY_CODE"} {
		s.Set(k, cur)
	}
	return s
}

func docTotalsOf(lines []invLine, vat vatInfo) docTotals {
	basis, discount := 0.0, 0.0
	for _, l := range lines {
		fx := l.FxRate
		if fx == 0 {
			fx = 1
		}
		qty := lineQty(l)
		gross := l.Price * qty
		disc := r2(gross * l.Discount / 100)
		basis += r2(r2(gross-disc) * fx)
		discount += r2(math.Abs(disc) * fx)
	}
	basis = math.Abs(r2(basis))
	discount = math.Abs(r2(discount))
	tax := r2(basis * moneyR(vat.Rate/100, 4))
	return docTotals{Basis: basis, Discount: discount, Tax: tax, Grand: r2(basis + tax)}
}

// jsonValidate is InetValidator.
func jsonValidate(c *config.Map, docType string) {
	var errs []string
	if mstr(c, "B03-BUYER_TAX_ID_TYPE") != "OTHR" && len(strings.TrimSpace(mstr(c, "B04-BUYER_TAX_ID"))) < 13 {
		errs = append(errs, "Buyer tax ID must be at least 13 digits for non-OTHR tax ID types (B04)")
	}
	if docType == "81" {
		for _, kv := range [][2]string{{"H07-ADDITIONAL_REF_ASSIGN_ID", "Credit note reference ID is required (H07)"}, {"H06-CREATE_PURPOSE", "Credit note create purpose is required (H06)"},
			{"H05-CREATE_PURPOSE_CODE", "Credit note create purpose code is required (H05)"}, {"F36-ORIGINAL_TOTAL_AMOUNT", "Credit note original amount is required (F36)"},
			{"F40-ADJUSTED_INFORMATION_AMOUNT", "Credit note adjusted information amount is required (F40)"}} {
			if strings.TrimSpace(mstr(c, kv[0])) == "" {
				errs = append(errs, kv[1])
			}
		}
	}
	if len(errs) > 0 {
		panic(errors.New(strings.Join(errs, "; ")))
	}
}
