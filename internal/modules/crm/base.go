package crm

import (
	"html/template"
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/db"
	"acme/internal/php"
)

/*
screen is the base of the CRM screens (CrmController). Same list / form / import / export / download as the core screens,
but rows live in crm_db, are limited by Access (own department + owner + shares),
and the detail page has comments, files, sharing and related records.
*/
type screen struct {
	res      *app.Resource
	entity   string // lead | contact | opportunity | campaign | activity | project | task
	prefix   string
	comments bool
	files    bool
	remote   []pair // column => kind (leads | contacts | opportunities | projects)
	dateCode bool   // codes are PREFIX-yyyyMMddHHmmss+ms instead of PREFIX-00042

	statCards   func(c *app.Ctx) []app.Stat
	hydrateMore func(c *app.Ctx, rows []db.Row) []db.Row
	prepareMore func(c *app.Ctx, data *app.Clean, existing db.Row) *app.Clean
	afterSave   func(c *app.Ctx, id int, data *app.Clean, existing db.Row)
	savedAfter  func(c *app.Ctx, id int) // after the base saved() (activities: Google Calendar)
	importMore  func(c *app.Ctx, raw app.CsvRow) app.CsvRow
	canModify   func(c *app.Ctx, row db.Row, action string) bool
	links       func(c *app.Ctx, row db.Row) []recLink

	showTop, showMain, showSide func(c *app.Ctx, row db.Row) string
	showTabs                    func(c *app.Ctx, row db.Row) []recTab
	badges                      func(c *app.Ctx, row db.Row) []string
	subtitle                    func(c *app.Ctx, row db.Row) string
	media                       func(c *app.Ctx, row db.Row) string
}

// recLink is a record action (rowLinks with the optional confirm text of the detail page).
type recLink struct {
	URL, Label, Icon, Confirm string
	Post                      bool
}

// recTab is one extra tab of the record page.
type recTab struct {
	Key, Label string
	HasCount   bool
	Count      int
	HTML       template.HTML
}

const maxFiles = 5

var screens = map[string]*screen{}

// setup fills in the base behaviour of a CRM screen.
func (s *screen) setup() *screen {
	r := s.res
	r.Conn, r.Module, r.Wizard = "crm", "crm", true
	r.ScopeSQL = func(c *app.Ctx) (string, []any) { return visible(c.MustUser(), "t", s.entity) }
	r.Owner = func(row db.Row) int { return row.Int("owner_id") }
	r.CanModify = func(c *app.Ctx, row db.Row, action string) bool {
		if s.canModify != nil {
			return s.canModify(c, row, action)
		}
		u := c.MustUser()
		if action == "delete" {
			return isManager(u, row)
		}
		return canChange(u, s.entity, row)
	}
	r.Hydrate = s.hydrate
	r.Stats = func(c *app.Ctx) []app.Stat {
		if c.Can("crm_stats", "view") && s.statCards != nil {
			return s.statCards(c)
		}
		return nil
	}
	r.Prepare = s.prepare
	r.Saved = s.saved
	r.ImportDefaults = s.importDefaults
	r.RowLinks = func(c *app.Ctx, row db.Row) []app.Link {
		if s.links == nil {
			return nil
		}
		var out []app.Link
		for _, l := range s.links(c, row) {
			out = append(out, app.Link{Label: l.Label, URL: l.URL, Icon: l.Icon, Post: l.Post})
		}
		return out
	}
	r.Finish = func(c *app.Ctx, id int) {
		if c.API || c.IsJSON() {
			c.JSON(app.M{"ok": true, "id": id, "url": c.URL(r.Base + "/" + php.Str(id))})
		}
		c.Redirect(r.Base + "/" + php.Str(id))
	}
	prevMeta := r.ExtraMeta
	r.ExtraMeta = func(c *app.Ctx) app.M {
		m := app.M{"entity": s.entity, "code": nil}
		if prevMeta != nil {
			for k, v := range prevMeta(c) {
				m[k] = v
			}
		}
		return m
	}
	screens[s.entity] = s
	return s
}

// mount registers the standard routes plus the CRM detail page and import.
func (s *screen) mount(rt app.Router) {
	s.res.Mount(rt, "show", "import")
	rt.Get(s.res.Base+"/{id}", s.show)
	rt.Post(s.res.Base+"/import", func(c *app.Ctx) {
		c.Shared["_crm_importing"] = true
		s.res.Import(c)
	})
}

// importing: a CSV / Excel import runs (fields the form asks for may be optional there).
func importing(c *app.Ctx) bool { return c.Shared["_crm_importing"] == true }

func must(c *app.Ctx) string {
	if importing(c) {
		return "nullable"
	}
	return "required"
}

func oldVal(c *app.Ctx, k string) (any, bool) {
	if c.Sess == nil {
		return nil, false
	}
	return c.Sess.Old(k)
}

// ---------------------------------------------------------------- hydrate: owner and department names live in user_db

func (s *screen) hydrate(c *app.Ctx, rows []db.Row) []db.Row {
	if len(rows) == 0 {
		return rows
	}
	users := names("users", append(col(rows, "owner_id"), col(rows, "created_by")...))
	deps := names("departments", col(rows, "department_id"))
	strOrNil := func(m map[int]string, id int) any {
		if v, ok := m[id]; ok {
			return v
		}
		return nil
	}
	for _, r := range rows {
		r["owner_name"] = strOrNil(users, r.Int("owner_id"))
		r["creator_name"] = strOrNil(users, r.Int("created_by"))
		r["department_name"] = strOrNil(deps, r.Int("department_id"))
	}
	if s.hydrateMore != nil {
		rows = s.hydrateMore(c, rows)
	}
	return rows
}

func inPlaceholders(n int) string { return strings.TrimSuffix(strings.Repeat("?,", n), ",") }

// ---------------------------------------------------------------- statistics cards

// figure: one number about the records this person may see, e.g. figure(c, "count(*)", "t.status = 'ACTIVE'").
func (s *screen) figure(c *app.Ctx, expr, where string, params []any, from string) float64 {
	sq, sp := visible(c.MustUser(), "t", s.entity)
	if from == "" {
		from = s.res.Table + " t"
	}
	if where == "" {
		where = "TRUE"
	}
	return php.Float(db.On("crm").Scalar("SELECT COALESCE("+expr+", 0) FROM "+from+" WHERE t.deleted_at IS NULL AND ("+where+") AND "+sq, append(append([]any{}, params...), sp...)...))
}

func card(label string, value any, sub, tone, icon, href string) app.Stat {
	v := php.Str(value)
	if f, ok := value.(float64); ok {
		v = php.NF(f, 0)
	}
	return app.Stat{Label: label, Value: v, Sub: sub, Tone: tone, Icon: icon, Href: href}
}

// ---------------------------------------------------------------- shared form parts

// filesFields: the Attachments step of a form: a drop zone, maxFiles in all.
func (s *screen) filesFields(c *app.Ctx, row db.Row) []app.Field {
	var existing []db.Row
	if row != nil {
		existing = attForRecord(s.entity, row.Int("id"))
	}
	return []app.Field{
		{Section: c.T("Attachments"), Icon: "clip", Help: c.T("Upload files (max :n)", "n", maxFiles)},
		{Name: "files", Label: c.T("Attachments"), Type: "custom", Partial: "crm/fields/dropfiles", Span: 2, NoTable: true, NoImport: true, Rules: "nullable",
			Extra: app.M{"hide_show": true, "max": maxFiles, "existing": existing, "free": maxFiles - len(existing), "count": len(existing),
				"mb": attMaxBytes / 1048576, "types": strings.Join(attExtensions(), ", ")}},
	}
}

type accessRow struct{ I, Target, Level string }

// ownershipFields: owner and department are never typed in: a new record belongs to the person who creates it and to
// their department. The only thing a person can add is access for other people / departments (a last "Access" step when
// creating; on an existing record it is managed from the detail page).
func (s *screen) ownershipFields(c *app.Ctx, row db.Row) []app.Field {
	if row != nil {
		return nil
	}
	u := c.MustUser()
	var targets []app.Opt
	for _, p := range people() {
		if p.ID != u.ID {
			l := p.Name
			if p.Department != "" {
				l += " · " + p.Department
			}
			targets = append(targets, app.Opt{Value: "user:" + php.Str(p.ID), Label: l})
		}
	}
	for _, d := range departments() {
		targets = append(targets, app.Opt{Value: "department:" + d.Value, Label: c.T("Department") + ": " + d.Label})
	}
	var rows []accessRow
	if o, ok := oldVal(c, "access"); ok {
		for i, r := range app.InList(o) {
			m := app.InMap(r)
			t, _ := m.Get("target")
			l, _ := m.Get("level")
			rows = append(rows, accessRow{php.Str(i), app.InStr(t), app.InStr(l)})
		}
	}
	return []app.Field{
		{Section: c.T("Access"), Icon: "users", Help: c.T("Who else can see and work on this record")},
		{Name: "access", Label: c.T("Share with"), Type: "custom", Partial: "crm/fields/access", Span: 2, NoTable: true, NoImport: true, Rules: "nullable",
			Extra: app.M{"hide_show": true, "targets": targets, "dept": u.DepartmentName, "rows": rows, "levels": []app.Opt{{Value: "view", Label: c.T("View only")}, {Value: "edit", Label: c.T("Can edit")}}}},
	}
}

func (s *screen) remoteKind(col string) string {
	for _, p := range s.remote {
		if p.K == col {
			return p.L
		}
	}
	return ""
}

// remoteItem: one row for the dropdown's button (logo, name, second line).
func remoteItem(c *app.Ctx, kind string, id int) app.M {
	var r db.Row
	switch kind {
	case "leads":
		r = db.On("crm").First("SELECT name_en AS label, name_th AS sub, image AS img FROM leads WHERE id = ?", id)
	case "projects":
		r = db.On("crm").First("SELECT name AS label, code AS sub, NULL AS img FROM projects WHERE id = ?", id)
	case "contacts":
		r = db.On("crm").First("SELECT trim(coalesce(c.salutation || ' ', '') || c.name_en) AS label, concat_ws(' · ', l.name_en, c.job_title) AS sub, c.avatar AS img FROM contacts c LEFT JOIN leads l ON l.id = c.lead_id WHERE c.id = ?", id)
	default:
		r = db.On("crm").First("SELECT o.name AS label, concat_ws(' · ', o.code, l.name_en) AS sub, NULL AS img FROM opportunities o LEFT JOIN leads l ON l.id = o.lead_id WHERE o.id = ?", id)
	}
	if r == nil {
		return nil
	}
	return app.M{"label": r["label"], "sub": r["sub"], "img": c.UploadURL(r["img"])}
}

// prefill: a value from the URL (?lead_id=12) for new records.
func prefill(c *app.Ctx, key string) any {
	v := c.Query(key)
	if ctypeDigit(v) {
		return php.Int(v)
	}
	return ""
}

// remoteField: a "pick a lead / contact / opportunity" field; f adds label, rules, span …
func (s *screen) remoteField(c *app.Ctx, column string, row db.Row, f app.Field) app.Field {
	kind := s.remoteKind(column)
	var id int
	o, _ := oldVal(c, column)
	switch {
	case !php.Empty(o):
		id = php.Int(o)
	case row != nil && row[column] != nil:
		id = row.Int(column)
	default:
		id = php.Int(prefill(c, column))
	}
	f.Name = column
	if f.Type == "" {
		f.Type = "select"
	}
	f.Remote = kind
	if id != 0 {
		if it := remoteItem(c, kind, id); it != nil {
			f.Current = it
		}
	}
	f.Default = prefill(c, column)
	return f
}

var remoteTables = map[string][2]string{"leads": {"leads", "lead"}, "contacts": {"contacts", "contact"}, "opportunities": {"opportunities", "opportunity"}, "projects": {"projects", "project"}}

// checkRemote: a picked record must be one this person may see (an unchanged existing value is left alone).
func (s *screen) checkRemote(c *app.Ctx, data *app.Clean, existing db.Row) {
	for _, p := range s.remote {
		v := data.Get(p.K)
		if v == nil || php.Str(v) == "" || (existing != nil && existing.Str(p.K) == php.Str(v)) {
			continue
		}
		t := remoteTables[p.L]
		sq, sp := visible(c.MustUser(), "t", t[1])
		if !ctypeDigit(php.Str(v)) || db.On("crm").Scalar("SELECT 1 FROM "+t[0]+" t WHERE t.id = ? AND t.deleted_at IS NULL AND "+sq, append([]any{php.Int(v)}, sp...)...) == nil {
			app.Invalid(p.K, c.T("Pick one from the list."))
		}
	}
}

// currencyOptions: [code => code · name]
func currencyOptions() []app.Opt {
	return optsFrom(db.On("crm").Select("SELECT code, code || ' · ' || name AS label FROM currencies ORDER BY is_base DESC, code"), "code", "label")
}

// createdColumn: last column of every list: who created the record and when.
func createdColumn(c *app.Ctx) app.Column {
	return app.Column{Key: "created", Label: c.T("Created by"), Sort: "t.created_at", Render: func(c *app.Ctx, r db.Row) template.HTML {
		n := r.Str("creator_name")
		if r["creator_name"] == nil {
			n = "—"
		}
		return h(uiPerson(c, n, app.FormatDate(c, r["created_at"], "M j, Y"), "", "", "rounded-full"))
	}}
}

// commonFilters: "My records" for everyone, plus a department filter for whole-company roles.
func commonFilters(c *app.Ctx) []app.Filter {
	uid := c.MustUser().ID
	f := []app.Filter{{Name: "scope", Label: c.T("All records"), Options: []app.Opt{{Value: "mine", Label: c.T("My records")}}, SQL: "t.owner_id = ?", Bind: func(string) any { return uid }}}
	if seesAll(c.MustUser()) {
		f = append(f, app.Filter{Name: "department", Label: c.T("All departments"), Options: departments(), Column: "t.department_id"})
	}
	return f
}

// visibleRows: rows of another CRM table the signed-in person may see, e.g. the contacts of a lead.
func visibleRows(c *app.Ctx, typ, sel, where string, params []any, order string, limit int) []db.Row {
	table := entities[typ].Table
	sq, sp := visible(c.MustUser(), "t", typ)
	if limit == 0 {
		limit = 8
	}
	return db.On("crm").Select("SELECT "+sel+" FROM "+table+" t WHERE t.deleted_at IS NULL AND ("+where+") AND "+sq+" ORDER BY "+order+" LIMIT "+php.Str(limit), append(append([]any{}, params...), sp...)...)
}

type relItem struct {
	Href, Title, Meta string
	MetaHTML          template.HTML
	Badge             template.HTML
}

// relatedPanel: a related-records card for the detail page.
func relatedPanel(c *app.Ctx, title string, items []relItem, createURL, empty, createLabel string) string {
	return string(c.Partial("crm/panels/related", app.M{"title": title, "items": items, "createUrl": createURL, "empty": empty, "createLabel": createLabel}))
}

func activityItems(c *app.Ctx, rows []db.Row) []relItem {
	var out []relItem
	for _, a := range rows {
		meta := trLabel(c, activityTypes, a.Str("activity_type"))
		if a["start_at"] != nil {
			meta += " · " + app.FormatDate(c, a["start_at"], "d M Y H:i")
		}
		tone := "info"
		switch a.Str("status") {
		case "DONE":
			tone = "success"
		case "CANCELLED":
			tone = "neutral"
		}
		out = append(out, relItem{Href: "/crm/activities/" + a.Str("id"), Title: a.Str("topic"), Meta: meta, Badge: h(uiBadge(trLabel(c, activityStatuses, a.Str("status")), tone))})
	}
	return out
}

func opportunityItems(c *app.Ctx, rows []db.Row) []relItem {
	var out []relItem
	for _, o := range rows {
		out = append(out, relItem{Href: "/crm/opportunities/" + o.Str("id"), Title: o.Str("name"), MetaHTML: h(uiMoney(o["amount"], o.Str("currency"))), Badge: h(uiStageBadge(c, o.Str("opportunity_stage")))})
	}
	return out
}

// ---------------------------------------------------------------- save hooks

type shareReq struct {
	Type   string
	ID     int
	Access string
}

func (s *screen) prepare(c *app.Ctx, data *app.Clean, existing db.Row) *app.Clean {
	u := c.MustUser()
	table := s.res.Table
	if code := data.Str("code"); data.Get("code") != nil && code != "" {
		var taken any
		if existing != nil {
			taken = db.On("crm").Scalar("SELECT 1 FROM "+table+" WHERE lower(code) = lower(?) AND id <> ?", code, existing["id"])
		} else {
			taken = db.On("crm").Scalar("SELECT 1 FROM "+table+" WHERE lower(code) = lower(?)", code)
		}
		if taken != nil {
			app.Invalid("code", c.T("This code is already used."))
		}
	}

	// A record belongs to whoever creates it, and to their department. Forms never change that.
	data.Delete("owner_id")
	data.Delete("department_id")
	if existing == nil {
		data.Set("created_by", u.ID)
		data.Set("owner_id", u.ID)
		data.Set("department_id", u.DeptID())
	}
	data.Set("updated_by", u.ID)

	// Extra access chosen while creating
	if existing == nil {
		pm := peopleMap(people())
		deps := departments()
		var list []shareReq
		idx := map[string]int{}
		for _, r := range app.InList(data.Get("access")) {
			m := app.InMap(r)
			t, _ := m.Get("target")
			typ, sid, _ := strings.Cut(app.InStr(t), ":")
			id := php.Int(sid)
			_, isPerson := pm[id]
			if (typ == "user" && isPerson && id != u.ID) || (typ == "department" && optHas(deps, php.Str(id))) {
				lv, _ := m.Get("level")
				acc := "view"
				if app.InStr(lv) == "edit" {
					acc = "edit"
				}
				k := typ + ":" + php.Str(id)
				if i, ok := idx[k]; ok {
					list[i].Access = acc
				} else {
					idx[k] = len(list)
					list = append(list, shareReq{typ, id, acc})
				}
			}
		}
		data.Set("access", list)
	}

	if s.files {
		had := 0
		if existing != nil {
			had = len(attForRecord(s.entity, existing.Int("id")))
		}
		in := c.Files("files")
		if had+len(in) > maxFiles { // at most maxFiles in all
			app.Invalid("files", c.T("You can attach up to :n files.", "n", maxFiles))
		}
		for _, f := range in {
			attCheck(c, f)
		}
	}

	s.checkRemote(c, data, existing)
	if s.prepareMore != nil {
		data = s.prepareMore(c, data, existing)
	}
	return data
}

// importDefaults: CSV import has no owner column: rows belong to the person importing them;
// a lead / contact / opportunity may be given by name in the CSV.
func (s *screen) importDefaults(c *app.Ctx, raw app.CsvRow) app.CsvRow {
	if _, ok := raw.Vals["owner_id"]; !ok {
		raw.Vals["owner_id"] = php.Str(c.MustUser().ID)
		raw.Keys = append(raw.Keys, "owner_id")
	}
	nameCols := map[string][3]string{"leads": {"leads", "name_en", "lead"}, "contacts": {"contacts", "name_en", "contact"}, "opportunities": {"opportunities", "name", "opportunity"}, "projects": {"projects", "name", "project"}}
	for _, p := range s.remote {
		v := strings.TrimSpace(raw.Vals[p.K])
		if v == "" || ctypeDigit(v) {
			continue
		}
		t := nameCols[p.L]
		sq, sp := visible(c.MustUser(), "t", t[2])
		id := db.On("crm").Scalar("SELECT t.id FROM "+t[0]+" t WHERE t.deleted_at IS NULL AND lower(t."+t[1]+") = lower(?) AND "+sq+" ORDER BY t.id LIMIT 1", append([]any{v}, sp...)...)
		if id != nil {
			raw.Vals[p.K] = php.Str(id)
		} else {
			raw.Vals[p.K] = ""
		}
	}
	if s.importMore != nil {
		raw = s.importMore(c, raw)
	}
	return raw
}

// newCode: code given to a new record that has none: PREFIX-00042, or the dateCode.
func (s *screen) newCode(id int) string {
	if s.dateCode {
		return s.dateCodeNow()
	}
	return s.prefix + "-" + padInt(id, 5)
}

func padInt(n, w int) string {
	v := php.Str(n)
	for len(v) < w {
		v = "0" + v
	}
	return v
}

// dateCodeNow: PREFIX-yyyyMMddHHmmss + 3 digits (milliseconds), e.g. LD-20261016061037142; the next free one if two are made at the same moment.
func (s *screen) dateCodeNow() string {
	t := time.Now().In(php.Loc)
	n := php.Int(t.Format("20060102150405") + padInt(t.Nanosecond()/1e6, 3))
	for db.On("crm").Scalar("SELECT 1 FROM "+s.res.Table+" WHERE code = ?", s.prefix+"-"+php.Str(n)) != nil {
		n++
	}
	return s.prefix + "-" + php.Str(n)
}

func (s *screen) saved(c *app.Ctx, id int, data *app.Clean, existing db.Row) {
	if existing == nil {
		db.On("crm").Exec("UPDATE "+s.res.Table+" SET code = ? WHERE id = ? AND (code IS NULL OR code = '')", s.newCode(id), id)
	}
	if s.files {
		for _, f := range c.Files("files") {
			attStore(c, f, s.entity, id, c.MustUser().ID)
		}
	}
	if existing == nil {
		if list, ok := data.Get("access").([]shareReq); ok {
			for _, sh := range list {
				addShare(s.entity, id, sh.Type, sh.ID, sh.Access, c.MustUser().ID)
			}
		}
	}
	if s.afterSave != nil {
		s.afterSave(c, id, data, existing)
	}
	if s.savedAfter != nil {
		s.savedAfter(c, id)
	}
}

// ---------------------------------------------------------------- used by the record actions

// openRecord: the record, if the signed-in person may see it (404 otherwise).
func (s *screen) openRecord(c *app.Ctx, id int) db.Row {
	c.Authorize(s.res.Resource, "view")
	return s.res.Find(c, id)
}

func (s *screen) allows(c *app.Ctx, row db.Row, action string) bool {
	return c.Can(s.res.Resource, action) && s.res.CanModify(c, row, action)
}

func (s *screen) label(c *app.Ctx, row db.Row) string { return s.res.LabelOf(c, row) }

// ---------------------------------------------------------------- detail page

type detailRow struct {
	Heading string
	Name    string
	Label   string
	Span    int
	Type    string
	Text    string
	Rich    template.HTML
	Href    string
}

// detailRows: what to show in the details card: skip empty values, file inputs, ownership (it has its own card)
// and widgets that have their own card.
func detailRows(c *app.Ctx, fields []app.Field, row db.Row) []detailRow {
	var out []detailRow
	heading := ""
	skipSection := false
	for _, f := range fields {
		if f.IsSection() {
			heading = f.Section
			skipSection = false
			continue
		}
		if skipSection || f.Name == "owner_id" || f.Name == "department_id" || f.Extra["hide_show"] == true || f.TypeOr() == "file" {
			continue
		}
		v, ok := detailValue(c, f, row)
		if !ok {
			continue
		}
		d := detailRow{Heading: heading, Name: f.Name, Label: f.Label, Span: f.Span, Type: f.TypeOr(), Text: v}
		if d.Type == "richtext" {
			d.Rich = template.HTML(c.HTMLRender(v))
		}
		if fn, ok := f.Extra["href"].(func(db.Row) string); ok {
			d.Href = fn(row)
		}
		out = append(out, d)
		heading = ""
	}
	return out
}

func detailValue(c *app.Ctx, f app.Field, row db.Row) (string, bool) {
	t := f.TypeOr()
	if f.Display != nil {
		d := f.Display(row)
		if d == nil || php.Str(d) == "" {
			return "", false
		}
		return php.Str(d), true
	}
	v := row[f.Name]
	if t == "checkbox" {
		if php.Bool(v) {
			return c.T("Yes"), true
		}
		return "", false
	}
	if v == nil || php.Str(v) == "" {
		return "", false
	}
	if l, ok := v.([]any); ok && len(l) == 0 {
		return "", false
	}
	if len(f.Options) > 0 {
		if l, ok := app.OptLabel(f.Options, php.Str(v)); ok {
			return l, true
		}
		if l, ok := app.OptLabel(f.AllOptions, php.Str(v)); ok {
			return l, true
		}
		return php.Str(v), true
	}
	switch t {
	case "richtext":
		return php.Str(app.HTMLClean(php.Str(v))), true
	case "date":
		return app.FormatDate(c, v, "d M Y"), true
	case "datetime":
		return app.FormatDate(c, v, "d M Y H:i"), true
	case "number":
		return php.NumberClean(v), true
	}
	return php.Str(v), true
}

func (s *screen) show(c *app.Ctx) {
	r := s.res
	c.Authorize(r.Resource, "view")
	id := c.ParamInt("id")
	row := r.Find(c, id)
	u := c.MustUser()
	meta := r.Meta(c)

	main := ""
	if s.showMain != nil {
		main = s.showMain(c, row)
	}
	if s.comments {
		main += string(c.Partial("crm/panels/comments", commentsData(c, meta, row, commentsFor(c, s.entity, id))))
	}
	side := recordPanel(c, row, s.entity)
	side += sharingPanel(c, meta, row, s.entity)
	if s.files {
		side += filesPanel(c, meta, row, attForRecord(s.entity, id), c.Can(r.Resource, "edit") && r.CanModify(c, row, "edit"), isManager(u, row))
	}
	if s.showSide != nil {
		side += s.showSide(c, row)
	}
	str := func(fn func(*app.Ctx, db.Row) string) template.HTML {
		if fn == nil {
			return ""
		}
		return template.HTML(fn(c, row))
	}
	var badges []template.HTML
	if s.badges != nil {
		for _, b := range s.badges(c, row) {
			badges = append(badges, template.HTML(b))
		}
	}
	var tabs []recTab
	if s.showTabs != nil {
		tabs = s.showTabs(c, row)
	}
	var links []recLink
	if s.links != nil {
		links = s.links(c, row)
	}
	fields := r.Fields(c, row)
	c.View("crm/show", app.M{
		"title": r.LabelOf(c, row), "c": meta, "row": row, "rows": detailRows(c, fields, row),
		"canEdit":     c.Can(r.Resource, "edit") && r.CanModify(c, row, "edit"),
		"canDelete":   c.Can(r.Resource, "delete") && r.CanModify(c, row, "delete"),
		"canDownload": c.Can(r.Resource, "download"),
		"links":       links, "badges": badges, "subtitle": string(str(s.subtitle)), "media": str(s.media),
		"top": str(s.showTop), "main": template.HTML(main), "side": template.HTML(side), "tabs": tabs,
	})
}

// ---------------------------------------------------------------- panels shared by every record page

func recordPanel(c *app.Ctx, row db.Row, typ string) string {
	u := c.MustUser()
	canHand := reassignInScope(c, u, row)
	var before []db.Row
	for _, h := range respHistory(typ, row.Int("id")) {
		if h.Str("role") == "owner" {
			before = append(before, h)
		}
	}
	data := app.M{"row": row, "type": typ, "canHand": canHand, "before": before, "dlg": "owner-history-" + typ + "-" + row.Str("id"),
		"history": historyDialog(c, "owner-history-"+typ+"-"+row.Str("id"), c.T("Owner history"), before, app.M{"at": row["created_at"], "by": row["creator_name"]}),
		"path":    entities[typ].Path, "all": seesAll(u)}
	if canHand {
		data["targets"] = withoutOpt(assignable(u), php.Str(row.Int("owner_id")))
	}
	return string(c.Partial("crm/panels/record", data))
}

func historyDialog(c *app.Ctx, id, title string, history []db.Row, created app.M) template.HTML {
	return c.Partial("crm/panels/owner-history", app.M{"id": id, "title": title, "history": history, "created": created})
}

func sharingPanel(c *app.Ctx, meta app.M, row db.Row, typ string) string {
	owner := row.Int("owner_id")
	var userOpts []app.Opt
	for _, p := range people() {
		if p.ID != owner {
			l := p.Name
			if p.Department != "" {
				l += " · " + p.Department
			}
			userOpts = append(userOpts, app.Opt{Value: php.Str(p.ID), Label: l})
		}
	}
	return string(c.Partial("crm/panels/sharing", app.M{"c": meta, "row": row, "shares": shares(typ, row.Int("id")), "canManage": isManager(c.MustUser(), row),
		"userOpts": userOpts, "departments": departments()}))
}

func filesPanel(c *app.Ctx, meta app.M, row db.Row, items []db.Row, canUpload, canManage bool) string {
	for _, f := range items {
		f["_size"] = humanSize(f.Int("size"))
		f["_viewable"] = strings.HasPrefix(f.Str("mime"), "image/") || f.Str("mime") == "application/pdf"
		f["_image"] = strings.HasPrefix(f.Str("mime"), "image/")
	}
	return string(c.Partial("crm/panels/files", app.M{"c": meta, "row": row, "items": items, "canUpload": canUpload, "canManage": canManage,
		"mb": attMaxBytes / 1048576, "types": strings.Join(attExtensions(), ", ")}))
}

func commentsData(c *app.Ctx, meta app.M, row db.Row, items []db.Row) app.M {
	for _, it := range items {
		it["_iso"] = php.DateOf("c", it["created_at"])
	}
	return app.M{"c": meta, "row": row, "items": items, "canPost": true}
}

// cleanMap reads a nested input value as an ordered map (nil-safe).
func cleanMap(v any) *config.Map { return app.InMap(v) }
