package accounting

import (
	"encoding/json"
	"html/template"
	"strings"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/php"
)

/*
Read-only lists of data copied from the ERP (ErpListController): same table screen as everywhere (search, sort, columns,
page size, export). Nothing here can be added, edited or deleted — the ERP is the source.
*/

type detailField struct{ key, label, typ string }

type erpList struct {
	res     *app.Resource
	detail  []detailField
	syncKey string
	// syncOne reads one record from the ERP again (nil = the entity by id)
	syncOne func(s *syncer, id int) int
}

func detailFields(c *app.Ctx, d []detailField) []app.Field {
	out := make([]app.Field, len(d))
	for i, f := range d {
		out[i] = app.Field{Name: f.key, Label: c.T(f.label), Type: f.typ}
	}
	return out
}

// newErpList fills in what ErpListController did for every list.
func newErpList(l *erpList) *erpList {
	r := l.res
	r.Conn, r.Module, r.NoSoftDelete, r.Readonly = C, "accounting", true, true
	if r.PerPage == 0 {
		r.PerPage = 10
	}
	if r.ScopeSQL == nil {
		r.ScopeSQL = func(c *app.Ctx) (string, []any) { return "TRUE", nil }
	}
	if r.CanModify == nil {
		r.CanModify = func(c *app.Ctx, row db.Row, action string) bool { return false }
	}
	if r.Fields == nil {
		d := l.detail
		r.Fields = func(c *app.Ctx, row db.Row) []app.Field { return detailFields(c, d) }
	}
	if r.RowLinks == nil && l.syncKey != "" {
		r.RowLinks = func(c *app.Ctx, row db.Row) []app.Link {
			if c.MustUser().IsAdmin() {
				return []app.Link{{URL: r.Base + "/" + row.Str("id") + "/sync", Label: c.T("Sync from ERP"), Icon: "bolt", Post: true}}
			}
			return nil
		}
	}
	return l
}

// export: the list as Excel (filters and search kept), with the detail columns.
func (l *erpList) export(c *app.Ctx) {
	c.Authorize(l.res.Resource, "export")
	rows, _ := l.res.Query(c, false)
	header := make([]string, len(l.detail))
	for i, d := range l.detail {
		header[i] = d.label
	}
	out := make([][]any, 0, len(rows))
	for _, r := range rows {
		line := make([]any, len(l.detail))
		for i, d := range l.detail {
			v := r[d.key]
			if v == nil {
				v = ""
			}
			line[i] = v
		}
		out = append(out, line)
	}
	c.XlsxDownload(l.res.Plural+"-"+php.Date("Ymd-His", php.Now())+".xlsx", header, out)
}

// syncRow reads this one record from the ERP again and updates it (even when the table's Overwrite is off).
func (l *erpList) syncRow(c *app.Ctx) {
	if !c.MustUser().IsAdmin() {
		app.Abort(403)
	}
	if l.syncKey == "" {
		app.Abort(404)
	}
	id := c.ParamInt("id")
	err := app.Catch(func() {
		s := newSyncer()
		var n int
		if l.syncOne != nil {
			n = l.syncOne(s, id)
		} else {
			n = s.run(l.syncKey, "one", php.Str(id), 0, nil, boolp(true)).saved
		}
		c.Flash("success", c.T("Read from the ERP: :n row(s) updated.", "n", n))
	})
	if err != nil {
		logException("sync", err, l.syncKey+" #"+php.Str(id)+" sync failed")
		c.Flash("error", err.Error())
	}
	c.Redirect(l.res.Base)
}

func (l *erpList) mount(r app.Router, index app.Handler) {
	b := l.res.Base
	if index == nil {
		index = l.res.Index
	}
	r.Get(b, index)
	r.Get(b+"/export", l.export)
	r.Get(b+"/{id}", l.res.Show)
	r.Get(b+"/{id}/download", l.res.Download)
}

func cell(s string) template.HTML { return template.HTML(s) }

// ---------------------------------------------------------------- customers

var customersList = newErpList(&erpList{syncKey: "customer", res: &app.Resource{
	Resource: "accounting_customers", Table: "erp_customers", Type: "customer", Singular: "customer", Plural: "customers",
	Base: "/accounting/customers", Icon: "users", OrderBy: "t.id DESC",
	Searchable: []string{"t.name", "t.code", "t.alternative_name", "t.vat_number", "t.corporation_identification_number"},
	SelectSQL: func(c *app.Ctx) string {
		return `SELECT t.*, concat_ws(' ', a.addressee, a.field1, a.field2, a.field3, a.locality, a.region, a.postal_code) AS address,
                       nullif(trim(concat_ws(' ', s.first_name, s.last_name)), '') AS seller_name,
                       (SELECT count(DISTINCT i.invoice_number) FROM erp_invoices i WHERE i.customer_id = t.id) AS invoice_count
                FROM erp_customers t LEFT JOIN erp_addresses a ON a.id = t.mailing_address_id LEFT JOIN erp_sellers s ON s.id = t.seller_id`
	},
	Label: func(c *app.Ctx, row db.Row) string { return php.Str(php.Coalesce(row["name"], "#"+row.Str("id"))) },
	Columns: func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "name", Label: c.T("Customer"), Primary: true, Sort: "t.name", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return cell(person(c, r.Str("name"), r.Str("code"), "/accounting/customers/"+r.Str("id")))
			}},
			{Key: "vat", Label: c.T("VAT number"), Sort: "t.vat_number", Render: func(c *app.Ctx, r db.Row) template.HTML { return cell(orDash(r["vat_number"])) }},
			{Key: "address", Label: c.T("Address"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				return cell(`<span class="block max-w-xs truncate text-steel" title="` + app.E(r["address"]) + `">` + orDash(r["address"]) + `</span>`)
			}},
			{Key: "seller", Label: c.T("Seller"), Sort: "seller_name", Render: func(c *app.Ctx, r db.Row) template.HTML { return cell(orDash(r["seller_name"])) }},
			{Key: "invoices", Label: c.T("Invoices"), Sort: "invoice_count", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return cell(`<span class="tabular-nums">` + php.Str(r.Int("invoice_count")) + `</span>`)
			}},
			{Key: "credit", Label: c.T("Credit limit"), Sort: "t.credit_limit", Render: func(c *app.Ctx, r db.Row) template.HTML { return cell(money(r["credit_limit"], "")) }},
		}
	},
}, detail: []detailField{{"id", "ID", "text"}, {"code", "Code", "text"}, {"name", "Name", "text"}, {"alternative_name", "Alternative name", "text"}, {"vat_number", "VAT number", "text"},
	{"corporation_identification_number", "Corporation ID", "text"}, {"address", "Address", "text"}, {"seller_name", "Seller", "text"}, {"credit_limit", "Credit limit", "number"},
	{"is_private_customer", "Private customer", "checkbox"}, {"invoice_count", "Invoices", "number"}}})

// ---------------------------------------------------------------- orders

// order kinds counted at the top: the letters an order number starts with (SO6910-001 → SO)
var orderKinds = []string{"SO", "SA", "TD"}

const orderPrefix = "upper(substring(t.order_number FROM '^[A-Za-z]+'))"
const orderInvoiced = `(EXISTS (SELECT 1 FROM erp_invoices i WHERE i.customer_order_id = t.id)
                               OR EXISTS (SELECT 1 FROM erp_invoices i WHERE i.customer_order_number = t.order_number AND t.order_number IS NOT NULL))`

var ordersList = newErpList(&erpList{syncKey: "order", res: &app.Resource{
	Resource: "accounting_orders", Table: "erp_orders", Type: "order", Singular: "order", Plural: "orders",
	Base: "/accounting/orders", Icon: "cart", OrderBy: "t.id DESC",
	Searchable: []string{"t.order_number", "t.business_contact_order_number", "t.vat_number", "c.name", "c.code"},
	SelectSQL: func(c *app.Ctx) string {
		return `SELECT t.*, ` + orderInvoiced + ` AS is_invoiced, ` + orderPrefix + ` AS kind,
                       (SELECT count(DISTINCT i.invoice_number) FROM erp_invoices i WHERE i.customer_order_id = t.id OR (i.customer_order_number = t.order_number AND t.order_number IS NOT NULL)) AS invoice_count,
                       c.name AS customer_name, c.code AS customer_code, nullif(trim(concat_ws(' ', s.first_name, s.last_name)), '') AS seller_name,
                       (SELECT count(*) FROM erp_order_rows r WHERE r.parent_order_id = t.id) AS row_count,
                       (SELECT sum(coalesce(r.ordered_quantity, 0) * coalesce(r.price, 0) * (1 - coalesce(r.discount, 0) / 100)) FROM erp_order_rows r WHERE r.parent_order_id = t.id) AS total
                FROM erp_orders t LEFT JOIN erp_customers c ON c.id = t.customer_id LEFT JOIN erp_sellers s ON s.id = t.seller_id`
	},
	Label: func(c *app.Ctx, row db.Row) string { return php.Str(php.Coalesce(row["order_number"], "#"+row.Str("id"))) },
	Filters: func(c *app.Ctx) []app.Filter {
		var kinds []app.Opt
		for _, r := range acc().Select("SELECT DISTINCT upper(substring(order_number FROM '^[A-Za-z]+')) AS k FROM erp_orders WHERE order_number ~ '^[A-Za-z]' ORDER BY 1") {
			kinds = append(kinds, app.Opt{Value: r.Str("k"), Label: r.Str("k")})
		}
		return []app.Filter{
			{Name: "credit", Label: c.T("Orders and credits"), Options: app.Opts("0", c.T("Orders"), "1", c.T("Credit orders")), Column: "t.is_credit", Bind: func(v string) any { return bstr(v == "1") }},
			{Name: "invoiced", Label: c.T("Invoiced"), Options: app.Opts("1", c.T("Yes"), "0", c.T("No")), SQL: orderInvoiced + " = (?::int = 1)"},
			{Name: "kind", Label: c.T("Order type"), Options: kinds, SQL: orderPrefix + " = ?"},
		}
	},
	// SO / SA / TD orders and orders without an invoice (all orders; a card filters the list).
	Stats: func(c *app.Ctx) []app.Stat {
		var parts []string
		for _, k := range orderKinds {
			parts = append(parts, "count(*) FILTER (WHERE "+orderPrefix+" = '"+k+"') AS "+strings.ToLower(k))
		}
		n := acc().First("SELECT " + strings.Join(parts, ", ") + ", count(*) FILTER (WHERE NOT " + orderInvoiced + ") AS no_invoice, count(*) AS total FROM erp_orders t")
		tone := map[string]string{"SO": "info", "SA": "violet", "TD": "success"}
		on := func(f, v string) bool { return c.Query(f) == v }
		var cards []app.Stat
		for _, k := range orderKinds {
			sub, href := c.T("Click to show"), "/accounting/orders?kind="+k
			if on("kind", k) {
				sub, href = c.T("Showing these"), "/accounting/orders"
			}
			cards = append(cards, app.Stat{Label: c.T(":kind orders", "kind", k), Value: php.NF(n.Int(strings.ToLower(k)), 0), Sub: sub, Tone: tone[k], Icon: "cart", Href: href})
		}
		t := "neutral"
		if n.Int("no_invoice") > 0 {
			t = "warn"
		}
		href := "/accounting/orders?invoiced=0"
		if on("invoiced", "0") {
			href = "/accounting/orders"
		}
		cards = append(cards, app.Stat{Label: c.T("No invoice"), Value: php.NF(n.Int("no_invoice"), 0), Sub: c.T("of :n orders", "n", php.NF(n.Int("total"), 0)), Tone: t, Icon: "doc", Href: href})
		return cards
	},
	Columns: func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "order", Label: c.T("Order"), Primary: true, Sort: "t.order_number", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return cell(person(c, r.Str("order_number"), r.Str("business_contact_order_number"), "/accounting/orders/"+r.Str("id")))
			}},
			{Key: "invoiced", Label: c.T("Invoiced"), Sort: "is_invoiced", Render: func(c *app.Ctx, r db.Row) template.HTML {
				if r.Bool("is_invoiced") {
					s := badge(c.T("Yes"), "success")
					if r.Int("invoice_count") > 1 {
						s += ` <span class="text-xs text-steel">×` + php.Str(r.Int("invoice_count")) + `</span>`
					}
					return cell(s)
				}
				return cell(badge(c.T("No"), "warn"))
			}},
			{Key: "date", Label: c.T("Date"), Sort: "t.order_date", Render: func(c *app.Ctx, r db.Row) template.HTML { return cell(dateCell(c, r["order_date"])) }},
			{Key: "customer", Label: c.T("Customer"), Sort: "c.name", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return cell(`<span class="block max-w-[14rem] truncate">` + orDash(r["customer_name"]) + `</span><span class="block text-xs text-steel">` + app.E(r["customer_code"]) + `</span>`)
			}},
			{Key: "vat", Label: c.T("VAT number"), Render: func(c *app.Ctx, r db.Row) template.HTML { return cell(orDash(r["vat_number"])) }},
			{Key: "seller", Label: c.T("Seller"), Render: func(c *app.Ctx, r db.Row) template.HTML { return cell(orDash(r["seller_name"])) }},
			{Key: "rows", Label: c.T("Rows"), Sort: "row_count", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return cell(`<span class="tabular-nums">` + php.Str(r.Int("row_count")) + `</span>`)
			}},
			{Key: "total", Label: c.T("Total"), Sort: "total", Render: func(c *app.Ctx, r db.Row) template.HTML { return cell(money(r["total"], "")) }},
			{Key: "type", Label: c.T("Type"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				if r.Bool("is_credit") {
					return cell(badge(c.T("Credit"), "warn"))
				}
				return cell(badge(c.T("Order"), "neutral"))
			}},
		}
	},
}, detail: []detailField{{"id", "ID", "text"}, {"order_number", "Order number", "text"}, {"business_contact_order_number", "Customer PO", "text"}, {"order_date", "Order date", "date"},
	{"customer_code", "Customer code", "text"}, {"customer_name", "Customer", "text"}, {"vat_number", "VAT number", "text"}, {"seller_name", "Seller", "text"},
	{"is_credit", "Credit order", "checkbox"}, {"is_invoiced", "Invoiced", "checkbox"}, {"invoice_count", "Invoices", "number"}, {"status", "Status", "number"}, {"row_count", "Rows", "number"}, {"total", "Total (before VAT)", "number"}},
	syncOne: func(s *syncer, id int) int {
		n := s.run("order", "one", php.Str(id), 0, nil, boolp(true)).saved
		return n + s.run("order_row", "all", "", 0, strp("ParentOrderId eq '"+php.Str(id)+"'"), boolp(true)).saved
	}})

// ---------------------------------------------------------------- invoices (one row per invoice number, added up from the ERP invoice log)

var invoicesList = newErpList(&erpList{syncKey: "invoice", res: &app.Resource{
	Resource: "accounting_invoices", Table: "erp_invoices", Type: "invoice", Singular: "invoice", Plural: "invoices",
	Base: "/accounting/invoices", Icon: "doc", OrderBy: "t.id DESC",
	Searchable: []string{"t.invoice_number", "t.customer_code", "t.customer_name", "t.customer_order_number", "t.seller", "t.seller_name", "t.department_code", "t.department_name", "t.app_department_name"},
	// id = invoice number
	SelectSQL: func(c *app.Ctx) string {
		return `SELECT t.* FROM (
                  SELECT i.invoice_number AS id, i.invoice_number, max(i.invoice_date) AS invoice_date, max(i.customer_id) AS customer_id, max(i.customer_code) AS customer_code,
                         max(c.name) AS customer_name, max(i.customer_order_number) AS customer_order_number, max(i.currency_code) AS currency_code, max(i.exchange_rate) AS exchange_rate,
                         count(*) AS line_count, max(i.seller) AS seller, max(i.seller_id) AS seller_id, max(i.seller_name) AS seller_name,
                         max(i.app_department_id) AS app_department_id, max(i.app_department_name) AS app_department_name,
                         max(i.department_code) AS department_code, max(i.department_name) AS department_name,
                         sum(coalesce(i.invoiced_quantity, 0) * coalesce(i.price, 0) * (1 - coalesce(i.discount, 0) / 100)) AS amount,
                         bool_or(coalesce(o.is_credit, false) OR i.invoice_number::text LIKE '4%') AS is_credit
                  FROM erp_invoices i LEFT JOIN erp_customers c ON c.id = i.customer_id LEFT JOIN erp_orders o ON o.id = i.customer_order_id
                  WHERE i.invoice_number IS NOT NULL GROUP BY i.invoice_number) t`
	},
	Label: func(c *app.Ctx, row db.Row) string { return row.Str("invoice_number") },
	Filters: func(c *app.Ctx) []app.Filter {
		var deps []app.Opt
		for _, r := range acc().Select("SELECT DISTINCT app_department_id::text AS id, app_department_name AS name FROM erp_invoices WHERE app_department_id IS NOT NULL ORDER BY 2") {
			deps = append(deps, app.Opt{Value: r.Str("id"), Label: r.Str("name")})
		}
		var codes []app.Opt
		for _, d := range acc().Select("SELECT department_code AS code, max(department_name) AS name FROM erp_invoices WHERE coalesce(department_code, '') <> '' GROUP BY 1 ORDER BY 1") {
			l := d.Str("code")
			if d.Str("name") != "" {
				l += " · " + d.Str("name")
			}
			codes = append(codes, app.Opt{Value: d.Str("code"), Label: l})
		}
		return []app.Filter{
			{Name: "credit", Label: c.T("Invoices and credit notes"), Options: app.Opts("0", c.T("Invoices"), "1", c.T("Credit notes")), Column: "t.is_credit", Bind: func(v string) any { return bstr(v == "1") }},
			{Name: "erp_dept", Label: c.T("ERP department"), Options: append(codes, app.Opt{Value: "none", Label: c.T("No department")}),
				SQL: "t.department_code IS NOT DISTINCT FROM nullif(?, 'none')"}, // 'none' → invoices without one
			{Name: "department", Label: c.T("Department (this app)"), Options: append(deps, app.Opt{Value: "none", Label: c.T("No department")}),
				SQL: "t.app_department_id::text IS NOT DISTINCT FROM nullif(?, 'none')"},
		}
	},
	Columns: func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "invoice", Label: c.T("Invoice"), Primary: true, Sort: "t.invoice_number", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return cell(person(c, r.Str("invoice_number"), r.Str("customer_order_number"), "/accounting/invoices/"+r.Str("id")))
			}},
			{Key: "date", Label: c.T("Date"), Sort: "t.invoice_date", Render: func(c *app.Ctx, r db.Row) template.HTML { return cell(dateCell(c, r["invoice_date"])) }},
			{Key: "customer", Label: c.T("Customer"), Sort: "t.customer_name", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return cell(`<span class="block max-w-[14rem] truncate">` + orDash(r["customer_name"]) + `</span><span class="block text-xs text-steel">` + app.E(r["customer_code"]) + `</span>`)
			}},
			{Key: "seller", Label: c.T("Seller"), Sort: "t.seller_name", Render: func(c *app.Ctx, r db.Row) template.HTML {
				if php.Truthy(r["seller_name"]) {
					return cell(`<a href="` + c.URL("/accounting/sellers/"+php.Str(r.Int("seller_id"))) + `" class="hover:text-signal-700">` + app.E(r["seller_name"]) + `</a><span class="block text-xs text-steel">` + app.E(r["seller"]) + `</span>`)
				}
				return cell(orDash(r["seller"]))
			}},
			{Key: "department", Label: c.T("Department"), Sort: "t.department_code", Render: func(c *app.Ctx, r db.Row) template.HTML {
				if php.Truthy(r["department_code"]) {
					s := badge(r.Str("department_code"), "neutral")
					if php.Truthy(r["department_name"]) {
						s += ` <span class="text-xs text-steel">` + app.E(r["department_name"]) + `</span>`
					}
					return cell(s)
				}
				return cell(dash())
			}},
			{Key: "app_department", Label: c.T("Department (this app)"), Sort: "t.app_department_name", Render: func(c *app.Ctx, r db.Row) template.HTML {
				if php.Truthy(r["app_department_name"]) {
					return cell(app.E(r["app_department_name"]))
				}
				return cell(dash())
			}},
			{Key: "lines", Label: c.T("Lines"), Sort: "t.line_count", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return cell(`<span class="tabular-nums">` + php.Str(r.Int("line_count")) + `</span>`)
			}},
			{Key: "amount", Label: c.T("Amount"), Sort: "t.amount", Render: func(c *app.Ctx, r db.Row) template.HTML { return cell(money(r["amount"], r.Str("currency_code"))) }},
			{Key: "type", Label: c.T("Type"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				if r.Bool("is_credit") {
					return cell(badge(c.T("Credit note"), "warn"))
				}
				return cell(badge(c.T("Invoice"), "neutral"))
			}},
		}
	},
}, detail: []detailField{{"invoice_number", "Invoice number", "text"}, {"invoice_date", "Invoice date", "date"}, {"customer_code", "Customer code", "text"}, {"customer_name", "Customer", "text"},
	{"customer_order_number", "Order number", "text"}, {"seller_name", "Seller", "text"}, {"seller", "Employee number", "text"}, {"department_code", "ERP department code", "text"},
	{"department_name", "ERP department", "text"}, {"app_department_name", "Department (this app)", "text"}, {"currency_code", "Currency", "text"}, {"exchange_rate", "Exchange rate", "number"},
	{"line_count", "Lines", "number"}, {"amount", "Amount (before VAT)", "number"}, {"is_credit", "Credit note", "checkbox"}},
	// the id of this list is the invoice number
	syncOne: func(s *syncer, id int) int {
		return s.run("invoice", "all", "", 0, strp("InvoiceNumber eq "+php.Str(id)), boolp(true)).saved
	}})

// invoicesIndex: invoices (and sellers) synced before seller and department were stamped on them get them the first time the list is opened.
func invoicesIndex(c *app.Ctx) {
	if acc().Scalar("SELECT 1 FROM erp_sellers s JOIN erp_departments d ON d.id = s.department_id WHERE s.department_code IS DISTINCT FROM nullif(trim(d.code), '') LIMIT 1") != nil ||
		acc().Scalar(`SELECT 1 FROM erp_invoices i JOIN erp_sellers s ON upper(trim(i.seller)) = upper(trim(s.employee_number)) AND trim(coalesce(s.employee_number, '')) <> ''
                           WHERE i.seller_id IS NULL OR i.department_code IS DISTINCT FROM s.department_code LIMIT 1`) != nil {
		sellerStampQuietly()
	}
	invoicesList.res.Index(c)
}

// ---------------------------------------------------------------- products

var productsList = newErpList(&erpList{syncKey: "product", res: &app.Resource{
	Resource: "accounting_products", Table: "erp_products", Type: "product", Singular: "product", Plural: "products",
	Base: "/accounting/products", Icon: "box", OrderBy: "t.id DESC",
	Searchable: []string{"t.part_number", "t.description", "t.alias"},
	Label:      func(c *app.Ctx, row db.Row) string { return php.Str(php.Coalesce(row["part_number"], "#"+row.Str("id"))) },
	Columns: func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "part", Label: c.T("Part"), Primary: true, Sort: "t.part_number", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return cell(person(c, r.Str("part_number"), r.Str("alias"), "/accounting/products/"+r.Str("id")))
			}},
			{Key: "desc", Label: c.T("Description"), Sort: "t.description", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return cell(`<span class="block max-w-md truncate" title="` + app.E(r["description"]) + `">` + orDash(r["description"]) + `</span>`)
			}},
			{Key: "price", Label: c.T("Standard price"), Sort: "t.standard_price", Render: func(c *app.Ctx, r db.Row) template.HTML { return cell(money(r["standard_price"], "")) }},
			{Key: "code", Label: c.T("Part code"), Render: func(c *app.Ctx, r db.Row) template.HTML { return cell(orDash(r["part_code_id"])) }},
		}
	},
}, detail: []detailField{{"id", "ID", "text"}, {"part_number", "Part number", "text"}, {"description", "Description", "text"}, {"alias", "Alias", "text"},
	{"standard_price", "Standard price", "number"}, {"part_code_id", "Part code", "text"}, {"part_template_id", "Part template", "text"}}})

// ---------------------------------------------------------------- sellers

// sellerDepartments: departments of this app (core database), id → name, in name order.
func sellerDepartments() []app.Opt {
	var out []app.Opt
	for _, r := range db.Select("SELECT id::text AS id, name FROM departments WHERE deleted_at IS NULL ORDER BY name") {
		out = append(out, app.Opt{Value: r.Str("id"), Label: r.Str("name")})
	}
	return out
}

/*
Sellers (persons) copied from the ERP. The ERP columns are read-only here; e-mail, user and Lark id are filled in by hand
and no sync ever writes to them (even with Overwrite on).
*/
var sellersList = newErpList(&erpList{syncKey: "seller", res: &app.Resource{
	Resource: "accounting_sellers", Table: "erp_sellers", Type: "seller", Singular: "seller", Plural: "sellers",
	Base: "/accounting/sellers", Icon: "users", OrderBy: "t.id DESC",
	Searchable: []string{"trim(coalesce(t.first_name, '') || ' ' || coalesce(t.last_name, ''))", "t.employee_number", "t.email", "t.email_address", "t.lark_id", "t.phone_number", "t.department_code", "t.department_name"},
	SelectSQL: func(c *app.Ctx) string {
		return `SELECT t.*, trim(coalesce(t.first_name, '') || ' ' || coalesce(t.last_name, '')) AS name, coalesce(t.email, t.email_address) AS mail_shown,
                       coalesce(t.department_name, d.name) AS department, coalesce(t.department_code, d.code) AS dept_code
                FROM erp_sellers t LEFT JOIN erp_departments d ON d.id = t.department_id`
	},
	// the system account lives in the user database, so its name is looked up here
	Hydrate: func(c *app.Ctx, rows []db.Row) []db.Row {
		var ids []any
		seen := map[int]bool{}
		for _, r := range rows {
			if id := r.Int("user_id"); id != 0 && !seen[id] {
				seen[id] = true
				ids = append(ids, id)
			}
		}
		names := map[int]string{}
		if len(ids) > 0 {
			for _, u := range db.Select("SELECT id, name FROM users WHERE id IN ("+strings.TrimSuffix(strings.Repeat("?,", len(ids)), ",")+")", ids...) {
				names[u.Int("id")] = u.Str("name")
			}
		}
		deps := sellerDepartments()
		for _, r := range rows {
			if n, ok := names[r.Int("user_id")]; ok {
				r["user_name"] = n
			} else {
				r["user_name"] = nil
			}
			if l, ok := app.OptLabel(deps, r.Str("app_department_id")); ok {
				r["app_department"] = l
			} else {
				r["app_department"] = nil
			}
		}
		return rows
	},
	// the e-mail, the person's account and the Lark id are the only things edited here
	CanModify: func(c *app.Ctx, row db.Row, action string) bool { return action == "edit" },
	Label:     func(c *app.Ctx, row db.Row) string { return php.Str(php.Or(row["name"], "#"+row.Str("id"))) },
	// A seller got a department: its Inets that have none yet take it (newer ones get it when generated);
	// the department's name is kept on the seller and the seller's invoices take both.
	Saved: func(c *app.Ctx, id int, data *app.Clean, existing db.Row) {
		var name any
		if php.Truthy(data.Get("app_department_id")) {
			if l, ok := app.OptLabel(sellerDepartments(), data.Str("app_department_id")); ok {
				name = l
			}
		}
		acc().Exec("UPDATE erp_sellers SET app_department_name = ? WHERE id = ?", name, id)
		sellerStampQuietly()
		if php.Truthy(data.Get("app_department_id")) {
			acc().Exec("UPDATE inets i SET app_department_id = ? FROM erp_orders o WHERE o.id = i.order_id AND o.seller_id = ? AND i.app_department_id IS NULL", data.Int("app_department_id"), id)
		}
	},
	Fields: func(c *app.Ctx, row db.Row) []app.Field {
		users := []app.Opt{{Value: "", Label: "—"}}
		for _, u := range db.Select("SELECT id, name FROM users WHERE deleted_at IS NULL ORDER BY name") {
			users = append(users, app.Opt{Value: u.Str("id"), Label: u.Str("name")})
		}
		return []app.Field{
			{Section: c.T("From the ERP (read only)")},
			{Name: "name", Label: c.T("Name"), Type: "text", Readonly: true, NoTable: true},
			{Name: "employee_number", Label: c.T("Employee number"), Type: "text", Readonly: true},
			{Name: "phone_number", Label: c.T("Phone"), Type: "text", Readonly: true},
			{Name: "email_address", Label: c.T("E-mail in the ERP"), Type: "text", Readonly: true},
			{Name: "dept_code", Label: c.T("Department code"), Type: "text", Readonly: true, NoTable: true},
			{Name: "department", Label: c.T("Department"), Type: "text", Readonly: true, NoTable: true},
			{Section: c.T("Filled in here")},
			{Name: "email", Label: c.T("E-mail"), Type: "email", Rules: "nullable|email|max:190", Help: c.T("Used to reach the seller. Never overwritten by a sync.")},
			{Name: "user_id", Label: c.T("User (system account)"), Type: "select", Options: users, Rules: "nullable"},
			{Name: "app_department_id", Label: c.T("Department (this app)"), Type: "select", Options: append([]app.Opt{{Value: "", Label: "—"}}, sellerDepartments()...), Rules: "nullable",
				Help: c.T("Inets of this seller get this department when they are generated; people of that department see the stamped ones under ERP → Invoices. Invoices of this seller (by employee number) show it too. Never overwritten by a sync.")},
			{Name: "lark_id", Label: c.T("Lark ID"), Type: "text", Rules: "nullable|max:120"},
		}
	},
	Columns: func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "name", Label: c.T("Seller"), Primary: true, Sort: "name", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return cell(person(c, r.Str("name"), r.Str("employee_number"), "/accounting/sellers/"+r.Str("id")))
			}},
			{Key: "dept", Label: c.T("Department"), Sort: "dept_code", Render: func(c *app.Ctx, r db.Row) template.HTML {
				if php.Truthy(r["dept_code"]) {
					s := badge(r.Str("dept_code"), "neutral")
					if php.Truthy(r["department"]) {
						s += ` <span class="text-xs text-steel">` + app.E(r["department"]) + `</span>`
					}
					return cell(s)
				}
				return cell(orDash(r["department"]))
			}},
			{Key: "app_dept", Label: c.T("Department (this app)"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				if php.Truthy(r["app_department"]) {
					return cell(app.E(r["app_department"]))
				}
				return cell(dash())
			}},
			{Key: "email", Label: c.T("E-mail"), Sort: "mail_shown", Render: func(c *app.Ctx, r db.Row) template.HTML { return cell(orDash(r["mail_shown"])) }},
			{Key: "user", Label: c.T("User"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				if php.Truthy(r["user_name"]) {
					return cell(app.E(r["user_name"]))
				}
				return cell(dash())
			}},
			{Key: "lark", Label: c.T("Lark ID"), Render: func(c *app.Ctx, r db.Row) template.HTML { return cell(orDash(r["lark_id"])) }},
			{Key: "phone", Label: c.T("Phone"), Render: func(c *app.Ctx, r db.Row) template.HTML { return cell(orDash(r["phone_number"])) }},
		}
	},
}, detail: []detailField{{"name", "Name", "text"}, {"employee_number", "Employee number", "text"}, {"phone_number", "Phone", "text"}, {"email_address", "E-mail in the ERP", "text"},
	{"dept_code", "Department code", "text"}, {"department", "Department", "text"}, {"email", "E-mail", "text"}, {"user_name", "User", "text"}, {"app_department", "Department (this app)", "text"}, {"lark_id", "Lark ID", "text"}}})

// sellersIndex: sellers synced before their ERP department code was copied onto them get it the first time the list is opened.
func sellersIndex(c *app.Ctx) {
	if acc().Scalar("SELECT 1 FROM erp_sellers s JOIN erp_departments d ON d.id = s.department_id WHERE s.department_code IS DISTINCT FROM nullif(trim(d.code), '') LIMIT 1") != nil {
		sellerStampQuietly()
	}
	sellersList.res.Index(c)
}

// ---------------------------------------------------------------- departments

/*
Departments copied from the ERP, with their sellers and this year's invoiced amount (invoice lines whose seller, by employee
number, belongs to the department). The app department linked to it is set in User Management → Departments.
*/
var departmentsList = newErpList(&erpList{syncKey: "department", res: &app.Resource{
	Resource: "accounting_departments", Table: "erp_departments", Type: "erp_department", Singular: "department", Plural: "departments",
	Base: "/accounting/departments", Icon: "building", OrderBy: "t.code, t.id",
	Searchable: []string{"t.code", "t.name", "t.description"},
	SelectSQL: func(c *app.Ctx) string {
		return `SELECT t.*, p.code AS parent_code, p.name AS parent_name,
                       (SELECT count(*) FROM erp_sellers s WHERE s.department_id = t.id) AS sellers,
                       (SELECT count(DISTINCT i.invoice_number) FROM erp_invoices i JOIN erp_sellers s ON s.id = i.seller_id
                         WHERE s.department_id = t.id AND i.invoice_date >= date_trunc('year', now())) AS invoices,
                       (SELECT coalesce(sum(coalesce(i.invoiced_quantity, 0) * coalesce(i.price, 0) * (1 - coalesce(i.discount, 0) / 100) * coalesce(nullif(i.exchange_rate, 0), 1)), 0)
                          FROM erp_invoices i JOIN erp_sellers s ON s.id = i.seller_id
                         WHERE s.department_id = t.id AND i.invoice_date >= date_trunc('year', now())) AS amount
                  FROM erp_departments t LEFT JOIN erp_departments p ON p.id = t.department_id`
	},
	// The app department linked to each ERP department lives in the core database.
	Hydrate: func(c *app.Ctx, rows []db.Row) []db.Row {
		var ids []string
		for _, r := range rows {
			if r.Int("id") != 0 {
				ids = append(ids, php.Str(r.Int("id")))
			}
		}
		appd := map[int]string{}
		if len(ids) > 0 {
			for _, d := range db.Select("SELECT erp_department_id, name FROM departments WHERE deleted_at IS NULL AND erp_department_id IN (" + strings.Join(ids, ",") + ")") {
				appd[d.Int("erp_department_id")] = d.Str("name")
			}
		}
		for _, r := range rows {
			if n, ok := appd[r.Int("id")]; ok {
				r["app_department"] = n
			} else {
				r["app_department"] = nil
			}
		}
		return rows
	},
	Label: func(c *app.Ctx, row db.Row) string {
		return strings.TrimSpace(row.Str("code") + " " + php.Str(php.Or(row["name"], php.Or(row["description"], "#"+row.Str("id")))))
	},
	Columns: func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "dept", Label: c.T("Department"), Primary: true, Sort: "t.code", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return cell(person(c, php.Str(php.Or(r["name"], php.Or(r["description"], "#"+r.Str("id")))), r.Str("code"), "/accounting/departments/"+r.Str("id")))
			}},
			{Key: "desc", Label: c.T("Description"), Sort: "t.description", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return cell(`<span class="block max-w-xs truncate">` + orDash(r["description"]) + `</span>`)
			}},
			{Key: "parent", Label: c.T("Parent department"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				return cell(orDash(strings.TrimSpace(r.Str("parent_code") + " " + r.Str("parent_name"))))
			}},
			{Key: "app", Label: c.T("Department (this app)"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				if php.Truthy(r["app_department"]) {
					return cell(app.E(r["app_department"]))
				}
				return cell(dash())
			}},
			{Key: "sellers", Label: c.T("Sellers"), Sort: "sellers", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return cell(`<span class="tabular-nums">` + php.Str(r.Int("sellers")) + `</span>`)
			}},
			{Key: "invoices", Label: c.T("Invoices this year"), Sort: "invoices", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return cell(`<span class="tabular-nums">` + php.Str(r.Int("invoices")) + `</span>`)
			}},
			{Key: "amount", Label: c.T("Invoiced this year"), Sort: "amount", Render: func(c *app.Ctx, r db.Row) template.HTML { return cell(money(r["amount"], "")) }},
		}
	},
}, detail: []detailField{{"id", "ID", "text"}, {"code", "Code", "text"}, {"name", "Name", "text"}, {"description", "Description", "text"}, {"parent_name", "Parent department", "text"},
	{"app_department", "Department (this app)", "text"}, {"sellers", "Sellers", "number"}, {"invoices", "Invoices this year", "number"}, {"amount", "Invoiced this year (THB, before VAT)", "number"}}})

// ---------------------------------------------------------------- vouchers

/*
Vouchers copied from the ERP. The voucher sync expands Comment, Rows and AccountsPayable, so a voucher's rows, its
accounts-payable invoice and its comment are all here; the detail page shows them together.
*/
var vouchersList = newErpList(&erpList{syncKey: "voucher", res: &app.Resource{
	Resource: "accounting_vouchers", Table: "erp_vouchers", Type: "voucher", Singular: "voucher", Plural: "vouchers",
	Base: "/accounting/vouchers", Icon: "doc", OrderBy: "t.voucher_date DESC NULLS LAST, t.id DESC",
	Searchable: []string{"t.number", "t.text", "p.invoice_number", "p.suppliers_invoice_number", "c.raw_text"},
	SelectSQL: func(c *app.Ctx) string {
		return `SELECT t.*, c.raw_text AS comment, p.invoice_number AS payable_invoice, p.suppliers_invoice_number AS payable_supplier_invoice,
                       p.invoice_amount AS payable_amount, p.due_date AS payable_due,
                       (SELECT count(*) FROM erp_voucher_rows r WHERE r.voucher_id = t.id) AS row_count,
                       (SELECT coalesce(sum(r.debit_in_company_currency), 0) FROM erp_voucher_rows r WHERE r.voucher_id = t.id) AS debit_total,
                       (SELECT coalesce(sum(r.credit_in_company_currency), 0) FROM erp_voucher_rows r WHERE r.voucher_id = t.id) AS credit_total
                  FROM erp_vouchers t LEFT JOIN erp_comments c ON c.id = t.comment_id LEFT JOIN erp_payables p ON p.id = t.accounts_payable_id`
	},
	Label: func(c *app.Ctx, row db.Row) string { return php.Str(php.Or(row["number"], "#"+row.Str("id"))) },
	Filters: func(c *app.Ctx) []app.Filter {
		var years []app.Opt
		for _, r := range acc().Select("SELECT DISTINCT extract(year FROM voucher_date)::int AS y FROM erp_vouchers WHERE voucher_date IS NOT NULL ORDER BY 1 DESC") {
			years = append(years, app.Opt{Value: r.Str("y"), Label: r.Str("y")})
		}
		return []app.Filter{
			{Name: "year", Label: c.T("Year"), Options: years, SQL: "extract(year FROM t.voucher_date)::int = ?::int"},
			{Name: "payable", Label: c.T("Accounts payable"), Options: app.Opts("1", c.T("With a payable invoice"), "0", c.T("Without")), SQL: "(t.accounts_payable_id IS NOT NULL) = (?::int = 1)"},
			{Name: "prelim", Label: c.T("Preliminary"), Options: app.Opts("1", c.T("Preliminary"), "0", c.T("Final")), SQL: "coalesce(t.is_preliminary, false) = (?::int = 1)"},
		}
	},
	Columns: func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "number", Label: c.T("Voucher"), Primary: true, Sort: "t.number", Render: func(c *app.Ctx, r db.Row) template.HTML {
				sub := ""
				if php.Truthy(r["voucher_date"]) {
					sub = app.FormatDate(c, r["voucher_date"], "d M Y")
				}
				return cell(person(c, php.Str(php.Or(r["number"], "#"+r.Str("id"))), sub, "/accounting/vouchers/"+r.Str("id")))
			}},
			{Key: "date", Label: c.T("Date"), Sort: "t.voucher_date", Render: func(c *app.Ctx, r db.Row) template.HTML { return cell(dateCell(c, r["voucher_date"])) }},
			{Key: "text", Label: c.T("Text"), Sort: "t.text", Render: func(c *app.Ctx, r db.Row) template.HTML {
				s := `<span class="block max-w-sm truncate" title="` + app.E(r["text"]) + `">` + orDash(r["text"]) + `</span>`
				if php.Truthy(r["comment"]) {
					s += `<span class="block max-w-sm truncate text-xs text-steel">` + app.E(r["comment"]) + `</span>`
				}
				return cell(s)
			}},
			{Key: "payable", Label: c.T("Accounts payable"), Sort: "p.invoice_number", Render: func(c *app.Ctx, r db.Row) template.HTML {
				if !php.Truthy(r["accounts_payable_id"]) {
					return cell(dash())
				}
				s := app.E(php.Or(r["payable_supplier_invoice"], php.Or(r["payable_invoice"], "#"+r.Str("accounts_payable_id"))))
				if r["payable_amount"] != nil {
					s += `<span class="block text-xs text-steel tabular-nums">` + app.E(php.NF(r["payable_amount"], 2)) + `</span>`
				}
				return cell(s)
			}},
			{Key: "rows", Label: c.T("Rows"), Sort: "row_count", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return cell(`<span class="tabular-nums">` + php.Str(r.Int("row_count")) + `</span>`)
			}},
			{Key: "debit", Label: c.T("Debit"), Sort: "debit_total", Render: func(c *app.Ctx, r db.Row) template.HTML { return cell(money(r["debit_total"], "")) }},
			{Key: "credit", Label: c.T("Credit"), Sort: "credit_total", Render: func(c *app.Ctx, r db.Row) template.HTML { return cell(money(r["credit_total"], "")) }},
			{Key: "state", Label: c.T("Status"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				if r.Bool("is_preliminary") {
					return cell(badge(c.T("Preliminary"), "warn"))
				}
				return cell(badge(c.T("Final"), "neutral"))
			}},
		}
	},
	// Rows, the accounts-payable invoice and the comment of the voucher.
	ShowExtra: voucherDetail,
}, detail: []detailField{{"number", "Voucher number", "text"}, {"voucher_date", "Voucher date", "date"}, {"text", "Text", "text"}, {"series_id", "Series", "text"},
	{"accounting_year_id", "Accounting year", "text"}, {"is_preliminary", "Preliminary", "checkbox"}, {"bundle_number", "Bundle number", "text"},
	{"connected_voucher_id", "Connected voucher", "text"}, {"connection_type", "Connection type", "text"},
	{"reverse_connection_voucher_id", "Reverse connection voucher", "text"}, {"reverse_connection_type", "Reverse connection type", "text"},
	{"accounts_payable_id", "Accounts payable", "text"}, {"accounts_receivable_id", "Accounts receivable", "text"}, {"invoice_log_ledger_id", "Invoice log ledger", "text"},
	{"row_count", "Rows", "number"}, {"debit_total", "Debit (THB)", "number"}, {"credit_total", "Credit (THB)", "number"}}})

func rawJSON(s string) map[string]any {
	out := map[string]any{}
	if s != "" {
		_ = json.Unmarshal([]byte(s), &out)
	}
	return out
}

func pickKey(r map[string]any, keys ...string) string {
	for _, k := range keys {
		if v, ok := r[k]; ok && v != nil && php.Str(v) != "" {
			return jsonStr(v)
		}
	}
	return ""
}

func jsonStr(v any) string {
	switch x := v.(type) {
	case float64:
		return php.Str(x)
	case bool:
		if x {
			return "1"
		}
		return ""
	}
	return php.Str(v)
}

func voucherDetail(c *app.Ctx, row db.Row) template.HTML {
	rows := acc().Select("SELECT * FROM erp_voucher_rows WHERE voucher_id = ? ORDER BY id", row.Int("id"))
	var payable db.Row
	if php.Truthy(row["accounts_payable_id"]) {
		payable = acc().First("SELECT * FROM erp_payables WHERE id = ?", row.Int("accounts_payable_id"))
	}
	num := func(v any) template.HTML {
		if v == nil || php.Str(v) == "" {
			return template.HTML(`<span class="text-graphite-400">—</span>`)
		}
		return template.HTML(app.E(php.NF(v, 2)))
	}
	debit, credit := 0.0, 0.0
	var list []app.M
	for i, r := range rows {
		x := rawJSON(r.Str("raw"))
		debit += r.Float("debit_in_company_currency")
		credit += r.Float("credit_in_company_currency")
		qty := ""
		if r["quantity"] != nil && r.Float("quantity") != 0 {
			qty = php.NumberClean(r["quantity"])
		}
		list = append(list, app.M{"no": php.Or(r["entry_identifier"], php.Str(i+1)), "account": php.OrStr(pickKey(x, "AccountNumber", "Account", "AccountCode", "AccountId"), "—"),
			"text": pickKey(x, "Text", "Description", "RowText"), "debit": num(r["debit"]), "credit": num(r["credit"]), "debit_c": num(r["debit_in_company_currency"]),
			"credit_c": num(r["credit_in_company_currency"]), "qty": qty})
	}
	balanced := ""
	if len(rows) > 0 {
		d := debit - credit
		if d < 0 {
			d = -d
		}
		if d < 0.005 {
			balanced = badge(c.T("Balanced"), "success")
		} else {
			balanced = badge(c.T("Debit and credit differ"), "warn")
		}
	}
	var lines [][2]string
	payableMsg := ""
	creditBadge := ""
	if payable == nil {
		if php.Truthy(row["accounts_payable_id"]) {
			payableMsg = c.T("Payable #:id is not saved yet. Sync the voucher again.", "id", row["accounts_payable_id"])
		} else {
			payableMsg = c.T("This voucher has no accounts payable invoice.")
		}
	} else {
		p := rawJSON(payable.Str("raw"))
		fd := func(k string) string {
			if php.Truthy(payable[k]) {
				return app.FormatDate(c, payable[k], "d M Y")
			}
			return ""
		}
		fn := func(k string) string {
			if payable[k] != nil {
				return php.NF(payable[k], 2)
			}
			return ""
		}
		for _, l := range [][2]string{
			{c.T("Supplier invoice"), payable.Str("suppliers_invoice_number")}, {c.T("Invoice number"), payable.Str("invoice_number")},
			{c.T("Supplier"), pickKey(p, "SupplierName", "SupplierCode", "SupplierId")}, {c.T("Invoice date"), fd("invoice_date")}, {c.T("Due date"), fd("due_date")},
			{c.T("Amount"), fn("invoice_amount")}, {c.T("Amount (THB)"), fn("invoice_amount_in_company_currency")}, {c.T("VAT"), fn("vat_amount")},
			{c.T("Still to pay"), fn("rest_amount")}, {c.T("Paid in full"), fd("paid_in_full_date")}, {c.T("Reference"), payable.Str("reference_number")},
		} {
			if l[1] != "" {
				lines = append(lines, l)
			}
		}
		if payable.Bool("is_credit") {
			creditBadge = badge(c.T("Credit note"), "warn")
		}
	}
	// all fields from the ERP, in the order they came
	var all [][2]string
	if v, err := decodeOrdered([]byte(row.Str("raw"))); err == nil {
		if m, ok := v.(*config.Map); ok {
			for _, k := range m.Keys {
				val := m.Vals[k]
				s := "—"
				if val != nil {
					switch x := val.(type) {
					case bool:
						s = bstr(x)
					default:
						s = valStr(val)
					}
				}
				all = append(all, [2]string{k, s})
			}
		}
	}
	return c.Partial("accounting/voucher-detail", app.M{"voucher": row, "rows": list, "count": len(rows), "balanced": template.HTML(balanced),
		"debit": num(debit), "credit": num(credit), "payable": payable != nil, "payableMsg": payableMsg, "lines": lines, "creditBadge": template.HTML(creditBadge),
		"comment": strings.TrimSpace(row.Str("comment")), "all": all})
}
