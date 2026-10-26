// Package oil is the fuel price feed (App\Core\Support\OilPrices): synced from PTT in the morning,
// shown on My workspace and Management → Oil prices.
//
// NOTE: the functions below are the agreed interface; the management port replaces their bodies.
package oil

import "acme/internal/app"

// Current is OilPrices::current(): one row per fuel (code, name_en, name_th, color, price, change, effective_at),
// in display order; empty when nothing was synced yet.
func Current() []app.M { return nil }

// LastSync is OilPrices::lastSync(): the last sync log row (or nil).
func LastSync() app.M { return nil }

// Tick is OilPrices::tick(): the morning sync when cron does not run it (called by the bell feed). Never panics.
func Tick(c *app.Ctx) {}
