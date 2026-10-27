package core

import (
	"regexp"
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/google"
	"acme/internal/php"
)

// One calendar (CalendarController): events from the modules' 'calendar.events' hooks (CRM activities you may see) +
// your Google Calendar. /calendar for everyone, /crm/calendar is the same page inside CRM.

func init() {
	signedIn(func(r app.Router) {
		r.Get("/calendar", calendarIndex)
		r.Get("/calendar/events", calendarEvents)
		r.Get("/crm/calendar", calendarIndex)
	})
}

func calendarIndex(c *app.Ctx) {
	u := c.MustUser()
	crm := app.ModuleUsable("crm") && c.Can("crm_activities", "view")
	connected := google.Connected(u.ID)
	var lost any
	if !connected {
		if conn := google.Connection(u.ID); conn != nil {
			lost = conn["last_error"]
		}
	}
	mine := c.Query("mine") == "1"
	canCreate := crm && c.Can("crm_activities", "create")
	inCrm := strings.HasPrefix(c.Path(), "/crm")
	cfg := om("events", c.URL("/calendar/events"), "create", c.URL("/crm/activities/create"), "canCreate", canCreate, "mine", mine, "lang", c.Locale(),
		"i18n", om("today", c.T("Today"), "month", c.T("Month"), "week", c.T("Week"), "day", c.T("Day"), "agenda", c.T("Agenda"), "new", c.T("New activity"),
			"activity", c.T("Activity"), "nothing", c.T("Nothing scheduled."), "allday", c.T("All day"), "open", c.T("Open in Google Calendar"),
			"google", c.T("Google Calendar"), "edit", c.T("Edit activity"), "more", c.T("more")))
	type legend struct{ Label, Color string }
	var legends []legend
	for _, l := range [][3]string{{"CALL", "Call", "#0284c7"}, {"MEETING", "Meeting", "#7c3aed"}, {"EMAIL", "E-mail", "#64748b"}, {"TASK", "Task", "#d97706"}, {"GOOGLE", "Google Calendar", "#059669"}} {
		if l[0] == "GOOGLE" && !connected {
			continue
		}
		legends = append(legends, legend{c.T(l[1]), l[2]})
	}
	toggle := "/calendar"
	if inCrm {
		toggle = "/crm/calendar"
	}
	toggleURL := c.URL(toggle)
	if !mine {
		toggleURL = c.URL(toggle, "mine", 1)
	}
	c.View("calendar/index", app.M{"title": c.T("Calendar"), "crm": crm, "canCreate": canCreate, "google": connected, "configured": google.Configured(),
		"lost": lost, "inCrm": inCrm, "mine": mine, "cfg": phpJSON(cfg), "legend": legends, "toggleURL": toggleURL})
}

var isoDay = regexp.MustCompile(`^\d{4}-\d{2}-\d{2}`)

func calendarEvents(c *app.Ctx) {
	u := c.MustUser()
	from, to := c.Query("from"), c.Query("to")
	if !isoDay.MatchString(from) || !isoDay.MatchString(to) {
		c.JSON(app.M{"events": []any{}}, 422)
	}
	from, to = from[:10], to[:10]
	events := []any{}
	linked := map[string]bool{}
	var warn any
	mine := c.Query("mine") == "1"
	res := app.RunHooks("calendar.events", "", func(_ string, fn any) any {
		if f, ok := fn.(app.HookCalendarEvents); ok {
			return f(c, u, from, to, mine)
		}
		return nil
	})
	for _, m := range app.Modules() {
		feed, ok := res[m.Key].(app.M)
		if !ok {
			continue
		}
		switch ev := feed["events"].(type) {
		case []app.M:
			for _, e := range ev {
				events = append(events, e)
			}
		case []any:
			events = append(events, ev...)
		}
		switch l := feed["linked"].(type) {
		case app.M:
			for k := range l {
				linked[k] = true
			}
		case map[string]bool:
			for k := range l {
				linked[k] = true
			}
		case []any:
			for _, k := range l {
				linked[php.Str(k)] = true
			}
		case []string:
			for _, k := range l {
				linked[k] = true
			}
		}
	}
	if google.Connected(u.ID) {
		if err := app.Catch(func() {
			loc, err := time.LoadLocation(config.String("app.timezone", "Asia/Bangkok"))
			if err != nil {
				loc = php.Loc
			}
			iso := func(d string) string {
				t, _ := time.ParseInLocation("2006-01-02 15:04:05", d, loc)
				return t.Format("2006-01-02T15:04:05-07:00")
			}
			for _, g := range google.CalendarEvents(c, u.ID, iso(from+" 00:00:00"), iso(to+" 00:00:00")) {
				if linked[php.Str(g["id"])] {
					continue // already shown as the CRM activity it came from
				}
				g["id"] = "g" + php.Str(g["id"])
				g["type"] = "GOOGLE"
				if !php.Bool(g["all_day"]) {
					g["start"] = php.DateOf("Y-m-d\\TH:i:s", g["start"])
					if php.Str(g["end"]) != "" {
						g["end"] = php.DateOf("Y-m-d\\TH:i:s", g["end"])
					} else {
						g["end"] = ""
					}
				}
				events = append(events, g)
			}
		}); err != nil {
			warn = err.Error()
		}
	}
	c.JSON(app.M{"events": events, "warning": warn})
}
