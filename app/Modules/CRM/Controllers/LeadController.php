<?php
declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Modules;
use App\Core\Support\Request;
use App\Core\Support\Session;
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

    /** Industries and lead sources a lead can have at most (the picker stops there). */
    private const PICK_MAX = 5;

    /** true while a CSV / Excel import runs: the form's extra required fields are not required there */
    private bool $importing = false;

    public function import(): never
    {
        $this->importing = true;
        parent::import();
    }

    /** LD-yyyyMMddHHmmss + 3 digits (milliseconds), e.g. LD-20261016061037142; the next free one if two leads are made at the same moment. */
    protected function newCode(int $id): string
    {
        $n = (int) (new \DateTimeImmutable())->format('YmdHisv');
        while (DB::scalar('SELECT 1 FROM leads WHERE code = ?', ['LD-'.$n], 'crm')) { $n++; }

        return 'LD-'.$n;
    }

    /** The form: four steps with dots (Basic info · Industry & source · Address · Description), like the old lead form. */
    protected function meta(): array { return parent::meta() + ['wizard_style' => 'dots']; }

    protected function fields(?array $row): array
    {
        $pick = fn (string $table) => array_map(fn ($r) => ['label' => $r['name'], 'color' => $r['color']],
            array_column(DB::select("SELECT id, name, color FROM {$table} ORDER BY name", [], 'crm'), null, 'id'));
        $must = $this->importing ? 'nullable' : 'required';     // asked for in the form, optional in an import

        return [
            '_basic'     => ['section' => __('Basic info'), 'icon' => 'building', 'help' => __('Company name, contact details and registration status')],
            'image'      => ['label' => __('Logo'), 'type' => 'logo', 'import' => false, 'hide_show' => true, 'span' => 2, 'rules' => 'nullable', 'help' => __('PNG, JPG or WEBP · max 2 MB'), 'recommend' => __('Recommended: 256 × 256 px')],
            'name_en'    => ['label' => __('Name (English)'), 'rules' => 'required|max:200', 'placeholder' => __('e.g. ACME Corporation'), 'example' => 'Siam Packaging Co., Ltd.'],
            'name_th'    => ['label' => __('Name (Thai)'), 'rules' => $must.'|max:200', 'placeholder' => __('e.g. บริษัท ACME จำกัด')],
            'tax_id'     => ['label' => __('Tax ID'), 'rules' => 'nullable|max:30', 'placeholder' => __('13-digit tax number')],
            'revenue'    => ['label' => __('Annual revenue'), 'type' => 'number', 'rules' => 'nullable|numeric', 'placeholder' => __('e.g. 10,000,000')],
            'phone'      => ['label' => __('Phone'), 'type' => 'tel', 'rules' => 'nullable|max:40', 'placeholder' => __('e.g. 02 123 4567')],
            'mobile'     => ['label' => __('Mobile'), 'type' => 'tel', 'rules' => 'nullable|max:40', 'placeholder' => __('e.g. 081 234 5678')],
            'fax'        => ['label' => __('Fax'), 'rules' => 'nullable|max:40', 'placeholder' => '+66 2 xxx xxxx'],
            'sector'     => ['label' => __('Sector'), 'type' => 'select', 'options' => ['private' => __('Private'), 'government' => __('Government')], 'rules' => $must, 'default' => 'private', 'table' => false,
                             'display' => fn ($r) => filter_var($r['is_government'] ?? false, FILTER_VALIDATE_BOOL) ? __('Government') : __('Private')],
            // typed; when it is the name of a lead you can see, the lead page links to it
            'parent_company' => ['label' => __('Parent company'), 'rules' => 'nullable|max:200', 'placeholder' => __('Parent company reference'),
                             'href' => fn ($r) => $r['company_id'] ? '/crm/leads/'.$r['company_id'] : null],
            'company_id' => $this->remoteField('company_id', $row, ['label' => __('Parent company (link)'), 'rules' => 'nullable', 'hide_form' => true, 'hide_show' => true]),
            'registration' => ['label' => __('Registration status'), 'type' => 'select', 'options' => ['not_registered' => __('Not registered'), 'registered' => __('Registered')], 'rules' => $must, 'default' => 'not_registered', 'table' => false,
                             'help' => __('Registered with the Department of Business Development.'),
                             'display' => fn ($r) => filter_var($r['is_register'] ?? false, FILTER_VALIDATE_BOOL) ? __('Registered') : __('Not registered')],
            'code'       => ['label' => __('Code'), 'rules' => 'nullable|max:30', 'hide_form' => true],     // made when the lead is created (import may give one)

            '_class'     => ['section' => __('Industry & source'), 'icon' => 'cart', 'help' => __('Industry type and lead source')],
            'industries'   => ['label' => __('Industry'), 'type' => 'picker', 'choices' => $pick('industries'), 'max' => self::PICK_MAX, 'span' => 2, 'table' => false, 'import' => false, 'rules' => $must,
                               'search' => __('Search industries name'), 'group' => __('Industries'), 'add' => __('Add industry'), 'empty' => __('Click to add industry'),
                               'none' => __('Add industries under CRM → Settings.'), 'display' => fn ($r) => $r['industries'] ?? null],
            'lead_sources' => ['label' => __('Lead source'), 'type' => 'picker', 'choices' => $pick('lead_sources'), 'max' => self::PICK_MAX, 'span' => 2, 'table' => false, 'import' => false, 'rules' => $must,
                               'search' => __('Search lead sources'), 'group' => __('Lead sources'), 'add' => __('Add lead source'), 'empty' => __('Click to add lead source'),
                               'none' => __('Add lead sources under CRM → Settings.'), 'display' => fn ($r) => $r['lead_sources'] ?? null],

            '_address'   => ['section' => __('Address'), 'icon' => 'globe', 'help' => __('Location, city, province, country and postal code')],
            'address'    => ['label' => __('Street address'), 'type' => 'textarea', 'rows' => 2, 'span' => 2, 'rules' => 'nullable|max:1000', 'placeholder' => __('e.g. Sukhumvit Road')],
            'city'       => ['label' => __('City'), 'rules' => $must.'|max:80', 'placeholder' => __('e.g. Bangkok')],
            'province'   => ['label' => __('Province / State'), 'rules' => 'nullable|max:80', 'placeholder' => __('e.g. Bangkok')],
            'country'    => ['label' => __('Country'), 'rules' => $must.'|max:80', 'default' => 'Thailand', 'placeholder' => __('e.g. Thailand')],
            'zipcode'    => ['label' => __('Postal code'), 'rules' => 'nullable|max:20', 'placeholder' => __('e.g. 10110')],

            '_more'      => ['section' => __('Description'), 'icon' => 'list', 'help' => __('Rich text notes about this lead')],
            'description' => ['label' => __('Description'), 'type' => 'richtext', 'span' => 2, 'rules' => 'nullable', 'counter' => true],
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
            $r['industries_ids']   = $r['industry_ids'];          // what the form's industries / lead_sources pickers read
            $r['lead_sources_ids'] = $r['lead_source_ids'];
            $r['sector']        = filter_var($r['is_government'] ?? false, FILTER_VALIDATE_BOOL) ? 'government' : 'private';
            $r['registration']  = filter_var($r['is_register'] ?? false, FILTER_VALIDATE_BOOL) ? 'registered' : 'not_registered';
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
        foreach (['industries' => __('Industry'), 'lead_sources' => __('Lead source')] as $key => $label) {
            if (count($data[$key]) > self::PICK_MAX) {
                throw new \App\Core\Support\ValidationException([$key => __('Pick up to :n for :field.', ['n' => self::PICK_MAX, 'field' => $label])]);
            }
        }
        // Parent company typed in the form: linked to the lead of that name when there is exactly one the person can see
        if (array_key_exists('parent_company', $data)) {
            $name = trim((string) $data['parent_company']);
            $data['parent_company'] = $name !== '' ? $name : null;
            $data['company_id'] = null;
            if ($name !== '') {
                $v   = \App\Modules\CRM\Support\Access::visible($this->user(), 'l', 'lead');
                $ids = array_column(DB::select('SELECT l.id FROM leads l WHERE lower(l.name_en) = lower(?) AND l.deleted_at IS NULL AND '.$v['sql'].($existing ? ' AND l.id <> ?' : '').' LIMIT 2',
                    [$name, ...$v['params'], ...($existing ? [(int) $existing['id']] : [])], 'crm'), 'id');
                $data['company_id'] = count($ids) === 1 ? (int) $ids[0] : null;
            }
        } elseif (! empty($data['company_id']) && empty($data['parent_company'])) {
            $data['parent_company'] = DB::scalar('SELECT name_en FROM leads WHERE id = ?', [(int) $data['company_id']], 'crm') ?: null;   // import by parent id / name
        }
        // Sector and registration status are two choices in the form, two yes / no columns in the table
        if (($data['sector'] ?? null) !== null) { $data['is_government'] = $data['sector'] === 'government'; }
        if (($data['registration'] ?? null) !== null) { $data['is_register'] = $data['registration'] === 'registered'; }
        unset($data['sector'], $data['registration']);
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

    /** Lead page: Details, Opportunities, Contacts and Activities as tabs. */
    protected function showTabs(array $row): array
    {
        $id = (int) $row['id'];

        $contacts = array_map(fn ($c) => ['href' => '/crm/contacts/'.$c['id'], 'title' => $c['name_en'], 'meta' => implode(' · ', array_filter([$c['job_title'], $c['email']])), 'badge' => ''],
            $this->visibleRows('contact', 't.id, t.name_en, t.job_title, t.email', 't.lead_id = ?', [$id], 't.name_en'));
        $opps = array_map(fn ($o) => ['href' => '/crm/opportunities/'.$o['id'], 'title' => $o['name'], 'meta_html' => true, 'meta' => Ui::money($o['amount'], $o['currency']), 'badge' => Ui::stageBadge($o['opportunity_stage'])],
            $this->visibleRows('opportunity', 't.id, t.name, t.amount, t.currency, t.opportunity_stage', 't.lead_id = ?', [$id], 't.id DESC'));
        $acts = array_map(fn ($a) => ['href' => '/crm/activities/'.$a['id'], 'title' => $a['topic'], 'meta' => __(Catalog::ACTIVITY_TYPES[$a['activity_type']] ?? $a['activity_type']).($a['start_at'] ? ' · '.format_date($a['start_at'], 'd M Y H:i') : ''),
            'badge' => Ui::badge(__(Catalog::ACTIVITY_STATUSES[$a['status']] ?? $a['status']), $a['status'] === 'DONE' ? 'success' : ($a['status'] === 'CANCELLED' ? 'neutral' : 'info'))],
            $this->visibleRows('activity', 't.id, t.topic, t.activity_type, t.start_at, t.status', 't.lead_id = ?', [$id], 't.start_at DESC NULLS LAST, t.id DESC'));

        $tabs = [
            'opportunities' => ['label' => __('Opportunities'), 'count' => count($opps),
                'html' => $this->relatedPanel(__('Opportunities'), $opps, can('crm_opportunities', 'create') ? '/crm/opportunities/create?lead_id='.$id : null, __('No opportunities yet.'), __('Add opportunity'))],
            'contacts' => ['label' => __('Contacts'), 'count' => count($contacts),
                'html' => $this->relatedPanel(__('Contacts'), $contacts, can('crm_contacts', 'create') ? '/crm/contacts/create?lead_id='.$id : null, __('No contacts yet.'), __('Add contact'))],
            'activities' => ['label' => __('Activities'), 'count' => count($acts),
                'html' => $this->relatedPanel(__('Activities'), $acts, can('crm_activities', 'create') ? '/crm/activities/create?lead_id='.$id : null, __('No activities yet.'), __('Log activity'))],
        ];

        // With a tax ID: the ERP invoices with that tax ID, and the quotes of the connected ERP customer (data from Accounting)
        if (preg_replace('/\D/', '', (string) $row['tax_id']) !== '') {
            $erp = Modules::usable('accounting');
            $inv = $erp ? (Modules::hook('customer.invoices', [(string) $row['tax_id']], 'accounting')['accounting'] ?? null) : null;
            $tabs['invoices'] = ['label' => __('Invoices'), 'count' => $inv['total'] ?? null,
                'html' => partial('crm/panels/lead-invoices', ['row' => $row, 'data' => $inv])];
            $cid = $row['erp_customer_id'] ? (string) $row['erp_customer_id'] : null;
            $quotes = $erp && $cid ? (Modules::hook('customer.quotes', [$cid], 'accounting')['accounting'] ?? null) : null;
            $choices = $erp && ! $cid && Request::query('pick') === '1' ? (Modules::hook('customer.by_tax', [(string) $row['tax_id']], 'accounting')['accounting'] ?? []) : [];
            $tabs['quotes'] = ['label' => __('Quotes'), 'count' => $quotes !== null ? count($quotes) : null,
                'html' => partial('crm/panels/lead-quotes', ['row' => $row, 'quotes' => $quotes, 'erp' => $erp, 'choices' => $choices,
                    'canEdit' => can($this->resource, 'edit') && $this->canModify($row, 'edit')])];
        }

        return $tabs;
    }

    /**
     * Quotes tab → Connect: find the ERP customer with the lead's tax ID and keep its CustomerId on the lead.
     * One match connects at once; several open a list to choose from (customer_id then says which).
     */
    public function erpConnect(int $id): never
    {
        $row  = $this->findForChange($id, 'edit');
        $back = '/crm/leads/'.$id;
        if (! Modules::usable('accounting')) {
            back('error', __('The ERP data is not available right now (the Accounting module is off or unavailable).'));
        }
        if (preg_replace('/\D/', '', (string) $row['tax_id']) === '') {
            back('error', __('Add the tax ID to this lead first.'));
        }
        $found = Modules::hook('customer.by_tax', [(string) $row['tax_id']], 'accounting')['accounting'] ?? [];
        $pick  = (string) Request::input('customer_id', '');
        if ($pick !== '') {
            $found = array_values(array_filter($found, fn ($c) => $c['id'] === $pick));   // only a customer with this tax ID
        }
        if (! $found) {
            back('error', __('No ERP customer has the tax ID :tax.', ['tax' => $row['tax_id']]));
        }
        if (count($found) > 1) {
            redirect($back.'?pick=1#quotes');
        }
        $c = $found[0];
        DB::update('leads', ['erp_customer_id' => $c['id'], 'erp_customer_name' => $c['name'], 'erp_connected_at' => now(), 'erp_connected_by' => $this->user()->id, 'updated_at' => now()], ['id' => $id], 'crm');
        Activity::log('updated', 'lead', $id, $this->label($row), ['Connected lead ":label" to ERP customer :customer', ['label' => $this->label($row), 'customer' => trim(($c['code'] ? $c['code'].' ' : '').$c['name'])]],
            ['changes' => ['erp_customer' => ['from' => (string) ($row['erp_customer_name'] ?? ''), 'to' => $c['name'].' (#'.$c['id'].')']], '_url' => url($back)], $this->owner($row), notify: false, module: 'crm');
        Session::flash('success', __('Connected to :name. Its quotes are shown below.', ['name' => $c['name']]));
        redirect($back.'#quotes');
    }

    public function erpDisconnect(int $id): never
    {
        $row = $this->findForChange($id, 'edit');
        DB::update('leads', ['erp_customer_id' => null, 'erp_customer_name' => null, 'erp_connected_at' => null, 'erp_connected_by' => null, 'updated_at' => now()], ['id' => $id], 'crm');
        Activity::log('updated', 'lead', $id, $this->label($row), ['Disconnected lead ":label" from its ERP customer', ['label' => $this->label($row)]],
            ['changes' => ['erp_customer' => ['from' => (string) ($row['erp_customer_name'] ?? ''), 'to' => '']]], $this->owner($row), notify: false, module: 'crm');
        Session::flash('success', __('Disconnected from the ERP customer.'));
        redirect('/crm/leads/'.$id.'#quotes');
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
