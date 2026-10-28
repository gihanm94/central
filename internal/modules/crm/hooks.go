package crm

import (
	"errors"
	"os"
	"path/filepath"
	"time"

	"acme/internal/app"
	"acme/internal/conv"
	"acme/internal/db"
)

// ---------------------------------------------------------------- Reminders

// remindersTick sends the reminder of a planned activity (bell, e-mail, Lark — whatever the company and the person left on)
// once its reminder time has come. Called by the bell's once-a-minute check while people are using the app,
// and by `acme reminders` from cron so it also works when nobody is signed in.
func remindersTick(c *app.Ctx, force bool) int {
	stamp := filepath.Join(app.BasePath, "storage", "cache", "reminders.stamp")
	if !force {
		if st, err := os.Stat(stamp); err == nil && time.Since(st.ModTime()) < 55*time.Second {
			return 0
		}
	}
	_ = os.MkdirAll(filepath.Dir(stamp), 0o775)
	now := time.Now()
	if err := os.Chtimes(stamp, now, now); err != nil {
		_ = os.WriteFile(stamp, nil, 0o664)
	}

	due := db.On("crm").Select(`SELECT t.id, t.topic, t.activity_type, t.start_at, t.owner_id, t.created_by, l.name_en AS lead_name
              FROM activities t LEFT JOIN leads l ON l.id = t.lead_id
             WHERE t.deleted_at IS NULL AND t.status = 'PLANNED' AND t.notify_me AND t.reminder_sent_at IS NULL AND t.start_at IS NOT NULL
               AND t.start_at - (t.notify_before * interval '1 minute') <= now() AND t.start_at > now() - interval '1 day'
             ORDER BY t.start_at LIMIT 100`)
	for _, a := range due {
		to := a.Int("owner_id")
		if to == 0 {
			to = a.Int("created_by")
		}
		db.On("crm").Exec("UPDATE activities SET reminder_sent_at = now() WHERE id = ?", a["id"])
		if to == 0 {
			continue
		}
		typ, ok := pairLabel(activityTypes, a.Str("activity_type"))
		if !ok {
			typ = a.Str("activity_type")
		}
		lead := ""
		if a.Str("lead_name") != "" {
			lead = " · " + a.Str("lead_name")
		}
		app.Deliver(c, "crm", "reminder", to,
			app.Text("Reminder: :topic", "topic", a["topic"]),
			app.Text(":type at :when:lead", "type", typ, "when", conv.DateOf("d M Y H:i", a["start_at"]), "lead", lead),
			c.URL("/crm/activities/"+a.Str("id")), 0)
	}
	return len(due)
}

// ---------------------------------------------------------------- CalendarFeed

// calendarEvents: CRM activities for the shared /calendar page (the "calendar.events" hook).
// linked = Google event ids already shown as a CRM activity.
func calendarEvents(c *app.Ctx, u *app.CurrentUser, from, to string, mine bool) app.M {
	events := []app.M{}
	linked := app.M{}
	if !c.Can("crm_activities", "view") {
		return app.M{"events": events, "linked": linked}
	}
	s, sp := visible(u, "t", "activity")
	where := s + " AND t.deleted_at IS NULL AND t.start_at IS NOT NULL AND t.start_at >= ?::date AND t.start_at < (?::date + 1)"
	params := append(append([]any{}, sp...), from, to)
	if mine {
		where += " AND t.owner_id = ?"
		params = append(params, u.ID)
	}
	rows := db.On("crm").Select("SELECT t.id, t.topic, t.activity_type, t.status, t.start_at, t.call_duration, t.meeting_duration, t.google_event_id, t.google_user_id, t.owner_id FROM activities t WHERE "+where+" ORDER BY t.start_at LIMIT 1000", params...)
	for _, a := range rows {
		start := conv.StrToTime(a["start_at"])
		var mins int
		if a.Str("activity_type") == "MEETING" {
			mins = conv.Int(conv.Or(a["meeting_duration"], 60))
		} else {
			mins = conv.Int(conv.Or(a["call_duration"], 30))
		}
		events = append(events, app.M{"id": "a" + a.Str("id"), "title": a.Str("topic"), "start": conv.Date("Y-m-d\\TH:i:s", conv.Unix(start)),
			"end": conv.Date("Y-m-d\\TH:i:s", conv.Unix(start+int64(mins)*60)), "all_day": false, "source": "crm", "type": a["activity_type"], "status": a["status"],
			"url": "/crm/activities/" + a.Str("id") + "/edit", "show": "/crm/activities/" + a.Str("id")})
		if a.Str("google_event_id") != "" && a.Int("google_user_id") == u.ID {
			linked[a.Str("google_event_id")] = true
		}
	}
	return app.M{"events": events, "linked": linked}
}

// ---------------------------------------------------------------- ManagementOverview

// managementData: whole-company opportunity figures for Management → CRM overview (hook "management.crm"). Management is for
// administrators and Management, so there is no department scope here. Amounts are in the CRM base currency.
// People and departments are returned as ids; the Management page adds names and photos from the core database.
func managementData(c *app.Ctx, from, to string) app.M {
	if !ymd.MatchString(from) || !ymd.MatchString(to) {
		panic(errors.New("Dates must be Y-m-d"))
	}
	v := "COALESCE(t.amount, 0) * COALESCE(c.rate_to_base, 1)"
	w := "COALESCE(t.close_at, t.updated_at)"
	base := "FROM opportunities t LEFT JOIN currencies c ON c.code = t.currency WHERE t.deleted_at IS NULL"
	open := sqlList(stageOpen)
	in := w + "::date BETWEEN '" + from + "' AND '" + to + "'"
	since := "coalesce((SELECT max(s.done_at) FROM opportunity_steps s WHERE s.opportunity_id = t.id AND s.kind = 'stage'), t.created_at)"

	row := db.On("crm").First(`SELECT
              count(*) FILTER (WHERE t.opportunity_stage IN ` + open + `) AS open_n,
              COALESCE(sum(` + v + `) FILTER (WHERE t.opportunity_stage IN ` + open + `), 0) AS open_v,
              COALESCE(sum(` + v + ` * COALESCE(t.probability, 0) / 100) FILTER (WHERE t.opportunity_stage IN ` + open + ` AND t.opportunity_stage <> 'ON_HOLD'), 0) AS weighted,
              count(*) FILTER (WHERE t.opportunity_stage = 'CLOSED_WON' AND ` + in + `) AS won_n,
              COALESCE(sum(` + v + `) FILTER (WHERE t.opportunity_stage = 'CLOSED_WON' AND ` + in + `), 0) AS won_v,
              count(*) FILTER (WHERE t.opportunity_stage = 'CLOSED_LOST' AND ` + in + `) AS lost_n,
              COALESCE(sum(` + v + `) FILTER (WHERE t.opportunity_stage = 'CLOSED_LOST' AND ` + in + `), 0) AS lost_v,
              count(*) FILTER (WHERE t.created_at::date BETWEEN '` + from + `' AND '` + to + `') AS new_n,
              COALESCE(sum(` + v + `) FILTER (WHERE t.created_at::date BETWEEN '` + from + `' AND '` + to + `'), 0) AS new_v,
              count(*) FILTER (WHERE t.opportunity_stage IN ` + open + ` AND (` + since + `) < now() - interval '90 days') AS stale_n
            ` + base)
	tot := app.M{}
	for _, k := range []string{"open_n", "open_v", "weighted", "won_n", "won_v", "lost_n", "lost_v", "new_n", "new_v", "stale_n"} {
		tot[k] = row.Float(k)
	}
	closed := row.Float("won_n") + row.Float("lost_n")
	if closed > 0 {
		tot["win_rate"] = roundHalf(row.Float("won_n")/closed*100, 1)
	} else {
		tot["win_rate"] = nil
	}
	if row.Float("won_n") > 0 {
		tot["avg_won"] = row.Float("won_v") / row.Float("won_n")
	} else {
		tot["avg_won"] = nil
	}

	byStage := map[string]db.Row{}
	for _, r := range db.On("crm").Select("SELECT t.opportunity_stage AS s, count(*) AS n, COALESCE(sum(" + v + "), 0) AS v " + base + " AND t.opportunity_stage IN " + open + " GROUP BY 1") {
		byStage[r.Str("s")] = r
	}
	stages := app.M{}
	for _, s := range stageOpen {
		r := byStage[s]
		stages[s] = app.M{"label": stageLabel(c, s), "n": r.Int("n"), "v": r.Float("v")}
	}

	group := func(column string) []db.Row {
		return db.On("crm").Select(`SELECT t.` + column + ` AS id,
              count(*) FILTER (WHERE t.opportunity_stage IN ` + open + `) AS open_n,
              COALESCE(sum(` + v + `) FILTER (WHERE t.opportunity_stage IN ` + open + `), 0) AS open_v,
              count(*) FILTER (WHERE t.opportunity_stage = 'CLOSED_WON' AND ` + in + `) AS won_n,
              COALESCE(sum(` + v + `) FILTER (WHERE t.opportunity_stage = 'CLOSED_WON' AND ` + in + `), 0) AS won_v,
              count(*) FILTER (WHERE t.opportunity_stage = 'CLOSED_LOST' AND ` + in + `) AS lost_n
            ` + base + ` GROUP BY 1
            HAVING count(*) FILTER (WHERE t.opportunity_stage IN ` + open + ` OR ((t.opportunity_stage IN ('CLOSED_WON','CLOSED_LOST')) AND ` + in + `)) > 0
            ORDER BY won_v DESC, open_v DESC`)
	}
	rank := func(rows []db.Row, limit int) []app.M {
		out := []app.M{}
		for i, r := range rows {
			if limit > 0 && i >= limit {
				break
			}
			cl := r.Int("won_n") + r.Int("lost_n")
			var id, wr any
			if r["id"] != nil {
				id = r.Int("id")
			}
			if cl > 0 {
				wr = roundHalf(float64(r.Int("won_n"))/float64(cl)*100, 0)
			}
			out = append(out, app.M{"id": id, "open_n": r.Int("open_n"), "open_v": r.Float("open_v"), "won_n": r.Int("won_n"),
				"won_v": r.Float("won_v"), "lost_n": r.Int("lost_n"), "win_rate": wr})
		}
		return out
	}

	// won amount and new opportunities per month, the last 12 months up to the end of the period
	var months []app.M
	for _, m := range db.On("crm").Select(`SELECT to_char(m, 'YYYY-MM') AS ym,
                COALESCE((SELECT sum(` + v + `) ` + base + ` AND t.opportunity_stage = 'CLOSED_WON' AND date_trunc('month', ` + w + `) = m), 0) AS won_v,
                (SELECT count(*) FROM opportunities t WHERE t.deleted_at IS NULL AND date_trunc('month', t.created_at) = m) AS new_n
            FROM generate_series(date_trunc('month', '` + to + `'::date) - interval '11 months', date_trunc('month', '` + to + `'::date), interval '1 month') m ORDER BY m`) {
		months = append(months, app.M{"ym": m.Str("ym"), "won_v": m.Float("won_v"), "new_n": m.Int("new_n")})
	}

	top := []app.M{}
	for _, r := range db.On("crm").Select(`SELECT t.id, t.name, t.code, t.opportunity_stage AS stage, t.owner_id, t.department_id, t.probability, t.close_at, ` + v + ` AS v, l.name_en AS customer,
                (` + since + `) AS since
            FROM opportunities t LEFT JOIN currencies c ON c.code = t.currency LEFT JOIN leads l ON l.id = t.lead_id
            WHERE t.deleted_at IS NULL AND t.opportunity_stage IN ` + open + ` ORDER BY v DESC NULLS LAST, t.id LIMIT 8`) {
		top = append(top, app.M(r))
	}

	year := conv.Int(to[:4])
	target := targetSummaryOf(0, year)
	return app.M{
		"cur": baseCurrency(), "totals": tot, "stages": stages,
		"departments": rank(group("department_id"), 0), "sellers": rank(group("owner_id"), 10),
		"months": months, "top": top, "target": app.M{"year": year, "total": target.Total, "actual": target.ActualTotal},
	}
}
