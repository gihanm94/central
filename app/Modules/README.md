# Modules

Every folder here with a `module.php` is a module (CRM, Accounting, Machines …). The app finds them
by itself, so you add a module by dropping in its folder. You don't edit `routes/web.php` or
`public/index.php`.

```
app/Modules/<Name>/
    module.php        manifest: key, name, icon, home, db, menu, dashboards, hooks
    routes.php        the module's URLs, all under /<key>/...
    Controllers/      extend App\Core\Http\Controllers\Controller (or ResourceController)
    Support/          the module's own classes
    views/            optional: view('<key>::page') loads views/page.php from here
```

Folders whose name starts with `_` are never loaded. `_Template` is a working starter.

## A module's errors stay inside the module

| What breaks                          | What happens                                                                 |
|--------------------------------------|------------------------------------------------------------------------------|
| `module.php` has an error            | That module's pages answer "temporarily unavailable" (503). Everything else works. |
| `routes.php` has an error            | Same. Routes are loaded only for the module the URL belongs to (`/crm/...` loads only CRM's). |
| One of its SQL migrations fails      | Same, and the migration is retried every minute. Only a **core** migration failure stops the app. |
| A hook throws (`tick`, `calendar.events` …) | Logged and skipped; other modules' hooks still run and the page still loads. |
| A controller throws on one page      | Only that page shows an error (as before).                                    |

Every failure is written to `storage/logs/php-error.log` and shown on **Administration → Modules**
(`/modules`, admins only), with the file, line and message. The module switcher shows a warning icon
next to it. Once you've fixed the file, press **Try again** (or simply open the module's pages; a
successful load clears the warning).

## Add a new module

1. Copy `_Template` to `app/Modules/Inventory` (no leading `_`).
2. In every file, replace `example` / `Example` with your key / name (`inventory`, `Inventory`).
   The namespace becomes `App\Modules\Inventory\...`.
3. `module.php`: fill in `name`, `icon`, `menu` and `db` (a key of `config/config.php` → `db.databases`).
4. Tables: put SQL in `database/sql/migrations/<db>/2026_11_01_001_inventory.sql`. It runs on the next page load.
5. Permissions: add rows so roles can be granted access (a migration in `database/sql/migrations/core/`):
   `INSERT INTO permissions (module, key, label, sort) VALUES ('inventory', 'inventory_items.view', 'Items', 200);`
   Then tick the boxes in Roles & access. Protect routes with `['perm:inventory_items,view']`.
6. Log with `Activity::log('created', 'item', $id, $name, '…', [], $ownerId, module: 'inventory')`.

To switch a module off without deleting it, set `'inventory' => ['enabled' => false]` in `config/modules.php`.

## Rules that keep modules independent

* **Never `use App\Modules\<Other>\...`** from another module or from Core. Shared helpers live in
  `App\Core\Support` (e.g. `Ui::badge()`, `Ui::person()`). A module may extend them, like `CRM\Support\Ui`.
* When Core needs something from modules, it calls a **hook**, never a class. Current hooks:
  * `tick()`: about once a minute while someone is signed in (notification feed poll).
  * `request()`: on every request to the module's own URLs.
  * `error(Throwable $e)`: an uncaught error on one of the module's pages.
  * `calendar.events($user, $from, $to, $mine)` returns `['events' => [...], 'linked' => [googleId => true]]` for `/calendar`.

  To add a hook, call `Modules::hook('name', [$args])` in Core and add `'name' => fn (...) => ...` to
  the modules that answer it.
* Use the module's own database connection: `DB::select($sql, $params, '<db>')`.
