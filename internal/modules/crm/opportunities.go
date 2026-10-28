package crm

import (
	"html/template"
	"math"
	"net/http"
	"strings"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/conv"
	"acme/internal/db"
)

// A sales opportunity: products (registered or typed by hand), a stage tracker,
// next steps with a history of what was done, comments and related activities (OpportunityController).

var oppUnits = []string{"pcs", "set", "unit", "box", "pack", "lot", "kg", "m", "m²", "litre", "hour", "day", "month", "year", "job", "licence"}

var opportunities = (&screen{
	entity: "opportunity", prefix: "OP", dateCode: true, files: true, comments: true, remote: []pair{{"lead_id", "leads"}, {"contact_id", "contacts"}},
	res: &app.Resource{Resource: "crm_opportunities", Table: "opportunities", Type: "opportunity", Singular: "opportunity", Plural: "opportunities", Base: "/crm/opportunities", Icon: "target",
		OrderBy:    "CASE WHEN t.opportunity_stage IN ('QUALIFICATION','SURVEY_PROPOSAL','EVALUATION_TESTING','NEGOTIATION') THEN 0 ELSE 1 END, t.updated_at DESC, t.id DESC",
		Searchable: []string{"t.name", "t.code", "l.name_en", "c.name_en"},
		SelectSQL: func(c *app.Ctx) string {
			return `SELECT t.*, l.name_en AS lead_name, l.name_th AS lead_name_th, l.image AS lead_image, c.name_en AS contact_name FROM opportunities t
                LEFT JOIN leads l ON l.id = t.lead_id
                LEFT JOIN contacts c ON c.id = t.contact_id`
		},
		Label:     func(c *app.Ctx, row db.Row) string { return row.Str("name") },
		ExtraMeta: func(c *app.Ctx) app.M { return app.M{"wizard_style": "dots"} },
	},
}).setup()

// oppDepartments: departments of this app: the seller's department of the deal.
func oppDepartments() []app.Opt {
	return optsFrom(db.Select("SELECT id::text AS id, name FROM departments WHERE deleted_at IS NULL ORDER BY name"), "id", "name")
}

type stepRow struct {
	I, Title, Due, Note, Sub string
	Done                     bool
}

type productLine struct {
	I                string
	Hidden           []pair
	Name, Sub, Total string
	Kind             string
}

type lineSection struct {
	Kind, Title, Icon, Add, Empty, Hint string
	Lines                               []productLine
}

func init() {
	s := opportunities
	r := s.res
	r.Filters = func(c *app.Ctx) []app.Filter {
		return append([]app.Filter{
			{Name: "stage", Label: c.T("All stages"), Options: stageOptions(c), Column: "t.opportunity_stage"},
			{Name: "priority", Label: c.T("All priorities"), Options: tr(c, priorities), Column: "t.priority"},
		}, commonFilters(c)...)
	}
	r.Fields = func(c *app.Ctx, row db.Row) []app.Field {
		m := must(c)
		var stageOpts []app.Opt
		if row != nil && inStrs(row.Str("opportunity_stage"), stageFinal) { // closed for good: only its own stage
			stageOpts = []app.Opt{{Value: row.Str("opportunity_stage"), Label: stageLabel(c, row.Str("opportunity_stage"))}}
		} else {
			cur := ""
			if row != nil {
				cur = row.Str("opportunity_stage")
			}
			stageOpts = stageOptionsFor(c, c.MustUser(), cur)
		}
		cur := currencyOptions()
		f := []app.Field{
			{Section: c.T("Basic info"), Icon: "target", Help: c.T("Name, stage, priority and references")},
			{Name: "name", Label: c.T("Opportunity name"), Rules: "required|max:200", Span: 2, Placeholder: c.T("e.g. Packaging line upgrade Q4"), Example: "Packaging line upgrade"},
			{Name: "opportunity_stage", Label: c.T("Stage"), Type: "select", Rules: "required", Default: "QUALIFICATION", Placeholder: c.T("Select stage"), Options: stageOpts},
			{Name: "priority", Label: c.T("Priority"), Type: "select", Options: tr(c, priorities), Rules: "required", Default: "MEDIUM", Placeholder: c.T("Select priority")},
			{Name: "cancel_reason", Label: c.T("Reason"), Type: "textarea", Rows: 2, Span: 2, Rules: "nullable|max:2000", ShowWhen: "opportunity_stage=CLOSED_LOST,CANCEL,ON_HOLD",
				Help: c.T("Why was it lost, cancelled or put on hold?")},
			{Name: "amount", Label: c.T("Deal amount"), Type: "custom", Partial: "crm/fields/money", Rules: m + "|numeric", Extra: moneyExtra(c, row, cur),
				Display: func(r db.Row) any {
					if r["amount"] == nil {
						return nil
					}
					return uiMoney(r["amount"], r.Str("currency"))
				}},
			{Name: "currency", Label: c.T("Currency"), Type: "select", Options: cur, Rules: "required", Default: "THB", HideForm: true, Extra: app.M{"hide_show": true}},
			{Name: "department_id", Label: c.T("Department"), Type: "select", Options: oppDepartments(), Rules: m, Placeholder: c.T("Seller department"),
				Default: conv.Str(c.MustUser().DeptID()), Help: c.T("The seller's department; its people see the opportunity.")},
			{Name: "close_at", Label: c.T("Expected close date"), Type: "date", Rules: m + "|date"},
			{Name: "follow_at", Label: c.T("Follow-up date"), Type: "date", Rules: m + "|date", Help: c.T("Next check-in date")},
			s.remoteField(c, "lead_id", row, app.Field{Label: c.T("Lead"), Rules: "required", Span: 2, Placeholder: c.T("Reference lead"),
				Display: func(r db.Row) any { return r["lead_name"] }, Extra: app.M{"href": hrefIf("lead_id", "/crm/leads/")}}),
			s.remoteField(c, "contact_id", row, app.Field{Label: c.T("Contact"), Rules: "nullable", Depends: "lead_id", Span: 2, Placeholder: c.T("Reference contact"),
				Display: func(r db.Row) any { return r["contact_name"] }, Extra: app.M{"href": hrefIf("contact_id", "/crm/contacts/")}}),
			{Name: "code", Label: c.T("Code"), Rules: "nullable|max:30", HideForm: true},
			{Name: "probability", Label: c.T("Probability (%)"), Type: "number", Rules: "nullable|numeric", HideForm: true}, // from the stage

			{Section: c.T("Products"), Icon: "box", Help: c.T("Products of Accounting and new products")},
			{Name: "products", Label: c.T("Products"), Type: "custom", Partial: "crm/fields/product-lines", Span: 2, NoTable: true, NoImport: true, Rules: "nullable",
				Extra: productLinesExtra(c, row)},

			{Section: c.T("Next steps"), Icon: "tasks", Help: c.T("Action items and follow-up tasks")},
			{Name: "next_steps", Label: c.T("Next steps"), Type: "custom", Partial: "crm/fields/next-steps", Span: 2, NoTable: true, NoImport: true, Rules: "nullable",
				Extra: nextStepsExtra(c, row)},

			{Section: c.T("Description"), Icon: "list", Help: c.T("Rich text notes about this opportunity")},
			{Name: "description", Label: c.T("Description"), Type: "richtext", Span: 2, Rules: "nullable", Counter: true},
		}
		f = append(f, s.filesFields(c, row)...)
		return append(f, s.ownershipFields(c, row)...)
	}
	r.Columns = func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "name", Label: c.T("Opportunity"), Primary: true, Sort: "t.name", Render: func(c *app.Ctx, r db.Row) template.HTML {
				sub := r.Str("code")
				if r.Str("priority") == "HIGH" {
					sub += " · " + c.T("High priority")
				}
				return h(uiTitle(c, r.Str("name"), strings.Trim(sub, " ·"), "/crm/opportunities/"+r.Str("id")))
			}},
			{Key: "lead", Label: c.T("Lead"), Sort: "l.name_en", Tone: "amber", Render: func(c *app.Ctx, r db.Row) template.HTML {
				if r.Int("lead_id") == 0 {
					return h(uiDash())
				}
				return h(uiPerson(c, r.Str("lead_name"), r.Str("lead_name_th"), r.Str("lead_image"), "/crm/leads/"+r.Str("lead_id"), ""))
			}},
			{Key: "contact", Label: c.T("Contact"), Sort: "c.name_en", Render: func(c *app.Ctx, r db.Row) template.HTML { return h(app.E(conv.OrStr(r.Str("contact_name"), "—"))) }},
			{Key: "stage", Label: c.T("Stage"), Sort: "t.opportunity_stage", Render: func(c *app.Ctx, r db.Row) template.HTML { return h(uiStageBadge(c, r.Str("opportunity_stage"))) }},
			{Key: "amount", Label: c.T("Amount"), Sort: "t.amount", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return h(`<span class="tabular-nums">` + uiMoney(r["amount"], r.Str("currency")) + `</span>`)
			}},
			{Key: "close", Label: c.T("Close"), Sort: "t.close_at", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return h(`<span class="tabular-nums text-steel">` + app.E(app.FormatDate(c, r["close_at"], "d M Y")) + `</span>`)
			}},
			createdColumn(c),
		}
	}
	s.hydrateMore = func(c *app.Ctx, rows []db.Row) []db.Row {
		ids := col(rows, "id")
		all := db.On("crm").Select("SELECT * FROM opportunity_products WHERE opportunity_id IN ("+inPlaceholders(len(ids))+") ORDER BY sort, id", ids...)
		for _, r := range rows {
			var list []db.Row
			var parts []string
			for _, p := range all {
				if p.Int("opportunity_id") == r.Int("id") {
					list = append(list, p)
					parts = append(parts, p.Str("name")+" × "+conv.NumberClean(p["quantity"]))
				}
			}
			r["product_list"] = list
			r["products"] = strings.Join(parts, "; ")
		}
		return rows
	}
	s.prepareMore = oppPrepare
	s.afterSave = func(c *app.Ctx, id int, data *app.Clean, existing db.Row) {
		db.On("crm").Exec("DELETE FROM opportunity_products WHERE opportunity_id = ?", id)
		lines, _ := data.Get("products").([]db.Cols)
		for i, l := range lines {
			db.On("crm").Insert("opportunity_products", append(append(db.Cols{}, l...), db.Col{Name: "opportunity_id", Value: id}, db.Col{Name: "sort", Value: i}), "")
		}
		stage := data.Str("opportunity_stage")
		if stage == "" && existing != nil {
			stage = existing.Str("opportunity_stage")
		}
		if stage == "" {
			stage = "QUALIFICATION"
		}
		steps, _ := data.Get("next_steps").([]stepRow)
		for _, st := range steps {
			db.On("crm").Insert("opportunity_steps", db.Cols{{Name: "opportunity_id", Value: id}, {Name: "kind", Value: "step"}, {Name: "stage", Value: stage},
				{Name: "title", Value: st.Title}, {Name: "note", Value: nilStr(st.Note)}, {Name: "due_at", Value: nilStr(st.Due)}, {Name: "created_by", Value: c.MustUser().ID}}, "")
		}
		if ns := data.Str("opportunity_stage"); ns != "" && (existing == nil || existing.Str("opportunity_stage") != ns) {
			from := ""
			if existing != nil {
				from = existing.Str("opportunity_stage")
			}
			recordStage(c, id, ns, from, data.Str("cancel_reason"))
		}
	}
	s.badges = func(c *app.Ctx, r db.Row) []string {
		b := []string{uiStageBadge(c, r.Str("opportunity_stage"))}
		if r.Str("priority") == "HIGH" {
			b = append(b, uiBadge(c.T("High priority"), "danger"))
		}
		return b
	}
	s.subtitle = func(c *app.Ctx, r db.Row) string {
		amt := ""
		if r["amount"] != nil {
			amt = conv.NF(r.Float("amount"), 2)
			if r.Str("currency") != "" {
				amt += " " + r.Str("currency")
			}
		}
		return joinNonEmpty(" · ", r.Str("code"), r.Str("lead_name"), r.Str("contact_name"), amt)
	}
	s.links = func(c *app.Ctx, r db.Row) []recLink {
		var l []recLink
		if c.Can("crm_activities", "create") {
			l = append(l, recLink{URL: "/crm/activities/create?" + oppQuery(r), Label: c.T("Log activity"), Icon: "clock"})
		}
		if c.Can(s.res.Resource, "create") {
			l = append(l, recLink{URL: "/crm/opportunities/" + r.Str("id") + "/duplicate", Label: c.T("Duplicate"), Icon: "copy", Post: true,
				Confirm: c.T(`Make a copy of ":name" starting at Qualification?`, "name", r.Str("name"))})
		}
		return l
	}
	s.showTop = func(c *app.Ctx, row db.Row) string {
		return stageTracker(c, row, c.Can(s.res.Resource, "edit") && s.res.CanModify(c, row, "edit"), oppSteps(c, row.Int("id")))
	}
	s.showMain = func(c *app.Ctx, row db.Row) string {
		id := row.Int("id")
		edit := c.Can(s.res.Resource, "edit") && s.res.CanModify(c, row, "edit")
		out := stepsPanel(c, row, oppSteps(c, id), edit)
		out += productsPanel(c, row)
		acts := activityItems(c, visibleRows(c, "activity", "t.id, t.topic, t.activity_type, t.start_at, t.status", "t.opportunity_id = ?", []any{id}, "t.start_at DESC NULLS LAST, t.id DESC", 8))
		ac := ""
		if c.Can("crm_activities", "create") {
			ac = "/crm/activities/create?" + oppQuery(row)
		}
		return out + relatedPanel(c, c.T("Activities"), acts, ac, c.T("No activities yet."), c.T("Log activity"))
	}
	s.statCards = func(c *app.Ctx) []app.Stat {
		open := "t.opportunity_stage IN ('QUALIFICATION','SURVEY_PROPOSAL','EVALUATION_TESTING','NEGOTIATION','ON_HOLD')"
		from := "opportunities t LEFT JOIN currencies cu ON cu.code = t.currency"
		val := "sum(COALESCE(t.amount, 0) * COALESCE(cu.rate_to_base, 1))"
		base := conv.Str(db.On("crm").Scalar("SELECT code FROM currencies WHERE is_base LIMIT 1"))
		money := func(where string) string {
			out := uiCompact(s.figure(c, val, where, nil, from))
			if base != "" {
				out += " " + base
			}
			return out
		}
		wonM := "t.opportunity_stage = 'CLOSED_WON' AND date_trunc('month', t.updated_at) = date_trunc('month', now())"
		return []app.Stat{
			card(c.T("Open"), s.figure(c, "count(*)", open, nil, ""), money(open), "info", "target", ""),
			card(c.T("Won this month"), s.figure(c, "count(*)", wonM, nil, ""), money(wonM), "success", "check", ""),
			card(c.T("Lost this month"), s.figure(c, "count(*)", "t.opportunity_stage = 'CLOSED_LOST' AND date_trunc('month', t.updated_at) = date_trunc('month', now())", nil, ""), "", "neutral", "columns", ""),
			card(c.T("Follow-up overdue"), s.figure(c, "count(*)", open+" AND t.follow_at < now()", nil, ""), c.T("Still open"), "danger", "clock", ""),
			card(c.T("Past the closing date"), s.figure(c, "count(*)", open+" AND t.close_at < now()", nil, ""), c.T("Still open"), "warn", "clock", ""),
		}
	}
}

func nilStr(s string) any {
	if s == "" {
		return nil
	}
	return s
}

func hrefIf(column, prefix string) func(db.Row) string {
	return func(r db.Row) string {
		if r.Int(column) != 0 {
			return prefix + r.Str(column)
		}
		return ""
	}
}

func oppQuery(r db.Row) string {
	q := "opportunity_id=" + r.Str("id")
	if r.Int("lead_id") != 0 {
		q += "&lead_id=" + r.Str("lead_id")
	}
	if r.Int("contact_id") != 0 {
		q += "&contact_id=" + r.Str("contact_id")
	}
	return q
}

// moneyExtra: the currency dropdown and the amount of the money field.
func moneyExtra(c *app.Ctx, row db.Row, cur []app.Opt) app.M {
	codes := make([]app.Opt, len(cur))
	for i, o := range cur {
		codes[i] = app.Opt{Value: o.Value, Label: o.Value}
	}
	cc := "THB"
	if row != nil && row.Str("currency") != "" {
		cc = row.Str("currency")
	}
	if o, ok := oldVal(c, "currency"); ok {
		cc = conv.Str(o)
	}
	var amt any
	if row != nil {
		amt = row["amount"]
	}
	if o, ok := oldVal(c, "amount"); ok {
		amt = o
	}
	a := ""
	if amt != nil && conv.Str(amt) != "" {
		a = strings.ReplaceAll(conv.NumberClean(amt), ",", "")
	}
	return app.M{"codes": codes, "cur": cc, "amount": a, "search": len(cur) > 8}
}

func productLinesExtra(c *app.Ctx, row db.Row) app.M {
	type prow struct {
		kind, erp, pid, name, part, qty, unit, price string
	}
	var rows []prow
	if o, ok := oldVal(c, "products"); ok && o != nil {
		for _, v := range app.InList(o) {
			m := app.InMap(v)
			g := func(k string) string { x, _ := m.Get(k); return app.InStr(x) }
			rows = append(rows, prow{g("kind"), g("erp_product_id"), g("product_id"), g("name"), g("part_number"), g("quantity"), g("unit"), g("unit_price")})
		}
	} else if row != nil {
		list, _ := row["product_list"].([]db.Row)
		for _, p := range list {
			kind := "new"
			if p.Int("erp_product_id") != 0 || p.Int("product_id") != 0 {
				kind = "catalog"
			}
			rows = append(rows, prow{kind, p.Str("erp_product_id"), p.Str("product_id"), p.Str("name"), p.Str("part_number"), p.Str("quantity"), p.Str("unit"), p.Str("unit_price")})
		}
	}
	line := func(i string, p prow) productLine {
		qty := 1.0
		if p.qty != "" {
			qty = conv.Float(p.qty)
		}
		var priceS, total string
		if p.price != "" {
			price := conv.Float(p.price)
			priceS = conv.NF(price, 2)
			total = conv.NF(qty*price, 2)
		}
		return productLine{I: i, Kind: p.kind, Name: p.name, Total: total,
			Hidden: []pair{{"kind", p.kind}, {"erp_product_id", p.erp}, {"product_id", p.pid}, {"name", p.name}, {"part_number", p.part}, {"quantity", p.qty}, {"unit", p.unit}, {"unit_price", p.price}},
			Sub:    joinNonEmpty(" · ", p.part, conv.NumberClean(qty)+" "+p.unit, priceS)}
	}
	sections := []lineSection{
		{Kind: "catalog", Title: c.T("Products"), Icon: "box", Add: c.T("Add product"), Empty: c.T("No products added yet"), Hint: c.T("Pick products of Accounting (from the ERP).")},
		{Kind: "new", Title: c.T("New products"), Icon: "cart", Add: c.T("Add new product"), Empty: c.T("No new products added yet"), Hint: c.T("Products that are not in Accounting yet: type them in.")},
	}
	for i, p := range rows {
		k := p.kind
		if k == "" {
			k = "new"
		}
		for j := range sections {
			if sections[j].Kind == k {
				sections[j].Lines = append(sections[j].Lines, line(conv.Str(i), p))
			}
		}
	}
	var units []app.Opt
	for _, u := range oppUnits {
		units = append(units, app.Opt{Value: u, Label: u})
	}
	blank := line("__i__", prow{qty: "1"})
	return app.M{"hide_show": true, "sections": sections, "units": units, "firstUnit": oppUnits[0], "count": len(rows), "blank": blank}
}

func nextStepsExtra(c *app.Ctx, row db.Row) app.M {
	var existing []stepRow
	if row != nil {
		for _, s := range db.On("crm").Select("SELECT title, note, due_at, is_done FROM opportunity_steps WHERE opportunity_id = ? AND kind = 'step' ORDER BY is_done, due_at NULLS LAST, id", row.Int("id")) {
			due := ""
			if s.Str("due_at") != "" {
				due = app.FormatDate(c, s["due_at"], "d M Y")
			}
			existing = append(existing, stepRow{Title: s.Str("title"), Done: s.Bool("is_done"), Sub: joinNonEmpty(" · ", due, s.Str("note"))})
		}
	}
	var fresh []stepRow
	if o, ok := oldVal(c, "next_steps"); ok && o != nil {
		for _, v := range app.InList(o) {
			m := app.InMap(v)
			g := func(k string) string { x, _ := m.Get(k); return app.InStr(x) }
			if g("title") == "" {
				continue
			}
			due := ""
			if g("due_at") != "" {
				due = app.FormatDate(c, g("due_at"), "d M Y")
			}
			fresh = append(fresh, stepRow{I: conv.Str(len(fresh)), Title: g("title"), Due: g("due_at"), Note: g("note"), Sub: joinNonEmpty(" · ", due, g("note"))})
		}
	}
	return app.M{"hide_show": true, "existing": existing, "new": fresh, "blank": stepRow{I: "__i__"}}
}

func oppPrepare(c *app.Ctx, data *app.Clean, existing db.Row) *app.Clean {
	stage := data.Str("opportunity_stage")
	if data.Get("opportunity_stage") == nil {
		stage = ""
		if existing != nil {
			stage = existing.Str("opportunity_stage")
		}
	}
	if stage == "" {
		stage = "QUALIFICATION"
	}
	if existing != nil && inStrs(existing.Str("opportunity_stage"), stageFinal) && stage != existing.Str("opportunity_stage") {
		app.Invalid("opportunity_stage", c.T("This opportunity is closed; its stage can no longer be changed. Duplicate it to start a new one."))
	}
	reason := data.Get("cancel_reason")
	if reason == nil && existing != nil {
		reason = existing["cancel_reason"]
	}
	if inStrs(stage, stageNeedsReason) && strings.TrimSpace(conv.Str(reason)) == "" {
		app.Invalid("cancel_reason", c.T("Write the reason for this stage."))
	}
	if !inStrs(stage, stageNeedsReason) {
		data.Set("cancel_reason", nil)
	}
	if p := data.Get("probability"); p != nil && (conv.Float(p) < 0 || conv.Float(p) > 100) {
		app.Invalid("probability", c.T("Probability must be between 0 and 100."))
	}
	changed := existing == nil || existing.Str("opportunity_stage") != stage
	def, _ := stageDefOf(stage)
	prob := data.Get("probability")
	if prob == nil {
		if def.Prob != nil {
			prob = *def.Prob
		} else if existing != nil {
			prob = existing["probability"]
		}
	} else if changed && inStrs(stage, []string{"CLOSED_WON", "CLOSED_LOST", "CANCEL"}) {
		prob = *def.Prob
	}
	data.Set("probability", prob)
	if (stage == "CLOSED_WON" || stage == "CLOSED_LOST") && conv.Empty(data.Get("close_at")) && (existing == nil || conv.Empty(existing["close_at"])) {
		data.Set("close_at", conv.NowString())
	}

	// products: a product of Accounting (erp_product_id), an older CRM catalogue product (product_id) or a new one typed by hand
	catalog := map[int]string{}
	for _, p := range db.On("crm").Select("SELECT id, name FROM products") {
		catalog[p.Int("id")] = p.Str("name")
	}
	var lines []db.Cols
	total := 0.0
	allPriced := true
	for _, v := range app.InList(data.Get("products")) {
		m := app.InMap(v)
		if mm, ok := v.(map[string]any); ok { // duplicate() hands plain maps
			m = config.NewMap()
			for _, k := range []string{"product_id", "name", "quantity", "unit_price", "note"} {
				m.Set(k, mm[k])
			}
		}
		g := func(k string) any { x, _ := m.Get(k); return x }
		name := strings.TrimSpace(conv.Str(g("name")))
		pid := conv.Int(g("product_id"))
		if _, ok := catalog[pid]; name == "" && pid != 0 && ok {
			name = catalog[pid]
		}
		if name == "" {
			continue
		}
		var erp any
		if conv.Str(g("kind")) == "catalog" && ctypeDigit(conv.Str(g("erp_product_id"))) {
			erp = conv.Int(g("erp_product_id"))
		}
		qty := 1.0
		if q := g("quantity"); app.IsNumeric(conv.Str(q)) && conv.Float(q) > 0 {
			qty = conv.Float(q)
		}
		var price any
		if p := g("unit_price"); p != nil && conv.Str(p) != "" && app.IsNumeric(conv.Str(p)) {
			price = conv.Float(p)
			total += qty * conv.Float(p)
		} else {
			allPriced = false
		}
		var pidv any
		if _, ok := catalog[pid]; pid != 0 && ok {
			pidv = pid
		}
		lines = append(lines, db.Cols{{Name: "product_id", Value: pidv}, {Name: "erp_product_id", Value: erp}, {Name: "name", Value: conv.Substr(name, 0, 200)},
			{Name: "part_number", Value: nilStr(conv.Substr(strings.TrimSpace(conv.Str(g("part_number"))), 0, 80))}, {Name: "unit", Value: nilStr(conv.Substr(strings.TrimSpace(conv.Str(g("unit"))), 0, 30))},
			{Name: "quantity", Value: qty}, {Name: "unit_price", Value: price}, {Name: "note", Value: nilStr(conv.Substr(strings.TrimSpace(conv.Str(g("note"))), 0, 250))}})
	}
	data.Set("products", lines)
	if data.Get("amount") == nil && len(lines) > 0 && allPriced {
		data.Set("amount", total)
	}

	// next steps typed in the form
	var steps []stepRow
	for _, v := range app.InList(data.Get("next_steps")) {
		m := app.InMap(v)
		g := func(k string) string { x, _ := m.Get(k); return conv.Str(x) }
		title := conv.Substr(strings.TrimSpace(g("title")), 0, 200)
		if title == "" {
			continue
		}
		due := ""
		if ymd.MatchString(g("due_at")) {
			due = g("due_at")
		}
		steps = append(steps, stepRow{Title: title, Due: due, Note: strings.TrimSpace(g("note"))})
	}
	data.Set("next_steps", steps)

	// the seller's department (the base form never changes department_id; here it is a choice)
	if dep := c.Input("department_id"); dep != "" && optHas(oppDepartments(), dep) {
		data.Set("department_id", conv.Int(dep))
	}
	return data
}

func recordStage(c *app.Ctx, id int, stage, from, note string) {
	db.On("crm").Insert("opportunity_steps", db.Cols{{Name: "opportunity_id", Value: id}, {Name: "kind", Value: "stage"}, {Name: "stage", Value: stage}, {Name: "from_stage", Value: nilStr(from)},
		{Name: "is_done", Value: true}, {Name: "done_at", Value: conv.NowString()}, {Name: "done_by", Value: c.MustUser().ID}, {Name: "note", Value: nilStr(note)}, {Name: "created_by", Value: c.MustUser().ID}}, "")
}

// ---------------------------------------------------------------- stage moves

func oppMoveStage(c *app.Ctx) {
	id := c.ParamInt("id")
	row := opportunities.res.FindForChange(c, id, "edit")
	stage := c.Input("stage")
	note := c.Input("note")
	if isStage(stage) && stage == row.Str("opportunity_stage") {
		c.Back("error", c.T("It is already in this stage."))
	}
	if inStrs(row.Str("opportunity_stage"), stageFinal) {
		c.Back("error", c.T("This opportunity is closed; its stage can no longer be changed. Duplicate it to start a new one."))
	}
	checkMove(c, stage, note)
	changeStage(c, row, stage, note, true)
	c.Flash("success", c.T("Moved to :stage.", "stage", stageLabel(c, stage)))
	c.Redirect("/crm/opportunities/" + conv.Str(id) + "#progress")
}

// checkMove: is this a stage the person can move to, and is the reason there when one is needed?
func checkMove(c *app.Ctx, stage, note string) {
	if !isStage(stage) {
		app.Invalid("stage", c.T("Pick a stage."))
	}
	if !stageAllowed(c.MustUser(), stage) {
		app.Invalid("stage", c.T("This stage is not available to your department."))
	}
	if inStrs(stage, stageNeedsReason) && note == "" {
		app.Invalid("note", c.T("Write the reason for this stage."))
	}
}

// changeStage moves an opportunity to a stage and writes the history. Used by the stage tracker and the pipeline board.
func changeStage(c *app.Ctx, row db.Row, stage, note string, notify bool) {
	id := row.Int("id")
	var reason any
	if inStrs(stage, stageNeedsReason) {
		reason = note
	}
	set := db.Cols{{Name: "opportunity_stage", Value: stage}, {Name: "updated_by", Value: c.MustUser().ID}, {Name: "updated_at", Value: conv.NowString()}, {Name: "cancel_reason", Value: reason}}
	def, _ := stageDefOf(stage)
	if def.Prob != nil {
		set = append(set, db.Col{Name: "probability", Value: *def.Prob})
	}
	if stage == "CLOSED_WON" || stage == "CLOSED_LOST" {
		set = append(set, db.Col{Name: "close_at", Value: conv.NowString()})
	}
	db.On("crm").Update("opportunities", set, db.Cols{{Name: "id", Value: id}})
	recordStage(c, id, stage, row.Str("opportunity_stage"), note)
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "opportunity", ID: id, Label: row.Str("name"), Text: `Moved opportunity ":label" to :stage`,
		Params: []any{"label", row.Str("name"), "stage", def.Label},
		Props:  app.M{"changes": app.M{"opportunity_stage": app.M{"from": stageLabel(c, row.Str("opportunity_stage")), "to": stageLabel(c, stage)}}, "_url": c.URL("/crm/opportunities/" + conv.Str(id))},
		Owner:  row.Int("owner_id"), NoNotify: !notify, Module: "crm"})
}

// ---------------------------------------------------------------- next steps

func oppAddStep(c *app.Ctx) {
	id := c.ParamInt("id")
	row := opportunities.res.FindForChange(c, id, "edit")
	data := c.ValidateInput(app.Rule{Field: "title", Rules: "required|max:200", Label: c.T("next step")}, app.Rule{Field: "due_at", Rules: "nullable|date", Label: c.T("due date")},
		app.Rule{Field: "note", Rules: "nullable|max:2000", Label: c.T("note")})
	db.On("crm").Insert("opportunity_steps", db.Cols{{Name: "opportunity_id", Value: id}, {Name: "kind", Value: "step"}, {Name: "stage", Value: row["opportunity_stage"]}, {Name: "title", Value: data.Get("title")},
		{Name: "note", Value: data.Get("note")}, {Name: "due_at", Value: data.Get("due_at")}, {Name: "created_by", Value: c.MustUser().ID}}, "")
	if d := data.Get("due_at"); d != nil {
		db.On("crm").Exec("UPDATE opportunities SET follow_at = ?, updated_at = now() WHERE id = ? AND (follow_at IS NULL OR follow_at > ?)", d, id, d)
	}
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "opportunity", ID: id, Label: row.Str("name"), Text: `Added next step ":step" to opportunity ":label"`,
		Params: []any{"step", data.Get("title"), "label", row.Str("name")}, Owner: row.Int("owner_id"), Module: "crm"})
	c.Flash("success", c.T("Next step added."))
	c.Redirect("/crm/opportunities/" + conv.Str(id) + "#progress")
}

func oppStep(c *app.Ctx, id int) db.Row {
	st := db.On("crm").First("SELECT * FROM opportunity_steps WHERE id = ? AND opportunity_id = ? AND kind = 'step'", c.ParamInt("sid"), id)
	if st == nil {
		app.Abort(http.StatusNotFound)
	}
	return st
}

func oppDoneStep(c *app.Ctx) {
	id := c.ParamInt("id")
	row := opportunities.res.FindForChange(c, id, "edit")
	st := oppStep(c, id)
	db.On("crm").Exec(`UPDATE opportunity_steps SET is_done = TRUE, done_at = now(), done_by = ?, note = COALESCE(NULLIF(?, ''), note) WHERE id = ?`, c.MustUser().ID, c.Input("note"), st["id"])
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "opportunity", ID: id, Label: row.Str("name"), Text: `Completed step ":step" on opportunity ":label"`,
		Params: []any{"step", st.Str("title"), "label", row.Str("name")}, Owner: row.Int("owner_id"), Module: "crm"})
	c.Flash("success", c.T("Step marked as done."))
	c.Redirect("/crm/opportunities/" + conv.Str(id) + "#progress")
}

func oppDeleteStep(c *app.Ctx) {
	id := c.ParamInt("id")
	opportunities.res.FindForChange(c, id, "edit")
	st := oppStep(c, id)
	db.On("crm").Exec("DELETE FROM opportunity_steps WHERE id = ?", st["id"])
	c.Flash("success", c.T("Step removed."))
	c.Redirect("/crm/opportunities/" + conv.Str(id) + "#progress")
}

// oppDuplicate: copy an opportunity as a new one at the first stage: same lead, contact, priority, amount, currency, products and
// notes; no code (numbered anew), dates, reason or history. The person copying it owns the copy.
func oppDuplicate(c *app.Ctx) {
	s := opportunities
	r := s.res
	c.Authorize(r.Resource, "create")
	id := c.ParamInt("id")
	src := r.Find(c, id) // only a record this person can see
	fields := r.Fields(c, nil)
	data := app.NewClean()
	var products []any
	list, _ := src["product_list"].([]db.Row)
	for _, p := range list {
		products = append(products, map[string]any{"product_id": p["product_id"], "name": p["name"], "quantity": p["quantity"], "unit_price": p["unit_price"], "note": p["note"]})
	}
	for _, kv := range []struct {
		k string
		v any
	}{{"name", conv.Substr(src.Str("name")+" ("+c.T("copy")+")", 0, 200)}, {"lead_id", src["lead_id"]}, {"contact_id", src["contact_id"]},
		{"opportunity_stage", "QUALIFICATION"}, {"priority", src["priority"]}, {"cancel_reason", nil}, {"code", nil},
		{"amount", src["amount"]}, {"currency", src["currency"]}, {"probability", nil}, {"follow_at", nil}, {"close_at", nil},
		{"description", src["description"]}, {"products", products}} {
		data.Set(kv.k, kv.v)
	}
	data = s.prepare(c, data, nil)
	newID := db.On("crm").InsertID(r.Table, r.TableData(fields, data))
	s.saved(c, newID, data, nil)
	row := r.Find(c, newID)
	app.LogActivity(c, app.ActivityEntry{Action: "created", Type: r.Type, ID: newID, Label: r.LabelOf(c, row), Text: `Duplicated opportunity ":label" from ":from"`,
		Params: []any{"label", r.LabelOf(c, row), "from", r.LabelOf(c, src)},
		Props:  app.M{"attributes": r.DisplayValues(c, fields, row), "copied_from": id, "_url": c.URL(r.Base + "/" + conv.Str(newID))},
		Owner:  row.Int("owner_id"), Module: "crm"})
	c.Flash("success", c.T("Copy made at Qualification. Check the details and save."))
	c.Redirect(r.Base + "/" + conv.Str(newID) + "/edit")
}

// ---------------------------------------------------------------- detail page parts

func oppSteps(c *app.Ctx, id int) []db.Row {
	rows := db.On("crm").Select("SELECT * FROM opportunity_steps WHERE opportunity_id = ? ORDER BY created_at DESC, id DESC", id)
	ns := names("users", append(col(rows, "created_by"), col(rows, "done_by")...))
	for _, r := range rows {
		if n, ok := ns[r.Int("created_by")]; ok {
			r["creator"] = n
		} else {
			r["creator"] = "—"
		}
		if n, ok := ns[r.Int("done_by")]; ok {
			r["doer"] = n
		} else {
			r["doer"] = nil
		}
	}
	return rows
}

type trackerNode struct {
	Key, Label, State, Style string
}

func stageTracker(c *app.Ctx, row db.Row, canEdit bool, steps []db.Row) string {
	stage := row.Str("opportunity_stage")
	cur := -1
	for i, s := range stagePipeline {
		if s == stage {
			cur = i
		}
	}
	var moves []db.Row
	for _, s := range steps {
		if s.Str("kind") == "stage" {
			moves = append(moves, s)
		}
	}
	reached := -1
	for _, m := range moves {
		for i, s := range stagePipeline {
			if s == m.Str("stage") && i > reached {
				reached = i
			}
		}
	}
	won := stage == "CLOSED_WON"
	closed := inStrs(stage, []string{"CLOSED_LOST", "CANCEL", "ON_HOLD"})
	final := inStrs(stage, stageFinal)
	canEdit = canEdit && !final
	since := row["created_at"]
	if len(moves) > 0 {
		since = moves[0]["created_at"]
	}
	days := int(math.Max(0, math.Floor(float64(conv.Now().Unix()-conv.StrToTime(since))/86400)))
	var nodes []trackerNode
	for i, key := range append(append([]string{}, stagePipeline...), "CLOSED_WON") {
		state := "todo"
		switch {
		case won:
			state = "done"
		case closed:
			if i <= reached {
				state = "past"
			}
		case cur >= 0:
			if i < cur {
				state = "done"
			} else if i == cur {
				state = "current"
			}
		}
		col := stageColor(c, key)
		style := map[string]string{"done": "background:" + col, "current": "background:" + col + "; box-shadow:0 0 0 3px " + col + "33", "past": "background:" + col + "66", "todo": ""}[state]
		nodes = append(nodes, trackerNode{Key: key, Label: stageLabel(c, key), State: state, Style: style})
	}
	banner := map[string]string{"CLOSED_WON": "tick", "CLOSED_LOST": "alert", "CANCEL": "x", "ON_HOLD": "clock"}[stage]
	in := c.T("less than a day")
	if days == 1 {
		in = c.T(":n day", "n", days)
	} else if days > 1 {
		in = c.T(":n days", "n", days)
	}
	bannerText := stageLabel(c, stage)
	if row.Str("close_at") != "" && stage != "ON_HOLD" {
		bannerText += " · " + app.FormatDate(c, row["close_at"], "d M Y")
	}
	col := stageColor(c, stage)
	return string(c.Partial("crm/panels/stage-tracker", app.M{"row": row, "nodes": nodes, "banner": banner, "bannerText": bannerText,
		"bannerStyle": template.CSS("background:" + col + "1a; box-shadow: inset 3px 0 0 " + col), "inStage": c.T("In this stage for :n", "n", in),
		"showExpected": row.Str("close_at") != "" && !won && !closed, "canEdit": canEdit, "final": final,
		"options": withoutOpt(stageOptionsFor(c, c.MustUser(), stage), stage)}))
}

func stepsPanel(c *app.Ctx, row db.Row, steps []db.Row, canEdit bool) string {
	var open, past []db.Row
	for _, s := range steps {
		if s.Str("kind") == "step" && !s.Bool("is_done") {
			open = append(open, s)
		}
		if s.Str("kind") == "stage" || s.Bool("is_done") {
			past = append(past, s)
		}
	}
	// open steps by due date (no date last)
	for i := 1; i < len(open); i++ {
		for j := i; j > 0; j-- {
			a, b := open[j-1].Str("due_at"), open[j].Str("due_at")
			if a == "" {
				a = "9999"
			}
			if b == "" {
				b = "9999"
			}
			if strings.Compare(a, b) > 0 {
				open[j-1], open[j] = open[j], open[j-1]
			} else {
				break
			}
		}
	}
	today := conv.StrToTime(conv.Today())
	for _, s := range open {
		s["_late"] = s.Str("due_at") != "" && conv.StrToTime(s["due_at"]) < today
		s["_stage"] = stageLabel(c, s.Str("stage"))
	}
	for _, s := range past {
		isStage := s.Str("kind") == "stage"
		at := s["created_at"]
		who := s["creator"]
		if !isStage {
			if s["done_at"] != nil {
				at = s["done_at"]
			}
			if s["doer"] != nil {
				who = s["doer"]
			}
		}
		s["_stagekind"], s["_at"], s["_who"], s["_iso"] = isStage, at, who, conv.DateOf("c", at)
		if isStage {
			if s.Str("from_stage") != "" {
				s["_text"] = c.T("Moved from :from to :to", "from", stageLabel(c, s.Str("from_stage")), "to", stageLabel(c, s.Str("stage")))
			} else {
				s["_text"] = c.T("Created in :to", "to", stageLabel(c, s.Str("stage")))
			}
		}
		s["_stage"] = stageLabel(c, s.Str("stage"))
	}
	return string(c.Partial("crm/panels/steps", app.M{"row": row, "open": open, "past": past, "canEdit": canEdit}))
}

func productsPanel(c *app.Ctx, row db.Row) string {
	items, _ := row["product_list"].([]db.Row)
	total := 0.0
	complete := true
	for _, it := range items {
		if it["unit_price"] == nil {
			complete = false
			it["_price"], it["_total"] = "—", "—"
		} else {
			t := it.Float("quantity") * it.Float("unit_price")
			total += t
			it["_price"], it["_total"] = conv.NF(it.Float("unit_price"), 2), conv.NF(t, 2)
		}
		it["_qty"] = conv.NumberClean(it["quantity"])
	}
	return string(c.Partial("crm/panels/products", app.M{"row": row, "items": items, "complete": complete, "total": conv.NF(total, 2)}))
}
