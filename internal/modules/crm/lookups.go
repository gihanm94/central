package crm

import (
	"html/template"
	"net/http"
	"strings"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

// Industries, lead sources, products and currencies: simple lists the whole CRM draws from.
// Managed with the crm_settings permission (LookupController and its subclasses).

// settingsTabs: tabs shown above every settings screen — only the ones this person may open.
func settingsTabs(c *app.Ctx) [][]any {
	var tabs [][]any
	if c.Can("crm_settings", "view") {
		for _, t := range [][3]string{{"industries", "Industries", "building"}, {"lead-sources", "Lead sources", "target"}, {"products", "Products", "box"}, {"currencies", "Currencies", "calc"}} {
			tabs = append(tabs, []any{"/crm/settings/" + t[0], c.T(t[1]), t[2]})
		}
	}
	if c.Can("crm_stages", "view") {
		tabs = append(tabs, []any{"/crm/settings/stages", c.T("Stages"), "columns"})
	}
	return tabs
}

func lookup(res *app.Resource, unique string, fields func(c *app.Ctx, row db.Row) []app.Field) *app.Resource {
	res.Conn, res.Module, res.NoSoftDelete = "crm", "crm", true
	if res.OrderBy == "" {
		res.OrderBy = "t.id"
	}
	res.ScopeSQL = func(c *app.Ctx) (string, []any) { return "TRUE", nil }
	res.Fields = fields
	res.ExtraMeta = func(c *app.Ctx) app.M { return app.M{"tabs": settingsTabs(c)} }
	res.TopExtra = func(c *app.Ctx) template.HTML { return c.Partial("partials/tabs", app.M{"tabs": settingsTabs(c)}) }
	if res.Label == nil {
		res.Label = func(c *app.Ctx, row db.Row) string {
			return php.OrStr(row.Str("name"), row.Str("code"), "#"+row.Str("id"))
		}
	}
	prev := res.Prepare
	res.Prepare = func(c *app.Ctx, data *app.Clean, existing db.Row) *app.Clean {
		if prev != nil {
			data = prev(c, data, existing)
		}
		if unique != "" && data.Str(unique) != "" {
			var taken any
			if existing != nil {
				taken = db.On("crm").Scalar("SELECT 1 FROM "+res.Table+" WHERE lower("+unique+") = lower(?) AND id <> ?", data.Str(unique), existing["id"])
			} else {
				taken = db.On("crm").Scalar("SELECT 1 FROM "+res.Table+" WHERE lower("+unique+") = lower(?)", data.Str(unique))
			}
			if taken != nil {
				label := ""
				for _, f := range app.RealFields(res.Fields(c, nil)) {
					if f.Name == unique {
						label = strings.ToLower(f.Label)
					}
				}
				app.Invalid(unique, c.T("This :field is already used.", "field", label))
			}
		}
		data.Set("updated_by", c.MustUser().ID)
		if existing == nil {
			data.Set("created_by", c.MustUser().ID)
		}
		return data
	}
	return res
}

func countCol(table, column string) func(c *app.Ctx, r db.Row) template.HTML {
	return func(c *app.Ctx, r db.Row) template.HTML {
		return h(app.E(db.On("crm").Scalar("SELECT count(*) FROM "+table+" WHERE "+column+" = ?", r["id"])))
	}
}

var industries = lookup(&app.Resource{Resource: "crm_settings", Table: "industries", Type: "industry", Singular: "industry", Plural: "industries",
	Base: "/crm/settings/industries", Icon: "building", OrderBy: "t.name", Searchable: []string{"t.name"},
	Columns: func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "name", Label: c.T("Industry"), Primary: true, Sort: "t.name", Render: func(c *app.Ctx, r db.Row) template.HTML { return h(uiChip(r.Str("name"), r.Str("color"))) }},
			{Key: "leads", Label: c.T("Leads"), Render: countCol("lead_industries", "industry_id")},
		}
	},
}, "name", func(c *app.Ctx, row db.Row) []app.Field {
	return []app.Field{
		{Name: "name", Label: c.T("Name"), Rules: "required|max:120", Example: "Manufacturing"},
		{Name: "color", Label: c.T("Colour"), Type: "color", Rules: "required|max:9", Default: "#2a78d6", Example: "#2a78d6"},
	}
})

var leadSources = lookup(&app.Resource{Resource: "crm_settings", Table: "lead_sources", Type: "lead_source", Singular: "lead source", Plural: "lead sources",
	Base: "/crm/settings/lead-sources", Icon: "target", OrderBy: "t.name", Searchable: []string{"t.name"},
	Columns: func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "name", Label: c.T("Lead source"), Primary: true, Sort: "t.name", Render: func(c *app.Ctx, r db.Row) template.HTML { return h(uiChip(r.Str("name"), r.Str("color"))) }},
			{Key: "leads", Label: c.T("Leads"), Render: countCol("lead_lead_sources", "lead_source_id")},
		}
	},
}, "name", func(c *app.Ctx, row db.Row) []app.Field {
	return []app.Field{
		{Name: "name", Label: c.T("Name"), Rules: "required|max:120", Example: "Website"},
		{Name: "color", Label: c.T("Colour"), Type: "color", Rules: "required|max:9", Default: "#1baf7a", Example: "#1baf7a"},
	}
})

// Registered products. Anything not listed here can still be typed on an opportunity as an "other" product.
var products = lookup(&app.Resource{Resource: "crm_settings", Table: "products", Type: "product", Singular: "product", Plural: "products",
	Base: "/crm/settings/products", Icon: "box", OrderBy: "t.name", Searchable: []string{"t.name", "t.code"},
	Columns: func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "name", Label: c.T("Product"), Primary: true, Sort: "t.name", Render: func(c *app.Ctx, r db.Row) template.HTML {
				return h(`<span class="font-medium">` + app.E(r["name"]) + `</span><span class="block text-xs text-steel">` + app.E(r.Str("code")) + `</span>`)
			}},
			{Key: "price", Label: c.T("Unit price"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				out := `<span class="tabular-nums">` + uiMoney(r["unit_price"], r.Str("currency")) + `</span>`
				if r.Str("unit") != "" {
					out += `<span class="text-xs text-steel"> / ` + app.E(r["unit"]) + `</span>`
				}
				return h(out)
			}},
			{Key: "active", Label: c.T("Status"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				if r.Bool("is_active") {
					return h(uiBadge(c.T("Active"), "success"))
				}
				return h(uiBadge(c.T("Inactive"), "neutral"))
			}},
		}
	},
}, "code", func(c *app.Ctx, row db.Row) []app.Field {
	cur := optsFrom(db.On("crm").Select("SELECT code, code AS l FROM currencies ORDER BY is_base DESC, code"), "code", "l")
	return []app.Field{
		{Name: "name", Label: c.T("Name"), Rules: "required|max:200", Span: 2, Example: "Servo filling machine"},
		{Name: "code", Label: c.T("Code"), Rules: "nullable|max:40", Example: "SFM-100"},
		{Name: "unit", Label: c.T("Unit"), Rules: "nullable|max:30", Example: "set"},
		{Name: "unit_price", Label: c.T("Unit price"), Type: "number", Rules: "nullable|numeric", Example: "250000"},
		{Name: "currency", Label: c.T("Currency"), Type: "select", Rules: "required", Default: "THB", Options: cur},
		{Name: "description", Label: c.T("Description"), Type: "textarea", Span: 2, Rules: "nullable|max:2000"},
		{Name: "is_active", Label: c.T("Active"), Type: "checkbox", Default: true, Help: c.T("Inactive products no longer appear in the opportunity form.")},
	}
})

// Currencies and the rate that turns them into the base currency on the dashboard.
var currencies = lookup(&app.Resource{Resource: "crm_settings", Table: "currencies", Type: "currency", Singular: "currency", Plural: "currencies",
	Base: "/crm/settings/currencies", Icon: "calc", OrderBy: "t.is_base DESC, t.code", Searchable: []string{"t.code", "t.name"},
	Columns: func(c *app.Ctx) []app.Column {
		return []app.Column{
			{Key: "code", Label: c.T("Currency"), Primary: true, Sort: "t.code", Render: func(c *app.Ctx, r db.Row) template.HTML {
				out := `<span class="font-medium">` + app.E(r["code"]) + `</span> <span class="text-steel">` + app.E(r["name"]) + `</span>`
				if r.Bool("is_base") {
					out += " " + uiBadge(c.T("Base"), "success")
				}
				return h(out)
			}},
			{Key: "rate", Label: c.T("Rate to base"), Render: func(c *app.Ctx, r db.Row) template.HTML {
				s := strings.TrimRight(strings.TrimRight(php.NF(r.Float("rate_to_base"), 6), "0"), ".")
				return h(`<span class="tabular-nums">` + app.E(s) + `</span>`)
			}},
		}
	},
	Saved: func(c *app.Ctx, id int, data *app.Clean, existing db.Row) {
		if data.Bool("is_base") {
			db.On("crm").Exec("UPDATE currencies SET is_base = FALSE WHERE id <> ?", id)
		}
	},
	BeforeDelete: func(c *app.Ctx, row db.Row) {
		if row.Bool("is_base") {
			c.Back("error", c.T("The base currency can't be deleted. Make another currency the base first."))
		}
		if db.On("crm").Scalar("SELECT 1 FROM opportunities WHERE currency = ? LIMIT 1", row["code"]) != nil || db.On("crm").Scalar("SELECT 1 FROM products WHERE currency = ? LIMIT 1", row["code"]) != nil {
			c.Back("error", c.T("This currency is used by opportunities or products, so it can't be deleted."))
		}
	},
	Prepare: func(c *app.Ctx, data *app.Clean, existing db.Row) *app.Clean {
		if data.Get("code") != nil {
			code := strings.ToUpper(data.Str("code"))
			data.Set("code", code)
			if !regexpUpper3.MatchString(code) {
				app.Invalid("code", c.T("Use three letters, e.g. USD."))
			}
		}
		if data.Get("rate_to_base") != nil && php.Float(data.Get("rate_to_base")) <= 0 {
			app.Invalid("rate_to_base", c.T("The rate must be more than zero."))
		}
		if data.Bool("is_base") {
			data.Set("rate_to_base", 1)
		}
		return data
	},
}, "code", func(c *app.Ctx, row db.Row) []app.Field {
	base := row != nil && row.Bool("is_base")
	return []app.Field{
		{Name: "code", Label: c.T("Code"), Rules: "required|min:3|max:3", Readonly: row != nil, Help: c.T("Three letters, e.g. USD."), Example: "USD"},
		{Name: "name", Label: c.T("Name"), Rules: "required|max:60", Example: "US dollar"},
		{Name: "rate_to_base", Label: c.T("Rate to base currency"), Type: "number", Rules: "required|numeric", Default: 1, Readonly: base,
			Help: c.T("How many base-currency units one unit of this currency is worth. Used for dashboard totals."), Example: "35"},
		{Name: "is_base", Label: c.T("Base currency"), Type: "checkbox", Readonly: base, Help: c.T("Dashboard totals are shown in this currency.")},
	}
})

// settingsHome: /crm/settings goes to the first settings tab.
func settingsHome(c *app.Ctx) {
	tabs := settingsTabs(c)
	if len(tabs) == 0 {
		app.Abort(http.StatusForbidden, c.T("You do not have access to CRM settings."))
	}
	c.Redirect(php.Str(tabs[0][0]))
}
