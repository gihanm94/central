package crm

import (
	"html/template"
	"math"
	"strings"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/conv"
	"acme/internal/db"
)

// Kanban board of opportunities. One column per stage the person's department uses, cards load 10 at a time
// as a column is scrolled. Every card is visible to those who may see the opportunity, but only the person who
// created it can drag it to another stage. The person's own cards are highlighted (PipelineController).

const (
	pipePerColumn = 10
	pipeFrom      = "opportunities t LEFT JOIN leads l ON l.id = t.lead_id LEFT JOIN contacts ct ON ct.id = t.contact_id LEFT JOIN currencies cu ON cu.code = t.currency"
	pipeValue     = "COALESCE(t.amount, 0) * COALESCE(cu.rate_to_base, 1)"
)

// pipeFilter: WHERE for everything on the board: what the person may see + search / my records / department.
func pipeFilter(c *app.Ctx) (string, []any) {
	u := c.MustUser()
	vs, vp := visible(u, "t", "opportunity")
	where := []string{"t.deleted_at IS NULL", vs}
	params := append([]any{}, vp...)
	if q := strings.TrimSpace(c.Input("q")); q != "" {
		like := likePattern(q)
		where = append(where, "(t.name ILIKE ? OR t.code ILIKE ? OR l.name_en ILIKE ? OR l.name_th ILIKE ?)")
		params = append(params, like, like, like, like)
	}
	if c.Input("mine") == "1" {
		where = append(where, "t.owner_id = ?")
		params = append(params, u.ID)
	}
	if dep := conv.Int(c.Input("department", "0")); dep != 0 && seesAll(u) {
		where = append(where, "t.department_id = ?")
		params = append(params, dep)
	}
	return strings.Join(where, " AND "), params
}

type stageStat struct {
	N int
	V float64
}

func pipeStats(c *app.Ctx, stages []string) map[string]stageStat {
	where, params := pipeFilter(c)
	out := map[string]stageStat{}
	for _, s := range stages {
		out[s] = stageStat{}
	}
	for _, r := range db.On("crm").Select("SELECT t.opportunity_stage AS stage, count(*) AS n, COALESCE(sum("+pipeValue+"), 0) AS v FROM "+pipeFrom+" WHERE "+where+" GROUP BY 1", params...) {
		if _, ok := out[r.Str("stage")]; ok {
			out[r.Str("stage")] = stageStat{r.Int("n"), r.Float("v")}
		}
	}
	return out
}

func pipeRows(c *app.Ctx, stage string, page int, onlyID int) []db.Row {
	where, params := pipeFilter(c)
	if onlyID != 0 {
		where += " AND t.id = ?"
		params = append(params, onlyID)
	} else {
		where += " AND t.opportunity_stage = ?"
		params = append(params, stage)
	}
	rows := db.On("crm").Select(`SELECT t.id, t.code, t.name, t.amount, t.currency, t.probability, t.close_at, t.priority, t.owner_id, t.created_by, t.opportunity_stage, t.updated_at,
                                   l.name_en AS lead_name, l.name_th AS lead_name_th, l.image AS lead_image, ct.name_en AS contact_name
                              FROM `+pipeFrom+` WHERE `+where+` ORDER BY t.updated_at DESC, t.id DESC LIMIT `+conv.Str(pipePerColumn+1)+` OFFSET `+conv.Str((page-1)*pipePerColumn), params...)
	ns := names("users", append(col(rows, "owner_id"), col(rows, "created_by")...))
	for _, r := range rows {
		if n, ok := ns[r.Int("owner_id")]; ok {
			r["owner_name"] = n
		} else {
			r["owner_name"] = nil
		}
		if n, ok := ns[r.Int("created_by")]; ok {
			r["creator_name"] = n
		} else {
			r["creator_name"] = nil
		}
	}
	return rows
}

func pipeHTML(c *app.Ctx, rows []db.Row, stages []string) string {
	u := c.MustUser()
	var b strings.Builder
	canEdit := c.Can("crm_opportunities", "edit")
	for _, o := range rows {
		mine := o.Int("created_by") == u.ID || o.Int("owner_id") == u.ID
		movable := canEdit && o.Int("created_by") == u.ID && !inStrs(o.Str("opportunity_stage"), stageFinal)
		late := o.Str("close_at") != "" && conv.StrToTime(o["close_at"]) < conv.StrToTime(conv.Today()) && inStrs(o.Str("opportunity_stage"), stageOpen)
		creator := "—"
		if o["creator_name"] != nil {
			creator = o.Str("creator_name")
		}
		owner, avatarName := "—", "?"
		if o["owner_name"] != nil {
			owner = o.Str("owner_name")
			avatarName = owner
		}
		var lead template.HTML
		if o.Str("lead_name") != "" {
			lead = h(uiPerson(c, o.Str("lead_name"), conv.OrStr(o.Str("lead_name_th"), o.Str("contact_name")), o.Str("lead_image"), "", ""))
		}
		var opts []app.M
		for _, code := range stages {
			if code != o.Str("opportunity_stage") {
				opts = append(opts, app.M{"Code": code, "Color": stageColor(c, code), "Label": stageLabel(c, code)})
			}
		}
		b.WriteString(string(c.Partial("crm/pipeline-card", app.M{"o": o, "mine": mine, "movable": movable, "late": late, "creator": creator, "ownerName": owner, "lead": lead,
			"stages": opts, "prob": o["probability"] != nil, "probText": conv.NumberClean(o["probability"]), "money": h(uiMoney(o["amount"], o.Str("currency"))),
			"closeShort": app.FormatDate(c, o["close_at"], "d M"), "ownerAvatar": avatarName,
		})))
	}
	return b.String()
}

func pipelineIndex(c *app.Ctx) {
	c.Authorize("crm_opportunities", "view")
	u := c.MustUser()
	stages := stagesAvailableFor(u)
	stats := pipeStats(c, stages)
	cur := baseCurrency()
	type col struct {
		Code, Color, Label, Count, Sum string
		HTML                           template.HTML
		More, HasHTML                  bool
	}
	var cols []col
	for _, code := range stages {
		rows := pipeRows(c, code, 1, 0)
		more := len(rows) > pipePerColumn
		if more {
			rows = rows[:pipePerColumn]
		}
		html := pipeHTML(c, rows, stages)
		cols = append(cols, col{Code: code, Color: stageColor(c, code), Label: stageLabel(c, code), Count: conv.Str(stats[code].N),
			Sum: cur + " " + uiCompact(stats[code].V), HTML: h(html), More: more, HasHTML: html != ""})
	}
	canEdit := c.Can("crm_opportunities", "edit")
	dep := conv.Int(c.Input("department", "0"))
	mine := c.Input("mine") == "1"
	link := func(m string) string {
		q := app.M{"q": strings.TrimSpace(c.Input("q")), "department": nilZero(dep), "mine": nil}
		if m != "" {
			q["mine"] = 1
		}
		return c.URL("/crm/pipeline", map[string]any(q))
	}
	desc := c.T("Cards with a red edge are yours.")
	if canEdit {
		desc = c.T("Drag your own cards to another stage. Cards with a red edge are yours; the rest belong to colleagues and are locked.")
	}
	c.View("crm/pipeline", app.M{"title": c.T("Pipeline"), "cols": cols, "desc": desc, "all": seesAll(u), "deps": departments(), "q": strings.TrimSpace(c.Input("q")), "mine": mine,
		"dep": ternaryStr(dep != 0, conv.Str(dep), ""), "canEdit": canEdit, "needsReason": strings.Join(stageNeedsReason, ","), "linkAll": link(""), "linkMine": link("1")})
}

func ternaryStr(c bool, a, b string) string {
	if c {
		return a
	}
	return b
}

// pipelineCards: next page of one column (endless scroll).
func pipelineCards(c *app.Ctx) {
	c.Authorize("crm_opportunities", "view")
	stages := stagesAvailableFor(c.MustUser())
	stage := c.Input("stage")
	if !inStrs(stage, stages) {
		c.JSON(app.M{"html": "", "more": false})
	}
	rows := pipeRows(c, stage, max(1, conv.Int(c.Input("page", "1"))), 0)
	more := len(rows) > pipePerColumn
	if more {
		rows = rows[:pipePerColumn]
	}
	c.JSON(app.M{"html": pipeHTML(c, rows, stages), "more": more})
}

// pipelineMove: drag and drop (or the card's "Move to" menu).
func pipelineMove(c *app.Ctx) {
	c.Authorize("crm_opportunities", "edit")
	u := c.MustUser()
	id := conv.Int(c.Input("id"))
	stage := c.Input("stage")
	note := strings.TrimSpace(c.Input("note"))
	row := opportunities.openRecord(c, id)
	if row.Int("created_by") != u.ID {
		name := c.T("its creator")
		if row["creator_name"] != nil {
			name = row.Str("creator_name")
		}
		c.JSON(app.M{"message": c.T("Only :name, who created this opportunity, can move it.", "name", name)}, 403)
	}
	if stage == row.Str("opportunity_stage") {
		c.JSON(app.M{"message": c.T("It is already in this stage.")}, 422)
	}
	if inStrs(row.Str("opportunity_stage"), stageFinal) {
		c.JSON(app.M{"message": c.T("This opportunity is closed; its stage can no longer be changed. Duplicate it to start a new one.")}, 422)
	}
	if err := app.Catch(func() { checkMove(c, stage, note) }); err != nil {
		if ve, ok := err.(*app.ValidationError); ok {
			c.JSON(app.M{"message": ve.Errors.First()}, 422)
		}
		panic(err)
	}
	changeStage(c, row, stage, note, true)
	stages := stagesAvailableFor(u)
	fresh := pipeRows(c, stage, 1, id)
	html := ""
	if len(fresh) > 0 {
		html = pipeHTML(c, fresh[:1], stages)
	}
	st := pipeStats(c, stages)
	stats := config.NewMap()
	for _, k := range stages {
		stats.Set(k, app.M{"n": st[k].N, "v": st[k].V})
	}
	// ordered like the PHP answer (a Go map would sort the keys)
	ans := config.NewMap()
	ans.Set("ok", true)
	ans.Set("stats", stats)
	ans.Set("html", html)
	ans.Set("message", c.T("Moved to :stage.", "stage", stageLabel(c, stage)))
	c.JSON(ans)
}

var _ = math.Abs
