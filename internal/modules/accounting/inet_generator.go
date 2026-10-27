package accounting

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"

	"acme/internal/app"
	"acme/internal/php"
)

/*
Generate (Inet\Generator) = build the INET text file (JSON) of each document of an invoice, keep it in the database and,
when "Auto PDF" was chosen, make the PDF too. An invoice gives 388 + T01; a credit note gives 81.
Nothing is sent to INET here — that is the Send button.
*/

// docCol: the column suffix of a document type.
func docCol(typ string) string {
	switch strings.ToUpper(typ) {
	case "388":
		return "388"
	case "T01":
		return "t01"
	case "81":
		return "81"
	}
	panic(errors.New("Unknown document type"))
}

type genItem struct {
	Invoice int  `json:"invoice"`
	AutoPDF bool `json:"auto_pdf"`
}

type genResult struct {
	docs   []string
	errors [][2]string // type, message ("-" = the whole invoice)
}

type genPrep struct {
	built  builtDoc
	doc    app.M
	credit *creditInfo
}

// generateMany: reading and building the JSON is quick and done one by one; the slow part, printing PDFs, runs parallel
// Chromium processes at the same time. A problem with one invoice never stops the others.
func generateMany(c *app.Ctx, items []genItem, by int, parallel int, progress func(stage string, n int)) (map[int]*genResult, []int) {
	company := companyRequire(c)
	res := map[int]*genResult{}
	var order []int
	prep := map[int]map[string]genPrep{}
	var prepOrder []int
	prepTypes := map[int][]string{}
	pages := map[string]string{}
	nBuilt, nSaved := 0, 0
	for _, it := range items {
		no := it.Invoice
		auto := it.AutoPDF
		if _, ok := res[no]; !ok {
			order = append(order, no)
		}
		res[no] = &genResult{}
		err := app.Catch(func() {
			logInfo("inet", php.Str(no)+": generate start", om("auto_pdf", auto, "by", by))
			doc, lines := docLoad(c, no)
			inetSave(no, auto, by) // header row (customer, seller, amounts …)
			acc().Exec("UPDATE inets SET payment_remark = ? WHERE no_invoice = ? AND is_credit = ?", doc["payment_remark"], no, bstr(php.Bool(doc["is_credit"])))
			var credit *creditInfo
			types := []string{"388", "T01"}
			if php.Bool(doc["is_credit"]) {
				credit = creditInfoOf(doc)
				types = []string{"81"}
			}
			kind := "invoice"
			if php.Bool(doc["is_credit"]) {
				kind = "credit note"
			}
			tl := []any{}
			for _, t := range types {
				tl = append(tl, t)
			}
			var ref any
			if credit != nil && credit.Reference != "" {
				ref = credit.Reference
			}
			logInfo("inet", php.Str(no)+": "+kind+" read from the ERP copy", om("lines", len(lines), "documents", tl, "reference", ref))
			for _, typ := range types {
				if e := app.Catch(func() {
					built := jsonBuild(c, doc, lines, typ, company, credit)
					if prep[no] == nil {
						prep[no] = map[string]genPrep{}
						prepOrder = append(prepOrder, no)
					}
					prep[no][typ] = genPrep{built: built, doc: doc, credit: credit}
					prepTypes[no] = append(prepTypes[no], typ)
					if auto {
						pages[php.Str(no)+"|"+typ] = documentHTML(doc, lines, company, typ, true, built.Totals, credit)
					}
				}); e != nil {
					generateFail(no, typ, doc, e, by, res)
				}
			}
			if credit != nil && credit.Reference != "" {
				acc().Exec("UPDATE inets SET reference_invoice = ? WHERE no_invoice = ? AND is_credit = TRUE", credit.Reference, no)
			}
		})
		if err != nil {
			logException("inet", err, php.Str(no)+" generate failed")
			res[no].errors = append(res[no].errors, [2]string{"-", err.Error()})
		}
		nBuilt++
		if progress != nil {
			progress("built", nBuilt)
		}
	}

	pdfs := map[string]pdfResult{} // 2. print the PDFs, several at a time
	if len(pages) > 0 {
		logInfo("inet", "Printing "+php.Str(len(pages))+" PDF(s), up to "+php.Str(parallel)+" at a time")
		var each func(int)
		if progress != nil {
			each = func(n int) { progress("printed", n) }
		}
		if err := app.Catch(func() { pdfs = renderMany(c, pages, parallel, each) }); err != nil {
			for k := range pages {
				pdfs[k] = pdfResult{err: err}
			}
		}
	}

	for _, no := range prepOrder { // 3. save
		nSaved++
		if progress != nil {
			progress("saved", nSaved)
		}
		for _, typ := range prepTypes[no] {
			pp := prep[no][typ]
			doc := pp.doc
			r, has := pdfs[php.Str(no)+"|"+typ]
			var pdf []byte
			var pdfErr error
			if has {
				if r.err != nil {
					pdfErr = r.err // keep the JSON; only the PDF is missing
				} else {
					pdf = r.pdf
				}
			}
			err := app.Catch(func() {
				col := docCol(typ)
				path := ""
				if pdf != nil {
					if e := app.Catch(func() { path = generateStore(no, typ, pdf, "doc") }); e != nil {
						pdfErr, pdf = e, nil // keep the JSON; say why the PDF is missing
					}
				}
				sets := []string{"text_" + col + " = ?", "is_" + col + "_generate = TRUE", "message_" + col + " = NULL", "modify_" + col + "_by = ?", "auto_pdf = ?", "updated_at = now()",
					"is_" + col + "_send = FALSE", "is_" + col + "_fetch = FALSE", "is_" + col + "_download = FALSE"} // a new text invalidates what was sent before
				par := []any{pp.built.JSON, by, bstr(pdf != nil)}
				if path != "" {
					sets = append(sets, "pdf_"+col+" = ?")
					par = append(par, path)
				}
				cr := bstr(php.Bool(doc["is_credit"]))
				acc().Exec("UPDATE inets SET "+strings.Join(sets, ", ")+" WHERE no_invoice = ? AND is_credit = ?", append(par, no, cr)...)
				t := pp.built.Totals // amounts as written in the file (THB)
				acc().Exec("UPDATE inets SET amount = ?, tax_amount = ?, total_amount = ?, currency_code = ? WHERE no_invoice = ? AND is_credit = ?", t.Basis, t.Tax, t.Grand, "THB", no, cr)
				res[no].docs = append(res[no].docs, typ)
				if pdfErr != nil {
					generateFail(no, typ, doc, errors.New("JSON saved, PDF failed: "+pdfErr.Error()), by, res)
					return
				}
				var pv any
				if path != "" {
					pv = path
				}
				suffix := ""
				if path != "" {
					suffix = " + PDF"
				}
				logInfo("inet", php.Str(no)+" "+typ+": JSON saved"+suffix, om("total", t.Grand, "tax", t.Tax, "pdf", pv))
			})
			if err != nil {
				generateFail(no, typ, doc, err, by, res)
			}
		}
	}
	return res, order
}

func generateFail(no int, typ string, doc app.M, err error, by int, res map[int]*genResult) {
	res[no].errors = append(res[no].errors, [2]string{typ, err.Error()})
	logException("inet", err, php.Str(no)+" "+typ+" not generated")
	_ = app.Catch(func() { // the row may not exist yet
		col := docCol(typ)
		acc().Exec("UPDATE inets SET message_"+col+" = ?, modify_"+col+"_by = ?, updated_at = now() WHERE no_invoice = ? AND is_credit = ?",
			php.Substr(err.Error(), 0, 1000), by, no, bstr(php.Bool(doc["is_credit"])))
	})
}

// generateStore keeps a PDF under storage/inet/<invoice>/ and returns the key saved in the database.
func generateStore(invoiceNumber int, typ string, b []byte, kind string) string {
	suffix := ""
	if kind == "signed" {
		suffix = "_signed"
	}
	key := "inet/" + php.Str(invoiceNumber) + "/" + php.Str(invoiceNumber) + "_" + typ + suffix + ".pdf"
	abs := filepath.Join(app.BasePath, "storage", key)
	// a PDF that cannot be written must not be recorded as there (the Send window would show a 404)
	if err := os.MkdirAll(filepath.Dir(abs), 0o775); err != nil || os.WriteFile(abs, b, 0o664) != nil {
		panic(errors.New("The PDF could not be saved in storage/" + filepath.Dir(key) + ": the web server cannot write there (check the folder's owner and permissions)."))
	}
	return key
}

func storedKeyOK(key string) bool {
	return key != "" && !strings.Contains(key, "..") && strings.HasPrefix(key, "inet/")
}

// generateExists: is the stored PDF really there?
func generateExists(key string) bool {
	if !storedKeyOK(key) {
		return false
	}
	st, err := os.Stat(filepath.Join(app.BasePath, "storage", key))
	return err == nil && st.Mode().IsRegular()
}

func generateRead(key string) []byte {
	if !storedKeyOK(key) {
		return nil
	}
	b, err := os.ReadFile(filepath.Join(app.BasePath, "storage", key))
	if err != nil {
		return nil
	}
	return b
}

// ---------------------------------------------------------------- jobs (Inet\Jobs)

/*
Generate runs in its own process (acme erp-sync generate <job>), never inside the web request: reading the data, building the
JSON and printing PDFs with Chromium can take a while. The page only asks "how far is it?" (a tiny JSON file) every second.
A job is one small file in storage/cache/jobs/.
*/

func jobDir() string {
	d := filepath.Join(app.BasePath, "storage", "cache", "jobs")
	_ = os.MkdirAll(d, 0o775)
	return d
}

func jobFile(id string) string { return filepath.Join(jobDir(), id+".json") }

var jobIDRe = regexp.MustCompile(`^j[a-f0-9]{14}$`)

type job struct {
	ID      string    `json:"id"`
	Status  string    `json:"status"`
	By      int       `json:"by"`
	Items   []genItem `json:"items"`
	Total   int       `json:"total"`
	Built   int       `json:"built"`
	Printed int       `json:"printed,omitempty"`
	Saved   int       `json:"saved"`
	Made    int       `json:"made"`
	Errors  []string  `json:"errors"`
	Message string    `json:"message"`
	Created int64     `json:"created"`
	Updated int64     `json:"updated"`
}

func jobCreate(items []genItem, by int) string {
	files, _ := filepath.Glob(filepath.Join(jobDir(), "*.json"))
	for _, f := range files {
		if st, err := os.Stat(f); err == nil && st.ModTime().Before(time.Now().Add(-24*time.Hour)) {
			_ = os.Remove(f)
		}
	}
	id := "j" + randID(7)
	now := time.Now().Unix()
	jobWrite(&job{ID: id, Status: "queued", By: by, Items: items, Total: len(items), Errors: []string{}, Created: now, Updated: now})
	return id
}

func jobRead(id string) *job {
	if !jobIDRe.MatchString(id) {
		return nil
	}
	raw, err := os.ReadFile(jobFile(id))
	if err != nil {
		return nil
	}
	j := &job{}
	if json.Unmarshal(raw, j) != nil {
		return nil
	}
	if (j.Status == "queued" || j.Status == "running") && time.Now().Unix()-j.Updated > 150 { // the process died
		j.Status = "failed"
		j.Message = "The background process stopped. See Accounting → Logs."
	}
	if j.Errors == nil {
		j.Errors = []string{}
	}
	return j
}

func jobWrite(j *job) {
	j.Updated = time.Now().Unix()
	b, _ := json.Marshal(j)
	tmp := jobFile(j.ID) + ".tmp"
	_ = os.WriteFile(tmp, b, 0o664)
	_ = os.Rename(tmp, jobFile(j.ID))
}

// jobStart starts it in the background; false = this server cannot start processes (the caller then runs it inline).
func jobStart(id string) bool { return spawn([]string{"generate", id}) }

// jobRun does the work (acme erp-sync generate, or inline).
func jobRun(c *app.Ctx, id string) {
	j := jobRead(id)
	if j == nil || j.Status != "queued" {
		return
	}
	j.Status = "running"
	jobWrite(j)
	logSetRun(id)
	logInfo("inet", "Generate: "+php.Str(j.Total)+" invoice(s)", om("job", id))
	err := app.Catch(func() {
		var mu sync.Mutex
		res, order := generateMany(c, j.Items, j.By, 5, func(stage string, n int) {
			mu.Lock()
			defer mu.Unlock()
			switch stage {
			case "built":
				j.Built = n
			case "printed":
				j.Printed = n
			case "saved":
				j.Saved = n
			}
			jobWrite(j)
		})
		made := 0
		var errs []string
		for _, no := range order {
			r := res[no]
			if len(r.docs) > 0 {
				made++
			}
			for _, e := range r.errors {
				label := php.Str(no)
				if e[0] != "-" {
					label += " (" + e[0] + ")"
				}
				errs = append(errs, label+": "+e[1])
			}
		}
		if len(errs) > 20 {
			errs = errs[:20]
		}
		if errs == nil {
			errs = []string{}
		}
		j.Made, j.Errors, j.Status = made, errs, "done"
	})
	if err != nil {
		logException("inet", err, "Generate failed")
		j.Status, j.Message = "failed", err.Error()
	}
	logInfo("inet", "Generate finished: "+php.Str(j.Made)+" made, "+php.Str(len(j.Errors))+" with problems", om("job", id))
	jobWrite(j)
}
