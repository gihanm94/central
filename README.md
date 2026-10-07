# Acme Inter — Company System (Core)

Plain PHP 8 + PostgreSQL. **No Composer, no npm, no build step.**
Open `install.php` in the browser, fill in the form, and it creates every database,
table, role, permission, your admin account and (optionally) demo data.

## Run it on a Mac

1. **PHP with PostgreSQL support**
   ```bash
   brew install php          # Homebrew PHP includes pdo_pgsql
   php -m | grep pdo_pgsql   # must print pdo_pgsql
   ```
2. **PostgreSQL** — [Postgres.app](https://postgresapp.com) or `brew install postgresql@16 && brew services start postgresql@16`.
3. **Create the database user** (once). In a terminal:
   ```bash
   psql postgres -f database/sql/00_create_databases.sql
   ```
   This creates the login `acme` / `acmeinter123` with permission to create databases.
4. **Start the app** from this folder:
   ```bash
   php -S localhost:8000 -t public public/index.php
   ```
5. Open **http://localhost:8000** — you are sent to the installer. Click **Install now**,
   then sign in with the admin e-mail and password you chose.

> Using Apache/nginx instead? Point the site's document root at `public/`
> (`public/.htaccess` handles Apache rewrites).

## Databases

| Area        | Database       | SQL file                                       |
|-------------|----------------|------------------------------------------------|
| Core        | `user_db`      | `user_db.sql`, `user_db_seed.sql`, `user_db_demo.sql` |
| CRM         | `crm_db`       | `migrations/crm/*.sql` (applied automatically) |
| Accounting  | `account_db`   | `account_db.sql`                               |
| Inventory   | `inventory_db` | `inventory_db.sql`                             |
| Machines    | `machine_db`   | `machine_db.sql`                               |
| HR          | `hr_db`        | `hr_db.sql`                                    |

All SQL is in `database/sql/` if you prefer to run it yourself with `psql -d <db> -f <file>`.
Re-running the installer is safe (delete `storage/installed.lock` first); existing data is kept.

## Demo accounts (if you ticked demo data)

Password for all: `Password@123`

| Role        | E-mail                     | Sees                                   |
|-------------|----------------------------|----------------------------------------|
| Management  | management@acmeinter.com   | Whole company, view and export only    |
| BU Manager  | bu.manager@acmeinter.com   | Operations department                  |
| Manager     | manager@acmeinter.com      | Maintenance team                       |
| Member      | member@acmeinter.com       | Own tasks, KPIs and activity           |

## Updating an existing install

Copy the new files over your folder (keep `config/config.php` and `storage/`). The next page
load applies any new SQL in `database/sql/migrations/` automatically — nothing to run.

## Languages

English and Thai are built in. People pick their language from the globe menu or
**Profile → Preferences**; the company default is under **Company settings**.
To add a language: copy `lang/th.php` to `lang/xx.php`, translate the right-hand side,
and add `'xx' => 'Name'` to `locales` in `config/core.php`. Missing lines fall back to English.

## Notifications (e-mail + Lark)

**Company settings → Notifications**
- Lark *Group chat*: paste a custom-bot webhook from a Lark group (optional signature secret).
- Lark *Direct message*: a Lark app (App ID + secret) with `im:message:send_as_bot` and
  `contact:user.email:readonly`; people are matched by work e-mail.
- A switch per module × action (create, edit, delete, import, export, download) × channel.
- Security alerts (new sign-in, password changed) per channel.

**Profile → Notifications**: each person turns off what they don't want (password e-mails always go out).

## What's included

- Sign in only (no sign-up), remember me, forgot / reset password, passkeys, lockout after failed attempts
- Sign-in page with replaceable company logo and photo (Company settings)
- Roles: Admin, Management, BU Manager, Manager, Member — plus your own roles
- Permission matrix per role: view, create, edit, delete, import, export, download
- Per-member exceptions (allow / deny) and a per-member data scope override
- Data scope everywhere: whole company / own department / own team / own records
- Members (with employment details), Departments, Teams, Tasks, KPIs: list, search, filter,
  create, edit, delete, CSV import (with template), CSV export, single-record download
- Activity log of every sign-in, change, import, export and download, with filters and export
- E-mail to the person who acted and the record owner on create / update / delete / import / export / download;
  security e-mails on sign-in, password reset and password change; invite e-mail for new members
- Admin dashboard (Control room) and employee dashboard (My workspace); each person picks the module and dashboard that opens after sign-in
- Employment data: level C1–C12/Other, status (Active, Probation, Suspended, Resigned, Terminated, Retired), type (Full time, Part time, Remote, Other), gender
- Collapsible sidebar with company + module switcher, breadcrumbs, phone-friendly drawer and card lists
- Tailwind dropdowns everywhere (no native selects), keyboard and search support
- Profile pages: details, security (password, passkeys, devices), preferences, notifications
- Module switcher ready for CRM, Accounting, Inventory, Machines, HR (see `app/Modules/README.md`)


## CRM module (`/crm`)

Built on the Java entities in `docs/reference/java-entities/` (Lead, Contact, Opportunity, Campaign, Activity, Industry, LeadSource, OpportunityStage).
A **lead** is the company; contacts, opportunities and activities hang off it. Code lives in `app/Modules/CRM`, views in `resources/views/crm`.

| Area | What it has |
|------|-------------|
| Leads | Industries and lead sources (several each), logo, parent company, Thai/English names, contacts / opportunities / activities on the page |
| Contacts | **Several mobile numbers** (one marked main), photo, company, lead source |
| Opportunities | Stage tracker (Qualification → Survey & proposal → Evaluation & testing → Negotiation → Won; plus Lost / On hold / Cancelled with a required reason), **registered products and "other" products typed by hand**, **next steps** with a full history of stage moves and what was done, comments, related activities |
| Activities | Call / meeting / e-mail / task, linked to a lead, contact and opportunity, reminders, **attachments**, **comments** |
| Campaigns | Budget vs cost, response rates, **attachments** |
| Settings | Industries, lead sources, products, currencies (rates to the base currency for dashboard totals) |
| Dashboard | Pipeline by stage, forecast, won by month, win rate, activities, leads by industry / source, campaigns. Everyone sees their **own department**; Admin / Management (whole-company roles) see all departments, pick one, and get a department comparison table |

### Who can see a record
* Whole-company roles (Admin, Management …): everything.
* Everyone else: records of **their own department**, records they own or created, and records **shared with them** (a person or a whole department).
* Owner, creator, the department head (a department-scope role) and whole-company roles can share a record, as *view only* or *can edit*, from the **Who can see this** card.
* Changing a record also needs the role's own create / edit / delete permission (Roles & access → "CRM …" rows). Defaults: Management view/export, BU Manager full, Manager and Member create/edit.


### Lists, forms and editor (all screens)
* **Lists**: search and filter on the left; add, import, export and *View* (choose which columns to show — remembered per person per list) on the right.
  First column = tick box + actions (view, edit, delete). Click a header to sort. Footer: first / previous / next / last, rows per page (default 10), go to page.
* **Delete** always asks to type `DELETE`; tick several rows to delete them together.
* **Forms** are full width. Required fields carry a red `*`, a counter under the title shows how many are filled with a *View required fields* dialog, and **Save stays disabled until every required field is filled**. CRM forms are wizards (one step per section).
* **Display size**: the zoom button in the top bar (80–125 %) is saved per person. All inputs, buttons and dropdowns are 32 px high (sign-in pages excluded).
* **Notes / descriptions** use a rich-text editor (Quill, served from `public/assets/vendor/quill`): images are uploaded to `public/uploads/editor` and can be resized by dragging or with 25/50/100 % buttons. Saved HTML is cleaned on the server.
* **Owner and department** are never typed: a new CRM record belongs to the person who creates it and to their department. Access for others is added in the form's last step or from *Who can see this* on the record.


### Notifications, pipeline, stages and targets
* **Bell** (top bar): unread count and the latest messages; *Notifications* page keeps the full list. Comment on a record → its owner gets a bell message and an e-mail; sharing a record notifies the people it is shared with; a planned activity with *Remind me* sends its reminder at the chosen time.
  Company switches: **Company settings → Notifications** (new *Comment*, *Share*, *Reminder* columns and an *In-app* row, per module). Each person can switch them off under **Profile → Notifications**.
* **Reminders** are sent while anyone has the app open. To send them when nobody is signed in, add a cron job: `* * * * * php /path/to/app/bin/reminders.php`.
* **Pipeline** (`/crm/pipeline`): one column per stage the person's department uses, coloured top edge, cards load as a column is scrolled. Everyone sees the cards they may see; **only the creator can drag a card** (or use its ⋮ *Move to* menu). Your own cards are highlighted.
* **Stages** (CRM settings → Stages): names are fixed. Colours (shown everywhere a stage appears, including the dashboard) are set by whole-company roles; each department's *available stages* are set by Admin or that department's BU Manager. A department that does not use a stage cannot pick it or see it in the pipeline.
* **Sales targets** (CRM settings → Sales targets): per department and year, a target for each quarter and for the year; actuals come from won deals and can be saved per year (or typed). The dashboard shows target vs actual.
* **Who sees CRM settings**: Admin, BU Manager and Manager (Manager/BU Manager can add industries, lead sources, products, currencies). Management can read targets only; Members have no settings. Adjust under *Roles & access*, or give one person an exception under *Members → Access*.

### Projects, tasks, team and data tools

- **Projects / Tasks** (`/crm/projects`, `/crm/tasks`): dates, status, details, files, comments and *several* responsible people per project and per task. Responsible people always see the project / task; progress shows on the project page. "Add task" opens the same task form in a side sheet.
- **Side sheet**: every "Add contact / opportunity / activity / task" button on a related page opens the normal create form in a slide-over (`?embed=1`) instead of leaving the page. There is only one form per record type.
- **Statistics cards** on top of each list: only roles holding *CRM statistics cards* (Admin and BU Manager by default).
- **Team** (`/crm/team`, permission *CRM team: reassign and bulk change*): pick a person (also people who left), see their records, hand ticked records or everything to someone else, or change the stage / status of many records at once (e.g. close stale opportunities). BU managers work inside their department; admins everywhere. The previous owner is kept in the record's history. A single record has *Change owner* in its Record card.
- **Data tools** (`/crm/data`, admin only): export tables as Excel, CSV (zip for several) or SQL INSERTs; import Excel, CSV or SQL files by choosing the table and matching each file column to a table column (fixed values, saved mappings, check-only run, update by code / id). SQL files are only read for INSERT / COPY data and never executed.
- **Mail log** (Company settings → Mail log): every e-mail and whether it was sent or why it failed.

### Files
Attachments are stored in `storage/attachments` (not reachable from the web) and downloaded through a permission check. Up to 10 MB each; pdf, images, office files, txt/csv, zip.

### Upgrading an existing install
Copy the files over; the next page load creates the CRM tables and permissions. If `crm_db` still has rows in the old CRM draft tables, the upgrade stops and asks you to empty them first.

## Accounting: ERP connection (`/accounting/erp`, administrators)

The ERP (Monitor) is read through its API and copied into `erp_*` tables of the accounting database (tables generated from your old Java entities: orders, order rows, invoices, customers, addresses, products, vouchers …).

1. Open **Accounting → ERP connection**, enter the server address, port, user name and password, and press *Test login*. The session id and cookies are kept in `erp_tokens` for an hour and renewed automatically (also after a 401).
2. All 39 API addresses are listed and editable (defaults in `config/erp.php`); *Test* asks an API for one row, *Sync* copies the newest rows of that entity.
3. The schedule works like the old `SyncScheduler`: **hot** (orders, invoices, customers … newest rows) every 3 minutes and **cold** (reference data) every 30 minutes, Mon–Fri 08:00–19:00 Bangkok, never two runs of a group at once. Tick *Run the schedule* and add the cron line shown on the page: `* * * * * php /path/to/app/bin/erp-sync.php tick`.
4. Command line: `php bin/erp-sync.php hot|cold|full|all`, `entity <key> [latest|all|one] [id]`, `login`.

The e-Tax (INET) addresses and authorization key are stored on the same page for the e-tax invoice step. Passwords and keys are saved in the database, never in the repository.

## Accounting: e-tax documents (`/accounting/inet`)

- **Company** (`/accounting/company`, administrators): the seller printed on every document and written into the INET text file, plus the path of Chrome/Chromium used for PDFs and the Authorization scheme (`Bearer` or `Basic`).
- **Generate** opens the invoice picker. For each chosen invoice the system reads the ERP copy, builds the INET text JSON (tax invoice `388`, receipt `T01`, or credit note `81`), validates it and saves it in `inets` (`text_388`, `text_t01`, `text_81`). With **Auto PDF** ticked it also renders the PDF (`storage/inet/<invoice>/`) from the same data.
- **Send** posts the JSON and PDF to the INET send API (if no PDF was generated you pick one to upload). **Inet** reads the status/params API and opens or downloads the signed PDF. **JSON / PDF** links show what was built. Sending needs the *edit* permission, generating needs *create*.
- The INET URLs and key are entered on the ERP connection page, never in the repo.
- Credit notes read the referenced invoice and amounts from the order comments (Thai or English wording).

## Generate: how it finds and builds documents

- `erp_documents` holds one prepared row per invoice number (customer, order, VAT no, delivery note, remark = the order's comment, credit flag). It is refreshed right after each ERP sync, only for invoices whose invoice / order / comment rows changed, so the Generate dialog is one indexed query. Building a document still reads the full source rows.
- Credit note = the order says `is_credit` **or** the invoice number starts with 4. The Invoice / Credit note tabs and the search work together (a search only looks inside the chosen tab).
- Generate builds every chosen invoice's JSON first, then prints the PDFs **5 at a time** (parallel Chromium) and saves. A PDF that fails keeps its JSON; the row shows the reason.
- The company (`companies`) is seeded once by migration `…_005_documents_company_seed.sql`; edit it on Accounting → Company. INET user code / access key / API key are not seeded (the flow does not use them).
- Auto sync: set the cron line shown on the ERP page, **or** leave it — any open accounting page starts a background `tick` when the scheduler has been quiet for a minute (default: schedule on, Mon–Fri 07:00–20:00). The ERP page shows live status, who last ran the scheduler, and the sync log.

## Accounting: PDF, sellers, overwrite

- **PDF** is built like the old system: rows come from the order's own rows delivered on this invoice (not the invoice log), free-text rows (type 4) sit under their product, batch numbers come from the stock transactions, and both BILL TO and SHIP TO are always printed. The box at the bottom left holds the **payment remark** (the customer's ERP comment) and, for a receipt / credit note, the company's receipt / credit text.
- **Payment remark** is prepared in `erp_documents` and shown in the Generate dialog (amber *none* when the customer has no comment), so you can skip an invoice before generating. Source: `erp_customers.comment_id` → `erp_comments`. Change it in `Documents::refresh()` and `DocumentData::paymentRemark()` if your ERP keeps it elsewhere.
- **Sellers** (`/accounting/sellers`): ERP columns are read-only; e-mail, user and Lark id are edited by hand and never touched by a sync.
- **Overwrite** (ERP connection → each table): off (default) = a sync only adds new ids; on = existing rows are updated too. **Sync from ERP** in a row menu (administrators) reads that one record and always updates it. The Inets *Sync* button does the same for an invoice and its order.
- Inets: tick rows to delete several (type DELETE); the `…` menu of each document has Raw Data, Get File, Regenerate and Remove.

## Accounting: Generate and Send windows

- **Generate** starts a background job (`bin/erp-sync.php generate <job>`, state in `storage/cache/jobs/`) and answers at once; the window shows a centre animation (spinner → tick) while the page asks `/accounting/inet/job/<id>` once a second, then closes and reloads. A web request is never held while Chromium prints. Without `exec()` the job runs inside the request instead.
- **Send** opens a window: upload a PDF (drag or click, preview, then Send) or switch on *Show Generated* to send the generated PDF. Send shows the same animation and closes on success.
- Success / error messages are toasts (`public/assets/toast.js`, `toast(msg, type)`), including the server's flash messages.

## Accounting: overview, billing notes, notifications

- **Overview** (`/accounting`): KPI cards and Chart.js charts (vendored in `public/assets/vendor/chartjs`): revenue by year / month, top customers, sellers, products, receivable ageing, orders per month, e-tax status, billing notes. Each block shows only if you may open its own screen. Numbers come from `Support\Dashboard` (kept 5 min; admins can Refresh).
- **Billing notes** (`/accounting/billing`): Generate = pick customer → tick its unbilled invoices → address → dates. Number is `BI` + yy + mm + 001, 002 … per month (admins can override by hand). The PDF is built like the old template, kept in `storage/billing/`, and opens in a window (zoom, print, download) from the list.
- **Notifications** (`/accounting/notify`, admins): once a day after the chosen time the scheduler sends the notes whose remind date is today on Lark — to the chosen users (app mode, by work e-mail) or the group (webhook mode). Lark itself is configured under Settings.
- **ERP connection** now has tabs (Status, Connection, Schedule, e-Tax portal, APIs); *Recent runs* is a table with pages and a Success / Warning / Failed filter (a run is *warning* when only some entities failed).

## Accounting logs (`/accounting/logs`, administrators)

Plain text files, never the database: `storage/logs/accounting/YYYY-MM-DD.log`, one JSON line per event, kept 30 days. The page follows the file live (about every 1.5 s) with filters for level, channel (`erp` API calls, `sync`, `inet` generate/send/fetch, `pdf`, `app` uncaught errors) and text search; each line opens to show URL, HTTP status, time, exception and where it happened. Secrets (keys, passwords, session ids) are masked. The **Generate** dialog shows its own run's lines live while it works. In code: `App\Modules\Accounting\Support\Log::info('inet', 'message', [...])`.

## Sessions, file manager, Google connectors

- **Session security:** the session id (access token) is replaced every 10 minutes (`security.session_rotate_minutes`); a sign-in can never last more than 12 hours (`security.session_max_hours`) — "remember me" included — then the person must sign in again. Every sign-in is a row in *Profile → Sessions* (device, browser, IP, last seen, sign-in/out log) and can be revoked; revoking your own current session signs you out. Administrators see everyone at *Administration → Sessions*.
- **File manager** (`/files`, administrators): browse the storage and upload folders, search, sort, paginate, see sizes; upload, new folder, rename, move, download, delete. Paths are sandboxed, executable file types and dot-files are refused.
- **Google connectors** (`/connectors` for the administrator, *Profile → Connectors* for everyone): create an OAuth client in Google Cloud Console (type *Web application*), enable the Gmail API and Google Calendar API, add the redirect URI shown on the Connectors page, paste the client id and secret. Each person then connects their own account; tokens are stored encrypted (AES-256-GCM, key derived from `app.key`). **Mail** (`/mail`) reads/searches/sends Gmail without copying it. **Calendar** (`/calendar`, and *CRM → Calendar*) shows CRM activities plus the Google Calendar; click a day to create an activity in the side sheet, click an activity to edit it. Every activity with a start time is also written to its owner's Google Calendar and kept in sync on edit, status change and delete.
- Notification e-mails are sent by the `mail` driver in `config/config.php`: `log` only writes them to *Settings → Mail log*; set `smtp` to really send.

## Machine Checklist (`/machines`)

Ported from the Spring service (entities, services, schedulers). Data lives in `machine_db` (migration `database/sql/migrations/machines`); people and departments come from the core. No overview page yet.

- **Machine types** (group → type) and **Questions** (the question bank) are managed by Admin, BU manager and Manager (`machines_types`, `machines_questions`).
- **Register**: anyone files a request (machine, people, maintenance and calibration plan, documents). An administrator/manager turns it into a **Machine** (*Create the machine*), which gets the code `<dept code>-<group><type>-<running no>` and a QR label (`{"status":true,"code":"…"}`, the same text the old labels carry). Documents are stored in `storage/machines` (signed-in download only).
- **Machine page**: QR code (print labels at `/machines/label`), checklist items (a question + when it starts again: general, daily, a weekday, monthly), maintenance and calibration plan, responsible-person history, change of responsible person.
- **Checklists** (mobile first): scan a QR code (`/machines/scan`: camera, photo of a QR code or typed code). Everybody does the *general* check. The responsible person's weekly/monthly *re-check* goes to the supervisor, then the manager, for approval; every step notifies the next person. NG answers and a non-operational status notify the team.
- **Maintenance** and **Calibration**: lists by year/state/department, edit, *Do the maintenance* (maintenance checklist, work done, photo, documents, late detection), calibration certificate and measurements.
- **Who sees what**: whole-company roles see everything; a department head sees the department; everybody else the machines they are responsible for, supervise or manage (plus what they checked or must approve).
- **Background jobs** (`app/Modules/Machines/Support/Jobs.php`): items start again on their day, weekly (Mon 00:05) / monthly (1st) close-out marks unapproved re-checks `…-OVERDUE` and puts machines back to PENDING, Friday 15:00 / last day 23:55 automatic "no action taken" records, weekday 09:00 maintenance/calibration reminders (managers Mon/Wed 09:15), 25 December copies the rounds to next year. Run `*/5 * * * * php bin/machines-cron.php` from cron; without cron the website runs them about once a minute while somebody is signed in. They start counting from the first run (no catching up on the past).
- Differences from the old service: a general check never changes the machine's check state; approving moves the machine's state along; the monthly automatic record runs on the last day of the month.

## E-mail

The installer defaults to **log** mode: every e-mail is saved as an HTML file in `storage/mail/`
(open it in a browser). To really send, choose SMTP in the installer or edit `config/config.php`:
```php
'mail' => ['driver' => 'smtp', 'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls',
           'username' => 'you@gmail.com', 'password' => 'your-app-password', …]
```
Then use **Company settings → Send me a test e-mail**.

## Passkeys

Work on `http://localhost` and on any HTTPS site. On a plain-HTTP IP address browsers block them.

## Changing the design

Styles are pre-built in `public/assets/app.css`. Only if you add new Tailwind classes, rebuild with:
```bash
npx @tailwindcss/cli -i resources/css/app.css -o public/assets/app.css --minify
```
Run it inside this project folder (it needs internet once to download Tailwind).

## Folder map

```
public/            index.php (front door), install.php, assets/, uploads/
app/Core/Auth      sign-in, remember me, passkeys
app/Core/Support   DB, router, validation, permissions + data scope, activity log, mailer, CSV
app/Core/Http      controllers (members, departments, teams, tasks, KPIs, roles, activity, settings…)
app/Modules        future CRM / Accounting / Inventory / Machines / HR
config/            config.php (written by installer), core.php (rule book), modules.php (menus)
database/sql/      every SQL file
resources/views    PHP templates (layouts, pages, e-mails)
routes/web.php     all URLs
storage/           logs, saved e-mails, install lock
```
