package core

import (
	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/oil"
	"acme/internal/php"
)

// The bell in the top bar and the full list of a person's notifications (NotificationController).
// unread / latest are app.NotificationsUnread / app.NotificationsLatest (the layout needs them too).

func init() {
	signedIn(func(r app.Router) {
		r.Get("/notifications", notificationsIndex)
		r.Get("/notifications/feed", notificationsFeed)
		r.Post("/notifications/read-all", notificationsReadAll)
		r.Get("/notifications/{id}/open", notificationsOpen)
	})
}

func notificationsIndex(c *app.Ctx) {
	u := c.MustUser()
	only := c.Query("show") == "unread"
	page := max(1, php.Int(c.Query("page", "1")))
	per := 20
	where := "n.user_id = ?"
	if only {
		where += " AND n.read_at IS NULL"
	}
	total := php.Int(db.Scalar("SELECT count(*) FROM notifications n WHERE "+where, u.ID))
	rows := db.Select(`SELECT n.*, a.name AS actor_name FROM notifications n LEFT JOIN users a ON a.id = n.actor_id WHERE `+where+`
                              ORDER BY n.created_at DESC, n.id DESC LIMIT `+php.Str(per)+" OFFSET "+php.Str((page-1)*per), u.ID)
	c.View("notifications/index", app.M{"title": c.T("Notifications"), "rows": rows, "total": total, "perPage": per, "page": page, "only": only, "unread": app.NotificationsUnread(u.ID)})
}

// Polled every minute by the page: unread count + the dropdown's list. Also gives every module its 'tick'.
func notificationsFeed(c *app.Ctx) {
	u := c.MustUser()
	answer := app.M{"unread": app.NotificationsUnread(u.ID), "html": string(c.Partial("partials/notification-list", app.M{"items": app.NotificationsLatest(u.ID, 8)}))}
	// module background work (CRM reminders, machine jobs …); one failing never stops the others
	app.RunHooks("tick", "", func(_ string, fn any) any {
		if f, ok := fn.(app.HookTick); ok {
			f(c)
		}
		return nil
	})
	notificationsCoreTick(c)
	c.JSON(answer)
}

// Core background work: the morning oil price sync when cron does not run it. Never breaks the bell.
func notificationsCoreTick(c *app.Ctx) {
	if err := app.Catch(func() { oil.Tick(c) }); err != nil {
		app.WriteErrorLog("[oil-prices] " + err.Error())
	}
}

func notificationsOpen(c *app.Ctx) {
	n := db.First("SELECT url FROM notifications WHERE id = ? AND user_id = ?", c.ParamInt("id"), c.MustUser().ID)
	if n == nil {
		app.Abort(404)
	}
	db.Exec("UPDATE notifications SET read_at = COALESCE(read_at, now()) WHERE id = ?", c.ParamInt("id"))
	to := n.Str("url")
	if to == "" {
		to = "/notifications"
	}
	c.Redirect(to)
}

func notificationsReadAll(c *app.Ctx) {
	db.Exec("UPDATE notifications SET read_at = now() WHERE user_id = ? AND read_at IS NULL", c.MustUser().ID)
	if c.IsJSON() {
		c.JSON(app.M{"unread": 0})
	}
	c.Back("success", c.T("All notifications marked as read."))
}

// ---------------------------------------------------------------- editor pictures (EditorController)

func init() { signedIn(func(r app.Router) { r.Post("/editor/image", editorImage) }) }

// Pictures dropped into the rich-text editor: saved as a file, the editor gets its address (the text holds the link, never base64).
func editorImage(c *app.Ctx) {
	file := c.File("image")
	if file == nil {
		app.Invalid("image", c.T("Choose an image."))
	}
	path := app.UploadImage(c, file, "editor", 3072, false) // folder public/uploads/editor, png/jpg/webp, 3 MB
	_ = app.Catch(func() {
		app.EditorImageUploaded(path, c.MustUser().ID)
		app.EditorImagesSweep() // and away with pictures of forms never saved
	}) // the picture is saved; the registry only decides when it may be deleted
	c.JSON(app.M{"url": c.URL("uploads/" + path)})
}
