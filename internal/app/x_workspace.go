package app

// AdminOnlyDashboards are the dashboards only administrators may pick in Preferences, as "module.key"
// (module.php 'dashboards' => [key => ['admin_only' => true]]; Dashboard has no such field yet).
// A module port can add its own: app.AdminOnlyDashboards["admin.admin"] = true.
var AdminOnlyDashboards = map[string]bool{"admin.admin": true}
