<?php
declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\CRM\Support\Ui;

class IndustryController extends LookupController
{
    protected string $resource = 'crm_settings';
    protected string $table = 'industries';
    protected string $type = 'industry';
    protected string $singular = 'industry';
    protected string $plural = 'industries';
    protected string $base = '/crm/settings/industries';
    protected string $icon = 'building';
    protected string $orderBy = 't.name';

    protected function fields(?array $row): array
    {
        return [
            'name'  => ['label' => __('Name'), 'rules' => 'required|max:120', 'example' => 'Manufacturing'],
            'color' => ['label' => __('Colour'), 'type' => 'color', 'rules' => 'required|max:9', 'default' => '#2a78d6', 'example' => '#2a78d6'],
        ];
    }

    protected function columns(): array
    {
        return [
            'name'  => ['label' => __('Industry'), 'primary' => true, 'render' => fn ($r) => Ui::chip($r['name'], $r['color'])],
            'leads' => ['label' => __('Leads'), 'render' => fn ($r) => (string) \App\Core\Support\DB::scalar('SELECT count(*) FROM lead_industries WHERE industry_id = ?', [$r['id']], 'crm')],
        ];
    }

    protected function searchable(): array { return ['t.name']; }
}
