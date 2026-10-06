<?php
declare(strict_types=1);

use App\Modules\Accounting\Controllers\AccountingController;
use App\Modules\Accounting\Controllers\CustomerController;
use App\Modules\Accounting\Controllers\ErpController;
use App\Modules\Accounting\Controllers\InetController;
use App\Modules\Accounting\Controllers\InvoiceController;
use App\Modules\Accounting\Controllers\OrderController;
use App\Modules\Accounting\Controllers\ProductController;

/** @var App\Core\Support\Router $r  (inside the 'auth' group of routes/web.php) */

$r->get('/accounting', [AccountingController::class, 'index']);

// ERP connection (administrators)
$r->get('/accounting/erp', [ErpController::class, 'index']);
$r->post('/accounting/erp/connection', [ErpController::class, 'saveConnection']);
$r->post('/accounting/erp/endpoints', [ErpController::class, 'saveEndpoints']);
$r->post('/accounting/erp/login', [ErpController::class, 'login']);
$r->post('/accounting/erp/test', [ErpController::class, 'test']);
$r->post('/accounting/erp/run', [ErpController::class, 'run']);

// Copies of ERP data (read only) and the e-tax page
foreach (['customers' => CustomerController::class, 'orders' => OrderController::class, 'invoices' => InvoiceController::class, 'products' => ProductController::class, 'inet' => InetController::class] as $path => $controller) {
    $r->get("/accounting/{$path}", [$controller, 'index']);
    $r->get("/accounting/{$path}/export", [$controller, 'export']);
    $r->get("/accounting/{$path}/{id}", [$controller, 'show']);
    $r->get("/accounting/{$path}/{id}/download", [$controller, 'download']);
}
$r->get('/accounting/inet/candidates', [InetController::class, 'candidates']);
$r->post('/accounting/inet/generate', [InetController::class, 'generate']);
$r->post('/accounting/inet/{id}/sync', [InetController::class, 'sync']);
