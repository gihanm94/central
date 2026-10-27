// Package signatures is App\Core\Support\Signatures: signatures and signed PDFs.
//   - One signature image per person (storage/signatures, never public): uploaded or drawn by Management → Member signatures.
//     Images are cleaned on the way in: re-drawn as PNG (no hidden data), a white paper background turned transparent,
//     trimmed to the ink and kept at a sensible size.
//   - Signed PDFs (storage/pdf-signs): the original and the signed copy of every signing, with fingerprints and placements.
//     People see their own; Management and administrators see all of them.
//
// The PDF log pages (PdfSignLogController) live here too: they are reached from Administrator (/pdf-sign-log) and
// Management (/management/pdf-signs).
package signatures

import (
	"bytes"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"io"
	"math"
	"mime/multipart"
	"os"
	"path/filepath"
	"regexp"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

// MaxPDF is the per-file limit.
const MaxPDF = 20 * 1024 * 1024

const (
	sigDir = "storage/signatures"
	pdfDir = "storage/pdf-signs"
)

// MaxPdf is the largest PDF that can be signed here: 20 MB, or less when the upload limit is lower (the original and
// the signed copy travel in one request, so each gets half of it). The Go server takes forms of up to 64 MB.
func MaxPdf() int {
	up := MaxPDF
	post := 64 << 20
	return max(512*1024, min(MaxPDF, up, post/2-64*1024))
}

// SizeLabel is Files::size(): 512 B, 1.5 KB, 20 MB.
func SizeLabel(b int) string {
	f := float64(b)
	for i, u := range []string{"B", "KB", "MB", "GB"} {
		if f < 1024 || i == 3 {
			if i == 0 {
				return php.Str(b) + " " + u
			}
			dec := 0
			if f < 10 {
				dec = 1
			}
			return php.NF(f, dec) + " " + u
		}
		f /= 1024
	}
	return ""
}

// CanAudit: Management and administrators manage signatures and read the signing log.
func CanAudit(u *app.CurrentUser) bool { return u.IsAdmin() || u.Can("management", "view") }

func dir(sub string) string {
	d := filepath.Join(app.BasePath, filepath.FromSlash(sub))
	_ = os.MkdirAll(d, 0o770)
	ht := filepath.Join(app.BasePath, "storage", ".htaccess")
	if _, err := os.Stat(ht); err != nil { // storage is outside the web root; this is a second lock
		_ = os.WriteFile(ht, []byte("Require all denied\n"), 0o644)
	}
	return d
}

func randHex(n int) string {
	b := make([]byte, n)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

/* ------------------------------------------------------------ signatures */

// Of is a person's user_signatures row (or nil).
func Of(userID int) db.Row {
	return db.First("SELECT * FROM user_signatures WHERE user_id = ?", userID)
}

var sigFile = regexp.MustCompile(`^[a-f0-9]{24}\.png$`)

// ImagePath is the absolute path of a person's signature PNG, or "".
func ImagePath(userID int) string {
	row := Of(userID)
	if row == nil || !sigFile.MatchString(row.Str("file")) {
		return ""
	}
	p := filepath.Join(dir(sigDir), row.Str("file"))
	if st, err := os.Stat(p); err != nil || st.IsDir() {
		return ""
	}
	return p
}

// Save stores a signature from image bytes (an uploaded PNG/JPG, or the PNG a signature pad drew); stops with a
// validation error when the image cannot be used.
func Save(c *app.Ctx, userID int, data []byte, source string, by int) {
	if len(data) == 0 || len(data) > 5*1024*1024 {
		app.Invalid("signature", c.T("Use an image of 5 MB or smaller."))
	}
	mime := app.DetectMime(data[:min(len(data), 512)])
	if !php.InList(mime, "image/png", "image/jpeg", "image/webp") {
		app.Invalid("signature", c.T("Use a PNG, JPG or WebP image."))
	}
	w, h, ok := imageSize(data)
	if ok && w*h > 25_000_000 {
		app.Invalid("signature", c.T("This image is too large. Use a smaller photo."))
	}
	src, err := decodeImage(data)
	if err != nil {
		app.Invalid("signature", c.T("This image could not be read."))
	}
	png := clean(src, source == "upload")
	if png == nil {
		app.Invalid("signature", c.T("No signature found in the image. Use a dark signature on a light background."))
	}
	name := randHex(12) + ".png"
	_ = os.WriteFile(filepath.Join(dir(sigDir), name), png, 0o640)
	old := Of(userID)
	src2 := "upload"
	if source == "drawn" {
		src2 = "drawn"
	}
	db.Exec(`INSERT INTO user_signatures (user_id, file, source, updated_by, updated_at) VALUES (?, ?, ?, ?, now())
                  ON CONFLICT (user_id) DO UPDATE SET file = EXCLUDED.file, source = EXCLUDED.source, updated_by = EXCLUDED.updated_by, updated_at = now()`,
		userID, name, src2, by)
	if old != nil && sigFile.MatchString(old.Str("file")) {
		_ = os.Remove(filepath.Join(dir(sigDir), old.Str("file")))
	}
}

// Remove deletes a person's signature; false when there was none.
func Remove(userID int) bool {
	old := Of(userID)
	if old == nil {
		return false
	}
	db.Exec("DELETE FROM user_signatures WHERE user_id = ?", userID)
	if sigFile.MatchString(old.Str("file")) {
		_ = os.Remove(filepath.Join(dir(sigDir), old.Str("file")))
	}
	return true
}

/* ---------------------------------------------------------- signed PDFs */

func isPdf(data []byte) bool { return bytes.Contains(data[:min(len(data), 1024)], []byte("%PDF-")) }

// Placement is where one signature went (fractions of the page).
type Placement struct {
	Page int     `json:"page"`
	X    float64 `json:"x"`
	Y    float64 `json:"y"`
	W    float64 `json:"w"`
	H    float64 `json:"h"`
}

var ctrl = regexp.MustCompile(`[\x00-\x1F\x7F]+`)

// Record keeps one signing: both files (checked to be PDFs), fingerprints and where the signatures went.
func Record(c *app.Ctx, u *app.CurrentUser, original, signed *multipart.FileHeader, placements []Placement, pages int) int {
	tooBig := c.T("PDF files must be :size or smaller.", "size", SizeLabel(MaxPdf()))
	var bodies [2][]byte
	for i, f := range []*multipart.FileHeader{original, signed} {
		if f == nil {
			app.Invalid("file", c.T("The upload failed. Try a smaller file."))
		}
		if f.Size > int64(MaxPdf()) {
			app.Invalid("file", tooBig)
		}
		r, err := f.Open()
		if err != nil {
			app.Invalid("file", c.T("The upload failed. Try a smaller file."))
		}
		data, err := io.ReadAll(r)
		r.Close()
		if err != nil {
			app.Invalid("file", c.T("The upload failed. Try a smaller file."))
		}
		if !isPdf(data) {
			app.Invalid("file", c.T("This is not a PDF file."))
		}
		bodies[i] = data
	}
	if len(placements) == 0 {
		app.Invalid("file", c.T("Place your signature on the document first."))
	}
	year := php.Date("Y", php.Now())
	d := dir(pdfDir + "/" + year)
	key := randHex(12)
	orig := year + "/" + key + "-original.pdf"
	sign := year + "/" + key + "-signed.pdf"
	_ = os.WriteFile(filepath.Join(d, key+"-original.pdf"), bodies[0], 0o640)
	_ = os.WriteFile(filepath.Join(d, key+"-signed.pdf"), bodies[1], 0o640)
	title := php.Substr(strings.TrimSpace(ctrl.ReplaceAllString(original.Filename, " ")), 0, 200)
	if title == "" {
		title = "document.pdf"
	}
	sum := func(b []byte) string { h := sha256.Sum256(b); return hex.EncodeToString(h[:]) }
	pj, _ := json.Marshal(placements)
	id := db.Core.InsertID("pdf_signatures", db.Cols{
		{Name: "user_id", Value: u.ID}, {Name: "user_name", Value: u.Name}, {Name: "department_id", Value: u.DeptID()}, {Name: "title", Value: title},
		{Name: "original_file", Value: orig}, {Name: "signed_file", Value: sign},
		{Name: "original_sha256", Value: sum(bodies[0])}, {Name: "signed_sha256", Value: sum(bodies[1])},
		{Name: "original_size", Value: len(bodies[0])}, {Name: "signed_size", Value: len(bodies[1])},
		{Name: "pages", Value: max(0, min(100000, pages))}, {Name: "placements", Value: string(pj)},
		{Name: "ip_address", Value: c.IP()}, {Name: "user_agent", Value: c.UserAgent()},
	})
	app.LogActivity(c, app.ActivityEntry{Action: "created", Type: "pdf_signature", ID: id, Label: title, Text: `Signed the PDF ":label"`, Params: []any{"label", title},
		Props: app.M{"signatures": len(placements), "pages": pages, "sha256": db.Scalar("SELECT signed_sha256 FROM pdf_signatures WHERE id = ?", id)}, NoNotify: true})
	return id
}

// Find is one signing this person may open: their own, or any for Management / administrators.
func Find(u *app.CurrentUser, id int) db.Row {
	row := db.First(`SELECT s.*, u.avatar, d.name AS department_name FROM pdf_signatures s LEFT JOIN users u ON u.id = s.user_id
                          LEFT JOIN departments d ON d.id = s.department_id WHERE s.id = ?`, id)
	if row == nil || (row.Int("user_id") != u.ID && !CanAudit(u)) {
		return nil
	}
	return row
}

var pdfFile = regexp.MustCompile(`^\d{4}/[a-f0-9]{24}-(original|signed)\.pdf$`)

// FilePath is the absolute path of the original or signed PDF, or "".
func FilePath(row db.Row, kind string) string {
	rel := row.Str("signed_file")
	if kind == "original" {
		rel = row.Str("original_file")
	}
	if !pdfFile.MatchString(rel) {
		return ""
	}
	p := filepath.Join(app.BasePath, filepath.FromSlash(pdfDir), filepath.FromSlash(rel))
	if st, err := os.Stat(p); err != nil || st.IsDir() {
		return ""
	}
	return p
}

// Filter of the signing log.
type Filter struct {
	Q, From, To string
	User, Page  int
}

// Result is one page of the log.
type Result struct {
	Rows               []db.Row
	Total, Page, Pages int
}

var ymd = regexp.MustCompile(`^\d{4}-\d{2}-\d{2}$`)

// List is the signing log; onlyUser > 0: one person's own list.
func List(f Filter, onlyUser int, per int) Result {
	w := []string{"TRUE"}
	p := []any{}
	if onlyUser > 0 {
		w = append(w, "s.user_id = ?")
		p = append(p, onlyUser)
	} else if f.User != 0 {
		w = append(w, "s.user_id = ?")
		p = append(p, f.User)
	}
	if q := strings.TrimSpace(f.Q); q != "" {
		like := "%" + strings.NewReplacer(`\`, `\\`, "%", `\%`, "_", `\_`).Replace(php.Substr(q, 0, 100)) + "%"
		w = append(w, "(s.title ILIKE ? OR s.user_name ILIKE ?)")
		p = append(p, like, like)
	}
	if ymd.MatchString(f.From) {
		w = append(w, "s.created_at >= ?::date")
		p = append(p, f.From)
	}
	if ymd.MatchString(f.To) {
		w = append(w, "s.created_at < ?::date + 1")
		p = append(p, f.To)
	}
	where := strings.Join(w, " AND ")
	page := max(1, f.Page)
	total := php.Int(db.Scalar("SELECT count(*) FROM pdf_signatures s WHERE "+where, p...))
	rows := db.Select(`SELECT s.id, s.user_id, s.user_name, s.title, s.pages, jsonb_array_length(s.placements) AS signs, s.signed_size, s.created_at,
                u.avatar, d.name AS department_name
            FROM pdf_signatures s LEFT JOIN users u ON u.id = s.user_id LEFT JOIN departments d ON d.id = s.department_id
            WHERE `+where+` ORDER BY s.created_at DESC, s.id DESC LIMIT `+php.Str(per)+` OFFSET `+php.Str((page-1)*per), p...)
	return Result{Rows: rows, Total: total, Page: page, Pages: max(1, int(math.Ceil(float64(total)/float64(per))))}
}

/* ---------------------------------------------------------- responses */

// PNG streams a signature image (PdfSignController::png).
func PNG(c *app.Ctx, path string) {
	data, err := os.ReadFile(path)
	if err != nil {
		app.Abort(404)
	}
	c.W.Header().Set("Content-Length", php.Str(len(data)))
	c.Inline("signature.png", "image/png", data)
}
