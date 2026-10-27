package crm

import (
	"bytes"
	"crypto/rand"
	"encoding/hex"
	"io"
	"mime/multipart"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"unicode/utf8"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

// Attachments: files on CRM records. They are kept in storage/attachments (not reachable from the web)
// under a random name and only handed out by the download route after the record check.

const attMaxBytes = 10 * 1024 * 1024

// extension => allowed mime types (the sniffed type is the judge, the browser's claim is ignored)
var attTypes = []struct {
	Ext   string
	Mimes []string
}{
	{"pdf", []string{"application/pdf"}},
	{"png", []string{"image/png"}}, {"jpg", []string{"image/jpeg"}}, {"jpeg", []string{"image/jpeg"}}, {"webp", []string{"image/webp"}}, {"gif", []string{"image/gif"}},
	{"txt", []string{"text/plain"}}, {"csv", []string{"text/plain", "text/csv", "application/csv"}},
	{"doc", []string{"application/msword", "application/x-ole-storage", "application/CDFV2"}},
	{"xls", []string{"application/vnd.ms-excel", "application/x-ole-storage", "application/CDFV2"}},
	{"ppt", []string{"application/vnd.ms-powerpoint", "application/x-ole-storage", "application/CDFV2"}},
	{"docx", []string{"application/vnd.openxmlformats-officedocument.wordprocessingml.document", "application/zip", "application/octet-stream"}},
	{"xlsx", []string{"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", "application/zip", "application/octet-stream"}},
	{"pptx", []string{"application/vnd.openxmlformats-officedocument.presentationml.presentation", "application/zip", "application/octet-stream"}},
	{"zip", []string{"application/zip", "application/x-zip-compressed"}},
}

func attExtensions() []string {
	out := make([]string, len(attTypes))
	for i, t := range attTypes {
		out[i] = t.Ext
	}
	return out
}

func attDir() string {
	d := filepath.Join(app.BasePath, "storage", "attachments")
	_ = os.MkdirAll(d, 0o775)
	return d
}

// sniff is finfo's MIME type for the kinds of files the CRM accepts.
func sniff(data []byte) string {
	switch {
	case bytes.HasPrefix(data, []byte("%PDF")):
		return "application/pdf"
	case bytes.HasPrefix(data, []byte{0xD0, 0xCF, 0x11, 0xE0, 0xA1, 0xB1, 0x1A, 0xE1}):
		return "application/CDFV2"
	case bytes.HasPrefix(data, []byte("PK\x03\x04")):
		head := data
		if len(head) > 4096 {
			head = head[:4096]
		}
		switch {
		case bytes.Contains(head, []byte("word/")):
			return "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
		case bytes.Contains(head, []byte("xl/")):
			return "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
		case bytes.Contains(head, []byte("ppt/")):
			return "application/vnd.openxmlformats-officedocument.presentationml.presentation"
		}
		return "application/zip"
	}
	m := app.DetectMime(data)
	if strings.HasPrefix(m, "text/") && m != "text/html" && m != "text/xml" {
		if utf8.Valid(data) || !bytes.ContainsRune(data, 0) {
			return "text/plain"
		}
	}
	if m == "text/html" || m == "text/xml" {
		return "text/plain"
	}
	return m
}

var ctrlName = regexp.MustCompile(`[\x00-\x1f\\/]+`)

func cleanName(name string) string {
	name = strings.ReplaceAll(name, `\`, "/")
	if i := strings.LastIndexByte(name, '/'); i >= 0 {
		name = name[i+1:]
	}
	name = strings.TrimSpace(ctrlName.ReplaceAllString(name, ""))
	if name == "" {
		name = "file"
	}
	return php.Substr(name, 0, 200)
}

var nonASCIIName = regexp.MustCompile(`[^A-Za-z0-9._ -]+`)

func asciiName(name string) string {
	s := nonASCIIName.ReplaceAllString(name, "_")
	if s == "" {
		return "file"
	}
	return s
}

type checkedFile struct {
	Name, Ext, Mime string
	Data            []byte
	Size            int64
}

// attCheck stops with a validation error if the file may not be stored.
func attCheck(c *app.Ctx, fh *multipart.FileHeader) checkedFile {
	name := cleanName(fh.Filename)
	f, err := fh.Open()
	if err != nil {
		app.Invalid("files", c.T(`":name" could not be uploaded. Try a smaller file.`, "name", name))
	}
	defer f.Close()
	if fh.Size > attMaxBytes {
		app.Invalid("files", c.T(`":name" is larger than :mb MB.`, "name", name, "mb", attMaxBytes/1048576))
	}
	data, err := io.ReadAll(f)
	if err != nil {
		app.Invalid("files", c.T(`":name" could not be uploaded. Try a smaller file.`, "name", name))
	}
	ext := strings.ToLower(strings.TrimPrefix(filepath.Ext(name), "."))
	mime := sniff(data)
	ok := false
	for _, t := range attTypes {
		if t.Ext == ext && inStrs(mime, t.Mimes) {
			ok = true
		}
	}
	if !ok {
		app.Invalid("files", c.T(`":name" is not an allowed file type. Allowed: :types.`, "name", name, "types", strings.Join(attExtensions(), ", ")))
	}
	return checkedFile{Name: name, Ext: ext, Mime: mime, Data: data, Size: fh.Size}
}

func attStore(c *app.Ctx, fh *multipart.FileHeader, typ string, id, userID int) db.Row {
	f := attCheck(c, fh)
	b := make([]byte, 16)
	_, _ = rand.Read(b)
	stored := hex.EncodeToString(b) + "." + f.Ext
	if err := os.WriteFile(filepath.Join(attDir(), stored), f.Data, 0o664); err != nil {
		app.Invalid("files", c.T(`":name" could not be saved.`, "name", f.Name))
	}
	row := db.Row{"entity_type": typ, "entity_id": id, "original_name": f.Name, "stored_name": stored, "mime": f.Mime, "size": int(f.Size), "uploaded_by": userID}
	row["id"] = db.On("crm").InsertID("attachments", db.Cols{{Name: "entity_type", Value: typ}, {Name: "entity_id", Value: id}, {Name: "original_name", Value: f.Name},
		{Name: "stored_name", Value: stored}, {Name: "mime", Value: f.Mime}, {Name: "size", Value: int(f.Size)}, {Name: "uploaded_by", Value: userID}})
	return row
}

func attForRecord(typ string, id int) []db.Row {
	rows := db.On("crm").Select("SELECT * FROM attachments WHERE entity_type = ? AND entity_id = ? ORDER BY created_at DESC, id DESC", typ, id)
	ns := names("users", col(rows, "uploaded_by"))
	for _, r := range rows {
		if n, ok := ns[r.Int("uploaded_by")]; ok {
			r["uploader"] = n
		} else {
			r["uploader"] = "—"
		}
	}
	return rows
}

func attSend(c *app.Ctx, row db.Row) {
	path := filepath.Join(attDir(), filepath.Base(row.Str("stored_name")))
	data, err := os.ReadFile(path)
	if err != nil {
		app.Abort(http.StatusNotFound, c.T("The file is missing on the server."))
	}
	mime := row.Str("mime")
	inline := strings.HasPrefix(mime, "image/") || mime == "application/pdf"
	ct := "application/octet-stream"
	if inline {
		ct = mime
	}
	hd := c.W.Header()
	hd.Set("Content-Length", strconv.Itoa(len(data)))
	hd.Set("Content-Security-Policy", "default-src 'none'; style-src 'unsafe-inline'; sandbox")
	if inline && c.QueryHas("view") {
		c.Inline(row.Str("original_name"), ct, data)
	}
	c.Download(row.Str("original_name"), ct, data)
}

func attRemove(row db.Row) {
	db.On("crm").Exec("DELETE FROM attachments WHERE id = ?", row["id"])
	_ = os.Remove(filepath.Join(attDir(), filepath.Base(row.Str("stored_name"))))
}

func humanSize(bytes int) string {
	if bytes >= 1048576 {
		return php.NF(float64(bytes)/1048576, 1) + " MB"
	}
	return php.Str(max(1, int(phpRound(float64(bytes)/1024, 0)))) + " KB"
}
