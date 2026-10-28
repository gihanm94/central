// Package sheets is the Google Sheets module (app/Modules/Sheets in the PHP version).
//
// People sync the tables the administrator allows into their own Google spreadsheets on a schedule.
// Its tables live in the core database (gsheet_*); its upgrades are in database/sql/modules/sheets, so a failing one
// fences off only this module.
package sheets

import (
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"strings"

	"acme/internal/app"
	"acme/internal/conv"
)

func init() {
	app.RegisterModule(&app.Module{
		Key: "sheets", Name: "Google Sheets", Icon: "table", Home: "/sheets", Enabled: true, DB: "core",
		Menu: []app.MenuItem{
			{Heading: "My syncs"},
			{Label: "Syncs", URL: "/sheets", Icon: "refresh"},
			{Label: "New sync", URL: "/sheets/new", Icon: "plus"},
			{Heading: "Administration"},
			{Label: "Tables & access", URL: "/sheets/tables", Icon: "table", AdminOnly: true},
			{Label: "Settings", URL: "/sheets/settings", Icon: "cog", AdminOnly: true},
		},
		Hooks: map[string]any{
			// Fallback for installs without cron: runs at most one due sync per poll (never in office hours).
			"tick": app.HookTick(func(c *app.Ctx) { tick(c, 1, 20) }),
			// One Google sign-in for everything: the main 'Connect Google' also asks for Sheets, and the account it
			// connects is added here (so Sheets, Mail and Calendar all stay connected together).
			"google.scopes": app.HookScopes(func() []string { return Scopes }),
			"google.connected": app.HookGoogleConnected(func(c *app.Ctx, userID int, email, access, refresh string, expiresIn int, scopes []string) {
				adopt(userID, email, access, refresh, expiresIn, scopes)
			}),
		},
		Routes:        routes,
		MigrationsDir: "database/sql/modules/sheets",
	})

	// acme sheets-sync: run it every 5 minutes from cron so syncs scheduled in the evening or at night run even
	// when nobody is signed in:   */5 * * * * /path/to/acme -base /path/to/app sheets-sync
	// Nothing runs during office hours (Google Sheets → Settings); due syncs wait until they end.
	app.Commands["sheets-sync"] = func(args []string) int {
		if !app.ModuleUsable("sheets") {
			fmt.Println(conv.Date("c", conv.Now()) + " the Google Sheets module is off or unavailable")
			return 0
		}
		c := &app.Ctx{Shared: app.M{}}
		ran := tick(c, 20, 240)
		out := "nothing due"
		if len(ran) > 0 {
			out = strings.Join(ran, "\n")
		}
		fmt.Println(conv.Date("c", conv.Now()) + " " + out)
		return 0
	}
}

func routes(r app.Router) {
	r.Get("/sheets", syncIndex)
	r.Get("/sheets/new", syncCreate)
	r.Post("/sheets", syncStore)
	r.Get("/sheets/{id}/edit", syncEdit)
	r.Post("/sheets/{id}", syncUpdate)
	r.Post("/sheets/{id}/delete", syncDestroy)
	r.Post("/sheets/{id}/run", syncRun)
	r.Post("/sheets/{id}/toggle", syncToggle)

	// Google accounts (several per person) and the spreadsheet picker
	r.Get("/sheets/connect", syncConnect)
	r.Get("/sheets/connect/callback", syncCallback)
	r.Post("/sheets/accounts/{id}/disconnect", syncDisconnect)
	r.Get("/sheets/accounts/{id}/files", syncFiles)
	r.Get("/sheets/accounts/{id}/tabs", syncTabs)

	// Administrators
	r.Get("/sheets/tables", adminTables)
	r.Get("/sheets/tables/edit", adminEditTable)
	r.Post("/sheets/tables/save", adminSaveTable)
	r.Get("/sheets/settings", adminSettings)
	r.Post("/sheets/settings", adminSaveSettings)
}

func randHex(n int) string {
	b := make([]byte, n)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}
