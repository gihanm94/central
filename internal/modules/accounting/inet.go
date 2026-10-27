package accounting

import (
	"errors"
	"html/template"
	"io"
	"math"
	"os"
	"path/filepath"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

/*
e-Tax (INET) page (InetController): one row per invoice / credit note. "Generate" picks invoices that came from the ERP and are
not here yet and prepares their e-tax rows. Sync re-reads the invoice from the ERP.
*/

const inetPer = 10

func isCreditTab(c *app.Ctx) bool { return c.Query("credit") == "1" }

func icn(name, class string) string { return string(app.Icon(name, class)) }

// docButtons: Sync / Send / Inet buttons of one document type (388, T01 or 81).
func docButtons(c *app.Ctx, r db.Row, typ string) string {
	col := docCol(typ)
	gen := r.Bool("is_" + col + "_generate")
	sent := r.Bool("is_" + col + "_send")
	hasPdf := generateExists(r.Str("pdf_" + col)) // the file itself, not only its name in the table
	signed := r.Str("inet_pdf_"+col) != ""
	msg := r.Str("message_" + col)
	canEdit := c.Can("accounting_inet", "edit")
	base := "/accounting/inet/" + r.Str("id") + "/" + strings.ToLower(typ)
	btn := "inline-flex h-7 items-center gap-1 rounded-md px-2 text-xs font-medium ring-1 ring-inset transition-colors "
	// soft colours: Sync = amber, Send = red, Inet = green; the same colour but faded when it cannot be used yet
	tone := map[string][2]string{
		"sync": {"bg-amber-50 text-amber-700 ring-amber-200 hover:bg-amber-100", "bg-amber-50/60 text-amber-400 ring-amber-100"},
		"send": {"bg-signal-50 text-signal-700 ring-signal-200 hover:bg-signal-100", "bg-signal-50/60 text-signal-300 ring-signal-100"},
		"inet": {"bg-emerald-50 text-emerald-700 ring-emerald-200 hover:bg-emerald-100", "bg-emerald-50/60 text-emerald-300 ring-emerald-100"},
	}
	on := func(k string) string { return btn + tone[k][0] }
	off := func(k string) string { return btn + "cursor-not-allowed " + tone[k][1] }
	form := func(path, inner string) string {
		return `<form method="POST" action="` + app.E(c.URL(base+path)) + `" class="inline" >` + string(app.HTML(`<input type="hidden" name="_token" value="`+app.E(c.Sess.CSRF())+`">`)) + inner + `</form>`
	}
	var sync string
	if canEdit {
		sync = form("/sync", `<button class="`+on("sync")+`" title="`+app.E(c.T("Read this invoice from the ERP again"))+`">`+icn("bolt", "size-3.5")+" "+app.E(c.T("Sync"))+`</button>`)
		sync = strings.Replace(sync, "/"+strings.ToLower(typ)+"/sync", "/sync", 1) // sync works on the whole invoice
	} else {
		sync = `<span class="` + off("sync") + `">` + icn("bolt", "size-3.5") + " " + app.E(c.T("Sync")) + `</span>`
	}
	var send string
	if !canEdit || !gen {
		t := ""
		if !gen {
			t = c.T("Generate first")
		}
		send = `<span class="` + off("send") + `" title="` + app.E(t) + `">` + icn("send", "size-3.5") + " " + app.E(c.T("Send")) + `</span>`
	} else { // opens the Send window: upload a PDF, or use the generated one
		pdfURL := ""
		if hasPdf {
			pdfURL = app.E(c.URL(base + "/file/pdf"))
		}
		kind := c.T("Invoice")
		if typ == "81" {
			kind = c.T("Credit note")
		} else if typ == "T01" {
			kind = c.T("Receipt")
		}
		label := c.T("Send")
		if sent {
			label = c.T("Resend")
		}
		send = `<button type="button" class="` + on("send") + `" data-inet-send data-url="` + app.E(c.URL(base+"/send")) + `" data-pdf="` + pdfURL + `" data-title="` + app.E(kind+" #"+r.Str("no_invoice")) + `">` + icn("send", "size-3.5") + " " + app.E(label) + `</button>`
	}
	var inet string
	switch {
	case signed:
		inet = `<a href="` + app.E(c.URL(base+"/file/signed")) + `" target="_blank" class="` + on("inet") + `" title="` + app.E(c.T("Open the signed PDF from INET")) + `">` + icn("download", "size-3.5") + ` Inet</a>`
	case sent && canEdit:
		inet = form("/fetch", `<button class="`+on("inet")+`" title="`+app.E(c.T("Get the signed PDF from INET"))+`">`+icn("download", "size-3.5")+` Inet</button>`)
	default:
		inet = `<span class="` + off("inet") + `">` + icn("download", "size-3.5") + ` Inet</span>`
	}
	more := btn + "bg-white text-graphite-700 ring-graphite-900/15 hover:bg-mist !px-1.5"
	seq := 0
	if v, ok := c.Shared["_inetMenuSeq"].(int); ok {
		seq = v
	}
	seq++
	c.Shared["_inetMenuSeq"] = seq
	menuID := "dm-" + r.Str("id") + "-" + strings.ToLower(typ) + "-" + php.Str(seq) // the phone list renders the same cells again: ids must differ
	item := "flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left hover:bg-mist"
	dead := "flex w-full cursor-not-allowed items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left text-graphite-400"
	fileURL := ""
	if signed {
		fileURL = base + "/file/signed"
	} else if hasPdf {
		fileURL = base + "/file/pdf"
	}
	menu := `<button type="button" class="` + more + `" data-menu="#` + app.E(menuID) + `" data-placement="bottom-end" aria-haspopup="menu" aria-expanded="false" aria-label="` + app.E(c.T("More")) + `">…</button>` +
		`<div id="` + app.E(menuID) + `" data-menu-panel hidden role="menu" class="fixed z-[70] w-44 rounded-lg bg-white p-1.5 text-sm shadow-xl ring-1 ring-graphite-900/10">`
	if gen {
		menu += `<a role="menuitem" href="` + app.E(c.URL(base+"/file/json")) + `" target="_blank" class="` + item + `"><span class="w-4 text-center font-mono text-emerald-600">{}</span> ` + app.E(c.T("Raw Data")) + `</a>`
	} else {
		menu += `<span class="` + dead + `"><span class="w-4 text-center font-mono">{}</span> ` + app.E(c.T("Raw Data")) + `</span>`
	}
	if fileURL != "" {
		menu += `<a role="menuitem" href="` + app.E(c.URL(fileURL)) + `" target="_blank" class="` + item + `">` + icn("download", "size-4 text-sky-600") + " " + app.E(c.T("Get File")) + `</a>`
	} else {
		menu += `<span class="` + dead + `">` + icn("download", "size-4") + " " + app.E(c.T("Get File")) + `</span>`
	}
	if c.Can("accounting_inet", "create") {
		auto := "0"
		if r.Bool("auto_pdf") {
			auto = "1"
		}
		menu += `<button type="button" role="menuitem" class="` + item + `" data-inet-regen data-invoice="` + app.E(r["no_invoice"]) + `" data-auto="` + auto + `" data-confirm-text="` + app.E(c.T("Generate this invoice again? The text file and PDF are rebuilt and anything sent is marked unsent.")) + `">` + icn("bolt", "size-4 text-amber-500") + " " + app.E(c.T("Regenerate")) + `</button>`
	}
	if c.Can("accounting_inet", "delete") {
		menu += `<hr class="my-1 border-graphite-900/8"><button type="button" role="menuitem" class="` + item + ` text-signal-700 hover:bg-signal-50" data-delete-url="` + app.E(c.URL("/accounting/inet/"+r.Str("id")+"/delete")) + `" data-delete-name="` + app.E(r["no_invoice"]) + `" data-delete-kind="` + app.E(c.T("e-tax invoice")) + `">` + icn("trash", "size-4") + " " + app.E(c.T("Remove")) + `</button>`
	}
	menu += `</div>`
	warn := ""
	if msg != "" && !strings.HasPrefix(msg, "OK") {
		warn = `<span class="block max-w-[14rem] truncate text-[11px] text-signal-700" title="` + app.E(msg) + `">` + app.E(msg) + `</span>`
	}
	return `<span class="block"><span class="inline-flex items-center gap-1.5">` + sync + send + inet + menu + `</span>` + warn + `</span>`
}

var inetsList = newErpList(&erpList{syncKey: "", res: &app.Resource{
	Resource: "accounting_inet", Table: "inets", Type: "inet", Singular: "e-tax invoice", Plural: "inets",
	Base: "/accounting/inet", Icon: "doc", OrderBy: "t.no_invoice DESC", Selectable: true,
	Searchable: []string{"t.no_invoice", "t.customer_code", "t.customer_name", "t.customer_order_number", "t.tax_id"},
	// The list is read-only like the other ERP copies, but an administrator may remove a generated e-tax row (and its files).
	CanModify: func(c *app.Ctx, row db.Row, action string) bool { return action == "delete" },
	ExtraMeta: func(c *app.Ctx) app.M { return app.M{"selectable": c.Can("accounting_inet", "delete")} },
	BeforeDelete: func(c *app.Ctx, row db.Row) {
		dir := filepath.Join(app.BasePath, "storage", "inet", php.Str(row.Int("no_invoice")))
		files, _ := filepath.Glob(filepath.Join(dir, "*"))
		for _, f := range files {
			_ = os.Remove(f)
		}
		_ = os.Remove(dir)
		logWarn("inet", row.Str("no_invoice")+": e-tax row removed", om("by", c.MustUser().Email))
	},
	ScopeSQL: func(c *app.Ctx) (string, []any) { return "t.is_credit = ?", []any{bstr(isCreditTab(c))} },
	Label:    func(c *app.Ctx, row db.Row) string { return row.Str("no_invoice") },
	Filters: func(c *app.Ctx) []app.Filter {
		sent := "(t.is_388_send AND t.is_t01_send)"
		if isCreditTab(c) {
			sent = "t.is_81_send"
		}
		return []app.Filter{{Name: "send", Label: c.T("Send status"), Options: app.Opts("sent", c.T("Sent"), "unsent", c.T("Not sent")),
			SQL: "CASE ? WHEN 'sent' THEN " + sent + " ELSE NOT " + sent + " END"}}
	},
	Columns: func(c *app.Ctx) []app.Column {
		cols := []app.Column{
			{Key: "no", Label: c.T("Invoice number"), Primary: true, Sort: "t.no_invoice", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return cell(`<a href="` + app.E(c.URL("/accounting/inet/"+r.Str("id"))) + `" class="font-medium hover:text-signal-700">` + app.E(r["no_invoice"]) + `</a><span class="block text-xs text-steel">` + app.E(php.Coalesce(r["customer_order_number"], "")) + `</span>`)
			}},
			{Key: "customer", Label: c.T("Customer code"), Sort: "t.customer_code", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return cell(`<span title="` + app.E(r["customer_name"]) + `">` + orDash(r["customer_code"]) + `</span>`)
			}},
			{Key: "intl", Label: c.T("International"), Sort: "t.is_international", Render: func(c *app.Ctx, r db.Row) template.HTML {
				if r.Bool("is_international") {
					return cell(badge(c.T("Yes"), "info"))
				}
				return cell(badge(c.T("No"), "neutral"))
			}},
			{Key: "amount", Label: c.T("Amount"), Sort: "t.total_amount", Render: func(c *app.Ctx, r db.Row) template.HTML {
				cur := "฿"
				if r.Str("currency_code") != "THB" {
					cur = r.Str("currency_code")
				}
				return cell(money(r["total_amount"], cur))
			}},
			{Key: "seller", Label: c.T("Seller"), Render: func(c *app.Ctx, r db.Row) template.HTML { return cell(orDash(r["seller_name"])) }},
		}
		if isCreditTab(c) {
			cols = append(cols, app.Column{Key: "credit", Label: c.T("Credit note"), Render: func(c *app.Ctx, r db.Row) template.HTML { return cell(docButtons(c, r, "81")) }})
		} else {
			cols = append(cols,
				app.Column{Key: "invoice", Label: c.T("Invoice"), Render: func(c *app.Ctx, r db.Row) template.HTML { return cell(docButtons(c, r, "388")) }},
				app.Column{Key: "receipt", Label: c.T("Receipt"), Render: func(c *app.Ctx, r db.Row) template.HTML { return cell(docButtons(c, r, "T01")) }})
		}
		return cols
	},
	TopExtra: func(c *app.Ctx) template.HTML {
		tab := func(credit bool, label string) string {
			cls := "text-steel hover:bg-mist"
			if isCreditTab(c) == credit {
				cls = "bg-signal-600 font-medium text-white"
			}
			var u string
			if credit {
				u = c.URL("/accounting/inet", "credit", 1)
			} else {
				u = c.URL("/accounting/inet")
			}
			return `<a href="` + app.E(u) + `" class="rounded-md px-3 py-1.5 text-sm ` + cls + `">` + app.E(label) + `</a>`
		}
		return cell(`<nav class="mt-4 inline-flex gap-1 rounded-lg bg-white p-1 shadow-sm ring-1 ring-graphite-900/10" aria-label="` + app.E(c.T("Document type")) + `">` + tab(false, c.T("Invoice")) + tab(true, c.T("Credit note")) + `</nav>` +
			string(c.Partial("accounting/inet-dialog", app.M{"credit": isCreditTab(c)})) + string(c.Partial("accounting/inet-send-dialog", app.M{})))
	},
	HeaderActions: func(c *app.Ctx) template.HTML {
		if !c.Can("accounting_inet", "create") {
			return ""
		}
		return cell(`<button type="button" class="btn-primary" data-inet-open data-url="` + app.E(c.URL("/accounting/inet/candidates")) + `">` + icn("bolt", "size-4") + " " + app.E(c.T("Generate")) + `</button>`)
	},
}, detail: []detailField{{"no_invoice", "Invoice number", "text"}, {"invoice_date", "Invoice date", "date"}, {"customer_code", "Customer code", "text"}, {"customer_name", "Customer", "text"},
	{"customer_order_number", "Order number", "text"}, {"delivery_note_number", "Delivery number", "text"}, {"seller_name", "Seller", "text"}, {"department_name", "Department", "text"},
	{"tax_id", "Tax ID", "text"}, {"email", "E-mail", "text"}, {"currency_code", "Currency", "text"}, {"amount", "Amount", "number"}, {"tax_rate", "VAT rate (%)", "number"},
	{"tax_amount", "VAT", "number"}, {"total_amount", "Total", "number"}, {"product_count", "Lines", "number"}, {"is_international", "International", "checkbox"}, {"is_credit", "Credit note", "checkbox"}}})

func init() {
	// inets: a generated row shows one extra thing: Resource defaults for RowLinks are not wanted (no Sync-from-ERP row link)
	inetsList.res.RowLinks = func(c *app.Ctx, row db.Row) []app.Link { return nil }
}

// inetFindRow reads the inets row for an action on one document.
func inetFindRow(c *app.Ctx, doc string) db.Row {
	row := acc().First("SELECT * FROM inets WHERE id = ?", c.ParamInt("id"))
	if row == nil {
		app.Abort(404)
	}
	if !inStrs(strings.ToLower(doc), []string{"388", "t01", "81"}) {
		app.Abort(404)
	}
	return row
}

func inetBackTo(c *app.Ctx, row db.Row) {
	if row.Bool("is_credit") {
		c.Redirect("/accounting/inet?credit=1")
	}
	c.Redirect("/accounting/inet")
}

// inetCandidates: invoices that are in the ERP copy and not yet here, 10 a page: {rows, pages, total}.
func inetCandidates(c *app.Ctx) {
	c.Authorize("accounting_inet", "create")
	credit := c.Query("credit") == "1"
	qs := strings.TrimSpace(c.Query("q"))
	page := max(1, php.Int(c.Query("page", "1")))
	if acc().Scalar("SELECT 1 FROM erp_documents LIMIT 1") == nil {
		documentsRefreshQuietly() // first time: prepare everything once
	}
	// one tab = credit notes or invoices; the search only looks inside that tab; invoices already generated are left out
	where := []string{"d.is_credit = ?", "NOT EXISTS (SELECT 1 FROM inets n WHERE n.no_invoice = d.invoice_number)"}
	par := []any{bstr(credit)}
	for _, w := range strings.Fields(strings.ToLower(qs)) {
		where = append(where, `d.search LIKE ? ESCAPE '\'`)
		par = append(par, "%"+likeEscape(w)+"%")
	}
	from := "FROM erp_documents d WHERE " + strings.Join(where, " AND ")
	total := php.Int(acc().Scalar("SELECT count(*) "+from, par...))
	rows := acc().Select("SELECT d.invoice_number, d.order_number AS order_no, d.vat_no, d.customer_code, d.delivery_no, d.remark, d.payment_remark, d.seller_name, d.missing "+from+
		" ORDER BY d.invoice_number DESC LIMIT "+php.Str(inetPer)+" OFFSET "+php.Str((page-1)*inetPer), par...)
	c.JSON(app.M{"rows": rowsM(rows), "total": total, "pages": max(1, int(math.Ceil(float64(total)/inetPer))), "credit": credit})
}

// inetGenerate starts a background job and answers at once with its id (the page then asks /job/{id}).
func inetGenerate(c *app.Ctx) {
	c.Authorize("accounting_inet", "create")
	list := c.InputAny("items")
	raw := app.InList(list)
	if len(raw) == 0 || len(raw) > 200 {
		c.JSON(app.M{"message": c.T("Choose between 1 and 200 invoices.")}, 422)
	}
	var items []genItem
	for _, x := range raw {
		m := app.InMap(x)
		if m == nil {
			continue
		}
		v, _ := m.Get("invoice")
		a, _ := m.Get("auto_pdf")
		if n := php.Int(v); n > 0 {
			items = append(items, genItem{Invoice: n, AutoPDF: !php.Empty(a) && php.Str(a) != "false"})
		}
	}
	id := jobCreate(items, c.UserID())
	logInfo("inet", "Generate queued: "+php.Str(len(items))+" invoice(s)", om("job", id, "by", c.MustUser().Email))
	if !jobStart(id) { // this server cannot start processes: do it here, the page still gets the finished job
		jobRun(c, id)
	}
	c.JSON(app.M{"job": id})
}

// inetJob: how far a Generate job is (polled once a second).
func inetJob(c *app.Ctx) {
	c.Authorize("accounting_inet", "create")
	j := jobRead(c.Param("job"))
	if j == nil {
		app.Abort(404)
	}
	c.JSON(app.M{"id": j.ID, "status": j.Status, "total": j.Total, "built": j.Built, "saved": j.Saved, "made": j.Made, "errors": j.Errors, "message": j.Message, "printed": j.Printed})
}

// inetSyncOne reads the invoice (and its order rows) from the ERP again and refreshes the figures.
func inetSyncOne(c *app.Ctx) {
	c.Authorize("accounting_inet", "edit")
	row := acc().First("SELECT * FROM inets WHERE id = ?", c.ParamInt("id"))
	if row == nil {
		app.Abort(404)
	}
	err := app.Catch(func() {
		s := newSyncer()
		s.run("invoice", "all", "", 0, strp("InvoiceNumber eq "+php.Str(row.Int("no_invoice"))), boolp(true))
		if php.Truthy(row["order_id"]) {
			s.run("order", "one", php.Str(row["order_id"]), 0, nil, nil)
			s.run("order_row", "all", "", 0, strp("ParentOrderId eq '"+php.Str(row.Int("order_id"))+"'"), boolp(true))
		}
		inetSave(row.Int("no_invoice"), !row.Bool("is_manual"), c.UserID())
		c.Flash("success", c.T("Invoice :no read from the ERP again.", "no", row["no_invoice"]))
	})
	if err != nil {
		c.Flash("error", err.Error())
	}
	if row.Bool("is_credit") {
		c.Redirect("/accounting/inet?credit=1")
	}
	c.Redirect("/accounting/inet")
}

// inetRegenerate builds one invoice again (same Auto PDF choice as before).
func inetRegenerate(c *app.Ctx) {
	c.Authorize("accounting_inet", "create")
	row := acc().First("SELECT * FROM inets WHERE id = ?", c.ParamInt("id"))
	if row == nil {
		app.Abort(404)
	}
	res, _ := generateMany(c, []genItem{{Invoice: row.Int("no_invoice"), AutoPDF: row.Bool("auto_pdf")}}, c.UserID(), 5, nil)
	r := res[row.Int("no_invoice")]
	if r != nil && len(r.errors) > 0 {
		var parts []string
		for _, e := range r.errors {
			parts = append(parts, e[1])
		}
		c.Flash("error", strings.Join(parts, " · "))
	} else {
		c.Flash("success", c.T(":n invoice(s) generated.", "n", 1))
	}
	inetBackTo(c, row)
}

// inetSendDoc sends the text and PDF of one document to INET (a PDF can be chosen when none was generated).
func inetSendDoc(c *app.Ctx) {
	doc := c.Param("doc")
	c.Authorize("accounting_inet", "edit")
	row := inetFindRow(c, doc)
	var pdf []byte
	if f := c.File("pdf"); f != nil {
		var bytes []byte
		if fh, err := f.Open(); err == nil {
			bytes, _ = io.ReadAll(io.LimitReader(fh, 16<<20))
			fh.Close()
		}
		if !strings.HasPrefix(string(bytes), "%PDF") || len(bytes) > 15*1024*1024 {
			if c.IsJSON() {
				c.JSON(app.M{"ok": false, "message": c.T("Choose a PDF file (up to 15 MB).")})
			}
			c.Flash("error", c.T("Choose a PDF file (up to 15 MB)."))
			inetBackTo(c, row)
		}
		pdf = bytes
	}
	ok := true
	var msg string
	if err := app.Catch(func() { msg = inetSend(c, row, strings.ToUpper(doc), pdf) }); err != nil {
		logException("inet", err, row.Str("no_invoice")+" "+doc+" send failed")
		ok, msg = false, err.Error()
	}
	if ok {
		c.Flash("success", msg)
	} else {
		c.Flash("error", msg)
	}
	if c.IsJSON() {
		c.JSON(app.M{"ok": ok, "message": msg})
	}
	inetBackTo(c, row)
}

// inetFetchDoc fetches the signed PDF from INET.
func inetFetchDoc(c *app.Ctx) {
	doc := c.Param("doc")
	c.Authorize("accounting_inet", "edit")
	row := inetFindRow(c, doc)
	var msg string
	if err := app.Catch(func() { msg = inetFetch(c, row, strings.ToUpper(doc)) }); err != nil {
		logException("inet", err, row.Str("no_invoice")+" "+doc+" fetch failed")
		c.Flash("error", err.Error())
	} else {
		c.Flash("success", msg)
	}
	inetBackTo(c, row)
}

// inetFile shows the text file (json), the generated PDF (pdf) or the signed PDF from INET (signed).
func inetFile(c *app.Ctx) {
	doc, kind := c.Param("doc"), c.Param("kind")
	c.Authorize("accounting_inet", "view")
	row := inetFindRow(c, doc)
	col := docCol(doc)
	if kind == "json" {
		t := row.Str("text_" + col)
		if t == "" {
			app.Abort(404)
		}
		c.Inline(row.Str("no_invoice")+"_"+strings.ToUpper(doc)+".json", "application/json; charset=UTF-8", []byte(t))
	}
	k := "pdf_" + col
	if kind == "signed" {
		k = "inet_pdf_" + col
	}
	key := row.Str(k)
	b := generateRead(key)
	if b == nil { // shown in the Send window's preview: say what is wrong, not a whole 404 page
		var kv any
		if key != "" {
			kv = key
		}
		logInfo("inet", row.Str("no_invoice")+" "+doc+": "+kind+" PDF asked for but the file is missing", om("key", kv))
		c.Status(404)
		explain := c.T("This document has no PDF yet. Generate it with Auto PDF, or upload a PDF.")
		if key != "" {
			explain = c.T("The invoice says it has a PDF, but the file is not on the server. Generate it again, or upload a PDF.")
		}
		c.HTML(`<!doctype html><meta charset="utf-8"><body style="margin:0;display:grid;place-items:center;height:100vh;font:14px/1.5 system-ui,sans-serif;color:#3d4149;background:#f6f7f8">` +
			`<div style="max-width:28rem;text-align:center;padding:1.5rem"><p style="font-weight:600;color:#17181b;margin:0 0 .5rem">` + app.E(c.T("The PDF file is missing")) + `</p>` +
			`<p style="margin:0">` + app.E(explain) + `</p></div></body>`)
	}
	suffix := ""
	if kind == "signed" {
		suffix = "_signed"
	}
	c.Inline(row.Str("no_invoice")+"_"+strings.ToUpper(doc)+suffix+".pdf", "application/pdf", b)
}

var _ = errors.New
