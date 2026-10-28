// Package oil is the fuel price feed (App\Core\Support\OilPrices): synced from PTT in the morning,
// shown on My workspace and Management → Oil prices.
//
// Daily oil prices from PTT OR (GetOilPrice SOAP service).
//   - Sync(): asks PTT for one day's prices (English and Thai names) and stores a price only when it differs from the
//     last stored price of that fuel. Every run is written to oil_price_syncs.
//   - Scheduled every morning at the times in setting 'oil_sync_times' (Bangkok time, default 05:10, 06:10, 07:00):
//     `acme oil-prices` from cron, and as a fallback the once-a-minute tick of signed-in pages.
//
// Shown on My workspace (current prices) and in Management → Oil prices (history, chart, sync).
package oil

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"html"
	"io"
	"math"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"time"

	"acme/internal/app"
	"acme/internal/conv"
	"acme/internal/db"
)

const (
	serviceURL = "https://orapiweb.pttor.com/oilservice/OilPrice.asmx"
	// TZ is the time zone of the PTT prices and the sync times.
	TZ = "Asia/Bangkok"
	// DefaultTimes are the morning sync times when the setting is not set.
	DefaultTimes = "05:10,06:10,07:00"
)

// Loc is Asia/Bangkok (UTC+7 when the zone database is missing).
var Loc = func() *time.Location {
	if l, err := time.LoadLocation(TZ); err == nil {
		return l
	}
	return time.FixedZone(TZ, 7*3600)
}()

// Color is OilPrices::color(): brand-like colours for the tiles and chart lines, matched on the English name.
func Color(code string) string {
	has := func(s string) bool { return strings.Contains(code, s) }
	switch {
	case has("premium") || has("super_power_diesel"):
		return "#475569"
	case has("x99"):
		return "#0f766e"
	case has("gsh95"):
		return "#7c3aed"
	case has("b20"):
		return "#dc2626"
	case has("b7"):
		return "#1d4ed8"
	case has("diesel"):
		return "#1e3a8a"
	case has("e85"):
		return "#c026d3"
	case has("e20"):
		return "#65a30d"
	case has("gasohol_91"):
		return "#16a34a"
	case has("gasohol_95"):
		return "#ea580c"
	case has("gasoline"):
		return "#f59e0b"
	case has("ngv"):
		return "#0284c7"
	}
	return "#6b7280"
}

var nonCode = regexp.MustCompile(`[^a-z0-9]+`)

// Code is OilPrices::code(): the product key from its English name.
func Code(nameEn string) string {
	c := strings.Trim(nonCode.ReplaceAllString(strings.ToLower(nameEn), "_"), "_")
	if c == "" {
		return "fuel"
	}
	return c
}

/* ----------------------------------------------------------- the service */

// Fuel is one price PTT gave: date = Y-m-d H:i:s Bangkok.
type Fuel struct {
	Name  string
	Price float64
	Date  string
}

var (
	resultRe = regexp.MustCompile(`(?s)<GetOilPriceResult>(.*)</GetOilPriceResult>`)
	fuelRe   = regexp.MustCompile(`(?s)<FUEL>\s*<PRICE_DATE>(.*?)</PRICE_DATE>\s*<PRODUCT>(.*?)</PRODUCT>\s*<PRICE>(.*?)</PRICE>\s*</FUEL>`)
)

var client = &http.Client{
	Timeout: 25 * time.Second,
	Transport: &http.Transport{
		Proxy:               http.ProxyFromEnvironment,
		DialContext:         (&net.Dialer{Timeout: 10 * time.Second}).DialContext,
		TLSHandshakeTimeout: 10 * time.Second,
	},
}

// Fetch is one day's prices from PTT; an error when PTT cannot be reached or answers something unexpected.
func Fetch(day time.Time, lang string) ([]Fuel, error) {
	if lang != "th" {
		lang = "en"
	}
	day = day.In(Loc)
	body := `<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">` +
		`<soap:Body><GetOilPrice xmlns="http://www.pttor.com"><Language>` + lang + `</Language>` +
		`<DD>` + strconv.Itoa(day.Day()) + `</DD><MM>` + strconv.Itoa(int(day.Month())) + `</MM><YYYY>` + strconv.Itoa(day.Year()) + `</YYYY></GetOilPrice></soap:Body></soap:Envelope>`
	req, err := http.NewRequest(http.MethodPost, serviceURL, bytes.NewBufferString(body))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "text/xml; charset=utf-8")
	req.Header.Set("SOAPAction", `"http://www.pttor.com/GetOilPrice"`)
	res, err := client.Do(req)
	if err != nil {
		return nil, errors.New("PTT oil price service did not answer: " + err.Error())
	}
	defer res.Body.Close()
	raw, rerr := io.ReadAll(res.Body)
	if rerr != nil {
		return nil, errors.New("PTT oil price service did not answer: " + rerr.Error())
	}
	if res.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("PTT oil price service did not answer (HTTP %d)", res.StatusCode)
	}
	m := resultRe.FindSubmatch(raw)
	if m == nil {
		return nil, errors.New("Unexpected answer from the PTT oil price service.")
	}
	inner := html.UnescapeString(string(m[1]))
	var out []Fuel
	for _, f := range fuelRe.FindAllStringSubmatch(inner, -1) {
		when, werr := time.ParseInLocation("1/2/2006 3:04:05 PM", strings.TrimSpace(f[1]), Loc)
		price := strings.TrimSpace(f[3])
		p, perr := strconv.ParseFloat(price, 64)
		if werr == nil && perr == nil && app.IsNumeric(price) && p > 0 && strings.TrimSpace(f[2]) != "" {
			out = append(out, Fuel{Name: strings.TrimSpace(f[2]), Price: math.Round(p*100) / 100, Date: when.Format("2006-01-02 15:04:05")})
		}
	}
	return out, nil
}

// Result is what one sync did.
type Result struct {
	Products  int
	Changed   int
	PriceDate string // "" = none
}

// M is the result like the PHP array: {products, changed, price_date}.
func (r Result) M() app.M {
	var pd any
	if r.PriceDate != "" {
		pd = r.PriceDate
	}
	return app.M{"products": r.Products, "changed": r.Changed, "price_date": pd}
}

// Sync fetches one day (nil = today in Bangkok) and keeps the changed prices. trigger: manual | schedule | history.
func Sync(trigger string, day *time.Time, userID int, userName string) (res Result, err error) {
	d := time.Now().In(Loc)
	if day != nil {
		d = day.In(Loc)
	}
	start := time.Now()
	defer func() {
		if r := recover(); r != nil {
			if e, ok := r.(error); ok {
				err = e
			} else {
				err = fmt.Errorf("%v", r)
			}
		}
		if err != nil {
			logSync(trigger, d, userID, userName, "failed", 0, 0, "", err.Error(), start)
			res = Result{}
		}
	}()
	en, ferr := Fetch(d, "en")
	if ferr != nil {
		return res, ferr
	}
	if len(en) == 0 {
		return res, errors.New("PTT returned no prices for " + d.Format("2006-01-02") + ".")
	}
	var th []Fuel // Thai names are a nice-to-have; loading history skips them (the next sync adds them)
	if trigger != "history" {
		if t, terr := Fetch(d, "th"); terr == nil {
			th = t
		}
	}
	changed := 0
	latest := ""
	notHistory := trigger != "history"
	for i, f := range en {
		code := Code(f.Name)
		var nameTh any
		if i < len(th) && math.Abs(th[i].Price-f.Price) < 0.001 { // same list, same order
			nameTh = th[i].Name
		}
		db.Exec(`INSERT INTO oil_products (code, name_en, name_th, sort, active, last_seen) VALUES (?, ?, ?, ?, TRUE, now())
                  ON CONFLICT (code) DO UPDATE SET name_en = EXCLUDED.name_en, name_th = COALESCE(EXCLUDED.name_th, oil_products.name_th), last_seen = now(),
                      sort = CASE WHEN ? THEN EXCLUDED.sort ELSE oil_products.sort END, active = CASE WHEN ? THEN TRUE ELSE oil_products.active END`,
			code, f.Name, nameTh, i, notHistory, notHistory)
		// the last price this fuel had on or before this date: store only when it differs
		prev := db.First("SELECT price, effective_at FROM oil_prices WHERE product_code = ? AND effective_at <= ? ORDER BY effective_at DESC LIMIT 1", code, f.Date)
		if latest == "" || f.Date > latest {
			latest = f.Date
		}
		if prev != nil && math.Abs(prev.Float("price")-f.Price) < 0.001 {
			continue
		}
		if db.Exec("INSERT INTO oil_prices (product_code, price, effective_at) VALUES (?, ?, ?) ON CONFLICT (product_code, effective_at) DO NOTHING", code, f.Price, f.Date) > 0 {
			changed++
		}
	}
	if changed > 0 { // loading older days can leave the same price twice in a row: keep only real changes
		db.Exec(`DELETE FROM oil_prices o USING (SELECT id, price, lag(price) OVER (PARTITION BY product_code ORDER BY effective_at) AS prev FROM oil_prices) x
                  WHERE o.id = x.id AND x.prev = x.price`)
	}
	if notHistory { // fuels PTT no longer lists drop off the dashboard (their history stays)
		codes := make([]any, len(en))
		for i, f := range en {
			codes[i] = Code(f.Name)
		}
		db.Exec("UPDATE oil_products SET active = FALSE WHERE code NOT IN ("+strings.TrimSuffix(strings.Repeat("?,", len(codes)), ",")+")", codes...)
	}
	msg := ""
	if changed == 0 {
		msg = "No price changed."
	}
	logSync(trigger, d, userID, userName, "ok", len(en), changed, latest, msg, start)
	return Result{Products: len(en), Changed: changed, PriceDate: latest}, nil
}

func logSync(trigger string, day time.Time, userID int, userName, status string, products, changed int, priceDate, msg string, start time.Time) {
	nz := func(s string) any {
		if s == "" {
			return nil
		}
		return s
	}
	var uid any
	if userID > 0 {
		uid = userID
	}
	var m any
	if msg != "" {
		m = conv.Substr(msg, 0, 500)
	}
	_ = db.Try(func() {
		db.Insert("oil_price_syncs", db.Cols{{Name: "source", Value: trigger}, {Name: "for_date", Value: day.Format("2006-01-02")}, {Name: "user_id", Value: uid},
			{Name: "user_name", Value: nz(userName)}, {Name: "status", Value: status}, {Name: "products", Value: products}, {Name: "changed", Value: changed},
			{Name: "price_date", Value: nz(priceDate)}, {Name: "message", Value: m}, {Name: "duration_ms", Value: int(math.Round(float64(time.Since(start).Microseconds()) / 1000))}})
	})
}

/* -------------------------------------------------------------- schedule */

var timeRe = regexp.MustCompile(`^([01]?\d|2[0-3]):([0-5]\d)$`)

// Times is OilPrices::times(): "HH:MM", sorted.
func Times() []string {
	seen := map[string]bool{}
	out := []string{}
	for _, t := range strings.Split(app.Setting("oil_sync_times", DefaultTimes), ",") {
		if m := timeRe.FindStringSubmatch(strings.TrimSpace(t)); m != nil {
			s := fmt.Sprintf("%02d:%02d", conv.Int(m[1]), conv.Int(m[2]))
			if !seen[s] {
				seen[s] = true
				out = append(out, s)
			}
		}
	}
	sort.Strings(out)
	return out
}

// Due is the latest scheduled time today that has passed, if it has not been run yet (a failure is retried after
// 10 minutes); "" when nothing is due.
func Due() string {
	now := time.Now().In(Loc)
	slot := ""
	for _, t := range Times() {
		if now.Format("15:04") >= t {
			slot = t
		}
	}
	if slot == "" {
		return ""
	}
	since := now.Format("2006-01-02") + " " + slot + ":00"
	last := db.First(`SELECT status, run_at FROM oil_price_syncs WHERE source IN ('schedule', 'manual') AND run_at >= (?::timestamp AT TIME ZONE '`+TZ+`')
                       ORDER BY run_at DESC LIMIT 1`, since)
	if last != nil && (last.Str("status") == "ok" || conv.StrToTime(last["run_at"]) > time.Now().Unix()-600) {
		return ""
	}
	return slot
}

// TickRun runs the scheduled sync when one is due. Cheap when nothing is due; one runner at a time.
// nil result = nothing ran.
func TickRun() (*Result, error) {
	if Due() == "" {
		return nil, nil
	}
	dir := filepath.Join(app.BasePath, "storage", "cache")
	_ = os.MkdirAll(dir, 0o775)
	f, err := os.OpenFile(filepath.Join(dir, "oil-prices.lock"), os.O_CREATE|os.O_RDWR, 0o664)
	if err != nil {
		return nil, nil
	}
	defer f.Close()
	if syscall.Flock(int(f.Fd()), syscall.LOCK_EX|syscall.LOCK_NB) != nil {
		return nil, nil
	}
	defer syscall.Flock(int(f.Fd()), syscall.LOCK_UN)
	if Due() == "" { // checked again inside the lock
		return nil, nil
	}
	r, err := Sync("schedule", nil, 0, "")
	if err != nil {
		return nil, err
	}
	return &r, nil
}

// Tick is OilPrices::tick(): the morning sync when cron does not run it (called by the bell feed). Never panics.
func Tick(c *app.Ctx) {
	_ = app.Catch(func() { _, _ = TickRun() })
}

/* ------------------------------------------------------------------ data */

// Current is OilPrices::current(): the current price of every fuel PTT still lists, with the price before it, in
// display order: code, name_en, name_th, price, effective_at, previous, change, color (and newest: changed in the
// newest update, for the red "Latest" tag); empty when nothing was synced yet.
func Current() []app.M {
	rows := db.Select(`SELECT p.code, p.name_en, p.name_th,
                (SELECT row_to_json(x) FROM (SELECT price, effective_at FROM oil_prices o WHERE o.product_code = p.code ORDER BY effective_at DESC LIMIT 1) x) AS cur,
                (SELECT price FROM oil_prices o WHERE o.product_code = p.code ORDER BY effective_at DESC OFFSET 1 LIMIT 1) AS previous
            FROM oil_products p WHERE p.active ORDER BY p.sort, p.name_en`)
	out := []app.M{}
	newest := ""
	for _, r := range rows {
		var cur struct {
			Price       json.Number `json:"price"`
			EffectiveAt string      `json:"effective_at"`
		}
		if r.Nil("cur") || json.Unmarshal([]byte(r.Str("cur")), &cur) != nil {
			continue
		}
		price := conv.Float(cur.Price.String())
		var prev, change any
		if !r.Nil("previous") {
			p := r.Float("previous")
			prev = p
			change = math.Round((price-p)*100) / 100
		}
		// row_to_json writes timestamps as 2026-10-26T05:00:00+07:00; PHP read the same text
		out = append(out, app.M{"code": r.Str("code"), "name_en": r["name_en"], "name_th": r["name_th"], "price": price, "effective_at": cur.EffectiveAt,
			"previous": prev, "change": change, "color": Color(r.Str("code"))})
		if cur.EffectiveAt > newest {
			newest = cur.EffectiveAt
		}
	}
	for _, o := range out {
		o["newest"] = o["effective_at"] == newest
	}
	return out
}

// LastSync is OilPrices::lastSync(): the last good sync log row (or nil).
func LastSync() app.M {
	r := db.First("SELECT * FROM oil_price_syncs WHERE status = 'ok' AND source <> 'history' ORDER BY run_at DESC LIMIT 1")
	if r == nil {
		return nil
	}
	return app.M(r)
}

/* ------------------------------------------------------------- command */

// acme oil-prices: run it every 5 minutes from cron; it syncs only at the times set in Management → Oil prices
// (default 05:10, 06:10 and 07:00 Bangkok time) and does nothing in between:
//
//	0-59/5 * * * * /path/to/acme -base /path/to/app oil-prices
//
// Without cron the website runs the same check once a minute while somebody is signed in.
//
//	acme oil-prices --now                                 sync right away
//	acme oil-prices --from=2026-01-01 [--to=2026-03-31]   load past prices, one request per day
func command(args []string) int {
	opt := map[string]string{}
	for i := 0; i < len(args); i++ {
		a := strings.TrimPrefix(args[i], "--")
		if a == args[i] {
			continue
		}
		k, v, ok := strings.Cut(a, "=")
		if !ok && (k == "from" || k == "to") && i+1 < len(args) {
			v = args[i+1]
			i++
		}
		opt[k] = v
	}
	stamp := func() string { return conv.Date("c", conv.Now()) }
	fail := func(err error) int {
		fmt.Fprintln(os.Stderr, stamp()+" "+err.Error())
		return 1
	}
	if from, ok := opt["from"]; ok {
		d, ok1 := parseDay(from)
		toS := opt["to"]
		if toS == "" {
			toS = "today"
		}
		to, ok2 := parseDay(toS)
		if !ok1 || !ok2 {
			return fail(errors.New("Use dates like 2026-01-01."))
		}
		for ; !d.After(to); d = d.AddDate(0, 0, 1) {
			day := d
			r, err := Sync("history", &day, 0, "")
			if err != nil {
				return fail(err)
			}
			fmt.Printf("%s %d change(s)\n", d.Format("2006-01-02"), r.Changed)
		}
		return 0
	}
	var r *Result
	var err error
	if _, now := opt["now"]; now {
		var x Result
		x, err = Sync("manual", nil, 0, "cron")
		r = &x
	} else {
		r, err = TickRun()
	}
	if err != nil {
		return fail(err)
	}
	if r == nil {
		fmt.Println(stamp() + " nothing due")
	} else {
		fmt.Printf("%s synced %d fuel(s), %d change(s)\n", stamp(), r.Products, r.Changed)
	}
	return 0
}

func parseDay(s string) (time.Time, bool) {
	now := time.Now().In(Loc)
	if strings.ToLower(strings.TrimSpace(s)) == "today" {
		return time.Date(now.Year(), now.Month(), now.Day(), 0, 0, 0, 0, Loc), true
	}
	t, err := time.ParseInLocation("2006-01-02", strings.TrimSpace(s), Loc)
	return t, err == nil
}

func init() { app.Commands["oil-prices"] = command }
