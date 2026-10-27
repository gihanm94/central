package crm

import (
	"fmt"
	"net/http"
	"os"
	"time"

	"acme/internal/app"
)

// CRM module manifest (module.php). "Enabled" here is the default; config/modules.json may override it.
func init() {
	app.RegisterModule(&app.Module{
		Key: "crm", Name: "CRM", Icon: "handshake", Home: "/crm/dashboard", Enabled: true, DB: "crm",
		Dashboards: []app.Dashboard{{Key: "overview", Label: "CRM overview", URL: "/crm/dashboard"}},
		Menu: []app.MenuItem{
			{Heading: "Overview"},
			{Label: "CRM overview", URL: "/crm/dashboard", Icon: "chart", Permission: "crm_dashboard.view"},
			{Heading: "Sales"},
			{Label: "Leads", URL: "/crm/leads", Icon: "building", Permission: "crm_leads.view"},
			{Label: "Contacts", URL: "/crm/contacts", Icon: "user", Permission: "crm_contacts.view"},
			{Label: "Opportunities", URL: "/crm/opportunities", Icon: "target", Permission: "crm_opportunities.view"},
			{Label: "Pipeline", URL: "/crm/pipeline", Icon: "columns", Permission: "crm_opportunities.view"},
			{Label: "Activities", URL: "/crm/activities", Icon: "clock", Permission: "crm_activities.view"},
			{Label: "Calendar", URL: "/crm/calendar", Icon: "calendar", Permission: "crm_activities.view"},
			{Heading: "Work"},
			{Label: "Projects", URL: "/crm/projects", Icon: "folder", Permission: "crm_projects.view"},
			{Label: "Tasks", URL: "/crm/tasks", Icon: "tasks", Permission: "crm_tasks.view"},
			{Heading: "Marketing"},
			{Label: "Campaigns", URL: "/crm/campaigns", Icon: "chat", Permission: "crm_campaigns.view"},
			{Heading: "Manager"},
			{Label: "Sales control", URL: "/crm/control", Icon: "users", Permission: "crm_reassign.view"},
			{Label: "Sales targets", URL: "/crm/targets", Icon: "chart", Permission: "crm_targets.view"},
			{Heading: "Setup"},
			{Label: "Data tools", URL: "/crm/data", Icon: "download", AdminOnly: true},
			{Label: "Settings", URL: "/crm/settings", Icon: "cog", PermissionAny: []string{"crm_settings.view", "crm_stages.view"}},
		},
		Hooks: map[string]any{
			// Polled about once a minute by every signed-in page (notification feed).
			"tick": app.HookTick(func(c *app.Ctx) { remindersTick(c, false) }),
			// Shared /calendar page: this person's visible CRM activities.
			"calendar.events": app.HookCalendarEvents(calendarEvents),
			// Management → CRM overview: whole-company opportunity figures for a period.
			"management.crm": app.HookPeriod(managementData),
		},
		Routes: routes,
		API:    api,
	})

	// Sends due activity reminders. Run it every minute from cron so reminders go out even when nobody is signed in:
	//   * * * * * /path/to/acme reminders
	app.Commands["reminders"] = func(args []string) int {
		if !app.ModuleUsable("crm") {
			fmt.Println(time.Now().Format(time.RFC3339) + " the crm module is off or unavailable")
			return 0
		}
		_ = app.LoadViews()
		req, _ := http.NewRequest(http.MethodGet, "/", nil)
		req.Host = ""
		c := &app.Ctx{W: discardWriter{h: http.Header{}}, R: req, Shared: app.M{}}
		var n int
		if err := app.Catch(func() { n = remindersTick(c, true) }); err != nil {
			app.ModuleFail("crm", "hook:cron", err)
			fmt.Fprintln(os.Stderr, time.Now().Format(time.RFC3339)+" "+err.Error())
			return 1
		}
		fmt.Printf("%s sent %d reminder(s)\n", time.Now().Format(time.RFC3339), n)
		return 0
	}
}

type discardWriter struct{ h http.Header }

func (d discardWriter) Header() http.Header         { return d.h }
func (d discardWriter) Write(b []byte) (int, error) { return len(b), nil }
func (d discardWriter) WriteHeader(int)             {}

// routes is routes.php (inside the signed-in group).
func routes(r app.Router) {
	r.Get("/crm", dashboardIndex)
	r.Get("/crm/dashboard", dashboardIndex)

	// Pipeline board, dropdown search, stage settings, sales targets
	r.Get("/crm/pipeline", pipelineIndex)
	r.Get("/crm/pipeline/cards", pipelineCards)
	r.Post("/crm/pipeline/move", pipelineMove)
	r.Get("/crm/search/{kind}", searchFind)
	r.Get("/crm/settings/stages", stagesIndex)
	r.Post("/crm/settings/stages", stagesSave)
	r.Get("/crm/targets", targetsIndex)
	r.Post("/crm/targets", targetsSave)
	r.Get("/crm/settings/targets", targetsMoved) // old address (bookmarks)

	// Admin data tools: import / export Excel, CSV, SQL with column mapping
	r.Get("/crm/data", dataIndex)
	r.Post("/crm/data/export", dataExport)
	r.Post("/crm/data/upload", dataUpload)
	r.Get("/crm/data/map", dataMap)
	r.Post("/crm/data/run", dataRun)
	r.Post("/crm/data/discard", dataDiscard)
	r.Post("/crm/data/mappings/{id}/delete", dataDeleteMapping)

	// Sales control: by person (hand records to somebody else) and by stage (move stale opportunities on)
	r.Get("/crm/control", teamIndex)
	r.Post("/crm/control/reassign", teamReassign)
	r.Post("/crm/control/handover", teamHandover)
	r.Post("/crm/control/status", teamStatus)
	r.Get("/crm/team", teamMoved) // old address (bookmarks)

	// Opportunity progress and the other actions of one record (before the standard /{id} routes)
	r.Post("/crm/opportunities/{id}/stage", oppMoveStage)
	r.Post("/crm/opportunities/{id}/duplicate", oppDuplicate)
	r.Post("/crm/leads/{id}/erp-connect", leadErpConnect)
	r.Post("/crm/leads/{id}/erp-disconnect", leadErpDisconnect)
	r.Post("/crm/opportunities/{id}/steps", oppAddStep)
	r.Post("/crm/opportunities/{id}/steps/{sid}/done", oppDoneStep)
	r.Post("/crm/opportunities/{id}/steps/{sid}/delete", oppDeleteStep)
	r.Post("/crm/tasks/{id}/status", taskSetStatus)          // task quick status
	r.Post("/crm/activities/{id}/status", activitySetStatus) // activity quick status

	// Standard screens: list, new, detail, edit, delete, export, import, template, download
	for _, s := range []*screen{leads, contacts, opportunities, campaigns, activities, projects, tasks} {
		s.mount(r)
	}
	for _, l := range []*app.Resource{industries, leadSources, products, currencies} {
		l.Mount(r)
	}
	r.Get("/crm/settings", settingsHome)

	// Comments, files and sharing on any record: /crm/{leads|contacts|opportunities|campaigns|activities}/{id}/…
	r.Post("/crm/{kind}/{id}/comments", recordComment)
	r.Post("/crm/{kind}/{id}/comments/{cid}/delete", recordDeleteComment)
	r.Post("/crm/{kind}/{id}/files", recordUpload)
	r.Get("/crm/{kind}/{id}/files/{fid}", recordDownload)
	r.Post("/crm/{kind}/{id}/files/{fid}/delete", recordDeleteFile)
	r.Post("/crm/{kind}/{id}/owner", teamOwner)
	r.Post("/crm/{kind}/{id}/shares", recordShare)
	r.Post("/crm/{kind}/{id}/shares/{sid}/delete", recordUnshare)
}
