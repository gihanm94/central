package crm

import (
	"html/template"
	"regexp"
	"strings"

	"acme/internal/app"
	"acme/internal/conv"
	"acme/internal/db"
)

// A person at a lead (company). Can have several mobile numbers (ContactController).

var contacts = (&screen{
	entity: "contact", prefix: "CT", dateCode: true, remote: []pair{{"lead_id", "leads"}},
	res: &app.Resource{Resource: "crm_contacts", Table: "contacts", Type: "contact", Singular: "contact", Plural: "contacts", Base: "/crm/contacts", Icon: "user",
		OrderBy:    "t.name_en, t.id",
		Searchable: []string{"t.name_en", "t.name_th", "t.code", "t.email", "t.phone", "l.name_en", "(SELECT string_agg(m.number, ' ') FROM contact_mobiles m WHERE m.contact_id = t.id)"},
		SelectSQL: func(c *app.Ctx) string {
			return `SELECT t.*, l.name_en AS lead_name, l.name_th AS lead_name_th, l.image AS lead_image, s.name AS source_name FROM contacts t
                LEFT JOIN leads l ON l.id = t.lead_id
                LEFT JOIN lead_sources s ON s.id = t.lead_source_id`
		},
		Label:     func(c *app.Ctx, row db.Row) string { return row.Str("name_en") },
		ExtraMeta: func(c *app.Ctx) app.M { return app.M{"wizard_style": "dots"} },
	},
}).setup()

type mobileRow struct {
	I, Number, Ext, Label string
	Main                  bool
}

var mobileRe = regexp.MustCompile(`^[0-9+()\-.\s]{3,40}$`)

func init() {
	s := contacts
	r := s.res
	sources := func() []app.Opt {
		return optsFrom(db.On("crm").Select("SELECT id, name FROM lead_sources ORDER BY name"), "id", "name")
	}
	r.Filters = func(c *app.Ctx) []app.Filter {
		return append([]app.Filter{{Name: "source", Label: c.T("All sources"), Options: sources(), Column: "t.lead_source_id"}}, commonFilters(c)...)
	}
	r.Fields = func(c *app.Ctx, row db.Row) []app.Field {
		m := must(c)
		noSearch := false
		var sal []app.Opt
		for _, x := range salutations {
			sal = append(sal, app.Opt{Value: x, Label: x})
		}
		reachMsg := c.T("Fill in at least one way to reach them: email, phone, mobile, fax or other.")
		f := []app.Field{
			{Section: c.T("Basic info"), Icon: "user", Help: c.T("Contact name, job and the lead they belong to")},
			{Name: "avatar", Label: c.T("Photo"), Type: "logo", NoImport: true, Span: 2, Rules: "nullable", Help: c.T("PNG, JPG or WEBP · max 2 MB"),
				Extra: app.M{"hide_show": true, "shape": "square", "recommend": c.T("Recommended: 256 × 256 px")}},
			{Name: "salutation", Label: c.T("Salutation"), Type: "select", Options: sal, Other: true, OtherMax: 20, OtherPlaceholder: c.T("e.g. Prof."), Rules: "nullable|max:20", Search: &noSearch, Span: 2},
			{Name: "name_en", Label: c.T("Name (English)"), Rules: "required|max:160", Placeholder: c.T("e.g. John Smith"), Example: "Somsak Rattanakul"},
			{Name: "name_th", Label: c.T("Name (Thai)"), Rules: m + "|max:160", Placeholder: c.T("e.g. จอห์น สมิธ")},
			{Name: "job_title", Label: c.T("Job title"), Rules: "nullable|max:120", Placeholder: c.T("e.g. Sales Manager")},
			{Name: "department", Label: c.T("Department"), Rules: "nullable|max:120", Placeholder: c.T("e.g. Sales"), Help: c.T("The department this person works in at their own company.")},
			s.remoteField(c, "lead_id", row, app.Field{Label: c.T("Lead"), Rules: "required", Span: 2, Placeholder: c.T("Select lead"),
				Display: func(r db.Row) any { return r["lead_name"] },
				Extra: app.M{"href": func(r db.Row) string {
					if r.Int("lead_id") != 0 {
						return "/crm/leads/" + r.Str("lead_id")
					}
					return ""
				}}}),
			{Name: "code", Label: c.T("Code"), Rules: "nullable|max:30", HideForm: true},

			// at least one way to reach them (checked in the form and again in prepareMore)
			{Section: c.T("Contact details"), Icon: "phone", Help: c.T("How to reach them: fill in at least one")},
			{Name: "email", Label: c.T("Email"), Type: "email", Rules: "nullable|email|max:190", Placeholder: c.T("e.g. john@example.com"), OneOf: "reach", Span: 2, OneOfMsg: reachMsg},
			{Name: "phone", Label: c.T("Phone"), Type: "tel", Rules: "nullable|max:40", Placeholder: c.T("e.g. 02 123 4567"), OneOf: "reach"},
			{Name: "phone_ext", Label: c.T("Phone ext."), Rules: "nullable|max:10", Placeholder: c.T("e.g. 205")},
			{Name: "mobiles", Label: c.T("Mobile"), Type: "custom", Partial: "crm/fields/mobiles", Span: 2, NoTable: true, Rules: "nullable", OneOf: "reach",
				Example: "0812345678; 0898765432", Help: c.T("Add as many as you need; mark the main one."), Extra: mobilesExtra(c, row),
				Display: func(r db.Row) any { return r["mobiles"] }},
			{Name: "fax", Label: c.T("Fax"), Rules: "nullable|max:40", Placeholder: "+66 2 xxx xxxx", OneOf: "reach"},
			{Name: "lead_source_id", Label: c.T("Lead source"), Type: "select", Options: sources(), Rules: "nullable", Display: func(r db.Row) any { return r["source_name"] }},
			{Name: "additional_contact", Label: c.T("Other ways to contact (LINE, WeChat …)"), Type: "textarea", Rows: 2, Span: 2, Rules: "nullable|max:1000", Placeholder: c.T("e.g. LINE: @somsak"), OneOf: "reach"},

			{Section: c.T("Address"), Icon: "pin", Help: c.T("Location, city, province, country and postal code")},
			{Name: "address", Label: c.T("Street address"), Type: "textarea", Rows: 2, Span: 2, Rules: "nullable|max:1000", Placeholder: c.T("e.g. 123 Sukhumvit Road")},
			{Name: "city", Label: c.T("City"), Rules: "nullable|max:80", Placeholder: c.T("e.g. Bangkok")},
			{Name: "province", Label: c.T("Province / State"), Rules: "nullable|max:80", Placeholder: c.T("e.g. Bangkok")},
			{Name: "country", Label: c.T("Country"), Rules: m + "|max:80", Default: "Thailand", Placeholder: c.T("e.g. Thailand")},
			{Name: "zipcode", Label: c.T("Postal code"), Rules: "nullable|max:20", Placeholder: c.T("e.g. 10110")},

			{Section: c.T("Description"), Icon: "list", Help: c.T("Rich text notes about this contact")},
			{Name: "description", Label: c.T("Description"), Type: "richtext", Span: 2, Rules: "nullable", Counter: true},
		}
		return append(f, s.ownershipFields(c, row)...)
	}
	r.Columns = func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "name_en", Label: c.T("Name"), Primary: true, Sort: "t.name_en", Render: func(c *app.Ctx, r db.Row) template.HTML {
				n := r.Str("name_en")
				if r.Str("salutation") != "" {
					n = r.Str("salutation") + " " + n
				}
				return h(uiPerson(c, strings.TrimSpace(n), conv.OrStr(r.Str("name_th"), r.Str("job_title")), r.Str("avatar"), "/crm/contacts/"+r.Str("id"), "rounded-full"))
			}},
			{Key: "lead", Label: c.T("Lead"), Sort: "l.name_en", Tone: "amber", Render: func(c *app.Ctx, r db.Row) template.HTML {
				if r.Int("lead_id") == 0 {
					return h(uiDash())
				}
				return h(uiPerson(c, r.Str("lead_name"), r.Str("lead_name_th"), r.Str("lead_image"), "/crm/leads/"+r.Str("lead_id"), ""))
			}},
			{Key: "job_title", Label: c.T("Position"), Sort: "t.job_title", Render: func(c *app.Ctx, r db.Row) template.HTML { return h(app.E(conv.OrStr(r.Str("job_title"), "—"))) }},
			{Key: "email", Label: c.T("E-mail"), Sort: "t.email", Render: func(c *app.Ctx, r db.Row) template.HTML {
				if r.Str("email") == "" {
					return h(uiDash())
				}
				return h(`<a href="mailto:` + app.E(r["email"]) + `" class="hover:text-signal-700">` + app.E(r["email"]) + `</a>`)
			}},
			{Key: "mobile", Label: c.T("Contact"), Tone: "sky", Render: func(c *app.Ctx, r db.Row) template.HTML {
				list, _ := r["mobile_list"].([]db.Row)
				main := r.Str("phone")
				if len(list) > 0 {
					main = list[0].Str("number")
				}
				if main == "" {
					return h(uiDash())
				}
				out := `<span class="tabular-nums">` + app.E(main) + `</span>`
				if len(list)-1 > 0 {
					out += ` <span class="badge bg-white/80 text-graphite-800">+` + conv.Str(len(list)-1) + `</span>`
				}
				if r.Str("phone") != "" && len(list) > 0 {
					out += `<span class="block text-xs tabular-nums text-steel">` + app.E(r["phone"]) + `</span>`
				}
				return h(out)
			}},
			createdColumn(c),
		}
	}
	s.hydrateMore = func(c *app.Ctx, rows []db.Row) []db.Row {
		ids := col(rows, "id")
		all := db.On("crm").Select("SELECT * FROM contact_mobiles WHERE contact_id IN ("+inPlaceholders(len(ids))+") ORDER BY is_primary DESC, sort, id", ids...)
		for _, r := range rows {
			var list []db.Row
			var parts []string
			for _, m := range all {
				if m.Int("contact_id") == r.Int("id") {
					list = append(list, m)
					p := m.Str("number")
					if m.Str("ext") != "" {
						p += " #" + m.Str("ext")
					}
					if m.Str("label") != "" {
						p += " (" + m.Str("label") + ")"
					}
					parts = append(parts, p)
				}
			}
			r["mobile_list"] = list
			r["mobiles"] = strings.Join(parts, "; ")
		}
		return rows
	}
	s.importMore = func(c *app.Ctx, raw app.CsvRow) app.CsvRow {
		return raw // the numbers ("a; b") are split in prepareMore while importing
	}
	s.prepareMore = func(c *app.Ctx, data *app.Clean, existing db.Row) *app.Clean {
		// Mobile numbers: [{number, ext, label}], main one picked with the radio button
		type mob struct {
			idx, number string
			ext, label  any
			primary     bool
		}
		var list []mob
		raw := data.Get("mobiles")
		if s, ok := raw.(string); ok && importing(c) { // CSV: "0812345678; 0898765432"
			cm := app.InMap(nil)
			i := 0
			for _, n := range strings.Split(s, ";") {
				if strings.TrimSpace(n) != "" {
					x := app.InMap(nil)
					x.Set("number", strings.TrimSpace(n))
					cm.Set(conv.Str(i), x)
					i++
				}
			}
			raw = cm
		}
		m := app.InMap(raw)
		if l, ok := raw.([]any); ok {
			for i, v := range l {
				m.Set(conv.Str(i), v)
			}
		}
		for _, k := range m.Keys {
			e := app.InMap(m.Vals[k])
			nv, _ := e.Get("number")
			num := app.InStr(nv)
			if num == "" {
				continue
			}
			if !mobileRe.MatchString(num) {
				app.Invalid("mobiles", c.T(`":number" is not a valid mobile number.`, "number", num))
			}
			ev, _ := e.Get("ext")
			lv, _ := e.Get("label")
			var ext, lab any
			if x := conv.Substr(app.InStr(ev), 0, 10); x != "" {
				ext = x
			}
			if x := conv.Substr(app.InStr(lv), 0, 30); x != "" {
				lab = x
			}
			list = append(list, mob{idx: k, number: num, ext: ext, label: lab})
		}
		if len(list) > 10 {
			app.Invalid("mobiles", c.T("A contact can have up to 10 mobile numbers."))
		}
		primary := c.Input("mobile_primary")
		found := false
		for _, x := range list {
			if x.idx == primary {
				found = true
			}
		}
		for i := range list {
			list[i].primary = (found && list[i].idx == primary) || (!found && i == 0)
		}
		var out []db.Row
		for _, x := range list {
			out = append(out, db.Row{"number": x.number, "ext": x.ext, "label": x.label, "is_primary": x.primary})
		}
		data.Set("mobiles", out)

		// at least one way to reach them (the form asks for it; an import may leave it out)
		if !importing(c) && len(out) == 0 {
			any := false
			for _, k := range []string{"email", "phone", "fax", "additional_contact"} {
				if strings.TrimSpace(data.Str(k)) != "" {
					any = true
				}
			}
			if !any {
				app.Invalid("email", c.T("Fill in at least one way to reach them: email, phone, mobile, fax or other."))
			}
		}
		if fh := c.File("avatar"); fh != nil {
			data.Set("avatar", app.UploadImage(c, fh, "crm", 2048, false))
			if existing != nil && existing.Str("avatar") != "" {
				app.DeleteUpload(existing.Str("avatar"))
			}
		} else {
			data.Delete("avatar")
		}
		return data
	}
	s.afterSave = func(c *app.Ctx, id int, data *app.Clean, existing db.Row) {
		db.On("crm").Exec("DELETE FROM contact_mobiles WHERE contact_id = ?", id)
		list, _ := data.Get("mobiles").([]db.Row)
		for i, m := range list {
			db.On("crm").Insert("contact_mobiles", db.Cols{{Name: "contact_id", Value: id}, {Name: "number", Value: m["number"]}, {Name: "ext", Value: m["ext"]}, {Name: "label", Value: m["label"]},
				{Name: "is_primary", Value: m["is_primary"]}, {Name: "sort", Value: i}}, "")
		}
	}
	s.media = func(c *app.Ctx, r db.Row) string {
		if r.Str("avatar") != "" {
			return `<img src="` + app.E(c.UploadURL(r["avatar"])) + `" alt="" class="size-14 shrink-0 rounded-full object-cover">`
		}
		return `<span class="size-14 inline-flex shrink-0 items-center justify-center rounded-full bg-graphite-700 text-xs font-semibold text-white">` + app.E(conv.Initials(r.Str("name_en"))) + `</span>`
	}
	s.subtitle = func(c *app.Ctx, r db.Row) string {
		return joinNonEmpty(" · ", r.Str("code"), r.Str("job_title"), r.Str("lead_name"), r.Str("name_th"))
	}
	s.badges = func(c *app.Ctx, r db.Row) []string {
		if r.Str("source_name") != "" {
			return []string{uiChip(c.T("Source")+": "+r.Str("source_name"), "")}
		}
		return nil
	}
	query := func(r db.Row) string {
		q := "contact_id=" + r.Str("id")
		if r.Int("lead_id") != 0 {
			q += "&lead_id=" + r.Str("lead_id")
		}
		return q
	}
	s.links = func(c *app.Ctx, r db.Row) []recLink {
		var l []recLink
		if c.Can("crm_opportunities", "create") {
			l = append(l, recLink{URL: "/crm/opportunities/create?" + query(r), Label: c.T("Add opportunity"), Icon: "target"})
		}
		if c.Can("crm_activities", "create") {
			l = append(l, recLink{URL: "/crm/activities/create?" + query(r), Label: c.T("Log activity"), Icon: "clock"})
		}
		return l
	}
	s.showMain = func(c *app.Ctx, row db.Row) string {
		id := row.Int("id")
		opps := opportunityItems(c, visibleRows(c, "opportunity", "t.id, t.name, t.amount, t.currency, t.opportunity_stage", "t.contact_id = ?", []any{id}, "t.id DESC", 8))
		acts := activityItems(c, visibleRows(c, "activity", "t.id, t.topic, t.activity_type, t.start_at, t.status", "t.contact_id = ?", []any{id}, "t.start_at DESC NULLS LAST, t.id DESC", 8))
		oc, ac := "", ""
		if c.Can("crm_opportunities", "create") {
			oc = "/crm/opportunities/create?" + query(row)
		}
		if c.Can("crm_activities", "create") {
			ac = "/crm/activities/create?" + query(row)
		}
		return relatedPanel(c, c.T("Opportunities"), opps, oc, c.T("No opportunities yet."), c.T("Add opportunity")) +
			relatedPanel(c, c.T("Activities"), acts, ac, c.T("No activities yet."), c.T("Log activity"))
	}
	s.statCards = func(c *app.Ctx) []app.Stat {
		return []app.Stat{
			card(c.T("Contacts"), s.figure(c, "count(*)", "", nil, ""), "", "neutral", "user", ""),
			card(c.T("New in 30 days"), s.figure(c, "count(*)", "t.created_at >= now() - interval '30 days'", nil, ""), "", "info", "plus", ""),
			card(c.T("No mobile number"), s.figure(c, "count(*)", "NOT EXISTS (SELECT 1 FROM contact_mobiles m WHERE m.contact_id = t.id)", nil, ""), "", "warn", "user", ""),
			card(c.T("No e-mail"), s.figure(c, "count(*)", "coalesce(t.email, '') = ''", nil, ""), "", "warn", "chat", ""),
		}
	}
}

// mobilesExtra: the rows of the mobile-number field (old input, else the stored numbers, else one empty row).
func mobilesExtra(c *app.Ctx, row db.Row) app.M {
	var rows []mobileRow
	o, hasOld := oldVal(c, "mobiles")
	primary, hasPrimary := oldVal(c, "mobile_primary")
	if hasOld && o != nil {
		for i, v := range app.InList(o) {
			m := app.InMap(v)
			g := func(k string) string { x, _ := m.Get(k); return app.InStr(x) }
			rows = append(rows, mobileRow{I: conv.Str(i), Number: g("number"), Ext: g("ext"), Label: g("label")})
		}
	} else if row != nil {
		list, _ := row["mobile_list"].([]db.Row)
		for i, m := range list {
			rows = append(rows, mobileRow{I: conv.Str(i), Number: m.Str("number"), Ext: m.Str("ext"), Label: m.Str("label"), Main: m.Bool("is_primary")})
		}
	}
	if len(rows) == 0 {
		rows = []mobileRow{{I: "0"}}
	}
	anyMain := false
	for _, r := range rows {
		anyMain = anyMain || r.Main
	}
	for i := range rows {
		if hasPrimary && primary != nil {
			rows[i].Main = conv.Str(primary) == rows[i].I
		} else if !anyMain && i == 0 {
			rows[i].Main = true
		}
	}
	return app.M{"rows": rows, "next": len(rows), "blank": mobileRow{I: "__i__"}}
}
