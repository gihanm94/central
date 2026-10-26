package app

/*
Module hooks (the 'hooks' of module.php). A module puts typed funcs in Module.Hooks; callers use RunHooks and a
type switch. Values crossing modules are JSON-like and keep the PHP array keys exactly:
maps are M, lists of maps are []M, lists of scalars []any, numbers int / float64, everything else string / bool / nil.
A file to send back is M{"name": …, "mime": …, "body": []byte}.

	app.RunHooks("revenue.ranking", "accounting", func(_ string, fn any) any {
	    if f, ok := fn.(app.HookPeriod); ok { return f(c, from, to) }
	    return nil
	})["accounting"]
*/

// HookTick: polled about once a minute by every signed-in page (the notification feed) — "tick".
type HookTick func(c *Ctx)

// HookRequest: every request to the module's pages — "request".
type HookRequest func(c *Ctx)

// HookError: an uncaught error on the module's pages — "error".
type HookError func(c *Ctx, err error)

// HookCalendarEvents: the shared /calendar page — "calendar.events": returns M{"events": []M, "linked": []any}.
type HookCalendarEvents func(c *Ctx, u *CurrentUser, from, to string, mine bool) M

// HookPeriod: a figure set for a period — "management.crm" (crm), "revenue.ranking" (accounting). nil = nothing.
type HookPeriod func(c *Ctx, from, to string) M

// HookFilters: a report from filters — "account.report" (accounting).
type HookFilters func(c *Ctx, filters M) M

// HookUserList: a list for a person — "erp.invoices", "erp.billing" (accounting): filters are the query values.
type HookUserList func(c *Ctx, u *CurrentUser, filters M) M

// HookUserFile: a PDF for a person — "erp.invoice_pdf" (id, doc), "erp.billing_pdf" (id, doc ""): nil = not allowed / missing.
type HookUserFile func(c *Ctx, u *CurrentUser, id int, doc string) M

// HookString: one string in, a result out — "customer.by_tax" ([]M), "customer.get" (M), "customer.invoices" (M),
// "customer.quotes" (M) (accounting).
type HookString func(c *Ctx, s string) any

// HookSearch: "product.search" (accounting): M{"items": []M, "more": bool}.
type HookSearch func(c *Ctx, q string, page, per int) M

// HookScopes: "google.scopes" (sheets): extra Google OAuth scopes.
type HookScopes func() []string

// HookGoogleConnected: "google.connected" (sheets): a person connected Google.
type HookGoogleConnected func(c *Ctx, userID int, email, access, refresh string, expiresIn int, scopes []string)

// Hook calls one module's hook and returns its result (nil when the module is not usable or has no such hook).
func Hook(name, module string, call func(fn any) any) any {
	if !ModuleUsable(module) {
		return nil
	}
	return RunHooks(name, module, func(_ string, fn any) any { return call(fn) })[module]
}
