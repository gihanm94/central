package accounting

import (
	"bytes"
	"crypto/rand"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"math"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"syscall"
	"time"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

/*
PdfRenderer: the HTML of a document (a port of resources/views/accounting/pdf/document.php, written here so the page is
byte for byte the one the PHP version printed) is printed to PDF by headless Chrome / Chromium. Set the program under
Accounting → Company when it is not found.
*/

type pdfTheme struct{ bg, head, text, border string }

var pdfThemes = map[string]pdfTheme{
	"388": {"#C2FAD3", "#0f3a26", "#1a1a1a", "#6cc992"},
	"T01": {"#FFB9FF", "#5c1a5c", "#1a1a1a", "#d98ad9"},
	"81":  {"#FFDBB7", "#5c3a1a", "#1a1a1a", "#d9a86c"},
}

var pdfTitles = map[string][4]string{
	"388": {"ใบกำกับภาษี", "TAX INVOICE", "เลขที่ใบกำกับภาษี", "TAX INV. NO."},
	"T01": {"ใบเสร็จรับเงิน", "RECEIPT", "เลขที่ใบเสร็จรับเงิน", "RECEIPT NO."},
	"81":  {"ใบลดหนี้", "CREDIT NOTE", "เลขที่ใบลดหนี้", "CN NO."},
}

// chromium: the program that prints the PDFs, or "".
func chromium() string {
	cands := []string{}
	if s := sget("pdf.chromium", ""); s != "" {
		cands = append(cands, s)
	}
	g, _ := filepath.Glob("/opt/pw-browsers/chromium-*/chrome-linux/chrome")
	cands = append(cands, g...)
	cands = append(cands, "/usr/bin/chromium", "/usr/bin/chromium-browser", "/usr/bin/google-chrome", "/usr/bin/google-chrome-stable", "/snap/bin/chromium")
	for _, c := range cands {
		if st, err := os.Stat(c); err == nil && st.Mode().IsRegular() && st.Mode()&0o111 != 0 {
			return c
		}
	}
	return ""
}

// pe is the template's $e: htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE).
func pe(v any) string {
	return strings.NewReplacer("&", "&amp;", "<", "&lt;", ">", "&gt;", `"`, "&quot;", "'", "&#039;").Replace(strings.ToValidUTF8(php.Str(v), "�"))
}

func addslashes(s string) string {
	return strings.NewReplacer(`\`, `\\`, `'`, `\'`, `"`, `\"`, "\x00", `\0`).Replace(s)
}

// trimNum is rtrim(rtrim(number_format($v, 2, '.', ”), '0'), '.').
func trimNum(v float64) string {
	return strings.TrimRight(strings.TrimRight(nf(v, 2, ".", ""), "0"), ".")
}

func dmy(v any, f string) string {
	if php.Empty(v) {
		return "-"
	}
	t, ok := php.ParseTime(php.Str(v))
	if !ok {
		return php.Str(v)
	}
	return php.Date(f, t)
}

func pdfData(file, mime string) string {
	raw, err := os.ReadFile(filepath.Join(app.BasePath, "resources", "pdf", file))
	if err != nil {
		return ""
	}
	return "data:" + mime + ";base64," + base64.StdEncoding.EncodeToString(raw)
}

var (
	fontsOnce sync.Once
	fontsCSS  string
)

func pdfFonts() string {
	fontsOnce.Do(func() {
		for _, w := range []struct {
			n    int
			name string
		}{{400, "Regular"}, {500, "Medium"}, {600, "SemiBold"}, {700, "Bold"}} {
			raw, err := os.ReadFile(filepath.Join(app.BasePath, "resources", "pdf", "sarabun", "Sarabun-"+w.name+".ttf"))
			if err == nil {
				fontsCSS += "@font-face{font-family:'Sarabun';font-weight:" + php.Str(w.n) + ";src:url('data:font/truetype;base64," + base64.StdEncoding.EncodeToString(raw) + "') format('truetype');}\n"
			}
		}
	})
	return fontsCSS
}

type pdfItem struct {
	No                          int
	PartNumber, PartName, Batch string
	Sub                         []string
	DeliveryDate                string
	Qty                         float64
	Unit                        string
	Price, Discount, Amount     float64
}

// documentHTML is PdfRenderer::html: the page that is printed.
func documentHTML(doc app.M, lines []invLine, company db.Row, docType string, thai bool, totals docTotals, credit *creditInfo) string {
	vat := docVat(doc)
	fxd := php.Float(doc["exchange_rate"])
	if fxd == 0 {
		fxd = 1
	}
	var items []pdfItem
	if rows := pdfRows(doc); len(rows) > 0 { // the order's rows, like the old system
		sum := 0.0
		for _, r := range rows {
			q := r.Qty
			if r.Conv != 0 {
				q = r2(r.Qty / r.Conv)
			}
			gross := r.Price * q
			amt := r2(r2(gross-r2(gross*r.Discount/100)) * fxd)
			sum += math.Abs(amt)
			items = append(items, pdfItem{No: r.No, PartNumber: r.PartNumber, PartName: r.PartName, Batch: r.Batch, Sub: r.Sub, DeliveryDate: dmy(r.DeliveryDate, "d/m/Y"),
				Qty: math.Abs(r.Qty), Unit: r.Unit, Price: math.Abs(r.Price * fxd), Discount: math.Abs(r.Discount), Amount: math.Abs(amt)})
		}
		sum = r2(sum)
		tax := r2(sum * moneyR(vat.Rate/100, 4))
		totals.Basis, totals.Tax, totals.Grand = sum, tax, r2(sum+tax)
	} else { // no order rows in the copy: the invoice lines
		no := 0
		for _, l := range lines {
			fx := l.FxRate
			if fx == 0 {
				fx = 1
			}
			qty := lineQty(l)
			gross := l.Price * qty
			amount := math.Abs(r2(r2(gross-r2(gross*l.Discount/100)) * fx))
			no += 10
			items = append(items, pdfItem{No: no, PartNumber: l.PartNumber, PartName: l.PartName, DeliveryDate: dmy(l.DeliveryDate, "d/m/Y"), Qty: math.Abs(l.OrderedQty),
				Unit: l.UnitCode, Price: math.Abs(l.Price * fx), Discount: math.Abs(l.Discount), Amount: amount})
		}
	}
	pick := func(th, en string) string {
		if thai {
			return th
		}
		return en
	}
	note := ""
	switch docType {
	case "T01":
		note = company.Str(pick("receipt_note_th", "receipt_note_en"))
		if strings.TrimSpace(note) == "" {
			note = pick("ใบเสร็จจะสมบูรณ์ก็ต่อเมื่อบริษัทฯ ได้รับชำระตามเอกสารเรียบร้อยแล้ว", "This receipt is complete only once the company has received payment as stated in this document.")
		}
	case "81":
		note = company.Str(pick("credit_note_th", "credit_note_en"))
	}
	// the red interest notice above the totals: tax invoice only (the company's billing interest note, else the usual text)
	interest := ""
	if docType == "388" {
		interest = strings.TrimSpace(company.Str(pick("payment_note_th", "payment_note_en")))
		if interest == "" {
			interest = pick("***บริษัทฯ สงวนสิทธิ์ที่จะคิดดอกเบี้ยจากเงินที่ค้างชำระในอัตราร้อยละ 1.50 ต่อเดือน ตลอดไปจนกว่าจะได้รับการชำระเสร็จสิ้น***",
				"***The company reserves the right to charge interest of 1.50% per month on overdue amounts until they are paid in full.***")
		}
	}
	var cr *creditInfo
	if docType == "81" && credit != nil {
		cc := *credit
		if cc.ReferenceDate != "" {
			cc.RefDate = php.DateOf("d/m/y", cc.ReferenceDate)
		}
		cr = &cc
	}
	footer := pick("**เอกสารนี้ได้จัดทำและส่งข้อมูลให้แก่กรมสรรพากรด้วยวิธีการทางอิเล็กทรอนิกส์**", "**This document has been prepared and submitted to the Revenue Department electronically.**")
	return documentPage(doc, company, items, totals, thai, docType, php.Str(doc["invoice_no"]), dmy(doc["invoice_date"], "d-m-Y"), pdfTitles[docType], pdfThemes[docType],
		vat.Rate, moneyWords(totals.Grand, "THB", thai), cr, pdfFonts(), pdfData("logo.jpg", "image/jpeg"), pdfData("signature.jpeg", "image/jpeg"), footer, note, interest)
}

// documentPage writes accounting/pdf/document.php.
func documentPage(d app.M, company db.Row, items []pdfItem, t docTotals, th bool, docType, number, date string, titles [4]string, theme pdfTheme,
	vatRate float64, words string, credit *creditInfo, fonts, logo, signature, footerText, note, interest string) string {
	p := func(a, b string) string {
		if th {
			return a
		}
		return b
	}
	fmtN := func(v float64) string { return nf(v, 2, ".", ",") }
	cs := func(k string) string { return company.Str(k) }
	var w strings.Builder
	W := func(s ...string) {
		for _, x := range s {
			w.WriteString(x)
		}
	}
	W(`<!DOCTYPE html>
<html lang="`, p("th", "en"), `"><head><meta charset="UTF-8"><title>`, pe("acme-tax-"+php.Str(d["invoice_no"])), `</title>
<style>`, fonts, `</style>
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
@page{size:A4;margin:0 0 6mm 0;
  @bottom-left{content:"`, addslashes(footerText), `";width:174mm;padding-left:18mm;text-align:center;vertical-align:middle;font-family:'Sarabun',sans-serif;font-size:10px;color:`, pe(theme.head), `;background:`, pe(theme.bg), `}
  @bottom-right{content:counter(page) "/" counter(pages);width:18mm;padding-right:18px;text-align:right;vertical-align:middle;font-family:'Sarabun',sans-serif;font-size:10px;color:`, pe(theme.head), `;background:`, pe(theme.bg), `}}
.avoid-break{page-break-inside:avoid;break-inside:avoid}
tr{page-break-inside:avoid}
</style></head>
<body style="--header-text:`, pe(theme.head), `;--text:`, pe(theme.text), `;--border:`, pe(theme.border), `;--bg:`, pe(theme.bg), `;">
<div style="display:flex;flex-direction:column;min-height:291mm;">

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
      <p class="font-semibold leading-tight c-text" style="font-size:13px;">`, pe(titles[0]), `</p>
      <p class="font-semibold leading-tight c-text" style="font-size:11px;">`, pe(titles[1]), `</p>
    </div>
    <div class="bd" style="border-radius:4px;overflow:hidden;">
      <div class="bd-b" style="display:grid;grid-template-columns:1fr 1fr;">
        <div class="bd-r" style="padding:5px 8px;"><p class="text-10px font-medium leading-tight c-text">`, pe(titles[2]), `</p><p class="text-10px leading-tight c-text">`, pe(titles[3]), `</p></div>
        <div style="padding:5px 8px;display:flex;align-items:center;"><p class="c-text" style="font-size:15px;">`, pe(number), `</p></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;">
        <div class="bd-r" style="padding:5px 8px;"><p class="text-10px font-medium leading-tight c-text">วันที่</p><p class="text-10px leading-tight c-text">DATE</p></div>
        <div style="padding:5px 8px;display:flex;align-items:center;"><p class="c-text" style="font-size:15px;">`, pe(date), `</p></div>
      </div>
    </div>
  </div>
</div>
<div class="bg-band" style="height:3px;"></div>

<!-- the empty table footer repeats on every printed page, so rows never run under the fixed bottom block -->
<table style="width:100%;border-collapse:collapse;"><tbody><tr style="page-break-inside:auto;break-inside:auto;"><td style="padding:0;">
<div class="px-5 py-3 mt-2" style="display:flex;flex-direction:column;gap:7px;">
  <!-- customer -->
  <div class="bd" style="display:flex;border-radius:6px;overflow:hidden;font-size:10px;">
    `)
	for _, c := range []struct {
		l string
		v any
		k int
	}{{p("รหัสลูกค้า", "Customer Code"), d["customer_code"], 0}, {p("ชื่อลูกค้า", "Customer Name"), d["customer_name"], 1}, {p("สาขา", "Branch"), p("สำนักงานใหญ่", "Head Office"), 0}, {p("เลขประจำตัวผู้เสียภาษี", "Tax No"), d["customer_vat_no"], 2}} {
		br, fl := "bd-r", ""
		if c.k == 2 {
			br = ""
		}
		if c.k == 1 {
			fl = "flex:1;"
		}
		W(`    <div class="bg-white `, br, `" style="`, fl, `"><p class="text-11px font-medium c-head bg-band" style="padding:4px 10px;">`, pe(c.l), `</p><p class="text-11px whitespace-pre leading-relaxed c-text" style="padding:5px 10px;">`, pe(c.v), `</p></div>
    `)
	}
	W(`  </div>
  <!-- addresses: bill to | ship to (always both, like the old documents) -->
  <div class="grid-2">
    `)
	type foot struct {
		l string
		v any
	}
	delAddr := php.Or(d["delivery_address"], d["mailing_address"])
	for _, a := range []struct {
		l    string
		addr any
		foot []foot
	}{{p("ที่อยู่", "Address"), d["mailing_address"], []foot{{p("โทรศัพท์", "Tel"), d["phone"]}, {p("โทรสาร", "Fax"), d["fax"]}}},
		{p("ที่อยู่จัดส่ง", "Delivery Address"), delAddr, []foot{{p("เลขที่ใบส่งสินค้า", "Delivery Note No."), d["delivery_note_number"]}}}} {
		W(`    <div class="bd" style="border-radius:6px;overflow:hidden;display:flex;flex-direction:column;">
      <p class="bg-band c-head font-semibold text-11px" style="padding:4px 10px;">`, pe(a.l), `</p>
      <div class="bg-white" style="padding:5px 10px 4px;flex:1;min-height:54px;">
        <p class="text-11px font-medium leading-relaxed c-text">`, pe(d["customer_name"]), `</p>
        <p class="text-11px whitespace-pre leading-relaxed c-text">`, pe(a.addr), `</p>
      </div>
      <div class="bg-white" style="display:flex;gap:28px;padding:2px 10px 5px;">
        `)
		for _, f := range a.foot {
			v := php.Str(f.v)
			if strings.TrimSpace(v) == "" {
				v = "-"
			}
			W(`<p class="text-11px c-text">`, pe(f.l), ` : `, pe(v), `</p>`)
		}
		W(`      </div>
    </div>
    `)
	}
	W(`  </div>
  <!-- order info -->
  <div class="bd" style="display:grid;grid-template-columns:minmax(90px,1.4fr) minmax(80px,1fr) minmax(60px,.8fr) minmax(60px,.8fr) minmax(50px,.7fr) minmax(70px,.9fr) minmax(70px,.9fr) minmax(90px,1.4fr);border-radius:6px;overflow:hidden;">
    `)
	credDays := ""
	if d["grace_days"] != nil && php.Str(d["grace_days"]) != "" {
		credDays = php.Str(d["grace_days"]) + " days"
	}
	cells := [][2]any{{p("คำสั่งซื้อ", "Order No"), php.Or(d["po_number"], "N/A")}, {p("เลขที่ใบสั่งขาย", "Sale Order No"), d["order_number"]},
		{p("คลังสินค้า", "Warehouse"), php.Or(php.Coalesce(d["warehouse_code"], ""), d["warehouse"])}, {p("แผนก", "Division"), php.Or(php.Coalesce(d["seller_department_code"], ""), d["seller_department"])},
		{p("เครดิต (วัน)", "Credit (Days)"), credDays}, {p("วันที่ครบกำหนด", "Due Date"), d["due_date"]}, {p("วิธีการจัดส่ง", "Delivery Method"), d["delivery_by"]}, {p("ผู้ขาย", "Seller"), d["seller_name"]}}
	for i, c := range cells {
		br := ""
		if i < 7 {
			br = "bd-r"
		}
		W(`    <div class="bg-white `, br, `" style="text-align:center;"><p class="text-11px font-medium c-head bg-band nowrap" style="padding:4px 6px;">`, pe(c[0]), `</p><p class="text-11px whitespace-pre leading-relaxed c-text" style="padding:5px 6px;">`, pe(c[1]), `</p></div>
    `)
	}
	W(`  </div>
  <!-- lines -->
  <div class="bd" style="border-radius:6px;overflow:hidden;">
    <table class="w-full border-collapse" style="border-spacing:0;font-size:10px;">
      <thead><tr class="c-head bg-band">
        <th class="px-2 py-2 text-center font-semibold text-11px" style="width:24px;">`, p("ที่", "No."), `</th>
        <th class="px-2 py-2 text-left font-semibold text-11px" style="width:72px;">`, p("รหัสสินค้า", "Item Code"), `</th>
        <th class="px-2 py-2 text-left font-semibold text-11px">`, p("รายการ", "Description"), `</th>
        <th class="px-2 py-2 text-center font-semibold text-11px" style="width:72px;">`, p("วันที่จัดส่ง", "Delivery Date"), `</th>
        <th class="px-2 py-2 text-right font-semibold text-11px" style="width:60px;">`, p("จำนวน", "Qty"), `</th>
        <th class="px-2 py-2 text-right font-semibold text-11px" style="width:70px;">`, p("ราคาต่อหน่วย", "Unit Price"), `</th>
        <th class="px-2 py-2 text-right font-semibold text-11px" style="width:36px;">%</th>
        <th class="px-2 py-2 text-right font-semibold text-11px" style="width:80px;">`, p("จำนวนเงิน", "Amount"), `</th>
      </tr></thead>
      <tbody>
      `)
	if credit != nil && credit.Reference != "" {
		W(`        <tr class="bg-white"><td></td><td></td><td colspan="6" class="px-2 py-2 text-11px c-text">`, p("การเครดิตใบกำกับสินค้าเลขที่", "Crediting invoice number"), ` `, pe(credit.Reference), ` `, p("วันที่", "date"), ` `, pe(credit.RefDate), `</td></tr>
      `)
	}
	W(`      `)
	for _, it := range items {
		hb := strings.TrimSpace(it.Batch) != ""
		sp := ""
		if hb {
			sp = `<div style="height:16px;"></div>`
		}
		disc := "-"
		if it.Discount > 0 {
			disc = trimNum(it.Discount)
		}
		W(`        <tr class="bg-white">
          <td class="px-2 py-2 text-11px c-text" style="white-space:nowrap;vertical-align:top;"><div>`, php.Str(it.No), `</div>`, sp, `</td>
          <td class="px-2 py-2 text-11px c-text" style="white-space:nowrap;vertical-align:top;"><div>`, pe(it.PartNumber), `</div>`)
		if hb {
			W(`<div class="text-10px c-text" style="margin-top:2px;">`, p("หมายเลขแบทช์", "Batch No."), `</div>`)
		}
		W(`</td>
          <td class="px-2 py-2 text-11px c-text" style="vertical-align:top;"><div>`, pe(it.PartName), `</div>`)
		if hb {
			W(`<div class="text-10px c-text" style="margin-top:2px;">`, pe(it.Batch), `</div>`)
		}
		W(`            `)
		for _, sub := range it.Sub {
			W(`<div class="whitespace-pre leading-relaxed text-11px c-text" style="margin-top:10px;">`, pe(sub), `</div>`)
		}
		W(`</td>
          <td class="px-2 py-2 text-right text-11px c-text" style="white-space:nowrap;vertical-align:top;"><div>`, pe(it.DeliveryDate), `</div>`, sp, `</td>
          <td class="px-2 py-2 text-right text-11px c-text" style="white-space:nowrap;vertical-align:top;"><div>`, fmtN(it.Qty), ` `, pe(it.Unit), `</div>`, sp, `</td>
          <td class="px-2 py-2 text-right text-11px c-text" style="white-space:nowrap;vertical-align:top;"><div>`, fmtN(it.Price), `</div>`, sp, `</td>
          <td class="px-2 py-2 text-right text-11px c-text" style="white-space:nowrap;vertical-align:top;"><div>`, disc, `</div>`, sp, `</td>
          <td class="px-2 py-2 text-right text-11px c-text" style="white-space:nowrap;vertical-align:top;"><div>`, fmtN(it.Amount), `</div>`, sp, `</td>
        </tr>
      `)
	}
	W(`      </tbody>
    </table>
  </div>
  `)
	if credit != nil && strings.TrimSpace(credit.Purpose) != "" {
		W(`  <div class="bd bg-band avoid-break" style="border-radius:6px;padding:10px;font-size:10px;">
    <p class="font-semibold c-text" style="margin-bottom:4px;">`, p("หมายเหตุ", "Note"), `</p><p class="c-text">`, pe(credit.Purpose), `</p>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:8px;padding-top:8px;border-top:1px solid var(--border);">
      <div class="c-text" style="display:flex;justify-content:space-between;"><span>`, p("มูลค่าใบกำกับภาษีเดิม (ก่อน VAT)", "Original Amount (Before VAT)"), `</span><span>`, fmtN(php.Float(credit.Original)), ` (THB)</span></div>
      <div class="c-text" style="display:flex;justify-content:space-between;"><span>`, p("มูลค่าใบกำกับภาษีที่ถูกต้อง (ก่อน VAT)", "Corrected Amount (Before VAT)"), `</span><span>`, fmtN(php.Float(credit.Adjusted)), ` (THB)</span></div>
    </div>
  </div>
  `)
	}
	footH := "51"
	if interest != "" {
		footH = "60"
	}
	W(`
</div>
</td></tr></tbody><tfoot><tr><td style="padding:0;height:`, footH, `mm;"></td></tr></tfoot></table>

  <!-- bottom: interest notice (invoice) | remark + signature | totals; the footer bar with the page number is the @page margin box -->
  <div class="avoid-break" style="position:fixed;left:0;right:0;bottom:3mm;background:#fff;padding:0 18px;">
    `)
	if interest != "" {
		W(`    <p class="text-11px font-medium text-center" style="border:1.5px solid #d32f2f;color:#c62828;border-radius:4px;padding:4px 10px;margin-bottom:7px;">`, pe(interest), `</p>
    `)
	}
	W(`    <div style="display:grid;grid-template-columns:11fr 9fr;gap:10px;align-items:stretch;">
      <div class="bd-dash" style="border-radius:6px;padding:10px 14px;min-height:150px;display:flex;flex-direction:column;">
        `)
	if strings.TrimSpace(php.Str(d["payment_remark"])) != "" {
		W(`<p class="text-11px leading-relaxed c-text" style="white-space:pre-line;text-align:left;">`, pe(d["payment_remark"]), `</p>`)
	}
	W(`        `)
	if strings.TrimSpace(note) != "" {
		W(`<p class="text-11px leading-relaxed c-text" style="white-space:pre-line;text-align:left;margin-top:4px;">`, pe(note), `</p>`)
	}
	W(`        <div style="margin-top:auto;padding-top:8px;width:150px;text-align:center;">
          <div style="height:46px;display:flex;align-items:flex-end;justify-content:center;">`)
	if signature != "" {
		W(`<img src="`, signature, `" alt="" style="max-width:110px;height:44px;object-fit:contain;">`)
	}
	W(`</div>
          <div style="border-bottom:1px solid var(--text);margin-top:2px;"></div>
          <p class="text-11px c-text" style="margin-top:3px;">`, p("ผู้มีอำนาจลงนาม", "Authorized Signature"), `</p>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:8px;">
        <div class="bd" style="border-radius:6px;overflow:hidden;"><table class="border-collapse w-full">
          `)
	for i, r := range []struct {
		l string
		v float64
	}{{p("รวมจำนวนเงิน", "Subtotal") + " (THB)", t.Basis}, {"VAT " + trimNum(vatRate) + "% (THB)", t.Tax}, {p("รวมเงินทั้งสิ้น", "Grand Total") + " (THB)", t.Grand}} {
		bb, fs := "", ""
		if i < 2 {
			bb = "bd-b"
		}
		if i == 2 {
			fs = "font-semibold"
		}
		W(`          <tr class="bg-white"><td class="`, bb, ` text-11px c-text `, fs, `" style="padding:6px 10px;">`, pe(r.l), `</td><td class="`, bb, ` text-right c-text `, fs, `" style="padding:6px 10px;font-size:13px;">`, fmtN(r.v), `</td></tr>
          `)
	}
	W(`        </table></div>
        <div class="bd bg-band text-center" style="border-radius:6px;padding:7px 10px;"><p class="text-11px font-medium c-head">`, pe(words), `</p></div>
      </div>
    </div>
  </div>
</div>
</body></html>
`)
	return w.String()
}

// ---------------------------------------------------------------- printing

func randID(n int) string {
	b := make([]byte, n)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

type pdfResult struct {
	pdf []byte
	err error
}

// renderPDF prints one page.
func renderPDF(c *app.Ctx, html string) []byte {
	r := renderMany(c, map[string]string{"one": html}, 1, nil)["one"]
	if r.err != nil {
		panic(r.err)
	}
	return r.pdf
}

// renderMany prints many pages to PDF, up to parallel Chromium processes at the same time.
func renderMany(c *app.Ctx, pages map[string]string, parallel int, each func(n int)) map[string]pdfResult {
	bin := chromium()
	if bin == "" {
		panic(errors.New(c.T("PDF needs Chrome or Chromium on the server. Set its path under Accounting → Company.")))
	}
	dir := filepath.Join(app.BasePath, "storage", "cache", "pdf")
	_ = os.MkdirAll(dir, 0o775)
	home := filepath.Join(dir, "home")
	_ = os.MkdirAll(home, 0o775)
	parallel = max(1, min(8, parallel))
	keys := make([]string, 0, len(pages))
	for k := range pages {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	out := map[string]pdfResult{}
	var mu sync.Mutex
	sem := make(chan struct{}, parallel)
	var wg sync.WaitGroup
	t00 := time.Now()
	for _, k := range keys {
		wg.Add(1)
		sem <- struct{}{}
		go func(k string) {
			defer wg.Done()
			defer func() { <-sem }()
			r := printOne(c, bin, dir, home, k, pages[k])
			mu.Lock()
			out[k] = r
			n := len(out)
			mu.Unlock()
			if each != nil {
				each(n)
			}
		}(k)
	}
	wg.Wait()
	if len(pages) > 1 {
		logInfo("pdf", php.Str(len(pages))+" PDFs printed, "+php.Str(min(parallel, len(pages)))+" at a time", om("ms", int(time.Since(t00).Milliseconds())))
	}
	return out
}

func printOne(c *app.Ctx, bin, dir, home, key, html string) pdfResult {
	id := randID(6)
	htmlFile := filepath.Join(dir, id+".html")
	pdfFile := filepath.Join(dir, id+".pdf")
	logFile := filepath.Join(dir, id+".log")
	profile := filepath.Join(dir, "profile-"+id)
	defer func() {
		_ = os.Remove(htmlFile)
		_ = os.Remove(pdfFile)
		_ = os.Remove(logFile)
		_ = os.RemoveAll(profile)
	}()
	_ = os.WriteFile(htmlFile, []byte(html), 0o664)
	lf, _ := os.Create(logFile)
	cmd := exec.Command(bin, "--headless", "--no-sandbox", "--disable-gpu", "--disable-dev-shm-usage", "--no-pdf-header-footer", "--disable-crash-reporter", "--disable-breakpad",
		"--no-first-run", "--disable-extensions", "--disable-background-networking", "--disable-sync", "--mute-audio", "--hide-scrollbars", "--user-data-dir="+profile,
		"--print-to-pdf="+pdfFile, "file://"+htmlFile)
	cmd.Env = []string{"HOME=" + home, "XDG_CONFIG_HOME=" + home + "/.config", "XDG_CACHE_HOME=" + home + "/.cache", "PATH=/usr/local/bin:/usr/bin:/bin", "LANG=C.UTF-8"} // the web user often has no writable home
	cmd.Stdout, cmd.Stderr = lf, lf
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
	t0 := time.Now()
	if err := cmd.Start(); err != nil {
		if lf != nil {
			lf.Close()
		}
		return pdfResult{err: errors.New(c.T("The PDF could not be made.") + " (cannot start Chromium)")}
	}
	done := make(chan struct{})
	go func() { _ = cmd.Wait(); close(done) }()
	timeout := false
	lastSize := int64(-1)
	tick := time.NewTicker(60 * time.Millisecond)
loop:
	for {
		select {
		case <-done:
			break loop
		case <-tick.C:
			// Chromium sometimes writes the PDF and then does not exit (crash reporter, a profile it cannot lock …): the finished file is enough
			var size int64
			if st, err := os.Stat(pdfFile); err == nil {
				size = st.Size()
			}
			ready := false
			if size > 500 && size == lastSize {
				if raw, err := os.ReadFile(pdfFile); err == nil && bytes.Contains(raw[max(0, len(raw)-64):], []byte("%%EOF")) {
					ready = true
				}
			}
			lastSize = size
			if time.Since(t0) > 60*time.Second || ready {
				timeout = !ready
				_ = syscall.Kill(-cmd.Process.Pid, syscall.SIGKILL) // its helper processes too
				<-done
				break loop
			}
		}
	}
	tick.Stop()
	if lf != nil {
		lf.Close()
	}
	pdf, _ := os.ReadFile(pdfFile)
	ms := int(time.Since(t0).Milliseconds())
	if len(pdf) < 500 || !bytes.HasPrefix(pdf, []byte("%PDF")) {
		raw, _ := os.ReadFile(logFile)
		var lines []string
		for _, l := range strings.Split(strings.TrimSpace(string(raw)), "\n") {
			if l != "" {
				lines = append(lines, l)
			}
		}
		if len(lines) > 4 {
			lines = lines[len(lines)-4:]
		}
		ol := []any{}
		for _, l := range lines {
			ol = append(ol, l)
		}
		msg := "Chromium did not make a PDF"
		if timeout {
			msg += " (timed out)"
		}
		logError("pdf", msg, om("ms", ms, "binary", bin, "output", ol, "doc", key))
		tail := lines
		if len(tail) > 2 {
			tail = tail[len(tail)-2:]
		}
		return pdfResult{err: errors.New(c.T("The PDF could not be made.") + " " + php.Substr(strings.Join(tail, " "), 0, 200))}
	}
	logInfo("pdf", "PDF rendered", om("ms", ms, "bytes", len(pdf), "doc", key))
	return pdfResult{pdf: pdf}
}
