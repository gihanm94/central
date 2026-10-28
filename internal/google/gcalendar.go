package google

import (
	"crypto/md5"
	"encoding/hex"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/conv"
	"acme/internal/db"
)

// Google Calendar events of one person (primary calendar) — GCalendar.

const calBase = "/calendar/v3/calendars/primary/events"

func cacheDir() string { return filepath.Join(app.BasePath, "storage", "cache") }

// CalendarEvents are the events between two moments (ISO), cached briefly so paging through months stays quick.
func CalendarEvents(c *app.Ctx, userID int, from, to string) []app.M {
	sum := md5.Sum([]byte(from + to))
	cache := filepath.Join(cacheDir(), "gcal-"+conv.Str(userID)+"-"+hex.EncodeToString(sum[:])+".json")
	if st, err := os.Stat(cache); err == nil && !st.IsDir() && time.Since(st.ModTime()) < 90*time.Second {
		var out []app.M
		raw, _ := os.ReadFile(cache)
		if json.Unmarshal(raw, &out) == nil && out != nil {
			return out
		}
		return []app.M{}
	}
	st, body := API(c, userID, "GET", calBase, Query{{"timeMin", from}, {"timeMax", to}, {"singleEvents", "true"}, {"orderBy", "startTime"}, {"maxResults", 250}}, nil)
	if st >= 400 {
		Fail(ErrorMessage(body, "Calendar error") + " (" + conv.Str(st) + ")")
	}
	out := []app.M{}
	for _, it := range List(body["items"]) {
		e := Map(it)
		if conv.Str(e["status"]) == "cancelled" {
			continue
		}
		start, end := Map(e["start"]), Map(e["end"])
		_, allDay := start["date"]
		title := "(no title)"
		if v, ok := e["summary"]; ok && v != nil {
			title = conv.Str(v)
		}
		out = append(out, app.M{"id": conv.Str(e["id"]), "title": title, "start": conv.Str(conv.Coalesce(start["dateTime"], start["date"], "")),
			"end": conv.Str(conv.Coalesce(end["dateTime"], end["date"], "")), "all_day": allDay, "link": conv.Str(e["htmlLink"]),
			"location": conv.Str(e["location"]), "source": "google"})
	}
	if raw, err := json.Marshal(out); err == nil {
		_ = os.MkdirAll(cacheDir(), 0o775)
		_ = os.WriteFile(cache, raw, 0o664)
	}
	return out
}

func forget(userID int) {
	files, _ := filepath.Glob(filepath.Join(cacheDir(), "gcal-"+conv.Str(userID)+"-*.json"))
	for _, f := range files {
		_ = os.Remove(f)
	}
}

// SyncActivity creates or updates the event of a CRM activity on its owner's calendar. Never throws: the activity is
// saved either way.
func SyncActivity(c *app.Ctx, activityID int) {
	if err := app.Catch(func() {
		a := db.On("crm").First("SELECT * FROM activities WHERE id = ?", activityID)
		if a == nil {
			return
		}
		uid := conv.Int(conv.Or(a["owner_id"], a["created_by"]))
		if uid == 0 || !Connected(uid) {
			return
		}
		if !conv.Empty(a["deleted_at"]) || a.Str("status") == "CANCELLED" || conv.Empty(a["start_at"]) {
			RemoveActivity(c, a)
			return
		}
		var mins int
		if a.Str("activity_type") == "MEETING" {
			mins = conv.Int(conv.Or(a["meeting_duration"], 60))
		} else {
			mins = conv.Int(conv.Or(a["call_duration"], 30))
		}
		start := conv.StrToTime(a.Str("start_at"))
		label := map[string]string{"CALL": "📞", "MEETING": "👥", "EMAIL": "✉️", "TASK": "✅"}[a.Str("activity_type")]
		done := ""
		if a.Str("status") == "DONE" {
			done = "✓ "
		}
		tz := config.String("app.timezone", "Asia/Bangkok")
		link := c.URL("/crm/activities/" + a.Str("id"))
		reminders := any(map[string]any{"useDefault": true})
		if a.Bool("notify_me") {
			reminders = map[string]any{"useDefault": false, "overrides": []any{map[string]any{"method": "popup", "minutes": a.Int("notify_before")}}}
		}
		body := map[string]any{
			"summary":     strings.TrimSpace(label + " " + done + a.Str("topic")),
			"description": strings.TrimSpace(StripTags(a.Str("description")) + "\n\n" + link),
			"location":    a.Str("meeting_location"),
			"start":       map[string]any{"dateTime": conv.Date("c", conv.Unix(start)), "timeZone": tz},
			"end":         map[string]any{"dateTime": conv.Date("c", conv.Unix(start+int64(mins)*60)), "timeZone": tz},
			"reminders":   reminders,
			"source":      map[string]any{"title": config.String("app.name", "CRM"), "url": link},
		}
		if !conv.Empty(a["google_event_id"]) && a.Int("google_user_id") == uid {
			st, _ := API(c, uid, "PATCH", calBase+"/"+RawURLEncode(a.Str("google_event_id")), nil, body)
			if st < 400 {
				forget(uid)
				return
			}
		}
		st, r := API(c, uid, "POST", calBase, nil, body)
		if st < 400 && !conv.Empty(r["id"]) {
			db.On("crm").Exec("UPDATE activities SET google_event_id = ?, google_user_id = ? WHERE id = ?", conv.Str(r["id"]), uid, activityID)
			forget(uid)
		} else {
			b, _ := json.Marshal(r)
			app.WriteErrorLog("[gcal] activity " + conv.Str(activityID) + " not added: " + string(b))
		}
	}); err != nil {
		app.WriteErrorLog("[gcal] activity " + conv.Str(activityID) + ": " + err.Error())
	}
}

// RemoveActivity takes a CRM activity's event off the calendar.
func RemoveActivity(c *app.Ctx, a db.Row) {
	if err := app.Catch(func() {
		if conv.Empty(a["google_event_id"]) || conv.Empty(a["google_user_id"]) {
			return
		}
		API(c, a.Int("google_user_id"), "DELETE", calBase+"/"+RawURLEncode(a.Str("google_event_id")), nil, nil)
		db.On("crm").Exec("UPDATE activities SET google_event_id = NULL, google_user_id = NULL WHERE id = ?", a["id"])
		forget(a.Int("google_user_id"))
	}); err != nil {
		app.WriteErrorLog("[gcal] remove: " + err.Error())
	}
}
