# Mobile app & API (v1)

JSON API for the Acme mobile app: CRM, Machine Checklist and **read-only** Accounting.
Base URL: `https://<your-server>/api/v1`. All examples assume that prefix.

## Security model

| What | How |
|---|---|
| Who may use it | Only people whose role (or own permissions) holds **Mobile app & API** (Roles & access). No role has it after installation. Administrators always may. |
| What they may do | Exactly what the website allows them: same permissions (`crm_leads.view` …) and the same row visibility (own / department / shared …). Accounting is read-only for everyone. |
| Transport | HTTPS only (refused with `403 https_required` unless `app.env` is `local`). HSTS is sent. |
| Sign-in | E-mail + password. Same lockout as the website (5 failures → locked, `429 locked` + `Retry-After`). At most 10 attempts per minute per IP and per e-mail. Every sign-in sends the person a "new sign-in" alert. |
| Tokens | `access_token` (random 256-bit, **15 min**) + `refresh_token` (random 256-bit, rotates on every use, expires after **30 days** without use). A sign-in ends after **90 days** in any case. The server stores only SHA-256 hashes. |
| Stolen token | Using a refresh token that was already used signs that device out at once and warns the person. |
| Ending sign-ins | The device's sign-in ends when the person signs out (`/auth/logout`), signs it out from another device or from Profile → Mobile devices, changes password, uses "Sign out all others", loses the permission, or is deactivated. An administrator can sign out any device (Administration → Mobile app & API). |
| Abuse | 120 requests per minute per device (`429 rate_limited` + `Retry-After`). Bodies up to 12 MB. POST only as `application/json` or `multipart/form-data`. |
| No cookies | The API never uses the website's session cookie, so CSRF does not apply. A browser session cannot call the API. |
| Audit | Every request is in `api_log` (person, device, path, status, time, IP; kept 90 days) and shown on Administration → Mobile app & API. Changes also appear in the normal activity log. |
| Isolation | Each module's API lives in its own `app/Modules/<Name>/api.php`. If one breaks, only its URLs answer `503 module_unavailable`. |

Settings (`config/config.php`, all optional):

```php
'api' => [
    'enabled'          => true,
    'require_https'    => true,   // ignored when app.env = local
    'access_minutes'   => 15,     // 1–60
    'refresh_days'     => 30,
    'max_days'         => 90,
    'rate_per_minute'  => 120,
    'login_per_minute' => 10,     // per IP
],
```

## Conventions

- Send `Authorization: Bearer <access_token>` on every request except login and refresh.
- `Accept-Language: th` or `en` picks the language of messages before sign-in. After sign-in, the person's own language is used.
- **Lists** answer `{"data": [...], "meta": {"page", "per_page", "total", "pages"}}`. Use `?page=` (from 1) and `?per_page=` (1–100, default 25). `?q=` searches.
- **Errors** answer `{"error": {"code": "...", "message": "...", "fields": {...}}}`. `message` is ready to show; `fields` is present for `422 invalid`.
- Every answer has an `X-Request-Id` header. Quote it when reporting a problem.

| Status | `code` | App should |
|---|---|---|
| 401 | `token_expired` | call `/auth/refresh`, then retry once |
| 401 | `invalid_token`, `session_ended`, `no_api_access`, `unauthenticated` | go to the sign-in screen (also when `/auth/refresh` answers any 401) |
| 401 | `invalid_credentials` | sign-in only: show the message |
| 403 | `no_api_access` | sign-in only: the account may not use the app |
| 403 | `forbidden`, `not_waiting_for_you` | show the message |
| 404 | `not_found`, `machine_not_found`, `pdf_not_available` | show the message |
| 422 | `invalid` | show `fields` next to the inputs |
| 429 | `rate_limited`, `locked` | wait `Retry-After` seconds |
| 503 | `module_unavailable` | "temporarily unavailable" |

## Auth

```
POST /auth/login     {"email", "password", "device_name": "Somchai's iPhone", "device_id": "<install id, optional>", "platform": "ios|android|other"}
  → {"token_type": "Bearer", "access_token", "expires_in", "refresh_token", "refresh_expires_in", "device_id", "user": {...profile...}}
POST /auth/refresh   {"refresh_token"} → new pair (store both; the old refresh token is now dead)
POST /auth/logout    → this device is signed out
GET  /me             → profile + "modules" and "permissions" the app may show
GET  /me/devices     → my signed-in devices ("current": true for this one)
POST /me/devices/{id}/revoke
GET  /me/notifications   ?unread=1
```

Keep tokens in the Keychain (iOS) or EncryptedSharedPreferences/Keystore (Android), never in plain storage.
**Refresh one at a time.** If two requests get `token_expired` together, let one call `/auth/refresh` and make the others wait for it. A refresh token used twice looks exactly like a stolen copy, and the device is signed out.
Signing in again with the same `device_id` replaces that install's old sign-in.

## CRM

```
GET  /crm/stats                      pipeline by stage, won this year, my activities and tasks, this year's sales target
GET  /crm/search?q=                  leads, contacts, opportunities (5 each)
GET  /crm/leads?q=&mine=1            GET /crm/leads/{id}  (+ its contacts, opportunities, latest activities)
GET  /crm/contacts?q=&lead_id=       GET /crm/contacts/{id}  (+ mobile numbers)
GET  /crm/opportunities?q=&stage=&open=1&lead_id=&mine=1      GET /crm/opportunities/{id}
GET  /crm/activities?q=&status=&type=&from=YYYY-MM-DD&to=YYYY-MM-DD&mine=1&lead_id=
GET  /crm/activities/{id}
POST /crm/activities                 log a call / meeting (below)
POST /crm/activities/{id}            change it (fields left out keep their value)
POST /crm/activities/{id}/status     {"status": "PLANNED|DONE|CANCELLED"}
GET  /crm/tasks?q=&status=&open=1&mine=1&project_id=          GET /crm/tasks/{id}
POST /crm/tasks/{id}/status          {"status": "TODO|IN_PROGRESS|BLOCKED|DONE|CANCELLED"}
```

`/crm/stats`: `pipeline` and `won_this_year` have one row per currency; `base_amount` / `base_weighted` are the same
converted to `base_currency`, so rows can be added up. `target` (only with the CRM dashboard permission; your department, or all departments for
whole-company roles; left out for someone without a department). `actual` is invoiced revenue when the department is
linked to Accounting (`source: "accounting"`), else opportunities won (`"crm"`), in the base currency:

```json
"target": {"year": 2026, "currency": "THB", "source": "crm", "total": 12000000, "actual": 7350000,
           "quarters": [{"q": 1, "target": 3000000, "actual": 2100000}, …]}
```

Log a call (same rules as the website form: `lead_id` required, linked lead / contact / opportunity must be
visible to you, you become the owner; HTML in `description` is cleaned):

```json
{"activity_type": "CALL", "topic": "Follow-up on quotation", "status": "DONE", "start_at": "2026-10-15 10:00",
 "call_direction": "OUTBOUND", "call_duration": 12, "lead_id": 7, "contact_id": 8, "description": "<p>Asked for a revised price</p>"}
```

`activity_type`: CALL, MEETING, EMAIL, TASK. Meetings use `meeting_type` (ONSITE, CUSTOMER_SITE, ONLINE), `meeting_duration` and `meeting_location`.
`notify_me` + `notify_before` (0, 5, 15, 30, 60, 1440 minutes) set a reminder. Activities with a time go to the owner's Google Calendar when it is connected.

## Machine Checklist

```
POST /machines/scan                  {"text": "<what the camera read>"}  → the machine (JSON QR, /machines/m/{code} link or plain code)
GET  /machines/find?q=               search machines in use
GET  /machines/m/{code}              machine, people, today's checklist, last checks, next maintenance / calibration
POST /machines/m/{code}/check        save today's check (below)
GET  /machines/checklists            my checks;  ?pending=1 → re-checks waiting for my approval
GET  /machines/checklists/{id}
POST /machines/checklists/{id}/approve   {"note": "..."}  (supervisor, then manager)
GET  /machines/due?days=30           maintenance and calibration of my machines, overdue or due soon
```

Save a check. `items` and their `id`s come from `GET /machines/m/{code}` → `data.checklist.items`. A `choice` item takes OK / NG / NA, and NG needs a `remark`. A `text` item takes text.

```json
{"machine_status": "OPERATIONAL", "answers": {"12": {"answer": "OK"}, "13": {"answer": "42 mm"}, "14": {"answer": "NG", "remark": "Belt worn"}}, "machine_note": "..."}
```

With a photo, send `multipart/form-data` with the same fields (`answers[12][answer]=OK` …) plus `image` (PNG, JPG or WebP, up to 2 MB).
A re-check by the responsible person goes to the supervisor (then the manager) for approval, exactly as on the website.

## Accounting (read only)

```
GET /accounting/stats                       overview blocks the person may see
GET /accounting/customers?q=                GET /accounting/customers/{id}  (+ links: invoices, orders, Inets, billing notes, CRM leads with the same tax id)
GET /accounting/products?q=                 GET /accounting/products/{id}
GET /accounting/orders?q=&customer_id=&credit=0|1      GET /accounting/orders/{id}  (+ rows)
GET /accounting/invoices?q=&customer_id=    GET /accounting/invoices/{number}  (+ lines)
GET /accounting/sellers?q=
GET /accounting/inets?q=&credit=1&send=sent|unsent&customer_id=      GET /accounting/inets/{id}
GET /accounting/inets/{id}/pdf/{388|t01|81}            stored PDF;  ?signed=1 → the copy signed by INET
GET /accounting/billing-notes?q=&status=pending|overdue|paid|complete&customer_id=    GET /accounting/billing-notes/{id}
GET /accounting/billing-notes/{id}/pdf
```

Each Inet lists its `documents` (tax invoice 388 + receipt t01, or credit note 81) with `generated`, `sent`, `signed` and ready-made `pdf_url` / `signed_pdf_url`.
Download with the same `Authorization` header. The answer is `application/pdf` as an attachment.
**The API never generates, sends, syncs or rebuilds anything.** A PDF that was never made answers `404 pdf_not_available`.
Generating Inets, billing notes and tax reports stays on the website.

## Adding endpoints to a module

Create `app/Modules/<Name>/api.php` (routes under `/api/v1/<key>/...`) and a controller extending `App\Core\Api\Controller`.
It provides `need()` for permission checks, `paged()`, `q()`/`like()` for safe search, `input([...])` for whitelisted input and `pick()` for whitelisted output.
To reuse a website save action with all its rules, call `Kernel::delegate(fn () => (new XController())->store(), $input)`.
