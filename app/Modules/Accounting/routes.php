<?php
declare(strict_types=1);

use App\Modules\Accounting\Controllers\AccountingController;
use App\Modules\Accounting\Controllers\ErpController;

/** @var App\Core\Support\Router $r  (inside the 'auth' group of routes/web.php) */

$r->get('/accounting', [AccountingController::class, 'index']);

// ERP connection (administrators)
$r->get('/accounting/erp', [ErpController::class, 'index']);
$r->post('/accounting/erp/connection', [ErpController::class, 'saveConnection']);
$r->post('/accounting/erp/endpoints', [ErpController::class, 'saveEndpoints']);
$r->post('/accounting/erp/login', [ErpController::class, 'login']);
$r->post('/accounting/erp/test', [ErpController::class, 'test']);
$r->post('/accounting/erp/run', [ErpController::class, 'run']);
