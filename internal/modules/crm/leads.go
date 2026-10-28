package crm

import (
	"html/template"
	"strings"

	"acme/internal/app"
	"acme/internal/conv"
	"acme/internal/db"
)

// A lead is the company / account. Contacts, opportunities and activities hang off it (LeadController).

const pickMax = 5 // industries and lead sources a lead can have at most (the picker stops there)

var leads = (&screen{
	entity: "lead", prefix: "LD", dateCode: true, remote: []pair{{"company_id", "leads"}},
	res: &app.Resource{Resource: "crm_leads", Table: "leads", Type: "lead", Singular: "lead", Plural: "leads", Base: "/crm/leads", Icon: "building",
		OrderBy: "t.updated_at DESC, t.id DESC", Searchable: []string{"t.name_en", "t.name_th", "t.code", "t.tax_id", "t.phone", "t.mobile", "t.city"},
		Label:     func(c *app.Ctx, row db.Row) string { return row.Str("name_en") },
		ExtraMeta: func(c *app.Ctx) app.M { return app.M{"wizard_style": "dots"} },
	},
}).setup()

func init() {
	s := leads
	r := s.res
	r.Filters = func(c *app.Ctx) []app.Filter {
		return append([]app.Filter{
			{Name: "industry", Label: c.T("All industries"), Options: optsFrom(db.On("crm").Select("SELECT id, name FROM industries ORDER BY name"), "id", "name"),
				SQL: "EXISTS (SELECT 1 FROM lead_industries x WHERE x.lead_id = t.id AND x.industry_id = ?)"},
			{Name: "source", Label: c.T("All sources"), Options: optsFrom(db.On("crm").Select("SELECT id, name FROM lead_sources ORDER BY name"), "id", "name"),
				SQL: "EXISTS (SELECT 1 FROM lead_lead_sources x WHERE x.lead_id = t.id AND x.lead_source_id = ?)"},
		}, commonFilters(c)...)
	}
	r.Fields = func(c *app.Ctx, row db.Row) []app.Field {
		pick := func(table string) []app.PickChoice {
			var out []app.PickChoice
			for _, x := range db.On("crm").Select("SELECT id, name, color FROM " + table + " ORDER BY name") {
				out = append(out, app.PickChoice{ID: x.Str("id"), Label: x.Str("name"), Color: x.Str("color")})
			}
			return out
		}
		m := must(c)
		f := []app.Field{
			{Section: c.T("Basic info"), Icon: "building", Help: c.T("Company name, contact details and registration status")},
			{Name: "image", Label: c.T("Logo"), Type: "logo", NoImport: true, Span: 2, Rules: "nullable", Help: c.T("PNG, JPG or WEBP · max 2 MB"), Extra: app.M{"hide_show": true, "recommend": c.T("Recommended: 256 × 256 px")}},
			{Name: "name_en", Label: c.T("Name (English)"), Rules: "required|max:200", Placeholder: c.T("e.g. ACME Corporation"), Example: "Siam Packaging Co., Ltd."},
			{Name: "name_th", Label: c.T("Name (Thai)"), Rules: m + "|max:200", Placeholder: c.T("e.g. บริษัท ACME จำกัด")},
			{Name: "tax_id", Label: c.T("Tax ID"), Rules: "nullable|max:30", Placeholder: c.T("13-digit tax number")},
			{Name: "revenue", Label: c.T("Annual revenue"), Type: "number", Rules: "nullable|numeric", Placeholder: c.T("e.g. 10,000,000")},
			{Name: "phone", Label: c.T("Phone"), Type: "tel", Rules: "nullable|max:40", Placeholder: c.T("e.g. 02 123 4567")},
			{Name: "mobile", Label: c.T("Mobile"), Type: "tel", Rules: "nullable|max:40", Placeholder: c.T("e.g. 081 234 5678")},
			{Name: "fax", Label: c.T("Fax"), Rules: "nullable|max:40", Placeholder: "+66 2 xxx xxxx"},
			{Name: "sector", Label: c.T("Sector"), Type: "select", Options: []app.Opt{{Value: "private", Label: c.T("Private")}, {Value: "government", Label: c.T("Government")}}, Rules: m, Default: "private", NoTable: true,
				Display: func(r db.Row) any {
					if r.Bool("is_government") {
						return c.T("Government")
					}
					return c.T("Private")
				}},
			// typed; when it is the name of a lead you can see, the lead page links to it
			{Name: "parent_company", Label: c.T("Parent company"), Rules: "nullable|max:200", Placeholder: c.T("Parent company reference"),
				Extra: app.M{"href": func(r db.Row) string {
					if r.Int("company_id") != 0 {
						return "/crm/leads/" + r.Str("company_id")
					}
					return ""
				}}},
			s.remoteField(c, "company_id", row, app.Field{Label: c.T("Parent company (link)"), Rules: "nullable", HideForm: true, Extra: app.M{"hide_show": true}}),
			{Name: "registration", Label: c.T("Registration status"), Type: "select", Options: []app.Opt{{Value: "not_registered", Label: c.T("Not registered")}, {Value: "registered", Label: c.T("Registered")}}, Rules: m, Default: "not_registered", NoTable: true,
				Help: c.T("Registered with the Department of Business Development."),
				Display: func(r db.Row) any {
					if r.Bool("is_register") {
						return c.T("Registered")
					}
					return c.T("Not registered")
				}},
			{Name: "code", Label: c.T("Code"), Rules: "nullable|max:30", HideForm: true}, // made when the lead is created (import may give one)

			{Section: c.T("Industry & source"), Icon: "cart", Help: c.T("Industry type and lead source")},
			{Name: "industries", Label: c.T("Industry"), Type: "picker", Span: 2, NoTable: true, NoImport: true, Rules: m,
				Extra:   app.M{"choices": pick("industries"), "max": pickMax, "search": c.T("Search industries name"), "group": c.T("Industries"), "add": c.T("Add industry"), "empty": c.T("Click to add industry"), "none": c.T("Add industries under CRM → Settings.")},
				Display: func(r db.Row) any { return r["industries"] }},
			{Name: "lead_sources", Label: c.T("Lead source"), Type: "picker", Span: 2, NoTable: true, NoImport: true, Rules: m,
				Extra:   app.M{"choices": pick("lead_sources"), "max": pickMax, "search": c.T("Search lead sources"), "group": c.T("Lead sources"), "add": c.T("Add lead source"), "empty": c.T("Click to add lead source"), "none": c.T("Add lead sources under CRM → Settings.")},
				Display: func(r db.Row) any { return r["lead_sources"] }},

			{Section: c.T("Address"), Icon: "pin", Help: c.T("Location, city, province, country and postal code")},
			{Name: "address", Label: c.T("Street address"), Type: "textarea", Rows: 2, Span: 2, Rules: "nullable|max:1000", Placeholder: c.T("e.g. Sukhumvit Road")},
			{Name: "city", Label: c.T("City"), Rules: m + "|max:80", Placeholder: c.T("e.g. Bangkok")},
			{Name: "province", Label: c.T("Province / State"), Rules: "nullable|max:80", Placeholder: c.T("e.g. Bangkok")},
			{Name: "country", Label: c.T("Country"), Rules: m + "|max:80", Default: "Thailand", Placeholder: c.T("e.g. Thailand")},
			{Name: "zipcode", Label: c.T("Postal code"), Rules: "nullable|max:20", Placeholder: c.T("e.g. 10110")},

			{Section: c.T("Description"), Icon: "list", Help: c.T("Rich text notes about this lead")},
			{Name: "description", Label: c.T("Description"), Type: "richtext", Span: 2, Rules: "nullable", Counter: true},
		}
		return append(f, s.ownershipFields(c, row)...)
	}
	r.Columns = func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "name_en", Label: c.T("Name"), Primary: true, Sort: "t.name_en", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return h(uiPerson(c, r.Str("name_en"), conv.OrStr(r.Str("name_th"), r.Str("code")), r.Str("image"), "/crm/leads/"+r.Str("id"), ""))
			}},
			{Key: "code", Label: c.T("Code"), Sort: "t.code", Render: func(c *app.Ctx, r db.Row) template.HTML { return h(app.E(conv.Coalesce(r["code"], "—"))) }},
			{Key: "industries", Label: c.T("Industries"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				l, _ := r["industry_list"].([]app.M)
				return h(uiChips(l))
			}},
			{Key: "phone", Label: c.T("Phone"), Sort: "t.phone", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return h(app.E(conv.OrStr(r.Str("phone"), r.Str("mobile"), "—")))
			}},
			{Key: "place", Label: c.T("Location"), Sort: "t.city", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return h(app.E(conv.OrStr(joinNonEmpty(", ", r.Str("city"), r.Str("country")), "—")))
			}},
			createdColumn(c),
		}
	}
	s.hydrateMore = func(c *app.Ctx, rows []db.Row) []db.Row {
		ids := col(rows, "id")
		in := inPlaceholders(len(ids))
		ind := db.On("crm").Select("SELECT x.lead_id, i.id, i.name, i.color FROM lead_industries x JOIN industries i ON i.id = x.industry_id WHERE x.lead_id IN ("+in+") ORDER BY i.name", ids...)
		src := db.On("crm").Select("SELECT x.lead_id, s.id, s.name, s.color FROM lead_lead_sources x JOIN lead_sources s ON s.id = x.lead_source_id WHERE x.lead_id IN ("+in+") ORDER BY s.name", ids...)
		var pids []any
		for _, r := range rows {
			if r.Int("company_id") != 0 {
				pids = append(pids, r.Int("company_id"))
			}
		}
		parents := map[int]string{}
		if len(pids) > 0 {
			for _, p := range db.On("crm").Select("SELECT id, name_en FROM leads WHERE id IN ("+inPlaceholders(len(pids))+")", pids...) {
				parents[p.Int("id")] = p.Str("name_en")
			}
		}
		of := func(list []db.Row, id int) ([]app.M, []string, []string) {
			var ms []app.M
			var idl, nm []string
			for _, x := range list {
				if x.Int("lead_id") == id {
					ms = append(ms, app.M(x))
					idl = append(idl, x.Str("id"))
					nm = append(nm, x.Str("name"))
				}
			}
			return ms, idl, nm
		}
		for _, r := range rows {
			il, iids, inames := of(ind, r.Int("id"))
			sl, sids, snames := of(src, r.Int("id"))
			r["industry_list"], r["source_list"] = il, sl
			r["industry_ids"], r["lead_source_ids"] = iids, sids
			r["industries_ids"], r["lead_sources_ids"] = iids, sids // what the form's pickers read
			r["sector"] = "private"
			if r.Bool("is_government") {
				r["sector"] = "government"
			}
			r["registration"] = "not_registered"
			if r.Bool("is_register") {
				r["registration"] = "registered"
			}
			r["industries"] = strings.Join(inames, ", ")
			r["lead_sources"] = strings.Join(snames, ", ")
			if n, ok := parents[r.Int("company_id")]; ok {
				r["company_name"] = n
			} else {
				r["company_name"] = nil
			}
		}
		return rows
	}
	s.prepareMore = func(c *app.Ctx, data *app.Clean, existing db.Row) *app.Clean {
		if data.Get("company_id") != nil && existing != nil && data.Int("company_id") == existing.Int("id") {
			data.Set("company_id", nil)
		}
		for _, k := range []string{"industries", "lead_sources"} {
			valid := map[int]bool{}
			for _, v := range db.On("crm").Column("SELECT id FROM " + k) {
				valid[conv.Int(v)] = true
			}
			var list []int
			for _, v := range app.InList(data.Get(k)) {
				if n := conv.Int(v); valid[n] {
					list = append(list, n)
				}
			}
			data.Set(k, list)
		}
		for _, p := range []pair{{"industries", c.T("Industry")}, {"lead_sources", c.T("Lead source")}} {
			if l, _ := data.Get(p.K).([]int); len(l) > pickMax {
				app.Invalid(p.K, c.T("Pick up to :n for :field.", "n", pickMax, "field", p.L))
			}
		}
		// Parent company typed in the form: linked to the lead of that name when there is exactly one the person can see
		if data.Has("parent_company") {
			name := strings.TrimSpace(data.Str("parent_company"))
			if name != "" {
				data.Set("parent_company", name)
			} else {
				data.Set("parent_company", nil)
			}
			data.Set("company_id", nil)
			if name != "" {
				vs, vp := visible(c.MustUser(), "l", "lead")
				sql := "SELECT l.id FROM leads l WHERE lower(l.name_en) = lower(?) AND l.deleted_at IS NULL AND " + vs
				params := append([]any{name}, vp...)
				if existing != nil {
					sql += " AND l.id <> ?"
					params = append(params, existing.Int("id"))
				}
				ids := db.On("crm").Column(sql+" LIMIT 2", params...)
				if len(ids) == 1 {
					data.Set("company_id", conv.Int(ids[0]))
				}
			}
		} else if data.Int("company_id") != 0 && conv.Empty(data.Get("parent_company")) {
			data.Set("parent_company", conv.Or(db.On("crm").Scalar("SELECT name_en FROM leads WHERE id = ?", data.Int("company_id")), nil)) // import by parent id / name
		}
		// Sector and registration status are two choices in the form, two yes / no columns in the table
		if data.Get("sector") != nil {
			data.Set("is_government", data.Str("sector") == "government")
		}
		if data.Get("registration") != nil {
			data.Set("is_register", data.Str("registration") == "registered")
		}
		data.Delete("sector")
		data.Delete("registration")
		if fh := c.File("image"); fh != nil {
			data.Set("image", app.UploadImage(c, fh, "crm", 2048, false))
			if existing != nil && existing.Str("image") != "" {
				app.DeleteUpload(existing.Str("image"))
			}
		} else {
			data.Delete("image")
		}
		return data
	}
	s.afterSave = func(c *app.Ctx, id int, data *app.Clean, existing db.Row) {
		for _, t := range [][3]string{{"lead_industries", "industry_id", "industries"}, {"lead_lead_sources", "lead_source_id", "lead_sources"}} {
			db.On("crm").Exec("DELETE FROM "+t[0]+" WHERE lead_id = ?", id)
			l, _ := data.Get(t[2]).([]int)
			for _, v := range l {
				db.On("crm").Exec("INSERT INTO "+t[0]+" (lead_id, "+t[1]+") VALUES (?, ?) ON CONFLICT DO NOTHING", id, v)
			}
		}
	}
	s.media = func(c *app.Ctx, r db.Row) string { return leadLogo(c, r, "size-14") }
	s.subtitle = func(c *app.Ctx, r db.Row) string {
		return joinNonEmpty(" · ", r.Str("code"), r.Str("name_th"), joinNonEmpty(", ", r.Str("city"), r.Str("country")))
	}
	s.badges = func(c *app.Ctx, r db.Row) []string {
		var b []string
		il, _ := r["industry_list"].([]app.M)
		for _, i := range il {
			b = append(b, uiChip(conv.Str(i["name"]), conv.Str(i["color"])))
		}
		sl, _ := r["source_list"].([]app.M)
		for _, x := range sl {
			b = append(b, uiChip(c.T("Source")+": "+conv.Str(x["name"]), conv.Str(x["color"])))
		}
		if r.Bool("is_government") {
			b = append(b, uiBadge(c.T("Government"), "violet"))
		}
		if r.Bool("is_register") {
			b = append(b, uiBadge(c.T("Registered"), "success"))
		}
		return b
	}
	s.links = func(c *app.Ctx, r db.Row) []recLink {
		var l []recLink
		if c.Can("crm_contacts", "create") {
			l = append(l, recLink{URL: "/crm/contacts/create?lead_id=" + r.Str("id"), Label: c.T("Add contact"), Icon: "user"})
		}
		if c.Can("crm_opportunities", "create") {
			l = append(l, recLink{URL: "/crm/opportunities/create?lead_id=" + r.Str("id"), Label: c.T("Add opportunity"), Icon: "target"})
		}
		return l
	}
	s.showTabs = leadTabs
	s.statCards = func(c *app.Ctx) []app.Stat {
		return []app.Stat{
			card(c.T("Leads"), s.figure(c, "count(*)", "", nil, ""), "", "neutral", "building", ""),
			card(c.T("New in 30 days"), s.figure(c, "count(*)", "t.created_at >= now() - interval '30 days'", nil, ""), "", "info", "plus", ""),
			card(c.T("No contact yet"), s.figure(c, "count(*)", "NOT EXISTS (SELECT 1 FROM contacts c WHERE c.lead_id = t.id AND c.deleted_at IS NULL)", nil, ""), c.T("Add a person to talk to"), "warn", "user", ""),
			card(c.T("With open opportunity"), s.figure(c, "count(*)", "EXISTS (SELECT 1 FROM opportunities o WHERE o.lead_id = t.id AND o.deleted_at IS NULL AND o.opportunity_stage IN ('QUALIFICATION','SURVEY_PROPOSAL','EVALUATION_TESTING','NEGOTIATION','ON_HOLD'))", nil, ""), "", "success", "target", ""),
		}
	}
}

func joinNonEmpty(sep string, parts ...string) string {
	var out []string
	for _, p := range parts {
		if p != "" && p != "0" {
			out = append(out, p)
		}
	}
	return strings.Join(out, sep)
}

func leadLogo(c *app.Ctx, r db.Row, size string) string {
	if r.Str("image") != "" {
		return `<img src="` + app.E(c.UploadURL(r["image"])) + `" alt="" class="` + size + ` shrink-0 rounded-md bg-white object-contain ring-1 ring-graphite-900/10">`
	}
	return `<span class="` + size + ` inline-flex shrink-0 items-center justify-center rounded-md bg-graphite-900/6 text-xs font-semibold text-graphite-700">` + app.E(conv.Initials(r.Str("name_en"))) + `</span>`
}

// toMaps reads a hook's list of rows ([]M, []any of M, or nil).
func toMaps(v any) []app.M {
	switch x := v.(type) {
	case []app.M:
		return x
	case []any:
		var out []app.M
		for _, e := range x {
			if m, ok := e.(app.M); ok {
				out = append(out, m)
			} else if m, ok := e.(map[string]any); ok {
				out = append(out, m)
			}
		}
		return out
	case app.M:
		for _, k := range []string{"quotes", "rows", "items", "data"} {
			if l, ok := x[k]; ok {
				return toMaps(l)
			}
		}
	}
	return nil
}

func accountingHook(c *app.Ctx, name, arg string) any {
	return app.Hook(name, "accounting", func(fn any) any {
		if f, ok := fn.(app.HookString); ok {
			return f(c, arg)
		}
		return nil
	})
}

// leadTabs: Details, Opportunities, Contacts and Activities as tabs; with a tax ID also the ERP invoices and quotes.
func leadTabs(c *app.Ctx, row db.Row) []recTab {
	id := row.Int("id")
	var contacts []relItem
	for _, x := range visibleRows(c, "contact", "t.id, t.name_en, t.job_title, t.email", "t.lead_id = ?", []any{id}, "t.name_en", 8) {
		contacts = append(contacts, relItem{Href: "/crm/contacts/" + x.Str("id"), Title: x.Str("name_en"), Meta: joinNonEmpty(" · ", x.Str("job_title"), x.Str("email"))})
	}
	opps := opportunityItems(c, visibleRows(c, "opportunity", "t.id, t.name, t.amount, t.currency, t.opportunity_stage", "t.lead_id = ?", []any{id}, "t.id DESC", 8))
	acts := activityItems(c, visibleRows(c, "activity", "t.id, t.topic, t.activity_type, t.start_at, t.status", "t.lead_id = ?", []any{id}, "t.start_at DESC NULLS LAST, t.id DESC", 8))
	create := func(perm, url string) string {
		if c.Can(perm, "create") {
			return url
		}
		return ""
	}
	sid := conv.Str(id)
	tabs := []recTab{
		{Key: "opportunities", Label: c.T("Opportunities"), HasCount: true, Count: len(opps),
			HTML: h(relatedPanel(c, c.T("Opportunities"), opps, create("crm_opportunities", "/crm/opportunities/create?lead_id="+sid), c.T("No opportunities yet."), c.T("Add opportunity")))},
		{Key: "contacts", Label: c.T("Contacts"), HasCount: true, Count: len(contacts),
			HTML: h(relatedPanel(c, c.T("Contacts"), contacts, create("crm_contacts", "/crm/contacts/create?lead_id="+sid), c.T("No contacts yet."), c.T("Add contact")))},
		{Key: "activities", Label: c.T("Activities"), HasCount: true, Count: len(acts),
			HTML: h(relatedPanel(c, c.T("Activities"), acts, create("crm_activities", "/crm/activities/create?lead_id="+sid), c.T("No activities yet."), c.T("Log activity")))},
	}

	// With a tax ID: the ERP invoices with that tax ID, and the quotes of the connected ERP customer (data from Accounting)
	if nonDigit.ReplaceAllString(row.Str("tax_id"), "") != "" {
		erp := app.ModuleUsable("accounting")
		var inv app.M
		if erp {
			if v, ok := accountingHook(c, "customer.invoices", row.Str("tax_id")).(app.M); ok {
				inv = v
			}
		}
		t := recTab{Key: "invoices", Label: c.T("Invoices")}
		data := app.M{"row": row, "data": nil}
		if inv != nil {
			t.HasCount, t.Count = true, conv.Int(inv["total"])
			rows := toMaps(inv["rows"])
			for _, r := range rows {
				credit := conv.Bool(r["is_credit"])
				r["_credit"] = credit
				r["_ref"] = strings.Trim(conv.Str(r["order_number"])+func() string {
					if conv.Str(r["customer_name"]) != "" {
						return " · " + conv.Str(r["customer_name"])
					}
					return ""
				}(), " ·")
				sign := ""
				if credit {
					sign = "−"
				}
				r["_amount"] = sign + conv.NF(conv.Float(r["amount"]), 2) + " " + conv.Str(r["currency_code"])
			}
			data["data"] = app.M{"rows": rows, "total": conv.Int(inv["total"]), "sum": conv.NF(conv.Float(inv["sum"]), 2), "more": conv.Int(inv["total"]) > len(rows)}
		}
		t.HTML = c.Partial("crm/panels/lead-invoices", data)
		tabs = append(tabs, t)

		cid := ""
		if row.Int("erp_customer_id") != 0 {
			cid = row.Str("erp_customer_id")
		}
		var quotes []app.M
		hasQuotes := false
		if erp && cid != "" {
			if v := accountingHook(c, "customer.quotes", cid); v != nil {
				quotes, hasQuotes = toMaps(v), true
			}
		}
		var choices []app.M
		if erp && cid == "" && c.Query("pick") == "1" {
			choices = toMaps(accountingHook(c, "customer.by_tax", row.Str("tax_id")))
		}
		today := conv.Today()
		for _, q := range quotes {
			q["_expired"] = conv.Str(q["valid_through_date"]) != "" && conv.StrToTime(q["valid_through_date"]) < conv.StrToTime(today)
			if conv.Str(q["order_number"]) != "" {
				q["_no"] = conv.Str(q["order_number"])
			} else {
				q["_no"] = "#" + conv.Str(q["id"])
			}
			q["_seller"] = conv.OrStr(conv.Str(q["seller"]), conv.Str(q["our_reference_name"]), "—")
			if q["amount"] != nil {
				q["_amount"] = conv.NF(conv.Float(q["amount"]), 2)
			} else {
				q["_amount"] = "—"
			}
		}
		for _, ch := range choices {
			ch["_sub"] = joinNonEmpty(" · ", conv.Str(ch["code"]), "#"+conv.Str(ch["id"]), c.T(":n invoices", "n", ch["invoices"]), c.T(":n quotes", "n", ch["quotes"]))
		}
		qt := recTab{Key: "quotes", Label: c.T("Quotes")}
		if hasQuotes {
			qt.HasCount, qt.Count = true, len(quotes)
		}
		qt.HTML = c.Partial("crm/panels/lead-quotes", app.M{"row": row, "quotes": quotes, "erp": erp, "choices": choices,
			"canEdit": c.Can(leads.res.Resource, "edit") && leads.res.CanModify(c, row, "edit"), "connected": row.Int("erp_customer_id") != 0})
		tabs = append(tabs, qt)
	}
	return tabs
}

// leadErpConnect: Quotes tab → Connect: find the ERP customer with the lead's tax ID and keep its CustomerId on the lead.
// One match connects at once; several open a list to choose from (customer_id then says which).
func leadErpConnect(c *app.Ctx) {
	id := c.ParamInt("id")
	row := leads.res.FindForChange(c, id, "edit")
	back := "/crm/leads/" + conv.Str(id)
	if !app.ModuleUsable("accounting") {
		c.Back("error", c.T("The ERP data is not available right now (the Accounting module is off or unavailable)."))
	}
	if nonDigit.ReplaceAllString(row.Str("tax_id"), "") == "" {
		c.Back("error", c.T("Add the tax ID to this lead first."))
	}
	found := toMaps(accountingHook(c, "customer.by_tax", row.Str("tax_id")))
	if pick := c.Input("customer_id"); pick != "" {
		var only []app.M
		for _, f := range found {
			if conv.Str(f["id"]) == pick { // only a customer with this tax ID
				only = append(only, f)
			}
		}
		found = only
	}
	if len(found) == 0 {
		c.Back("error", c.T("No ERP customer has the tax ID :tax.", "tax", row.Str("tax_id")))
	}
	if len(found) > 1 {
		c.Redirect(back + "?pick=1#quotes")
	}
	cu := found[0]
	db.On("crm").Update("leads", db.Cols{{Name: "erp_customer_id", Value: conv.Int(cu["id"])}, {Name: "erp_customer_name", Value: cu["name"]}, {Name: "erp_connected_at", Value: conv.NowString()},
		{Name: "erp_connected_by", Value: c.MustUser().ID}, {Name: "updated_at", Value: conv.NowString()}}, db.Cols{{Name: "id", Value: id}})
	code := ""
	if conv.Str(cu["code"]) != "" {
		code = conv.Str(cu["code"]) + " "
	}
	label := leads.label(c, row)
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "lead", ID: id, Label: label, Text: `Connected lead ":label" to ERP customer :customer`,
		Params: []any{"label", label, "customer", strings.TrimSpace(code + conv.Str(cu["name"]))},
		Props:  app.M{"changes": app.M{"erp_customer": app.M{"from": row.Str("erp_customer_name"), "to": conv.Str(cu["name"]) + " (#" + conv.Str(cu["id"]) + ")"}}, "_url": c.URL(back)},
		Owner:  row.Int("owner_id"), NoNotify: true, Module: "crm"})
	c.Flash("success", c.T("Connected to :name. Its quotes are shown below.", "name", cu["name"]))
	c.Redirect(back + "#quotes")
}

func leadErpDisconnect(c *app.Ctx) {
	id := c.ParamInt("id")
	row := leads.res.FindForChange(c, id, "edit")
	db.On("crm").Update("leads", db.Cols{{Name: "erp_customer_id", Value: nil}, {Name: "erp_customer_name", Value: nil}, {Name: "erp_connected_at", Value: nil}, {Name: "erp_connected_by", Value: nil},
		{Name: "updated_at", Value: conv.NowString()}}, db.Cols{{Name: "id", Value: id}})
	label := leads.label(c, row)
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "lead", ID: id, Label: label, Text: `Disconnected lead ":label" from its ERP customer`,
		Params: []any{"label", label}, Props: app.M{"changes": app.M{"erp_customer": app.M{"from": row.Str("erp_customer_name"), "to": ""}}},
		Owner: row.Int("owner_id"), NoNotify: true, Module: "crm"})
	c.Flash("success", c.T("Disconnected from the ERP customer."))
	c.Redirect("/crm/leads/" + conv.Str(id) + "#quotes")
}
