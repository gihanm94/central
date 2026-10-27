package machines

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"time"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

/*
The Machine Checklist background jobs (Support\Jobs — what the old Spring schedulers did). Each job knows when it is due and remembers
when it last ran (job_state), so it does not matter whether `acme machines-cron` runs every few minutes or the
website's once-a-minute check does it: a job that was missed (server off) runs as soon as possible, and never twice for the same period.

	every day 00:00      items that came due start again (a "Tuesday" question is unticked on Tuesday)
	every Monday 00:05   weekly re-checks nobody approved become *-OVERDUE; weekly machines are PENDING again
	the 1st 00:10        the same for monthly machines
	every Friday 15:00   a weekly machine whose responsible person did not check gets an automatic "no action taken" record
	last day 23:55       the same for monthly machines
	weekdays 09:00       maintenance and calibration due within 30 days: responsible person and supervisor are reminded (managers: Mon and Wed 09:15)
	25 December          next year's maintenance and calibration rounds are made from this year's
*/

type job struct {
	name string
	due  time.Time
	run  func() int
}

func dayAt(t time.Time, h, m, s int) time.Time {
	return time.Date(t.Year(), t.Month(), t.Day(), h, m, s, 0, t.Location())
}

// mondayThisWeek is PHP's modify('monday this week') (weeks run Monday to Sunday).
func mondayThisWeek(t time.Time) time.Time {
	wd := int(t.Weekday())
	if wd == 0 {
		wd = 7
	}
	return dayAt(t, 0, 0, 0).AddDate(0, 0, 1-wd)
}

func lastDayOfMonth(t time.Time) time.Time {
	return time.Date(t.Year(), t.Month()+1, 0, 0, 0, 0, 0, t.Location())
}

// jobsTick runs the jobs that are due and returns their names. force skips the once-a-minute throttle (cron).
func jobsTick(c *app.Ctx, force bool, at *time.Time) []string {
	stampFile := filepath.Join(app.BasePath, "storage", "cache", "machines-jobs.stamp")
	if !force && at == nil {
		if st, err := os.Stat(stampFile); err == nil && time.Since(st.ModTime()) < 55*time.Second {
			return nil
		}
	}
	touch(stampFile)
	now := php.Now()
	if at != nil {
		now = *at
	}
	lock, err := os.OpenFile(filepath.Join(app.BasePath, "storage", "cache", "machines-jobs.lock"), os.O_CREATE|os.O_RDWR, 0o664)
	if err != nil {
		return nil
	}
	defer lock.Close()
	if syscall.Flock(int(lock.Fd()), syscall.LOCK_EX|syscall.LOCK_NB) != nil {
		return nil // another request is already doing it
	}
	defer syscall.Flock(int(lock.Fd()), syscall.LOCK_UN)

	day := dayAt(now, 0, 0, 0)
	dow := int(now.Weekday())
	if dow == 0 {
		dow = 7
	}
	dom := now.Day()
	month := int(now.Month())
	weekend := dow >= 6
	monday := mondayThisWeek(now)
	first := time.Date(now.Year(), now.Month(), 1, 0, 0, 0, 0, now.Location())
	last := lastDayOfMonth(now)

	jobs := []job{
		{"items_reset", day, func() int { return resetItems(now) }},
		{"weekly_close", dayAt(monday, 0, 5, 0), func() int { return closePeriod("WEEKLY") }},
		{"monthly_close", dayAt(first, 0, 10, 0), func() int { return closePeriod("MONTHLY") }},
		{"auto_weekly", dayAt(monday.AddDate(0, 0, 4), 15, 0, 0), func() int {
			return autoRecord(c, "WEEKLY", monday, dayAt(monday.AddDate(0, 0, 4), 23, 59, 59))
		}},
		{"auto_monthly", dayAt(last, 23, 55, 0), func() int { return autoRecord(c, "MONTHLY", first, dayAt(last, 23, 59, 59)) }},
		{"remind_team", dayAt(day, 9, 0, 0), func() int {
			if weekend {
				return 0
			}
			return remind(c, false)
		}},
		{"remind_managers", dayAt(day, 9, 15, 0), func() int {
			if dow == 1 || dow == 3 {
				return remind(c, true)
			}
			return 0
		}},
		{"next_year", time.Date(now.Year(), 12, 25, 1, 0, 0, 0, now.Location()), func() int { return nextYear(now.Year()) }},
	}
	if mdb().Scalar("SELECT 1 FROM job_state LIMIT 1") == nil {
		// first run ever: start counting from now, do not "catch up" periods that passed before the module was used
		for _, j := range jobs {
			markJob(j.name, now)
		}
		return nil
	}
	var ran []string
	for _, j := range jobs {
		if now.Before(j.due) || lastJob(j.name) >= j.due.Unix() {
			continue
		}
		if j.name == "next_year" && (month != 12 || dom < 25) {
			continue
		}
		markJob(j.name, now) // claim first: a crash must not make it run every minute
		run := j.run
		if err := app.Catch(func() { run() }); err != nil {
			app.WriteErrorLog("[machines-jobs] " + j.name + ": " + err.Error())
			continue
		}
		ran = append(ran, j.name)
	}
	return ran
}

func touch(p string) {
	now := time.Now()
	if err := os.Chtimes(p, now, now); err != nil {
		if f, err := os.OpenFile(p, os.O_CREATE|os.O_WRONLY, 0o664); err == nil {
			_ = f.Close()
		}
	}
}

func lastJob(name string) int64 {
	return php.StrToTime(mdb().Scalar("SELECT value FROM job_state WHERE key = ?", name))
}

func markJob(name string, at time.Time) {
	mdb().Exec("INSERT INTO job_state (key, value, updated_at) VALUES (?, ?, now()) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()", name, php.Date("c", at))
}

// resetItems: questions that came due today start again.
func resetItems(now time.Time) int {
	n := 0
	for _, i := range mdb().Select("SELECT id, reset_time FROM machine_checklist WHERE check_status AND reset_time <> ?", generalRule) {
		if resetsOn(i.Str("reset_time"), now) {
			mdb().Exec("UPDATE machine_checklist SET check_status = FALSE, updated_at = now() WHERE id = ?", i["id"])
			n++
		}
	}
	return n
}

// closePeriod: a new week/month: what nobody approved is overdue, and the machines wait for their responsible person again.
func closePeriod(period string) int {
	mdb().Exec(`UPDATE checklist_record c SET checklist_status = c.checklist_status || '-OVERDUE', updated_at = now()
                  WHERE c.recheck AND c.checklist_status IN ('PENDING SUPERVISOR', 'PENDING MANAGER')
                    AND c.machine_code IN (SELECT machine_code FROM machine WHERE reset_period = ? AND deleted_at IS NULL)`, period)
	return mdb().Exec("UPDATE machine SET check_status = 'PENDING', updated_at = now() WHERE reset_period = ? AND deleted_at IS NULL AND machine_status IN ("+activeIn()+")", period)
}

// autoRecord: the responsible person did not do the re-check in time: record "no action taken" so the approvers see it.
func autoRecord(c *app.Ctx, period string, from, to time.Time) int {
	n := 0
	machines := mdb().Select("SELECT * FROM machine WHERE deleted_at IS NULL AND reset_period = ? AND check_status = 'PENDING' AND responsible_person_id IS NOT NULL AND machine_status IN ("+activeIn()+")", period)
	for _, m := range machines {
		if mdb().Scalar("SELECT 1 FROM checklist_record WHERE machine_code = ? AND created_by = ? AND recheck AND check_type = 'GENERAL' AND created_at BETWEEN ? AND ?",
			m["machine_code"], m["responsible_person_id"], php.Date("c", from), php.Date("c", to)) != nil {
			continue
		}
		who := db.First("SELECT name, email FROM users WHERE id = ?", m["responsible_person_id"])
		status := "PENDING MANAGER"
		if !php.Empty(m["supervisor_id"]) {
			status = "PENDING SUPERVISOR"
		}
		var email, name any = nil, m["responsible_person_name"]
		if who != nil {
			email, name = who["email"], who["name"]
		}
		mdb().Transaction(func(tx db.Q) {
			tx.Insert("checklist_record", db.Cols{
				{Name: "check_type", Value: "GENERAL"}, {Name: "recheck", Value: true}, {Name: "machine_code", Value: m["machine_code"]}, {Name: "machine_name", Value: m["machine_name"]}, {Name: "machine_status", Value: m["machine_status"]},
				{Name: "machine_checklist", Value: "[]"}, {Name: "machine_note", Value: "Automatic recording"}, {Name: "user_id", Value: email}, {Name: "user_name", Value: name},
				{Name: "supervisor", Value: m["supervisor_id"]}, {Name: "manager", Value: m["manager_id"]}, {Name: "checklist_status", Value: status}, {Name: "reason_not_checked", Value: "NO ACTION TAKEN"},
				{Name: "created_by", Value: m["responsible_person_id"]}, {Name: "updated_by", Value: m["responsible_person_id"]},
			})
			tx.Exec("UPDATE machine SET check_status = ?, updated_at = now() WHERE id = ?", status, m["id"])
		})
		toID := m.Int("supervisor_id")
		if toID == 0 {
			toID = m.Int("manager_id")
		}
		if toID != 0 {
			whoName := any("—")
			if who != nil {
				whoName = who["name"]
			}
			app.Deliver(c, "machines", "reminder", toID, app.Text(":code was not checked in time", "code", m["machine_code"]),
				app.Text(":who did not do the :period re-check of :code · :name. An automatic record waits for your approval.", "who", whoName, "period", strings.ToLower(period), "code", m["machine_code"], "name", m["machine_name"]),
				c.URL("/machines/checklists", "view", "approve"), 0)
		}
		n++
	}
	return n
}

// remind: maintenance and calibration due within 30 days (or already late), one message per person.
func remind(c *app.Ctx, managers bool) int {
	limit := php.Date("Y-m-d", php.Now().AddDate(0, 0, 30))
	type bucket struct {
		uid  int
		kind string
		rows []db.Row
	}
	var order []string
	per := map[string]*bucket{}
	sources := []struct{ kind, sql string }{
		{"maintenance", `SELECT r.machine_code, r.machine_name, r.due_date, m.responsible_person_id, m.supervisor_id, m.manager_id FROM maintenance_record r JOIN machine m ON m.machine_code = r.machine_code
                              WHERE NOT r.is_canceled AND r.actual_date IS NULL AND r.start_date IS NULL AND r.due_date <= ? AND m.deleted_at IS NULL AND m.machine_status IN (` + activeIn() + `) ORDER BY r.due_date`},
		{"calibration", `SELECT r.machine_code, r.machine_name, r.due_date, m.responsible_person_id, m.supervisor_id, m.manager_id FROM calibration_record r JOIN machine m ON m.machine_code = r.machine_code
                              WHERE NOT r.is_canceled AND r.certificate_date IS NULL AND r.start_date IS NULL AND r.due_date <= ? AND m.deleted_at IS NULL AND m.machine_status IN (` + activeIn() + `) ORDER BY r.due_date`},
	}
	// PHP groups per person first, then per kind ($per[$uid][$kind][]): keep that order
	var uids []int
	seenUID := map[int]bool{}
	for _, s := range sources {
		for _, r := range mdb().Select(s.sql, limit) {
			ppl := []any{r["responsible_person_id"], r["supervisor_id"]}
			if managers {
				ppl = []any{r["manager_id"]}
			}
			seen := map[int]bool{}
			for _, p := range ppl {
				uid := php.Int(p)
				if uid == 0 || seen[uid] {
					continue
				}
				seen[uid] = true
				if !seenUID[uid] {
					seenUID[uid] = true
					uids = append(uids, uid)
				}
				key := fmt.Sprintf("%d|%s", uid, s.kind)
				if per[key] == nil {
					per[key] = &bucket{uid: uid, kind: s.kind}
					order = append(order, key)
				}
				per[key].rows = append(per[key].rows, r)
			}
		}
	}
	sent := 0
	today := php.Today()
	for _, uid := range uids {
		for _, kind := range []string{"maintenance", "calibration"} {
			b := per[fmt.Sprintf("%d|%s", uid, kind)]
			if b == nil {
				continue
			}
			var parts []string
			for i, r := range b.rows {
				if i >= 8 {
					break
				}
				when := fmtDate(c, r["due_date"], "d M")
				if r.Str("due_date") < today {
					when = c.T("overdue")
				}
				parts = append(parts, r.Str("machine_code")+" ("+when+")")
			}
			list := strings.Join(parts, "; ")
			if len(b.rows) > 8 {
				list += " …"
			}
			key := ":n machines need calibration soon"
			if kind == "maintenance" {
				key = ":n machines need maintenance soon"
			}
			app.Deliver(c, "machines", "reminder", uid, app.Text(key, "n", php.Str(len(b.rows))), app.Text(":list", "list", list), c.URL("/machines/"+kind), 0)
			sent++
		}
	}
	_ = order
	return sent
}

// nextYear: Dec 25: copy this year's rounds to the next year (once; skipped when next year already has rounds).
func nextYear(year int) int {
	next := year + 1
	n := 0
	if mdb().Scalar("SELECT 1 FROM maintenance_record WHERE years = ? LIMIT 1", php.Str(next)) == nil {
		for _, r := range mdb().Select("SELECT r.* FROM maintenance_record r JOIN machine m ON m.machine_code = r.machine_code WHERE r.years = ? AND NOT r.is_canceled AND m.deleted_at IS NULL AND m.machine_status IN ("+activeIn()+")", php.Str(year)) {
			mdb().Insert("maintenance_record", db.Cols{{Name: "machine_code", Value: r["machine_code"]}, {Name: "machine_name", Value: r["machine_name"]}, {Name: "years", Value: php.Str(next)}, {Name: "round", Value: r["round"]},
				{Name: "note", Value: r["note"]}, {Name: "maintenance_type", Value: r["maintenance_type"]}, {Name: "due_date", Value: shiftYear(r["due_date"], next)}, {Name: "plan_date", Value: shiftYear(r["plan_date"], next)}})
			n++
		}
	}
	if mdb().Scalar("SELECT 1 FROM calibration_record WHERE years = ? LIMIT 1", php.Str(next)) == nil {
		for _, r := range mdb().Select("SELECT r.* FROM calibration_record r JOIN machine m ON m.machine_code = r.machine_code WHERE r.years = ? AND NOT r.is_canceled AND m.deleted_at IS NULL AND m.machine_status IN ("+activeIn()+")", php.Str(year)) {
			mdb().Insert("calibration_record", db.Cols{{Name: "machine_code", Value: r["machine_code"]}, {Name: "machine_name", Value: r["machine_name"]}, {Name: "years", Value: php.Str(next)}, {Name: "due_date", Value: shiftYear(r["due_date"], next)},
				{Name: "criteria", Value: r["criteria"]}, {Name: "measuring_range", Value: r["measuring_range"]}, {Name: "accuracy", Value: r["accuracy"]}, {Name: "calibration_range", Value: r["calibration_range"]}, {Name: "permissible_capacity", Value: r["permissible_capacity"]},
				{Name: "resolution", Value: r["resolution"]}, {Name: "max_uncertainty", Value: r["max_uncertainty"]}, {Name: "mpe", Value: r["mpe"]}, {Name: "note", Value: r["note"]}})
			n++
		}
	}
	return n
}

// shiftYear: same month and day in another year (29 Feb → 28 Feb); nil stays nil.
func shiftYear(v any, year int) any {
	s := php.Str(v)
	if php.Empty(v) || len(s) < 10 {
		return nil
	}
	m, d := php.Int(s[5:7]), php.Int(s[8:10])
	days := time.Date(year, time.Month(m)+1, 0, 0, 0, 0, 0, time.UTC).Day()
	return fmt.Sprintf("%04d-%02d-%02d", year, m, min(d, days))
}
