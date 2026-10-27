package sheets

import (
	"fmt"
	"time"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

// Runs the syncs that are due (Support\Runner). Called by cron (acme sheets-sync, every 5 minutes) and, as a fallback,
// by the module's 'tick' hook while somebody is signed in. Nothing runs during office hours.

// tick returns one line per sync that ran.
func tick(c *app.Ctx, maxN, budgetSeconds int) []string {
	if inOffice(time.Now()) {
		return nil // due syncs simply wait until office hours end
	}
	start := time.Now()
	var done []string
	ids := db.Select(fmt.Sprintf(`SELECT id FROM gsheet_syncs WHERE active AND next_run_at <= now()
            AND (running_since IS NULL OR running_since < now() - interval '30 minutes') ORDER BY next_run_at LIMIT %d`, max(1, maxN)))
	for _, r := range ids {
		if time.Since(start) > time.Duration(budgetSeconds)*time.Second {
			break
		}
		if s := claim(r.Int("id"), false); s != nil {
			done = append(done, runSync(c, s, false))
		}
	}
	return done
}

// claim marks the sync as running unless another process already did.
func claim(id int, manual bool) db.Row {
	cond := " AND active AND next_run_at <= now()"
	if manual {
		cond = ""
	}
	return db.First("UPDATE gsheet_syncs SET running_since = now() WHERE id = ?"+cond+`
            AND (running_since IS NULL OR running_since < now() - interval '30 minutes') RETURNING *`, id)
}

// runNow is "Sync now" from the page.
func runNow(c *app.Ctx, id int) string {
	if inOffice(time.Now()) {
		panic(fmt.Errorf("%s", c.T("Syncing is paused during office hours (:h).", "h", officeLabel(c))))
	}
	s := claim(id, true)
	if s == nil {
		panic(fmt.Errorf("%s", c.T("This sync is already running.")))
	}
	return runSync(c, s, true)
}

func runSync(c *app.Ctx, s db.Row, manual bool) string {
	ok := false
	var rows any
	keep := true
	var msg string
	if err := app.Catch(func() {
		if !mayUse(c, s.Int("table_id"), s.Int("user_id")) {
			keep = false
			panic(fmt.Errorf("%s", c.T("You may no longer sync this table. Ask an administrator.")))
		}
		t := db.First("SELECT * FROM gsheet_tables WHERE id = ?", s["table_id"])
		header, data, truncated := read(c, t, maxRows())
		write(c, s.Int("account_id"), s.Str("spreadsheet_id"), s.Str("sheet_tab"), header, data)
		rows = len(data)
		if truncated {
			msg = c.T("Only the first :n rows were sent (limit set by the administrator).", "n", php.NF(len(data), 0))
		} else {
			msg = c.T(":n rows sent.", "n", php.NF(len(data), 0))
		}
		ok = true
	}); err != nil {
		msg = err.Error()
		app.WriteErrorLog("[sheets] sync " + s.Str("id") + ": " + err.Error())
	}

	var next any
	active := false
	if manual {
		if !php.Empty(s["next_run_at"]) {
			next = s["next_run_at"]
		}
		active = keep && s.Bool("active") && next != nil
	} else {
		if at, has := nextRun(s, time.Now()); has {
			next = php.Date("c", at)
		}
		active = keep && s.Str("mode") == "daily" && next != nil
	}
	if !active {
		next = nil
	}
	status := "error"
	if ok {
		status = "ok"
	}
	db.Exec(`UPDATE gsheet_syncs SET running_since = NULL, last_run_at = now(), last_status = ?, last_message = ?, last_rows = coalesce(?, last_rows),
                  next_run_at = ?, active = ?, updated_at = now() WHERE id = ?`,
		status, php.Substr(msg, 0, 500), rows, next, active, s["id"])

	if !ok && !manual {
		_ = app.Catch(func() { // the bell is a courtesy
			app.Bell(c, s.Int("user_id"), "sheets", "sync_failed", c.T("Google Sheets sync failed: :tab", "tab", s["sheet_tab"]), msg, "/sheets", 0)
		})
	}
	return "#" + s.Str("id") + " " + status + ": " + msg
}
