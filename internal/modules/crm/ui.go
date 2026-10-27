package crm

import (
	"html/template"
	"math"
	"regexp"
	"strings"

	"acme/internal/app"
	"acme/internal/php"
)

// Small HTML snippets of the list and detail screens (App\Core\Support\Ui + App\Modules\CRM\Support\Ui). Everything is escaped here.

var uiTones = map[string]string{
	"neutral": "bg-graphite-900/6 text-graphite-800",
	"success": "bg-emerald-50 text-emerald-800",
	"warn":    "bg-amber-50 text-amber-800",
	"danger":  "bg-signal-50 text-signal-800",
	"info":    "bg-sky-50 text-sky-800",
	"violet":  "bg-violet-50 text-violet-800",
}

func uiBadge(text, tone string) string {
	cl, ok := uiTones[tone]
	if !ok {
		cl = uiTones["neutral"]
	}
	return `<span class="badge ` + cl + `">` + app.E(text) + `</span>`
}

var chipColor = regexp.MustCompile(`(?i)^#[0-9a-f]{3,8}$`)

// uiChip is a pill with the colour of an industry / lead source as a dot.
func uiChip(text, color string) string {
	if !chipColor.MatchString(color) {
		color = "#8a909a"
	}
	return `<span class="inline-flex items-center gap-1.5 rounded-full bg-mist px-2 py-0.5 text-xs font-medium text-graphite-800"><span class="size-2 rounded-full" style="background:` + app.E(color) + `"></span>` + app.E(text) + `</span>`
}

func uiChips(items []app.M) string {
	if len(items) == 0 {
		return `<span class="text-graphite-400">—</span>`
	}
	var b strings.Builder
	b.WriteString(`<span class="flex flex-wrap gap-1">`)
	for _, i := range items {
		b.WriteString(uiChip(php.Str(i["name"]), php.Str(i["color"])))
	}
	b.WriteString(`</span>`)
	return b.String()
}

// uiMoney: 1,234.50 THB, "—" when empty (escaped text).
func uiMoney(amount any, currency string) string {
	if amount == nil || php.Str(amount) == "" {
		return "—"
	}
	s := php.NF(php.Float(amount), 2)
	if currency != "" {
		s += " " + currency
	}
	return app.E(s)
}

// uiCompact: 1 234 567 → 1.2M (chart labels).
func uiCompact(n float64) string {
	a := math.Abs(n)
	for _, d := range []struct {
		div float64
		sfx string
	}{{1e9, "B"}, {1e6, "M"}, {1e3, "K"}} {
		if a >= d.div {
			s := php.NF(n/d.div, 1)
			s = strings.TrimRight(strings.TrimRight(s, "0"), ".")
			return s + d.sfx
		}
	}
	return php.NF(n, 0)
}

// uiPerson: avatar (picture or initials) with a name and a small second line — the first cell of most lists.
func uiPerson(c *app.Ctx, name, sub, img, href, shape string) string {
	if shape == "" {
		shape = "rounded-md"
	}
	var pic string
	if img != "" {
		pic = `<img src="` + app.E(c.UploadURL(img)) + `" alt="" class="size-9 shrink-0 ` + shape + ` bg-white object-contain ring-1 ring-graphite-900/10">`
	} else {
		pic = `<span class="inline-flex size-9 shrink-0 items-center justify-center ` + shape + ` bg-graphite-900/6 text-xs font-semibold text-graphite-700">` + app.E(php.Initials(name)) + `</span>`
	}
	var title string
	if href != "" {
		title = `<a href="` + app.E(c.URL(href)) + `" class="block truncate font-medium hover:text-signal-700">` + app.E(name) + `</a>`
	} else {
		title = `<span class="block truncate font-medium">` + app.E(name) + `</span>`
	}
	s := ""
	if sub != "" {
		s = `<span class="block truncate text-xs text-steel">` + app.E(sub) + `</span>`
	}
	return `<div class="flex min-w-0 max-w-[15rem] items-center gap-3">` + pic + `<div class="min-w-0">` + title + s + `</div></div>`
}

// uiTitle: a list's name cell without a picture: title (linked) and a line under it.
func uiTitle(c *app.Ctx, name, sub, href string) string {
	var title string
	if href != "" {
		title = `<a href="` + app.E(c.URL(href)) + `" class="block truncate font-medium hover:text-signal-700">` + app.E(name) + `</a>`
	} else {
		title = `<span class="block truncate font-medium">` + app.E(name) + `</span>`
	}
	s := ""
	if sub != "" {
		s = `<span class="block truncate text-xs text-steel">` + app.E(sub) + `</span>`
	}
	return `<div class="min-w-0 max-w-[18rem]">` + title + s + `</div>`
}

func uiLink(c *app.Ctx, href, text string) string {
	return `<a href="` + app.E(c.URL(href)) + `" class="font-medium hover:text-signal-700">` + app.E(text) + `</a>`
}

// uiAvatars: a row of overlapping round initials, e.g. the people responsible.
func uiAvatars(names []string, max int) string {
	if len(names) == 0 {
		return uiDash()
	}
	if max <= 0 {
		max = 4
	}
	var b strings.Builder
	for i, n := range names {
		if i >= max {
			break
		}
		b.WriteString(`<span title="` + app.E(n) + `" class="inline-flex size-7 items-center justify-center rounded-full bg-graphite-900/8 text-[10px] font-semibold text-graphite-700 ring-2 ring-white">` + app.E(php.Initials(n)) + `</span>`)
	}
	if len(names) > max {
		b.WriteString(`<span title="` + app.E(strings.Join(names[max:], ", ")) + `" class="inline-flex size-7 items-center justify-center rounded-full bg-graphite-900 text-[10px] font-semibold text-white ring-2 ring-white">+` + php.Str(len(names)-max) + `</span>`)
	}
	return `<span class="inline-flex -space-x-2">` + b.String() + `</span>`
}

func uiDash() string { return `<span class="text-graphite-400">—</span>` }

// uiStageBadge: stage name with the colour set for it in CRM settings.
func uiStageBadge(c *app.Ctx, stage string) string {
	col := stageColor(c, stage)
	return `<span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded px-1.5 py-0.5 text-xs font-medium text-graphite-900" style="background:` + app.E(col) + `22"><span class="size-2 shrink-0 rounded-full" style="background:` + app.E(col) + `"></span>` + app.E(stageLabel(c, stage)) + `</span>`
}

func h(s string) template.HTML { return template.HTML(s) }

// meter renders crm/meter.
func meter(c *app.Ctx, value float64, label string) string {
	return string(c.Partial("crm/meter", app.M{"value": value, "label": label}))
}
