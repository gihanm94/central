<?php
declare(strict_types=1);

use App\Core\Support\Request;
use App\Modules\Accounting\Erp\Schedule;
use App\Modules\Accounting\Support\DepartmentInvoices;
use App\Modules\Accounting\Support\Log;

/*
 | Accounting module manifest — read by App\Core\Support\Modules at boot.
 | 'enabled' here is the default; config/modules.php may override it.
 */
return [
    'key' => 'accounting',
    'name' => 'Accounting', 'icon' => 'calc', 'home' => '/accounting', 'enabled' => true, 'db' => 'accounting',
    'dashboards' => ['overview' => ['label' => 'Accounting overview', 'url' => '/accounting']],
    'menu' => [
        ['heading' => 'Overview'],
        ['label' => 'Accounting overview', 'url' => '/accounting', 'icon' => 'chart'],
        ['heading' => 'ERP data'],
        ['label' => 'Customers', 'url' => '/accounting/customers', 'icon' => 'users', 'permission' => 'accounting_customers.view'],
        ['label' => 'Orders',    'url' => '/accounting/orders',    'icon' => 'cart',  'permission' => 'accounting_orders.view'],
        ['label' => 'Invoices',  'url' => '/accounting/invoices',  'icon' => 'doc',   'permission' => 'accounting_invoices.view'],
        ['label' => 'Products',  'url' => '/accounting/products',  'icon' => 'box',   'permission' => 'accounting_products.view'],
        ['label' => 'Sellers',   'url' => '/accounting/sellers',   'icon' => 'users', 'permission' => 'accounting_sellers.view'],
        ['heading' => 'E-Tax'],
        ['label' => 'Inets',     'url' => '/accounting/inet',      'icon' => 'doc',   'permission' => 'accounting_inet.view'],
        ['heading' => 'Billing'],
        ['label' => 'Billing notes', 'url' => '/accounting/billing', 'icon' => 'doc', 'permission' => 'accounting_billing.view'],
        ['heading' => 'Setup'],
        ['label' => 'Company',        'url' => '/accounting/company', 'icon' => 'building', 'admin_only' => true],
        ['label' => 'Notifications',  'url' => '/accounting/notify', 'icon' => 'bell', 'admin_only' => true],
        ['label' => 'ERP connection', 'url' => '/accounting/erp', 'icon' => 'cog', 'admin_only' => true],
        ['label' => 'Logs',           'url' => '/accounting/logs', 'icon' => 'tasks', 'admin_only' => true],
    ],
    'hooks' => [
        // Every request to an /accounting page: start the ERP / billing schedule when it is due.
        'request' => function () { if (Request::method() === 'GET') { Schedule::kick(); } },
        // An uncaught error on an /accounting page also goes to the accounting log.
        'error'   => fn (\Throwable $e) => Log::exception('app', $e, 'Uncaught on '.Request::method().' '.Request::path()),
        // ERP → Invoices in User Management: stamped Inets of the person's department (the core page never reads this database itself)
        'erp.invoices'    => fn ($user, array $filters) => DepartmentInvoices::list($user, $filters),
        'erp.invoice_pdf' => fn ($user, int $id, string $doc) => DepartmentInvoices::pdf($user, $id, $doc),
        // ERP → Billing notes in User Management: billing notes of the person's department
        'erp.billing'     => fn ($user, array $filters) => App\Modules\Accounting\Support\DepartmentBilling::list($user, $filters),
        // CRM lead page: ERP customers, invoices and quotes by tax ID (the CRM checks who may see the lead)
        'customer.by_tax'   => fn (string $taxId) => App\Modules\Accounting\Support\CustomerLink::byTax($taxId),
        'customer.get'      => fn (string $id) => App\Modules\Accounting\Support\CustomerLink::customer($id),
        'customer.invoices' => fn (string $taxId) => App\Modules\Accounting\Support\CustomerLink::invoices($taxId),
        'customer.quotes'   => fn (string $customerId) => App\Modules\Accounting\Support\CustomerLink::quotes($customerId),
        'erp.billing_pdf' => fn ($user, int $id) => App\Modules\Accounting\Support\DepartmentBilling::pdf($user, $id),
        // Management overview: revenue, top customers, sellers and departments between two dates
        'revenue.ranking' => fn (string $from, string $to) => App\Modules\Accounting\Support\Ranking::get($from, $to),
    ],
];
