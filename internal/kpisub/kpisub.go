// Package kpisub is App\Core\Support\KpiSubmissions: monthly KPI / OKR results (User Management → KPI submissions).
//   - The KPIs and OKRs come from User Management → KPIs & OKRs (kpis); each can be submitted once per month.
//   - Month M is due on the 7th of month M+1: on time 100 %, then 20 % less per day late, 0 % from 5 days.
//     The approver should approve by the 10th; a later approval is flagged.
//   - The submitter cannot change a submission. The BU manager of the department (or an administrator) can correct or
//     delete it; deleting frees the KPI again. Every change is in the activity log and on the KPI's page.
package kpisub

import (
	"crypto/rand"
	"encoding/hex"
	"io"
	"mime/multipart"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"acme/internal/app"
	"acme/internal/db"
	"acme/internal/php"
)

const (
	DueDay     = 7
	ApproveDay = 10
	MaxFiles   = 5
	MaxFileMB  = 10
)

var exts = []string{"pdf", "png", "jpg", "jpeg", "webp", "gif", "doc", "docx", "xls", "xlsx", "ppt", "pptx", "csv", "txt", "zip"}

func day(y, m, d int) time.Time { return time.Date(y, time.Month(m), d, 0, 0, 0, 0, php.Loc) }

func nextMonthDay(y, m, d int) time.Time {
	if m == 12 {
		y, m = y+1, 1
	} else {
		m++
	}
	return day(y, m, d)
}

// DueDate is the last day to submit month m of year y (the 7th of the next month).
func DueDate(y, m int) time.Time { return nextMonthDay(y, m, DueDay) }

// ApproveBy: the approver should approve by the 10th of the next month.
func ApproveBy(y, m int) time.Time { return nextMonthDay(y, m, ApproveDay) }

// DaysBetween is the whole days from a to b (b later = positive), on calendar dates.
func DaysBetween(a, b time.Time) int {
	a = time.Date(a.Year(), a.Month(), a.Day(), 0, 0, 0, 0, time.UTC)
	b = time.Date(b.Year(), b.Month(), b.Day(), 0, 0, 0, 0, time.UTC)
	return int(b.Sub(a).Hours() / 24)
}

// Today is today at midnight (company time).
func Today() time.Time { n := php.Now(); return day(n.Year(), int(n.Month()), n.Day()) }

// Timeliness of a submission made on `on`: days late and percentage.
func Timeliness(y, m int, on time.Time) (int, int) {
	late := max(0, DaysBetween(DueDate(y, m), on))
	return late, max(0, 100-20*late)
}

// Period is a year and month.
type Period struct{ Y, M int }

// Periods: months people may submit now: this month and the eleven before it, newest first.
func Periods() []Period {
	n := php.Now()
	d := day(n.Year(), int(n.Month()), 1)
	out := make([]Period, 0, 12)
	for i := 0; i < 12; i++ {
		out = append(out, Period{d.Year(), int(d.Month())})
		d = d.AddDate(0, -1, 0)
	}
	return out
}

// InPeriods: is y/m one of Periods()?
func InPeriods(y, m int) bool {
	for _, p := range Periods() {
		if p.Y == y && p.M == m {
			return true
		}
	}
	return false
}

// Task is a KPI / OKR that can be submitted.
type Task struct {
	ID       int
	Title    string
	Kind     string
	Criteria any
}

// Available: the person's active KPIs & OKRs whose period covers this month and that are not yet submitted for it.
func Available(u *app.CurrentUser, y, m int) []Task {
	first := php.Date("Y-m-d", day(y, m, 1))
	rows := db.Select(`SELECT k.id, k.title, k.kind, k.criteria FROM kpis k
            WHERE k.deleted_at IS NULL AND k.status = 'active' AND k.user_id = ?
              AND k.period_start <= (?::date + interval '1 month - 1 day') AND k.period_end >= ?::date
              AND NOT EXISTS (SELECT 1 FROM kpi_submissions s WHERE s.kpi_id = k.id AND s.period_year = ? AND s.period_month = ? AND s.deleted_at IS NULL)
            ORDER BY k.kind, k.title`, u.ID, first, first, y, m)
	out := make([]Task, 0, len(rows))
	for _, r := range rows {
		out = append(out, Task{ID: r.Int("id"), Title: r.Str("title"), Kind: r.Str("kind"), Criteria: r["criteria"]})
	}
	return out
}

// FindTask picks one of the available tasks.
func FindTask(tasks []Task, id int) *Task {
	for i := range tasks {
		if tasks[i].ID == id {
			return &tasks[i]
		}
	}
	return nil
}

// Approvers: people who can approve, with photo: active managers and above (not the submitter themselves).
func Approvers(c *app.Ctx, userID int) []app.Opt {
	rows := db.Select(`SELECT u.id, u.name, u.avatar, r.name AS role_name, d.name AS department_name FROM users u JOIN roles r ON r.id = u.role_id
            LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.deleted_at IS NULL AND u.is_active AND u.id <> ? AND r.level >= 40 ORDER BY u.name`, userID)
	return People(c, rows)
}

// People are dropdown options with photo.
func People(c *app.Ctx, rows []db.Row) []app.Opt {
	out := []app.Opt{}
	for _, r := range rows {
		sub := r.Str("role_name")
		if r.Str("department_name") != "" {
			sub += " · " + r.Str("department_name")
		}
		o := app.Opt{Value: r.Str("id"), Label: r.Str("name"), Sub: strings.Trim(sub, " ·")}
		if r.Str("avatar") != "" {
			o.Img = c.UploadURL(r["avatar"])
		}
		out = append(out, o)
	}
	return out
}

// HasOpt: is v one of the options?
func HasOpt(opts []app.Opt, v string) bool {
	_, ok := app.OptLabel(opts, v)
	return ok
}

// ScopeSQL: administrators and roles that see everything (Management) see all; a department role (BU manager) sees
// its department and its own; everyone else only their own records.
func ScopeSQL(u *app.CurrentUser, alias, ownerCol string) (string, []any) {
	scope := u.Scope()
	if u.IsAdmin() || scope == "all" {
		return "TRUE", nil
	}
	if scope == "department" && u.DepartmentID != 0 {
		return "(" + alias + ".department_id = ? OR " + alias + "." + ownerCol + " = ?)", []any{u.DepartmentID, u.ID}
	}
	return alias + "." + ownerCol + " = ?", []any{u.ID}
}

// VisibleSQL: submissions in the scope above, plus the ones this person has to approve.
func VisibleSQL(u *app.CurrentUser, alias string) (string, []any) {
	sql, p := ScopeSQL(u, alias, "user_id")
	return "(" + sql + " OR " + alias + ".approver_id = ?)", append(p, u.ID)
}

// CanManage: adding, changing and deleting KPIs & OKRs and submissions: administrators, and the BU manager of that department.
func CanManage(u *app.CurrentUser, departmentID any) bool {
	if u.IsAdmin() {
		return true
	}
	return u.Scope() == "department" && u.DepartmentID != 0 && php.Int(departmentID) == u.DepartmentID
}

// CanAdd: may this person add KPIs & OKRs at all (administrator or a department role)?
func CanAdd(u *app.CurrentUser) bool {
	return u.IsAdmin() || (u.Scope() == "department" && u.DepartmentID != 0)
}

func Find(u *app.CurrentUser, id int) db.Row {
	sql, p := VisibleSQL(u, "s")
	return db.First("SELECT s.* FROM kpi_submissions s WHERE s.id = ? AND s.deleted_at IS NULL AND "+sql, append([]any{id}, p...)...)
}

func CanApprove(u *app.CurrentUser, row db.Row) bool {
	return row.Int("approver_id") == u.ID || u.IsAdmin()
}

// CanEdit / CanDelete: only the BU manager of its department (or an administrator).
func CanEdit(u *app.CurrentUser, row db.Row) bool   { return CanManage(u, row["department_id"]) }
func CanDelete(u *app.CurrentUser, row db.Row) bool { return CanManage(u, row["department_id"]) }

// ---- attachments: storage/kpi/<year>/<random>.<ext>, never under public/

// Upload is one checked file.
type Upload struct {
	FH   *multipart.FileHeader
	Name string
	Ext  string
	Size int
}

// Uploads checks the uploaded files before anything is saved.
func Uploads(c *app.Ctx, files []*multipart.FileHeader) []Upload {
	out := []Upload{}
	for _, f := range files {
		name := f.Filename
		ext := strings.ToLower(strings.TrimPrefix(filepath.Ext(name), "."))
		if !php.InList(ext, exts...) {
			app.Invalid("files", c.T("\":name\": this kind of file is not allowed.", "name", name))
		}
		if f.Size > MaxFileMB*1024*1024 {
			app.Invalid("files", c.T("\":name\" is larger than :n MB.", "name", name, "n", MaxFileMB))
		}
		out = append(out, Upload{FH: f, Name: cleanName(name, ext), Ext: ext, Size: int(f.Size)})
	}
	if len(out) > MaxFiles {
		app.Invalid("files", c.T("Attach at most :n files.", "n", MaxFiles))
	}
	return out
}

// cleanName is Files::name() (or file.<ext> when that refuses the name).
func cleanName(n, ext string) string {
	n = strings.TrimSpace(strings.NewReplacer("\x00", "", "/", "", `\`, "").Replace(n))
	if n == "" || n == "." || n == ".." || strings.HasPrefix(n, ".") || php.Len(n) > 180 {
		return "file." + ext
	}
	return n
}

func randHex(n int) string {
	b := make([]byte, n)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

// Store saves the checked files of a submission.
func Store(submissionID int, files []Upload) {
	year := php.Date("Y", php.Now())
	dir := filepath.Join(app.BasePath, "storage", "kpi", year)
	if len(files) > 0 {
		_ = os.MkdirAll(dir, 0o775)
	}
	for _, f := range files {
		rel := year + "/" + randHex(16) + "." + f.Ext
		r, err := f.FH.Open()
		if err != nil {
			continue
		}
		data, err := io.ReadAll(r)
		r.Close()
		if err != nil || os.WriteFile(filepath.Join(app.BasePath, "storage", "kpi", filepath.FromSlash(rel)), data, 0o664) != nil {
			continue
		}
		mime := app.DetectMime(data[:min(len(data), 512)])
		if mime == "" {
			mime = "application/octet-stream"
		}
		db.Core.Insert("kpi_submission_files", db.Cols{{Name: "submission_id", Value: submissionID}, {Name: "name", Value: php.Substr(f.Name, 0, 200)},
			{Name: "path", Value: rel}, {Name: "mime", Value: mime}, {Name: "size", Value: f.Size}})
	}
}

var storedRe = regexp.MustCompile(`^\d{4}/[a-f0-9]{32}\.[a-z0-9]{2,5}$`)

// Path is the absolute path of a stored attachment, or "" if the stored name looks wrong.
func Path(rel string) string {
	if !storedRe.MatchString(rel) {
		return ""
	}
	abs := filepath.Join(app.BasePath, "storage", "kpi", filepath.FromSlash(rel))
	if st, err := os.Stat(abs); err != nil || st.IsDir() {
		return ""
	}
	return abs
}
