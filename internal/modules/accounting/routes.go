package accounting

import "acme/internal/app"

// crmLeadsFor: CRM leads with the same tax id for the API's customer view. The CRM module is ported in parallel and does
// not export its visibility rule yet, so the list stays empty (the PHP code also answers [] when the CRM link fails).
func crmLeadsFor(c *app.Ctx, vat string) []any { return []any{} }

// routes.php: inside the 'auth' group of the web routes.
func routes(r app.Router) {
	r.Get("/accounting", overviewPage)

	// ERP connection (administrators)
	r.Get("/accounting/erp", erpIndex)
	r.Post("/accounting/erp/connection", erpSaveConnection)
	r.Post("/accounting/erp/endpoints", erpSaveEndpoints)
	r.Post("/accounting/erp/login", erpLogin)
	r.Post("/accounting/erp/test", erpTest)
	r.Post("/accounting/erp/run", erpRun)
	r.Get("/accounting/erp/status", erpStatus)
	r.Get("/accounting/erp/runs", erpRuns)

	// Copies of ERP data (read only) and the e-tax page
	customersList.mount(r, nil)
	ordersList.mount(r, nil)
	invoicesList.mount(r, invoicesIndex)
	productsList.mount(r, nil)
	sellersList.mount(r, sellersIndex)
	departmentsList.mount(r, nil)
	vouchersList.mount(r, nil)
	inetsList.mount(r, nil)
	// one record from the ERP again (admins) and the hand-filled seller fields
	for _, l := range []*erpList{customersList, ordersList, invoicesList, productsList, sellersList, departmentsList, vouchersList} {
		r.Post(l.res.Base+"/{id}/sync", l.syncRow)
	}
	r.Get("/accounting/sellers/{id}/edit", sellersList.res.Edit)
	r.Post("/accounting/sellers/{id}", sellersList.res.Update)
	r.Get("/accounting/inet/candidates", inetCandidates)
	r.Post("/accounting/inet/generate", inetGenerate)
	r.Get("/accounting/inet/job/{job}", inetJob)
	r.Post("/accounting/inet/{id}/sync", inetSyncOne)
	r.Post("/accounting/inet/{id}/regenerate", inetRegenerate)
	r.Post("/accounting/inet/{id}/delete", inetsList.res.Destroy)
	r.Post("/accounting/inet/bulk-delete", inetsList.res.BulkDestroy)
	r.Post("/accounting/inet/{id}/{doc}/send", inetSendDoc)
	r.Post("/accounting/inet/{id}/{doc}/fetch", inetFetchDoc)
	r.Get("/accounting/inet/{id}/{doc}/file/{kind}", inetFile)

	// Our company (seller on the e-tax documents) — administrators
	r.Get("/accounting/company", companyEdit)
	r.Post("/accounting/company", companySaveAction)

	// Logs (files, not the database) — administrators; /tail is also used by the Generate dialog
	r.Get("/accounting/logs", logsIndex)
	r.Get("/accounting/logs/tail", logsTail)
	r.Get("/accounting/logs/download", logsDownload)
	r.Post("/accounting/logs/clear", logsClear)

	// Billing notes
	r.Get("/accounting/billing", billingList.res.Index)
	r.Get("/accounting/billing/export", billingList.export)
	r.Get("/accounting/billing/create", billingCreatePage)
	r.Get("/accounting/billing/customers", billingCustomersJSON)
	r.Get("/accounting/billing/invoices", billingInvoicesJSON)
	r.Post("/accounting/billing", billingStore)
	r.Post("/accounting/billing/bulk-delete", billingList.res.BulkDestroy)
	r.Get("/accounting/billing/{id}", billingShow)
	r.Get("/accounting/billing/{id}/edit", billingEdit)
	r.Post("/accounting/billing/{id}", billingUpdateAction)
	r.Get("/accounting/billing/{id}/pdf", billingPDFAction)
	r.Post("/accounting/billing/{id}/regenerate", billingRegenerate)
	r.Post("/accounting/billing/{id}/state/{to}", billingState)
	r.Post("/accounting/billing/{id}/delete", billingList.res.Destroy)

	// Daily Lark list of billing notes (administrators)
	r.Get("/accounting/notify", notifyEdit)
	r.Post("/accounting/notify", notifySave)
	r.Post("/accounting/notify/send", notifySendNow)

	// Management → Account report (lines added mainly by import; duplicates skipped)
	accountReports.Mount(r)
}
