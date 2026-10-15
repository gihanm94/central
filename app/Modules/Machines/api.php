<?php
declare(strict_types=1);

use App\Modules\Machines\Api\MachinesApi;

/** @var App\Core\Support\Router $r  Machine Checklist endpoints of the mobile API (Bearer token; loaded only for /api/v1/machines/...). */

$r->post('/api/v1/machines/scan', [MachinesApi::class, 'scan']);
$r->get('/api/v1/machines/find', [MachinesApi::class, 'find']);
$r->get('/api/v1/machines/due', [MachinesApi::class, 'due']);
$r->get('/api/v1/machines/m/{code}', [MachinesApi::class, 'machine']);
$r->post('/api/v1/machines/m/{code}/check', [MachinesApi::class, 'check']);
$r->get('/api/v1/machines/checklists', [MachinesApi::class, 'checklists']);
$r->get('/api/v1/machines/checklists/{id}', [MachinesApi::class, 'record']);
$r->post('/api/v1/machines/checklists/{id}/approve', [MachinesApi::class, 'approve']);
