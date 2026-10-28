package app

import (
	"io"
	"mime/multipart"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strings"

	"acme/internal/conv"
	"acme/internal/db"
)

// Safe image uploads into public/uploads/<folder> (App\Core\Support\Upload). Returns "folder/file.ext".

var imageTypes = map[string]string{"image/png": "png", "image/jpeg": "jpg", "image/webp": "webp", "image/svg+xml": "svg", "image/gif": "gif"}
var svgScript = regexp.MustCompile(`(?i)<script|on\w+\s*=|javascript:`)

// DetectMime sniffs a file's type like finfo (with the SVG / XML special cases the PHP code relied on).
func DetectMime(head []byte) string {
	m := http.DetectContentType(head)
	if i := strings.IndexByte(m, ';'); i >= 0 {
		m = m[:i]
	}
	if m == "text/xml" || m == "text/plain" || m == "application/octet-stream" {
		s := strings.ToLower(string(head))
		if strings.Contains(s, "<svg") {
			return "image/svg+xml"
		}
	}
	return m
}

// UploadImage stores an uploaded picture; maxKB is the size limit.
func UploadImage(c *Ctx, fh *multipart.FileHeader, folder string, maxKB int, allowSVG bool) string {
	if fh == nil {
		Invalid(folder, "The upload failed. Try a smaller file.")
	}
	if fh.Size > int64(maxKB)*1024 {
		Invalid(folder, "Images must be "+conv.NumberClean(float64(maxKB)/1024)+" MB or smaller.")
	}
	f, err := fh.Open()
	if err != nil {
		Invalid(folder, "The upload failed. Try a smaller file.")
	}
	defer f.Close()
	data, _ := io.ReadAll(f)
	mime := DetectMime(data)
	if (mime == "text/xml" || mime == "text/plain") && strings.HasSuffix(strings.ToLower(fh.Filename), ".svg") {
		mime = "image/svg+xml"
	}
	ext, ok := imageTypes[mime]
	if !ok || (mime == "image/svg+xml" && !allowSVG) {
		msg := "Use a PNG, JPG or WebP image"
		if allowSVG {
			msg += " (or SVG)"
		}
		Invalid(folder, msg+".")
	}
	if mime == "image/svg+xml" && svgScript.Match(data) {
		Invalid(folder, "This SVG contains scripts and was rejected.")
	}
	dir := filepath.Join(BasePath, "public", "uploads", folder)
	_ = os.MkdirAll(dir, 0o775)
	name := randHex(12) + "." + ext
	if err := os.WriteFile(filepath.Join(dir, name), data, 0o664); err != nil {
		Invalid(folder, "The upload failed. Try a smaller file.")
	}
	return folder + "/" + name
}

// DeleteUpload removes public/uploads/<path>.
func DeleteUpload(path string) {
	if path != "" && !strings.Contains(path, "..") {
		_ = os.Remove(filepath.Join(BasePath, "public", "uploads", filepath.FromSlash(path)))
	}
}

// ---------------------------------------------------------------- editor pictures (EditorImages)

var editorRef = regexp.MustCompile(`(?i)uploads/(editor/[a-f0-9]{24}\.(?:png|jpg|webp|gif))`)
var editorFile = regexp.MustCompile(`^editor/[a-f0-9]{24}\.(png|jpg|webp|gif)$`)

// EditorImagesIn lists editor/<name>.<ext> of every picture in a text.
func EditorImagesIn(html any) []string {
	seen := map[string]bool{}
	var out []string
	for _, m := range editorRef.FindAllStringSubmatch(conv.Str(html), -1) {
		f := strings.ToLower(m[1])
		if !seen[f] {
			seen[f] = true
			out = append(out, f)
		}
	}
	return out
}

// EditorImageUploaded notes a new upload (not used anywhere yet).
func EditorImageUploaded(file string, userID int) {
	var u any
	if userID > 0 {
		u = userID
	}
	db.Exec("INSERT INTO editor_images (file, created_by) VALUES (?, ?) ON CONFLICT (file) DO NOTHING", file, u)
}

// EditorImagesSaved: the field ref (conn.table.id.field) now holds html, it held before; pictures taken out are deleted
// when nothing else uses them.
func EditorImagesSaved(ref string, html, before any, stillUsed func(file string) bool) {
	now := EditorImagesIn(html)
	was := EditorImagesIn(before)
	for _, f := range now {
		db.Exec("INSERT INTO editor_images (file) VALUES (?) ON CONFLICT (file) DO NOTHING", f)
		db.Exec("INSERT INTO editor_image_uses (file, ref) VALUES (?, ?) ON CONFLICT DO NOTHING", f, ref)
	}
	sql := "DELETE FROM editor_image_uses WHERE ref = ?"
	args := []any{ref}
	if len(now) > 0 {
		sql += " AND file NOT IN (" + strings.TrimSuffix(strings.Repeat("?,", len(now)), ",") + ")"
		for _, f := range now {
			args = append(args, f)
		}
	}
	db.Exec(sql, args...)
	in := map[string]bool{}
	for _, f := range now {
		in[f] = true
	}
	for _, f := range was {
		if in[f] {
			continue
		}
		if db.Scalar("SELECT 1 FROM editor_image_uses WHERE file = ? LIMIT 1", f) == nil && !stillUsed(f) {
			removeEditorImage(f)
		}
	}
}

// EditorImagesSweep removes uploads older than a day that no record took.
func EditorImagesSweep() {
	for _, r := range db.Select(`SELECT i.file FROM editor_images i WHERE i.created_at < now() - interval '1 day'
                               AND NOT EXISTS (SELECT 1 FROM editor_image_uses u WHERE u.file = i.file) LIMIT 200`) {
		removeEditorImage(r.Str("file"))
	}
}

func removeEditorImage(file string) {
	if editorFile.MatchString(file) {
		DeleteUpload(file)
	}
	db.Exec("DELETE FROM editor_images WHERE file = ?", file)
}
