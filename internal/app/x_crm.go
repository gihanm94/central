package app

import "acme/internal/db"

// Google Calendar sync of CRM activities (App\Core\Support\Google\GCalendar::syncActivity / removeActivity).
// The Google support is not ported yet; whoever ports it sets these. nil = nothing to sync (nobody connected Google).
var (
	GCalendarSyncActivity   func(c *Ctx, activityID int)
	GCalendarRemoveActivity func(c *Ctx, activity db.Row)
)
