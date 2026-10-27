package management

import (
	"encoding/base64"
	"io"
	"net/url"
	"regexp"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
	"acme/internal/signatures"
)

// Management → Power BI → Overview (PowerBiController): a Power BI report shown full page in an iframe. The report is
// chosen by an administrator under Admin → Company settings → Power BI.
func powerBI(c *app.Ctx) {
	c.Authorize("management", "view")
	c.View("management/powerbi", app.M{"title": c.T("Power BI overview"), "url": app.PowerBiURL(), "isAdmin": c.MustUser().IsAdmin()})
}

// Management → Member signatures (SignatureController): every active member with their signature. Management uploads a
// picture of it or draws it on screen; the person can then place it on PDFs in User Management → Sign PDF.

func sigMember(c *app.Ctx, id int) db.Row {
	m := db.First("SELECT id, name FROM users WHERE id = ? AND deleted_at IS NULL", id)
	if m == nil {
		app.Abort(404)
	}
	return m
}

func sigIndex(c *app.Ctx) {
	c.Authorize("management", "view")
	q := strings.TrimSpace(c.Query("q"))
	dep := php.Int(c.Query("department", "0"))
	has := c.Query("has")
	w := []string{"u.deleted_at IS NULL", "u.is_active"}
	p := []any{}
	if q != "" {
		like := "%" + strings.NewReplacer(`\`, `\\`, "%", `\%`, "_", `\_`).Replace(php.Substr(q, 0, 100)) + "%"
		w = append(w, "(u.name ILIKE ? OR u.email ILIKE ? OR u.username ILIKE ?)")
		p = append(p, like, like, like)
	}
	if dep != 0 {
		w = append(w, "u.department_id = ?")
		p = append(p, dep)
	}
	if has == "yes" {
		w = append(w, "s.user_id IS NOT NULL")
	}
	if has == "no" {
		w = append(w, "s.user_id IS NULL")
	}
	rows := db.Select(`SELECT u.id, u.name, u.avatar, u.email, d.name AS department, ep.job_title, s.source, s.updated_at AS signed_at, ub.name AS signed_by,
                (SELECT count(*) FROM pdf_signatures ps WHERE ps.user_id = u.id) AS pdfs
            FROM users u LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN employee_profiles ep ON ep.user_id = u.id
            LEFT JOIN user_signatures s ON s.user_id = u.id LEFT JOIN users ub ON ub.id = s.updated_by
            WHERE `+strings.Join(w, " AND ")+` ORDER BY (s.user_id IS NULL), u.name LIMIT 500`, p...)
	counts := db.First("SELECT count(*) AS members, count(s.user_id) AS signed FROM users u LEFT JOIN user_signatures s ON s.user_id = u.id WHERE u.deleted_at IS NULL AND u.is_active")
	var cards []app.M
	for _, r := range rows {
		signed := !r.Nil("signed_at")
		sub := []string{}
		for _, s := range []string{r.Str("job_title"), r.Str("department")} {
			if s != "" {
				sub = append(sub, s)
			}
		}
		line := ""
		if signed {
			how := c.T("Uploaded")
			if r.Str("source") == "drawn" {
				how = c.T("Drawn")
			}
			by := "—"
			if !r.Nil("signed_by") {
				by = r.Str("signed_by")
			}
			line = c.T(":how :date by :by", "how", how, "date", app.FormatDate(c, r["signed_at"], "d M Y"), "by", by)
			if r.Int("pdfs") > 0 {
				line += " · " + c.T(":n PDFs", "n", r.Int("pdfs"))
			}
		}
		sl := "—"
		if len(sub) > 0 {
			sl = strings.Join(sub, " · ")
		}
		cards = append(cards, app.M{"r": r, "signed": signed, "sub": sl, "line": line, "v": php.StrToTime(r["signed_at"])})
	}
	var deps []app.Opt
	for _, d := range db.Select("SELECT id, name FROM departments WHERE deleted_at IS NULL ORDER BY name") {
		deps = append(deps, app.Opt{Value: d.Str("id"), Label: d.Str("name")})
	}
	depV := ""
	if dep != 0 {
		depV = php.Str(dep)
	}
	hasOpts := []app.Opt{{Value: "yes", Label: c.T("Has a signature")}, {Value: "no", Label: c.T("No signature yet")}}
	c.View("management/signatures", app.M{"title": c.T("Member signatures"), "rows": cards, "q": q, "dep": depV, "has": has, "counts": counts, "deps": deps, "hasOpts": hasOpts})
}

func sigImage(c *app.Ctx) {
	c.Authorize("management", "view")
	p := signatures.ImagePath(c.ParamInt("id"))
	if p == "" {
		app.Abort(404)
	}
	signatures.PNG(c, p)
}

var drawnRe = regexp.MustCompile(`^data:image/png;base64,([A-Za-z0-9+/=]+)$`)

// sigSave saves from an uploaded image ("file") or the drawing pad ("drawn" = PNG data URL).
func sigSave(c *app.Ctx) {
	c.Authorize("management", "view")
	id := c.ParamInt("id")
	m := sigMember(c, id)
	by := c.MustUser().ID
	how := "upload"
	if drawn := c.Input("drawn"); drawn != "" {
		mm := drawnRe.FindStringSubmatch(drawn)
		if mm == nil {
			app.Invalid("signature", c.T("The drawing could not be read. Try again."))
		}
		data, err := base64.StdEncoding.DecodeString(mm[1])
		if err != nil {
			data = nil
		}
		signatures.Save(c, id, data, "drawn", by)
		how = "drawn"
	} else {
		fh := c.File("file")
		if fh == nil {
			app.Invalid("signature", c.T("Choose an image of the signature, or draw it."))
		}
		r, err := fh.Open()
		if err != nil {
			app.Invalid("signature", c.T("Choose an image of the signature, or draw it."))
		}
		data, _ := io.ReadAll(r)
		r.Close()
		signatures.Save(c, id, data, "upload", by)
	}
	text := "Uploaded the signature of :name"
	if how == "drawn" {
		text = "Drew the signature of :name"
	}
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "signature", ID: id, Label: m.Str("name"), Text: text, Params: []any{"name", m["name"]}, Owner: id, NoNotify: true})
	c.Flash("success", c.T("Signature of :name saved.", "name", m["name"]))
	to := "/management/signatures"
	if c.Input("return") != "" {
		to += "?" + url.Values{"q": {c.Input("q")}}.Encode()
	}
	c.Redirect(to)
}

func sigRemove(c *app.Ctx) {
	c.Authorize("management", "view")
	id := c.ParamInt("id")
	m := sigMember(c, id)
	if signatures.Remove(id) {
		app.LogActivity(c, app.ActivityEntry{Action: "deleted", Type: "signature", ID: id, Label: m.Str("name"), Text: "Removed the signature of :name", Params: []any{"name", m["name"]}, Owner: id, NoNotify: true})
		c.Flash("success", c.T("Signature of :name removed.", "name", m["name"]))
	}
	c.Redirect("/management/signatures")
}
