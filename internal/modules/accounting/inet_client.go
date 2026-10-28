package accounting

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"regexp"
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/config"
	"acme/internal/conv"
	"acme/internal/db"
)

// The INET e-Tax portal (Inet\InetClient): send a signed document (text + PDF), ask for its status, look a document up by
// number, and download the signed PDF. Port of the old SendService.

var authSchemeRe = regexp.MustCompile(`(?i)^(Bearer|Basic)\s`)

// inetAuth: a value that already says Bearer / Basic is used as it is, otherwise the scheme from the settings is put in front.
func inetAuth(c *app.Ctx) string {
	v := strings.TrimSpace(inetSetting("authorization"))
	if v == "" {
		panic(errors.New(c.T("The INET authorization key is not set (Accounting → ERP connection).")))
	}
	if authSchemeRe.MatchString(v) {
		return v
	}
	return conv.OrStr(sget("inet.auth_scheme", "Bearer"), "Bearer") + " " + v
}

func inetPost(c *app.Ctx, url string, payload *config.Map) string {
	t0 := time.Now()
	body := jsonEnc(payload, false)
	client := &http.Client{Timeout: 60 * time.Second}
	var st int
	var out []byte
	var err error
	req, err := http.NewRequest(http.MethodPost, url, bytes.NewReader([]byte(body)))
	if err == nil {
		req.Header.Set("Content-Type", "application/json")
		req.Header.Set("Accept", "application/json")
		req.Header.Set("Authorization", inetAuth(c))
		var resp *http.Response
		resp, err = client.Do(req)
		if err == nil {
			out, err = io.ReadAll(resp.Body)
			resp.Body.Close()
			st = resp.StatusCode
		}
	}
	fields := []any{}
	for _, k := range payload.Keys {
		fields = append(fields, k)
	}
	ctx := om("status", st, "ms", int(time.Since(t0).Milliseconds()), "fields", fields, "answer", conv.Substr(strings.TrimSpace(string(out)), 0, 600))
	if err != nil {
		logError("inet", "POST "+url+" failed: "+err.Error(), ctx)
		panic(errors.New("INET: " + err.Error()))
	}
	if st < 200 || st >= 300 {
		logError("inet", "POST "+url+" → HTTP "+conv.Str(st), ctx)
		panic(errors.New("INET answered HTTP " + conv.Str(st) + ": " + conv.Substr(strings.TrimSpace(stripTags(string(out))), 0, 300)))
	}
	logInfo("inet", "POST "+url+" → HTTP "+conv.Str(st), ctx)
	return string(out)
}

func inetJ(body string) map[string]any {
	j := map[string]any{}
	if json.Unmarshal([]byte(body), &j) != nil {
		panic(errors.New("INET answer is not JSON."))
	}
	out := map[string]any{}
	for k, v := range j {
		out[strings.ToLower(k)] = v
	}
	return out
}

var queryRe = regexp.MustCompile(`\?.*`)

func inetDownload(url string) []byte {
	client := &http.Client{Timeout: 30 * time.Second}
	st := 0
	var b []byte
	resp, err := client.Get(url)
	if err == nil {
		b, err = io.ReadAll(resp.Body)
		resp.Body.Close()
		st = resp.StatusCode
	}
	ctx := om("bytes", len(b), "url", queryRe.ReplaceAllString(url, "?…"))
	if err != nil || st >= 400 {
		logError("inet", "GET signed PDF → HTTP "+conv.Str(st), ctx)
	} else {
		logInfo("inet", "GET signed PDF → HTTP "+conv.Str(st), ctx)
	}
	if err != nil || st >= 400 || len(b) == 0 {
		panic(errors.New("The signed PDF could not be downloaded (HTTP " + conv.Str(st) + ")."))
	}
	return b
}

// inetSend sends one document; returns the message for the person.
func inetSend(c *app.Ctx, row db.Row, typ string, pdf []byte) string {
	col := docCol(typ)
	logInfo("inet", row.Str("no_invoice")+" "+typ+": send start", om("upload", pdf != nil))
	text := row.Str("text_" + col)
	if text == "" {
		panic(errors.New(c.T("Generate the document first.")))
	}
	if pdf == nil {
		pdf = generateRead(row.Str("pdf_" + col))
	}
	if pdf == nil {
		panic(errors.New(c.T("There is no PDF yet: generate with Auto PDF, or choose a PDF file when sending.")))
	}
	company := companyRequire(c)
	id := row.Int("id")
	inv := row.Int("no_invoice")
	content, _ := decodeOrdered([]byte(text))
	r := inetJ(inetPost(c, inetSetting("sendApi"), om("SellerTaxId", company["tax_id"], "SellerBranchId", company["branch_id"], "ServiceCode", company.Str("service_code"), "ServiceType", "Recurring",
		"TextContent", content, "PDFContent", base64.StdEncoding.EncodeToString(pdf))))
	status := conv.Str(r["status"])
	tx := conv.Str(r["transactioncode"])
	url := conv.Str(r["pdfurl"])
	if status == "ER" && conv.Str(r["errorcode"]) == "ER011" && url != "" { // already in INET: take its copy
		status = "OK"
	}
	if status == "OK" {
		if url == "" {
			inetMark(id, col, inetMarks{send: boolp(true), tx: tx, msg: strp("OK – pdfURL missing")})
			return c.T("Sent. INET has no PDF yet — press Inet to fetch it.")
		}
		key := generateStore(inv, typ, inetDownload(url), "signed")
		inetMark(id, col, inetMarks{send: boolp(true), fetch: boolp(true), download: boolp(true), tx: tx, msg: strp("OK"), signed: key})
		return c.T("Sent and signed. The signed PDF is saved.")
	}
	if status == "PC" {
		inetMark(id, col, inetMarks{send: boolp(true), tx: tx, msg: strp("PC – " + conv.Str(conv.Coalesce(r["errormessage"], "processing")))})
		return c.T("INET is still processing it. Press Inet in a minute to fetch the signed PDF.")
	}
	msg := "[" + conv.Str(conv.Coalesce(r["errorcode"], "?")) + "] " + conv.Str(conv.Coalesce(r["errormessage"], "Unknown error"))
	logError("inet", conv.Str(inv)+" "+typ+": INET refused it — "+msg)
	inetMark(id, col, inetMarks{msg: &msg})
	panic(errors.New(msg))
}

// inetFetch gets the signed PDF: by transaction code, else by invoice number.
func inetFetch(c *app.Ctx, row db.Row, typ string) string {
	col := docCol(typ)
	logInfo("inet", row.Str("no_invoice")+" "+typ+": fetch start")
	company := companyRequire(c)
	id := row.Int("id")
	inv := row.Int("no_invoice")
	tx := row.Str("transaction_" + col)
	var found *[2]string // pdfUrl, transaction
	if tx != "" {
		for try := 0; try < 4 && found == nil; try++ {
			r := inetJ(inetPost(c, inetSetting("statusApi"), om("sellerTaxId", company["tax_id"], "sellerBranchId", company["branch_id"], "serviceCode", company.Str("service_code"), "transactionCode", tx)))
			st := conv.Str(r["status"])
			if st == "OK" && !conv.Empty(r["pdfurl"]) {
				found = &[2]string{conv.Str(r["pdfurl"]), tx}
			} else if st == "PC" && try < 3 {
				time.Sleep(5 * time.Second)
			} else {
				break
			}
		}
	}
	if found == nil {
		r := inetJ(inetPost(c, inetSetting("paramsApi"), om("SellerTaxId", company["tax_id"], "SellerBranchId", company["branch_id"], "InvoiceNumber", conv.Str(inv), "InvoiceType", strings.ToUpper(typ), "OutputType", "PDF")))
		list, _ := r["responseinvoices"].([]any)
		for _, x := range list {
			d, _ := x.(map[string]any)
			if conv.Str(d["status"]) == "OK" && !conv.Empty(d["pdfURL"]) {
				found = &[2]string{conv.Str(d["pdfURL"]), conv.Str(d["processId"])}
				if conv.Str(d["invoiceId"]) == conv.Str(inv) {
					break
				}
			}
		}
	}
	if found == nil {
		panic(errors.New(c.T("INET has no signed PDF for this document yet.")))
	}
	key := generateStore(inv, typ, inetDownload(found[0]), "signed")
	inetMark(id, col, inetMarks{send: boolp(true), fetch: boolp(true), download: boolp(true), tx: found[1], msg: strp("OK"), signed: key})
	return c.T("The signed PDF was fetched from INET.")
}

type inetMarks struct {
	send, fetch, download *bool
	tx                    string
	msg                   *string
	signed                string
}

// inetMark writes what happened to the inets row.
func inetMark(id int, col string, x inetMarks) {
	var sets []string
	var par []any
	for _, f := range []struct {
		name string
		v    *bool
	}{{"send", x.send}, {"fetch", x.fetch}, {"download", x.download}} {
		if f.v != nil {
			sets = append(sets, "is_"+col+"_"+f.name+" = ?")
			par = append(par, bstr(*f.v))
		}
	}
	if x.tx != "" {
		sets = append(sets, "transaction_"+col+" = ?")
		par = append(par, x.tx)
	}
	if x.msg != nil {
		sets = append(sets, "message_"+col+" = ?")
		par = append(par, conv.Substr(*x.msg, 0, 1000))
	}
	if x.signed != "" {
		sets = append(sets, "inet_pdf_"+col+" = ?")
		par = append(par, x.signed)
	}
	sets = append(sets, "updated_at = now()")
	acc().Exec("UPDATE inets SET "+strings.Join(sets, ", ")+" WHERE id = ?", append(par, id)...)
}
