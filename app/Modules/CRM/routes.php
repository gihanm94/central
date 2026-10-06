<?php
declare(strict_types=1);

use App\Core\Support\Router;
use App\Modules\CRM\Controllers\ActivityController;
use App\Modules\CRM\Controllers\CampaignController;
use App\Modules\CRM\Controllers\ContactController;
use App\Modules\CRM\Controllers\CurrencyController;
use App\Modules\CRM\Controllers\DataController;
use App\Modules\CRM\Controllers\DashboardController;
use App\Modules\CRM\Controllers\IndustryController;
use App\Modules\CRM\Controllers\LeadController;
use App\Modules\CRM\Controllers\LeadSourceController;
use App\Modules\CRM\Controllers\OpportunityController;
use App\Modules\CRM\Controllers\PipelineController;
use App\Modules\CRM\Controllers\ProductController;
use App\Modules\CRM\Controllers\ProjectController;
use App\Modules\CRM\Controllers\RecordController;
use App\Modules\CRM\Controllers\SearchController;
use App\Modules\CRM\Controllers\StageController;
use App\Modules\CRM\Controllers\TargetController;
use App\Modules\CRM\Controllers\TaskController;
use App\Modules\CRM\Controllers\TeamController;

/** @var Router $r  (inside the 'auth' group of routes/web.php) */

$r->get('/crm', [DashboardController::class, 'index']);
$r->get('/crm/dashboard', [DashboardController::class, 'index']);

// Pipeline board, dropdown search, stage and target settings
$r->get('/crm/pipeline', [PipelineController::class, 'index']);
$r->get('/crm/pipeline/cards', [PipelineController::class, 'cards']);
$r->post('/crm/pipeline/move', [PipelineController::class, 'move']);
$r->get('/crm/search/{kind}', [SearchController::class, 'find']);
$r->get('/crm/settings/stages', [StageController::class, 'index']);
$r->post('/crm/settings/stages', [StageController::class, 'save']);
$r->get('/crm/settings/targets', [TargetController::class, 'index']);
$r->post('/crm/settings/targets', [TargetController::class, 'save']);

// Admin data tools: import / export Excel, CSV, SQL with column mapping
$r->get('/crm/data', [DataController::class, 'index']);
$r->post('/crm/data/export', [DataController::class, 'export']);
$r->post('/crm/data/upload', [DataController::class, 'upload']);
$r->get('/crm/data/map', [DataController::class, 'map']);
$r->post('/crm/data/run', [DataController::class, 'run']);
$r->post('/crm/data/discard', [DataController::class, 'discard']);
$r->post('/crm/data/mappings/{id}/delete', [DataController::class, 'deleteMapping']);

// Team: see one person's records, hand them to somebody else, change many at once
$r->get('/crm/team', [TeamController::class, 'index']);
$r->post('/crm/team/reassign', [TeamController::class, 'reassign']);
$r->post('/crm/team/handover', [TeamController::class, 'handover']);
$r->post('/crm/team/status', [TeamController::class, 'status']);

// Standard screens: list, new, detail, edit, delete, export, import, template, download
$crm = [
    'leads' => LeadController::class, 'contacts' => ContactController::class, 'opportunities' => OpportunityController::class,
    'campaigns' => CampaignController::class, 'activities' => ActivityController::class,
    'projects' => ProjectController::class, 'tasks' => TaskController::class,
    'settings/industries' => IndustryController::class, 'settings/lead-sources' => LeadSourceController::class,
    'settings/products' => ProductController::class, 'settings/currencies' => CurrencyController::class,
];
foreach ($crm as $path => $controller) {
    $r->get("/crm/{$path}", [$controller, 'index']);
    $r->get("/crm/{$path}/create", [$controller, 'create']);
    $r->post("/crm/{$path}", [$controller, 'store']);
    $r->get("/crm/{$path}/export", [$controller, 'export']);
    $r->get("/crm/{$path}/template", [$controller, 'template']);
    $r->post("/crm/{$path}/import", [$controller, 'import']);
    $r->post("/crm/{$path}/bulk-delete", [$controller, 'bulkDestroy']);
    $r->get("/crm/{$path}/{id}", [$controller, 'show']);
    $r->get("/crm/{$path}/{id}/edit", [$controller, 'edit']);
    $r->post("/crm/{$path}/{id}", [$controller, 'update']);
    $r->post("/crm/{$path}/{id}/delete", [$controller, 'destroy']);
    $r->get("/crm/{$path}/{id}/download", [$controller, 'download']);
}
$r->get('/crm/settings', [DashboardController::class, 'settings']);

// Comments, files and sharing on any record: /crm/{leads|contacts|opportunities|campaigns|activities}/{id}/…
$r->post('/crm/{kind}/{id}/comments', [RecordController::class, 'comment']);
$r->post('/crm/{kind}/{id}/comments/{cid}/delete', [RecordController::class, 'deleteComment']);
$r->post('/crm/{kind}/{id}/files', [RecordController::class, 'upload']);
$r->get('/crm/{kind}/{id}/files/{fid}', [RecordController::class, 'download']);
$r->post('/crm/{kind}/{id}/files/{fid}/delete', [RecordController::class, 'deleteFile']);
$r->post('/crm/{kind}/{id}/owner', [TeamController::class, 'owner']);
$r->post('/crm/{kind}/{id}/shares', [RecordController::class, 'share']);
$r->post('/crm/{kind}/{id}/shares/{sid}/delete', [RecordController::class, 'unshare']);

// Opportunity progress
$r->post('/crm/opportunities/{id}/stage', [OpportunityController::class, 'moveStage']);
$r->post('/crm/opportunities/{id}/steps', [OpportunityController::class, 'addStep']);
$r->post('/crm/opportunities/{id}/steps/{sid}/done', [OpportunityController::class, 'doneStep']);
$r->post('/crm/opportunities/{id}/steps/{sid}/delete', [OpportunityController::class, 'deleteStep']);

// Task quick status
$r->post('/crm/tasks/{id}/status', [TaskController::class, 'setStatus']);

// Activity quick status
$r->post('/crm/activities/{id}/status', [ActivityController::class, 'setStatus']);
