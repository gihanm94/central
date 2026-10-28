package machines

import (
	"log"
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/conv"
	"acme/internal/db"
)

// The rules of a checklist (Support\Checklists): which questions a person gets, how answers are checked, who approves, who is told.

type checkPlan struct {
	Recheck bool
	Items   []db.Row
	Empty   bool
	Weekend bool
}

/*
plan: what this person has to do on this machine right now.
  - The responsible person, while the machine's check is PENDING (a new week/month) and not on a weekend: their re-check.
    They get every item that is not yet ticked this period (general items + those that came due). It then goes to approval.
  - Everybody else (and the responsible person on other days): the general items only.
*/
func plan(m db.Row, u *app.CurrentUser, now time.Time) checkPlan {
	wd := now.Weekday()
	weekend := wd == time.Saturday || wd == time.Sunday
	recheck := m.Int("responsible_person_id") == u.ID && m.Str("check_status") == "PENDING" && !weekend
	cond := "i.reset_time = ?"
	params := []any{m["machine_code"], generalRule}
	if recheck {
		cond = "NOT i.check_status"
		params = []any{m["machine_code"]}
	}
	items := mdb().Select(`SELECT i.id, i.question_id, i.reset_time, i.check_status, q.detail, q.description, q.is_choice FROM machine_checklist i JOIN question q ON q.id = i.question_id
                WHERE i.machine_code = ? AND q.deleted_at IS NULL AND `+cond+` ORDER BY i.id`, params...)
	return checkPlan{Recheck: recheck, Items: items, Empty: len(items) == 0, Weekend: weekend}
}

// answer is one saved answer, in the order PHP wrote the keys.
type answer struct {
	ID         int    `json:"id"`
	QuestionID int    `json:"question_id"`
	Detail     any    `json:"detail"`
	IsChoice   bool   `json:"is_choice"`
	ResetTime  any    `json:"reset_time"`
	Answer     string `json:"answer"`
	Remark     string `json:"remark"`
}

// answers: posted answers → the list saved with the record. Every item needs an answer; "NG" needs a remark.
// posted is Request::input('answers'): answers[<item id>] = [answer => OK|NG|NA|text, remark => ”].
func answers(c *app.Ctx, items []db.Row, posted any) []answer {
	if len(items) == 0 {
		app.Invalid("answers", c.T("This machine has no checklist questions yet. Ask its manager to add them."))
	}
	pm, _ := rowOf(posted)
	var out []answer
	missing := 0
	for _, i := range items {
		p, _ := rowOf(pm[i.Str("id")])
		a := strings.TrimSpace(app.InStr(p["answer"]))
		remark := conv.Substr(strings.TrimSpace(app.InStr(p["remark"])), 0, 500)
		if i.Bool("is_choice") {
			if !conv.InList(a, "OK", "NG", "NA") {
				missing++
				continue
			}
			if a == "NG" && remark == "" {
				app.Invalid("answers", c.T(`Write what is wrong for ":q".`, "q", i["detail"]))
			}
		} else if a == "" {
			missing++
			continue
		} else {
			a = conv.Substr(a, 0, 200)
		}
		out = append(out, answer{ID: i.Int("id"), QuestionID: i.Int("question_id"), Detail: i["detail"], IsChoice: i.Bool("is_choice"), ResetTime: i["reset_time"], Answer: a, Remark: remark})
	}
	if missing > 0 {
		app.Invalid("answers", c.T("Answer every question (:n left).", "n", missing))
	}
	return out
}

func canApprove(r db.Row, u *app.CurrentUser) bool {
	return (r.Str("checklist_status") == "PENDING SUPERVISOR" && r.Int("supervisor") == u.ID) ||
		(r.Str("checklist_status") == "PENDING MANAGER" && r.Int("manager") == u.ID)
}

// chainStep is one approval step for the detail page; State is done | now | waiting | late.
type chainStep struct {
	Label, Who string
	At         any
	State      string
}

func chain(c *app.Ctx, r db.Row) []chainStep {
	n := names(r["created_by"], r["supervisor"], r["manager"])
	steps := []chainStep{{c.T("Checked"), conv.Str(nameOr(n, r["created_by"], r["user_name"])), r["created_at"], "done"}}
	if !r.Bool("recheck") {
		return steps
	}
	st := r.Str("checklist_status")
	late := strings.HasSuffix(st, "-OVERDUE")
	base := strings.ReplaceAll(st, "-OVERDUE", "")
	state := func(at any, want string) string {
		if !conv.Empty(at) {
			return "done"
		}
		if base == want {
			if late {
				return "late"
			}
			return "now"
		}
		return "waiting"
	}
	if !conv.Empty(r["supervisor"]) {
		steps = append(steps, chainStep{c.T("Supervisor"), conv.Str(nameOr(n, r["supervisor"], "—")), r["date_supervisor_checked"], state(r["date_supervisor_checked"], "PENDING SUPERVISOR")})
	}
	if !conv.Empty(r["manager"]) {
		steps = append(steps, chainStep{c.T("Manager"), conv.Str(nameOr(n, r["manager"], "—")), r["date_manager_checked"], state(r["date_manager_checked"], "PENDING MANAGER")})
	}
	return steps
}

// notifyAfterCheck tells the right people: the approver of a re-check, and the team when something is wrong.
func notifyAfterCheck(c *app.Ctx, m db.Row, recheck bool, status, machineStatus string, id, ng int, actor *app.CurrentUser) {
	url := c.URL("/machines/checklists/" + conv.Str(id))
	if err := app.Catch(func() {
		if recheck {
			to := m.Int("manager_id")
			if status == "PENDING SUPERVISOR" {
				to = m.Int("supervisor_id")
			}
			if to != 0 && to != actor.ID {
				app.Deliver(c, "machines", "assigned", to, app.Text("Checklist of :code waits for your approval", "code", m["machine_code"]),
					app.Text(":who checked :code · :name. Please approve it.", "who", actor.Name, "code", m["machine_code"], "name", m["machine_name"]), url, actor.ID)
			}
		}
		if ng > 0 || machineStatus != "OPERATIONAL" {
			for _, uid := range team(m, true) {
				if uid != actor.ID {
					app.Deliver(c, "machines", "updated", uid, app.Text("Problem reported on :code", "code", m["machine_code"]),
						app.Text(":who checked :code · :name: :n items are NG, machine is :status.", "who", actor.Name, "code", m["machine_code"], "name", m["machine_name"], "n", ng, "status", strings.ToLower(machineStatus)), url, actor.ID)
				}
			}
		}
	}); err != nil {
		log.Printf("[machines] notify after check: %v", err)
	}
}

func notifyApproval(c *app.Ctx, r db.Row, next string, actor *app.CurrentUser) {
	url := c.URL("/machines/checklists/" + r.Str("id"))
	if err := app.Catch(func() {
		if next == "PENDING MANAGER" && r.Int("manager") != 0 && r.Int("manager") != actor.ID {
			app.Deliver(c, "machines", "assigned", r.Int("manager"), app.Text("Checklist of :code waits for your approval", "code", r["machine_code"]),
				app.Text(":who approved the check of :code. Please give the final approval.", "who", actor.Name, "code", r["machine_code"]), url, actor.ID)
		} else if next == "COMPLETED" && r.Int("created_by") != 0 && r.Int("created_by") != actor.ID {
			app.Deliver(c, "machines", "updated", r.Int("created_by"), app.Text("Your check of :code is approved", "code", r["machine_code"]),
				app.Text(":who approved the check of :code. It is complete.", "who", actor.Name, "code", r["machine_code"]), url, actor.ID)
		}
	}); err != nil {
		log.Printf("[machines] notify approval: %v", err)
	}
}
