// Package admin is the Administrator module (app/Modules/Admin in the PHP version).
//
// The administration pages (control room, members, departments, teams, roles, account requests, sessions, API access,
// settings, modules, connectors, files, activity log, system logs, mail log). They are core pages and keep their
// addresses ('paths' shows them under this module); /admin opens the first one the person may use. Visible to
// administrators and to roles allowed one of its pages.
package admin

import (
	"strings"

	"acme/internal/app"
)

func init() {
	app.RegisterModule(&app.Module{
		Key: "admin", Name: "Administrator", Icon: "shield", Home: "/admin", Enabled: true, DB: "core",
		PermissionAny: []string{"admin", "members.view", "departments.view", "teams.view", "roles.view", "activity_logs.view", "settings.view"},
		Paths:         []string{"/dashboard/admin", "/members", "/departments", "/teams", "/roles", "/sessions", "/api-access", "/settings", "/modules", "/connectors", "/files", "/activity", "/system-logs", "/mail-log", "/pdf-sign-log"},
		Dashboards:    []app.Dashboard{{Key: "admin", Label: "Control room", URL: "/dashboard/admin"}},
		Menu: []app.MenuItem{
			{Heading: "Overview"},
			{Label: "Control room", URL: "/dashboard/admin", Icon: "chart", AdminOnly: true},
			{Heading: "Organization"},
			{Label: "Members", URL: "/members", Icon: "users", Permission: "members.view"},
			{Label: "Departments", URL: "/departments", Icon: "building", Permission: "departments.view"},
			{Label: "Teams", URL: "/teams", Icon: "team", Permission: "teams.view"},
			{Heading: "People & access"},
			{Label: "Roles & access", URL: "/roles", Icon: "shield", Permission: "roles.view"},
			{Label: "Account requests", URL: "/members/requests", Icon: "user", Permission: "members.create", AdminOnly: true},
			{Label: "Sessions", URL: "/sessions", Icon: "lock", AdminOnly: true},
			{Heading: "Mobile"},
			{Label: "Mobile app & API", URL: "/api-access", Icon: "key", AdminOnly: true},
			{Heading: "System"},
			{Label: "Company settings", URL: "/settings", Icon: "cog", Permission: "settings.view"},
			{Label: "Modules", URL: "/modules", Icon: "grid", AdminOnly: true},
			{Label: "Connectors", URL: "/connectors", Icon: "link", AdminOnly: true},
			{Label: "File manager", URL: "/files", Icon: "folder", AdminOnly: true},
			{Heading: "Audit"},
			{Label: "Activity log", URL: "/activity", Icon: "clock", Permission: "activity_logs.view"},
			{Label: "System logs", URL: "/system-logs", Icon: "alert", AdminOnly: true},
			{Label: "Mail log", URL: "/mail-log", Icon: "mail", Permission: "settings.view"},
			{Label: "PDF signing log", URL: "/pdf-sign-log", Icon: "doc", AdminOnly: true},
		},
		Routes: func(r app.Router) { r.Get("/admin", home) },
	})
}

// home is HomeController::index: /admin opens the first administration page this person may use.
func home(c *app.Ctx) {
	u := c.MustUser()
	for _, item := range app.ModuleByKey("admin").Menu {
		if item.Heading != "" || (item.AdminOnly && !u.IsAdmin()) {
			continue
		}
		res, act, _ := strings.Cut(item.Permission, ".")
		if act == "" {
			act = "view"
		}
		if res == "" || c.Can(res, act) {
			c.Redirect(item.URL)
		}
	}
	app.Abort(403, c.T("You have no administration pages."))
}
