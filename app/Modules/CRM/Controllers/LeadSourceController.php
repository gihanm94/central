<?php
declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\CRM\Support\Ui;

class LeadSourceController extends LookupController
{
    protected string $resource = 'crm_settings';
    protected string $table = 'lead_sources';
    protected string $type = 'lead_source';
    protected string $singular = 'lead source';
    protected string $plural = 'lead sources';
    protected string $base = '/crm/settings/lead-sources';
    protected string $icon = 'target';
    protected string $orderBy = 't.name';

    protected function fields(?array $row): array
    {
        return [
            'name'  => ['label' => __('Name'), 'rules' => 'required|max:120', 'example' => 'Website'],
            'color' => ['label' => __('Colour'), 'type' => 'color', 'rules' => 'required|max:9', 'default' => '#1baf7a', 'example' => '#1baf7a'],
        ];
    }

    protected function columns(): array
    {
        return [
            'name'  => ['label' => __('Lead source'), 'primary' => true, 'sort' => 't.name', 'render' => fn ($r) => Ui::chip($r['name'], $r['color'])],
            'leads' => ['label' => __('Leads'), 'render' => fn ($r) => (string) \App\Core\Support\DB::scalar('SELECT count(*) FROM lead_lead_sources WHERE lead_source_id = ?', [$r['id']], 'crm')],
        ];
    }

    protected function searchable(): array { return ['t.name']; }
}
