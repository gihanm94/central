<?php
declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\Upload;
use App\Modules\CRM\Support\Catalog;
use App\Modules\CRM\Support\Ui;

/** A lead is the company / account. Contacts, opportunities and activities hang off it. */
class LeadController extends CrmController
{
    protected array $remote = ['company_id' => 'leads'];

    protected string $resource = 'crm_leads';
    protected string $table = 'leads';
    protected string $type = 'lead';
    protected string $entity = 'lead';
    protected string $codePrefix = 'LD';
    protected string $singular = 'lead';
    protected string $plural = 'leads';
    protected string $base = '/crm/leads';
    protected string $icon = 'building';
    protected string $orderBy = 't.updated_at DESC, t.id DESC';

    protected function label(array $row): string { return (string) $row['name_en']; }

    protected function searchable(): array { return ['t.name_en', 't.name_th', 't.code', 't.tax_id', 't.phone', 't.mobile', 't.city']; }

    protected function filters(): array
    {
        $industries = array_column(DB::select('SELECT id, name FROM industries ORDER BY name', [], 'crm'), 'name', 'id');
        $sources    = array_column(DB::select('SELECT id, name FROM lead_sources ORDER BY name', [], 'crm'), 'name', 'id');

        return [
            'industry' => ['label' => __('All industries'), 'options' => $industries, 'sql' => 'EXISTS (SELECT 1 FROM lead_industries x WHERE x.lead_id = t.id AND x.industry_id = ?)'],
            'source'   => ['label' => __('All sources'), 'options' => $sources, 'sql' => 'EXISTS (SELECT 1 FROM lead_lead_sources x WHERE x.lead_id = t.id AND x.lead_source_id = ?)'],
        ] + $this->commonFilters();
    }

    protected function fields(?array $row): array
    {
        $industries = array_column(DB::select('SELECT id, name FROM industries ORDER BY name', [], 'crm'), 'name', 'id');
        $sources    = array_column(DB::select('SELECT id, name FROM lead_sources ORDER BY name', [], 'crm'), 'name', 'id');

        return [
            '_company'   => ['section' => __('Company')],
            'name_en'    => ['label' => __('Name (English)'), 'rules' => 'required|max:200', 'example' => 'Siam Packaging Co., Ltd.'],
            'name_th'    => ['label' => __('Name (Thai)'), 'rules' => 'nullable|max:200'],
            'code'       => ['label' => __('Code'), 'rules' => 'nullable|max:30', 'help' => __('Leave empty to number it automatically.')],
            'tax_id'     => ['label' => __('Tax ID'), 'rules' => 'nullable|max:30'],
            'revenue'    => ['label' => __('Annual revenue'), 'type' => 'number', 'rules' => 'nullable|numeric'],
            'company_id' => $this->remoteField('company_id', $row, ['label' => __('Parent company'), 'rules' => 'nullable',
                             'display' => fn ($r) => $r['company_name'] ?? null, 'href' => fn ($r) => $r['company_id'] ? '/crm/leads/'.$r['company_id'] : null]),
            'is_register'   => ['label' => __('Registered company'), 'type' => 'checkbox', 'help' => __('Registered with the Department of Business Development.')],
            'is_government' => ['label' => __('Government organization'), 'type' => 'checkbox'],

            '_class'     => ['section' => __('Industry and source')],
            'industries'    => ['label' => __('Industries'), 'type' => 'multi', 'choices' => $industries, 'span' => 2, 'table' => false, 'import' => false, 'rules' => 'nullable',
                                'empty' => __('Add industries under CRM → Settings.'), 'display' => fn ($r) => $r['industries'] ?? null],
            'lead_sources'  => ['label' => __('Lead sources'), 'type' => 'multi', 'choices' => $sources, 'span' => 2, 'table' => false, 'import' => false, 'rules' => 'nullable',
                                'empty' => __('Add lead sources under CRM → Settings.'), 'display' => fn ($r) => $r['lead_sources'] ?? null],

            '_numbers'   => ['section' => __('Phone numbers')],
            'phone'      => ['label' => __('Phone'), 'rules' => 'nullable|max:40'],
            'mobile'     => ['label' => __('Mobile'), 'rules' => 'nullable|max:40'],
            'fax'        => ['label' => __('Fax'), 'rules' => 'nullable|max:40'],

            '_address'   => ['section' => __('Address')],
            'address'    => ['label' => __('Address'), 'type' => 'textarea', 'span' => 2, 'rules' => 'nullable|max:1000'],
            'city'       => ['label' => __('City'), 'rules' => 'nullable|max:80'],
            'province'   => ['label' => __('Province'), 'rules' => 'nullable|max:80'],
            'country'    => ['label' => __('Country'), 'rules' => 'nullable|max:80', 'default' => 'Thailand'],
            'zipcode'    => ['label' => __('Postal code'), 'rules' => 'nullable|max:20'],

            '_more'      => ['section' => __('More')],
            'description' => ['label' => __('Notes'), 'type' => 'richtext', 'span' => 2, 'rules' => 'nullable'],
            'image'      => ['label' => __('Logo'), 'type' => 'file', 'import' => false, 'span' => 2, 'rules' => 'nullable', 'help' => __('PNG, JPG or WebP, up to 2 MB.')],
        ] + $this->ownershipFields($row);
    }

    protected function columns(): array
    {
        return [
            'name_en'    => ['label' => __('Name'), 'primary' => true, 'sort' => 't.name_en', 'render' => fn ($r) => Ui::person($r['name_en'], $r['name_th'] ?: $r['code'], $r['image'], '/crm/leads/'.$r['id'])],
            'code'       => ['label' => __('Code'), 'sort' => 't.code', 'render' => fn ($r) => e($r['code'] ?? '—')],
            'industries' => ['label' => __('Industries'), 'render' => fn ($r) => Ui::chips($r['industry_list'] ?? [])],
            'phone'      => ['label' => __('Phone'), 'sort' => 't.phone', 'render' => fn ($r) => e($r['phone'] ?: ($r['mobile'] ?: '—'))],
            'place'      => ['label' => __('Location'), 'sort' => 't.city', 'render' => fn ($r) => e(implode(', ', array_filter([$r['city'], $r['country']])) ?: '—')],
            'created'    => $this->createdColumn(),
        ];
    }

    private function logo(array $r, string $size): string
    {
        return ! empty($r['image'])
            ? '<img src="'.e(upload_url($r['image'])).'" alt="" class="'.$size.' shrink-0 rounded-md bg-white object-contain ring-1 ring-graphite-900/10">'
            : '<span class="'.$size.' inline-flex shrink-0 items-center justify-center rounded-md bg-graphite-900/6 text-xs font-semibold text-graphite-700">'.e(initials((string) $r['name_en'])).'</span>';
    }

    /** industries / sources (names for display, ids for the form) */
    protected function hydrateMore(array $rows): array
    {
        $ids = array_column($rows, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $ind = DB::select("SELECT x.lead_id, i.id, i.name, i.color FROM lead_industries x JOIN industries i ON i.id = x.industry_id WHERE x.lead_id IN ({$in}) ORDER BY i.name", $ids, 'crm');
        $src = DB::select("SELECT x.lead_id, s.id, s.name, s.color FROM lead_lead_sources x JOIN lead_sources s ON s.id = x.lead_source_id WHERE x.lead_id IN ({$in}) ORDER BY s.name", $ids, 'crm');
        $parentIds = array_filter(array_column($rows, 'company_id'));
        $parents = $parentIds ? array_column(DB::select('SELECT id, name_en FROM leads WHERE id IN ('.implode(',', array_fill(0, count($parentIds), '?')).')', array_values($parentIds), 'crm'), 'name_en', 'id') : [];

        foreach ($rows as &$r) {
            $r['industry_list'] = array_values(array_filter($ind, fn ($x) => (int) $x['lead_id'] === (int) $r['id']));
            $r['source_list']   = array_values(array_filter($src, fn ($x) => (int) $x['lead_id'] === (int) $r['id']));
            $r['industry_ids']     = array_column($r['industry_list'], 'id');
            $r['lead_source_ids']  = array_column($r['source_list'], 'id');
            $r['industries']    = implode(', ', array_column($r['industry_list'], 'name'));
            $r['lead_sources']  = implode(', ', array_column($r['source_list'], 'name'));
            $r['company_name']  = $parents[$r['company_id'] ?? 0] ?? null;
        }
        unset($r);

        return $rows;
    }

    protected function prepareMore(array $data, ?array $existing): array
    {
        if (isset($data['company_id']) && $existing && (int) $data['company_id'] === (int) $existing['id']) {
            $data['company_id'] = null;
        }
        foreach (['industries' => 'industries', 'lead_sources' => 'lead_sources'] as $key => $table) {
            $valid      = array_map('intval', array_column(DB::select("SELECT id FROM {$table}", [], 'crm'), 'id'));
            $data[$key] = array_values(array_intersect(array_map('intval', (array) ($data[$key] ?? [])), $valid));
        }
        if ($file = Request::file('image')) {
            $data['image'] = Upload::image($file, 'crm');
            if ($existing && $existing['image']) {
                Upload::delete($existing['image']);
            }
        } else {
            unset($data['image']);
        }

        return $data;
    }

    protected function afterSave(int $id, array $data, ?array $existing): void
    {
        foreach ([['lead_industries', 'industry_id', 'industries'], ['lead_lead_sources', 'lead_source_id', 'lead_sources']] as [$table, $col, $key]) {
            DB::exec("DELETE FROM {$table} WHERE lead_id = ?", [$id], 'crm');
            foreach ($data[$key] as $v) {
                DB::exec("INSERT INTO {$table} (lead_id, {$col}) VALUES (?, ?) ON CONFLICT DO NOTHING", [$id, $v], 'crm');
            }
        }
    }

    protected function headerMedia(array $row): string { return $this->logo($row, 'size-14'); }

    protected function subtitleFor(array $row): string
    {
        return implode(' · ', array_filter([$row['code'], $row['name_th'], implode(', ', array_filter([$row['city'], $row['country']]))]));
    }

    protected function badges(array $row): array
    {
        $b = [];
        foreach ($row['industry_list'] as $i) { $b[] = Ui::chip($i['name'], $i['color']); }
        foreach ($row['source_list'] as $s) { $b[] = Ui::chip(__('Source').': '.$s['name'], $s['color']); }
        if (filter_var($row['is_government'], FILTER_VALIDATE_BOOL)) { $b[] = Ui::badge(__('Government'), 'violet'); }
        if (filter_var($row['is_register'], FILTER_VALIDATE_BOOL)) { $b[] = Ui::badge(__('Registered'), 'success'); }

        return $b;
    }

    protected function rowLinks(array $row): array
    {
        $links = [];
        if (can('crm_contacts', 'create'))      { $links[] = ['url' => '/crm/contacts/create?lead_id='.$row['id'], 'label' => __('Add contact'), 'icon' => 'user']; }
        if (can('crm_opportunities', 'create')) { $links[] = ['url' => '/crm/opportunities/create?lead_id='.$row['id'], 'label' => __('Add opportunity'), 'icon' => 'target']; }

        return $links;
    }

    protected function showMain(array $row): string
    {
        $id = (int) $row['id'];

        $contacts = array_map(fn ($c) => ['href' => '/crm/contacts/'.$c['id'], 'title' => $c['name_en'], 'meta' => implode(' · ', array_filter([$c['job_title'], $c['email']])), 'badge' => ''],
            $this->visibleRows('contact', 't.id, t.name_en, t.job_title, t.email', 't.lead_id = ?', [$id], 't.name_en'));
        $opps = array_map(fn ($o) => ['href' => '/crm/opportunities/'.$o['id'], 'title' => $o['name'], 'meta' => Ui::money($o['amount'], $o['currency']), 'badge' => Ui::stageBadge($o['opportunity_stage'])],
            $this->visibleRows('opportunity', 't.id, t.name, t.amount, t.currency, t.opportunity_stage', 't.lead_id = ?', [$id], 't.id DESC'));
        $acts = array_map(fn ($a) => ['href' => '/crm/activities/'.$a['id'], 'title' => $a['topic'], 'meta' => __(Catalog::ACTIVITY_TYPES[$a['activity_type']] ?? $a['activity_type']).($a['start_at'] ? ' · '.format_date($a['start_at'], 'd M Y H:i') : ''),
            'badge' => Ui::badge(__(Catalog::ACTIVITY_STATUSES[$a['status']] ?? $a['status']), $a['status'] === 'DONE' ? 'success' : ($a['status'] === 'CANCELLED' ? 'neutral' : 'info'))],
            $this->visibleRows('activity', 't.id, t.topic, t.activity_type, t.start_at, t.status', 't.lead_id = ?', [$id], 't.start_at DESC NULLS LAST, t.id DESC'));

        return $this->relatedPanel(__('Contacts'), $contacts, can('crm_contacts', 'create') ? '/crm/contacts/create?lead_id='.$id : null, __('No contacts yet.'), __('Add contact'))
            .$this->relatedPanel(__('Opportunities'), $opps, can('crm_opportunities', 'create') ? '/crm/opportunities/create?lead_id='.$id : null, __('No opportunities yet.'), __('Add opportunity'))
            .$this->relatedPanel(__('Activities'), $acts, can('crm_activities', 'create') ? '/crm/activities/create?lead_id='.$id : null, __('No activities yet.'), __('Log activity'));
    }

    protected function statCards(): array
    {
        return [
            $this->card(__('Leads'), $this->figure('count(*)'), null, 'neutral', 'building'),
            $this->card(__('New in 30 days'), $this->figure('count(*)', "t.created_at >= now() - interval '30 days'"), null, 'info', 'plus'),
            $this->card(__('No contact yet'), $this->figure('count(*)', 'NOT EXISTS (SELECT 1 FROM contacts c WHERE c.lead_id = t.id AND c.deleted_at IS NULL)'), __('Add a person to talk to'), 'warn', 'user'),
            $this->card(__('With open opportunity'), $this->figure('count(*)', "EXISTS (SELECT 1 FROM opportunities o WHERE o.lead_id = t.id AND o.deleted_at IS NULL AND o.opportunity_stage IN ('QUALIFICATION','SURVEY_PROPOSAL','EVALUATION_TESTING','NEGOTIATION','ON_HOLD'))"), null, 'success', 'target'),
        ];
    }
}
