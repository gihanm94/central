package sheets

import (
	"strconv"
	"strings"

	"acme/internal/app"
	"acme/internal/conv"
	"acme/internal/google"
)

// The few Drive + Sheets calls the module needs (Support\GSheets).

const chunkRows = 2000 // rows per write request

func sheetsURL() string {
	return strings.TrimRight(conv.OrStr(app.Setting("google_sheets_url", ""), "https://sheets.googleapis.com"), "/")
}

func check(st int, body map[string]any, what string) map[string]any {
	if st >= 400 {
		google.Fail(what + ": " + google.ErrorMessage(body, "HTTP "+strconv.Itoa(st)))
	}
	return body
}

type spreadsheetFile struct {
	ID       string `json:"id"`
	Name     string `json:"name"`
	Modified string `json:"modified"`
}

// spreadsheets of the account, newest first.
func spreadsheets(c *app.Ctx, accountID int, search string) []spreadsheetFile {
	q := "mimeType = 'application/vnd.google-apps.spreadsheet' and trashed = false"
	if search != "" {
		q += " and name contains '" + strings.NewReplacer(`\`, `\\`, "'", `\'`).Replace(search) + "'"
	}
	st, b := accountAPI(c, accountID, "GET", google.APIURL()+"/drive/v3/files", google.Query{
		{K: "q", V: q}, {K: "orderBy", V: "modifiedTime desc"}, {K: "pageSize", V: 50}, {K: "fields", V: "files(id,name,modifiedTime)"},
		{K: "supportsAllDrives", V: "true"}, {K: "includeItemsFromAllDrives", V: "true"},
	}, nil)
	body := check(st, b, c.T("Google Drive"))
	out := []spreadsheetFile{}
	for _, f := range google.List(body["files"]) {
		m := google.Map(f)
		out = append(out, spreadsheetFile{conv.Str(m["id"]), conv.Str(m["name"]), conv.Str(m["modifiedTime"])})
	}
	return out
}

type sheetTab struct {
	ID    int    `json:"id"`
	Title string `json:"title"`
}

type spreadsheetInfo struct {
	Name string     `json:"name"`
	Tabs []sheetTab `json:"tabs"`
}

func spreadsheet(c *app.Ctx, accountID int, id string) spreadsheetInfo {
	st, b := accountAPI(c, accountID, "GET", sheetsURL()+"/v4/spreadsheets/"+google.RawURLEncode(id),
		google.Query{{K: "fields", V: "properties.title,sheets.properties(sheetId,title)"}}, nil)
	body := check(st, b, c.T("Google Sheets"))
	out := spreadsheetInfo{Name: conv.Str(google.Map(body["properties"])["title"]), Tabs: []sheetTab{}}
	for _, s := range google.List(body["sheets"]) {
		p := google.Map(google.Map(s)["properties"])
		out.Tabs = append(out.Tabs, sheetTab{conv.Int(p["sheetId"]), conv.Str(p["title"])})
	}
	return out
}

// create makes a new spreadsheet in the account's Drive.
func create(c *app.Ctx, accountID int, title, tab string) (string, string) {
	st, b := accountAPI(c, accountID, "POST", sheetsURL()+"/v4/spreadsheets", nil, map[string]any{
		"properties": map[string]any{"title": title}, "sheets": []any{map[string]any{"properties": map[string]any{"title": tab}}},
	})
	body := check(st, b, c.T("Google Sheets"))
	name := title
	if t, ok := google.Map(body["properties"])["title"]; ok && t != nil {
		name = conv.Str(t)
	}
	return conv.Str(body["spreadsheetId"]), name
}

// write replaces the whole tab with header + rows (the tab is created when missing and sized to fit, so rows left
// over from a bigger earlier sync disappear).
func write(c *app.Ctx, accountID int, id, tab string, header []string, rows [][]any) {
	base := sheetsURL() + "/v4/spreadsheets/" + google.RawURLEncode(id)
	sheetID := -1
	for _, t := range spreadsheet(c, accountID, id).Tabs {
		if t.Title == tab {
			sheetID = t.ID
		}
	}
	grid := map[string]any{"rowCount": max(2, len(rows)+1), "columnCount": max(1, len(header)), "frozenRowCount": 1}
	if sheetID < 0 {
		st, b := accountAPI(c, accountID, "POST", base+":batchUpdate", nil, map[string]any{"requests": []any{
			map[string]any{"addSheet": map[string]any{"properties": map[string]any{"title": tab, "gridProperties": grid}}},
		}})
		check(st, b, c.T("Google Sheets"))
	} else {
		st, b := accountAPI(c, accountID, "POST", base+":batchUpdate", nil, map[string]any{"requests": []any{
			map[string]any{"updateSheetProperties": map[string]any{"properties": map[string]any{"sheetId": sheetID, "gridProperties": grid}, "fields": "gridProperties(rowCount,columnCount,frozenRowCount)"}},
		}})
		check(st, b, c.T("Google Sheets"))
	}
	rng := "'" + strings.ReplaceAll(tab, "'", "''") + "'"
	st, b := accountAPI(c, accountID, "POST", base+"/values/"+google.RawURLEncode(rng)+":clear", nil, []any{})
	check(st, b, c.T("Google Sheets"))

	h := make([]any, len(header))
	for i, x := range header {
		h[i] = x
	}
	all := append([][]any{h}, rows...)
	for i := 0; i*chunkRows < len(all); i++ {
		chunk := all[i*chunkRows : min(len(all), (i+1)*chunkRows)]
		at := rng + "!A" + strconv.Itoa(i*chunkRows+1)
		st, b := accountAPI(c, accountID, "PUT", base+"/values/"+google.RawURLEncode(at), google.Query{{K: "valueInputOption", V: "RAW"}},
			map[string]any{"range": at, "majorDimension": "ROWS", "values": chunk})
		check(st, b, c.T("Google Sheets"))
	}
}
