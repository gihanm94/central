package accounting

import (
	"errors"
	"regexp"
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/php"
)

/*
syncer (Erp\Syncer) copies one ERP entity (see config/erp.json) into its local table.

	latest  the newest N rows (what the hot / cold schedule uses)
	all     every row, 1000 at a time
	one     a single id
*/
type syncer struct{ client *erpClient }

const syncPage = 1000

func newSyncer() *syncer { return &syncer{client: newErpClient()} }

// erpEntities is config('erp.entities').
func erpEntities() *config.Map { return cfgMap("erp.entities") }

func entityDef(key string) *config.Map {
	d, _ := erpEntities().Vals[key].(*config.Map)
	return d
}

// overwriteOf: the overwrite setting of the ERP table that holds this entity (children of an order are saved by that table's own setting).
func overwriteOf(entity string) bool {
	ents := erpEntities()
	for _, k := range ents.Keys {
		d, _ := ents.Vals[k].(*config.Map)
		if mstr(d, "entity") == entity {
			return overwriteOn(k)
		}
	}
	return false
}

var idCleanRe = regexp.MustCompile(`[^0-9A-Za-z_-]`)

type syncResult struct{ fetched, saved int }

// run copies one entity; force nil = only "one" always updates. A failure is recorded and thrown again (panics).
func (s *syncer) run(key, mode, id string, size int, filter *string, force *bool) syncResult {
	def := entityDef(key)
	if def == nil {
		panic(errors.New("Unknown ERP entity " + key))
	}
	start := time.Now()
	forced := mode == "one"
	if force != nil {
		forced = *force
	}
	acc().Exec(`INSERT INTO erp_sync_state (entity, status, mode, last_run_at, error) VALUES (?, 'running', ?, now(), NULL)
                  ON CONFLICT (entity) DO UPDATE SET status = 'running', mode = EXCLUDED.mode, last_run_at = now(), error = NULL`, key, mode)
	fetched, saved := 0, 0
	var flt, idv any
	if filter != nil {
		flt = *filter
	}
	if id != "" {
		idv = id
	}
	logInfo("sync", key+": start ("+mode+")", om("api", mget(def, "api"), "filter", flt, "id", idv))
	ms := func() int { return int(time.Since(start).Milliseconds()) }
	err := app.Catch(func() {
		limit := -1
		if mode == "latest" {
			limit = size
			if size == 0 {
				limit = php.Int(mget(def, "size"))
			}
		} else if mode == "one" {
			limit = 1
		}
		skip := 0
		for {
			top := syncPage
			if limit >= 0 {
				top = min(syncPage, limit-skip)
			}
			if top <= 0 {
				break
			}
			q := erpQuery{Top: top, Skip: skip, OrderBy: "Id desc"} // newest first, always
			if ex := config.AsStrings(mget(def, "expand")); len(ex) > 0 {
				q.Expand = ex
			} else if cols := config.AsStrings(mget(def, "columns")); len(cols) > 0 {
				q.Select = cols // nested columns are only safe with a plain select
			}
			if filter != nil {
				q.Filter = *filter
			}
			if mode == "one" {
				q.Filter = "Id eq '" + idCleanRe.ReplaceAllString(id, "") + "'"
			}
			page := s.client.page(mstr(def, "api"), q)
			if page.err != "" {
				panic(errors.New(page.err))
			}
			fetched += len(page.rows)
			saved += s.save(def, page.rows, forced, key)
			skip += len(page.rows)
			if len(page.rows) < top || mode == "one" {
				break
			}
		}
		acc().Exec("UPDATE erp_sync_state SET status = 'ok', last_ok_at = now(), fetched = ?, saved = ?, duration_ms = ?, error = NULL WHERE entity = ?", fetched, saved, ms(), key)
		if inStrs(key, []string{"invoice", "order", "comment", "order_invoice", "customer", "extra_field"}) {
			documentsRefreshQuietly()
		}
		if inStrs(key, []string{"invoice", "seller", "department"}) {
			sellerStampQuietly() // seller + department on the invoice lines
		}
		logInfo("sync", key+": done — fetched "+php.Str(fetched)+", saved "+php.Str(saved), om("ms", ms()))
	})
	if err != nil {
		logException("sync", err, key+" failed", om("fetched", fetched, "saved", saved, "mode", mode))
		acc().Exec("UPDATE erp_sync_state SET status = 'failed', fetched = ?, saved = ?, duration_ms = ?, error = ? WHERE entity = ?", fetched, saved, ms(), php.Substr(err.Error(), 0, 500), key)
		panic(err)
	}
	return syncResult{fetched, saved}
}

// save stores the rows and everything found inside them (addresses of an order, rows of an order …).
func (s *syncer) save(def *config.Map, raw []*config.Map, forced bool, key string) int {
	var main []*erpRow
	kids := map[string][]*erpRow{}
	var order []string
	for _, r := range raw {
		row := storeMap(mstr(def, "entity"), r, nil)
		if row == nil {
			continue
		}
		main = append(main, row)
		children, _ := mget(def, "children").([]any)
		for _, ch := range children {
			c, _ := ch.(*config.Map)
			var v any
			for _, k := range r.Keys {
				if strings.EqualFold(k, mstr(c, "path")) {
					v = r.Vals[k]
				}
			}
			var list []any
			switch x := v.(type) {
			case []any:
				if len(x) == 0 {
					continue
				}
				if php.Truthy(mget(c, "many")) {
					list = x
				} else {
					list = []any{x}
				}
			case *config.Map:
				if x.Len() == 0 {
					continue
				}
				if php.Truthy(mget(c, "many")) {
					for _, k := range x.Keys {
						list = append(list, x.Vals[k])
					}
				} else {
					list = []any{x}
				}
			default:
				continue
			}
			stamp := map[string]any{}
			if sm := mmap(c, "stamp"); sm != nil {
				for _, col := range sm.Keys {
					stamp[col] = row.vals[php.Str(sm.Vals[col])]
				}
			}
			ent := mstr(c, "entity")
			for _, item := range list {
				im, ok := item.(*config.Map)
				if !ok {
					continue
				}
				if m := storeMap(ent, im, stamp); m != nil {
					if _, seen := kids[ent]; !seen {
						order = append(order, ent)
					}
					kids[ent] = append(kids[ent], m)
				}
			}
		}
	}
	// parents first, so rows that point at each other find what they need
	for _, ent := range order {
		rows := kids[ent]
		if err := app.Catch(func() { storeUpsert(ent, rows, forced || overwriteOf(ent)) }); err != nil {
			logException("sync", err, "child "+ent+" not saved", om("rows", len(rows)))
		}
	}
	return storeUpsert(mstr(def, "entity"), main, forced || (key != "" && overwriteOn(key)))
}

func strp(s string) *string { return &s }
func boolp(b bool) *bool    { return &b }
