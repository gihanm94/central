package app

import (
	"encoding/json"
	"html/template"
	"net/http"
	"regexp"
	"strings"

	"acme/internal/config"
	"acme/internal/conv"
	"acme/internal/db"
)

/*
Resource is the generic list / create / edit / delete / import / export / download screen
(App\Core\Http\Controllers\ResourceController), driven by Fields and Columns. Every action checks the
permission (resource.action) and the data scope. A screen fills in the struct and its hooks:

	var members = &app.Resource{Resource: "members", Table: "users", Type: "member", Singular: "member", Plural: "members",
	    Base: "/members", Fields: memberFields, Columns: memberColumns, ...}
	members.Mount(r)    // GET /members, /members/create … the standard routes

A hook left nil does what the PHP base class did.
*/
type Resource struct {
	Resource     string
	Table        string
	Type         string
	Singular     string
	Plural       string
	Base         string
	ScopeCols    ScopeCols
	NoSoftDelete bool   // PHP $softDelete = false
	OrderBy      string // default "t.id DESC"
	PerPage      int    // default 10
	Wizard       bool
	WizardStyle  string // "dots"
	Icon         string
	Module       string // "core" when empty
	Conn         string // database connection, "core" when empty
	Readonly     bool   // read-only screens (ERP copies): no add / import
	Selectable   bool   // tick rows → delete the ticked ones
	Searchable   []string

	Fields  func(c *Ctx, row db.Row) []Field
	Columns func(c *Ctx) []Column

	SelectSQL      func(c *Ctx) string // default "SELECT t.* FROM <table> t"
	Filters        func(c *Ctx) []Filter
	ScopeSQL       func(c *Ctx) (string, []any)
	Label          func(c *Ctx, row db.Row) string
	Owner          func(row db.Row) int
	Prepare        func(c *Ctx, data *Clean, existing db.Row) *Clean
	Saved          func(c *Ctx, id int, data *Clean, existing db.Row)
	CanModify      func(c *Ctx, row db.Row, action string) bool
	RowLinks       func(c *Ctx, row db.Row) []Link
	ShowExtra      func(c *Ctx, row db.Row) template.HTML
	Intro          func(c *Ctx) string
	Stats          func(c *Ctx) []Stat
	HeaderActions  func(c *Ctx) template.HTML
	TopExtra       func(c *Ctx) template.HTML
	Hydrate        func(c *Ctx, rows []db.Row) []db.Row
	BeforeDelete   func(c *Ctx, row db.Row)
	ImportDefaults func(c *Ctx, raw CsvRow) CsvRow
	AlreadyStored  func(c *Ctx, data *Clean, existing db.Row) bool
	MayImport      func(c *Ctx) bool
	Finish         func(c *Ctx, id int)
	// ExtraMeta is merged into the view's .c (e.g. wizard_style, readonly flags a screen adds)
	ExtraMeta func(c *Ctx) M
}

// Field is one form field, or a section heading (Section set). See ResourceController's docblock.
type Field struct {
	Name             string
	Label            string
	Type             string // text|email|password|textarea|select|date|datetime|number|checkbox|richtext|file|logo|picker|multi|custom|tel|color
	Rules            string
	Options          []Opt
	AllOptions       []Opt // labels of values no longer offered
	Span             int
	Help             string
	Placeholder      string
	Readonly         bool
	Default          any
	NoTable          bool // 'table' => false: not a column of the table, handled in Saved
	NoImport         bool // 'import' => false
	HideForm         bool
	Rows             int
	Other            bool // the list plus "Other…"
	OtherMax         int
	OtherPlaceholder string
	OneOf            string
	OneOfMsg         string
	ShowWhen         string
	WideWhen         string
	Counter          bool
	Remote           string // remote-select kind
	Depends          string
	Current          any
	Rich             bool
	Search           *bool
	Partial          string // custom
	Choices          []Opt  // multi
	Empty            string
	Example          string
	Display          func(row db.Row) any
	// Extra values a custom partial or the picker needs
	Extra M

	// section heading
	Section string
	Icon    string
}

func (f Field) TypeOr() string {
	if f.Type == "" {
		return "text"
	}
	return f.Type
}

// IsSection: a heading, not a field.
func (f Field) IsSection() bool { return f.Section != "" }

// Required: the rules say required and the field can be changed.
func (f Field) Required() bool {
	return strings.Contains(f.Rules, "required") && !f.Readonly && f.TypeOr() != "checkbox"
}

// Column is one list column: Render gives the cell's HTML.
type Column struct {
	Key     string
	Label   string
	Primary bool
	Sort    string // SQL expression; empty = not sortable
	Tone    string // amber | sky | emerald
	Render  func(c *Ctx, row db.Row) template.HTML
}

// Filter is a list filter (a dropdown in the filter panel).
type Filter struct {
	Name    string
	Label   string
	Options []Opt
	Column  string
	SQL     string // own WHERE fragment; "?" binds the value
	Bind    func(v string) any
}

// Link is an extra action of a row or record.
type Link struct {
	Label string
	URL   string
	Icon  string
	Post  bool
}

// Stat is a summary card above a list.
type Stat struct {
	Label, Value, Sub, Tone, Icon, Href string
}

func (r *Resource) conn() db.Q {
	if r.Conn == "" {
		return db.Core
	}
	return db.On(r.Conn)
}

func (r *Resource) module() string {
	if r.Module == "" {
		return "core"
	}
	return r.Module
}

func (r *Resource) softDelete() bool { return !r.NoSoftDelete }

func (r *Resource) selectSQL(c *Ctx) string {
	if r.SelectSQL != nil {
		return r.SelectSQL(c)
	}
	return "SELECT t.* FROM " + r.Table + " t"
}

func (r *Resource) scope(c *Ctx) (string, []any) {
	if r.ScopeSQL != nil {
		return r.ScopeSQL(c)
	}
	cols := r.ScopeCols
	if len(cols.Owner) == 0 && cols.Department == "" && cols.Team == "" {
		cols = ScopeCols{Owner: []string{"user_id"}, Department: "department_id", Team: "team_id"}
	}
	return ScopeSQL(c.MustUser(), "t", cols)
}

// LabelOf is label($row).
func (r *Resource) LabelOf(c *Ctx, row db.Row) string {
	if r.Label != nil {
		return r.Label(c, row)
	}
	if v := row.Str("name"); v != "" {
		return v
	}
	if v := row.Str("title"); v != "" {
		return v
	}
	return "#" + row.Str("id")
}

func (r *Resource) owner(row db.Row) int {
	if r.Owner != nil {
		return r.Owner(row)
	}
	return 0
}

func (r *Resource) canModify(c *Ctx, row db.Row, action string) bool {
	if r.CanModify != nil {
		return r.CanModify(c, row, action)
	}
	return true
}

func (r *Resource) hydrate(c *Ctx, rows []db.Row) []db.Row {
	if r.Hydrate != nil {
		return r.Hydrate(c, rows)
	}
	return rows
}

func (r *Resource) filters(c *Ctx) []Filter {
	if r.Filters != nil {
		return r.Filters(c)
	}
	return nil
}

func (r *Resource) columnByKey(c *Ctx, key string) (Column, bool) {
	for _, col := range r.Columns(c) {
		if col.Key == key {
			return col, true
		}
	}
	return Column{}, false
}

// ---------------------------------------------------------------- queries

// Query lists the rows the person may see, filtered, sorted and (paginate) paged.
func (r *Resource) Query(c *Ctx, paginate bool) ([]db.Row, int) {
	ssql, params := r.scope(c)
	where := []string{ssql}
	if r.softDelete() {
		where = append(where, "t.deleted_at IS NULL")
	}
	if q := c.Query("q"); q != "" && len(r.Searchable) > 0 {
		var parts []string
		for _, col := range r.Searchable {
			parts = append(parts, col+"::text ILIKE ?")
			params = append(params, "%"+q+"%")
		}
		where = append(where, "("+strings.Join(parts, " OR ")+")")
	}
	for _, f := range r.filters(c) {
		v := c.Query(f.Name)
		if v == "" {
			continue
		}
		if _, ok := OptLabel(f.Options, v); !ok {
			continue
		}
		if f.SQL != "" {
			where = append(where, f.SQL)
		} else {
			where = append(where, f.Column+" = ?")
		}
		if f.SQL == "" || strings.Contains(f.SQL, "?") {
			if f.Bind != nil {
				params = append(params, f.Bind(v))
			} else {
				params = append(params, v)
			}
		}
	}
	sql := r.selectSQL(c) + " WHERE " + strings.Join(where, " AND ")
	total := conv.Int(r.conn().Scalar("SELECT COUNT(*) FROM ("+sql+") x", params...))
	key, dir := r.sorting(c)
	if key != "" {
		col, _ := r.columnByKey(c, key)
		sql += " ORDER BY " + col.Sort + " " + dir + " NULLS LAST, t.id DESC"
	} else {
		ob := r.OrderBy
		if ob == "" {
			ob = "t.id DESC"
		}
		sql += " ORDER BY " + ob
	}
	if paginate {
		page := max(1, conv.Int(c.Query("page", "1")))
		size := r.pageSize(c)
		sql += " LIMIT " + conv.Str(size) + " OFFSET " + conv.Str((page-1)*size)
	}
	return r.hydrate(c, r.conn().Select(sql, params...)), total
}

func (r *Resource) sorting(c *Ctx) (string, string) {
	key := c.Query("sort")
	col, ok := r.columnByKey(c, key)
	if !ok || col.Sort == "" {
		return "", "ASC"
	}
	if strings.ToLower(c.Query("dir", "asc")) == "desc" {
		return key, "DESC"
	}
	return key, "ASC"
}

var pageSizes = []int{10, 20, 50, 100}

func okSize(n int) bool {
	for _, s := range pageSizes {
		if s == n {
			return true
		}
	}
	return false
}

func (r *Resource) pageSize(c *Ctx) int {
	if q := conv.Int(c.Query("per_page", "0")); okSize(q) {
		return q
	}
	if saved := conv.Int(TablePrefs(c.MustUser().ID, r.Resource)["per_page"]); okSize(saved) {
		return saved
	}
	if r.PerPage > 0 {
		return r.PerPage
	}
	return 10
}

// Find reads one row the person may see, or 404.
func (r *Resource) Find(c *Ctx, id int) db.Row {
	ssql, sp := r.scope(c)
	sql := r.selectSQL(c) + " WHERE t.id = ? AND " + ssql
	if r.softDelete() {
		sql += " AND t.deleted_at IS NULL"
	}
	row := r.conn().First(sql, append([]any{id}, sp...)...)
	if row != nil {
		row = r.hydrate(c, []db.Row{row})[0]
	}
	if row == nil {
		Abort(http.StatusNotFound, c.T(":Item not found, or it is outside what you can see.", "Item", c.T(conv.Ucfirst(r.Singular))))
	}
	return row
}

// FindForChange checks the permission and the row's own rule.
func (r *Resource) FindForChange(c *Ctx, id int, action string) db.Row {
	c.Authorize(r.Resource, action)
	row := r.Find(c, id)
	if !r.canModify(c, row, action) {
		Abort(http.StatusForbidden, c.T("You can't :action this :item.", "action", c.T(action), "item", c.T(r.Singular)))
	}
	return row
}

// ---------------------------------------------------------------- pages

// Mount registers the standard routes: list, new, detail, edit, delete, export, import, template, download.
// skip names actions a screen registers itself ("index", "create", "store", "show", "edit", "update", "destroy", "export", "template", "import", "bulk", "download").
func (r *Resource) Mount(rt Router, skip ...string) {
	s := map[string]bool{}
	for _, k := range skip {
		s[k] = true
	}
	p := r.Base
	if !s["index"] {
		rt.Get(p, r.Index)
	}
	if !s["create"] {
		rt.Get(p+"/create", r.Create)
	}
	if !s["store"] {
		rt.Post(p, r.Store)
	}
	if !s["export"] {
		rt.Get(p+"/export", r.Export)
	}
	if !s["template"] {
		rt.Get(p+"/template", r.Template)
	}
	if !s["import"] {
		rt.Post(p+"/import", r.Import)
	}
	if !s["bulk"] {
		rt.Post(p+"/bulk-delete", r.BulkDestroy)
	}
	if !s["show"] {
		rt.Get(p+"/{id}", r.Show)
	}
	if !s["edit"] {
		rt.Get(p+"/{id}/edit", r.Edit)
	}
	if !s["update"] {
		rt.Post(p+"/{id}", r.Update)
	}
	if !s["destroy"] {
		rt.Post(p+"/{id}/delete", r.Destroy)
	}
	if !s["download"] {
		rt.Get(p+"/{id}/download", r.Download)
	}
}

// RowActions are what each list row's menu offers.
type RowActions struct {
	Edit, Delete, Download bool
	Links                  []Link
}

// Index is the list page.
func (r *Resource) Index(c *Ctx) {
	c.Authorize(r.Resource, "view")
	r.savedFilters(c)
	rows, total := r.Query(c, true)
	for _, row := range rows {
		row["_label"] = r.LabelOf(c, row)
	}
	if asked := conv.Int(c.Query("per_page", "0")); okSize(asked) {
		RememberPageSize(c.MustUser().ID, r.Resource, asked)
	}
	actions := map[int]RowActions{}
	for _, row := range rows {
		a := RowActions{
			Edit:     c.Can(r.Resource, "edit") && r.canModify(c, row, "edit"),
			Delete:   c.Can(r.Resource, "delete") && r.canModify(c, row, "delete"),
			Download: c.Can(r.Resource, "download"),
		}
		if r.RowLinks != nil {
			a.Links = r.RowLinks(c, row)
		}
		actions[row.Int("id")] = a
	}
	key, dir := r.sorting(c)
	data := M{
		"title": c.T(conv.Ucfirst(r.Plural)), "c": r.Meta(c), "rows": rows, "total": total,
		"columns": r.Columns(c), "filters": r.filters(c), "rowActions": actions,
		"perPage": r.pageSize(c), "page": max(1, conv.Int(c.Query("page", "1"))),
		"hidden": TablePrefs(c.MustUser().ID, r.Resource)["hidden"],
		"sort":   key, "dir": strings.ToLower(dir),
		"canDelete": c.Can(r.Resource, "delete"), "canSearch": len(r.Searchable) > 0,
		"hasSaved": len(SavedFilters(c.MustUser().ID, r.Resource)) > 0,
		"intro":    "", "stats": []Stat(nil), "headerActions": template.HTML(""), "topExtra": template.HTML(""),
	}
	if r.Intro != nil {
		data["intro"] = r.Intro(c)
	}
	if r.Stats != nil {
		data["stats"] = r.Stats(c)
	}
	if r.HeaderActions != nil {
		data["headerActions"] = r.HeaderActions(c)
	}
	if r.TopExtra != nil {
		data["topExtra"] = r.TopExtra(c)
	}
	c.View("resource/index", data)
}

// savedFilters is the "keep my filters" behaviour of the Filter sheet:
//   - ?save=1   (Apply): keep the chosen filters for this person and show the list with them;
//   - ?clear=1  (Clear): forget them and show the list unfiltered;
//   - a list opened with no filter in its address gets the person's kept filters.
func (r *Resource) savedFilters(c *Ctx) {
	filters := r.filters(c)
	if len(filters) == 0 {
		return
	}
	uid := c.MustUser().ID
	valid := func(name, v string) bool {
		for _, f := range filters {
			if f.Name == name {
				_, ok := OptLabel(f.Options, v)
				return ok && v != ""
			}
		}
		return false
	}
	keep := map[string]string{} // the rest of the address (search, sort, rows per page)
	for k, v := range c.QueryMap() {
		if k == "save" || k == "clear" || k == "page" {
			continue
		}
		keep[k] = v
	}
	go_ := func(q map[string]string) {
		m := map[string]any{}
		for k, v := range q {
			m[k] = v
		}
		c.Redirect(c.URL(c.Path(), m))
	}
	switch {
	case c.Query("clear") == "1":
		SaveFilters(uid, r.Resource, map[string]string{})
		for _, f := range filters {
			delete(keep, f.Name)
		}
		go_(keep)
	case c.Query("save") == "1":
		chosen := map[string]string{}
		for _, f := range filters {
			if v := c.Query(f.Name); valid(f.Name, v) {
				chosen[f.Name] = v
			}
			delete(keep, f.Name)
		}
		SaveFilters(uid, r.Resource, chosen)
		for k, v := range chosen {
			keep[k] = v
		}
		go_(keep)
	default:
		for _, f := range filters {
			if c.QueryHas(f.Name) {
				return // the address already says which filters to use
			}
		}
		saved := SavedFilters(uid, r.Resource)
		applied := false
		for _, f := range filters {
			if v, ok := saved[f.Name]; ok && valid(f.Name, v) {
				keep[f.Name] = v
				applied = true
			}
		}
		if applied {
			go_(keep)
		}
	}
}

// Create is the new-record form.
func (r *Resource) Create(c *Ctx) {
	c.Authorize(r.Resource, "create")
	c.View("resource/form", M{"title": c.T("New :item", "item", c.T(r.Singular)), "c": r.Meta(c), "row": db.Row(nil), "fields": FormFields(c, r.Fields(c, nil), nil), "embed": c.Query("embed") != ""})
}

// Store saves a new record.
func (r *Resource) Store(c *Ctx) {
	c.Authorize(r.Resource, "create")
	fields := r.Fields(c, nil)
	data := r.prepare(c, r.ValidateFields(c, fields, c.All(), nil), nil)
	id := r.conn().InsertID(r.Table, r.TableData(fields, data))
	if r.Saved != nil {
		r.Saved(c, id, data, nil)
	}
	r.editorImages(c, fields, id, data, nil)
	row := r.Find(c, id)
	LogActivity(c, ActivityEntry{Action: "created", Type: r.Type, ID: id, Label: r.LabelOf(c, row), Text: `Created :type ":label"`,
		Params: []any{"type", r.Singular, "label", r.LabelOf(c, row)}, Props: M{"attributes": r.DisplayValues(c, fields, row), "_url": c.URL(r.Base + "/" + conv.Str(id))},
		Owner: r.owner(row), Module: r.module()})
	c.Flash("success", c.T(":Item created.", "Item", c.T(conv.Ucfirst(r.Singular))))
	r.finish(c, id)
}

// Show is the detail page.
func (r *Resource) Show(c *Ctx) {
	c.Authorize(r.Resource, "view")
	row := r.Find(c, c.ParamInt("id"))
	data := M{
		"title": r.LabelOf(c, row), "c": r.Meta(c), "row": row, "fields": r.Fields(c, row),
		"canEdit":     c.Can(r.Resource, "edit") && r.canModify(c, row, "edit"),
		"canDelete":   c.Can(r.Resource, "delete") && r.canModify(c, row, "delete"),
		"canDownload": c.Can(r.Resource, "download"),
		"extra":       template.HTML(""), "links": []Link(nil),
	}
	if r.ShowExtra != nil {
		data["extra"] = r.ShowExtra(c, row)
	}
	if r.RowLinks != nil {
		data["links"] = r.RowLinks(c, row)
	}
	data["display"] = ShowFields(c, data["fields"].([]Field), row)
	c.View("resource/show", data)
}

// Edit is the edit form.
func (r *Resource) Edit(c *Ctx) {
	row := r.FindForChange(c, c.ParamInt("id"), "edit")
	c.View("resource/form", M{"title": c.T("Edit :name", "name", r.LabelOf(c, row)), "c": r.Meta(c), "row": row, "fields": FormFields(c, r.Fields(c, row), row), "embed": c.Query("embed") != ""})
}

// Update saves changes.
func (r *Resource) Update(c *Ctx) {
	id := c.ParamInt("id")
	row := r.FindForChange(c, id, "edit")
	fields := r.Fields(c, row)
	data := r.prepare(c, r.ValidateFields(c, fields, c.All(), row), row)
	table := r.TableData(fields, data)
	if len(table) > 0 {
		table = append(table, db.Col{Name: "updated_at", Value: conv.NowString()})
		r.conn().Update(r.Table, table, db.Cols{{Name: "id", Value: id}})
	}
	if r.Saved != nil {
		r.Saved(c, id, data, row)
	}
	r.editorImages(c, fields, id, data, row)
	after := r.Find(c, id)
	var names []string
	for _, f := range realFields(fields) {
		names = append(names, f.Name)
	}
	changes := Diff(r.DisplayValues(c, fields, row), r.DisplayValues(c, fields, after), names)
	if data.Has("password") && data.Str("password") != "" {
		changes.Set("password", Change{From: "•••", To: "changed"})
	}
	if changes.Len() > 0 {
		LogActivity(c, ActivityEntry{Action: "updated", Type: r.Type, ID: id, Label: r.LabelOf(c, after), Text: `Updated :type ":label"`,
			Params: []any{"type", r.Singular, "label", r.LabelOf(c, after)}, Props: M{"changes": changes, "_url": c.URL(r.Base + "/" + conv.Str(id))},
			Owner: r.owner(after), Module: r.module()})
		c.Flash("success", c.T(":Item saved.", "Item", c.T(conv.Ucfirst(r.Singular))))
	} else {
		c.Flash("status", c.T("Nothing changed."))
	}
	r.finish(c, id)
}

func (r *Resource) prepare(c *Ctx, data *Clean, existing db.Row) *Clean {
	if r.Prepare != nil {
		return r.Prepare(c, data, existing)
	}
	return data
}

// pictures in rich-text fields: note them against this record; delete the ones taken out (and their files)
func (r *Resource) editorImages(c *Ctx, fields []Field, id int, data *Clean, existing db.Row) {
	connName := r.Conn
	if connName == "" {
		connName = "core"
	}
	for _, f := range realFields(fields) {
		if f.TypeOr() != "richtext" || f.NoTable || !data.Has(f.Name) {
			continue
		}
		var before any
		if existing != nil {
			before = existing[f.Name]
		}
		name := f.Name
		_ = Catch(func() {
			EditorImagesSaved(connName+"."+r.Table+"."+conv.Str(id)+"."+name, data.Get(name), before, func(file string) bool {
				return r.conn().Scalar("SELECT 1 FROM "+r.Table+" WHERE "+name+" LIKE ? AND id <> ? LIMIT 1", "%"+lastPart(file)+"%", id) != nil
			})
		})
	}
}

func lastPart(p string) string {
	if i := strings.LastIndexByte(p, '/'); i >= 0 {
		return p[i+1:]
	}
	return p
}

// after saving: the side sheet gets JSON (and reloads the page behind it), a normal form goes to the record
func (r *Resource) finish(c *Ctx, id int) {
	if r.Finish != nil {
		r.Finish(c, id)
		return
	}
	if c.IsJSON() {
		c.JSON(M{"ok": true, "id": id, "url": c.URL(r.Base + "/" + conv.Str(id))})
	}
	c.Redirect(r.Base + "/" + conv.Str(id))
}

// Destroy deletes one record (the word DELETE must be typed).
func (r *Resource) Destroy(c *Ctx) {
	id := c.ParamInt("id")
	row := r.FindForChange(c, id, "delete")
	r.requireTypedConfirmation(c)
	if r.BeforeDelete != nil {
		r.BeforeDelete(c, row)
	}
	r.deleteRow(id)
	LogActivity(c, ActivityEntry{Action: "deleted", Type: r.Type, ID: id, Label: r.LabelOf(c, row), Text: `Deleted :type ":label"`,
		Params: []any{"type", r.Singular, "label", r.LabelOf(c, row)}, Owner: r.owner(row), Module: r.module()})
	c.Flash("success", c.T(":Item deleted.", "Item", c.T(conv.Ucfirst(r.Singular))))
	c.Redirect(r.Base)
}

func (r *Resource) deleteRow(id int) {
	if r.softDelete() {
		r.conn().Exec("UPDATE "+r.Table+" SET deleted_at = now() WHERE id = ?", id)
	} else {
		r.conn().Exec("DELETE FROM "+r.Table+" WHERE id = ?", id)
	}
}

func (r *Resource) requireTypedConfirmation(c *Ctx) {
	if c.Input("confirm") != "DELETE" {
		c.Back("error", c.T("Type DELETE to confirm."))
	}
}

// BulkDestroy deletes the ticked rows (each one still checked against the person's rights).
func (r *Resource) BulkDestroy(c *Ctx) {
	c.Authorize(r.Resource, "delete")
	r.requireTypedConfirmation(c)
	seen := map[int]bool{}
	var ids []int
	for _, v := range c.Inputs("ids") {
		if n := conv.Int(v); n != 0 && !seen[n] {
			seen[n] = true
			ids = append(ids, n)
		}
	}
	done, skipped := 0, 0
	for _, id := range ids {
		var row db.Row
		err := Catch(func() { row = r.Find(c, id) })
		if err != nil {
			if _, ok := err.(*HTTPError); ok {
				skipped++
				continue
			}
			panic(err)
		}
		if !r.canModify(c, row, "delete") {
			skipped++
			continue
		}
		if r.BeforeDelete != nil {
			r.BeforeDelete(c, row)
		}
		r.deleteRow(id)
		LogActivity(c, ActivityEntry{Action: "deleted", Type: r.Type, ID: id, Label: r.LabelOf(c, row), Text: `Deleted :type ":label"`,
			Params: []any{"type", r.Singular, "label", r.LabelOf(c, row)}, Owner: r.owner(row), Module: r.module()})
		done++
	}
	kind := "error"
	if done > 0 {
		kind = "success"
		if skipped > 0 {
			kind = "warning"
		}
	}
	msg := c.T(":n deleted.", "n", done)
	if skipped > 0 {
		msg += "\n" + c.T(":n skipped (not yours to delete).", "n", skipped)
	}
	c.Flash(kind, msg)
	c.Redirect(r.Base)
}

// ---------------------------------------------------------------- import / export / download

// Export sends the list (with its filters) as Excel.
func (r *Resource) Export(c *Ctx) {
	c.Authorize(r.Resource, "export")
	var fields []Field
	for _, f := range realFields(r.Fields(c, nil)) {
		if !f.NoImport && f.TypeOr() != "password" {
			fields = append(fields, f)
		}
	}
	rows, _ := r.Query(c, false)
	filters := map[string]any{}
	for k, v := range c.QueryMap() {
		if v != "" && v != "0" {
			filters[k] = v
		}
	}
	LogActivity(c, ActivityEntry{Action: "export", Type: r.Type, Label: conv.Ucfirst(r.Plural), Text: "Exported :count :type", Params: []any{"count", len(rows), "type", r.Plural},
		Props: M{"count": len(rows), "filters": filters}, Module: r.module()})
	header := []string{"id"}
	for _, f := range fields {
		header = append(header, f.Name)
	}
	out := make([][]any, 0, len(rows))
	for _, row := range rows {
		vals := r.DisplayValues(c, fields, row)
		line := []any{row["id"]}
		for _, f := range fields {
			line = append(line, vals[f.Name])
		}
		out = append(out, line)
	}
	c.XlsxDownload(r.Plural+"-"+conv.Date("Ymd-His", conv.Now())+".xlsx", header, out)
}

func (r *Resource) mayImport(c *Ctx) bool {
	if r.MayImport != nil {
		return r.MayImport(c)
	}
	return c.MustUser().IsAdmin() && c.Can(r.Resource, "import")
}

// Template is the import template (CSV).
func (r *Resource) Template(c *Ctx) {
	if !r.mayImport(c) {
		Abort(http.StatusForbidden, c.T("Only an administrator can import data."))
	}
	var header []string
	var example []any
	for _, f := range realFields(r.Fields(c, nil)) {
		if f.NoImport || f.Readonly {
			continue
		}
		header = append(header, f.Name)
		if len(f.Options) > 0 {
			example = append(example, f.Options[0].Label)
		} else {
			example = append(example, f.Example)
		}
	}
	LogActivity(c, ActivityEntry{Action: "download", Type: r.Type, Label: "Import template", Text: "Downloaded the :type import template", Params: []any{"type", r.Plural}, NoNotify: true, Module: r.module()})
	c.CsvDownload(r.Plural+"-import-template.csv", header, [][]any{example})
}

// Import adds rows from a CSV / Excel file.
func (r *Resource) Import(c *Ctx) {
	if !r.mayImport(c) {
		Abort(http.StatusForbidden, c.T("Only an administrator can import data."))
	}
	file := c.File("file")
	if file == nil {
		Invalid("file", c.T("Choose a CSV file to import."))
	}
	rows := CsvRead(file, 5000)
	fields := realFields(r.Fields(c, nil))
	created, skipped := 0, 0
	var errs []string
	for i, raw := range rows {
		line := i + 2
		err := Catch(func() {
			// selects accept either the stored value or the visible label
			for _, f := range fields {
				v, ok := raw.Vals[f.Name]
				if len(f.Options) == 0 || !ok || v == "" {
					continue
				}
				if _, known := OptLabel(f.Options, v); known {
					continue
				}
				want := strings.ToLower(strings.TrimSpace(v))
				for _, o := range f.Options {
					if strings.ToLower(o.Label) == want {
						raw.Vals[f.Name] = o.Value
						break
					}
				}
			}
			if r.ImportDefaults != nil {
				raw = r.ImportDefaults(c, raw)
			}
			in := config.NewMap()
			for _, k := range raw.Keys {
				in.Set(k, raw.Vals[k])
			}
			for k, v := range raw.Vals {
				if _, ok := in.Get(k); !ok {
					in.Set(k, v)
				}
			}
			clean := r.ValidateFields(c, fields, in, nil)
			if r.AlreadyStored != nil && r.AlreadyStored(c, clean, nil) {
				skipped++
				return
			}
			data := r.prepare(c, clean, nil)
			id := r.conn().InsertID(r.Table, r.TableData(fields, data))
			if r.Saved != nil {
				r.Saved(c, id, data, nil)
			}
			created++
		})
		if err == nil {
			continue
		}
		switch e := err.(type) {
		case *ValidationError:
			errs = append(errs, c.T("Row :n", "n", line)+": "+strings.Join(e.Errors.List(), " "))
		case *db.Error:
			errs = append(errs, c.T("Row :n", "n", line)+": "+c.T("could not be saved")+" ("+conv.Limit(e.Error(), 90)+")")
		default:
			panic(err)
		}
	}
	first := errs
	if len(first) > 20 {
		first = first[:20]
	}
	LogActivity(c, ActivityEntry{Action: "import", Type: r.Type, Label: conv.Ucfirst(r.Plural), Text: "Imported :created of :total :type",
		Params: []any{"created", created, "total", len(rows), "type", r.Plural},
		Props:  M{"created": created, "skipped": skipped, "failed": len(errs), "errors": first, "file": file.Filename}, Module: r.module()})
	title := c.T("Imported :created of :total rows.", "created", created, "total", len(rows))
	var detail []string
	if skipped > 0 {
		detail = append(detail, c.T(":n already there, skipped.", "n", skipped))
	}
	if len(errs) > 0 {
		shown := errs
		if len(shown) > 5 {
			shown = shown[:5]
		}
		s := c.T("Problems") + ": " + strings.Join(shown, " | ")
		if len(errs) > 5 {
			s += " …"
		}
		detail = append(detail, s)
	}
	tone := "success"
	if len(errs) > 0 {
		tone = "error"
		if created > 0 {
			tone = "warning"
		}
	} else if created == 0 && skipped > 0 {
		tone = "warning"
	}
	if len(detail) > 0 {
		title += "\n" + strings.Join(detail, "\n")
	}
	c.Flash(tone, title)
	c.Redirect(r.Base)
}

// Download sends one record as Excel.
func (r *Resource) Download(c *Ctx) {
	c.Authorize(r.Resource, "download")
	id := c.ParamInt("id")
	row := r.Find(c, id)
	var fields []Field
	for _, f := range realFields(r.Fields(c, row)) {
		if f.TypeOr() != "password" {
			fields = append(fields, f)
		}
	}
	LogActivity(c, ActivityEntry{Action: "download", Type: r.Type, ID: id, Label: r.LabelOf(c, row), Text: `Downloaded :type ":label"`,
		Params: []any{"type", r.Singular, "label", r.LabelOf(c, row)}, Owner: r.owner(row), Module: r.module()})
	vals := r.DisplayValues(c, fields, row)
	lines := [][]any{{"ID", conv.Str(id)}}
	for _, f := range fields {
		lines = append(lines, []any{f.Label, conv.Str(vals[f.Name])})
	}
	lines = append(lines, []any{c.T("Downloaded by"), c.MustUser().Name + " · " + FormatDate(c, conv.NowString(), "d M Y H:i")})
	c.XlsxDownload(r.Type+"-"+conv.Str(id)+".xlsx", []string{c.T("Field"), c.T("Value")}, lines)
}

// ---------------------------------------------------------------- helpers

// Meta is the view's .c.
func (r *Resource) Meta(c *Ctx) M {
	icon := r.Icon
	if icon == "" {
		icon = "grid"
	}
	m := M{"resource": r.Resource, "base": r.Base, "singular": r.Singular, "plural": r.Plural, "icon": icon, "wizard": r.Wizard,
		"import": r.mayImport(c), "wizard_style": r.WizardStyle, "readonly": r.Readonly, "selectable": r.Selectable}
	if r.ExtraMeta != nil {
		for k, v := range r.ExtraMeta(c) {
			m[k] = v
		}
	}
	return m
}

func realFields(fields []Field) []Field {
	out := make([]Field, 0, len(fields))
	for _, f := range fields {
		if !f.IsSection() {
			out = append(out, f)
		}
	}
	return out
}

// RealFields drops section headings.
func RealFields(fields []Field) []Field { return realFields(fields) }

// ValidateFields validates input against the fields' rules (selects must hold one of their options).
func (r *Resource) ValidateFields(c *Ctx, fields []Field, input *config.Map, existing db.Row) *Clean {
	fields = realFields(fields)
	var rules []Rule
	for _, f := range fields {
		if f.Readonly {
			continue
		}
		rule := f.Rules
		if rule == "" {
			rule = "nullable"
		}
		if f.TypeOr() == "checkbox" {
			rule = "boolean"
		} else if len(f.Options) > 0 && !f.Other && !strings.Contains(rule, "in:") {
			vals := make([]string, len(f.Options))
			for i, o := range f.Options {
				vals[i] = o.Value
			}
			rule += "|in:" + strings.Join(vals, ",")
		}
		label := f.Label
		if c.Locale() == "en" {
			label = strings.ToLower(f.Label)
		}
		if f.HideForm {
			if _, sent := input.Get(f.Name); !sent {
				continue // a field the form does not show keeps its value unless it is sent (an import)
			}
		}
		rules = append(rules, Rule{Field: f.Name, Rules: rule, Label: label})
	}
	for _, f := range fields { // a select with "Other…": what was typed is the value
		if f.Other {
			if v, _ := input.Get(f.Name); InStr(v) == "__other" {
				o, _ := input.Get(f.Name + "__other")
				input.Set(f.Name, InStr(o))
			}
		}
	}
	clean := c.Validate(input, rules)
	for _, f := range fields {
		if f.TypeOr() == "richtext" && clean.Has(f.Name) {
			clean.Set(f.Name, HTMLClean(clean.Get(f.Name)))
		}
	}
	return clean
}

// TableData is the data that goes into the table's own columns ("" becomes NULL).
func (r *Resource) TableData(fields []Field, data *Clean) db.Cols {
	byName := map[string]Field{}
	for _, f := range fields {
		if !f.IsSection() {
			byName[f.Name] = f
		}
	}
	var out db.Cols
	for _, k := range data.Keys {
		f, known := byName[k]
		if known && f.NoTable {
			continue
		}
		v := data.Vals[k]
		if s, ok := v.(string); ok && s == "" {
			v = nil
		}
		out = append(out, db.Col{Name: k, Value: v})
	}
	return out
}

// DisplayValues: human-readable values (select labels, yes/no) for logs, exports and the detail page.
func (r *Resource) DisplayValues(c *Ctx, fields []Field, row db.Row) map[string]any {
	out := map[string]any{}
	for _, f := range realFields(fields) {
		v := row[f.Name]
		switch {
		case f.Display != nil:
			v = f.Display(row)
		case f.TypeOr() == "checkbox":
			if conv.Bool(v) {
				v = c.T("Yes")
			} else {
				v = c.T("No")
			}
		case len(f.Options) > 0 && v != nil && conv.Str(v) != "":
			if l, ok := OptLabel(f.Options, conv.Str(v)); ok {
				v = l
			} else if l, ok := OptLabel(f.AllOptions, conv.Str(v)); ok {
				v = l
			}
		case f.TypeOr() == "richtext":
			v = HTMLText(v)
		case f.TypeOr() == "password":
			continue
		}
		out[f.Name] = v
	}
	return out
}

// ---------------------------------------------------------------- tables prefs (TablePrefController)

// TablePrefs: per person and list, the hidden columns and rows per page.
func TablePrefs(userID int, resource string) M {
	row := db.First("SELECT hidden, per_page FROM user_table_prefs WHERE user_id = ? AND resource = ?", userID, resource)
	hidden := []string{}
	if row != nil {
		_ = json.Unmarshal([]byte(row.Str("hidden")), &hidden)
		return M{"hidden": hidden, "per_page": row["per_page"]}
	}
	return M{"hidden": hidden, "per_page": nil}
}

// SavedFilters are the filters a person kept on a list (filter name → value).
func SavedFilters(userID int, resource string) map[string]string {
	out := map[string]string{}
	if raw := db.Scalar("SELECT filters::text FROM user_table_prefs WHERE user_id = ? AND resource = ?", userID, resource); raw != nil {
		_ = json.Unmarshal([]byte(conv.Str(raw)), &out)
	}
	return out
}

// SaveFilters keeps (or, when empty, forgets) a person's filters of a list.
func SaveFilters(userID int, resource string, f map[string]string) {
	b, _ := json.Marshal(f)
	db.Exec(`INSERT INTO user_table_prefs (user_id, resource, filters) VALUES (?, ?, ?)
                  ON CONFLICT (user_id, resource) DO UPDATE SET filters = EXCLUDED.filters, updated_at = now()`, userID, resource, string(b))
}

// RememberPageSize keeps the rows-per-page choice.
func RememberPageSize(userID int, resource string, size int) {
	db.Exec(`INSERT INTO user_table_prefs (user_id, resource, per_page) VALUES (?, ?, ?)
                  ON CONFLICT (user_id, resource) DO UPDATE SET per_page = EXCLUDED.per_page, updated_at = now()`, userID, resource, size)
}

var prefResource = regexp.MustCompile(`^[a-z_]{2,60}$`)
var prefCol = regexp.MustCompile(`(?i)^[a-z0-9_]{1,40}$`)

// SaveTablePrefs is POST /table-prefs.
func SaveTablePrefs(c *Ctx) {
	resource := c.Input("resource")
	if !prefResource.MatchString(resource) {
		c.JSON(M{"message": "Unknown list."}, http.StatusUnprocessableEntity)
	}
	hidden := []string{}
	for _, h := range c.Inputs("hidden") {
		if prefCol.MatchString(h) {
			hidden = append(hidden, h)
		}
	}
	var per any
	if p := conv.Int(c.Input("per_page")); okSize(p) {
		per = p
	}
	hj, _ := json.Marshal(hidden)
	db.Exec(`INSERT INTO user_table_prefs (user_id, resource, hidden, per_page) VALUES (?, ?, ?, ?)
                  ON CONFLICT (user_id, resource) DO UPDATE SET hidden = EXCLUDED.hidden, per_page = COALESCE(EXCLUDED.per_page, user_table_prefs.per_page), updated_at = now()`,
		c.MustUser().ID, resource, string(hj), per)
	c.JSON(M{"ok": true})
}
