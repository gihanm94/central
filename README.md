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

### Files
Attachments are stored in `storage/attachments` (not reachable from the web) and downloaded through a permission check. Up to 10 MB each; pdf, images, office files, txt/csv, zip.

### Upgrading an existing install
Copy the files over; the next page load creates the CRM tables and permissions. If `crm_db` still has rows in the old CRM draft tables, the upgrade stops and asks you to empty them first.

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
