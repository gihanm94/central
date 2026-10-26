package app

import (
	"html/template"
	"strings"

	"acme/internal/db"
	"acme/internal/php"
)

// FormStep is one section of a form (a wizard step when the form has several).
type FormStep struct {
	Index  int
	Title  string
	Icon   string
	Help   string
	Fields []FormField
	Req    bool // has required fields
}

// FormField is a field ready for resource/form (the PHP form computed these inline).
type FormField struct {
	Field
	Value    string
	Err      string
	Req      bool
	ReqAttr  template.HTMLAttr
	Opts     []Opt
	OtherVal string
	OtherOn  bool
	Picked   []string
	Checked  bool
	RowVal   any // the stored value (file / logo fields)
}

// FormView is everything resource/form needs about the fields.
type FormView struct {
	Steps   []FormStep
	ErrStep int
	All     []Field
}

// FormFields groups the fields into steps and works out each field's value, error and marks.
func FormFields(c *Ctx, fields []Field, row db.Row) *FormView {
	v := &FormView{All: fields}
	cur := FormStep{Title: c.T("Details")}
	for _, f := range fields {
		if f.IsSection() {
			if len(cur.Fields) > 0 {
				v.Steps = append(v.Steps, cur)
			}
			cur = FormStep{Title: f.Section, Icon: f.Icon, Help: f.Help}
			continue
		}
		if f.HideForm {
			continue
		}
		cur.Fields = append(cur.Fields, formField(c, f, row))
	}
	if len(cur.Fields) > 0 {
		v.Steps = append(v.Steps, cur)
	}
	errs := c.Sess.Errors()
	seenOne := map[string]bool{}
	for i := range v.Steps {
		v.Steps[i].Index = i
		for j := range v.Steps[i].Fields {
			ff := &v.Steps[i].Fields[j]
			if ff.OneOf != "" || ff.Req {
				v.Steps[i].Req = true
			}
			// required marks: a field, or a group (one_of) where one filled field is enough — its first field carries the mark
			if ff.OneOf != "" {
				first := !seenOne[ff.OneOf]
				seenOne[ff.OneOf] = true
				a := `data-one-of="` + template.HTMLEscapeString(ff.OneOf) + `"`
				if first {
					msg := ff.OneOfMsg
					if msg == "" {
						msg = c.T("Fill in at least one of these.")
					}
					a += ` data-required data-missing-text="` + template.HTMLEscapeString(msg) + `"`
				}
				ff.ReqAttr = template.HTMLAttr(a)
			} else if ff.Req {
				ff.ReqAttr = "data-required"
			}
		}
	}
	for i, st := range v.Steps {
		for _, ff := range st.Fields {
			if errs.Has(ff.Name) {
				v.ErrStep = i
				return v
			}
		}
	}
	return v
}

func formField(c *Ctx, f Field, row db.Row) FormField {
	ff := FormField{Field: f, Err: c.Sess.Errors().Get(f.Name), Req: f.Required()}
	if row != nil {
		ff.RowVal = row[f.Name]
	}
	// value: old input, else the row, else the default
	var raw any
	if o, ok := c.Sess.Old(f.Name); ok {
		raw = o
	} else if row != nil && row.Has(f.Name) && row[f.Name] != nil {
		raw = row[f.Name]
	} else if f.Default != nil {
		raw = f.Default
	} else {
		raw = ""
	}
	val := php.Str(raw)
	switch f.TypeOr() {
	case "date":
		if val != "" {
			val = php.Substr(val, 0, 10)
		}
	case "datetime":
		if val != "" {
			val = php.DateOf("Y-m-d\\TH:i", val)
		}
	case "number":
		if val != "" {
			val = strings.ReplaceAll(php.NumberClean(val), ",", "")
		}
	case "richtext":
		val = c.HTMLRender(val)
	case "checkbox":
		var cv any
		if o, ok := c.Sess.Old(f.Name); ok {
			cv = o
		} else if row != nil {
			cv = row[f.Name]
		} else {
			cv = f.Default
		}
		ff.Checked = php.Bool(cv)
	case "multi", "picker":
		if o, ok := c.Sess.Old(f.Name); ok {
			ff.Picked = InStrings(o)
		} else if row != nil {
			ff.Picked = toStrings(row[f.Name+"_ids"])
		}
	case "select":
		opts := append([]Opt{}, f.Options...)
		if f.Other { // the list plus "Other…", which opens a box to type in
			other := ""
			if o, ok := c.Sess.Old(f.Name + "__other"); ok {
				other = php.Str(o)
			} else if val != "" {
				if _, known := OptLabel(opts, val); !known {
					other = val
				}
			}
			ff.OtherVal = other
			ff.OtherOn = val == "__other" || other != ""
			if ff.OtherOn {
				val = "__other"
			}
			opts = append(opts, Opt{Value: "__other", Label: c.T("Other…")})
		}
		if val != "" {
			if _, known := OptLabel(opts, val); !known {
				opts = append(append([]Opt{}, f.AllOptions...), opts...)
			}
		}
		ff.Opts = opts
	}
	ff.Value = val
	return ff
}

// ShowItem is one line of the detail page.
type ShowItem struct {
	Field
	Text    string
	Img     string
	ImgOnly bool // a photo field (no label)
	IsPic   bool // a picture dropdown entry (person with photo)
	Empty   bool
}

// ShowFields: what resource/show prints for each field (sections stay as headings).
func ShowFields(c *Ctx, fields []Field, row db.Row) []ShowItem {
	var out []ShowItem
	for _, f := range fields {
		if f.IsSection() {
			out = append(out, ShowItem{Field: f})
			continue
		}
		v := row[f.Name]
		t := f.TypeOr()
		it := ShowItem{Field: f}
		switch {
		case t == "password":
			continue
		case t == "checkbox":
			if php.Bool(v) {
				it.Text = c.T("Yes")
			} else {
				it.Text = c.T("No")
			}
		case v == nil || php.Str(v) == "":
			it.Text, it.Empty = "—", true
		case t == "file":
			it.IsPic, it.ImgOnly, it.Img = true, true, c.UploadURL(v)
		case len(f.Options) > 0:
			o, ok := findOpt(f.Options, php.Str(v))
			if !ok {
				o, ok = findOpt(f.AllOptions, php.Str(v))
			}
			if ok {
				if o.Img != "" || o.Sub != "" {
					it.IsPic, it.Img, it.Text = true, o.Img, o.Label
				} else {
					it.Text = o.Label
				}
			} else {
				it.Text = php.Str(v)
			}
		case t == "date":
			it.Text = FormatDate(c, v, "d M Y")
		case t == "datetime":
			it.Text = FormatDate(c, v, "d M Y H:i")
		case t == "number":
			it.Text = php.NumberClean(v)
		default:
			it.Text = php.Str(v)
		}
		out = append(out, it)
	}
	return out
}

func findOpt(opts []Opt, v string) (Opt, bool) {
	for _, o := range opts {
		if o.Value == v {
			return o, true
		}
	}
	return Opt{}, false
}

// Pager is the arithmetic of partials/pagination.
type Pager struct {
	Page, Pages, From, To   int
	First, Prev, Next, Last string
}

func pagerFor(c *Ctx, total, perPage, page int) Pager {
	pages := max(1, (total+max(1, perPage)-1)/max(1, perPage))
	page = min(max(page, 1), pages)
	link := func(p int) string {
		q := map[string]any{}
		for k, v := range c.QueryMap() {
			q[k] = v
		}
		if p > 1 {
			q["page"] = p
		} else {
			delete(q, "page")
		}
		return c.URL(c.Path(), q)
	}
	from := 0
	if total > 0 {
		from = (page-1)*perPage + 1
	}
	return Pager{Page: page, Pages: pages, From: from, To: min(total, page*perPage), First: link(1), Prev: link(page - 1), Next: link(page + 1), Last: link(pages)}
}

// PickChoice is one choice of a picker field (Field.Extra["choices"]).
type PickChoice struct {
	ID    string
	Label string
	Color string
}

type pickerView struct {
	Max     int
	JSON    template.JS
	Picked  []string
	Chips   []PickChoice
	Choices []PickChoice
}

var hexColor = regexpMust(`(?i)^#[0-9a-f]{6}$`)

func pickerData(f Field, picked []string) pickerView {
	choices, _ := f.Extra["choices"].([]PickChoice)
	byID := map[string]PickChoice{}
	var out []PickChoice
	for _, ch := range choices {
		if !hexColor.MatchString(ch.Color) {
			ch.Color = "#8a909a"
		}
		byID[ch.ID] = ch
		out = append(out, ch)
	}
	v := pickerView{Max: php.Int(f.Extra["max"]), Choices: out}
	// options keep their order in the JSON object
	var b strings.Builder
	b.WriteString("{")
	for i, ch := range out {
		if i > 0 {
			b.WriteString(",")
		}
		b.WriteString(jsonString(ch.ID) + `:{"label":` + jsonString(ch.Label) + `,"color":` + jsonString(ch.Color) + `}`)
	}
	b.WriteString("}")
	v.JSON = template.JS(b.String())
	for _, id := range picked {
		if ch, ok := byID[id]; ok {
			v.Picked = append(v.Picked, id)
			v.Chips = append(v.Chips, ch)
		}
	}
	return v
}
