package management

import (
	"fmt"
	"html/template"
	"sort"
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/oil"
	"acme/internal/php"
)

// Management → Oil prices (OilPriceController): today's PTT prices, a chart of every price change, the change history
// and the sync log. Management and administrators can sync now, load past days and change the morning sync times.

var rangeKeys = []string{"3m", "6m", "1y", "all"}
var rangeLabels = map[string]string{"3m": "3 months", "6m": "6 months", "1y": "1 year", "all": "All"}

func oilName(c *app.Ctx, nameEn, nameTh any, code string) string {
	if c.Locale() == "th" && php.Str(nameTh) != "" {
		return php.Str(nameTh)
	}
	if php.Str(nameEn) != "" {
		return php.Str(nameEn)
	}
	return code
}

func oilIndex(c *app.Ctx) {
	c.Authorize("management", "view")
	rng := c.Query("range", "6m")
	if _, ok := rangeLabels[rng]; !ok {
		rng = "6m"
	}
	fuel := c.Query("fuel")
	now := time.Now().In(php.Loc)
	ago := func(m, y int) string { return now.AddDate(-y, -m, 0).Format("2006-01-02") }
	from := ago(6, 0)
	switch rng {
	case "3m":
		from = ago(3, 0)
	case "1y":
		from = ago(0, 1)
	case "all":
		from = "2000-01-01"
	}
	products := map[string]db.Row{}
	var codes []string
	for _, p := range db.Select("SELECT code, name_en, name_th, active FROM oil_products ORDER BY active DESC, sort, name_en") {
		products[p.Str("code")] = p
		codes = append(codes, p.Str("code"))
	}
	// every price in the range, plus the price each fuel had when the range starts (so its line starts at the left edge)
	rows := db.Select(`SELECT product_code, price, effective_at FROM oil_prices WHERE effective_at >= ?::date
                UNION SELECT DISTINCT ON (product_code) product_code, price, ?::date FROM oil_prices WHERE effective_at < ?::date ORDER BY 3, 1`, from, from, from)
	dset := map[string]bool{}
	for _, r := range rows {
		dset[php.Substr(r.Str("effective_at"), 0, 10)] = true
	}
	dates := []string{}
	for d := range dset {
		dates = append(dates, d)
	}
	sort.Strings(dates)
	series := []app.M{}
	for _, code := range codes {
		p := products[code]
		byDay := map[string]float64{}
		has := false
		for _, r := range rows {
			if r.Str("product_code") == code {
				byDay[php.Substr(r.Str("effective_at"), 0, 10)] = r.Float("price")
				has = true
			}
		}
		if !has {
			continue
		}
		vals := []any{}
		var last any
		for _, d := range dates { // a price holds until it changes
			if v, ok := byDay[d]; ok {
				last = v
			}
			vals = append(vals, last)
		}
		series = append(series, app.M{"code": code, "label": oilName(c, p["name_en"], p["name_th"], code), "color": oil.Color(code), "data": vals, "active": p.Bool("active")})
	}
	today := now.Format("2006-01-02")
	if len(dates) > 0 && today > dates[len(dates)-1] { // carry the lines on to today
		dates = append(dates, today)
		for _, s := range series {
			d := s["data"].([]any)
			s["data"] = append(d, d[len(d)-1])
		}
	}
	hw, hp := "TRUE", []any{}
	if _, ok := products[fuel]; fuel != "" && ok {
		hw, hp = "o.product_code = ?", []any{fuel}
	}
	type histRow struct {
		Date, Color, Name, Before, Price, Change, Tone string
		First                                          bool
	}
	var hist []histRow
	for _, h := range db.Select(`SELECT o.product_code, o.price, o.effective_at, lag(o.price) OVER (PARTITION BY o.product_code ORDER BY o.effective_at) AS before
            FROM oil_prices o WHERE `+hw+` ORDER BY o.effective_at DESC, o.product_code LIMIT 300`, hp...) {
		p := products[h.Str("product_code")]
		hr := histRow{Date: app.FormatDate(c, h["effective_at"], "d M Y"), Color: oil.Color(h.Str("product_code")), Price: php.NF(h.Float("price"), 2), Before: "—", Tone: "text-steel"}
		if p != nil {
			hr.Name = oilName(c, p["name_en"], p["name_th"], h.Str("product_code"))
		} else {
			hr.Name = h.Str("product_code")
		}
		if !h.Nil("before") {
			hr.Before = php.NF(h.Float("before"), 2)
			d := float64(int((h.Float("price")-h.Float("before"))*100+sign(h.Float("price")-h.Float("before"))*0.5)) / 100
			if d > 0 {
				hr.Change, hr.Tone = "▲ +"+php.NF(d, 2), "text-signal-700"
			} else {
				hr.Change, hr.Tone = "▼ "+php.NF(d, 2), "text-emerald-700"
			}
		} else {
			hr.First = true
		}
		hist = append(hist, hr)
	}
	src := map[string]string{"schedule": c.T("Scheduled"), "manual": c.T("Sync now"), "history": c.T("History")}
	var syncs []app.M
	for _, s := range db.Select("SELECT * FROM oil_price_syncs WHERE source <> 'history' ORDER BY run_at DESC, id DESC LIMIT 15") { // history loads are in the activity log
		ok := s.Str("status") == "ok"
		line := s.Str("message")
		if ok {
			line = c.T(":c change(s) of :p fuels", "c", s["changed"], "p", s["products"])
			if s.Str("source") == "history" {
				line += " · " + app.FormatDate(c, s["for_date"], "d M Y")
			}
		}
		who := src[s.Str("source")]
		if who == "" {
			who = s.Str("source")
		}
		head := app.FormatDate(c, s["run_at"], "d M Y H:i") + " · " + who
		if s.Str("user_name") != "" {
			head += " · " + s.Str("user_name")
		}
		syncs = append(syncs, app.M{"ok": ok, "head": head, "line": line, "msg": s.Str("message")})
	}
	var ranges []app.M
	for _, k := range rangeKeys {
		var fl any
		if fuel != "" {
			fl = fuel
		}
		ranges = append(ranges, app.M{"url": c.URL("/management/oil-prices", "range", k, "fuel", fl), "label": c.T(rangeLabels[k]), "on": rng == k})
	}
	var fuels []app.Opt
	for _, code := range codes {
		p := products[code]
		fuels = append(fuels, app.Opt{Value: code, Label: oilName(c, p["name_en"], p["name_th"], code)})
	}
	last := oil.LastSync()
	lastLine := c.T("Never synced yet")
	if last != nil {
		lastLine = c.T("Last synced :t", "t", app.FormatDate(c, last["run_at"], "d M Y H:i"))
	}
	times := oil.Times()
	due := oil.Due()
	dueLine := c.T("Runs from the server's cron (bin/oil-prices.php), or by the website itself while someone is signed in.")
	if due != "" {
		dueLine = c.T("The :t sync is due and runs within a minute.", "t", due)
	}
	timeTpl := `<div class="w-40"><input type="time" name="times[]" value="08:00" class="input" required aria-label="` + app.E(c.T("Sync time")) + `"></div><button type="button" class="btn-ghost px-2 text-steel" data-remove aria-label="` + app.E(c.T("Remove")) + `">` + string(app.Icon("x", "size-4")) + `</button>`
	days := []app.Opt{{Value: "30", Label: c.T("Last 30 days")}, {Value: "90", Label: c.T("Last 90 days")}, {Value: "180", Label: c.T("Last 6 months")}, {Value: "365", Label: c.T("Last year")}}
	loc := "en-GB"
	if c.Locale() == "th" {
		loc = "th-TH"
	}
	c.View("management/oil-prices", app.M{"title": c.T("Oil prices"), "oil": oil.Current(), "dates": dates, "ndates": len(dates), "series": series, "range": rng, "ranges": ranges,
		"fuel": fuel, "fuels": fuels, "history": hist, "syncs": syncs, "times": times, "lastLine": lastLine, "dueLine": dueLine, "days": days,
		"timeTpl": template.JS(jsonStr(timeTpl)), "loc": template.JS(jsonStr(loc))})
}

func sign(f float64) float64 {
	if f < 0 {
		return -1
	}
	return 1
}

func jsonStr(s string) string {
	b := jsonJS(s)
	return string(b)
}

func oilSync(c *app.Ctx) {
	c.Authorize("management", "view")
	u := c.MustUser()
	r, err := oil.Sync("manual", nil, u.ID, u.Name)
	if err != nil {
		c.Back("error", c.T("The prices could not be fetched from PTT: :m", "m", err.Error()))
	}
	if r.Changed > 0 {
		c.Flash("success", c.T(":n price change(s) saved.", "n", r.Changed))
	} else {
		c.Flash("success", c.T("Prices checked: nothing changed since the last update."))
	}
	c.Redirect("/management/oil-prices")
}

// oilHistory loads past days from PTT (one request per day), to fill the history and the chart.
func oilHistory(c *app.Ctx) {
	c.Authorize("management", "view")
	days := php.Int(c.Input("days", "30"))
	if !php.InList(php.Str(days), "30", "90", "180", "365") {
		app.Invalid("days", c.T("Choose how far back to load."))
	}
	u := c.MustUser()
	changed, failed := 0, 0
	// every day up to 90 days back; further back every second day (prices rarely hold for less than two days)
	step := 2
	if days <= 90 {
		step = 1
	}
	today := time.Now().In(oil.Loc)
	today = time.Date(today.Year(), today.Month(), today.Day(), 0, 0, 0, 0, oil.Loc)
	for i := days; i >= 0; i-- {
		if i > 14 && i%step != 0 {
			continue
		}
		d := today.AddDate(0, 0, -i)
		if r, err := oil.Sync("history", &d, u.ID, u.Name); err != nil {
			failed++
		} else {
			changed += r.Changed
		}
	}
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "oil_prices", Label: "Oil prices", Text: "Loaded :n days of oil price history", Params: []any{"n", days},
		Props: app.M{"changes": changed, "failed": failed}, NoNotify: true, Module: "management"})
	kind := "success"
	msg := c.T("History loaded: :c price change(s) found.", "c", changed)
	if failed > 0 {
		kind = "error"
		msg += " " + c.T(":n day(s) could not be fetched.", "n", failed)
	}
	c.Flash(kind, msg)
	c.Redirect("/management/oil-prices")
}

func oilTimes(c *app.Ctx) {
	c.Authorize("management", "view")
	var ok []string
	seen := map[string]bool{}
	for _, t := range c.Inputs("times") {
		t = strings.TrimSpace(t)
		if t == "" {
			continue
		}
		parts := strings.Split(t, ":")
		if len(parts) != 2 || !timeOK(parts[0], parts[1]) {
			app.Invalid("times", c.T("Use times like 05:10."))
		}
		s := fmt.Sprintf("%02d:%s", php.Int(parts[0]), parts[1])
		if !seen[s] {
			seen[s] = true
			ok = append(ok, s)
		}
	}
	sort.Strings(ok)
	if len(ok) == 0 || len(ok) > 8 {
		app.Invalid("times", c.T("Set between one and eight sync times."))
	}
	before := strings.Join(oil.Times(), ", ")
	app.PutSetting("oil_sync_times", strings.Join(ok, ","))
	after := strings.Join(ok, ", ")
	ch := app.M{"times": app.M{"from": before, "to": after}}
	app.LogActivity(c, app.ActivityEntry{Action: "updated", Type: "oil_prices", Label: "Oil prices", Text: "Changed the oil price sync times to :t", Params: []any{"t", after},
		Props: app.M{"changes": ch}, NoNotify: true, Module: "management"})
	c.Flash("success", c.T("Sync times saved: :t (Bangkok time).", "t", after))
	c.Redirect("/management/oil-prices")
}

func timeOK(h, m string) bool {
	hh, mm := php.Int(h), php.Int(m)
	if len(h) < 1 || len(h) > 2 || len(m) != 2 {
		return false
	}
	for _, r := range h + m {
		if r < '0' || r > '9' {
			return false
		}
	}
	return hh >= 0 && hh <= 23 && mm >= 0 && mm <= 59
}
