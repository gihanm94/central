package app

import (
	"bytes"
	"encoding/csv"
	"io"
	"mime/multipart"
	"regexp"
	"strings"
)

var formulaStart = regexp.MustCompile(`^[=+\-@]`)

// CsvDownload streams a CSV (UTF-8 with BOM so Excel opens it right); cells that look like formulas get a quote.
func (c *Ctx) CsvDownload(filename string, header []string, rows [][]any) {
	var buf bytes.Buffer
	buf.WriteString("\xEF\xBB\xBF")
	w := csv.NewWriter(&buf)
	_ = w.Write(header)
	for _, r := range rows {
		line := make([]string, len(r))
		for i, v := range r {
			s := phpStr(v)
			if _, ok := v.(string); ok && formulaStart.MatchString(s) {
				s = "'" + s
			}
			line[i] = s
		}
		_ = w.Write(line)
	}
	w.Flush()
	c.W.Header().Set("Cache-Control", "no-store")
	c.W.Header().Set("Content-Disposition", `attachment; filename="`+filename+`"`)
	c.write("text/csv; charset=UTF-8", buf.Bytes())
}

// CsvRow is one line of an import, keyed by the lower-case header, in header order.
type CsvRow struct {
	Keys []string
	Vals map[string]string
}

func (r CsvRow) Get(k string) (string, bool) { v, ok := r.Vals[k]; return v, ok }

var bomRe = regexp.MustCompile("^\xEF\xBB\xBF")
var csvExt = regexp.MustCompile(`(?i)\.(csv|txt)$`)
var xlsxExt = regexp.MustCompile(`(?i)\.xlsx$`)

// CsvRead reads an uploaded CSV or Excel file into rows keyed by the header (Csv::read).
func CsvRead(fh *multipart.FileHeader, maxRows int) []CsvRow {
	if maxRows <= 0 {
		maxRows = 5000
	}
	f, err := fh.Open()
	if err != nil {
		Invalid("file", "Upload failed.")
	}
	defer f.Close()
	data, _ := io.ReadAll(f)
	if xlsxExt.MatchString(fh.Filename) {
		sheets, err := XlsxRead(data, maxRows+1)
		if err != nil {
			Invalid("file", err.Error())
		}
		var lines [][]string
		if len(sheets) > 0 {
			lines = sheets[0].Rows
		}
		if len(lines) == 0 {
			return nil
		}
		header := make([]string, len(lines[0]))
		for i, h := range lines[0] {
			header[i] = strings.ToLower(strings.TrimSpace(h))
		}
		return combine(header, lines[1:], maxRows)
	}
	if !csvExt.MatchString(fh.Filename) {
		Invalid("file", "Upload a .csv or .xlsx file.")
	}
	r := csv.NewReader(bytes.NewReader(data))
	r.FieldsPerRecord = -1
	r.LazyQuotes = true
	all, _ := r.ReadAll()
	if len(all) == 0 {
		return nil
	}
	header := make([]string, len(all[0]))
	for i, h := range all[0] {
		header[i] = strings.ToLower(strings.TrimSpace(bomRe.ReplaceAllString(h, "")))
	}
	return combine(header, all[1:], maxRows)
}

func combine(header []string, lines [][]string, maxRows int) []CsvRow {
	var out []CsvRow
	for _, line := range lines {
		if len(out) >= maxRows {
			break
		}
		blank := true
		for _, v := range line {
			if strings.TrimSpace(v) != "" {
				blank = false
				break
			}
		}
		if blank {
			continue
		}
		row := CsvRow{Keys: header, Vals: map[string]string{}}
		for i, h := range header {
			v := ""
			if i < len(line) {
				v = line[i]
			}
			row.Vals[h] = v
		}
		out = append(out, row)
	}
	return out
}
