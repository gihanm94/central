package signatures

import (
	"encoding/json"
	"sort"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

/*
The PDF signing log (PdfSignLogController): who signed which PDF and when, with a preview of each signed copy.
Shown in Administrator → PDF signing log (/pdf-sign-log) and Management → PDF signing log (/management/pdf-signs).
*/

func logBase(c *app.Ctx) string {
	if strings.HasPrefix(c.Path(), "/management/") {
		return "/management/pdf-signs"
	}
	return "/pdf-sign-log"
}

func gate(c *app.Ctx) {
	if !CanAudit(c.MustUser()) {
		app.Abort(403)
	}
}

func nz(v any) any {
	if php.Empty(v) {
		return nil
	}
	return v
}

// RowsData is what the pdf-sign/rows partial needs; link builds the page links of the list.
func RowsData(c *app.Ctx, r Result, base string, showPerson bool, link func(page int) string) app.M {
	rows := make([]app.M, 0, len(r.Rows))
	for _, x := range r.Rows {
		rows = append(rows, app.M{"r": x, "size": SizeLabel(x.Int("signed_size"))})
	}
	return app.M{"rows": rows, "page": r.Page, "pages": r.Pages, "base": base, "showPerson": showPerson,
		"prev": link(r.Page - 1), "next": link(r.Page + 1)}
}

// LogIndex is PdfSignLogController::index.
func LogIndex(c *app.Ctx) {
	gate(c)
	f := Filter{Q: strings.TrimSpace(c.Query("q")), User: php.Int(c.Query("user")), From: c.Query("from"), To: c.Query("to"), Page: php.Int(c.Query("page", "1"))}
	var people []app.Opt
	for _, r := range db.Select("SELECT DISTINCT u.id, u.name FROM pdf_signatures s JOIN users u ON u.id = s.user_id ORDER BY u.name") {
		people = append(people, app.Opt{Value: r.Str("id"), Label: r.Str("name")})
	}
	base := logBase(c)
	res := List(f, 0, 25)
	link := func(page int) string {
		return c.URL(base, "q", f.Q, "user", nz(f.User), "from", f.From, "to", f.To, "page", nz(page))
	}
	user := ""
	if f.User != 0 {
		user = php.Str(f.User)
	}
	c.View("pdf-sign/log", app.M{"title": c.T("PDF signing log"), "f": f, "user": user, "total": res.Total, "people": people, "base": base,
		"rows": RowsData(c, res, base, true, link)})
}

// ShowData is what pdf-sign/show needs.
func ShowData(c *app.Ctx, row db.Row, back [2]string, audit bool) app.M {
	var places []map[string]any
	_ = json.Unmarshal([]byte(row.Str("placements")), &places)
	byPage := map[int]int{}
	for _, p := range places {
		byPage[php.Int(p["page"])]++
	}
	pages := make([]int, 0, len(byPage))
	for p := range byPage {
		pages = append(pages, p)
	}
	sort.Ints(pages)
	parts := []string{}
	for _, p := range pages {
		s := c.T("page :p", "p", p)
		if byPage[p] > 1 {
			s += " ×" + php.Str(byPage[p])
		}
		parts = append(parts, s)
	}
	return app.M{"title": row["title"], "row": row, "back": back, "audit": audit, "signs": len(places), "byPage": strings.Join(parts, ", "),
		"signedSize": SizeLabel(row.Int("signed_size")), "originalSize": SizeLabel(row.Int("original_size"))}
}

// LogShow is PdfSignLogController::show.
func LogShow(c *app.Ctx) {
	gate(c)
	row := Find(c.MustUser(), c.ParamInt("id"))
	if row == nil {
		app.Abort(404)
	}
	c.View("pdf-sign/show", ShowData(c, row, [2]string{c.T("PDF signing log"), logBase(c)}, true))
}
