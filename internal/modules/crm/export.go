package crm

import (
	"acme/internal/app"
	"acme/internal/db"
)

// LeadsByTax is what the accounting API shows on a customer: CRM leads with the same tax id, limited to what the
// person may see (CRM's own visibility rule, Access::visible). A failure gives an empty list, like PHP.
func LeadsByTax(c *app.Ctx, vat string) []db.Row {
	out := []db.Row{}
	if err := app.Catch(func() {
		s, p := visible(c.MustUser(), "t", "lead")
		out = db.On("crm").Select(`SELECT t.id, t.code, t.name_en FROM leads t WHERE t.deleted_at IS NULL AND regexp_replace(t.tax_id, '\D', '', 'g') = regexp_replace(?, '\D', '', 'g') AND `+s+` LIMIT 5`,
			append([]any{vat}, p...)...)
	}); err != nil {
		app.WriteErrorLog("[accounting-api] CRM link: " + err.Error())
		return []db.Row{}
	}
	return out
}
