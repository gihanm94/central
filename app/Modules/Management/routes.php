<?php
declare(strict_types=1);

use App\Modules\Management\Controllers\KpiCalculatorController;
use App\Modules\Management\Controllers\OverviewController;

/** @var App\Core\Support\Router $r  (signed-in users only; loaded only for /management/... URLs) */

$r->group(['perm:management,view'], function ($r) {
    $r->get('/management', [OverviewController::class, 'index']);
    $r->get('/management/crm', [OverviewController::class, 'crm']);

    // KPI calculator: yearly appraisal score out of 100
    $r->get('/management/kpi-calculator', [KpiCalculatorController::class, 'index']);
    $r->get('/management/kpi-calculator/export', [KpiCalculatorController::class, 'export']);
    $r->get('/management/kpi-calculator/{id}', [KpiCalculatorController::class, 'show']);
    $r->post('/management/kpi-calculator/{id}', [KpiCalculatorController::class, 'calculate']);
});
