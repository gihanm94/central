package sheets

import (
	"regexp"
	"sort"
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/conv"
	"acme/internal/db"
)

// When syncs may run (Support\Schedule). Two kinds: 'daily' (once a day at a time, on chosen weekdays) and 'once'
// (one date). Office hours (default Mon–Fri 08:30–17:30, set on the module's Settings page) are never used: a schedule
// cannot point into them, "Sync now" is refused in them, and a sync that falls due during them waits until they end.

var dayNames = map[int]string{1: "Mon", 2: "Tue", 3: "Wed", 4: "Thu", 5: "Fri", 6: "Sat", 7: "Sun"}

type officeHours struct {
	Start, End string
	Days       []int
}

func office() officeHours {
	var days []int
	for _, d := range strings.Split(app.Setting("gsheet_office_days", "1,2,3,4,5"), ",") {
		if n := conv.Int(d); n >= 1 && n <= 7 {
			days = append(days, n)
		}
	}
	return officeHours{app.Setting("gsheet_office_start", "08:30"), app.Setting("gsheet_office_end", "17:30"), days}
}

func maxRows() int { return max(100, conv.Int(app.Setting("gsheet_max_rows", "100000"))) }

// officeTime: is hm (HH:MM) inside office hours (start inclusive, end exclusive)?
func officeTime(hm string) bool {
	o := office()
	return hm >= o.Start && hm < o.End
}

func isoDay(t time.Time) int {
	d := int(t.Weekday())
	if d == 0 {
		return 7
	}
	return d
}

func inList(n int, list []int) bool {
	for _, x := range list {
		if x == n {
			return true
		}
	}
	return false
}

func inOffice(at time.Time) bool {
	at = at.In(conv.Loc)
	return inList(isoDay(at), office().Days) && officeTime(at.Format("15:04"))
}

// officeLabel: "Mon–Fri 08:30–17:30".
func officeLabel(c *app.Ctx) string {
	o := office()
	d := o.Days
	var days string
	contiguous := len(d) > 2
	if contiguous {
		for i := range d {
			if d[i] != d[0]+i {
				contiguous = false
			}
		}
	}
	if contiguous {
		days = c.T(dayNames[d[0]]) + "–" + c.T(dayNames[d[len(d)-1]])
	} else {
		var names []string
		for _, x := range d {
			names = append(names, c.T(dayNames[x]))
		}
		days = strings.Join(names, ", ")
	}
	return days + " " + o.Start + "–" + o.End
}

var hmRe = regexp.MustCompile(`^([01]\d|2[0-3]):[0-5]\d$`)
var ymdRe = regexp.MustCompile(`^\d{4}-\d{2}-\d{2}$`)

// when is the schedule part of a sync.
type when struct {
	Mode, Days, RunTime string
	RunDate             any // string or nil
}

func parseLocal(date, hm string) (time.Time, bool) {
	t, err := time.ParseInLocation("2006-01-02 15:04", date+" "+hm, conv.Loc)
	return t, err == nil
}

// validateSchedule checks the schedule part of a form; errs collects the messages.
func validateSchedule(c *app.Ctx, mode, runTime string, dayVals []string, date string, now time.Time, errs *app.Errors) when {
	if mode != "once" {
		mode = "daily"
	}
	if !hmRe.MatchString(runTime) {
		errs.Add("run_time", c.T("Choose a time."))
	}
	o := office()
	var days []int
	officeMsg := func() string {
		return c.T("Office hours (:h) are kept free: choose a time before :s or from :e.", "h", officeLabel(c), "s", o.Start, "e", o.End)
	}
	if mode == "daily" {
		seen := map[int]bool{}
		for _, v := range dayVals {
			if n := conv.Int(v); n >= 1 && n <= 7 && !seen[n] {
				seen[n] = true
				days = append(days, n)
			}
		}
		sort.Ints(days)
		if len(days) == 0 {
			errs.Add("days", c.T("Choose at least one day."))
		} else if !errs.Has("run_time") && officeTime(runTime) {
			for _, d := range days {
				if inList(d, o.Days) {
					errs.Add("run_time", officeMsg())
					break
				}
			}
		}
	} else {
		d, ok := parseLocal(date, runTime)
		if !ymdRe.MatchString(date) || !ok {
			errs.Add("run_date", c.T("Choose a date."))
		} else if !errs.Has("run_time") {
			if !d.After(now) {
				errs.Add("run_date", c.T("Choose a moment in the future."))
			} else if inOffice(d) {
				errs.Add("run_time", officeMsg())
			}
		}
	}
	if len(days) == 0 {
		days = []int{1, 2, 3, 4, 5}
	}
	ds := make([]string, len(days))
	for i, d := range days {
		ds[i] = conv.Str(d)
	}
	w := when{Mode: mode, Days: strings.Join(ds, ","), RunTime: runTime}
	if mode == "once" {
		w.RunDate = date
	}
	return w
}

// nextRun is the next moment after `after` at which the sync is due (false: a 'once' sync that is past).
func nextRun(s db.Row, after time.Time) (time.Time, bool) {
	hm := conv.Substr(s.Str("run_time"), 0, 5)
	if s.Str("mode") == "once" {
		at, ok := parseLocal(conv.Substr(s.Str("run_date"), 0, 10), hm)
		return at, ok && at.After(after)
	}
	var days []int
	for _, d := range strings.Split(s.Str("days"), ",") {
		days = append(days, conv.Int(d))
	}
	after = after.In(conv.Loc)
	for i := 0; i <= 7; i++ {
		at, ok := parseLocal(after.AddDate(0, 0, i).Format("2006-01-02"), hm)
		if ok && at.After(after) && inList(isoDay(at), days) {
			return at, true
		}
	}
	return time.Time{}, false
}

// describe: "Every weekday at 19:00" / "Mon, Wed at 06:00" / "Once on 24 Oct 2026 at 18:00".
func describe(c *app.Ctx, s db.Row) string {
	hm := conv.Substr(s.Str("run_time"), 0, 5)
	if s.Str("mode") == "once" {
		return c.T("Once on :d at :t", "d", app.FormatDate(c, s.Str("run_date"), "d M Y"), "t", hm)
	}
	var parts []string
	for _, d := range strings.Split(s.Str("days"), ",") {
		parts = append(parts, conv.Str(conv.Int(d)))
	}
	label := ""
	switch strings.Join(parts, ",") {
	case "1,2,3,4,5,6,7":
		label = c.T("Every day")
	case "1,2,3,4,5":
		label = c.T("Every weekday")
	case "6,7":
		label = c.T("Every weekend day")
	default:
		var names []string
		for _, p := range parts {
			names = append(names, c.T(dayNames[conv.Int(p)]))
		}
		label = strings.Join(names, ", ")
	}
	return c.T(":days at :t", "days", label, "t", hm)
}
