<?php
declare(strict_types=1);

use App\Modules\Sheets\Controllers\AdminController;
use App\Modules\Sheets\Controllers\SyncController;

/** @var App\Core\Support\Router $r  (signed-in users only; loaded only for /sheets/... URLs) */

$r->get('/sheets', [SyncController::class, 'index']);
$r->get('/sheets/new', [SyncController::class, 'create']);
$r->post('/sheets', [SyncController::class, 'store']);
$r->get('/sheets/{id}/edit', [SyncController::class, 'edit']);
$r->post('/sheets/{id}', [SyncController::class, 'update']);
$r->post('/sheets/{id}/delete', [SyncController::class, 'destroy']);
$r->post('/sheets/{id}/run', [SyncController::class, 'run']);
$r->post('/sheets/{id}/toggle', [SyncController::class, 'toggle']);

// Google accounts (several per person) and the spreadsheet picker
$r->get('/sheets/connect', [SyncController::class, 'connect']);
$r->get('/sheets/connect/callback', [SyncController::class, 'callback']);
$r->post('/sheets/accounts/{id}/disconnect', [SyncController::class, 'disconnect']);
$r->get('/sheets/accounts/{id}/files', [SyncController::class, 'files']);
$r->get('/sheets/accounts/{id}/tabs', [SyncController::class, 'tabs']);

// Administrators
$r->get('/sheets/tables', [AdminController::class, 'tables']);
$r->get('/sheets/tables/edit', [AdminController::class, 'editTable']);
$r->post('/sheets/tables/save', [AdminController::class, 'saveTable']);
$r->get('/sheets/settings', [AdminController::class, 'settings']);
$r->post('/sheets/settings', [AdminController::class, 'saveSettings']);
