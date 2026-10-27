// Package management is the Management module (app/Modules/Management in the PHP version).
//
// For administrators and Management (permission management.view): the revenue overview (top customers, sellers and
// departments, from Accounting through the 'revenue.ranking' hook), the CRM overview (opportunities, from CRM through
// the 'management.crm' hook), the account report ('account.report'), oil prices, Power BI, Everbetter, member
// signatures, the PDF signing log and the KPI calculator (yearly appraisal score).
// Its data lives in the core database (kpi_scores); it never reads another module's database itself.
package management

import (
	"acme/internal/app"
	"acme/internal/signatures"
)

func init() {
	app.RegisterModule(&app.Module{
		Key: "management", Name: "Management", Icon: "chart", Home: "/management", Enabled: true, DB: "core",
		Permission: "management.view",
		Menu: []app.MenuItem{
			{Heading: "Overview"},
			{Label: "Overview", URL: "/management", Icon: "chart", Permission: "management.view"},
			{Label: "CRM overview", URL: "/management/crm", Icon: "target", Permission: "management.view"},
			{Label: "Account report", URL: "/management/account-report", Icon: "calc", Permission: "management.view"},
			{Label: "Oil prices", URL: "/management/oil-prices", Icon: "chart", Permission: "management.view"},
			{Heading: "Power BI"},
			{Label: "Overview", URL: "/management/powerbi", Icon: "chart", Permission: "management.view"},
			{Heading: "People"},
			{Label: "KPI calculator", URL: "/management/kpi-calculator", Icon: "calc", Permission: "kpi_calculator.view"},
			{Label: "Everbetter", URL: "/management/everbetter", Icon: "bolt", Permission: "management.view"},
			{Label: "Member signatures", URL: "/management/signatures", Icon: "pencil", Permission: "management.view"},
			{Heading: "Audit"},
			{Label: "PDF signing log", URL: "/management/pdf-signs", Icon: "doc", Permission: "management.view"},
		},
		Routes: routes,
	})
}

// routes is routes.php (signed-in users only).
func routes(r app.Router) {
	r.Group([]app.Middleware{app.Perm("management", "view")}, func(r app.Router) {
		r.Get("/management", overviewIndex)
		r.Get("/management/crm", overviewCRM)
		r.Get("/management/account-report", accountReport)
		// Power BI report in an iframe; its link is set by an administrator (Admin → Company settings → Power BI)
		r.Get("/management/powerbi", powerBI)
		r.Get("/management/everbetter", ebIndex)
		r.Get("/management/everbetter/projects/{id}", ebProject)
		r.Post("/management/everbetter/requests/{id}/acknowledge", ebAcknowledge)
		r.Post("/management/everbetter/requests/{id}/decide", ebDecide)
		r.Post("/management/everbetter/days", ebSessions)
		r.Post("/management/everbetter/ranking", ebSaveRanking)
		r.Get("/management/oil-prices", oilIndex)
		r.Post("/management/oil-prices/sync", oilSync)
		r.Post("/management/oil-prices/history", oilHistory)
		r.Post("/management/oil-prices/times", oilTimes)

		// Member signatures (upload or draw) and the PDF signing log
		r.Get("/management/signatures", sigIndex)
		r.Get("/management/signatures/{id}/image", sigImage)
		r.Post("/management/signatures/{id}", sigSave)
		r.Post("/management/signatures/{id}/delete", sigRemove)
		r.Get("/management/pdf-signs", signatures.LogIndex)
		r.Get("/management/pdf-signs/{id}", signatures.LogShow)

		// KPI calculator: yearly appraisal score out of 100
		r.Get("/management/kpi-calculator", kcIndex)
		r.Get("/management/kpi-calculator/export", kcExport)
		r.Get("/management/kpi-calculator/{id}", kcShow)
		r.Post("/management/kpi-calculator/{id}", kcCalculate)
	})
}
