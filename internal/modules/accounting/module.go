package accounting

import (
	"acme/internal/app"
)

// module.php: the Accounting module manifest. 'Enabled' here is the default; config/modules.json may override it.
func init() {
	app.RegisterModule(&app.Module{
		Key: "accounting", Name: "Accounting", Icon: "calc", Home: "/accounting", Enabled: true, DB: "accounting",
		Dashboards: []app.Dashboard{{Key: "overview", Label: "Accounting overview", URL: "/accounting"}},
		Menu: []app.MenuItem{
			{Heading: "Overview"},
			{Label: "Accounting overview", URL: "/accounting", Icon: "chart"},
			{Heading: "ERP data"},
			{Label: "Customers", URL: "/accounting/customers", Icon: "users", Permission: "accounting_customers.view"},
			{Label: "Orders", URL: "/accounting/orders", Icon: "cart", Permission: "accounting_orders.view"},
			{Label: "Invoices", URL: "/accounting/invoices", Icon: "doc", Permission: "accounting_invoices.view"},
			{Label: "Vouchers", URL: "/accounting/vouchers", Icon: "doc", Permission: "accounting_vouchers.view"},
			{Label: "Products", URL: "/accounting/products", Icon: "box", Permission: "accounting_products.view"},
			{Label: "Sellers", URL: "/accounting/sellers", Icon: "users", Permission: "accounting_sellers.view"},
			{Label: "Departments", URL: "/accounting/departments", Icon: "building", Permission: "accounting_departments.view"},
			{Heading: "E-Tax"},
			{Label: "Inets", URL: "/accounting/inet", Icon: "doc", Permission: "accounting_inet.view"},
			{Heading: "Billing"},
			{Label: "Billing notes", URL: "/accounting/billing", Icon: "doc", Permission: "accounting_billing.view"},
			{Heading: "Management"},
			{Label: "Account report", URL: "/accounting/account-report", Icon: "chart", Permission: "accounting_reports.view"},
			{Heading: "Setup"},
			{Label: "Company", URL: "/accounting/company", Icon: "building", AdminOnly: true},
			{Label: "Notifications", URL: "/accounting/notify", Icon: "bell", AdminOnly: true},
			{Label: "ERP connection", URL: "/accounting/erp", Icon: "cog", AdminOnly: true},
			{Label: "Logs", URL: "/accounting/logs", Icon: "tasks", AdminOnly: true},
		},
		Hooks: map[string]any{
			// Every request to an /accounting page: start the ERP / billing schedule when it is due.
			"request": app.HookRequest(func(c *app.Ctx) {
				if c.Method() == "GET" {
					kick()
				}
			}),
			// An uncaught error on an /accounting page also goes to the accounting log.
			"error": app.HookError(func(c *app.Ctx, err error) {
				logException("app", err, "Uncaught on "+c.Method()+" "+c.Path())
			}),
			// ERP → Invoices in User Management: stamped Inets of the person's department (the core page never reads this database itself)
			"erp.invoices": app.HookUserList(func(c *app.Ctx, u *app.CurrentUser, f app.M) app.M { return deptInvoicesList(u, f) }),
			"erp.invoice_pdf": app.HookUserFile(func(c *app.Ctx, u *app.CurrentUser, id int, doc string) app.M {
				return deptInvoicePDF(u, id, doc)
			}),
			// ERP → Billing notes in User Management: billing notes of the person's department
			"erp.billing": app.HookUserList(func(c *app.Ctx, u *app.CurrentUser, f app.M) app.M { return deptBillingList(u, f) }),
			"erp.billing_pdf": app.HookUserFile(func(c *app.Ctx, u *app.CurrentUser, id int, doc string) app.M {
				return deptBillingPDF(c, u, id)
			}),
			// CRM lead page: ERP customers, invoices and quotes by tax ID (the CRM checks who may see the lead)
			"customer.by_tax":   app.HookString(func(c *app.Ctx, s string) any { return customerByTax(s) }),
			"customer.get":      app.HookString(func(c *app.Ctx, s string) any { return customerGet(s) }),
			"customer.invoices": app.HookString(func(c *app.Ctx, s string) any { return customerInvoices(s, 200) }),
			"customer.quotes":   app.HookString(func(c *app.Ctx, s string) any { return customerQuotes(s, 200) }),
			// CRM opportunity form: pick a product copied from the ERP
			"product.search": app.HookSearch(func(c *app.Ctx, q string, page, per int) app.M { return productSearch(q, page, per) }),
			// Management → Account report: income / expenses by year, type, department and month
			"account.report": app.HookFilters(func(c *app.Ctx, f app.M) app.M { return accountReportSummary(f) }),
			// Management overview: revenue, top customers, sellers and departments between two dates
			"revenue.ranking": app.HookPeriod(func(c *app.Ctx, from, to string) app.M { return rankingGet(from, to) }),
		},
		Routes: routes,
		API:    apiRoutes,
	})
	app.Commands["erp-sync"] = erpSync
}
