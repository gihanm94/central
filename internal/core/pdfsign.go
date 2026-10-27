package core

import (
	"encoding/json"
	"math"
	"os"
	"regexp"
	"strings"

	"acme/internal/app"
	"acme/internal/php"
	"acme/internal/signatures"
)

/*
User Management → Sign PDF (PdfSignController). A person uploads a PDF, places their own signature (set by Management)
on its pages, moves and resizes it, and saves. The page stamps the PDF in the browser; the server keeps the original and
the signed copy with fingerprints. People only ever get their own signature and their own signed PDFs.
*/

func psIndex(c *app.Ctx) {
	c.Authorize("pdf_sign", "view")
	u := c.MustUser()
	f := signatures.Filter{Q: strings.TrimSpace(c.Query("q")), From: c.Query("from"), To: c.Query("to"), Page: php.Int(c.Query("page", "1"))}
	res := signatures.List(f, u.ID, 25)
	link := func(page int) string {
		var p any
		if page != 0 {
			p = page
		}
		return c.URL("/sign-pdf", "q", f.Q, "from", f.From, "to", f.To, "page", p)
	}
	c.View("pdf-sign/index", app.M{"title": c.T("Sign PDF"), "f": f, "signature": signatures.Of(u.ID), "rows": signatures.RowsData(c, res, "/sign-pdf", false, link)})
}

func psCreate(c *app.Ctx) {
	c.Authorize("pdf_sign", "create")
	c.View("pdf-sign/editor", app.M{"title": c.T("Sign a PDF"), "signature": signatures.Of(c.MustUser().ID),
		"max": signatures.MaxPdf(), "maxLabel": signatures.SizeLabel(signatures.MaxPdf())})
}

// psMySignature: the signed-in person's own signature (the only one they can ever load).
func psMySignature(c *app.Ctx) {
	c.Authorize("pdf_sign", "view")
	p := signatures.ImagePath(c.MustUser().ID)
	if p == "" {
		app.Abort(404, c.T("You have no signature yet."))
	}
	signatures.PNG(c, p)
}

func psStore(c *app.Ctx) {
	c.Authorize("pdf_sign", "create")
	u := c.MustUser()
	if signatures.ImagePath(u.ID) == "" {
		c.JSON(app.M{"message": c.T("You have no signature yet. Ask Management to add it.")}, 422)
	}
	var raw []map[string]any
	_ = json.Unmarshal([]byte(c.Input("placements", "[]")), &raw)
	if len(raw) > 100 {
		raw = raw[:100]
	}
	places := []signatures.Placement{}
	for _, p := range raw {
		n := func(k string) float64 {
			v, ok := p[k]
			if !ok || !app.IsNumeric(v) {
				return 0
			}
			return math.Max(0, math.Min(1, math.Round(php.Float(v)*1e5)/1e5))
		}
		page := php.Int(p["page"])
		if page >= 1 && n("w") > 0 && n("h") > 0 {
			places = append(places, signatures.Placement{Page: page, X: n("x"), Y: n("y"), W: n("w"), H: n("h")})
		}
	}
	var id int
	if err := app.Catch(func() {
		id = signatures.Record(c, u, c.File("original"), c.File("signed"), places, php.Int(c.Input("pages", "0")))
	}); err != nil {
		if v, ok := err.(*app.ValidationError); ok {
			c.JSON(app.M{"message": v.Errors.First()}, 422)
		}
		panic(err)
	}
	c.Flash("success", c.T("PDF signed and saved."))
	c.JSON(app.M{"ok": true, "url": c.URL("/sign-pdf/" + php.Str(id))})
}

func psShow(c *app.Ctx) {
	c.Authorize("pdf_sign", "view")
	row := signatures.Find(c.MustUser(), c.ParamInt("id"))
	if row == nil {
		app.Abort(404, c.T("This signed PDF is not available."))
	}
	c.View("pdf-sign/show", signatures.ShowData(c, row, [2]string{c.T("Sign PDF"), "/sign-pdf"}, false))
}

var pdfExt = regexp.MustCompile(`(?i)\.pdf$`)

// psFile streams the signed or original PDF: the person who signed it, Management or an administrator.
func psFile(c *app.Ctx) {
	u := c.MustUser()
	id := c.ParamInt("id")
	row := signatures.Find(u, id)
	if row == nil {
		app.Abort(404)
	}
	kind := "signed"
	if c.Param("kind") == "original" {
		kind = "original"
	}
	path := signatures.FilePath(row, kind)
	if path == "" {
		app.Abort(404)
	}
	name := pdfExt.ReplaceAllString(row.Str("title"), "")
	if kind != "original" {
		name += " (signed)"
	}
	name += ".pdf"
	dl := c.Query("download") == "1"
	if dl {
		app.LogActivity(c, app.ActivityEntry{Action: "download", Type: "pdf_signature", ID: id, Label: row.Str("title"), Text: `Downloaded the :kind PDF ":label"`,
			Params: []any{"kind", kind, "label", row["title"]}, NoNotify: true})
	}
	data, err := os.ReadFile(path)
	if err != nil {
		app.Abort(404)
	}
	c.W.Header().Set("Content-Length", php.Str(len(data)))
	if dl {
		c.Download(name, "application/pdf", data)
	}
	c.Inline(name, "application/pdf", data)
}

func init() {
	signedIn(func(r app.Router) {
		r.Get("/sign-pdf", psIndex)
		r.Get("/sign-pdf/new", psCreate)
		r.Get("/sign-pdf/my-signature", psMySignature)
		r.Post("/sign-pdf", psStore)
		r.Get("/sign-pdf/{id}", psShow)
		r.Get("/sign-pdf/{id}/file/{kind}", psFile)
		r.Get("/pdf-sign-log", signatures.LogIndex)
		r.Get("/pdf-sign-log/{id}", signatures.LogShow)
	})
}
