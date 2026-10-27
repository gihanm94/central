package accounting

import (
	"errors"
	"html/template"
	"os"
	"path/filepath"
	"regexp"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

// Billing notes (BillingController): list, the 4-step generate page, PDF view / download, paid / complete.

const billingStatusSQL = "CASE WHEN t.is_complete THEN 'complete' WHEN t.is_paid THEN 'paid' WHEN t.remind_date < CURRENT_DATE THEN 'overdue' ELSE 'pending' END"

var billingList = newErpList(&erpList{res: &app.Resource{
	Resource: "accounting_billing", Table: "billing_notes", Type: "billing_note", Singular: "billing note", Plural: "billing notes",
	Base: "/accounting/billing", Icon: "doc", OrderBy: "t.id DESC", Selectable: true,
	Searchable: []string{"t.billing_number", "t.customer_code", "t.customer_name", "t.vat_number"},
	SelectSQL: func(c *app.Ctx) string {
		return "SELECT t.*, " + billingStatusSQL + ` AS status,
                       (SELECT count(*) FROM billing_note_invoices b WHERE b.billing_id = t.id) AS invoice_count FROM billing_notes t`
	},
	CanModify: func(c *app.Ctx, row db.Row, action string) bool {
		return action == "delete" || (action == "edit" && (!row.Bool("is_complete") || c.MustUser().IsAdmin()))
	},
	ExtraMeta: func(c *app.Ctx) app.M { return app.M{"selectable": c.Can("accounting_billing", "delete")} },
	Label:     func(c *app.Ctx, row db.Row) string { return row.Str("billing_number") },
	Filters: func(c *app.Ctx) []app.Filter {
		return []app.Filter{{Name: "status", Label: c.T("Status"), Options: app.Opts("pending", c.T("Pending"), "overdue", c.T("Overdue"), "paid", c.T("Paid"), "complete", c.T("Complete")),
			SQL: billingStatusSQL + " = ?"}}
	},
	Columns: func(c *app.Ctx) []app.Column {
		tone := map[string]string{"pending": "neutral", "overdue": "danger", "paid": "info", "complete": "success"}
		return []app.Column{
			{Key: "no", Label: c.T("Billing number"), Primary: true, Sort: "t.billing_number", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return cell(`<a href="` + app.E(c.URL("/accounting/billing/"+r.Str("id")+"/pdf")) + `" data-bill-view data-title="` + app.E(r["billing_number"]) + `" class="font-medium hover:text-signal-700">` + app.E(r["billing_number"]) + `</a><span class="block text-xs text-steel">` + app.E(r.Str("invoice_count")) + ` ` + app.E(c.T("invoices")) + `</span>`)
			}},
			{Key: "cust", Label: c.T("Customer"), Sort: "t.customer_name", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return cell(`<span class="block max-w-md truncate" title="` + app.E(r["customer_name"]) + `">` + app.E(r["customer_name"]) + `</span><span class="block text-xs text-steel">` + app.E(r["customer_code"]) + `</span>`)
			}},
			{Key: "total", Label: c.T("Total amount"), Sort: "t.total_amount", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return cell(money(r["total_amount"], billingSymbol(r.Str("currency_code"))))
			}},
			{Key: "status", Label: c.T("Status"), Sort: "status", Render: func(c *app.Ctx, r db.Row) template.HTML {
				t, ok := tone[r.Str("status")]
				if !ok {
					t = "neutral"
				}
				return cell(badge(c.T(php.Ucfirst(r.Str("status"))), t))
			}},
			{Key: "bdate", Label: c.T("Billing date"), Sort: "t.billing_at", Render: func(c *app.Ctx, r db.Row) template.HTML { return cell(dateCell(c, r["billing_at"])) }},
			{Key: "remind", Label: c.T("Remind date"), Sort: "t.remind_date", Render: func(c *app.Ctx, r db.Row) template.HTML { return cell(dateCell(c, r["remind_date"])) }},
		}
	},
	RowLinks: func(c *app.Ctx, row db.Row) []app.Link {
		b := "/accounting/billing/" + row.Str("id")
		l := []app.Link{{URL: b, Label: c.T("Open"), Icon: "doc"}, {URL: b + "/pdf", Label: c.T("View PDF"), Icon: "eye"}, {URL: b + "/pdf?download=1", Label: c.T("Download PDF"), Icon: "download"}}
		if c.Can("accounting_billing", "edit") {
			if !row.Bool("is_complete") || c.MustUser().IsAdmin() {
				l = append(l, app.Link{URL: b + "/edit", Label: c.T("Edit"), Icon: "pencil"})
			}
			l = append(l, app.Link{URL: b + "/regenerate", Label: c.T("Build the PDF again"), Icon: "bolt", Post: true})
			if !row.Bool("is_paid") {
				l = append(l, app.Link{URL: b + "/state/paid", Label: c.T("Mark as paid"), Icon: "check", Post: true})
			}
			if !row.Bool("is_complete") {
				l = append(l, app.Link{URL: b + "/state/complete", Label: c.T("Mark as complete"), Icon: "check", Post: true})
			}
		}
		return l
	},
	HeaderActions: func(c *app.Ctx) template.HTML {
		if !c.Can("accounting_billing", "create") {
			return ""
		}
		return cell(`<a href="` + app.E(c.URL("/accounting/billing/create")) + `" class="btn-primary">` + icn("bolt", "size-4") + " " + app.E(c.T("Generate")) + `</a>`)
	},
	TopExtra: func(c *app.Ctx) template.HTML { return c.Partial("accounting/billing-viewer", app.M{}) },
	BeforeDelete: func(c *app.Ctx, row db.Row) {
		if k := row.Str("file_key"); k != "" {
			_ = os.Remove(filepath.Join(app.BasePath, "storage", k))
		}
	},
}, detail: []detailField{{"billing_number", "Billing number", "text"}, {"customer_name", "Customer", "text"}, {"customer_code", "Customer code", "text"}, {"total_amount", "Total amount", "number"},
	{"billing_at", "Billing date", "date"}, {"remind_date", "Remind date", "date"}, {"status", "Status", "text"}}})

func billingCreatePage(c *app.Ctx) {
	c.Authorize("accounting_billing", "create")
	c.View("accounting/billing-create", app.M{"title": c.T("Generate billing note"), "isAdmin": c.MustUser().IsAdmin(), "notifyTime": sget("notify.time", "08:30")})
}

func billingCustomersJSON(c *app.Ctx) {
	c.Authorize("accounting_billing", "create")
	var rows []app.M
	if err := app.Catch(func() { rows = billingCustomers(strings.TrimSpace(c.Query("q"))) }); err != nil {
		app.WriteErrorLog("[billing] customers: " + err.Error())
		c.JSON(app.M{"error": err.Error(), "rows": []any{}}, 500)
	}
	c.JSON(app.M{"rows": rows})
}

func billingInvoicesJSON(c *app.Ctx) {
	c.Authorize("accounting_billing", "create")
	id := strings.TrimSpace(c.Query("customer"))
	var cu db.Row
	var rows []billInv
	if err := app.Catch(func() {
		cu = billingCustomer(id)
		rows = []billInv{}
		if cu != nil {
			rows = billingInvoices(id)
		}
	}); err != nil {
		app.WriteErrorLog("[billing] invoices: " + err.Error())
		c.JSON(app.M{"error": err.Error()}, 500)
	}
	if cu == nil {
		c.JSON(app.M{"error": c.T("Customer not found.") + " (#" + app.E(id) + ")"}, 404)
	}
	c.JSON(app.M{"customer": app.M{"id": cu.Str("id"), "name": cu.Str("name"), "code": cu.Str("code"), "address": cu.Str("address")}, "rows": rows})
}

func billingInput0(c *app.Ctx) billingInput {
	return billingInput{CustomerID: strings.TrimSpace(c.Input("customer_id")), Invoices: c.Inputs("invoices"), Address: c.Input("address"),
		BillingDate: c.Input("billing_date"), RemindDate: c.Input("remind_date"), IsThai: c.Input("lang", "th") != "en", Override: c.Input("override"),
		Note: c.Input("note"), Remark: c.Input("remark"), Number: c.Input("billing_number")}
}

func billingStore(c *app.Ctx) {
	c.Authorize("accounting_billing", "create")
	var id int
	if err := app.Catch(func() { id = billingCreate(c, billingInput0(c), c.UserID(), c.MustUser().IsAdmin()) }); err != nil {
		if c.IsJSON() {
			c.JSON(app.M{"message": err.Error()}, 422)
		}
		c.Flash("error", err.Error())
		c.Back("", "")
	}
	row := acc().First("SELECT billing_number FROM billing_notes WHERE id = ?", id)
	app.LogActivity(c, app.ActivityEntry{Action: "created", Type: "billing_note", ID: id, Label: row.Str("billing_number"), Text: `Created :type ":label"`,
		Params: []any{"type", "billing note", "label", row["billing_number"]}, NoNotify: true, Module: "accounting"})
	c.Flash("success", c.T("Billing note :n created.", "n", row["billing_number"]))
	if c.IsJSON() {
		c.JSON(app.M{"ok": true, "id": id, "url": c.URL("/accounting/billing")})
	}
	c.Redirect("/accounting/billing")
}

func billingShow(c *app.Ctx) {
	c.Authorize("accounting_billing", "view")
	r := billingList.res
	row := r.Find(c, c.ParamInt("id"))
	note := billingGet(row.Int("id"))
	if note == nil {
		app.Abort(404)
	}
	by := map[int]string{}
	for _, u := range db.Select("SELECT id, name FROM users WHERE id IN (?, ?)", note.Int("created_by"), note.Int("updated_by")) {
		by[u.Int("id")] = u.Str("name")
	}
	name := func(id any) string {
		if n, ok := by[php.Int(id)]; ok {
			return n
		}
		return "—"
	}
	rows := note["rows"].([]db.Row)
	tone := map[string]string{"pending": "neutral", "overdue": "danger", "paid": "info", "complete": "success"}
	t := tone[row.Str("status")]
	if t == "" {
		t = "neutral"
	}
	changed := ""
	if note.Int("updated_by") != 0 && note.Str("updated_at") != note.Str("created_at") {
		changed = c.T("Last changed by :n", "n", name(note["updated_by"])) + " · " + app.FormatDate(c, note["updated_at"], "d M Y H:i")
	}
	c.View("accounting/billing-show", app.M{"title": row.Str("billing_number"), "n": note, "rows": rows, "statusBadge": template.HTML(badge(c.T(php.Ucfirst(row.Str("status"))), t)),
		"sym": billingSymbol(note.Str("currency_code")), "thai": note.Bool("is_thai"), "b": "/accounting/billing/" + note.Str("id"),
		"canEdit": c.Can("accounting_billing", "edit") && r.CanModify(c, row, "edit"), "canDelete": c.Can("accounting_billing", "delete"),
		"createdBy": c.T("Created by :n", "n", name(note["created_by"])) + " · " + app.FormatDate(c, note["created_at"], "d M Y H:i"), "changedBy": changed,
		"total": php.NF(note["total_amount"], 2)})
}

func billingEdit(c *app.Ctx) {
	r := billingList.res
	row := r.FindForChange(c, c.ParamInt("id"), "edit")
	note := billingGet(row.Int("id"))
	if note == nil {
		app.Abort(404)
	}
	choices := billingChoices(note)
	picked := app.InList(c.Sess.Get("_old_invoices_unused"))
	_ = picked
	type ch struct {
		No       int
		OrderNo  string
		Date     string
		Due      string
		Amount   string
		Raw      float64
		IsCredit bool
		OnNote   bool
		Checked  bool
	}
	var oldInv []string
	if v, ok := c.Sess.Old("invoices"); ok {
		oldInv = app.InStrings(v)
	}
	var out []ch
	for _, x := range choices {
		sign := 1.0
		if x.IsCredit {
			sign = -1
		}
		checked := x.OnNote
		if oldInv != nil {
			checked = inStrs(php.Str(x.InvoiceNumber), oldInv)
		}
		d := func(v any) string {
			if php.Truthy(v) {
				return app.FormatDate(c, v, "d M Y")
			}
			return "—"
		}
		out = append(out, ch{No: x.InvoiceNumber, OrderNo: x.OrderNo, Date: d(x.InvoiceDate), Due: d(x.DueDate), Amount: php.NF(x.Amount, 2), Raw: sign * x.Amount, IsCredit: x.IsCredit, OnNote: x.OnNote, Checked: checked})
	}
	thai := note.Bool("is_thai")
	if v, ok := c.Sess.Old("lang"); ok {
		thai = php.Str(v) == "th"
	}
	c.View("accounting/billing-edit", app.M{"title": c.T("Edit :n", "n", row.Str("billing_number")), "n": note, "choices": out, "isAdmin": c.MustUser().IsAdmin(),
		"sym": billingSymbol(note.Str("currency_code")), "thai": thai, "billingAt": php.Substr(note.Str("billing_at"), 0, 10), "remindAt": php.Substr(note.Str("remind_date"), 0, 10),
		"invoicesError": c.Sess.Errors().Get("invoices")})
}

func billingUpdateAction(c *app.Ctx) {
	r := billingList.res
	row := r.FindForChange(c, c.ParamInt("id"), "edit")
	id := row.Int("id")
	if err := app.Catch(func() { billingUpdate(c, id, billingInput0(c), c.UserID(), c.MustUser().IsAdmin()) }); err != nil {
		c.Flash("error", err.Error())
		c.Back("", "")
	}
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "billing_note", ID: id, Label: row.Str("billing_number"), Text: `Updated :type ":label"`,
		Params: []any{"type", "billing note", "label", row["billing_number"]}, Props: app.M{"_url": c.URL("/accounting/billing/" + php.Str(id))}, NoNotify: true, Module: "accounting"})
	c.Flash("success", c.T("Billing note :n saved. The PDF was built again.", "n", row["billing_number"]))
	c.Redirect("/accounting/billing/" + php.Str(id))
}

var fileSafe = regexp.MustCompile(`[^A-Za-z0-9._-]`)

func billingPDFAction(c *app.Ctx) {
	c.Authorize("accounting_billing", "view")
	row := billingList.res.Find(c, c.ParamInt("id"))
	var abs string
	if err := app.Catch(func() { abs = billingPDF(c, row.Int("id"), false) }); err != nil {
		app.Abort(500, err.Error())
	}
	b, err := os.ReadFile(abs)
	if err != nil {
		app.Abort(500, err.Error())
	}
	name := fileSafe.ReplaceAllString(row.Str("billing_number"), "_") + ".pdf"
	if c.Query("download") == "1" {
		c.Download(name, "application/pdf", b)
	}
	c.Inline(name, "application/pdf", b)
}

func billingRegenerate(c *app.Ctx) {
	c.Authorize("accounting_billing", "edit")
	row := billingList.res.Find(c, c.ParamInt("id"))
	if err := app.Catch(func() { billingPDF(c, row.Int("id"), true) }); err != nil {
		c.Flash("error", err.Error())
	} else {
		c.Flash("success", c.T("The PDF of :n was built again.", "n", row["billing_number"]))
	}
	c.Redirect("/accounting/billing")
}

func billingState(c *app.Ctx) {
	c.Authorize("accounting_billing", "edit")
	row := billingList.res.Find(c, c.ParamInt("id"))
	to := c.Param("to")
	col := ""
	switch to {
	case "paid":
		col = "is_paid"
	case "complete":
		col = "is_complete"
	default:
		app.Abort(404)
	}
	id := row.Int("id")
	acc().Exec("UPDATE billing_notes SET "+col+" = TRUE, updated_by = ?, updated_at = now() WHERE id = ?", c.UserID(), id)
	if to == "complete" {
		acc().Exec("UPDATE billing_notes SET is_paid = TRUE WHERE id = ?", id)
	}
	c.Flash("success", c.T(":n marked as :s.", "n", row["billing_number"], "s", c.T(to)))
	c.Redirect("/accounting/billing")
}

var _ = errors.New
