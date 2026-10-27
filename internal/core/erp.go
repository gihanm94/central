package core

import (
	"os"
	"regexp"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

// ERP → Invoices and ERP → Billing notes (ErpInvoiceController, ErpBillingController), in User Management: the stamped
// Inet invoices / billing notes of the person's own department, view and download. The data belongs to the Accounting
// module and comes through its hooks ('erp.invoices', 'erp.invoice_pdf', 'erp.billing', 'erp.billing_pdf'), so these
// pages never touch that module's database and simply say "unavailable" when Accounting is off or broken.

func init() {
	signedIn(func(r app.Router) {
		r.Get("/erp/invoices", erpInvoicesIndex)
		r.Get("/erp/invoices/{id}/pdf/{doc}", erpInvoicePdf)
		r.Get("/erp/billing", erpBillingIndex)
		r.Get("/erp/billing/{id}/pdf", erpBillingPdf)
	})
}

func erpFilters(c *app.Ctx) app.M {
	return app.M{"q": c.Query("q", ""), "from": c.Query("from", ""), "to": c.Query("to", ""),
		"department": php.Int(c.Query("department", "0")), "page": php.Int(c.Query("page", "1"))}
}

func erpList(c *app.Ctx, hook string, f app.M) app.M {
	u := c.MustUser()
	res, _ := app.Hook(hook, "accounting", func(fn any) any {
		if h, ok := fn.(app.HookUserList); ok {
			return h(c, u, f)
		}
		return nil
	}).(app.M)
	return res
}

// erpPage builds what erp/invoices and erp/billing show.
func erpPage(c *app.Ctx, view, title, base, hook string) {
	u := c.MustUser()
	f := erpFilters(c)
	result := erpList(c, hook, f)
	var deps []app.Opt
	names := map[int]string{}
	for _, r := range db.Select("SELECT id, name FROM departments WHERE deleted_at IS NULL ORDER BY name") {
		deps = append(deps, app.Opt{Value: r.Str("id"), Label: r.Str("name")})
		names[r.Int("id")] = r.Str("name")
	}
	var myDept any
	if u.DepartmentID > 0 {
		if n, ok := names[u.DepartmentID]; ok {
			myDept = n
		}
	}
	data := app.M{"title": title, "f": f, "result": result, "deps": deps, "depNames": names, "myDept": myDept, "isAdmin": u.IsAdmin()}
	if result != nil {
		page, pages := php.Int(result["page"]), php.Int(result["pages"])
		link := func(p int) string {
			return c.URL(base, "q", f["q"], "from", f["from"], "to", f["to"], "department", php.Or(f["department"], nil), "page", php.Or(p, nil))
		}
		data["prev"], data["next"] = link(page-1), link(page+1)
		data["page"], data["pages"] = page, pages
		data["allDepartments"] = php.Truthy(result["all_departments"])
		data["rows"] = erpRows(result["rows"])
		dep := ""
		if php.Int(f["department"]) > 0 {
			dep = php.Str(f["department"])
		}
		data["depSelect"] = app.SelectField(c, "department", deps, dep, app.M{"id": "f-dep", "placeholder": c.T("All departments")})
	}
	c.View(view, data)
}

// erpRows: the hook's rows as maps (whatever list type the module returned).
func erpRows(v any) []app.M {
	switch x := v.(type) {
	case []app.M:
		return x
	case []db.Row:
		out := make([]app.M, len(x))
		for i, r := range x {
			out[i] = app.M(r)
		}
		return out
	case []any:
		var out []app.M
		for _, e := range x {
			switch m := e.(type) {
			case app.M:
				out = append(out, m)
			case db.Row:
				out = append(out, app.M(m))
			}
		}
		return out
	}
	return nil
}

func erpInvoicesIndex(c *app.Ctx) {
	c.Authorize("erp_invoices", "view")
	erpPage(c, "erp/invoices", c.T("Invoices"), "/erp/invoices", "erp.invoices")
}

func erpBillingIndex(c *app.Ctx) {
	c.Authorize("erp_billing", "view")
	erpPage(c, "erp/billing", c.T("Billing notes"), "/erp/billing", "erp.billing")
}

var pdfNameRe = regexp.MustCompile(`[^A-Za-z0-9._-]`)

// erpSendPdf streams one PDF from the hook's answer: M{"name", "label", and "path" (a file) or "body" ([]byte)}.
func erpSendPdf(c *app.Ctx, file app.M, missing, typ, text string, id int) {
	var body []byte
	if file != nil {
		if b, ok := file["body"].([]byte); ok {
			body = b
		} else if p := php.Str(file["path"]); p != "" {
			if st, err := os.Stat(p); err == nil && st.Mode().IsRegular() {
				body, _ = os.ReadFile(p)
			}
		}
	}
	if body == nil {
		app.Abort(404, missing)
	}
	label := php.Str(file["label"])
	app.LogActivity(c, app.ActivityEntry{Action: "download", Type: typ, ID: id, Label: label, Text: text, Params: []any{"doc", label}, Props: app.M{}, NoNotify: true})
	name := pdfNameRe.ReplaceAllString(php.Str(file["name"]), "_")
	if c.Query("download") == "1" {
		c.Download(name, "application/pdf", body)
	}
	c.Inline(name, "application/pdf", body)
}

func erpFile(c *app.Ctx, hook string, id int, doc string) app.M {
	u := c.MustUser()
	res, _ := app.Hook(hook, "accounting", func(fn any) any {
		if h, ok := fn.(app.HookUserFile); ok {
			return h(c, u, id, doc)
		}
		return nil
	}).(app.M)
	return res
}

// erpInvoicePdf streams one stamped PDF (only of the person's department; anything else is "not found").
func erpInvoicePdf(c *app.Ctx) {
	c.Authorize("erp_invoices", "view")
	id := c.ParamInt("id")
	file := erpFile(c, "erp.invoice_pdf", id, c.Param("doc"))
	erpSendPdf(c, file, c.T("This invoice is not available."), "inet", "Downloaded the stamped :doc", id)
}

// erpBillingPdf streams one billing note PDF (only of the person's department; anything else is "not found").
func erpBillingPdf(c *app.Ctx) {
	c.Authorize("erp_billing", "view")
	id := c.ParamInt("id")
	file := erpFile(c, "erp.billing_pdf", id, "")
	erpSendPdf(c, file, c.T("This billing note is not available."), "billing_note", "Downloaded billing note :doc", id)
}
