package core

import (
	"errors"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"

	"acme/internal/app"
	"acme/internal/conv"
)

// Sandboxed file operations for the admin file manager (App\Core\Support\Files). Everything happens inside one of a
// few named roots; a path is a relative path inside a root and can never leave it (no "..", no symlink out, no hidden
// dot-files, no executable uploads). Failures are thrown (panic with an error), like the PHP RuntimeException.

var filesBlocked = []string{"php", "php3", "php4", "php5", "php7", "php8", "phtml", "pht", "phar", "phps", "htaccess", "htpasswd", "ini", "cgi", "pl", "sh", "bat", "cmd", "exe", "dll", "so",
	"html", "htm", "xhtml", "shtml", "svg", "svgz", "js", "mjs", "xml"} // could run script in the browser from public/uploads

// FileRoot is one named folder.
type FileRoot struct {
	Key, Label, Dir string
}

// FileRoots are the folders the file manager shows, in order.
func FileRoots(c *app.Ctx) []FileRoot {
	b := app.BasePath
	return []FileRoot{
		{"uploads", c.T("Uploads (public)"), b + "/public/uploads"},
		{"attachments", c.T("Attachments"), b + "/storage/attachments"},
		{"inet", c.T("E-tax files"), b + "/storage/inet"},
		{"billing", c.T("Billing notes"), b + "/storage/billing"},
		{"mail", c.T("Mail log"), b + "/storage/mail"},
	}
}

func fileRootOf(c *app.Ctx, key string) (FileRoot, bool) {
	for _, r := range FileRoots(c) {
		if r.Key == key {
			return r, true
		}
	}
	return FileRoot{}, false
}

func filesFail(msg string) { panic(errors.New(msg)) }

// FilesRoot is the real path of a root (created when missing).
func FilesRoot(c *app.Ctx, key string) string {
	r, ok := fileRootOf(c, key)
	if !ok {
		filesFail(c.T("Unknown folder."))
	}
	if st, err := os.Stat(r.Dir); err != nil || !st.IsDir() {
		_ = os.MkdirAll(r.Dir, 0o775)
	}
	real, err := filepath.EvalSymlinks(r.Dir)
	if err != nil {
		filesFail(c.T("The folder is not available."))
	}
	abs, _ := filepath.Abs(real)
	return abs
}

var ctrlRe = regexp.MustCompile(`[\x00-\x1f]`)
var sepRe = regexp.MustCompile(`[\\/]+`)

// FilesClean: "a/b" → clean segments, or throw.
func FilesClean(c *app.Ctx, rel string) string {
	var parts []string
	for _, p := range sepRe.Split(strings.Trim(rel, "/\\ "), -1) {
		if p == "" || p == "." {
			continue
		}
		if p == ".." || strings.HasPrefix(p, ".") || ctrlRe.MatchString(p) {
			filesFail(c.T("That path is not allowed."))
		}
		parts = append(parts, p)
	}
	return strings.Join(parts, "/")
}

func exists(p string) bool { _, err := os.Lstat(p); return err == nil }

func realpath(p string) (string, bool) {
	r, err := filepath.EvalSymlinks(p)
	if err != nil {
		return "", false
	}
	a, err := filepath.Abs(r)
	return a, err == nil
}

// FilesAbs is the absolute path of a relative path; it must exist unless mayCreate, and must stay inside the root.
func FilesAbs(c *app.Ctx, rootKey, rel string, mayCreate bool) string {
	root := FilesRoot(c, rootKey)
	rel = FilesClean(c, rel)
	path := root
	if rel != "" {
		path = root + "/" + rel
	}
	inside := func(p string) bool { return p == root || strings.HasPrefix(p, root+"/") }
	if !mayCreate || exists(path) {
		real, ok := realpath(path)
		if !ok || !inside(real) {
			filesFail(c.T("Not found."))
		}
		return real
	}
	parent, ok := realpath(filepath.Dir(path)) // a new thing: its folder must exist inside the root
	if !ok || !inside(parent) {
		filesFail(c.T("Not found."))
	}
	return parent + "/" + filepath.Base(path)
}

// FilesName checks a plain file / folder name.
func FilesName(c *app.Ctx, n string) string {
	n = strings.TrimSpace(strings.NewReplacer("\x00", "", "/", "", "\\", "").Replace(n))
	if n == "" || n == "." || n == ".." || strings.HasPrefix(n, ".") || conv.Len(n) > 180 {
		filesFail(c.T("Use a plain name (no slashes, not starting with a dot)."))
	}
	if conv.InList(strings.ToLower(extOf(n)), filesBlocked...) {
		filesFail(c.T("Files of this type are not allowed."))
	}
	return n
}

// extOf is pathinfo($n, PATHINFO_EXTENSION).
func extOf(n string) string {
	i := strings.LastIndexByte(n, '.')
	if i < 0 {
		return ""
	}
	return n[i+1:]
}

// filenameOf is pathinfo($n, PATHINFO_FILENAME).
func filenameOf(n string) string {
	i := strings.LastIndexByte(n, '.')
	if i < 0 {
		return n
	}
	return n[:i]
}

// FileEntry is one listed file or folder.
type FileEntry struct {
	Name, Rel, Ext, Parent string
	Dir                    bool
	Size, Mtime            int64
}

// FilesList is one folder, sorted and paged; or a recursive name search.
func FilesList(c *app.Ctx, rootKey, rel, q, sortKey, dir string, page, per int) ([]FileEntry, int) {
	root := FilesRoot(c, rootKey)
	base := FilesAbs(c, rootKey, rel, false)
	rows := []FileEntry{}
	relOf := func(p string) string { return strings.TrimLeft(strings.TrimPrefix(p, root), "/") }
	add := func(full string) {
		name := filepath.Base(full)
		lst, err := os.Lstat(full)
		if strings.HasPrefix(name, ".") || err != nil || lst.Mode()&os.ModeSymlink != 0 {
			return
		}
		st, _ := os.Stat(full)
		e := FileEntry{Name: name, Rel: relOf(full), Dir: st.IsDir(), Mtime: st.ModTime().Unix(), Parent: relOf(filepath.Dir(full))}
		if !e.Dir {
			e.Size = st.Size()
			e.Ext = strings.ToLower(extOf(name))
		}
		rows = append(rows, e)
	}
	if q != "" { // search below the current folder (stops at 20 000 entries)
		needle := strings.ToLower(q)
		n := 0
		stop := errors.New("stop")
		_ = filepath.WalkDir(base, func(p string, d os.DirEntry, err error) error {
			if err != nil || p == base {
				return nil
			}
			n++
			if n > 20000 {
				return stop
			}
			if strings.Contains(strings.ToLower(d.Name()), needle) {
				add(p)
			}
			return nil
		})
	} else {
		ents, _ := os.ReadDir(base)
		for _, e := range ents {
			add(base + "/" + e.Name())
		}
	}
	key := sortKey
	if !conv.InList(key, "name", "size", "mtime", "ext") {
		key = "name"
	}
	mul := 1
	if dir == "desc" {
		mul = -1
	}
	sort.SliceStable(rows, func(i, j int) bool {
		a, b := rows[i], rows[j]
		if a.Dir != b.Dir {
			return a.Dir // folders first
		}
		cmp := 0
		switch key {
		case "name":
			cmp = natCaseCmp(a.Name, b.Name)
		case "size":
			cmp = cmp3(a.Size, b.Size)
		case "mtime":
			cmp = cmp3(a.Mtime, b.Mtime)
		case "ext":
			cmp = strings.Compare(a.Ext, b.Ext)
		}
		if cmp == 0 {
			return natCaseCmp(a.Name, b.Name) < 0
		}
		return cmp*mul < 0
	})
	total := len(rows)
	from := min(total, (page-1)*per)
	to := min(total, from+per)
	return rows[from:to], total
}

func cmp3(a, b int64) int {
	switch {
	case a < b:
		return -1
	case a > b:
		return 1
	}
	return 0
}

// natCaseCmp is strnatcasecmp.
func natCaseCmp(a, b string) int {
	a, b = strings.ToLower(a), strings.ToLower(b)
	i, j := 0, 0
	isDigit := func(c byte) bool { return c >= '0' && c <= '9' }
	for i < len(a) && j < len(b) {
		ca, cb := a[i], b[j]
		if isDigit(ca) && isDigit(cb) {
			si := i
			for i < len(a) && isDigit(a[i]) {
				i++
			}
			sj := j
			for j < len(b) && isDigit(b[j]) {
				j++
			}
			na := strings.TrimLeft(a[si:i], "0")
			nb := strings.TrimLeft(b[sj:j], "0")
			if len(na) != len(nb) {
				if len(na) < len(nb) {
					return -1
				}
				return 1
			}
			if c := strings.Compare(na, nb); c != 0 {
				return c
			}
			continue
		}
		if ca != cb {
			if ca < cb {
				return -1
			}
			return 1
		}
		i++
		j++
	}
	switch {
	case len(a)-i < len(b)-j:
		return -1
	case len(a)-i > len(b)-j:
		return 1
	}
	return 0
}

// FilesFolders are all folders below the root (for the "move to" list), capped; "" = the root itself.
func FilesFolders(c *app.Ctx, rootKey string, limit int) []string {
	root := FilesRoot(c, rootKey)
	out := []string{""}
	stop := errors.New("stop")
	_ = filepath.WalkDir(root, func(p string, d os.DirEntry, err error) error {
		if err != nil || p == root {
			return nil
		}
		if d.IsDir() && d.Type()&os.ModeSymlink == 0 && !strings.HasPrefix(d.Name(), ".") {
			out = append(out, strings.TrimLeft(strings.TrimPrefix(p, root), "/"))
		}
		if len(out) >= limit {
			return stop
		}
		return nil
	})
	sort.SliceStable(out, func(i, j int) bool { return natCaseCmp(out[i], out[j]) < 0 })
	return out
}

// FilesDelete removes a file or a folder with everything in it.
func FilesDelete(abs string) {
	if st, err := os.Lstat(abs); err == nil && st.IsDir() {
		ents, _ := os.ReadDir(abs)
		for _, e := range ents {
			FilesDelete(abs + "/" + e.Name())
		}
		_ = os.Remove(abs)
	} else {
		_ = os.Remove(abs)
	}
}

// FilesSize is Files::size(): "12 KB".
func FilesSize(b int64) string {
	f := float64(b)
	for i, u := range []string{"B", "KB", "MB", "GB"} {
		if f < 1024 || i == 3 {
			if i == 0 {
				return conv.Str(b) + " " + u
			}
			dec := 0
			if f < 10 {
				dec = 1
			}
			return conv.NF(f, dec) + " " + u
		}
		f /= 1024
	}
	return ""
}
