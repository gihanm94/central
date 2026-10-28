package app

import (
	"strings"

	"acme/internal/conv"
	"acme/internal/db"
)

// NavItem is a sidebar entry ready to show.
type NavItem struct {
	MenuItem
	First  bool
	Active bool
}

// ModuleLink is an entry of the module switcher.
type ModuleLink struct {
	Key, Name, Icon, Home string
	Current, Problem      bool
}

// NavData is what layouts/app, sidebar and navbar compute (the PHP layout did this inline).
func NavData(c *Ctx) M {
	here := c.Path()
	current := c.ModuleForDisplay("/" + strings.Trim(here, "/"))
	mod := ModuleByKey(current)
	u := c.User()

	// visible menu items: permission, permission_any, admin_only
	var menu []MenuItem
	for _, item := range mod.Menu {
		if item.Heading != "" {
			menu = append(menu, item)
			continue
		}
		if item.AdminOnly && !u.IsAdmin() {
			continue
		}
		if len(item.PermissionAny) > 0 {
			ok := false
			for _, any := range item.PermissionAny {
				r, a, found := strings.Cut(any, ".")
				if !found {
					a = "view"
				}
				if c.Can(r, a) {
					ok = true
					break
				}
			}
			if ok {
				menu = append(menu, item)
			}
			continue
		}
		if item.Permission == "" {
			menu = append(menu, item)
			continue
		}
		r, a, found := strings.Cut(item.Permission, ".")
		if !found {
			a = "view"
		}
		if c.Can(r, a) {
			menu = append(menu, item)
		}
	}
	// drop headings with nothing under them
	var kept []MenuItem
	for i, item := range menu {
		if item.Heading != "" && (i+1 >= len(menu) || menu[i+1].Heading != "") {
			continue
		}
		kept = append(kept, item)
	}
	matches := func(url string) bool { return here == url || strings.HasPrefix(here, url+"/") }
	items := make([]NavItem, 0, len(kept))
	for i, item := range kept {
		n := NavItem{MenuItem: item, First: i == 0}
		if item.Heading == "" {
			n.Active = matches(item.URL)
			for _, other := range kept {
				// a longer menu address that also matches wins (/accounting vs /accounting/erp)
				if n.Active && other.URL != "" && len(other.URL) > len(item.URL) && matches(other.URL) {
					n.Active = false
				}
			}
		}
		items = append(items, n)
	}

	var mods []ModuleLink
	for _, m := range Modules() {
		if m.Enabled && c.ModuleAllowed(m.Key) {
			mods = append(mods, ModuleLink{Key: m.Key, Name: m.Name, Icon: m.Icon, Home: m.Home, Current: m.Key == current, Problem: len(ModuleProblems(m.Key)) > 0})
		}
	}

	unread := 0
	var latest []db.Row
	if u != nil {
		unread = NotificationsUnread(u.ID)
		latest = NotificationsLatest(u.ID, 8)
	}
	badge := conv.Str(unread)
	if unread > 99 {
		badge = "99+"
	}
	return M{
		"collapsed": c.Cookie("sidebar") == "collapsed",
		"current":   current,
		"module":    mod,
		"menu":      items,
		"modules":   mods,
		"here":      here,
		"unread":    unread,
		"badge":     badge,
		"latest":    latest,
	}
}

// NotificationsUnread is NotificationController::unread.
func NotificationsUnread(userID int) int {
	return conv.Int(db.Scalar("SELECT count(*) FROM notifications WHERE user_id = ? AND read_at IS NULL", userID))
}

// NotificationsLatest is NotificationController::latest.
func NotificationsLatest(userID, limit int) []db.Row {
	return db.Select(`SELECT n.*, a.name AS actor_name FROM notifications n LEFT JOIN users a ON a.id = n.actor_id
                            WHERE n.user_id = ? ORDER BY n.created_at DESC, n.id DESC LIMIT `+conv.Str(limit), userID)
}

// AccessRequestsEnabled is set by the access-request controller (sign-in page "Request" link).
var AccessRequestsEnabled = func() bool { return false }
