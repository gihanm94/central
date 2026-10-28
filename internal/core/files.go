package core

import (
	"crypto/md5"
	"encoding/hex"
	"html/template"
	"io"
	"net/url"
	"os"
	"path/filepath"
	"strings"

	"acme/internal/app"
	"acme/internal/conv"
)

// Administrators (FileController): look at the files the system keeps, search them, move / rename / delete / upload /
// download.

func init() {
	signedIn(func(r app.Router) {
		r.Get("/files", filesIndex)
		r.Get("/files/download", filesDownload)
		r.Post("/files/upload", filesUpload)
		r.Post("/files/mkdir", filesMkdir)
		r.Post("/files/rename", filesRename)
		r.Post("/files/move", filesMove)
		r.Post("/files/delete", filesDeleteAction)
	})
}

func filesGate(c *app.Ctx) {
	if !c.MustUser().IsAdmin() {
		app.Abort(403, c.T("Only an administrator can open this."))
	}
}

func filesRootKey(c *app.Ctx) string {
	r := c.Input("root", c.Query("root", "uploads"))
	if _, ok := fileRootOf(c, r); ok {
		return r
	}
	return "uploads"
}

func filesBack(c *app.Ctx, root, path string) {
	c.Redirect("/files?" + filesQuery("root", root, "path", path))
}

// filesQuery is http_build_query(array_filter([...])).
func filesQuery(kv ...string) string {
	var parts []string
	for i := 0; i+1 < len(kv); i += 2 {
		if kv[i+1] != "" && kv[i+1] != "0" {
			parts = append(parts, phpEscape(kv[i])+"="+phpEscape(kv[i+1]))
		}
	}
	return strings.Join(parts, "&")
}

func filesFailBack(c *app.Ctx, root, path string, err error) {
	c.Flash("error", err.Error())
	filesBack(c, root, path)
}

func filesIndex(c *app.Ctx) {
	filesGate(c)
	root := filesRootKey(c)
	path := ""
	if err := app.Catch(func() { path = FilesClean(c, c.Query("path", "")); FilesAbs(c, root, path, false) }); err != nil {
		path = ""
	}
	per := conv.Int(c.Query("per_page", "20"))
	if !conv.InList(conv.Str(per), "10", "20", "50", "100") {
		per = 20
	}
	page := max(1, conv.Int(c.Query("page", "1")))
	q := c.Query("q", "")
	sortKey := c.Query("sort", "name")
	dir := strings.ToLower(c.Query("dir", "asc"))
	rows, total := FilesList(c, root, path, q, sortKey, dir, page, per)
	if dir != "desc" {
		dir = "asc"
	}
	roots := FileRoots(c)
	rootLabel := ""
	for _, r := range roots {
		if r.Key == root {
			rootLabel = r.Label
		}
	}
	// the page's links (PHP built them with closures in the view)
	link := func(extra ...string) string {
		vals := map[string]string{"root": root, "path": path, "q": q, "sort": "", "dir": "", "per_page": ""}
		if sortKey != "name" {
			vals["sort"] = sortKey
		}
		if dir == "desc" {
			vals["dir"] = "desc"
		}
		if per != 20 {
			vals["per_page"] = conv.Str(per)
		}
		// PHP: [...] + $extra — keys already in the array win, so only new keys (page) are taken from extra
		for i := 0; i+1 < len(extra); i += 2 {
			if _, ok := vals[extra[i]]; !ok {
				vals[extra[i]] = extra[i+1]
			}
		}
		return c.URL("/files", "root", vals["root"], "path", vals["path"], "q", vals["q"], "sort", vals["sort"], "dir", vals["dir"], "per_page", vals["per_page"], "page", vals["page"])
	}
	type sortHead struct {
		URL, Label, Arrow string
	}
	head := func(col, label string) sortHead {
		next := "asc"
		if sortKey == col && dir == "asc" {
			next = "desc"
		}
		s, d := col, ""
		if col == "name" {
			s = ""
		}
		if next == "desc" {
			d = "desc"
		}
		arrow := ""
		if sortKey == col {
			arrow = " ▼"
			if dir == "asc" {
				arrow = " ▲"
			}
		}
		return sortHead{link("sort", s, "dir", d, "page", ""), label, arrow}
	}
	type trailItem struct{ Seg, URL string }
	var trail []trailItem
	acc := ""
	for _, seg := range strings.Split(path, "/") {
		if seg == "" || seg == "0" {
			continue
		}
		if acc != "" {
			acc += "/"
		}
		acc += seg
		trail = append(trail, trailItem{seg, c.URL("/files", "root", root, "path", acc)})
	}
	type view struct {
		FileEntry
		ID, Icon, Size, Modified, Kind, TypeLabel, Open, Download, DeleteURL string
	}
	var list []view
	for _, r := range rows {
		sum := md5.Sum([]byte(r.Rel))
		v := view{FileEntry: r, ID: hex.EncodeToString(sum[:]), Modified: conv.Date("d M Y, H:i", conv.Unix(r.Mtime)),
			Download: c.URL("/files/download", "root", root, "path", r.Rel), DeleteURL: c.URL("/files/delete", "root", root, "path", path, "item", r.Rel)}
		if r.Dir {
			v.Icon, v.Size, v.Kind, v.TypeLabel = "folder", "—", c.T("folder"), c.T("Folder")
			v.Open = c.URL("/files", "root", root, "path", r.Rel)
		} else {
			v.Icon = "doc"
			if conv.InList(r.Ext, "png", "jpg", "jpeg", "gif", "webp", "svg") {
				v.Icon = "eye"
			}
			v.Size, v.Kind = FilesSize(r.Size), c.T("file")
			v.TypeLabel = strings.ToUpper(r.Ext)
			if v.TypeLabel == "" {
				v.TypeLabel = "—"
			}
		}
		list = append(list, v)
	}
	up := ""
	if path != "" && q == "" {
		parent := ""
		if strings.Contains(path, "/") {
			parent = filepath.Dir(path)
		}
		up = c.URL("/files", "root", root, "path", parent)
	}
	type folderOpt struct{ Value, Label string }
	var folders []folderOpt
	for _, f := range FilesFolders(c, root, 400) {
		l := "/" + f
		if f == "" {
			l = "/ (" + c.T("top folder") + ")"
		}
		folders = append(folders, folderOpt{f, l})
	}
	c.View("admin/files", app.M{"title": c.T("File manager"), "roots": roots, "root": root, "rootLabel": rootLabel, "path": path, "rows": list, "total": total,
		"perPage": per, "page": page, "q": q, "folders": folders, "sort": sortKey, "dir": dir, "trail": trail, "up": up,
		"clearURL": link("q", ""), "hName": head("name", c.T("Name")), "hExt": head("ext", c.T("Type")), "hSize": head("size", c.T("Size")), "hMtime": head("mtime", c.T("Modified")),
		"bulkURL":  c.URL("/files/delete", "root", root, "path", path),
		"labels":   template.JS("{ mkdir: " + jsonCompatAscii(c.T("New folder")) + ", rename: " + jsonCompatAscii(c.T("Rename")) + ", move: " + jsonCompatAscii(c.T("Move to…")) + " }"),
		"filesURL": template.JS(jsonCompat(c.URL("/files/")))})
}

func filesDownload(c *app.Ctx) {
	filesGate(c)
	var abs string
	if err := app.Catch(func() { abs = FilesAbs(c, filesRootKey(c), c.Query("path", ""), false) }); err != nil {
		app.Abort(404)
	}
	st, err := os.Stat(abs)
	if err != nil || !st.Mode().IsRegular() {
		app.Abort(404)
	}
	name := filepath.Base(abs)
	app.LogActivity(c, app.ActivityEntry{Action: "download", Type: "file", Label: name, Text: `Downloaded the file ":name"`, Params: []any{"name", name}, Props: app.M{}, NoNotify: true})
	f, err := os.Open(abs)
	if err != nil {
		app.Abort(404)
	}
	defer f.Close()
	data, _ := io.ReadAll(f)
	c.Download(name, "application/octet-stream", data)
}

func filesUpload(c *app.Ctx) {
	filesGate(c)
	root := filesRootKey(c)
	path := FilesClean(c, c.Input("path", ""))
	if err := app.Catch(func() {
		dir := FilesAbs(c, root, path, false)
		n := 0
		for _, fh := range c.Files("files") {
			name := FilesName(c, fh.Filename)
			target := dir + "/" + name
			if exists(target) {
				name = filenameOf(name) + "-" + conv.Date("His", conv.Now()) + "." + extOf(name)
				target = dir + "/" + name
			}
			src, err := fh.Open()
			if err != nil {
				continue
			}
			out, err := os.OpenFile(target, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o644)
			if err == nil {
				if _, err = io.Copy(out, src); err == nil {
					n++
				}
				out.Close()
			}
			src.Close()
		}
		label := path
		if label == "" {
			label = root
		}
		app.LogActivity(c, app.ActivityEntry{Action: "created", Type: "file", Label: label, Text: `Uploaded :n file(s) to ":p"`, Params: []any{"n", n, "p", root + "/" + path}, Props: app.M{}, NoNotify: true})
		if n > 0 {
			c.Flash("success", c.T(":n file(s) uploaded.", "n", n))
		} else {
			c.Flash("error", c.T("Nothing was uploaded."))
		}
	}); err != nil {
		filesFailBack(c, root, path, err)
	}
	filesBack(c, root, path)
}

func filesMkdir(c *app.Ctx) {
	filesGate(c)
	root := filesRootKey(c)
	path := FilesClean(c, c.Input("path", ""))
	if err := app.Catch(func() {
		name := FilesName(c, c.Input("name", ""))
		rel := name
		if path != "" {
			rel = path + "/" + name
		}
		abs := FilesAbs(c, root, rel, true)
		if exists(abs) {
			filesFail(c.T("That name is already used."))
		}
		_ = os.Mkdir(abs, 0o775)
		c.Flash("success", c.T("Folder created."))
	}); err != nil {
		filesFailBack(c, root, path, err)
	}
	filesBack(c, root, path)
}

func filesRename(c *app.Ctx) {
	filesGate(c)
	root := filesRootKey(c)
	path := FilesClean(c, c.Input("path", ""))
	if err := app.Catch(func() {
		if strings.Trim(c.Input("item", ""), "/ ") == "" {
			filesFail(c.T("Choose a file or folder to rename.")) // never the root folder itself
		}
		from := FilesAbs(c, root, c.Input("item", ""), false)
		name := FilesName(c, c.Input("name", ""))
		to := filepath.Dir(from) + "/" + name
		if exists(to) {
			filesFail(c.T("That name is already used."))
		}
		_ = os.Rename(from, to)
		app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "file", Label: name, Text: `Renamed ":a" to ":b"`, Params: []any{"a", filepath.Base(from), "b", name}, Props: app.M{}, NoNotify: true})
		c.Flash("success", c.T("Renamed."))
	}); err != nil {
		filesFailBack(c, root, path, err)
	}
	filesBack(c, root, path)
}

// filesMove moves one or more items into a folder of the same root.
func filesMove(c *app.Ctx) {
	filesGate(c)
	root := filesRootKey(c)
	path := FilesClean(c, c.Input("path", ""))
	if err := app.Catch(func() {
		destRel := FilesClean(c, c.Input("dest", ""))
		dest := FilesAbs(c, root, destRel, false)
		if st, err := os.Stat(dest); err != nil || !st.IsDir() {
			filesFail(c.T("Choose a folder."))
		}
		n := 0
		for _, item := range c.Inputs("items") {
			from := FilesAbs(c, root, item, false)
			to := dest + "/" + filepath.Base(from)
			if st, err := os.Stat(from); err == nil && st.IsDir() && (dest == from || strings.HasPrefix(dest+"/", from+"/")) {
				filesFail(c.T("A folder cannot go inside itself."))
			}
			if exists(to) {
				filesFail(c.T("“:n” already exists there.", "n", filepath.Base(from)))
			}
			if os.Rename(from, to) == nil {
				n++
			}
		}
		label := destRel
		if label == "" {
			label = root
		}
		app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "file", Label: label, Text: `Moved :n item(s) to ":p"`, Params: []any{"n", n, "p", root + "/" + destRel}, Props: app.M{}, NoNotify: true})
		c.Flash("success", c.T(":n item(s) moved.", "n", n))
	}); err != nil {
		filesFailBack(c, root, path, err)
	}
	filesBack(c, root, path)
}

// filesDeleteAction deletes one (item in the query) or several (ids[]) — the word DELETE must be typed.
func filesDeleteAction(c *app.Ctx) {
	filesGate(c)
	root := filesRootKey(c)
	path := FilesClean(c, c.Input("path", ""))
	if c.Input("confirm") != "DELETE" {
		c.Flash("error", c.T("Type DELETE to confirm."))
		filesBack(c, root, path)
	}
	items := c.Inputs("ids")
	if one := c.Query("item", ""); one != "" {
		items = append(items, one)
	}
	n := 0
	if err := app.Catch(func() {
		for _, item := range items {
			abs := FilesAbs(c, root, item, false)
			if abs == FilesRoot(c, root) {
				filesFail(c.T("The top folder cannot be deleted."))
			}
			FilesDelete(abs)
			n++
		}
		app.LogActivity(c, app.ActivityEntry{Action: "deleted", Type: "file", Label: root + "/" + path, Text: `Deleted :n file(s) or folder(s) in ":p"`, Params: []any{"n", n, "p", root + "/" + path}, Props: app.M{}, NoNotify: true})
		c.Flash("success", c.T(":n deleted.", "n", n))
	}); err != nil {
		c.Flash("error", err.Error())
	}
	filesBack(c, root, path)
}

func phpEscape(s string) string { return strings.ReplaceAll(url.QueryEscape(s), "~", "%7E") }
