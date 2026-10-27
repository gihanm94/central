package machines

import (
	"fmt"
	"os"
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/php"
)

// Machines module manifest (module.php). 'Enabled' here is the default; config/modules.json may override it.
func init() {
	app.RegisterModule(&app.Module{
		Key: "machines", Name: "Machine Checklist", Icon: "gear", Home: "/machines", Enabled: true, DB: "machines",
		Menu: []app.MenuItem{
			{Heading: "Daily work"},
			{Label: "Scan QR code", URL: "/machines/scan", Icon: "qr"},
			{Label: "Checklists", URL: "/machines/checklists", Icon: "check", Permission: "machines_checklists.view"},
			{Heading: "Machines"},
			{Label: "Machines", URL: "/machines/machines", Icon: "gear", Permission: "machines_machines.view"},
			{Label: "Register", URL: "/machines/register", Icon: "doc", Permission: "machines_register.view"},
			{Label: "Maintenance", URL: "/machines/maintenance", Icon: "tools", Permission: "machines_maintenance.view"},
			{Label: "Calibration", URL: "/machines/calibration", Icon: "target", Permission: "machines_calibration.view"},
			{Heading: "Setup"},
			{Label: "Machine types", URL: "/machines/types", Icon: "columns", Permission: "machines_types.view"},
			{Label: "Questions", URL: "/machines/questions", Icon: "list", Permission: "machines_questions.view"},
		},
		Hooks: map[string]any{
			// Polled about once a minute by every signed-in page (notification feed).
			"tick": app.HookTick(func(c *app.Ctx) { jobsTick(c, false, nil) }),
		},
		Routes: routes,
		API:    api,
	})
	app.Commands["machines-cron"] = cron
}

// routes is routes.php (inside the signed-in group).
func routes(r app.Router) {
	r.Get("/machines", scanHome)

	// Scan, look-ups and the machine a QR label opens
	r.Get("/machines/scan", scanPage)
	r.Get("/machines/lookup", scanLookup)
	r.Get("/machines/find", scanFind)
	r.Get("/machines/m/{code}", scanMachine)

	// Checklists: new check form, approvals, list (no generic add/import)
	r.Get("/machines/checklists", checklists.Index)
	r.Get("/machines/checklists/new", checklistCreate)
	r.Post("/machines/checklists", checklistStore)
	r.Post("/machines/checklists/bulk-delete", checklists.BulkDestroy)
	r.Get("/machines/checklists/export", checklistExport)
	r.Get("/machines/checklists/{id}", checklistShow)
	r.Post("/machines/checklists/{id}/approve", checklistApprove)
	r.Post("/machines/checklists/{id}/delete", checklists.Destroy)

	// Maintenance: do a round
	r.Get("/machines/maintenance/{id}/perform", maintPerform)
	r.Post("/machines/maintenance/{id}/perform", maintComplete)

	// Machines: a few extra pages must come before /machines/machines/{id}
	r.Get("/machines/file", fileShow)
	r.Get("/machines/label", machineLabels)
	r.Post("/machines/machines/{id}/responsible", machineChangeResponsible)

	machines.Mount(r, "show")
	r.Get("/machines/machines/{id}", machineShow)
	register.Mount(r, "show")
	r.Get("/machines/register/{id}", registerShow)
	maintenance.Mount(r)
	calibration.Mount(r)
	types.Mount(r)
	questions.Mount(r)
}

// cron is bin/machines-cron.php: run it every few minutes from cron; a job that is not due simply does nothing.
// Without cron the website runs the same jobs about once a minute while somebody is signed in.
func cron(args []string) int {
	stamp := func() string { return php.Date("c", php.Now()) }
	if !app.ModuleUsable("machines") {
		fmt.Println(stamp() + " the machines module is off or unavailable")
		return 0
	}
	var ran []string
	if err := app.Catch(func() { ran = jobsTick(&app.Ctx{Shared: app.M{}}, true, nil) }); err != nil {
		app.ModuleFail("machines", "hook:cron", err)
		fmt.Fprintln(os.Stderr, stamp()+" "+err.Error())
		return 1
	}
	msg := "nothing due"
	if len(ran) > 0 {
		msg = strings.Join(ran, ", ")
	}
	fmt.Println(stamp() + " ran: " + msg)
	return 0
}

var _ = time.Now
