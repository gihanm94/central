<?php
declare(strict_types=1);

use App\Modules\Management\Controllers\KpiCalculatorController;
use App\Modules\Management\Controllers\EverbetterController;
use App\Modules\Management\Controllers\OilPriceController;
use App\Modules\Management\Controllers\OverviewController;
use App\Modules\Management\Controllers\SignatureController;
use App\Core\Http\Controllers\PdfSignLogController;

/** @var App\Core\Support\Router $r  (signed-in users only; loaded only for /management/... URLs) */

$r->group(['perm:management,view'], function ($r) {
    $r->get('/management', [OverviewController::class, 'index']);
    $r->get('/management/crm', [OverviewController::class, 'crm']);
    $r->get('/management/everbetter', [EverbetterController::class, 'index']);
    $r->get('/management/everbetter/projects/{id}', [EverbetterController::class, 'project']);
    $r->post('/management/everbetter/requests/{id}/acknowledge', [EverbetterController::class, 'acknowledge']);
    $r->post('/management/everbetter/requests/{id}/decide', [EverbetterController::class, 'decide']);
    $r->post('/management/everbetter/days', [EverbetterController::class, 'sessions']);
    $r->post('/management/everbetter/ranking', [EverbetterController::class, 'saveRanking']);
    $r->get('/management/oil-prices', [OilPriceController::class, 'index']);
    $r->post('/management/oil-prices/sync', [OilPriceController::class, 'sync']);
    $r->post('/management/oil-prices/history', [OilPriceController::class, 'history']);
    $r->post('/management/oil-prices/times', [OilPriceController::class, 'times']);

    // Member signatures (upload or draw) and the PDF signing log
    $r->get('/management/signatures', [SignatureController::class, 'index']);
    $r->get('/management/signatures/{id}/image', [SignatureController::class, 'image']);
    $r->post('/management/signatures/{id}', [SignatureController::class, 'save']);
    $r->post('/management/signatures/{id}/delete', [SignatureController::class, 'remove']);
    $r->get('/management/pdf-signs', [PdfSignLogController::class, 'index']);
    $r->get('/management/pdf-signs/{id}', [PdfSignLogController::class, 'show']);

    // KPI calculator: yearly appraisal score out of 100
    $r->get('/management/kpi-calculator', [KpiCalculatorController::class, 'index']);
    $r->get('/management/kpi-calculator/export', [KpiCalculatorController::class, 'export']);
    $r->get('/management/kpi-calculator/{id}', [KpiCalculatorController::class, 'show']);
    $r->post('/management/kpi-calculator/{id}', [KpiCalculatorController::class, 'calculate']);
});
