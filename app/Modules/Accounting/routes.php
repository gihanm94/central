<?php
declare(strict_types=1);

use App\Modules\Accounting\Controllers\AccountingController;
use App\Modules\Accounting\Controllers\CompanyController;
use App\Modules\Accounting\Controllers\CustomerController;
use App\Modules\Accounting\Controllers\ErpController;
use App\Modules\Accounting\Controllers\InetController;
use App\Modules\Accounting\Controllers\InvoiceController;
use App\Modules\Accounting\Controllers\LogController;
use App\Modules\Accounting\Controllers\OrderController;
use App\Modules\Accounting\Controllers\ProductController;
use App\Modules\Accounting\Controllers\SellerController;

/** @var App\Core\Support\Router $r  (inside the 'auth' group of routes/web.php) */

$r->get('/accounting', [AccountingController::class, 'index']);

// ERP connection (administrators)
$r->get('/accounting/erp', [ErpController::class, 'index']);
$r->post('/accounting/erp/connection', [ErpController::class, 'saveConnection']);
$r->post('/accounting/erp/endpoints', [ErpController::class, 'saveEndpoints']);
$r->post('/accounting/erp/login', [ErpController::class, 'login']);
$r->post('/accounting/erp/test', [ErpController::class, 'test']);
$r->post('/accounting/erp/run', [ErpController::class, 'run']);
$r->get('/accounting/erp/status', [ErpController::class, 'status']);

// Copies of ERP data (read only) and the e-tax page
foreach (['customers' => CustomerController::class, 'orders' => OrderController::class, 'invoices' => InvoiceController::class, 'products' => ProductController::class, 'sellers' => SellerController::class, 'inet' => InetController::class] as $path => $controller) {
    $r->get("/accounting/{$path}", [$controller, 'index']);
    $r->get("/accounting/{$path}/export", [$controller, 'export']);
    $r->get("/accounting/{$path}/{id}", [$controller, 'show']);
    $r->get("/accounting/{$path}/{id}/download", [$controller, 'download']);
}
// one record from the ERP again (admins) and the hand-filled seller fields
foreach (['customers' => CustomerController::class, 'orders' => OrderController::class, 'invoices' => InvoiceController::class, 'products' => ProductController::class, 'sellers' => SellerController::class] as $path => $controller) {
    $r->post("/accounting/{$path}/{id}/sync", [$controller, 'syncRow']);
}
$r->get('/accounting/sellers/{id}/edit', [SellerController::class, 'edit']);
$r->post('/accounting/sellers/{id}', [SellerController::class, 'update']);
$r->get('/accounting/inet/candidates', [InetController::class, 'candidates']);
$r->post('/accounting/inet/generate', [InetController::class, 'generate']);
$r->get('/accounting/inet/job/{job}', [InetController::class, 'job']);
$r->post('/accounting/inet/{id}/sync', [InetController::class, 'sync']);
$r->post('/accounting/inet/{id}/regenerate', [InetController::class, 'regenerate']);
$r->post('/accounting/inet/{id}/delete', [InetController::class, 'destroy']);
$r->post('/accounting/inet/bulk-delete', [InetController::class, 'bulkDestroy']);
$r->post('/accounting/inet/{id}/{doc}/send', [InetController::class, 'send']);
$r->post('/accounting/inet/{id}/{doc}/fetch', [InetController::class, 'fetch']);
$r->get('/accounting/inet/{id}/{doc}/file/{kind}', [InetController::class, 'file']);

// Our company (seller on the e-tax documents) — administrators
$r->get('/accounting/company', [CompanyController::class, 'edit']);
$r->post('/accounting/company', [CompanyController::class, 'save']);

// Logs (files, not the database) — administrators; /tail is also used by the Generate dialog
$r->get('/accounting/logs', [LogController::class, 'index']);
$r->get('/accounting/logs/tail', [LogController::class, 'tail']);
$r->get('/accounting/logs/download', [LogController::class, 'download']);
$r->post('/accounting/logs/clear', [LogController::class, 'clear']);
