package crm

import (
	"net/http"
	"net/url"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

// CRM → Manager → Sales control, for a BU manager (own department) or an admin (everybody). Two views:
//  - By person: pick someone, see every record they have, hand them to somebody else (with a reason) or change many at once.
//  - By stage: how many opportunities sit in each stage within a date range; pick a stage, tick the stale ones and move them on.
// Nothing is lost: the record stays as it is and the previous owner / stage is kept in its history (TeamController).

func teamGate(c *app.Ctx, action string) { c.Authorize("crm_reassign", action) }

// teamMembers: people the signed-in person can look at: everybody for whole-company roles, the own department otherwise.
func teamMembers(c *app.Ctx) []person {
	u := c.MustUser()
	var out []person
	for _, p := range everyone() {
		if seesAll(u) || (u.DepartmentID != 0 && p.DepartmentID == u.DepartmentID) {
			out = append(out, p)
		}
	}
	return out
}

// teamMoved: the old address of this screen.
func teamMoved(c *app.Ctx) {
	q := url.Values{}
	for _, k := range []string{"user", "type"} {
		if v := c.Query(k); v != "" {
			q.Set(k, v)
		}
	}
	to := "/crm/control"
	if len(q) > 0 {
		to += "?" + q.Encode()
	}
	c.Redirect(to)
}

func teamIndex(c *app.Ctx) {
	teamGate(c, "view")
	u := c.MustUser()
	view := "person"
	if c.Query("view") == "stage" {
		view = "stage"
	}
	members := teamMembers(c)
	mm := peopleMap(members)
	types := reassignTypes(c)
	from := php.Int(c.Query("user", "0"))
	typ := c.Query("type", "opportunity")
	if _, ok := reassignType1(c, typ); !ok {
		typ = "opportunity"
	}
	t, _ := reassignType1(c, typ)
	openOnly := !c.QueryHas("all")
	if _, ok := mm[from]; !ok {
		from = 0
	}
	var options []app.Opt
	for _, p := range members {
		l := p.Name
		if p.Department != "" && seesAll(u) {
			l += " · " + p.Department
		}
		if !p.Active {
			l += " (" + c.T("inactive") + ")"
		}
		options = append(options, app.Opt{Value: php.Str(p.ID), Label: l})
	}
	targets := withoutOpt(assignable(u), php.Str(from))

	// By stage
	f := stageFilter{Owner: php.Int(c.Query("owner", "0")), From: c.Query("from"), To: c.Query("to"), Date: c.Query("date", "since")}
	if seesAll(u) {
		f.Department = php.Int(c.Query("department", "0"))
	}
	if _, ok := mm[f.Owner]; !ok {
		f.Owner = 0
	}
	if _, ok := stageDateSQL[f.Date]; !ok {
		f.Date = "since"
	}
	stage := c.Query("stage", "QUALIFICATION")
	if !isStage(stage) {
		stage = "QUALIFICATION"
	}
	all := everyone()
	pnames := map[string]string{}
	for _, p := range all {
		pnames[php.Str(p.ID)] = p.Name
	}
	openSQL := "SELECT count(*) FROM opportunities t WHERE t.deleted_at IS NULL AND t.opportunity_stage IN ('" + strings.Join(stageOpen, "','") + "')"
	if !seesAll(u) {
		openSQL += " AND t.department_id = " + php.Str(u.DepartmentID)
	}
	openCount := php.Int(db.On("crm").Scalar(openSQL))

	data := app.M{"title": c.T("Sales control"), "view": view, "stage": stage, "openCount": openCount, "wholeCompany": seesAll(u), "canEdit": c.Can("crm_reassign", "edit"),
		"from": from, "members": options, "type": typ, "tname": t.Name, "hasStatus": t.Status != "", "openOnly": openOnly, "targets": targets, "types": types}
	personName := ""
	personInactive := false
	if p, ok := mm[from]; ok {
		personName, personInactive = p.Name, !p.Active
	}
	data["personName"], data["personInactive"] = personName, personInactive
	qs := func(x app.M) string {
		q := app.M{"user": from, "type": typ, "all": nil}
		if !openOnly {
			q["all"] = 1
		}
		for k, v := range x {
			q[k] = v
		}
		return c.URL("/crm/control", map[string]any(q))
	}
	data["qsOpen"], data["qsAll"] = qs(app.M{"all": nil}), qs(app.M{"all": 1})
	data["personLink"] = c.URL("/crm/control", "user", ternaryStr(from != 0, php.Str(from), ""))
	data["stageLink"] = c.URL("/crm/control", "view", "stage")
	data["personMeta"] = c.T("Choose a person")
	if from != 0 {
		data["personMeta"] = personName
	}
	data["stageMeta"] = c.T(":n open opportunities", "n", openCount)
	if view == "person" && from != 0 {
		counts := reassignCounts(c, u, from)
		type tile struct {
			Name, URL   string
			Open, Total int
			On          bool
		}
		var tiles []tile
		for _, tt := range types {
			n := counts[tt.Key]
			tiles = append(tiles, tile{tt.Name, qs(app.M{"type": tt.Key}), n[0], n[1], tt.Key == typ})
		}
		data["tiles"] = tiles
		rows := recordsOf(u, t, from, openOnly, 300)
		path := entities[typ].Path
		for _, r := range rows {
			r["_url"] = c.URL("/crm/" + path + "/" + r.Str("id"))
			if t.Status != "" {
				if typ == "opportunity" {
					r["_badge"] = h(uiStageBadge(c, r.Str("status")))
				} else {
					l, _ := app.OptLabel(t.Statuses, r.Str("status"))
					if l == "" {
						l = r.Str("status")
					}
					r["_badge"] = h(uiBadge(l, "neutral"))
				}
			}
			r["_ago"] = app.TimeAgo(c, r["updated_at"])
		}
		data["rows"] = rows
		data["statuses"] = t.Statuses
		data["limitNote"] = len(rows) >= 300
	}
	if view == "stage" {
		stageViewData(c, data, f, stage, pnames, options)
	}
	c.View("crm/team", data)
}

func stageViewData(c *app.Ctx, data app.M, f stageFilter, stage string, pnames map[string]string, members []app.Opt) {
	u := c.MustUser()
	deps := departments()
	counts := stageCounts(u, f)
	rows := stageRows(u, stage, f, 300)
	depNames := map[string]string{}
	for _, d := range deps {
		depNames[d.Value] = d.Label
	}
	ago := func(d int) string { return php.Date("Y-m-d", php.Now().AddDate(0, 0, -d)) }
	sq := func(x app.M) string {
		q := app.M{"view": "stage", "stage": stage, "department": nilZero(f.Department), "owner": nilZero(f.Owner), "from": f.From, "to": f.To, "date": f.Date}
		for k, v := range x {
			q[k] = v
		}
		if q["date"] == "since" {
			q["date"] = nil
		}
		return c.URL("/crm/control", map[string]any(q))
	}
	type tile struct {
		Code, Label, URL, Amount string
		N                        int
		On, Open                 bool
	}
	var tiles []tile
	for _, s := range stagesDef {
		cn := counts[s.Code]
		tiles = append(tiles, tile{s.Code, c.T(s.Label), sq(app.M{"stage": s.Code}), php.NF(cn[1], 0), int(cn[0]), s.Code == stage, inStrs(s.Code, stageOpen)})
	}
	type chip struct {
		Days int
		URL  string
		On   bool
	}
	var chips []chip
	for _, d := range []int{30, 60, 90, 180} {
		chips = append(chips, chip{d, sq(app.M{"date": "since", "from": nil, "to": ago(d)}), f.Date == "since" && f.From == "" && f.To == ago(d)})
	}
	for _, r := range rows {
		r["_url"] = c.URL("/crm/opportunities/" + r.Str("id"))
		r["_ref"] = strings.Trim(r.Str("code")+func() string {
			if r.Str("customer") != "" {
				return " · " + r.Str("customer")
			}
			return ""
		}(), " ·")
		r["_owner"] = php.OrStr(pnames[r.Str("owner_id")], "—")
		r["_dept"] = php.OrStr(depNames[r.Str("department_id")], "—")
		if r["amount"] != nil {
			r["_amount"] = php.NF(r.Float("amount"), 2) + " " + r.Str("currency")
		} else {
			r["_amount"] = "—"
		}
		r["_since"] = app.FormatDate(c, r["since"], "d M Y")
		r["_created"] = app.FormatDate(c, r["created_at"], "d M Y")
		r["_ago"] = app.TimeAgo(c, r["updated_at"])
		if r.Str("since") != "" {
			d := int(max(0, (php.Now().Unix()-php.StrToTime(r["since"]))/86400))
			cl := "bg-mist text-steel"
			if d >= 90 {
				cl = "bg-signal-50 text-signal-800"
			} else if d >= 30 {
				cl = "bg-amber-50 text-amber-800"
			}
			r["_days"], r["_dayClass"] = c.T(":n days", "n", d), cl
		}
	}
	var dateOpts []app.Opt
	for _, d := range stageDates(c) {
		dateOpts = append(dateOpts, app.Opt{Value: d.K, Label: d.L})
	}
	ret := "/crm/control?" + func() string { u, _ := url.Parse(sq(nil)); return u.RawQuery }()
	otherStages := []app.Opt{}
	for _, o := range stageOptions(c) {
		if o.Value != stage {
			otherStages = append(otherStages, o)
		}
	}
	data["f"] = app.M{"department": ternaryStr(f.Department != 0, php.Str(f.Department), ""), "owner": ternaryStr(f.Owner != 0, php.Str(f.Owner), ""), "from": f.From, "to": f.To, "date": f.Date}
	data["deps"], data["dateOpts"], data["stageTiles"], data["chips"], data["stageRows"] = deps, dateOpts, tiles, chips, rows
	data["hasFilters"] = f.From != "" || f.To != "" || f.Owner != 0 || f.Department != 0 || f.Date != "since"
	data["clearURL"] = c.URL("/crm/control", "view", "stage", "stage", stage)
	data["return"] = ret
	data["stageLabel"] = stageLabel(c, stage)
	data["otherStages"] = otherStages
	data["stageLimit"] = len(rows) >= 300
	data["statusOld"] = php.Str(oldOr(c, "status"))
	data["noteOld"] = php.Str(oldOr(c, "status_note"))
}

func oldOr(c *app.Ctx, k string) any {
	if o, ok := oldVal(c, k); ok {
		return o
	}
	return ""
}

func teamBack(c *app.Ctx, from int, typ string) {
	ret := c.Input("return") // the By stage view sends its own address (filters kept)
	if strings.HasPrefix(ret, "/crm/control?") {
		c.Redirect(ret)
	}
	c.Redirect("/crm/control?user=" + php.Str(from) + "&type=" + typ)
}

// teamReason: a reason is required whenever records change hands.
func teamReason(c *app.Ctx, field string) string {
	note := strings.TrimSpace(c.Input(field))
	if note == "" {
		app.Invalid(field, c.T("Write the reason for moving them."))
	}
	return php.Substr(note, 0, 250)
}

func ints(vals []string) []int {
	var out []int
	for _, v := range vals {
		out = append(out, php.Int(v))
	}
	return out
}

// teamReassign: hand the ticked records to another person.
func teamReassign(c *app.Ctx) {
	teamGate(c, "edit")
	typ := c.Input("type")
	ids := ints(c.Inputs("ids"))
	to := php.Int(c.Input("to"))
	from := php.Int(c.Input("from"))
	if _, ok := reassignType1(c, typ); !ok || to == 0 {
		app.Invalid("to", c.T("Choose who should get them."))
	}
	if len(ids) == 0 {
		c.Flash("error", c.T("Tick at least one record first."))
		teamBack(c, from, typ)
	}
	moved, skipped := reassignApply(c, c.MustUser(), typ, ids, to, from, teamReason(c, "note"), c.Bool("move_department"))
	msg := c.T(":n moved.", "n", moved)
	if skipped > 0 {
		msg += " " + c.T(":n skipped (not yours to change, or already theirs).", "n", skipped)
	}
	c.Flash("success", msg)
	teamBack(c, from, typ)
}

// teamHandover: somebody left: everything they have (all types) goes to one person.
func teamHandover(c *app.Ctx) {
	teamGate(c, "edit")
	from := php.Int(c.Input("from"))
	to := php.Int(c.Input("to"))
	if from == 0 || to == 0 || from == to {
		app.Invalid("to", c.T("Choose who should get them."))
	}
	openOnly := !c.Bool("include_closed")
	note := teamReason(c, "note")
	moved, skipped := 0, 0
	for _, t := range reassignTypes(c) {
		var ids []int
		for _, r := range recordsOf(c.MustUser(), t, from, openOnly, 5000) {
			ids = append(ids, r.Int("id"))
		}
		if len(ids) > 0 {
			m, s := reassignApply(c, c.MustUser(), t.Key, ids, to, from, note, c.Bool("move_department"))
			moved += m
			skipped += s
		}
	}
	msg := c.T(":n moved.", "n", moved)
	if skipped > 0 {
		msg += " " + c.T(":n skipped (not yours to change, or already theirs).", "n", skipped)
	}
	c.Flash("success", msg)
	teamBack(c, from, c.Input("type", "opportunity"))
}

// teamStatus: move the ticked records to one stage / status, e.g. close every stale opportunity.
func teamStatus(c *app.Ctx) {
	teamGate(c, "edit")
	u := c.MustUser()
	typ := c.Input("type")
	seen := map[int]bool{}
	var ids []int
	for _, i := range ints(c.Inputs("ids")) {
		if !seen[i] {
			seen[i] = true
			ids = append(ids, i)
		}
	}
	status := c.Input("status")
	note := strings.TrimSpace(c.Input("status_note"))
	from := php.Int(c.Input("from"))
	t, ok := reassignType1(c, typ)
	if !ok || t.Status == "" {
		app.Abort(http.StatusNotFound)
	}
	if !optHas(t.Statuses, status) {
		app.Invalid("status", c.T("Pick a status."))
	}
	if len(ids) == 0 {
		c.Flash("error", c.T("Tick at least one record first."))
		teamBack(c, from, typ)
	}
	if typ == "opportunity" {
		checkMove(c, status, note) // the stage must be allowed for this department, and a reason is given when needed
	}
	colName := strings.TrimPrefix(t.Status, "t.")
	moved, skipped := 0, 0
	byOwner := map[int]int{}
	var order []int
	statusLabel, _ := app.OptLabel(t.Statuses, status)
	for _, id := range ids {
		row := db.On("crm").First("SELECT * FROM "+t.Table+" WHERE id = ? AND deleted_at IS NULL", id)
		if row == nil || !reassignInScope(c, u, row) || row.Str(colName) == status {
			skipped++
			continue
		}
		label := row.Str(t.Label)
		if typ == "opportunity" {
			changeStage(c, row, status, note, false)
		} else {
			db.On("crm").Exec("UPDATE "+t.Table+" SET "+colName+" = ?, updated_by = ?, updated_at = now() WHERE id = ?", status, u.ID, id)
			fromLabel, _ := app.OptLabel(t.Statuses, row.Str(colName))
			if fromLabel == "" {
				fromLabel = row.Str(colName)
			}
			app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: typ, ID: id, Label: label, Text: `Changed :type ":label" to :status`,
				Params: []any{"type", typ, "label", label, "status", statusLabel},
				Props:  app.M{"changes": app.M{"status": app.M{"from": fromLabel, "to": statusLabel}}}, Owner: row.Int("owner_id"), NoNotify: true, Module: "crm"})
		}
		moved++
		if oid := row.Int("owner_id"); oid != 0 && oid != u.ID {
			if _, ok := byOwner[oid]; !ok {
				order = append(order, oid)
			}
			byOwner[oid]++
		}
	}
	for _, owner := range order { // one message per owner, not one per record
		n := note
		if n == "" {
			n = "—"
		}
		app.Deliver(c, "crm", "updated", owner,
			app.Text(":name changed :n of your :type to :status", "name", u.Name, "n", byOwner[owner], "type", t.Name, "status", statusLabel),
			app.Text(":note", "note", n), c.URL("/crm/"+entities[typ].Path+"?scope=mine"), u.ID)
	}
	msg := c.T(":n changed.", "n", moved)
	if skipped > 0 {
		msg += " " + c.T(":n skipped.", "n", skipped)
	}
	c.Flash("success", msg)
	teamBack(c, from, typ)
}

// teamOwner: one record, from its own page: change its owner.
func teamOwner(c *app.Ctx) {
	teamGate(c, "edit")
	typ := typeForPath(c.Param("kind"))
	if typ == "" {
		app.Abort(http.StatusNotFound)
	}
	id := c.ParamInt("id")
	to := php.Int(c.Input("to"))
	if db.On("crm").First("SELECT * FROM "+entities[typ].Table+" WHERE id = ? AND deleted_at IS NULL", id) == nil {
		app.Abort(http.StatusNotFound)
	}
	moved, _ := reassignApply(c, c.MustUser(), typ, []int{id}, to, 0, strings.TrimSpace(c.Input("note")), c.Bool("move_department"))
	if moved > 0 {
		c.Flash("success", c.T("Owner changed. The previous owner is kept in the history."))
	} else {
		c.Flash("error", c.T("Nothing changed."))
	}
	c.Redirect("/crm/" + entities[typ].Path + "/" + php.Str(id))
}
