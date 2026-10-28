package crm

import (
	"net/http"

	"acme/internal/app"
	"acme/internal/conv"
	"acme/internal/db"
)

// Things every CRM record can have: comments, files and sharing (RecordController).
// Routes look like /crm/{leads|contacts|opportunities|campaigns|activities|projects|tasks}/{id}/comments …
// The record itself is always opened through its own screen, so the "can this person see it" rule is the same everywhere.

func openKind(c *app.Ctx) (*screen, db.Row, int) {
	typ := typeForPath(c.Param("kind"))
	s := screens[typ]
	if typ == "" || s == nil {
		app.Abort(http.StatusNotFound)
	}
	id := c.ParamInt("id")
	return s, s.openRecord(c, id), id
}

func backTo(c *app.Ctx, s *screen, id int, anchor string) {
	c.Redirect("/crm/" + entities[s.entity].Path + "/" + conv.Str(id) + anchor)
}

func ownerOf(row db.Row) int { return row.Int("owner_id") }

// ---------------------------------------------------------------- comments

func commentsFor(c *app.Ctx, typ string, id int) []db.Row {
	rows := db.On("crm").Select("SELECT * FROM comments WHERE entity_type = ? AND entity_id = ? ORDER BY created_at DESC, id DESC", typ, id)
	ns := names("users", col(rows, "created_by"))
	for _, r := range rows {
		if n, ok := ns[r.Int("created_by")]; ok {
			r["author"] = n
		} else {
			r["author"] = c.T("Former member")
		}
	}
	return rows
}

func recordComment(c *app.Ctx) {
	s, row, id := openKind(c)
	if !s.comments {
		app.Abort(http.StatusNotFound)
	}
	data := c.ValidateInput(app.Rule{Field: "body", Rules: "required|max:5000", Label: c.T("comment")})
	u := c.MustUser()
	db.On("crm").Insert("comments", db.Cols{{Name: "entity_type", Value: s.entity}, {Name: "entity_id", Value: id}, {Name: "body", Value: data.Get("body")}, {Name: "created_by", Value: u.ID}})
	label := s.label(c, row)
	app.LogActivity(c, app.ActivityEntry{Action: "commented", Type: s.entity, ID: id, Label: label, Text: `Commented on :type ":label"`,
		Params: []any{"type", s.entity, "label", label}, Props: app.M{"comment": conv.Limit(data.Str("body"), 200)}, Owner: ownerOf(row), NoNotify: true, Module: "crm"})
	// The owner hears about it (bell, e-mail — whatever is switched on), unless they wrote it themselves.
	if row.Int("owner_id") != 0 && row.Int("owner_id") != u.ID {
		app.Deliver(c, "crm", "commented", row.Int("owner_id"),
			app.Text(`:name commented on :type ":label"`, "name", u.Name, "type", s.entity, "label", label),
			app.Text(":comment", "comment", conv.Limit(data.Str("body"), 300)),
			c.URL("/crm/"+entities[s.entity].Path+"/"+conv.Str(id)+"#comments"), u.ID)
	}
	c.Flash("success", c.T("Comment added."))
	backTo(c, s, id, "#comments")
}

func recordDeleteComment(c *app.Ctx) {
	s, _, id := openKind(c)
	cm := db.On("crm").First("SELECT * FROM comments WHERE id = ? AND entity_type = ? AND entity_id = ?", c.ParamInt("cid"), s.entity, id)
	if cm == nil {
		app.Abort(http.StatusNotFound)
	}
	u := c.MustUser()
	if cm.Int("created_by") != u.ID && !u.IsAdmin() {
		app.Abort(http.StatusForbidden, c.T("You can only delete your own comments."))
	}
	db.On("crm").Exec("DELETE FROM comments WHERE id = ?", cm["id"])
	c.Flash("success", c.T("Comment deleted."))
	backTo(c, s, id, "#comments")
}

// ---------------------------------------------------------------- files

func recordUpload(c *app.Ctx) {
	s, row, id := openKind(c)
	if !s.files {
		app.Abort(http.StatusNotFound)
	}
	if !s.allows(c, row, "edit") {
		app.Abort(http.StatusForbidden, c.T("You can't add files to this record."))
	}
	files := c.Files("files")
	if len(files) == 0 {
		app.Invalid("files", c.T("Choose at least one file."))
	}
	var ns []any
	for _, f := range files {
		ns = append(ns, attStore(c, f, s.entity, id, c.MustUser().ID).Str("original_name"))
	}
	label := s.label(c, row)
	app.LogActivity(c, app.ActivityEntry{Action: "attached", Type: s.entity, ID: id, Label: label, Text: `Attached :count file(s) to :type ":label"`,
		Params: []any{"count", len(ns), "type", s.entity, "label", label}, Props: app.M{"files": ns}, Owner: ownerOf(row), NoNotify: true, Module: "crm"})
	c.Flash("success", c.T(":count file(s) uploaded.", "count", len(ns)))
	backTo(c, s, id, "#files")
}

func recordFile(c *app.Ctx, s *screen, id int) db.Row {
	f := db.On("crm").First("SELECT * FROM attachments WHERE id = ? AND entity_type = ? AND entity_id = ?", c.ParamInt("fid"), s.entity, id)
	if f == nil {
		app.Abort(http.StatusNotFound)
	}
	return f
}

func recordDownload(c *app.Ctx) {
	s, row, id := openKind(c)
	f := recordFile(c, s, id)
	app.LogActivity(c, app.ActivityEntry{Action: "download", Type: s.entity, ID: id, Label: f.Str("original_name"), Text: `Downloaded file ":label"`,
		Params: []any{"label", f.Str("original_name")}, Owner: ownerOf(row), NoNotify: true, Module: "crm"})
	attSend(c, f)
}

func recordDeleteFile(c *app.Ctx) {
	s, row, id := openKind(c)
	f := recordFile(c, s, id)
	if f.Int("uploaded_by") != c.MustUser().ID && !isManager(c.MustUser(), row) {
		app.Abort(http.StatusForbidden, c.T("Only the person who uploaded it or the record owner can remove a file."))
	}
	attRemove(f)
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: s.entity, ID: id, Label: s.label(c, row), Text: `Removed file ":label"`,
		Params: []any{"label", f.Str("original_name")}, NoNotify: true, Module: "crm"})
	c.Flash("success", c.T("File removed."))
	backTo(c, s, id, "#files")
}

// ---------------------------------------------------------------- sharing

func recordShare(c *app.Ctx) {
	s, row, id := openKind(c)
	u := c.MustUser()
	if !isManager(u, row) {
		app.Abort(http.StatusForbidden, c.T("Only the owner, the department head or an administrator can share this record."))
	}
	typ := "user"
	if c.Input("target_type") == "department" {
		typ = "department"
	}
	access := "view"
	if c.Input("access") == "edit" {
		access = "edit"
	}
	field := "user_id"
	if typ == "department" {
		field = "department_id"
	}
	target := conv.Int(c.Input(field))
	pm := peopleMap(people())
	deps := departments()
	if _, ok := pm[target]; typ == "user" && !ok {
		app.Invalid(field, c.T("Pick a person from the list."))
	}
	if typ == "department" && !optHas(deps, conv.Str(target)) {
		app.Invalid(field, c.T("Pick a department from the list."))
	}
	if typ == "department" && row.Int("department_id") == target && access == "view" {
		app.Invalid(field, c.T("That department can already see this record."))
	}
	if typ == "user" && row.Int("owner_id") == target {
		app.Invalid(field, c.T("That person already owns this record."))
	}
	addShare(s.entity, id, typ, target, access, u.ID)
	name := pm[target].Name
	if typ == "department" {
		name, _ = app.OptLabel(deps, conv.Str(target))
	}
	label := s.label(c, row)
	acc := "view only"
	if access == "edit" {
		acc = "can edit"
	}
	app.LogActivity(c, app.ActivityEntry{Action: "shared", Type: s.entity, ID: id, Label: label, Text: `Shared :type ":label" with :who (:access)`,
		Params: []any{"type", s.entity, "label", label, "who", name, "access", acc}, Props: app.M{"target": typ, "target_id": target, "access": access},
		Owner: ownerOf(row), NoNotify: true, Module: "crm"})
	// tell the person (or everyone in the department) that something was shared with them
	var recipients []int
	if typ == "user" {
		recipients = []int{target}
	} else {
		for _, v := range db.Core.Column("SELECT id FROM users WHERE department_id = ? AND is_active AND deleted_at IS NULL AND id <> ? LIMIT 60", target, u.ID) {
			recipients = append(recipients, conv.Int(v))
		}
	}
	body := "You can view it."
	if access == "edit" {
		body = "You can edit it."
	}
	for _, to := range recipients {
		if to != u.ID {
			app.Deliver(c, "crm", "shared", to, app.Text(`:name shared :type ":label" with you`, "name", u.Name, "type", s.entity, "label", label),
				app.Text(body), c.URL("/crm/"+entities[s.entity].Path+"/"+conv.Str(id)), u.ID)
		}
	}
	c.Flash("success", c.T("Shared with :who.", "who", name))
	backTo(c, s, id, "#sharing")
}

func recordUnshare(c *app.Ctx) {
	s, row, id := openKind(c)
	if !isManager(c.MustUser(), row) {
		app.Abort(http.StatusForbidden, c.T("Only the owner, the department head or an administrator can change who this record is shared with."))
	}
	db.On("crm").Exec("DELETE FROM record_shares WHERE id = ? AND entity_type = ? AND entity_id = ?", c.ParamInt("sid"), s.entity, id)
	label := s.label(c, row)
	app.LogActivity(c, app.ActivityEntry{Action: "shared", Type: s.entity, ID: id, Label: label, Text: `Stopped sharing :type ":label"`,
		Params: []any{"type", s.entity, "label", label}, NoNotify: true, Module: "crm"})
	c.Flash("success", c.T("Access removed."))
	backTo(c, s, id, "#sharing")
}
