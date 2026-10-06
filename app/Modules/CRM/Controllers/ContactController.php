<?php
declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\Upload;
use App\Core\Support\ValidationException;
use App\Modules\CRM\Support\Catalog;
use App\Modules\CRM\Support\Ui;

/** A person at a lead (company). Can have several mobile numbers. */
class ContactController extends CrmController
{
    protected string $resource = 'crm_contacts';
    protected string $table = 'contacts';
    protected string $type = 'contact';
    protected string $entity = 'contact';
    protected string $codePrefix = 'CT';
    protected string $singular = 'contact';
    protected string $plural = 'contacts';
    protected string $base = '/crm/contacts';
    protected string $icon = 'user';
    protected string $orderBy = 't.name_en, t.id';

    protected function select(): string
    {
        return 'SELECT t.*, l.name_en AS lead_name, l.name_th AS lead_name_th, l.image AS lead_image, s.name AS source_name FROM contacts t
                LEFT JOIN leads l ON l.id = t.lead_id
                LEFT JOIN lead_sources s ON s.id = t.lead_source_id';
    }

    protected function label(array $row): string { return (string) $row['name_en']; }

    protected function searchable(): array
    {
        return ['t.name_en', 't.name_th', 't.code', 't.email', 't.phone', 'l.name_en', '(SELECT string_agg(m.number, \' \') FROM contact_mobiles m WHERE m.contact_id = t.id)'];
    }

    protected function filters(): array
    {
        $sources = array_column(DB::select('SELECT id, name FROM lead_sources ORDER BY name', [], 'crm'), 'name', 'id');

        return ['source' => ['label' => __('All sources'), 'options' => $sources, 'column' => 't.lead_source_id']] + $this->commonFilters();
    }

    protected function fields(?array $row): array
    {
        $sources = array_column(DB::select('SELECT id, name FROM lead_sources ORDER BY name', [], 'crm'), 'name', 'id');

        return [
            '_person'    => ['section' => __('Person')],
            'lead_id'    => ['label' => __('Company (lead)'), 'type' => 'select', 'options' => $this->leadOptions($row), 'rich' => true, 'rules' => 'required', 'default' => $this->prefill('lead_id'),
                             'display' => fn ($r) => $r['lead_name'] ?? null, 'href' => fn ($r) => $r['lead_id'] ? '/crm/leads/'.$r['lead_id'] : null],
            'salutation' => ['label' => __('Salutation'), 'type' => 'select', 'options' => Catalog::salutations(), 'rules' => 'nullable', 'search' => false],
            'name_en'    => ['label' => __('Name (English)'), 'rules' => 'required|max:160', 'example' => 'Somsak Rattanakul'],
            'name_th'    => ['label' => __('Name (Thai)'), 'rules' => 'nullable|max:160'],
            'job_title'  => ['label' => __('Job title'), 'rules' => 'nullable|max:120'],
            'department' => ['label' => __('Their department'), 'rules' => 'nullable|max:120', 'help' => __('The department this person works in at their own company.')],
            'code'       => ['label' => __('Code'), 'rules' => 'nullable|max:30', 'help' => __('Leave empty to number it automatically.')],
            'lead_source_id' => ['label' => __('Lead source'), 'type' => 'select', 'options' => $sources, 'rules' => 'nullable',
                             'display' => fn ($r) => $r['source_name'] ?? null],

            '_reach'     => ['section' => __('How to reach them')],
            'email'      => ['label' => __('E-mail'), 'type' => 'email', 'rules' => 'nullable|email|max:190'],
            'phone'      => ['label' => __('Phone'), 'rules' => 'nullable|max:40'],
            'phone_ext'  => ['label' => __('Phone ext.'), 'rules' => 'nullable|max:10'],
            'fax'        => ['label' => __('Fax'), 'rules' => 'nullable|max:40'],
            'mobiles'    => ['label' => __('Mobile numbers'), 'type' => 'custom', 'partial' => 'crm/fields/mobiles', 'span' => 2, 'table' => false, 'rules' => 'nullable',
                             'example' => '0812345678; 0898765432', 'help' => __('Add as many as you need; mark the main one.')],
            'additional_contact' => ['label' => __('Other ways to contact (LINE, WeChat …)'), 'type' => 'textarea', 'span' => 2, 'rules' => 'nullable|max:1000'],

            '_address'   => ['section' => __('Address')],
            'address'    => ['label' => __('Address'), 'type' => 'textarea', 'span' => 2, 'rules' => 'nullable|max:1000'],
            'city'       => ['label' => __('City'), 'rules' => 'nullable|max:80'],
            'province'   => ['label' => __('Province'), 'rules' => 'nullable|max:80'],
            'country'    => ['label' => __('Country'), 'rules' => 'nullable|max:80', 'default' => 'Thailand'],
            'zipcode'    => ['label' => __('Postal code'), 'rules' => 'nullable|max:20'],

            '_more'      => ['section' => __('More')],
            'description' => ['label' => __('Notes'), 'type' => 'richtext', 'span' => 2, 'rules' => 'nullable'],
            'avatar'     => ['label' => __('Photo'), 'type' => 'file', 'import' => false, 'span' => 2, 'rules' => 'nullable', 'help' => __('PNG, JPG or WebP, up to 2 MB.')],
        ] + $this->ownershipFields($row);
    }

    protected function columns(): array
    {
        return [
            'name_en'   => ['label' => __('Name'), 'primary' => true, 'sort' => 't.name_en', 'render' => fn ($r) => Ui::person(trim(($r['salutation'] ? $r['salutation'].' ' : '').$r['name_en']), $r['name_th'] ?: $r['job_title'], $r['avatar'], '/crm/contacts/'.$r['id'], 'rounded-full')],
            'lead'      => ['label' => __('Lead'), 'sort' => 'l.name_en', 'tone' => 'amber', 'render' => fn ($r) => $r['lead_id'] ? Ui::person((string) $r['lead_name'], $r['lead_name_th'], $r['lead_image'], '/crm/leads/'.$r['lead_id']) : Ui::dash()],
            'job_title' => ['label' => __('Position'), 'sort' => 't.job_title', 'render' => fn ($r) => e($r['job_title'] ?: '—')],
            'email'     => ['label' => __('E-mail'), 'sort' => 't.email', 'render' => fn ($r) => $r['email'] ? '<a href="mailto:'.e($r['email']).'" class="hover:text-signal-700">'.e($r['email']).'</a>' : Ui::dash()],
            'mobile'    => ['label' => __('Contact'), 'tone' => 'sky', 'render' => function ($r) {
                $list = $r['mobile_list'] ?? [];
                $main = $list ? $list[0]['number'] : ($r['phone'] ?: null);
                if (! $main) { return Ui::dash(); }
                $more = count($list) - 1;

                return '<span class="tabular-nums">'.e($main).'</span>'.($more > 0 ? ' <span class="badge bg-white/80 text-graphite-800">+'.$more.'</span>' : '').($r['phone'] && $list ? '<span class="block text-xs tabular-nums text-steel">'.e($r['phone']).'</span>' : '');
            }],
            'created'   => $this->createdColumn(),
        ];
    }

    private function photo(array $r, string $size): string
    {
        return ! empty($r['avatar'])
            ? '<img src="'.e(upload_url($r['avatar'])).'" alt="" class="'.$size.' shrink-0 rounded-full object-cover">'
            : '<span class="'.$size.' inline-flex shrink-0 items-center justify-center rounded-full bg-graphite-700 text-xs font-semibold text-white">'.e(initials((string) $r['name_en'])).'</span>';
    }

    protected function hydrateMore(array $rows): array
    {
        $ids  = array_column($rows, 'id');
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $all  = DB::select("SELECT * FROM contact_mobiles WHERE contact_id IN ({$in}) ORDER BY is_primary DESC, sort, id", $ids, 'crm');
        foreach ($rows as &$r) {
            $r['mobile_list'] = array_values(array_filter($all, fn ($m) => (int) $m['contact_id'] === (int) $r['id']));
            $r['mobiles']     = implode('; ', array_map(fn ($m) => $m['number'].($m['ext'] ? ' #'.$m['ext'] : '').($m['label'] ? ' ('.$m['label'].')' : ''), $r['mobile_list']));
        }
        unset($r);

        return $rows;
    }

    protected function importDefaults(array $raw): array
    {
        $raw = parent::importDefaults($raw);
        if (isset($raw['mobiles']) && is_string($raw['mobiles'])) {
            $raw['mobiles'] = array_map(fn ($n) => ['number' => trim($n)], array_filter(explode(';', $raw['mobiles']), fn ($n) => trim($n) !== ''));
        }

        return $raw;
    }

    protected function prepareMore(array $data, ?array $existing): array
    {
        // Mobile numbers: [{number, ext, label}], main one picked with the radio button
        $list = [];
        foreach ((array) ($data['mobiles'] ?? []) as $idx => $m) {
            $num = trim((string) ($m['number'] ?? ''));
            if ($num === '') {
                continue;
            }
            if (! preg_match('/^[0-9+()\-.\s]{3,40}$/', $num)) {
                throw new ValidationException(['mobiles' => __('":number" is not a valid mobile number.', ['number' => $num])]);
            }
            $list[$idx] = ['number' => $num, 'ext' => mb_substr(trim((string) ($m['ext'] ?? '')), 0, 10) ?: null, 'label' => mb_substr(trim((string) ($m['label'] ?? '')), 0, 30) ?: null];
        }
        if (count($list) > 10) {
            throw new ValidationException(['mobiles' => __('A contact can have up to 10 mobile numbers.')]);
        }
        $primary = Request::input('mobile_primary');
        $primary = isset($list[$primary]) ? (int) $primary : array_key_first($list);
        foreach ($list as $idx => &$m) {
            $m['is_primary'] = $idx === $primary;
        }
        unset($m);
        $list = array_values($list);
        $data['mobiles'] = $list;

        if ($file = Request::file('avatar')) {
            $data['avatar'] = Upload::image($file, 'crm');
            if ($existing && $existing['avatar']) {
                Upload::delete($existing['avatar']);
            }
        } else {
            unset($data['avatar']);
        }

        return $data;
    }

    protected function afterSave(int $id, array $data, ?array $existing): void
    {
        DB::exec('DELETE FROM contact_mobiles WHERE contact_id = ?', [$id], 'crm');
        foreach ($data['mobiles'] as $i => $m) {
            DB::insert('contact_mobiles', ['contact_id' => $id, 'number' => $m['number'], 'ext' => $m['ext'], 'label' => $m['label'], 'is_primary' => $m['is_primary'], 'sort' => $i], 'crm', '');
        }
    }

    protected function headerMedia(array $row): string { return $this->photo($row, 'size-14'); }

    protected function subtitleFor(array $row): string
    {
        return implode(' · ', array_filter([$row['code'], $row['job_title'], $row['lead_name'], $row['name_th']]));
    }

    protected function badges(array $row): array
    {
        return $row['source_name'] ? [Ui::chip(__('Source').': '.$row['source_name'])] : [];
    }

    protected function rowLinks(array $row): array
    {
        $q = 'contact_id='.$row['id'].($row['lead_id'] ? '&lead_id='.$row['lead_id'] : '');
        $links = [];
        if (can('crm_opportunities', 'create')) { $links[] = ['url' => '/crm/opportunities/create?'.$q, 'label' => __('Add opportunity'), 'icon' => 'target']; }
        if (can('crm_activities', 'create'))    { $links[] = ['url' => '/crm/activities/create?'.$q, 'label' => __('Log activity'), 'icon' => 'clock']; }

        return $links;
    }

    protected function showMain(array $row): string
    {
        $id   = (int) $row['id'];
        $opps = array_map(fn ($o) => ['href' => '/crm/opportunities/'.$o['id'], 'title' => $o['name'], 'meta' => Ui::money($o['amount'], $o['currency']), 'badge' => Ui::stageBadge($o['opportunity_stage'])],
            $this->visibleRows('opportunity', 't.id, t.name, t.amount, t.currency, t.opportunity_stage', 't.contact_id = ?', [$id], 't.id DESC'));
        $acts = array_map(fn ($a) => ['href' => '/crm/activities/'.$a['id'], 'title' => $a['topic'], 'meta' => __(Catalog::ACTIVITY_TYPES[$a['activity_type']] ?? $a['activity_type']).($a['start_at'] ? ' · '.format_date($a['start_at'], 'd M Y H:i') : ''),
            'badge' => Ui::badge(__(Catalog::ACTIVITY_STATUSES[$a['status']] ?? $a['status']), $a['status'] === 'DONE' ? 'success' : ($a['status'] === 'CANCELLED' ? 'neutral' : 'info'))],
            $this->visibleRows('activity', 't.id, t.topic, t.activity_type, t.start_at, t.status', 't.contact_id = ?', [$id], 't.start_at DESC NULLS LAST, t.id DESC'));
        $q = 'contact_id='.$id.($row['lead_id'] ? '&lead_id='.$row['lead_id'] : '');

        return $this->relatedPanel(__('Opportunities'), $opps, can('crm_opportunities', 'create') ? '/crm/opportunities/create?'.$q : null, __('No opportunities yet.'), __('Add opportunity'))
            .$this->relatedPanel(__('Activities'), $acts, can('crm_activities', 'create') ? '/crm/activities/create?'.$q : null, __('No activities yet.'), __('Log activity'));
    }
}
