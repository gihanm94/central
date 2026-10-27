package core

import (
	"regexp"
	"strings"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/everbetter"
	"acme/internal/php"
)

/*
User Management → Everbetter (EverbetterController): my improvement projects (as owner or member), their presentations
to Management, and the "Present" request (Project Initiation, Closure or Extend time) for a quarter's presentation day.
*/

func ebIndex(c *app.Ctx) {
	c.Authorize("everbetter", "view")
	u := c.MustUser()
	y, q := everbetter.QuarterOf("")
	var next db.Row
	if s := everbetter.UpcomingSessions(); len(s) > 0 {
		next = s[0]
	}
	session := everbetter.Session(y, q)
	day := next
	if day == nil {
		day = session
	}
	projects := everbetter.Projects(u, "", "", true)
	c.View("everbetter/index", app.M{"title": c.T("Everbetter"), "projects": ebCards(c, projects, 8), "day": day})
}

// ebCards adds what the project cards and rows show (status badge, last request label, first members).
func ebCards(c *app.Ctx, rows []db.Row, show int) []db.Row {
	kinds := everbetter.KindsT(c)
	for _, p := range rows {
		ms := everbetter.Members(p)
		n := min(show, len(ms))
		p["shown"] = ms[:n]
		p["more"] = len(ms) - n
		if last, ok := p["last_request"].(db.Row); ok && last != nil {
			l, tone := everbetter.StateOf(c, last)
			p["last_label"] = l
			p["last_tone"] = tone
			p["last_kind"] = kinds[last.Str("kind")]
		}
	}
	return rows
}

// ebPeople: every active person [id => {label, sub, img}] for the member picker.
func ebPeople(c *app.Ctx) ([]app.Opt, map[string]app.Opt) {
	var opts []app.Opt
	byID := map[string]app.Opt{}
	for _, r := range db.Select(`SELECT u.id, u.name, u.avatar, d.name AS department FROM users u LEFT JOIN departments d ON d.id = u.department_id
                                     WHERE u.deleted_at IS NULL AND u.is_active ORDER BY u.name`) {
		o := app.Opt{Value: r.Str("id"), Label: r.Str("name"), Sub: r.Str("department")}
		if r.Str("avatar") != "" {
			o.Img = c.UploadURL(r["avatar"])
		}
		opts = append(opts, o)
		byID[o.Value] = o
	}
	return opts, byID
}

func ebForm(c *app.Ctx, p db.Row, memberIDs []int) {
	opts, byID := ebPeople(c)
	names := config.NewMap()
	for _, o := range opts {
		names.Set(o.Value, o.Label)
	}
	type member struct {
		ID    int
		Label string
		Owner bool
	}
	var members []member
	for _, mid := range memberIDs {
		o, ok := byID[php.Str(mid)]
		if !ok {
			continue
		}
		owner := mid == c.UserID()
		if p != nil {
			owner = p.Int("owner_id") == mid
		}
		members = append(members, member{mid, o.Label, owner})
	}
	title := c.T("New Everbetter project")
	locked := false
	if p != nil {
		title = c.T("Edit project")
		locked = !php.InList(p.Str("status"), "draft", "rejected")
	}
	c.View("everbetter/form", app.M{"title": title, "p": p, "people": opts, "names": names, "members": members, "locked": locked})
}

func ebCreate(c *app.Ctx) {
	c.Authorize("everbetter", "create")
	ebForm(c, nil, []int{c.UserID()})
}

var ebDate = regexp.MustCompile(`^\d{4}-\d{2}-\d{2}$`)

func ebFormData(c *app.Ctx) db.Cols {
	title := strings.TrimSpace(c.Input("title"))
	var desc any
	if d := strings.TrimSpace(c.Input("description")); d != "" {
		desc = d
	}
	start, end := c.Input("start_date"), c.Input("end_date")
	errs := app.NewErrors()
	if title == "" || php.Len(title) > 200 {
		errs.Add("title", c.T("Write the project title (up to 200 characters)."))
	}
	for _, kv := range [][2]string{{"start_date", start}, {"end_date", end}} {
		k, v := kv[0], kv[1]
		if !ebDate.MatchString(v) || php.StrToTime(v) == 0 {
			errs.Add(k, c.T("Choose a date."))
		}
	}
	if errs.Len() == 0 && end < start {
		errs.Add("end_date", c.T("The end date cannot be before the start date."))
	}
	if errs.Len() > 0 {
		app.InvalidErrors(errs)
	}
	return db.Cols{{Name: "title", Value: title}, {Name: "description", Value: desc}, {Name: "start_date", Value: start}, {Name: "end_date", Value: end}}
}

func ebMemberInput(c *app.Ctx, first int) []int {
	out := []int{first}
	for _, s := range c.Inputs("members") {
		out = append(out, php.Int(s))
	}
	return out
}

func ebStore(c *app.Ctx) {
	c.Authorize("everbetter", "create")
	u := c.MustUser()
	d := ebFormData(c)
	id := db.Core.InsertID("everbetter_projects", append(d, db.Col{Name: "owner_id", Value: u.ID}, db.Col{Name: "owner_name", Value: u.Name}, db.Col{Name: "department_id", Value: u.DeptID()}))
	everbetter.SetMembers(id, ebMemberInput(c, u.ID))
	title := php.Str(d[0].Value)
	app.LogActivity(c, app.ActivityEntry{Action: "created", Type: "everbetter", ID: id, Label: title, Text: `Created the Everbetter project ":label"`, Params: []any{"label", title}, NoNotify: true})
	c.Flash("success", c.T("Project created. Ask to present it when you are ready."))
	c.Redirect("/everbetter/" + php.Str(id))
}

func ebProject(c *app.Ctx, id int) db.Row {
	p := everbetter.Find(c.MustUser(), id)
	if p == nil {
		app.Abort(404, c.T("This project is not available."))
	}
	return p
}

func ebShow(c *app.Ctx) {
	c.Authorize("everbetter", "view")
	u := c.MustUser()
	id := c.ParamInt("id")
	p := ebProject(c, id)
	presentations := everbetter.Presentations(id)
	kinds := everbetter.KindsT(c)
	presented := 0
	for _, x := range presentations {
		if x["result"] != nil {
			presented++
		}
		x["state"], x["tone"] = everbetter.StateOf(c, x)
		x["kind_label"] = kinds[x.Str("kind")]
	}
	open := everbetter.Open(id)
	if open != nil {
		open["state"], open["tone"] = everbetter.StateOf(c, open)
		open["kind_label"] = kinds[open.Str("kind")]
	}
	sessions := everbetter.UpcomingSessions()
	var sessOpts []app.Opt
	for _, s := range sessions {
		l := c.T("Q:q :y · :date", "q", s["quarter"], "y", s["year"], "date", app.FormatDate(c, s["present_on"], "D d M Y"))
		if s.Str("place") != "" {
			l += " · " + s.Str("place")
		}
		sessOpts = append(sessOpts, app.Opt{Value: s.Str("id"), Label: l})
	}
	allowed := everbetter.AllowedKinds(p)
	help := map[string]string{"initiation": c.T("Start: what you will improve, the plan and the team. Approval starts the project."),
		"closure":   c.T("Finish: the results. Approval closes the project."),
		"extension": c.T("More time: why, and the new end date. Approval moves the end date.")}
	type kindOpt struct{ Key, Label, Help string }
	var kopts []kindOpt
	for _, k := range allowed {
		kopts = append(kopts, kindOpt{k, kinds[k], help[k]})
	}
	minEnd := ""
	if t, ok := php.ParseTime(p.Str("end_date")); ok {
		minEnd = t.AddDate(0, 0, 1).Format("2006-01-02")
	}
	firstSession := ""
	if len(sessions) > 0 {
		firstSession = sessions[0].Str("id")
	}
	c.View("everbetter/show", app.M{"title": p["title"], "p": p, "presentations": presentations, "open": open, "sessions": sessOpts, "firstSession": firstSession,
		"canEdit": everbetter.CanEdit(u, p), "allowed": kopts, "presented": presented, "members": everbetter.Members(p),
		"extFirst": len(allowed) > 0 && allowed[0] == "extension", "minEnd": minEnd})
}

func ebEdit(c *app.Ctx) {
	c.Authorize("everbetter", "edit")
	p := ebProject(c, c.ParamInt("id"))
	if !everbetter.CanEdit(c.MustUser(), p) {
		app.Abort(403)
	}
	var ids []int
	for _, m := range everbetter.Members(p) {
		ids = append(ids, m.Int("id"))
	}
	ebForm(c, p, ids)
}

func ebUpdate(c *app.Ctx) {
	c.Authorize("everbetter", "edit")
	id := c.ParamInt("id")
	p := ebProject(c, id)
	if !everbetter.CanEdit(c.MustUser(), p) {
		app.Abort(403)
	}
	d := ebFormData(c)
	if p.Str("status") != "draft" && p.Str("status") != "rejected" { // approved: the dates change only through Extend time
		d = d[:2]
	}
	db.Core.Update("everbetter_projects", append(d, db.Col{Name: "updated_at", Value: php.NowString()}), db.Cols{{Name: "id", Value: id}})
	everbetter.SetMembers(id, ebMemberInput(c, p.Int("owner_id")))
	title := php.Str(d[0].Value)
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "everbetter", ID: id, Label: title, Text: `Edited the Everbetter project ":label"`, Params: []any{"label", title}, NoNotify: true})
	c.Flash("success", c.T("Project saved."))
	c.Redirect("/everbetter/" + php.Str(id))
}

func ebPresent(c *app.Ctx) {
	c.Authorize("everbetter", "view")
	u := c.MustUser()
	id := c.ParamInt("id")
	p := ebProject(c, id)
	if !everbetter.CanEdit(u, p) {
		app.Abort(403)
	}
	everbetter.Request(c, u, p, php.Int(c.Input("session_id")), c.Input("kind"), c.Input("new_end_date"), c.Input("note"))
	c.Flash("success", c.T("Request sent. Management has been told and will accept it."))
	c.Redirect("/everbetter/" + php.Str(id))
}

func ebWithdraw(c *app.Ctx) {
	c.Authorize("everbetter", "view")
	u := c.MustUser()
	id := c.ParamInt("id")
	p := ebProject(c, id)
	if !everbetter.CanEdit(u, p) {
		app.Abort(403)
	}
	x := everbetter.Presentation(c.ParamInt("rid"))
	if x == nil || x.Int("project_id") != id {
		app.Abort(404)
	}
	everbetter.Withdraw(c, x)
	c.Flash("success", c.T("Request withdrawn."))
	c.Redirect("/everbetter/" + php.Str(id))
}

func init() {
	signedIn(func(r app.Router) {
		r.Get("/everbetter", ebIndex)
		r.Get("/everbetter/new", ebCreate)
		r.Post("/everbetter", ebStore)
		r.Get("/everbetter/{id}", ebShow)
		r.Get("/everbetter/{id}/edit", ebEdit)
		r.Post("/everbetter/{id}", ebUpdate)
		r.Post("/everbetter/{id}/present", ebPresent)
		r.Post("/everbetter/{id}/requests/{rid:[0-9]+}/withdraw", ebWithdraw)
	})
}
