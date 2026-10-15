<?php
declare(strict_types=1);

use App\Modules\CRM\Api\CrmApi;

/** @var App\Core\Support\Router $r  CRM endpoints of the mobile API (Bearer token; loaded only for /api/v1/crm/...). */

$r->get('/api/v1/crm/stats', [CrmApi::class, 'stats']);
$r->get('/api/v1/crm/search', [CrmApi::class, 'search']);
$r->get('/api/v1/crm/options', [CrmApi::class, 'options']);
$r->get('/api/v1/crm/leads', [CrmApi::class, 'leads']);
$r->post('/api/v1/crm/leads', [CrmApi::class, 'createLead']);
$r->get('/api/v1/crm/leads/{id}', [CrmApi::class, 'lead']);
$r->get('/api/v1/crm/contacts', [CrmApi::class, 'contacts']);
$r->post('/api/v1/crm/contacts', [CrmApi::class, 'createContact']);
$r->get('/api/v1/crm/contacts/{id}', [CrmApi::class, 'contact']);
$r->get('/api/v1/crm/opportunities', [CrmApi::class, 'opportunities']);
$r->post('/api/v1/crm/opportunities', [CrmApi::class, 'createOpportunity']);
$r->get('/api/v1/crm/opportunities/{id}', [CrmApi::class, 'opportunity']);
$r->get('/api/v1/crm/activities', [CrmApi::class, 'activities']);
$r->post('/api/v1/crm/activities', [CrmApi::class, 'createActivity']);
$r->get('/api/v1/crm/activities/{id}', [CrmApi::class, 'activity']);
$r->post('/api/v1/crm/activities/{id}', [CrmApi::class, 'updateActivity']);
$r->post('/api/v1/crm/activities/{id}/status', [CrmApi::class, 'activityStatus']);
$r->get('/api/v1/crm/tasks', [CrmApi::class, 'tasks']);
$r->get('/api/v1/crm/tasks/{id}', [CrmApi::class, 'task']);
$r->post('/api/v1/crm/tasks/{id}/status', [CrmApi::class, 'taskStatus']);
