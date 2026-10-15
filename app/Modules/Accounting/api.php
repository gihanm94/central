<?php
declare(strict_types=1);

use App\Modules\Accounting\Api\AccountingApi;

/**
 * @var App\Core\Support\Router $r  Accounting endpoints of the mobile API — READ ONLY (GET only; Bearer token;
 * loaded only for /api/v1/accounting/...). Generating or sending Inets, billing notes or tax reports is website-only.
 */

$r->get('/api/v1/accounting/stats', [AccountingApi::class, 'stats']);
$r->get('/api/v1/accounting/customers', [AccountingApi::class, 'customers']);
$r->get('/api/v1/accounting/customers/{id}', [AccountingApi::class, 'customer']);
$r->get('/api/v1/accounting/products', [AccountingApi::class, 'products']);
$r->get('/api/v1/accounting/products/{id}', [AccountingApi::class, 'product']);
$r->get('/api/v1/accounting/orders', [AccountingApi::class, 'orders']);
$r->get('/api/v1/accounting/orders/{id}', [AccountingApi::class, 'order']);
$r->get('/api/v1/accounting/invoices', [AccountingApi::class, 'invoices']);
$r->get('/api/v1/accounting/invoices/{number}', [AccountingApi::class, 'invoice']);
$r->get('/api/v1/accounting/sellers', [AccountingApi::class, 'sellers']);
$r->get('/api/v1/accounting/inets', [AccountingApi::class, 'inets']);
$r->get('/api/v1/accounting/inets/{id}', [AccountingApi::class, 'inet']);
$r->get('/api/v1/accounting/inets/{id}/pdf/{doc}', [AccountingApi::class, 'inetPdf']);
$r->get('/api/v1/accounting/billing-notes', [AccountingApi::class, 'billingNotes']);
$r->get('/api/v1/accounting/billing-notes/{id}', [AccountingApi::class, 'billingNote']);
$r->get('/api/v1/accounting/billing-notes/{id}/pdf', [AccountingApi::class, 'billingPdf']);
