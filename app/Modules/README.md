# Modules

Each module (CRM, Accounting, Inventory, Machines, HR) lives in its own folder here
and its own PostgreSQL database (tables already created by the installer).

To add one, e.g. CRM:

1. `app/Modules/CRM/Controllers/CustomerController.php` — extend
   `App\Core\Http\Controllers\ResourceController`, set `$resource = 'crm.customers'`,
   and query with `DB::select($sql, $params, 'crm')` (third argument = connection).
   Or override `select()`/`query()` to use the `crm` connection.
2. `app/Modules/CRM/routes.php` — register `/crm`, `/crm/customers` … and require it
   at the bottom of `routes/web.php`.
3. `config/modules.php` — set `'enabled' => true` and fill the `menu` array.
   The module switcher and the CRM sidebar appear automatically.
4. Add the permission rows so roles can be granted access:
   `INSERT INTO permissions (module, key, label, sort) VALUES ('crm', 'crm.customers', 'Customers', 200);`
   Then tick the boxes in Roles & access.
5. Log with `Activity::log('created', 'customer', $id, $name, '…', [], $ownerId, module: 'crm')`
   to get the activity entry and e-mails like the core screens.
