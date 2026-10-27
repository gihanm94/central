package accounting

import (
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"syscall"
	"time"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/php"
)

/*
The scheduler (Erp\Schedule): `acme erp-sync tick` (cron, every minute) decides what is due.

	hot   newest rows of the busy entities, every few minutes
	cold  reference data, every half hour

Only inside the working window (Mon–Fri 07:00–20:00 Bangkok by default). A group never runs twice at once.
*/

var tzRe = regexp.MustCompile(`^[A-Za-z0-9_+\-/]+$`)

func inWindow(now time.Time) bool {
	tzName := schedSetting("tz")
	if !tzRe.MatchString(tzName) {
		tzName = "Asia/Bangkok"
	}
	loc, err := time.LoadLocation(tzName)
	if err != nil {
		loc, _ = time.LoadLocation("Asia/Bangkok")
	}
	d := now.In(loc)
	var days []int
	for _, s := range strings.Split(schedSetting("days"), ",") {
		if s != "" {
			days = append(days, php.Int(s))
		}
	}
	n := int(d.Weekday())
	if n == 0 {
		n = 7
	}
	ok := false
	for _, x := range days {
		if x == n {
			ok = true
		}
	}
	return ok && d.Hour() >= php.Int(schedSetting("hour_start")) && d.Hour() < php.Int(schedSetting("hour_end"))
}

// ---- who is running the scheduler: cron (acme erp-sync tick) or, when nobody set up cron, the website itself (kick)

func beatFile() string { return filepath.Join(app.BasePath, "storage", "cache", "erp-heartbeat") }

type heartbeat struct {
	at int64
	by string
}

// readHeartbeat: the last time the scheduler looked (nil = never).
func readHeartbeat() *heartbeat {
	raw, err := os.ReadFile(beatFile())
	if err != nil || !strings.Contains(string(raw), "|") {
		return nil
	}
	t, by, _ := strings.Cut(strings.TrimSpace(string(raw)), "|")
	return &heartbeat{at: int64(php.Int(t)), by: by}
}

func beat(by string) {
	_ = os.MkdirAll(filepath.Dir(beatFile()), 0o775)
	_ = os.WriteFile(beatFile(), []byte(php.Str(int(time.Now().Unix()))+"|"+by), 0o664)
}

// program is this binary (PHP: the php executable running bin/erp-sync.php).
func program() string {
	if p, err := os.Executable(); err == nil {
		return p
	}
	return os.Args[0]
}

// spawn runs `acme -base <folder> erp-sync <args>` in the background.
func spawn(args []string) bool {
	all := append([]string{"-base", app.BasePath, "erp-sync"}, args...)
	cmd := exec.Command(program(), all...)
	cmd.Dir = app.BasePath
	cmd.Stdin, cmd.Stdout, cmd.Stderr = nil, nil, nil
	cmd.SysProcAttr = &syscall.SysProcAttr{Setsid: true}
	if err := cmd.Start(); err != nil {
		app.WriteErrorLog("[accounting] could not start erp-sync: " + err.Error())
		return false
	}
	go func() { _ = cmd.Wait() }()
	return true
}

// kick: without cron nothing would ever run the schedule. Every accounting page (and the live ERP page) calls this: when the
// scheduler has not looked for ~a minute, it starts a background tick. With cron running the file is always fresh, so this does nothing.
func kick() {
	if b := readHeartbeat(); b != nil && time.Now().Unix()-b.at < 55 {
		return
	}
	erp := schedSetting("enabled") == "1" && erpConfigured() && inWindow(time.Now())
	if !erp && !notifyEnabled() {
		return
	}
	beat("website") // claim it first so parallel page loads do not all start one
	spawn([]string{"tick", "website"})
}

// scheduleTick is called every minute; it returns the groups that ran.
func scheduleTick(c *app.Ctx, by string) []string {
	beat(by)
	notifyTick(c) // the day's billing list on Lark (independent of the ERP working hours)
	if schedSetting("enabled") != "1" || !erpConfigured() || !inWindow(time.Now()) {
		return nil
	}
	var ran []string
	for _, g := range []struct {
		group   string
		minutes int
	}{{"hot", php.Int(schedSetting("hot_minutes"))}, {"cold", php.Int(schedSetting("cold_minutes"))}} {
		last := acc().Scalar("SELECT max(finished_at) FROM erp_sync_runs WHERE kind = ? AND finished_at IS NOT NULL", g.group)
		if last != nil && time.Now().Unix()-php.StrToTime(last) < int64(max(1, g.minutes)*60) {
			continue
		}
		if runGroup(g.group, "schedule", 0, "") != 0 {
			ran = append(ran, g.group)
		}
	}
	return ran
}

// runGroup runs every entity of a group (hot | cold | full | all) one after the other. Returns the run id, or 0 when it is already running.
func runGroup(group, trigger string, by int, forceMode string) int {
	lockPath := filepath.Join(app.BasePath, "storage", "cache", "erp-"+group+".lock")
	_ = os.MkdirAll(filepath.Dir(lockPath), 0o775)
	lock, err := os.OpenFile(lockPath, os.O_CREATE|os.O_RDWR, 0o664)
	if err != nil || syscall.Flock(int(lock.Fd()), syscall.LOCK_EX|syscall.LOCK_NB) != nil {
		if lock != nil {
			lock.Close()
		}
		logWarn("sync", "group "+group+": already running, skipped", om("trigger", trigger))
		return 0
	}
	defer func() { _ = syscall.Flock(int(lock.Fd()), syscall.LOCK_UN); lock.Close() }()
	ents := erpEntities()
	var keys []string
	for _, k := range ents.Keys {
		d, _ := ents.Vals[k].(*config.Map)
		if group == "all" || mstr(d, "group") == group {
			keys = append(keys, k)
		}
	}
	mode := forceMode
	if mode == "" {
		mode = "all"
		if group == "hot" || group == "cold" {
			mode = "latest"
		}
	}
	var byV any
	if by > 0 {
		byV = by
	}
	fetched, saved := 0, 0
	id := php.Int(acc().Insert("erp_sync_runs", db.Cols{{Name: "kind", Value: group}, {Name: "source", Value: trigger}, {Name: "started_by", Value: byV}}))
	logInfo("sync", fmt.Sprintf("group %s: run #%d start", group, id), om("trigger", trigger, "mode", mode, "entities", len(keys), "by", byV))
	var failed []string
	s := newSyncer()
	for _, k := range keys {
		var r syncResult
		if err := app.Catch(func() { r = s.run(k, mode, "", 0, nil, nil) }); err != nil {
			failed = append(failed, k+": "+err.Error())
		} else {
			fetched += r.fetched
			saved += r.saved
		}
		if len(failed) > 0 && strings.Contains(failed[len(failed)-1], "Login failed") { // no point asking the rest
			break
		}
	}
	status := "ok"
	msg := php.Str(len(keys)) + " entities, " + mode
	if len(failed) > 0 {
		status = "failed"
		if len(failed) < len(keys) {
			status = "warning"
		}
		msg = php.Substr(strings.Join(failed, " | "), 0, 1500)
	}
	acc().Exec("UPDATE erp_sync_runs SET finished_at = now(), status = ?, message = ?, fetched = ?, saved = ? WHERE id = ?", status, msg, fetched, saved, id)
	fl := []any{}
	for _, f := range failed {
		fl = append(fl, f)
	}
	ctx := om("fetched", fetched, "saved", saved, "failed", fl)
	if len(failed) > 0 {
		logError("sync", fmt.Sprintf("group %s: run #%d FAILED", group, id), ctx)
	} else {
		logInfo("sync", fmt.Sprintf("group %s: run #%d finished", group, id), ctx)
	}
	return id
}

// runEntity runs one entity in its own run record.
func runEntity(key, mode, one string, by int) int {
	var byV any
	if by > 0 {
		byV = by
	}
	id := php.Int(acc().Insert("erp_sync_runs", db.Cols{{Name: "kind", Value: "entity:" + key}, {Name: "source", Value: "manual"}, {Name: "started_by", Value: byV}}))
	var r syncResult
	if err := app.Catch(func() { r = newSyncer().run(key, mode, one, 0, nil, nil) }); err != nil {
		acc().Exec("UPDATE erp_sync_runs SET finished_at = now(), status = ?, message = ? WHERE id = ?", "failed", php.Substr(err.Error(), 0, 1500), id)
	} else {
		acc().Exec("UPDATE erp_sync_runs SET finished_at = now(), status = ?, message = ?, fetched = ?, saved = ? WHERE id = ?", "ok", mode, r.fetched, r.saved, id)
	}
	return id
}

// erpSync is the command `acme erp-sync …` (bin/erp-sync.php):
//
//	tick [website]                                   the scheduler (cron, every minute)
//	hot | cold | full | all [userId] [latest|all]    run a whole group now
//	entity <key> [latest|all|one] [id]               run one entity
//	generate <job>                                   a Generate job started by the Inets page
//	login                                            test the login and show the session
func erpSync(args []string) int {
	if !app.ModuleUsable("accounting") {
		fmt.Print(php.Date("c", php.Now()) + " the accounting module is off or unavailable\n")
		return 0
	}
	cmd := "tick"
	if len(args) > 0 {
		cmd = args[0]
	}
	arg := func(i int) string {
		if len(args) > i {
			return args[i]
		}
		return ""
	}
	c := cliCtx()
	code := 0
	err := app.Catch(func() {
		switch cmd {
		case "tick":
			by := "cron"
			if arg(1) == "website" {
				by = "website"
			}
			ran := scheduleTick(c, by)
			if len(ran) > 0 {
				logInfo("sync", "scheduler tick ran: "+strings.Join(ran, ", "))
				fmt.Print(php.Date("c", php.Now()) + " ran: " + strings.Join(ran, ", ") + "\n")
			} else {
				fmt.Print(php.Date("c", php.Now()) + " nothing due\n")
			}
		case "hot", "cold", "full", "all":
			force := ""
			if arg(3) == "latest" || arg(3) == "all" {
				force = arg(3)
			}
			id := runGroup(cmd, "manual", php.Int(arg(2)), force)
			if id != 0 {
				fmt.Printf("%s run #%d finished\n", php.Date("c", php.Now()), id)
			} else {
				fmt.Print(php.Date("c", php.Now()) + " already running\n")
			}
		case "entity":
			mode := arg(3)
			if mode == "" {
				mode = "latest"
			}
			id := runEntity(arg(2), mode, arg(4), 0)
			fmt.Printf("%s run #%d finished\n", php.Date("c", php.Now()), id)
		case "generate":
			jobRun(c, arg(1))
		case "login":
			t := newErpClient().login()
			fmt.Print("session " + php.Substr(t.Str("session_id"), 0, 6) + "… saved\n")
		default:
			fmt.Fprintf(os.Stderr, "Unknown command %s\n", cmd)
			code = 1
		}
	})
	if err != nil {
		logException("sync", err, "erp-sync "+cmd+" failed")
		fmt.Fprintf(os.Stderr, "%s %s\n", php.Date("c", php.Now()), err.Error())
		return 1
	}
	return code
}

// cliCtx is a request-less context for command-line jobs (texts in the company language, links to app.url).
func cliCtx() *app.Ctx {
	c := &app.Ctx{Shared: app.M{}}
	c.SetLocale(app.Setting("default_locale", "en"))
	return c
}

var errNotFound = errors.New("not found")
