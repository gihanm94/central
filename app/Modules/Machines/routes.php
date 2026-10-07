<?php
declare(strict_types=1);

use App\Modules\Machines\Controllers\CalibrationController;
use App\Modules\Machines\Controllers\ChecklistController;
use App\Modules\Machines\Controllers\FileController;
use App\Modules\Machines\Controllers\MachineController;
use App\Modules\Machines\Controllers\MaintenanceController;
use App\Modules\Machines\Controllers\QuestionController;
use App\Modules\Machines\Controllers\RegisterController;
use App\Modules\Machines\Controllers\ScanController;
use App\Modules\Machines\Controllers\TypeController;

/** @var App\Core\Support\Router $r  (inside the 'auth' group of routes/web.php) */

$r->get('/machines', [ScanController::class, 'home']);

// Standard list / form screens
// Scan, look-ups and the machine a QR label opens
$r->get('/machines/scan', [ScanController::class, 'scan']);
$r->get('/machines/lookup', [ScanController::class, 'lookup']);
$r->get('/machines/find', [ScanController::class, 'find']);
$r->get('/machines/m/{code}', [ScanController::class, 'machine']);

// Checklists: new check form, approvals, list (no generic add/import)
$r->get('/machines/checklists', [ChecklistController::class, 'index']);
$r->get('/machines/checklists/new', [ChecklistController::class, 'create']);
$r->post('/machines/checklists', [ChecklistController::class, 'store']);
$r->post('/machines/checklists/bulk-delete', [ChecklistController::class, 'bulkDestroy']);
$r->get('/machines/checklists/export', [ChecklistController::class, 'export']);
$r->get('/machines/checklists/{id}', [ChecklistController::class, 'show']);
$r->post('/machines/checklists/{id}/approve', [ChecklistController::class, 'approve']);
$r->post('/machines/checklists/{id}/delete', [ChecklistController::class, 'destroy']);

// Maintenance: do a round
$r->get('/machines/maintenance/{id}/perform', [MaintenanceController::class, 'perform']);
$r->post('/machines/maintenance/{id}/perform', [MaintenanceController::class, 'complete']);

// Machines: a few extra pages must come before /machines/machines/{id}
$r->get('/machines/file', [FileController::class, 'show']);
$r->get('/machines/label', [MachineController::class, 'labels']);
$r->post('/machines/machines/{id}/responsible', [MachineController::class, 'changeResponsible']);

foreach (['machines' => MachineController::class, 'register' => RegisterController::class, 'maintenance' => MaintenanceController::class, 'calibration' => CalibrationController::class, 'types' => TypeController::class, 'questions' => QuestionController::class] as $path => $controller) {
    $r->get("/machines/{$path}", [$controller, 'index']);
    $r->get("/machines/{$path}/create", [$controller, 'create']);
    $r->post("/machines/{$path}", [$controller, 'store']);
    $r->get("/machines/{$path}/export", [$controller, 'export']);
    $r->get("/machines/{$path}/template", [$controller, 'template']);
    $r->post("/machines/{$path}/import", [$controller, 'import']);
    $r->post("/machines/{$path}/bulk-delete", [$controller, 'bulkDestroy']);
    $r->get("/machines/{$path}/{id}", [$controller, 'show']);
    $r->get("/machines/{$path}/{id}/edit", [$controller, 'edit']);
    $r->post("/machines/{$path}/{id}", [$controller, 'update']);
    $r->post("/machines/{$path}/{id}/delete", [$controller, 'destroy']);
    $r->get("/machines/{$path}/{id}/download", [$controller, 'download']);
}
