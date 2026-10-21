<?php
declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\ValidationException;
use App\Modules\CRM\Support\Access;
use App\Modules\CRM\Support\Catalog;
use App\Modules\CRM\Support\Stages;
use App\Modules\CRM\Support\Ui;

/**
 * A sales opportunity: products (registered or typed by hand), a stage tracker,
 * next steps with a history of what was done, comments and related activities.
 */
class OpportunityController extends CrmController
{
    protected array $remote = ['lead_id' => 'leads', 'contact_id' => 'contacts'];

    protected string $resource = 'crm_opportunities';
    protected string $table = 'opportunities';
    protected string $type = 'opportunity';
    protected string $entity = 'opportunity';
    protected string $codePrefix = 'OP';
    protected bool $files = true;

    /** Files an opportunity may have in all (the form's drop zone counts the ones already attached). */
    private const MAX_FILES = 5;
    private const UNITS = ['pcs', 'set', 'unit', 'box', 'pack', 'lot', 'kg', 'm', 'm²', 'litre', 'hour', 'day', 'month', 'year', 'job', 'licence'];

    /** OP-yyyyMMddHHmmss + 3 digits, like leads and contacts */
    protected function newCode(int $id): string { return $this->dateCode(); }

    /** The form: Basic info · Products · Next steps · Description · Attachments with dots, like the lead form. */
    protected function meta(): array { return parent::meta() + ['wizard_style' => 'dots']; }

    /** Departments of this app: the seller's department of the deal. */
    private static function departments(): array
    {
        return array_column(DB::select('SELECT id::text AS id, name FROM departments WHERE deleted_at IS NULL ORDER BY name', [], 'core'), 'name', 'id');
    }
    protected string $singular = 'opportunity';
    protected string $plural = 'opportunities';
    protected string $base = '/crm/opportunities';
    protected string $icon = 'target';
    protected string $orderBy = "CASE WHEN t.opportunity_stage IN ('QUALIFICATION','SURVEY_PROPOSAL','EVALUATION_TESTING','NEGOTIATION') THEN 0 ELSE 1 END, t.updated_at DESC, t.id DESC";
    protected bool $comments = true;

    protected function select(): string
    {
        return 'SELECT t.*, l.name_en AS lead_name, l.name_th AS lead_name_th, l.image AS lead_image, c.name_en AS contact_name FROM opportunities t
                LEFT JOIN leads l ON l.id = t.lead_id
                LEFT JOIN contacts c ON c.id = t.contact_id';
    }

    protected function label(array $row): string { return (string) $row['name']; }

    protected function searchable(): array { return ['t.name', 't.code', 'l.name_en', 'c.name_en']; }

    protected function filters(): array
    {
        return [
            'stage'    => ['label' => __('All stages'), 'options' => Catalog::stages(), 'column' => 't.opportunity_stage'],
            'priority' => ['label' => __('All priorities'), 'options' => Catalog::tr(Catalog::PRIORITIES), 'column' => 't.priority'],
        ] + $this->commonFilters();
    }

    protected function fields(?array $row): array
    {
        $must = $this->importing ? 'nullable' : 'required';     // asked for in the form, optional in an import
        $steps = $row ? DB::select("SELECT title, note, due_at, is_done FROM opportunity_steps WHERE opportunity_id = ? AND kind = 'step' ORDER BY is_done, due_at NULLS LAST, id", [(int) $row['id']], 'crm') : [];

        return [
            '_basic'      => ['section' => __('Basic info'), 'icon' => 'target', 'help' => __('Name, stage, priority and references')],
            'name'        => ['label' => __('Opportunity name'), 'rules' => 'required|max:200', 'span' => 2, 'placeholder' => __('e.g. Packaging line upgrade Q4'), 'example' => 'Packaging line upgrade'],
            'opportunity_stage' => ['label' => __('Stage'), 'type' => 'select', 'rules' => 'required', 'default' => 'QUALIFICATION', 'placeholder' => __('Select stage'),
                              'options' => $row && in_array($row['opportunity_stage'], Catalog::FINAL, true)     // closed for good: only its own stage
                                  ? [$row['opportunity_stage'] => Catalog::stageLabel($row['opportunity_stage'])] : Stages::options($this->user(), $row['opportunity_stage'] ?? null)],
            'priority'    => ['label' => __('Priority'), 'type' => 'select', 'options' => Catalog::tr(Catalog::PRIORITIES), 'rules' => 'required', 'default' => 'MEDIUM', 'placeholder' => __('Select priority')],
            'cancel_reason' => ['label' => __('Reason'), 'type' => 'textarea', 'rows' => 2, 'span' => 2, 'rules' => 'nullable|max:2000', 'show_when' => 'opportunity_stage=CLOSED_LOST,CANCEL,ON_HOLD',
                              'help' => __('Why was it lost, cancelled or put on hold?')],
            'amount'      => ['label' => __('Deal amount'), 'type' => 'custom', 'partial' => 'crm/fields/money', 'rules' => $must.'|numeric', 'currencies' => $this->currencyOptions(), 'currency' => 'THB',
                              'display' => fn ($r) => $r['amount'] !== null ? Ui::money($r['amount'], $r['currency']) : null],
            'currency'    => ['label' => __('Currency'), 'type' => 'select', 'options' => $this->currencyOptions(), 'rules' => 'required', 'default' => 'THB', 'hide_form' => true, 'hide_show' => true],
            'department_id' => ['label' => __('Department'), 'type' => 'select', 'options' => self::departments(), 'rules' => $must, 'placeholder' => __('Seller department'),
                              'default' => (string) ($this->user()->department_id ?? ''), 'help' => __('The seller\'s department; its people see the opportunity.')],
            'close_at'    => ['label' => __('Expected close date'), 'type' => 'date', 'rules' => $must.'|date'],
            'follow_at'   => ['label' => __('Follow-up date'), 'type' => 'date', 'rules' => $must.'|date', 'help' => __('Next check-in date')],
            'lead_id'     => $this->remoteField('lead_id', $row, ['label' => __('Lead'), 'rules' => 'required', 'span' => 2, 'placeholder' => __('Reference lead'),
                              'display' => fn ($r) => $r['lead_name'] ?? null, 'href' => fn ($r) => $r['lead_id'] ? '/crm/leads/'.$r['lead_id'] : null]),
            'contact_id'  => $this->remoteField('contact_id', $row, ['label' => __('Contact'), 'rules' => 'nullable', 'depends' => 'lead_id', 'span' => 2, 'placeholder' => __('Reference contact'),
                              'display' => fn ($r) => $r['contact_name'] ?? null, 'href' => fn ($r) => $r['contact_id'] ? '/crm/contacts/'.$r['contact_id'] : null]),
            'code'        => ['label' => __('Code'), 'rules' => 'nullable|max:30', 'hide_form' => true],     // made when the opportunity is created (import may give one)
            'probability' => ['label' => __('Probability (%)'), 'type' => 'number', 'rules' => 'nullable|numeric', 'hide_form' => true],             // from the stage

            '_products'   => ['section' => __('Products'), 'icon' => 'box', 'help' => __('Products of Accounting and new products')],
            'products'    => ['label' => __('Products'), 'type' => 'custom', 'partial' => 'crm/fields/product-lines', 'span' => 2, 'table' => false, 'import' => false, 'hide_show' => true, 'rules' => 'nullable',
                              'units' => self::UNITS],

            '_steps'      => ['section' => __('Next steps'), 'icon' => 'tasks', 'help' => __('Action items and follow-up tasks')],
            'next_steps'  => ['label' => __('Next steps'), 'type' => 'custom', 'partial' => 'crm/fields/next-steps', 'span' => 2, 'table' => false, 'import' => false, 'hide_show' => true, 'rules' => 'nullable',
                              'existing' => $steps],

            '_more'       => ['section' => __('Description'), 'icon' => 'list', 'help' => __('Rich text notes about this opportunity')],
            'description' => ['label' => __('Description'), 'type' => 'richtext', 'span' => 2, 'rules' => 'nullable', 'counter' => true],

            '_files'      => ['section' => __('Attachments'), 'icon' => 'clip', 'help' => __('Upload files (max :n)', ['n' => self::MAX_FILES])],
            'files'       => ['label' => __('Attachments'), 'type' => 'custom', 'partial' => 'crm/fields/dropfiles', 'span' => 2, 'table' => false, 'import' => false, 'hide_show' => true, 'rules' => 'nullable',
                              'max' => self::MAX_FILES, 'existing' => $row ? \App\Modules\CRM\Support\Attachments::forRecord('opportunity', (int) $row['id']) : []],
        ] + $this->ownershipFields($row);
    }

    protected function columns(): array
    {
        return [
            'name'  => ['label' => __('Opportunity'), 'primary' => true, 'sort' => 't.name', 'render' => fn ($r) => \App\Core\Support\Ui::title($r['name'], trim($r['code'].($r['priority'] === 'HIGH' ? ' · '.__('High priority') : ''), ' ·'), '/crm/opportunities/'.$r['id'])],
            'lead'  => ['label' => __('Lead'), 'sort' => 'l.name_en', 'tone' => 'amber', 'render' => fn ($r) => $r['lead_id'] ? Ui::person((string) $r['lead_name'], $r['lead_name_th'], $r['lead_image'], '/crm/leads/'.$r['lead_id']) : Ui::dash()],
            'contact' => ['label' => __('Contact'), 'sort' => 'c.name_en', 'render' => fn ($r) => e($r['contact_name'] ?: '—')],
            'stage' => ['label' => __('Stage'), 'sort' => 't.opportunity_stage', 'render' => fn ($r) => Ui::stageBadge($r['opportunity_stage'])],
            'amount' => ['label' => __('Amount'), 'sort' => 't.amount', 'render' => fn ($r) => '<span class="tabular-nums">'.Ui::money($r['amount'], $r['currency']).'</span>'],
            'close' => ['label' => __('Close'), 'sort' => 't.close_at', 'render' => fn ($r) => '<span class="tabular-nums text-steel">'.format_date($r['close_at'], 'd M Y').'</span>'],
            'created' => $this->createdColumn(),
        ];
    }

    /* ----------------------------------------------------------------- saving */

    protected function hydrateMore(array $rows): array
    {
        $ids = array_column($rows, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $all = DB::select("SELECT * FROM opportunity_products WHERE opportunity_id IN ({$in}) ORDER BY sort, id", $ids, 'crm');
        foreach ($rows as &$r) {
            $r['product_list'] = array_values(array_filter($all, fn ($p) => (int) $p['opportunity_id'] === (int) $r['id']));
            $r['products']     = implode('; ', array_map(fn ($p) => $p['name'].' × '.number_clean($p['quantity']), $r['product_list']));
        }
        unset($r);

        return $rows;
    }

    protected function prepareMore(array $data, ?array $existing): array
    {
        $stage = $data['opportunity_stage'] ?? $existing['opportunity_stage'] ?? 'QUALIFICATION';
        if ($existing && in_array($existing['opportunity_stage'], Catalog::FINAL, true) && $stage !== $existing['opportunity_stage']) {
            throw new ValidationException(['opportunity_stage' => __('This opportunity is closed; its stage can no longer be changed. Duplicate it to start a new one.')]);
        }

        if (in_array($stage, Catalog::NEEDS_REASON, true) && ! trim((string) ($data['cancel_reason'] ?? $existing['cancel_reason'] ?? ''))) {
            throw new ValidationException(['cancel_reason' => __('Write the reason for this stage.')]);
        }
        if (! in_array($stage, Catalog::NEEDS_REASON, true)) {
            $data['cancel_reason'] = null;
        }
        if (isset($data['probability']) && $data['probability'] !== null && ((float) $data['probability'] < 0 || (float) $data['probability'] > 100)) {
            throw new ValidationException(['probability' => __('Probability must be between 0 and 100.')]);
        }
        $changedStage = ! $existing || $existing['opportunity_stage'] !== $stage;
        $default      = Catalog::STAGES[$stage][1] ?? null;
        $prob         = $data['probability'] ?? null;
        if ($prob === null) {
            $prob = $default ?? ($existing['probability'] ?? null);
        } elseif ($changedStage && in_array($stage, ['CLOSED_WON', 'CLOSED_LOST', 'CANCEL'], true)) {
            $prob = $default;
        }
        $data['probability'] = $prob;
        if (in_array($stage, ['CLOSED_WON', 'CLOSED_LOST'], true) && empty($data['close_at']) && empty($existing['close_at'])) {
            $data['close_at'] = now();
        }

        // products: a product of Accounting (erp_product_id), an older CRM catalogue product (product_id) or a new one typed by hand
        $catalog = array_column(DB::select('SELECT id, name FROM products', [], 'crm'), 'name', 'id');
        $lines   = [];
        foreach ((array) ($data['products'] ?? []) as $p) {
            $name = trim((string) ($p['name'] ?? ''));
            $pid  = (int) ($p['product_id'] ?? 0);
            if ($name === '' && $pid && isset($catalog[$pid])) { $name = $catalog[$pid]; }
            if ($name === '') {
                continue;
            }
            $erp   = ($p['kind'] ?? '') === 'catalog' && ctype_digit((string) ($p['erp_product_id'] ?? '')) ? (int) $p['erp_product_id'] : null;
            $qty   = is_numeric($p['quantity'] ?? null) && (float) $p['quantity'] > 0 ? (float) $p['quantity'] : 1.0;
            $price = isset($p['unit_price']) && $p['unit_price'] !== '' && is_numeric($p['unit_price']) ? (float) $p['unit_price'] : null;
            $lines[] = ['product_id' => $pid && isset($catalog[$pid]) ? $pid : null, 'erp_product_id' => $erp, 'name' => mb_substr($name, 0, 200),
                'part_number' => mb_substr(trim((string) ($p['part_number'] ?? '')), 0, 80) ?: null, 'unit' => mb_substr(trim((string) ($p['unit'] ?? '')), 0, 30) ?: null,
                'quantity' => $qty, 'unit_price' => $price, 'note' => mb_substr(trim((string) ($p['note'] ?? '')), 0, 250) ?: null];
        }
        $data['products'] = $lines;
        if (($data['amount'] ?? null) === null && $lines && ! in_array(null, array_column($lines, 'unit_price'), true)) {
            $data['amount'] = array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_price'], $lines));
        }

        // next steps typed in the form
        $data['next_steps'] = array_values(array_filter(array_map(fn ($st) => [
            'title' => mb_substr(trim((string) ($st['title'] ?? '')), 0, 200),
            'due_at' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($st['due_at'] ?? '')) ? $st['due_at'] : null,
            'note' => trim((string) ($st['note'] ?? '')) ?: null,
        ], (array) ($data['next_steps'] ?? [])), fn ($st) => $st['title'] !== ''));

        // the seller's department (the base form never changes department_id; here it is a choice)
        $dep = (string) Request::input('department_id', '');
        if ($dep !== '' && isset(self::departments()[$dep])) { $data['department_id'] = (int) $dep; }

        // attachments: at most MAX_FILES in all
        $had = $existing ? count(\App\Modules\CRM\Support\Attachments::forRecord('opportunity', (int) $existing['id'])) : 0;
        if ($had + count(\App\Modules\CRM\Support\Attachments::incoming('files')) > self::MAX_FILES) {
            throw new ValidationException(['files' => __('You can attach up to :n files.', ['n' => self::MAX_FILES])]);
        }

        return $data;
    }

    protected function afterSave(int $id, array $data, ?array $existing): void
    {
        DB::exec('DELETE FROM opportunity_products WHERE opportunity_id = ?', [$id], 'crm');
        foreach ($data['products'] as $i => $l) {
            DB::insert('opportunity_products', $l + ['opportunity_id' => $id, 'sort' => $i], 'crm', '');
        }
        foreach ($data['next_steps'] ?? [] as $st) {
            DB::insert('opportunity_steps', ['opportunity_id' => $id, 'kind' => 'step', 'stage' => $data['opportunity_stage'] ?? $existing['opportunity_stage'] ?? 'QUALIFICATION',
                'title' => $st['title'], 'note' => $st['note'], 'due_at' => $st['due_at'], 'created_by' => $this->user()->id], 'crm', '');
        }
        $stage = $data['opportunity_stage'] ?? null;
        if ($stage && (! $existing || $existing['opportunity_stage'] !== $stage)) {
            $this->recordStage($id, $stage, $existing ? $existing['opportunity_stage'] : null, $data['cancel_reason'] ?? null);
        }
    }

    private function recordStage(int $id, string $stage, ?string $from, ?string $note): void
    {
        DB::insert('opportunity_steps', [
            'opportunity_id' => $id, 'kind' => 'stage', 'stage' => $stage, 'from_stage' => $from, 'is_done' => true, 'done_at' => now(), 'done_by' => $this->user()->id,
            'note' => ($note !== null && $note !== '') ? $note : null, 'created_by' => $this->user()->id,
        ], 'crm', '');
    }

    /* ------------------------------------------------------------ stage moves */

    public function moveStage(int $id): never
    {
        $row   = $this->findForChange($id, 'edit');
        $stage = (string) Request::input('stage');
        $note  = trim((string) Request::input('note'));
        if (isset(Catalog::STAGES[$stage]) && $stage === $row['opportunity_stage']) {
            back('error', __('It is already in this stage.'));
        }
        if (in_array($row['opportunity_stage'], Catalog::FINAL, true)) {
            back('error', __('This opportunity is closed; its stage can no longer be changed. Duplicate it to start a new one.'));
        }
        $this->checkMove($stage, $note);
        $this->changeStage($row, $stage, $note);

        Session::flash('success', __('Moved to :stage.', ['stage' => Catalog::stageLabel($stage)]));
        redirect('/crm/opportunities/'.$id.'#progress');
    }

    /** Is this a stage the person can move to, and is the reason there when one is needed? (rights on the record are checked by the caller) */
    public function checkMove(string $stage, string $note): void
    {
        if (! isset(Catalog::STAGES[$stage])) {
            throw new ValidationException(['stage' => __('Pick a stage.')]);
        }
        if (! Stages::allowed($this->user(), $stage)) {
            throw new ValidationException(['stage' => __('This stage is not available to your department.')]);
        }
        if (in_array($stage, Catalog::NEEDS_REASON, true) && $note === '') {
            throw new ValidationException(['note' => __('Write the reason for this stage.')]);
        }
    }

    /** Moves an opportunity to a stage and writes the history. Used by the stage tracker and the pipeline board. */
    public function changeStage(array $row, string $stage, string $note, bool $notify = true): void
    {
        $id   = (int) $row['id'];
        $set  = ['opportunity_stage' => $stage, 'updated_by' => $this->user()->id, 'updated_at' => now(), 'cancel_reason' => in_array($stage, Catalog::NEEDS_REASON, true) ? $note : null];
        $prob = Catalog::STAGES[$stage][1];
        if ($prob !== null) { $set['probability'] = $prob; }
        if (in_array($stage, ['CLOSED_WON', 'CLOSED_LOST'], true)) { $set['close_at'] = now(); }
        DB::update('opportunities', $set, ['id' => $id], 'crm');
        $this->recordStage($id, $stage, $row['opportunity_stage'], $note !== '' ? $note : null);

        Activity::log('updated', 'opportunity', $id, $row['name'], ['Moved opportunity ":label" to :stage', ['label' => $row['name'], 'stage' => Catalog::STAGES[$stage][0]]],
            ['changes' => ['opportunity_stage' => ['from' => Catalog::stageLabel($row['opportunity_stage']), 'to' => Catalog::stageLabel($stage)]], '_url' => url('/crm/opportunities/'.$id)], $this->owner($row), notify: $notify, module: 'crm');
    }

    /* ------------------------------------------------------------- next steps */

    public function addStep(int $id): never
    {
        $row  = $this->findForChange($id, 'edit');
        $data = $this->validate(['title' => 'required|max:200', 'due_at' => 'nullable|date', 'note' => 'nullable|max:2000'], ['title' => __('next step'), 'due_at' => __('due date'), 'note' => __('note')]);
        DB::insert('opportunity_steps', [
            'opportunity_id' => $id, 'kind' => 'step', 'stage' => $row['opportunity_stage'], 'title' => $data['title'], 'note' => $data['note'],
            'due_at' => $data['due_at'], 'created_by' => $this->user()->id,
        ], 'crm', '');
        if ($data['due_at']) {
            DB::exec('UPDATE opportunities SET follow_at = ?, updated_at = now() WHERE id = ? AND (follow_at IS NULL OR follow_at > ?)', [$data['due_at'], $id, $data['due_at']], 'crm');
        }
        Activity::log('updated', 'opportunity', $id, $row['name'], ['Added next step ":step" to opportunity ":label"', ['step' => $data['title'], 'label' => $row['name']]], [], $this->owner($row), module: 'crm');
        Session::flash('success', __('Next step added.'));
        redirect('/crm/opportunities/'.$id.'#progress');
    }

    private function step(int $id, int $stepId): array
    {
        return DB::first("SELECT * FROM opportunity_steps WHERE id = ? AND opportunity_id = ? AND kind = 'step'", [$stepId, $id], 'crm') ?? abort(404);
    }

    public function doneStep(int $id, int $stepId): never
    {
        $row  = $this->findForChange($id, 'edit');
        $step = $this->step($id, $stepId);
        $note = trim((string) Request::input('note'));
        DB::exec('UPDATE opportunity_steps SET is_done = TRUE, done_at = now(), done_by = ?, note = COALESCE(NULLIF(?, \'\'), note) WHERE id = ?', [$this->user()->id, $note, $stepId], 'crm');
        Activity::log('updated', 'opportunity', $id, $row['name'], ['Completed step ":step" on opportunity ":label"', ['step' => $step['title'], 'label' => $row['name']]], [], $this->owner($row), module: 'crm');
        Session::flash('success', __('Step marked as done.'));
        redirect('/crm/opportunities/'.$id.'#progress');
    }

    public function deleteStep(int $id, int $stepId): never
    {
        $this->findForChange($id, 'edit');
        $this->step($id, $stepId);
        DB::exec('DELETE FROM opportunity_steps WHERE id = ?', [$stepId], 'crm');
        Session::flash('success', __('Step removed.'));
        redirect('/crm/opportunities/'.$id.'#progress');
    }

    /* ------------------------------------------------------------ detail page */

    protected function badges(array $row): array
    {
        $b = [Ui::stageBadge($row['opportunity_stage'])];
        if ($row['priority'] === 'HIGH') { $b[] = Ui::badge(__('High priority'), 'danger'); }

        return $b;
    }

    protected function subtitleFor(array $row): string
    {
        return implode(' · ', array_filter([$row['code'], $row['lead_name'], $row['contact_name'], $row['amount'] !== null ? strip_tags(Ui::money($row['amount'], $row['currency'])) : null]));
    }

    protected function rowLinks(array $row): array
    {
        $q = 'opportunity_id='.$row['id'].($row['lead_id'] ? '&lead_id='.$row['lead_id'] : '').($row['contact_id'] ? '&contact_id='.$row['contact_id'] : '');

        $links = can('crm_activities', 'create') ? [['url' => '/crm/activities/create?'.$q, 'label' => __('Log activity'), 'icon' => 'clock']] : [];
        if (can($this->resource, 'create')) {
            $links[] = ['url' => '/crm/opportunities/'.$row['id'].'/duplicate', 'label' => __('Duplicate'), 'icon' => 'copy', 'post' => true,
                'confirm' => __('Make a copy of ":name" starting at Qualification?', ['name' => $row['name']])];
        }

        return $links;
    }

    /**
     * Copy an opportunity as a new one at the first stage: same lead, contact, priority, amount, currency, products and
     * notes; no code (numbered anew), dates, reason or history. The person copying it owns the copy.
     */
    public function duplicate(int $id): never
    {
        $this->authorize($this->resource, 'create');
        $src    = $this->find($id);                                      // only a record this person can see
        $fields = $this->fields(null);
        $data   = $this->prepare([
            'name' => mb_substr($src['name'].' ('.__('copy').')', 0, 200), 'lead_id' => $src['lead_id'], 'contact_id' => $src['contact_id'],
            'opportunity_stage' => 'QUALIFICATION', 'priority' => $src['priority'], 'cancel_reason' => null, 'code' => null,
            'amount' => $src['amount'], 'currency' => $src['currency'], 'probability' => null, 'follow_at' => null, 'close_at' => null,
            'description' => $src['description'],
            'products' => array_map(fn ($p) => ['product_id' => $p['product_id'], 'name' => $p['name'], 'quantity' => $p['quantity'], 'unit_price' => $p['unit_price'], 'note' => $p['note']], $src['product_list'] ?? []),
        ], null);
        $newId = (int) DB::insert($this->table, $this->tableData($fields, $data), $this->conn);
        $this->saved($newId, $data, null);
        $row = $this->find($newId);
        Activity::log('created', $this->type, $newId, $this->label($row), ['Duplicated opportunity ":label" from ":from"', ['label' => $this->label($row), 'from' => $this->label($src)]],
            ['attributes' => $this->displayValues($fields, $row), 'copied_from' => $id, '_url' => url($this->base.'/'.$newId)], $this->owner($row), module: $this->module);
        Session::flash('success', __('Copy made at Qualification. Check the details and save.'));
        redirect($this->base.'/'.$newId.'/edit');
    }

    private function steps(int $id): array
    {
        $rows  = DB::select('SELECT * FROM opportunity_steps WHERE opportunity_id = ? ORDER BY created_at DESC, id DESC', [$id], 'crm');
        $names = Access::names('users', array_merge(array_column($rows, 'created_by'), array_column($rows, 'done_by')));
        foreach ($rows as &$r) {
            $r['creator'] = $names[$r['created_by']] ?? '—';
            $r['doer']    = $names[$r['done_by'] ?? 0] ?? null;
        }

        return $rows;
    }

    protected function showTop(array $row): string
    {
        $edit = can($this->resource, 'edit') && $this->canModify($row, 'edit');

        return partial('crm/panels/stage-tracker', ['row' => $row, 'canEdit' => $edit, 'steps' => $this->steps((int) $row['id'])]);
    }

    protected function showMain(array $row): string
    {
        $id   = (int) $row['id'];
        $edit = can($this->resource, 'edit') && $this->canModify($row, 'edit');
        $out  = partial('crm/panels/steps', ['row' => $row, 'steps' => $this->steps($id), 'canEdit' => $edit]);
        $out .= partial('crm/panels/products', ['row' => $row, 'items' => $row['product_list']]);

        $acts = array_map(fn ($a) => ['href' => '/crm/activities/'.$a['id'], 'title' => $a['topic'], 'meta' => __(Catalog::ACTIVITY_TYPES[$a['activity_type']] ?? $a['activity_type']).($a['start_at'] ? ' · '.format_date($a['start_at'], 'd M Y H:i') : ''),
            'badge' => Ui::badge(__(Catalog::ACTIVITY_STATUSES[$a['status']] ?? $a['status']), $a['status'] === 'DONE' ? 'success' : ($a['status'] === 'CANCELLED' ? 'neutral' : 'info'))],
            $this->visibleRows('activity', 't.id, t.topic, t.activity_type, t.start_at, t.status', 't.opportunity_id = ?', [$id], 't.start_at DESC NULLS LAST, t.id DESC'));
        $q = 'opportunity_id='.$id.($row['lead_id'] ? '&lead_id='.$row['lead_id'] : '').($row['contact_id'] ? '&contact_id='.$row['contact_id'] : '');

        return $out.$this->relatedPanel(__('Activities'), $acts, can('crm_activities', 'create') ? '/crm/activities/create?'.$q : null, __('No activities yet.'), __('Log activity'));
    }

    protected function statCards(): array
    {
        $open = "t.opportunity_stage IN ('QUALIFICATION','SURVEY_PROPOSAL','EVALUATION_TESTING','NEGOTIATION','ON_HOLD')";
        $from = 'opportunities t LEFT JOIN currencies cu ON cu.code = t.currency';
        $val  = 'sum(COALESCE(t.amount, 0) * COALESCE(cu.rate_to_base, 1))';
        $base = (string) DB::scalar('SELECT code FROM currencies WHERE is_base LIMIT 1', [], 'crm');
        $money = fn (string $where) => Ui::compact($this->figure($val, $where, [], $from)).($base ? ' '.$base : '');

        return [
            $this->card(__('Open'), $this->figure('count(*)', $open), $money($open), 'info', 'target'),
            $this->card(__('Won this month'), $this->figure('count(*)', "t.opportunity_stage = 'CLOSED_WON' AND date_trunc('month', t.updated_at) = date_trunc('month', now())"), $money("t.opportunity_stage = 'CLOSED_WON' AND date_trunc('month', t.updated_at) = date_trunc('month', now())"), 'success', 'check'),
            $this->card(__('Lost this month'), $this->figure('count(*)', "t.opportunity_stage = 'CLOSED_LOST' AND date_trunc('month', t.updated_at) = date_trunc('month', now())"), null, 'neutral', 'columns'),
            $this->card(__('Follow-up overdue'), $this->figure('count(*)', "{$open} AND t.follow_at < now()"), __('Still open'), 'danger', 'clock'),
            $this->card(__('Past the closing date'), $this->figure('count(*)', "{$open} AND t.close_at < now()"), __('Still open'), 'warn', 'clock'),
        ];
    }
}
