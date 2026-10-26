package app

import (
	"bytes"
	"html"
	"regexp"
	"strings"

	"acme/internal/php"

	xhtml "golang.org/x/net/html"
	"golang.org/x/net/html/atom"
)

// Rich text (the editor) is stored as a small, safe subset of HTML (App\Core\Support\Html).
//   HTMLClean(posted)  → what goes into the database (unknown tags/attributes removed, images only from our upload folder)
//   c.HTMLRender(stored) → what the page prints (image paths made absolute)

var rtTags = map[string]bool{"p": true, "br": true, "strong": true, "b": true, "em": true, "i": true, "u": true, "s": true, "a": true, "ul": true, "ol": true, "li": true,
	"h1": true, "h2": true, "h3": true, "h4": true, "blockquote": true, "pre": true, "code": true, "span": true, "img": true, "sub": true, "sup": true, "div": true,
	"mark": true, "hr": true, "table": true, "thead": true, "tbody": true, "tr": true, "th": true, "td": true, "colgroup": true, "col": true}
var rtDrop = map[string]bool{"script": true, "style": true, "iframe": true, "object": true, "embed": true, "form": true, "input": true, "button": true, "textarea": true,
	"select": true, "svg": true, "math": true, "link": true, "meta": true, "base": true, "noscript": true, "template": true}
var rtStyleOK = map[string]bool{"text-align": true, "width": true, "height": true, "color": true, "background-color": true}

var (
	qlClass   = regexp.MustCompile(`^(ql-[a-z0-9-]+)( ql-[a-z0-9-]+)*$`)
	styleVal  = regexp.MustCompile(`(?i)^[#a-z0-9%.(),\s-]{1,40}$`)
	styleBad  = regexp.MustCompile(`(?i)url|expression|javascript`)
	hrefOK    = regexp.MustCompile(`(?i)^(https?://|mailto:|tel:)`)
	editorImg = regexp.MustCompile(`(?i)(?:^|/)uploads/editor/([a-f0-9]{24}\.(?:png|jpg|webp|gif))$`)
	imgSize   = regexp.MustCompile(`^\d{1,4}(px|%)?$`)
	tagRe     = regexp.MustCompile(`<[^>]*>`)
	spaceRe   = regexp.MustCompile(`\s+`)
)

// HTMLClean sanitises posted rich text; nil when nothing is left.
func HTMLClean(in any) any {
	s := strings.TrimSpace(php.Str(in))
	if s == "" || php.Len(s) > 200000 {
		return nil
	}
	ctx := &xhtml.Node{Type: xhtml.ElementNode, Data: "div", DataAtom: atom.Div}
	nodes, err := xhtml.ParseFragment(strings.NewReader(s), ctx)
	if err != nil {
		return nil
	}
	root := &xhtml.Node{Type: xhtml.ElementNode, Data: "div", DataAtom: atom.Div}
	for _, n := range nodes {
		root.AppendChild(n)
	}
	walkRT(root)
	var buf bytes.Buffer
	for ch := root.FirstChild; ch != nil; ch = ch.NextSibling {
		_ = xhtml.Render(&buf, ch)
	}
	out := strings.TrimSpace(strings.ReplaceAll(strings.ReplaceAll(buf.String(), "&nbsp;", " "), " ", " "))
	text := strings.TrimSpace(html.UnescapeString(tagRe.ReplaceAllString(out, "")))
	if text != "" || strings.Contains(out, "<img") {
		return out
	}
	return nil
}

func walkRT(n *xhtml.Node) {
	for ch := n.FirstChild; ch != nil; {
		next := ch.NextSibling
		switch ch.Type {
		case xhtml.CommentNode:
			n.RemoveChild(ch)
		case xhtml.ElementNode:
			tag := strings.ToLower(ch.Data)
			switch {
			case rtDrop[tag]:
				n.RemoveChild(ch)
			case !rtTags[tag]: // unknown tag: keep its content
				walkRT(ch)
				for ch.FirstChild != nil {
					c := ch.FirstChild
					ch.RemoveChild(c)
					n.InsertBefore(c, ch)
				}
				n.RemoveChild(ch)
			case !rtAttributes(ch, tag):
				n.RemoveChild(ch)
			default:
				walkRT(ch)
			}
		}
		ch = next
	}
}

func rtAttributes(el *xhtml.Node, tag string) bool {
	var keep []xhtml.Attribute
	hasSrc, hasHref := false, false
	for _, a := range el.Attr {
		name := strings.ToLower(a.Key)
		val := a.Val
		ok := false
		switch {
		case name == "class":
			ok = qlClass.MatchString(val)
		case name == "style":
			var safe []string
			for _, decl := range strings.Split(val, ";") {
				k, v, _ := strings.Cut(decl, ":")
				k, v = strings.ToLower(strings.TrimSpace(k)), strings.TrimSpace(v)
				if rtStyleOK[k] && styleVal.MatchString(v) && !styleBad.MatchString(v) {
					safe = append(safe, k+": "+v)
				}
			}
			if len(safe) > 0 {
				keep = append(keep, xhtml.Attribute{Key: "style", Val: strings.Join(safe, "; ")})
			}
			continue
		case tag == "a" && name == "href":
			ok = hrefOK.MatchString(strings.TrimSpace(val))
			hasHref = ok
		case tag == "img" && name == "src":
			m := editorImg.FindStringSubmatch(strings.TrimSpace(val))
			if m == nil {
				return false
			}
			keep = append(keep, xhtml.Attribute{Key: "src", Val: "uploads/editor/" + strings.ToLower(m[1])})
			hasSrc = true
			continue
		case tag == "img" && (name == "width" || name == "height"):
			ok = imgSize.MatchString(strings.TrimSpace(val))
		case tag == "img" && name == "alt":
			ok = php.Len(val) < 200
		case name == "data-list" && tag == "li":
			ok = php.InList(val, "bullet", "ordered", "checked", "unchecked")
		}
		if ok {
			keep = append(keep, xhtml.Attribute{Key: name, Val: val})
		}
	}
	el.Attr = keep
	if tag == "img" && !hasSrc {
		return false
	}
	if tag == "a" && hasHref {
		el.Attr = append(el.Attr, xhtml.Attribute{Key: "target", Val: "_blank"}, xhtml.Attribute{Key: "rel", Val: "noopener noreferrer"})
	}
	return true
}

// HTMLRender: stored rich text → HTML for the browser.
func (c *Ctx) HTMLRender(stored any) string {
	s := php.Str(stored)
	if s == "" {
		return ""
	}
	return strings.ReplaceAll(s, `src="uploads/editor/`, `src="`+html.EscapeString(c.BaseURL())+`/uploads/editor/`)
}

// HTMLText: the plain text of stored rich text.
func HTMLText(stored any) string {
	s := php.Str(stored)
	for _, t := range []string{"</p>", "<br>", "<br/>", "</li>", "</h1>", "</h2>", "</h3>", "</h4>", "</td>", "</th>"} {
		s = strings.ReplaceAll(s, t, " ")
	}
	return strings.TrimSpace(spaceRe.ReplaceAllString(html.UnescapeString(tagRe.ReplaceAllString(s, "")), " "))
}
