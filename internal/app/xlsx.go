package app

import (
	"archive/zip"
	"bytes"
	"encoding/xml"
	"errors"
	"fmt"
	"io"
	"math"
	"regexp"
	"strconv"
	"strings"
	"time"

	"acme/internal/conv"
)

// Minimal Excel (.xlsx) reader and writer (App\Core\Support\Xlsx): a bold, frozen header row with filter buttons;
// text stays text (codes with leading zeros), plain numbers become numbers, cells are never formulas.

// Sheet is one worksheet to write.
type Sheet struct {
	Name   string
	Header []string
	Rows   [][]any
}

var safeName = regexp.MustCompile(`[^A-Za-z0-9._-]+`)
var csvXlsxExt = regexp.MustCompile(`(?i)\.(csv|xlsx)$`)

// XlsxDownload sends one sheet as an .xlsx download (Xlsx::download).
func (c *Ctx) XlsxDownload(filename string, header []string, rows [][]any, sheet ...string) {
	name := "Data"
	if len(sheet) > 0 {
		name = sheet[0]
	}
	c.XlsxSend(filename, []Sheet{{Name: name, Header: header, Rows: rows}})
}

// XlsxSend sends several sheets as one .xlsx download (Xlsx::send).
func (c *Ctx) XlsxSend(filename string, sheets []Sheet) {
	body := XlsxBuild(sheets)
	base := strings.Trim(safeName.ReplaceAllString(csvXlsxExt.ReplaceAllString(filename, ""), "-"), "-.")
	if base == "" {
		base = "export"
	}
	c.W.Header().Set("Content-Length", strconv.Itoa(len(body)))
	c.Download(base+".xlsx", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", body)
}

// XlsxBuild makes the file.
func XlsxBuild(sheets []Sheet) []byte {
	var buf bytes.Buffer
	z := zip.NewWriter(&buf)
	add := func(name, content string) {
		w, _ := z.Create(name)
		_, _ = io.WriteString(w, content)
	}
	ct := `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>`
	for i := range sheets {
		ct += `<Override PartName="/xl/worksheets/sheet` + strconv.Itoa(i+1) + `.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>`
	}
	add("[Content_Types].xml", ct+"</Types>")
	add("_rels/.rels", `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>`)
	wb := `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>`
	rel := `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">`
	for i, s := range sheets {
		n := strconv.Itoa(i + 1)
		wb += `<sheet name="` + xmlEsc(sheetName(s.Name, i)) + `" sheetId="` + n + `" r:id="rId` + n + `"/>`
		rel += `<Relationship Id="rId` + n + `" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet` + n + `.xml"/>`
	}
	rel += `<Relationship Id="rId` + strconv.Itoa(len(sheets)+1) + `" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>`
	add("xl/workbook.xml", wb+"</sheets></workbook>")
	add("xl/_rels/workbook.xml.rels", rel)
	add("xl/styles.xml", `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>`)
	for i, s := range sheets {
		var b strings.Builder
		cols := ""
		for ci, h := range s.Header {
			w := max(10, min(60, conv.Len(h)+6))
			cols += fmt.Sprintf(`<col min="%d" max="%d" width="%d" customWidth="1"/>`, ci+1, ci+1, w)
		}
		b.WriteString(`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>`)
		if cols != "" {
			b.WriteString("<cols>" + cols + "</cols>")
		}
		b.WriteString("<sheetData>")
		hdr := make([]any, len(s.Header))
		for k, h := range s.Header {
			hdr[k] = h
		}
		b.WriteString(rowXML(1, hdr, 1))
		n := 1
		for _, row := range s.Rows {
			n++
			b.WriteString(rowXML(n, row, 0))
		}
		b.WriteString("</sheetData>")
		if len(s.Header) > 0 {
			b.WriteString(`<autoFilter ref="A1:` + colName(len(s.Header)-1) + strconv.Itoa(max(1, n)) + `"/>`)
		}
		b.WriteString("</worksheet>")
		add("xl/worksheets/sheet"+strconv.Itoa(i+1)+".xml", b.String())
	}
	_ = z.Close()
	return buf.Bytes()
}

var badSheetChars = regexp.MustCompile(`[\\/\?\*\[\]:]`)

func sheetName(n string, i int) string {
	n = strings.TrimSpace(badSheetChars.ReplaceAllString(n, " "))
	if n == "" {
		n = "Sheet" + strconv.Itoa(i+1)
	}
	return conv.Substr(n, 0, 31)
}

var plainNumber = regexp.MustCompile(`^-?(0|[1-9]\d{0,14})(\.\d{1,10})?$`)

func rowXML(r int, cells []any, style int) string {
	var x strings.Builder
	x.WriteString(`<row r="` + strconv.Itoa(r) + `">`)
	st := ""
	if style > 0 {
		st = ` s="` + strconv.Itoa(style) + `"`
	}
	for i, v := range cells {
		if v == nil {
			continue
		}
		if s, ok := v.(string); ok && s == "" {
			continue
		}
		ref := colName(i) + strconv.Itoa(r)
		if s, ok := v.(string); ok && style == 0 && plainNumber.MatchString(s) {
			if strings.Contains(s, ".") {
				f, _ := strconv.ParseFloat(s, 64)
				v = f
			} else {
				n, _ := strconv.Atoi(s)
				v = n
			}
		}
		if b, ok := v.(bool); ok {
			if b {
				v = "Yes"
			} else {
				v = "No"
			}
		}
		switch n := v.(type) {
		case int:
			x.WriteString(`<c r="` + ref + `"` + st + `><v>` + strconv.Itoa(n) + `</v></c>`)
		case float64:
			x.WriteString(`<c r="` + ref + `"` + st + `><v>` + conv.Str(n) + `</v></c>`)
		default:
			x.WriteString(`<c r="` + ref + `" t="inlineStr"` + st + `><is><t xml:space="preserve">` + xmlEsc(conv.Str(v)) + `</t></is></c>`)
		}
	}
	x.WriteString("</row>")
	return x.String()
}

func colName(i int) string {
	s := ""
	for n := i + 1; n > 0; n = (n - 1) / 26 {
		s = string(rune('A'+(n-1)%26)) + s
	}
	return s
}

var ctrlChars = regexp.MustCompile("[\x00-\x08\x0B\x0C\x0E-\x1F]")

func xmlEsc(s string) string {
	s = ctrlChars.ReplaceAllString(s, "")
	var b bytes.Buffer
	_ = xml.EscapeText(&b, []byte(s))
	return strings.ReplaceAll(b.String(), "&#xA;", "\n")
}

// ---------------------------------------------------------------- read

// XlsxSheet is a sheet read from a file.
type XlsxSheet struct {
	Name string
	Rows [][]string
}

// XlsxRead reads every sheet into rows of strings (dates become 2026-10-06 / 2026-10-06 14:30).
func XlsxRead(data []byte, maxRows int) ([]XlsxSheet, error) {
	zr, err := zip.NewReader(bytes.NewReader(data), int64(len(data)))
	if err != nil {
		return nil, errors.New("This is not a valid Excel file.")
	}
	files := map[string]*zip.File{}
	for _, f := range zr.File {
		files[f.Name] = f
	}
	read := func(name string) []byte {
		f, ok := files[name]
		if !ok {
			return nil
		}
		rc, err := f.Open()
		if err != nil {
			return nil
		}
		defer rc.Close()
		b, _ := io.ReadAll(io.LimitReader(rc, 512<<20))
		return b
	}
	// shared strings
	var shared []string
	if raw := read("xl/sharedStrings.xml"); raw != nil {
		var sst struct {
			SI []struct {
				T string `xml:"t"`
				R []struct {
					T string `xml:"t"`
				} `xml:"r"`
			} `xml:"si"`
		}
		_ = xml.Unmarshal(raw, &sst)
		for _, si := range sst.SI {
			t := si.T
			if t == "" {
				for _, r := range si.R {
					t += r.T
				}
			}
			shared = append(shared, t)
		}
	}
	dates := dateStyles(read("xl/styles.xml"))
	var wb struct {
		Sheets []struct {
			Name string `xml:"name,attr"`
			RID  string `xml:"http://schemas.openxmlformats.org/officeDocument/2006/relationships id,attr"`
		} `xml:"sheets>sheet"`
	}
	if err := xml.Unmarshal(read("xl/workbook.xml"), &wb); err != nil {
		return nil, errors.New("Cannot read the workbook.")
	}
	var rels struct {
		R []struct {
			ID     string `xml:"Id,attr"`
			Target string `xml:"Target,attr"`
		} `xml:"Relationship"`
	}
	_ = xml.Unmarshal(read("xl/_rels/workbook.xml.rels"), &rels)
	targets := map[string]string{}
	for _, r := range rels.R {
		targets[r.ID] = strings.TrimLeft(r.Target, "/")
	}
	var out []XlsxSheet
	for _, s := range wb.Sheets {
		t := targets[s.RID]
		if t == "" {
			continue
		}
		if !strings.HasPrefix(t, "xl/") {
			t = "xl/" + t
		}
		raw := read(t)
		if raw == nil {
			continue
		}
		rows, err := sheetRows(raw, shared, dates, maxRows)
		if err != nil {
			return nil, err
		}
		out = append(out, XlsxSheet{Name: s.Name, Rows: rows})
	}
	return out, nil
}

func sheetRows(raw []byte, shared []string, dates map[int]bool, maxRows int) ([][]string, error) {
	d := xml.NewDecoder(bytes.NewReader(raw))
	var rows [][]string
	type cell struct {
		R  string `xml:"r,attr"`
		T  string `xml:"t,attr"`
		S  string `xml:"s,attr"`
		V  string `xml:"v"`
		IS struct {
			T string `xml:"t"`
			R []struct {
				T string `xml:"t"`
			} `xml:"r"`
		} `xml:"is"`
	}
	for {
		tok, err := d.Token()
		if err == io.EOF {
			break
		}
		if err != nil {
			return nil, errors.New("This is not a valid Excel file.")
		}
		se, ok := tok.(xml.StartElement)
		if !ok || se.Name.Local != "row" {
			continue
		}
		var row struct {
			C []cell `xml:"c"`
		}
		if err := d.DecodeElement(&row, &se); err != nil {
			return nil, errors.New("This is not a valid Excel file.")
		}
		vals := map[int]string{}
		maxCol := -1
		for _, c := range row.C {
			col := colIndex(c.R)
			v := c.V
			switch c.T {
			case "s":
				i := conv.Int(v)
				if i >= 0 && i < len(shared) {
					v = shared[i]
				} else {
					v = ""
				}
			case "inlineStr":
				v = c.IS.T
				if v == "" {
					for _, r := range c.IS.R {
						v += r.T
					}
				}
			case "b":
				if v == "1" {
					v = "TRUE"
				} else {
					v = "FALSE"
				}
			case "", "n":
				if v != "" && dates[conv.Int(c.S)] && IsNumeric(v) {
					v = excelDate(conv.Float(v))
				} else if v != "" && IsNumeric(v) && strings.Contains(v, "E") {
					v = strings.TrimRight(strings.TrimRight(strconv.FormatFloat(conv.Float(v), 'f', 10, 64), "0"), ".")
				}
			}
			vals[col] = v
			if col > maxCol {
				maxCol = col
			}
		}
		full := make([]string, maxCol+1)
		for i, v := range vals {
			full[i] = v
		}
		rows = append(rows, full)
		if len(rows) > maxRows {
			return nil, fmt.Errorf("The sheet has more than %d rows. Split it into smaller files.", maxRows)
		}
	}
	for len(rows) > 0 {
		last := rows[len(rows)-1]
		empty := true
		for _, v := range last {
			if v != "" {
				empty = false
				break
			}
		}
		if !empty {
			break
		}
		rows = rows[:len(rows)-1]
	}
	return rows, nil
}

var colLetters = regexp.MustCompile(`^[A-Z]+`)

func colIndex(ref string) int {
	m := colLetters.FindString(ref)
	if m == "" {
		m = "A"
	}
	n := 0
	for _, ch := range m {
		n = n*26 + int(ch-64)
	}
	return n - 1
}

var fmtStrip = regexp.MustCompile(`"[^"]*"|\[[^\]]*\]`)
var dateCode = regexp.MustCompile(`[dmyhs]`)
var numCode = regexp.MustCompile(`^[#0.,%\s]+$`)

func dateStyles(raw []byte) map[int]bool {
	out := map[int]bool{}
	if raw == nil {
		return out
	}
	var st struct {
		NumFmts []struct {
			ID   int    `xml:"numFmtId,attr"`
			Code string `xml:"formatCode,attr"`
		} `xml:"numFmts>numFmt"`
		Xfs []struct {
			ID int `xml:"numFmtId,attr"`
		} `xml:"cellXfs>xf"`
	}
	_ = xml.Unmarshal(raw, &st)
	custom := map[int]bool{}
	for _, f := range st.NumFmts {
		code := strings.ToLower(fmtStrip.ReplaceAllString(f.Code, ""))
		custom[f.ID] = dateCode.MatchString(code) && !numCode.MatchString(code)
	}
	for i, xf := range st.Xfs {
		if (xf.ID >= 14 && xf.ID <= 22) || (xf.ID >= 45 && xf.ID <= 47) || custom[xf.ID] {
			out[i] = true
		}
	}
	return out
}

func excelDate(serial float64) string {
	secs := int64(math.Round((serial - 25569) * 86400))
	t := time.Unix(secs, 0).UTC()
	if math.Mod(serial, 1.0) > 0.0001 {
		return t.Format("2006-01-02 15:04")
	}
	return t.Format("2006-01-02")
}
