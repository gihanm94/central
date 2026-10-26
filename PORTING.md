# Porting the PHP app to Go (branch `acme-go`)

The Go version must behave **exactly** like the PHP one: same URLs, same HTML (Tailwind classes, data-attributes,
texts), same database (PostgreSQL schemas are untouched, the SQL in `database/` is reused as is), same JavaScript and
CSS (`public/assets` is served unchanged), same translations (`lang/th.json`, generated from `lang/th.php`).
Port line by line; keep the PHP comments that explain *why*. When the PHP code does something odd, keep doing it.

## Layout

| PHP | Go |
|---|---|
| `public/index.php`, `app/bootstrap.php`, `routes/web.php` | `cmd/acme/main.go`, `internal/app/server.go`, `internal/core/routes.go` |
| `app/Core/Support/*` (DB, Session, View, Validator, Settings, Crypt, Activity, Mailer, Html, Xlsx, Csv, Upload, EditorImages, Modules, Migrator) | `internal/app/*`, `internal/db`, `internal/config`, `internal/i18n` |
| PHP behaviours (casts, `number_format`, `date`, `strtotime`, `mb_substr` …) | `internal/php` |
| `app/Core/Auth/*` (Auth, CurrentUser, SessionGuard, Permission) | `internal/app/auth.go` |
| `app/Core/Http/Controllers/ResourceController.php` + `resources/views/resource/*` | `internal/app/resource.go`, `resource_view.go`, `web/views/resource/*` |
| `app/Core/Http/Controllers/*` (core pages) | `internal/core/*.go` (package `core`) |
| `app/Modules/<Name>/` | `internal/modules/<key>/` (package `<key>`) |
| `resources/views/<x>.php`, `app/Modules/<Name>/views/<x>.php` | `web/views/<x>.gohtml` (module views: `web/views/<key>/…`, same relative names as PHP `view('key::x')` → `"<key>/x"`) |
| `config/*.php` | `config/*.json` (made by `php tools/php-to-json.php`); `config/config.json` holds the server's passwords (not in git) |
| `lang/th.php` | `lang/th.json` |
| `bin/*.php` | `acme <command>` (register in `app.Commands`) |

Run: `go build -o acme ./cmd/acme && ./acme -addr :8000 -base .` (`acme migrate` runs the SQL migrations).
Use `GOTOOLCHAIN=local` (Go 1.24, no newer toolchain download). Allowed libraries: the standard library, chi, pgx,
golang.org/x/crypto, golang.org/x/net. Ask before adding anything else.

## Handlers

A handler is `func(c *app.Ctx)`. Like PHP's `redirect()`, `json_response()`, `abort()` and `exit`, the response
helpers **stop the handler** (they panic with a sentinel that the server recovers):

```go
c.View("members/index", app.M{"title": c.T("Members"), "rows": rows})   // return view(...)
c.Redirect("/members/" + php.Str(id))                                   // redirect(...)
c.Back("success", c.T("Saved."))                                        // back('success', …)
c.JSON(app.M{"ok": true})                                               // json_response([...])
c.Download(name, mime, bytes); c.Inline(name, mime, bytes)
c.XlsxDownload(name, header, rows); c.CsvDownload(name, header, rows)    // Xlsx::download / Csv::download
app.Abort(404, msg)                                                      // abort(404, $msg)
app.Invalid("field", msg)                                                // throw new ValidationException(['field' => $msg])
c.Flash("success", msg)                                                  // Session::flash
```

`try { … } catch (\Throwable $e)` → `if err := app.Catch(func() { … }); err != nil { … }` (a written response still stops).
A failing query panics with `*db.Error` (PDOException); `db.Try(func(){…})` turns it into an error.

Request: `c.Input("k")` (trimmed string, Request::input), `c.Inputs("ids")` (array), `c.InputAny("k")` (raw: string,
`[]any` or `*config.Map` for nested `lines[0][qty]`), `c.All()` (ordered `*config.Map`, Request::all), `c.Has`,
`c.Bool("k")` (Request::boolean), `c.Query("k", def)`, `c.QueryMap()`, `c.File("k")` / `c.Files("k")`
(`*multipart.FileHeader`), `c.Param("name")`, `c.ParamInt("id")`, `c.IsJSON()`, `c.Method()`, `c.Path()`, `c.IP()`,
`c.UserAgent()`, `c.Cookie()`, `c.SetCookie()`, `c.Sess` (session: Get/Set/Delete/Int).

Auth: `c.User()` (Auth::user(), nil for guests), `c.MustUser()` ($this->user()), `c.UserID()`, `c.Can(res, act)`,
`c.Authorize(res, act)`, `u.IsAdmin()`, `u.HasRole(...)`, `u.Scope()`, `u.DepartmentID` (0 = none), `u.DeptID()` (nil when none),
`app.ScopeSQL(u, "t", app.ScopeCols{Owner: []string{"user_id"}, Department: "department_id", Team: "team_id"})` (Permission::scope → sql, params).

Language: `c.T("Hello :name", "name", n)` (__()), `c.Locale()`, `c.With(locale, func(){…})` (I18n::with).
URLs: `c.URL("/x", "page", 2)` (url()), `c.Asset()`, `c.UploadURL()`, `c.BaseURL()`.
Validation: `c.ValidateInput(app.Rule{Field: "email", Rules: "required|email|max:190", Label: c.T("e-mail")}, …)` → `*app.Clean`
(ordered; `.Str/.Int/.Bool/.Get/.Has/.Set`). Same rules and messages as Validator.php.
Activity: `app.LogActivity(c, app.ActivityEntry{Action: "created", Type: "task", ID: id, Label: l, Text: "Created :type \":label\"", Params: []any{"type", "task", "label", l}, Props: app.M{…}, Owner: ownerID, Module: "crm"})`.
Mail: `app.Notify(c, to, name, subject, []template.HTML{…}, &app.MailButton{…}, note)` (Mailer::notify), `app.SendMail(...)`.
Settings: `app.Setting(key, def)`, `app.PutSetting(key, v)`, `app.Branding(c)`. Crypt: `app.Encrypt/Decrypt` (same format as PHP).
Uploads: `app.UploadImage(c, fh, folder, maxKB, allowSVG)`, `app.DeleteUpload(path)`. Rich text: `app.HTMLClean`, `c.HTMLRender`, `app.HTMLText`, `app.EditorImagesSaved`.
Small helpers: `app.E(v)` (e()), `app.HTML(s)`, `app.Badge`, `app.FormatDate(c, v, fmt)`, `app.TimeAgo`, `app.DeptColor`, `app.EnumLabel/EnumOptions`.

Shared support already ported (use these, do not port them again):
- Notifier: `app.Deliver(c, module, action, userID, app.Text("Key :x", "x", v), app.Text(…), url, actorID)` (Notifier::deliver),
  `app.Bell(...)`, `app.SecurityAlert(...)`, `app.NotifyMatrix()`, `app.NotifySecurity()`, `app.NotifyPrefs(raw)`; record events are sent by `app.LogActivity`.
- Lark: `app.LarkMode()`, `app.LarkWebhook`, `app.LarkDirect`, `app.LarkTest`.
- Options: `app.OptionsUsers(c, u, active)`, `app.OptionsPeople`, `app.OptionsDepartments(u)`, `app.OptionsTeams(u)`, `app.OptionsRoles(u)`, `app.OptionsAllRoles()`.
- Period: `from, to, key := c.PeriodFromRequest()`, `app.PeriodPresets`; partial `partials/range-bar` takes
  `dict "action" (url "/x") "range" key "from" from "to" to "keep" (map) "selects" ([]app.RangeSelect)`.
- Partials ready in `web/views/partials`: avatar, delete-dialog, flash, logo-drop, notification-list, pagination, picker, remote-select,
  select, priority (`dict "p" …`), progress (`dict "value" …`), scope-badge (`dict "scope" …`), status (`dict "s" … "labels" map`), tabs (`dict "tabs" (list (list url label icon) …)`), range-bar, department-extra; `crm/stats` (`dict "cards" []app.Stat`).
- Package `acme/internal/oil` (OilPrices): interface stubs `oil.Current()`, `oil.LastSync()`, `oil.Tick(c)`; the management port fills them in.

## Database

```go
rows := db.Select("SELECT … WHERE id = ?", id)           // core (user_db); []db.Row
row  := db.On("crm").First("SELECT …", a, b)              // nil when none
n    := php.Int(db.On("accounting").Scalar("SELECT count(*) …"))
db.On("crm").Exec("UPDATE …", …)
id   := db.On("crm").InsertID("leads", db.Cols{{Name: "name", Value: n}, …})   // DB::insert … RETURNING id
db.On("crm").Insert("t", cols, "")                        // no RETURNING
db.On("crm").Update("leads", db.Cols{…}, db.Cols{{Name: "id", Value: id}})
db.On("crm").Transaction(func(tx db.Q) { tx.Exec(…) })    // use tx inside!
```

Placeholders are `?`. Values come back typed like PHP's PDO gave them: integers → `int`, booleans → `bool`,
float4/8 → `float64`, **numeric, dates, timestamps, json, text → `string`**, NULL → `nil`.
`row.Str("k")`, `row.Int("k")`, `row.Float("k")`, `row.Bool("k")` (filter_var BOOL), `row.Has`, `row.Nil`.
Parameters: Go values; maps/slices are JSON-encoded (like the PHP layer). `bool` works as is.

`internal/php` mirrors PHP: `php.Int`, `php.Float`, `php.Str`, `php.Bool` (FILTER_VALIDATE_BOOL), `php.Empty`,
`php.Blank`, `php.Or` (?:), `php.Coalesce` (??), `php.NF(n, dec)` (number_format), `php.NumberClean`,
`php.Substr`, `php.Len`, `php.Limit` (str_limit), `php.Ucfirst`, `php.Initials`, `php.InList`,
`php.Now()`, `php.NowString()` (now()), `php.Date(fmt, t)` (date()), `php.DateOf(fmt, v)` (date($f, strtotime($v))),
`php.StrToTime(v)`, `php.ParseTime`, `php.Today()`, `php.Loc` (company time zone).

## Templates (`web/views/**/*.gohtml`)

Go `html/template`; every file is one template named by its path without the extension, all in one set, so any
template can include any other: `{{template "partials/avatar" (dict "name" .name "size" "size-9")}}`.
A page is rendered first, then its layout (`layouts/app`, or `{{set "layout" "layouts/guest"}}`, or `{{set "layout" ""}}`
for none) — `{{set "title" …}}` / `{{set "crumbs" (list (list label url) …)}}` pass PHP's `$title` / `$crumbs` to the layout.
Pass `title` from the handler when PHP did (`view('x', ['title' => …])`).

Funcs (PHP helper → template func): `__ "Key" "param" v` (`__()`), `url "/x" "k" v`, `asset`, `uploadURL`, `icon "name" "classes"`,
`csrfField`, `csrfToken`, `old "k" default`, `errorFor "k"`, `fieldError "k"`, `auth` (CurrentUser: `.Name`, `.ID`, `.IsAdmin` …),
`can "res" "act"`, `isAdmin`, `locale`, `formatDate v "d M Y"`, `timeAgo`, `date "Y-m-d" v`, `initials`, `strLimit s 60`,
`numberClean`, `nf n 2` (number_format), `ucfirst`, `lower`, `upper`, `trim`, `replace`, `contains`, `hasPrefix`, `hasSuffix`, `join`, `split`,
`substr`, `len`, `setting "k" "def"`, `config "a.b"`, `enumLabel`, `enumOptions`, `activityText row`,
`select name opts value (dict "placeholder" … "submit" true …)` (`select_field()`, options are `[]app.Opt` — build them in Go
or with `opts "v1" "Label 1" …`), `partial "name" "k" v …` (dynamic name), `dict`, `list`, `strs`, `mergeDict`, `json` (JS value),
`jsonAttr`, `raw` (trusted HTML), `attr`, `safeURL`, `css`, `str`, `int`, `float`, `bool`, `empty`, `blank`, `filled`, `default def v`,
`coalesce`, `or`, `ternary cond a b`, `eqs` (string compare), `in v list…`, `add`, `sub`, `mul`, `div`, `mod`, `gt`, `lt`, `seq a b`,
`get m k`, `has m k`, `path`, `query "k"`, `queryMap`, `withQueryMap "k" v …`, `pager`, `ctx`, `now`, `year`.

Rules:
- Keep the PHP markup byte for byte where you can (classes, attributes, order). Whitespace may differ.
- Values print escaped automatically; PHP `<?= $html ?>` without `e()` → pass `template.HTML` from Go (build it with `app.E` for every value) or `raw`.
- `{{range}}` changes `.`; use `$` or named variables (`{{range $r := .rows}}`).
- Comparisons: `eq` needs the same type (`eq .row.status "done"` is fine for strings; ints from rows are `int`). Use `eqs` when unsure.
- Compute heavy logic (grouping, maps, closures in PHP views) in Go and pass ready values; keep templates simple.
- Column renderers (ResourceController `columns()` closures) are `Render func(c *app.Ctx, row db.Row) template.HTML`.

## Standard screens

`internal/app/resource.go` is ResourceController. A PHP subclass becomes an `*app.Resource` value with hook funcs
(nil = base behaviour) — see `internal/core/departments.go` (DepartmentController) as the worked example.
`fields()` → `Fields func(c, row) []app.Field` (a section heading is `app.Field{Section: …, Icon: …, Help: …}`;
`'table' => false` → `NoTable: true`; `'import' => false` → `NoImport: true`; options → `[]app.Opt`;
picker choices → `Extra: app.M{"choices": []app.PickChoice{…}, "max": 3, …}`).
`$resource->Mount(r, skip…)` registers the standard routes; register your own handler for an action the PHP class
overrides and pass its name in `skip`. Methods: `Index`, `Create`, `Store`, `Show`, `Edit`, `Update`, `Destroy`,
`BulkDestroy`, `Export`, `Template`, `Import`, `Download`, plus `Find`, `FindForChange`, `Query`, `LabelOf`, `ValidateFields`,
`TableData`, `DisplayValues`, `Meta`.

## Modules

Each module package registers itself (module.php → Go):

```go
func init() {
    app.RegisterModule(&app.Module{
        Key: "crm", Name: "CRM", Icon: "handshake", Home: "/crm/dashboard", Enabled: true, DB: "crm",
        Dashboards: []app.Dashboard{{Key: "overview", Label: "CRM overview", URL: "/crm/dashboard"}},
        Menu: []app.MenuItem{{Heading: "Overview"}, {Label: "CRM overview", URL: "/crm/dashboard", Icon: "chart", Permission: "crm_dashboard.view"}, …},
        Hooks: map[string]any{"tick": app.HookTick(func(c *app.Ctx) { … })},
        Routes: routes,          // routes.php: func(r app.Router) { r.Get("/crm/leads", leadsIndex) … } — already inside the signed-in group
        API: api,                // api.php, paths relative to /api/v1
        MigrationsDir: "database/sql/modules/sheets",   // only for modules with their own migrations folder
    })
}
```

`config/modules.json` still overrides manifests (enabled …). Hooks between modules are typed in `internal/app/hooks.go`;
values that cross modules keep PHP's array keys (`app.M`, `[]app.M`). Command-line jobs (`bin/*.php`):
`app.Commands["erp-sync"] = func(args []string) int {…}` (set `db.CLI`-style behaviour is automatic).
Core pages register through `signedIn(func(r app.Router){…})` / `guest(…)` in `internal/core` (init funcs).

## Working rules for the parallel port

- Only add files in your own area (your package, `web/views/<your folders>`). Do not edit the framework
  (`internal/app`, `internal/db`, `internal/config`, `internal/php`, `internal/i18n`, `web/views/layouts`, `web/views/resource`,
  existing `web/views/partials/*`). If you truly need a framework helper, add it in a **new** file
  `internal/app/x_<area>.go` (small and generic), or keep it in your package. Report any framework bug you find.
- A shared partial from `resources/views/partials` that is not ported yet: port it to `web/views/partials/<same name>.gohtml`.
- `go build ./... && go vet -composites=false ./...` must pass before you commit.
- Test against the real database: copy `/var/tmp/goapp/config/config.json` into a base folder of your own, run your
  build on your own port, sign in as admin / Secret123A (member, management, bu.manager2: Password@123), and compare
  each page with the PHP version at http://localhost:8000 (screenshots: `node shot.js` in the scratchpad, see `PORTING-TEST.md`).
  Clean up test data you create.
- Commit in your worktree with clear messages ending with the session's Co-Authored-By lines.
