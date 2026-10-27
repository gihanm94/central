package machines

import (
	"fmt"
	"math"
	"net/http"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/php"
)

/*
Machine Checklist for the mobile app (Api\MachinesApi): scan a QR code, see the machine, do today's check (with a photo),
approve re-checks, see what is due. Same rules as the website: scanning is open to every signed-in person
(anyone who uses a machine does its general check); lists follow scope(); saving and approving run the
website's own actions. Paths are relative to /api/v1; the API kernel signs the person in (Bearer token).

Every endpoint answers like the PHP API kernel: the result, or {"error": {"code", "message", "fields"?}}.
*/
func api(r app.Router) {
	r.Post("/machines/scan", apiHandler(apiScan))
	r.Get("/machines/find", apiHandler(apiFind))
	r.Get("/machines/due", apiHandler(apiDue))
	r.Get("/machines/m/{code}", apiHandler(func(c *app.Ctx) (any, int) { return apiMachine(c, c.Param("code")), 200 }))
	r.Post("/machines/m/{code}/check", apiHandler(apiCheck))
	r.Get("/machines/checklists", apiHandler(apiChecklists))
	r.Get("/machines/checklists/{id}", apiHandler(func(c *app.Ctx) (any, int) { return apiRecord(c, c.ParamInt("id")), 200 }))
	r.Post("/machines/checklists/{id}/approve", apiHandler(apiApprove))
}

// apiError is ApiException: an answer other than 2xx.
type apiError struct {
	status      int
	code, msg   string
}

func (e *apiError) Error() string { return e.msg }

func apiFail(status int, code, msg string) { panic(&apiError{status, code, msg}) }

// apiHandler turns a result (or an error) into the JSON answer of the PHP API kernel.
func apiHandler(h func(c *app.Ctx) (any, int)) app.Handler {
	return func(c *app.Ctx) {
		c.API = true
		var body any
		status := 200
		err := app.Catch(func() {
			if c.User() == nil {
				apiFail(401, "unauthenticated", c.T("Sign in first."))
			}
			body, status = h(c)
		})
		if err != nil {
			switch e := err.(type) {
			case *apiError:
				status, body = e.status, om("error", om("code", e.code, "message", e.msg))
			case *app.ValidationError:
				fields := config.NewMap()
				for _, k := range e.Errors.Keys {
					fields.Set(k, e.Errors.Msgs[k])
				}
				status, body = 422, om("error", om("code", "invalid", "message", e.Errors.First(), "fields", fields))
			case *app.HTTPError:
				status = e.Status
				code := map[int]string{401: "unauthenticated", 403: "forbidden", 404: "not_found", 405: "method_not_allowed", 419: "invalid", 503: "unavailable"}[e.Status]
				if code == "" {
					code = "error"
				}
				msg := e.Message
				if msg == "" {
					msg = fmt.Sprintf("Error %d", e.Status)
					if status == 404 {
						msg = "Not found."
					}
				}
				body = om("error", om("code", code, "message", msg))
			default:
				panic(err)
			}
		}
		c.W.Header().Set("Cache-Control", "no-store")
		c.JSON(body, status)
	}
}

func apiNeed(c *app.Ctx, resource string) {
	if !c.Can(resource, "view") {
		apiFail(403, "forbidden", c.T("You don't have permission to :a :r.", "a", "view", "r", replaceUnderscore(resource)))
	}
}

func replaceUnderscore(s string) string {
	b := []byte(s)
	for i := range b {
		if b[i] == '_' {
			b[i] = ' '
		}
	}
	return string(b)
}

func apiNotFound(c *app.Ctx) { apiFail(404, "not_found", c.T("Not found.")) }

// pick keeps only these keys of a row, in that order.
func pick(r db.Row, keys ...string) *config.Map {
	out := config.NewMap()
	for _, k := range keys {
		out.Set(k, r[k])
	}
	return out
}

var machineKeys = []string{"id", "machine_code", "machine_name", "machine_group_name", "machine_type_name", "brand", "model", "serial_number", "machine_number",
	"machine_status", "check_status", "department_id", "is_calibration", "reset_period", "maintenance_period", "image_url", "note"}

func machineOut(c *app.Ctx, m db.Row) *config.Map {
	m = m.Copy()
	if php.Empty(m["image"]) {
		m["image_url"] = nil
	} else {
		m["image_url"] = c.UploadURL(m["image"])
	}
	return pick(m, machineKeys...)
}

// orderedRows: rows in their column order (the API sends what the SELECT lists).
func orderedRows(rows []db.OrderedRow) []any {
	out := make([]any, 0, len(rows))
	for _, r := range rows {
		m := config.NewMap()
		for i, k := range r.Cols {
			m.Set(k, r.Vals[i])
		}
		out = append(out, m)
	}
	return out
}

// POST {text: what the camera read} → the machine (JSON QR, a /machines/m/{code} link or a bare code).
func apiScan(c *app.Ctx) (any, int) {
	code := codeFromScan(php.Substr(app.InStr(c.InputAny("text")), 0, 500))
	if code == "" || machine(code) == nil {
		apiFail(404, "machine_not_found", c.T("No machine with this code."))
	}
	return apiMachine(c, code), 200
}

func apiQ(c *app.Ctx) string { return php.Substr(c.Query("q", ""), 0, 100) }

func apiLike(q string) string {
	r := []byte{}
	for i := 0; i < len(q); i++ {
		if q[i] == '\\' || q[i] == '%' || q[i] == '_' {
			r = append(r, '\\')
		}
		r = append(r, q[i])
	}
	return "%" + string(r) + "%"
}

// ?q= (2+ characters) → machines in use whose code, name, serial or number match.
func apiFind(c *app.Ctx) (any, int) {
	q := apiQ(c)
	if php.Len(q) < 2 {
		return om("data", []any{}), 200
	}
	like := apiLike(q)
	rows := mdb().Select(`SELECT m.*, t.machine_group_name, t.machine_type_name FROM machine m
            LEFT JOIN machine_type t ON t.machine_group_id = m.machine_group_id AND t.machine_type_id = m.machine_type_id AND t.deleted_at IS NULL
            WHERE m.deleted_at IS NULL AND m.machine_status IN (`+activeIn()+`) AND (m.machine_code ILIKE ? OR m.machine_name ILIKE ? OR m.serial_number ILIKE ? OR m.machine_number ILIKE ?)
            ORDER BY m.machine_code LIMIT 20`, like, like, like, like)
	out := []any{}
	for _, m := range rows {
		out = append(out, machineOut(c, m))
	}
	return om("data", out), 200
}

// The scan landing: machine, people, today's checklist, last checks, next maintenance / calibration.
func apiMachine(c *app.Ctx, code string) *config.Map {
	u := c.MustUser()
	m := machine(code)
	if m == nil {
		apiFail(404, "machine_not_found", c.T("No machine with this code."))
	}
	n := names(m["responsible_person_id"], m["supervisor_id"], m["manager_id"])
	person := func(id any) any {
		if php.Empty(id) {
			return nil
		}
		return om("id", php.Int(id), "name", nameOr(n, id, nil))
	}
	var checklist any
	if isActive(m["machine_status"]) {
		p := plan(m, u, php.Now())
		items := []any{}
		for _, i := range p.Items {
			choice := i.Bool("is_choice")
			var choices any
			kind := "text"
			if choice {
				kind, choices = "choice", []string{"OK", "NG", "NA"}
			}
			items = append(items, om("id", i.Int("id"), "question", i["detail"], "description", i["description"], "answer_type", kind, "choices", choices))
		}
		checklist = om("can_check", !p.Empty && u.Can("machines_checklists", "create"), "recheck", p.Recheck, "weekend", p.Weekend, "machine_statuses", active, "items", items)
	}
	return om("data", om(
		"machine", machineOut(c, m),
		"people", om("responsible", person(m["responsible_person_id"]), "supervisor", person(m["supervisor_id"]), "manager", person(m["manager_id"])),
		"checklist", checklist,
		"last_checks", orderedRows(mdb().SelectOrdered("SELECT id, check_type, recheck, checklist_status, machine_status, user_name, created_at FROM checklist_record WHERE machine_code = ? ORDER BY created_at DESC LIMIT 5", m["machine_code"])),
		"next_maintenance", mdb().Scalar("SELECT due_date FROM maintenance_record WHERE machine_code = ? AND actual_date IS NULL AND NOT is_canceled ORDER BY due_date LIMIT 1", m["machine_code"]),
		"next_calibration", mdb().Scalar("SELECT due_date FROM calibration_record WHERE machine_code = ? AND certificate_date IS NULL AND NOT is_canceled ORDER BY due_date LIMIT 1", m["machine_code"]),
	))
}

// apiInput is Controller::input($allowed): only these keys of the request body.
func apiInput(c *app.Ctx, allowed ...string) *config.Map {
	all := c.All()
	out := config.NewMap()
	for _, k := range all.Keys {
		if php.InList(k, allowed...) {
			out.Set(k, all.Vals[k])
		}
	}
	return out
}

/*
apiCheck saves today's check. JSON {machine_status, answers: {<item id>: {answer, remark}}, machine_note}
or multipart/form-data with the same fields plus "image" (photo, up to 2 MB).
*/
func apiCheck(c *app.Ctx) (any, int) {
	in := apiInput(c, "machine_status", "answers", "machine_note")
	in.Set("machine_code", c.Param("code"))
	c.Replace(in) // the website's own action sees only these fields
	id := saveCheck(c)
	c.Replace(nil)
	return apiRecord(c, id), 201
}

// My checks, newest first (?pending=1: re-checks waiting for my approval instead).
func apiChecklists(c *app.Ctx) (any, int) {
	apiNeed(c, "machines_checklists")
	u := c.MustUser()
	sql, params := "SELECT * FROM checklist_record WHERE created_by = ?", []any{u.ID}
	if c.Query("pending") == "1" {
		sql = "SELECT * FROM checklist_record WHERE (checklist_status = 'PENDING SUPERVISOR' AND supervisor = ?) OR (checklist_status = 'PENDING MANAGER' AND manager = ?)"
		params = []any{u.ID, u.ID}
	}
	per := max(1, min(100, php.Int(c.Query("per_page", "25"))))
	page := max(1, min(10000, php.Int(c.Query("page", "1"))))
	total := php.Int(mdb().Scalar("SELECT count(*) FROM ("+sql+") x", params...))
	rows := mdb().Select(sql+" ORDER BY created_at DESC, id DESC LIMIT "+php.Str(per)+" OFFSET "+php.Str((page-1)*per), params...)
	data := []any{}
	for _, r := range rows {
		data = append(data, recordOut(c, r, false))
	}
	return om("data", data, "meta", om("page", page, "per_page", per, "total", total, "pages", int(math.Ceil(float64(total)/float64(per))))), 200
}

func apiRecord(c *app.Ctx, id int) *config.Map {
	apiNeed(c, "machines_checklists")
	u := c.MustUser()
	r := mdb().First("SELECT * FROM checklist_record WHERE id = ?", id)
	if r == nil {
		apiNotFound(c)
	}
	if r.Int("created_by") != u.ID && !canApprove(r, u) && !canSee(u, r.Str("machine_code")) {
		apiNotFound(c)
	}
	return om("data", recordOut(c, r, true))
}

func recordOut(c *app.Ctx, r db.Row, full bool) *config.Map {
	out := pick(r, "id", "check_type", "recheck", "machine_code", "machine_name", "machine_status", "checklist_status", "machine_note", "user_name", "created_at",
		"date_supervisor_checked", "date_manager_checked", "reason_not_checked")
	out.Set("can_approve", canApprove(r, c.MustUser()))
	if full {
		ans := []any{}
		for _, a := range jsonList(r["machine_checklist"]) {
			ans = append(ans, om("question", a["detail"], "answer", a["answer"], "remark", a["remark"]))
		}
		out.Set("answers", ans)
		if php.Empty(r["image"]) {
			out.Set("image_url", nil)
		} else {
			out.Set("image_url", c.UploadURL(r["image"]))
		}
	}
	return out
}

// Approve a re-check that waits for me (supervisor, then manager). {note?}
func apiApprove(c *app.Ctx) (any, int) {
	apiNeed(c, "machines_checklists")
	id := c.ParamInt("id")
	r := mdb().First("SELECT * FROM checklist_record WHERE id = ?", id)
	if r == nil {
		apiNotFound(c)
	}
	if !canApprove(r, c.MustUser()) {
		apiFail(403, "not_waiting_for_you", c.T("This checklist is not waiting for you."))
	}
	note := php.Substr(app.InStr(apiInput(c, "note").Vals["note"]), 0, 500)
	c.Replace(om("note", note))
	// the website's approve: same checks (view permission, visible record) and the same save
	c.Authorize("machines_checklists", "view")
	rec := checklists.Find(c, id)
	approveCheck(c, id, rec)
	c.Replace(nil)
	return apiRecord(c, id), 200
}

// Maintenance and calibration of my machines that are overdue or due within ?days= (default 30, max 90).
func apiDue(c *app.Ctx) (any, int) {
	u := c.MustUser()
	days := max(1, min(90, php.Int(c.Query("days", "30"))))
	s, p := scope(u, "m")
	out := config.NewMap()
	if u.Can("machines_maintenance", "view") {
		out.Set("maintenance", orderedRows(mdb().SelectOrdered(`SELECT r.id, r.machine_code, r.machine_name, r.maintenance_type, r.due_date, r.plan_date, r.start_date, r.due_date < CURRENT_DATE AS overdue
                FROM maintenance_record r JOIN machine m ON m.machine_code = r.machine_code AND m.deleted_at IS NULL
                WHERE r.actual_date IS NULL AND NOT r.is_canceled AND r.due_date <= CURRENT_DATE + ?::int AND `+s+` ORDER BY r.due_date LIMIT 200`, append([]any{days}, p...)...)))
	}
	if u.Can("machines_calibration", "view") {
		out.Set("calibration", orderedRows(mdb().SelectOrdered(`SELECT r.id, r.machine_code, m.machine_name, r.due_date, r.start_date, r.due_date < CURRENT_DATE AS overdue
                FROM calibration_record r JOIN machine m ON m.machine_code = r.machine_code AND m.deleted_at IS NULL
                WHERE r.certificate_date IS NULL AND NOT r.is_canceled AND r.due_date <= CURRENT_DATE + ?::int AND `+s+` ORDER BY r.due_date LIMIT 200`, append([]any{days}, p...)...)))
	}
	if out.Len() == 0 {
		return om("data", []any{}), 200
	}
	return om("data", out), http.StatusOK
}
