package app

import (
	"time"

	"acme/internal/php"
)

// PeriodPresets are the dashboard period buttons (Period::PRESETS), in order.
var PeriodPresets = []Opt{{Value: "today", Label: "Today"}, {Value: "7d", Label: "7 days"}, {Value: "30d", Label: "30 days"},
	{Value: "month", Label: "This month"}, {Value: "last_month", Label: "Last month"}, {Value: "90d", Label: "90 days"}, {Value: "year", Label: "This year"}}

// PeriodFromRequest is Period::fromRequest(): ?range=month|today|7d|30d|90d|last_month|year|custom&from=&to= (default: this month).
// Returns from, to (inclusive, midnight in the company zone) and the preset key.
func (c *Ctx) PeriodFromRequest() (time.Time, time.Time, string) {
	n := php.Now()
	today := time.Date(n.Year(), n.Month(), n.Day(), 0, 0, 0, 0, php.Loc)
	first := time.Date(today.Year(), today.Month(), 1, 0, 0, 0, 0, php.Loc)
	key := c.Query("range", "month")
	from, to := first, first.AddDate(0, 1, -1)
	switch key {
	case "today":
		from, to = today, today
	case "7d":
		from, to = today.AddDate(0, 0, -6), today
	case "30d":
		from, to = today.AddDate(0, 0, -29), today
	case "90d":
		from, to = today.AddDate(0, 0, -89), today
	case "last_month":
		from, to = first.AddDate(0, -1, 0), first.AddDate(0, 0, -1)
	case "year":
		from, to = time.Date(today.Year(), 1, 1, 0, 0, 0, 0, php.Loc), time.Date(today.Year(), 12, 31, 0, 0, 0, 0, php.Loc)
	case "custom":
		f, e1 := time.ParseInLocation("2006-01-02", c.Query("from"), php.Loc)
		t, e2 := time.ParseInLocation("2006-01-02", c.Query("to"), php.Loc)
		if e1 == nil && e2 == nil {
			if f.After(t) {
				f, t = t, f
			}
			from, to = f, t
		} else {
			key = "month"
		}
	default:
		key = "month"
	}
	if to.Sub(from) > 730*24*time.Hour {
		from = to.AddDate(0, 0, -730)
	}
	return from, to, key
}

// RangeSelect is one dropdown of partials/range-bar: name, label, options, value, placeholder.
type RangeSelect struct {
	Name, Label string
	Options     []Opt
	Value       string
	Placeholder string
}

type rangePreset struct{ Key, Label, URL string }

// rangeKeep: the hidden inputs and preset links of partials/range-bar (the action URL is relative to the site).
func rangeKeep(c *Ctx, action string, keep map[string]any, selects []RangeSelect) M {
	clean := map[string]any{}
	for k, v := range keep {
		s := php.Str(v)
		if v == nil || s == "" || s == "0" {
			continue
		}
		clean[k] = v
	}
	own := map[string]bool{"from": true, "to": true, "range": true}
	for _, s := range selects {
		own[s.Name] = true
	}
	hidden := map[string]string{}
	for k, v := range clean {
		if !own[k] {
			hidden[k] = php.Str(v)
		}
	}
	var presets []rangePreset
	for _, p := range PeriodPresets {
		q := map[string]any{}
		for k, v := range clean {
			q[k] = v
		}
		q["range"] = p.Value
		presets = append(presets, rangePreset{Key: p.Value, Label: p.Label, URL: urlWith(action, q)})
	}
	return M{"hidden": hidden, "presets": presets}
}

// urlWith appends a query to an absolute URL.
func urlWith(u string, q map[string]any) string {
	s := buildQuery(q)
	if s == "" {
		return u
	}
	return u + "?" + s
}
