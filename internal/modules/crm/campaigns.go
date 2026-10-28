package crm

import (
	"html/template"
	"strings"

	"acme/internal/app"
	"acme/internal/conv"
	"acme/internal/db"
)

// Marketing campaigns with budget tracking and attachments (CampaignController).

var campaigns = (&screen{
	entity: "campaign", prefix: "CP", dateCode: true, files: true,
	res: &app.Resource{Resource: "crm_campaigns", Table: "campaigns", Type: "campaign", Singular: "campaign", Plural: "campaigns", Base: "/crm/campaigns", Icon: "chat",
		OrderBy:    "CASE t.status WHEN 'ACTIVE' THEN 0 WHEN 'PLANNED' THEN 1 WHEN 'DRAFT' THEN 2 ELSE 3 END, t.start_date DESC NULLS LAST, t.id DESC",
		Searchable: []string{"t.name", "t.code", "t.description"},
		Label:      func(c *app.Ctx, row db.Row) string { return row.Str("name") },
		ExtraMeta:  func(c *app.Ctx) app.M { return app.M{"wizard_style": "dots"} },
	},
}).setup()

var campaignTones = map[string]string{"ACTIVE": "success", "PLANNED": "info", "DRAFT": "neutral", "COMPLETED": "violet", "CANCELLED": "danger"}

func campaignTone(s string) string { return conv.OrStr(campaignTones[s], "neutral") }

func init() {
	s := campaigns
	r := s.res
	r.Filters = func(c *app.Ctx) []app.Filter {
		return append([]app.Filter{
			{Name: "type", Label: c.T("All types"), Options: tr(c, campaignTypes), Column: "t.type"},
			{Name: "status", Label: c.T("All statuses"), Options: tr(c, campaignStatuses), Column: "t.status"},
		}, commonFilters(c)...)
	}
	r.Fields = func(c *app.Ctx, row db.Row) []app.Field {
		no := false
		f := []app.Field{
			{Section: c.T("Basic info"), Icon: "chat", Help: c.T("Name, type, status and dates")},
			{Name: "name", Label: c.T("Campaign name"), Rules: "required|max:200", Span: 2, Placeholder: c.T("e.g. Spring packaging expo"), Example: "Spring packaging expo"},
			{Name: "type", Label: c.T("Campaign type"), Type: "select", Options: tr(c, campaignTypes), Rules: "required", Default: "EMAIL", Search: &no},
			{Name: "status", Label: c.T("Status"), Type: "select", Options: tr(c, campaignStatuses), Rules: "required", Default: "DRAFT", Search: &no},
			{Name: "start_date", Label: c.T("Start date"), Type: "date", Rules: "nullable|date"},
			{Name: "end_date", Label: c.T("End date"), Type: "date", Rules: "nullable|date"},
			{Name: "code", Label: c.T("Code"), Rules: "nullable|max:30", HideForm: true},

			{Section: c.T("Budget and results"), Icon: "calc", Help: c.T("What it costs and what it brings")},
			{Name: "budget", Label: c.T("Budget"), Type: "number", Rules: "nullable|numeric", Placeholder: "0.00"},
			{Name: "actual_cost", Label: c.T("Actual cost"), Type: "number", Rules: "nullable|numeric", Placeholder: "0.00"},
			{Name: "expected_revenue", Label: c.T("Expected revenue"), Type: "number", Rules: "nullable|numeric", Placeholder: "0.00", Span: 2},
			{Name: "expected_response_rate", Label: c.T("Expected response rate (%)"), Type: "number", Rules: "nullable|numeric", Placeholder: c.T("e.g. 5")},
			{Name: "actual_response_rate", Label: c.T("Actual response rate (%)"), Type: "number", Rules: "nullable|numeric", Placeholder: c.T("e.g. 4.2")},

			{Section: c.T("Description"), Icon: "list", Help: c.T("Rich text notes about this campaign")},
			{Name: "description", Label: c.T("Description"), Type: "richtext", Span: 2, Rules: "nullable", Counter: true},
		}
		f = append(f, s.filesFields(c, row)...)
		return append(f, s.ownershipFields(c, row)...)
	}
	r.Columns = func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "name", Label: c.T("Campaign"), Primary: true, Sort: "t.name", Render: func(c *app.Ctx, r db.Row) template.HTML {
				sub := strings.Trim(trLabel(c, campaignTypes, r.Str("type"))+" · "+r.Str("code"), " ·")
				return h(uiTitle(c, r.Str("name"), sub, "/crm/campaigns/"+r.Str("id")))
			}},
			{Key: "status", Label: c.T("Status"), Sort: "t.status", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return h(uiBadge(trLabel(c, campaignStatuses, r.Str("status")), campaignTone(r.Str("status"))))
			}},
			{Key: "period", Label: c.T("Period"), Sort: "t.start_date", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return h(`<span class="tabular-nums text-steel">` + app.E(app.FormatDate(c, r["start_date"], "d M")+" → "+app.FormatDate(c, r["end_date"], "d M Y")) + `</span>`)
			}},
			{Key: "budget", Label: c.T("Budget"), Sort: "t.budget", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return h(`<span class="tabular-nums">` + uiMoney(r["budget"], "") + `</span>`)
			}},
			{Key: "cost", Label: c.T("Spent"), Sort: "t.actual_cost", Render: func(c *app.Ctx, r db.Row) template.HTML {
				if r["actual_cost"] == nil {
					return h(uiDash())
				}
				if r.Float("budget") > 0 {
					return h(meter(c, minF(100, r.Float("actual_cost")/r.Float("budget")*100), conv.NumberClean(r["actual_cost"])))
				}
				return h(`<span class="tabular-nums">` + uiMoney(r["actual_cost"], "") + `</span>`)
			}},
			createdColumn(c),
		}
	}
	s.badges = func(c *app.Ctx, r db.Row) []string {
		return []string{uiBadge(trLabel(c, campaignStatuses, r.Str("status")), campaignTone(r.Str("status"))), uiBadge(trLabel(c, campaignTypes, r.Str("type")), "violet")}
	}
	s.subtitle = func(c *app.Ctx, r db.Row) string {
		p := ""
		if r["start_date"] != nil {
			p = dateRange(c, r["start_date"], r["end_date"])
		}
		return joinNonEmpty(" · ", r.Str("code"), p)
	}
	s.showMain = func(c *app.Ctx, row db.Row) string { return campaignStats(c, row) }
	s.statCards = func(c *app.Ctx) []app.Stat {
		return []app.Stat{
			card(c.T("Active"), s.figure(c, "count(*)", "t.status = 'ACTIVE'", nil, ""), "", "success", "chat", ""),
			card(c.T("Planned"), s.figure(c, "count(*)", "t.status IN ('PLANNED','DRAFT')", nil, ""), "", "info", "chat", ""),
			card(c.T("Budget"), uiCompact(s.figure(c, "sum(t.budget)", "", nil, "")), "", "neutral", "chart", ""),
			card(c.T("Spent"), uiCompact(s.figure(c, "sum(t.actual_cost)", "", nil, "")), "", "warn", "chart", ""),
		}
	}
}

func minF(a, b float64) float64 {
	if a < b {
		return a
	}
	return b
}

func campaignStats(c *app.Ctx, row db.Row) string {
	has := func(k string) bool { return row[k] != nil }
	var budget, cost float64
	if has("budget") {
		budget = row.Float("budget")
	}
	if has("actual_cost") {
		cost = row.Float("actual_cost")
	}
	var used *float64
	if budget != 0 && has("actual_cost") {
		u := cost / budget * 100
		used = &u
	}
	var roi *float64
	if cost != 0 && has("expected_revenue") {
		x := (row.Float("expected_revenue") - cost) / cost * 100
		roi = &x
	}
	num := func(k string) string {
		if !has(k) {
			return "—"
		}
		return conv.NF(row.Float(k), 2)
	}
	over := c.T("Over budget")
	var costNote, remNote, revNote, rateNote string
	if used != nil {
		costNote = c.T(":n% of budget", "n", fmtFloat(roundHalf(*used, 0)))
	}
	remaining := "—"
	if has("budget") && has("actual_cost") {
		remaining = conv.NF(budget-cost, 2)
		if cost > budget {
			remNote = over
		}
	}
	if roi != nil {
		revNote = c.T("Expected return :n%", "n", fmtFloat(roundHalf(*roi, 0)))
	}
	rate := "—"
	if has("actual_response_rate") {
		rate = conv.NumberClean(row["actual_response_rate"]) + "%"
	}
	if has("expected_response_rate") {
		rateNote = c.T("Expected :n%", "n", conv.NumberClean(row["expected_response_rate"]))
	}
	tiles := [][]any{
		{c.T("Budget"), num("budget"), "", false},
		{c.T("Actual cost"), num("actual_cost"), costNote, false},
		{c.T("Remaining"), remaining, remNote, remNote == over},
		{c.T("Expected revenue"), num("expected_revenue"), revNote, false},
		{c.T("Response rate"), rate, rateNote, false},
	}
	data := app.M{"tiles": tiles, "meter": template.HTML("")}
	if used != nil {
		data["meter"] = meterHTML(c, *used, fmtFloat(roundHalf(*used, 0))+"%")
	}
	return string(c.Partial("crm/panels/campaign-stats", data))
}
