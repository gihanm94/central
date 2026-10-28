package machines

import (
	"crypto/rand"
	"encoding/hex"
	"io"
	"mime/multipart"
	"os"
	"path/filepath"
	"regexp"
	"strings"

	"acme/internal/app"
	"acme/internal/conv"
)

/*
Documents of a machine (manuals, work instructions, certificates, warranty papers) — Support\Files.
Stored outside public/ in storage/machines under a random name; the database keeps a JSON list
[{"f": "<random>.pdf", "n": "Manual.pdf", "s": 12345}] and people download through /machines/file?f=<random>.pdf (signed-in only).
*/

const filesMaxBytes = 15 * 1048576

var fileExt = []string{"pdf", "doc", "docx", "xls", "xlsx", "ppt", "pptx", "txt", "csv", "png", "jpg", "jpeg", "webp", "zip"}

// fileEntry is one stored document.
type fileEntry struct {
	F string `json:"f"`
	N string `json:"n"`
	S int    `json:"s"`
}

func filesDir() string {
	d := filepath.Join(app.BasePath, "storage", "machines")
	if st, err := os.Stat(d); err != nil || !st.IsDir() {
		_ = os.MkdirAll(d, 0o775)
	}
	return d
}

// filesSaveMany saves every file of a multiple-file input and returns their descriptions.
func filesSaveMany(c *app.Ctx, field string) []fileEntry {
	var out []fileEntry
	for _, fh := range c.Files(field) {
		out = append(out, filesSaveOne(c, fh, field))
	}
	return out
}

var badNameChars = regexp.MustCompile(`[\r\n/\\]+`)

func filesSaveOne(c *app.Ctx, fh *multipart.FileHeader, field string) fileEntry {
	f, err := fh.Open()
	if err != nil {
		app.Invalid(field, c.T(`The upload of ":f" failed. Try a smaller file.`, "f", fh.Filename))
	}
	defer f.Close()
	if fh.Size > filesMaxBytes {
		app.Invalid(field, c.T(`":f" is larger than :mb MB.`, "f", fh.Filename, "mb", filesMaxBytes/1048576))
	}
	ext := strings.ToLower(strings.TrimPrefix(filepath.Ext(fh.Filename), "."))
	if !conv.InList(ext, fileExt...) {
		app.Invalid(field, c.T(`":f": this file type is not allowed (:types).`, "f", fh.Filename, "types", strings.Join(fileExt, ", ")))
	}
	b := make([]byte, 12)
	_, _ = rand.Read(b)
	stored := hex.EncodeToString(b) + "." + ext
	out, err := os.OpenFile(filepath.Join(filesDir(), stored), os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o644)
	if err != nil {
		app.Invalid(field, c.T(`The upload of ":f" failed. Try a smaller file.`, "f", fh.Filename))
	}
	_, _ = io.Copy(out, f)
	_ = out.Close()
	return fileEntry{F: stored, N: conv.Substr(badNameChars.ReplaceAllString(fh.Filename, "_"), 0, 160), S: int(fh.Size)}
}

// filesDecode: JSON column → list.
func filesDecode(v any) []fileEntry {
	var out []fileEntry
	for _, x := range jsonList(v) {
		if conv.Empty(x["f"]) {
			continue
		}
		out = append(out, fileEntry{F: conv.Str(x["f"]), N: conv.Str(x["n"]), S: conv.Int(conv.Str(x["s"]))})
	}
	return out
}

func filesEncode(list []fileEntry) any {
	if len(list) == 0 {
		return nil
	}
	return jsonCompat(list, flagUnicode)
}

func filesNames(list []fileEntry) []string {
	out := make([]string, 0, len(list))
	for _, x := range list {
		out = append(out, x.F)
	}
	return out
}

// filesMerge keeps the files the form still lists (names in keep) and adds new ones; the others are deleted.
func filesMerge(current any, keep []string, added []fileEntry) any {
	var kept []fileEntry
	for _, x := range filesDecode(current) {
		if conv.InList(x.F, keep...) {
			kept = append(kept, x)
		} else {
			_ = os.Remove(filepath.Join(filesDir(), filepath.Base(x.F)))
		}
	}
	return filesEncode(append(kept, added...))
}

var storedName = regexp.MustCompile(`^[a-f0-9]{24}\.[a-z0-9]{2,5}$`)

func filesPath(stored string) string {
	base := filepath.Base(stored)
	p := filepath.Join(filesDir(), base)
	if !storedName.MatchString(base) {
		return ""
	}
	if st, err := os.Stat(p); err != nil || st.IsDir() {
		return ""
	}
	return p
}

// filesLabel is the original name of a stored file (searched in the given JSON columns).
func filesLabel(stored string, jsonColumns ...any) string {
	for _, j := range jsonColumns {
		for _, x := range filesDecode(j) {
			if x.F == stored {
				return x.N
			}
		}
	}
	return ""
}

// keepList is (array) Request::all()['keep_<col>'] as strings.
func keepList(c *app.Ctx, col string) []string {
	return app.InStrings(c.InputAny("keep_" + col))
}

// fileView is one file for the views (size in KB like number_format($s / 1024, 0)).
type fileView struct {
	F, N, KB string
	On       bool
}

func filesView(list []fileEntry) []fileView {
	out := make([]fileView, 0, len(list))
	for _, x := range list {
		out = append(out, fileView{F: x.F, N: x.N, KB: conv.NF(float64(x.S)/1024, 0), On: true})
	}
	return out
}
