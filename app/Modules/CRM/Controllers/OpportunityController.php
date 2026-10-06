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
        $products = array_column(DB::select('SELECT id, name, unit_price FROM products WHERE is_active ORDER BY name', [], 'crm'), null, 'id');

        return [
            '_deal'       => ['section' => __('Opportunity')],
            'name'        => ['label' => __('Name'), 'rules' => 'required|max:200', 'span' => 2, 'example' => 'Packaging line upgrade'],
            'lead_id'     => $this->remoteField('lead_id', $row, ['label' => __('Lead'), 'rules' => 'required', 'span' => 2,
                              'display' => fn ($r) => $r['lead_name'] ?? null, 'href' => fn ($r) => $r['lead_id'] ? '/crm/leads/'.$r['lead_id'] : null]),
            'contact_id'  => $this->remoteField('contact_id', $row, ['label' => __('Contact'), 'rules' => 'nullable', 'depends' => 'lead_id', 'span' => 2,
                              'display' => fn ($r) => $r['contact_name'] ?? null, 'href' => fn ($r) => $r['contact_id'] ? '/crm/contacts/'.$r['contact_id'] : null]),
            'opportunity_stage' => ['label' => __('Stage'), 'type' => 'select', 'options' => Stages::options($this->user(), $row['opportunity_stage'] ?? null), 'rules' => 'required', 'default' => 'QUALIFICATION',
                              'help' => __('After saving, use the stage tracker on the detail page to move it and keep a history.')],
            'priority'    => ['label' => __('Priority'), 'type' => 'select', 'options' => Catalog::tr(Catalog::PRIORITIES), 'rules' => 'required', 'default' => 'MEDIUM'],
            'cancel_reason' => ['label' => __('Reason'), 'type' => 'textarea', 'span' => 2, 'rules' => 'nullable|max:2000', 'show_when' => 'opportunity_stage=CLOSED_LOST,CANCEL,ON_HOLD',
                              'help' => __('Why was it lost, cancelled or put on hold?')],
            'code'        => ['label' => __('Code'), 'rules' => 'nullable|max:30', 'help' => __('Leave empty to number it automatically.')],

            '_value'      => ['section' => __('Value and dates')],
            'amount'      => ['label' => __('Amount'), 'type' => 'number', 'rules' => 'nullable|numeric', 'help' => __('Leave empty to add up the products below.')],
            'currency'    => ['label' => __('Currency'), 'type' => 'select', 'options' => $this->currencyOptions(), 'rules' => 'required', 'default' => 'THB', 'search' => false],
            'probability' => ['label' => __('Probability (%)'), 'type' => 'number', 'rules' => 'nullable|numeric', 'help' => __('Leave empty to use the stage default.')],
            'follow_at'   => ['label' => __('Next follow-up'), 'type' => 'date', 'rules' => 'nullable|date'],
            'close_at'    => ['label' => __('Expected close date'), 'type' => 'date', 'rules' => 'nullable|date'],

            '_products'   => ['section' => __('Products')],
            'products'    => ['label' => __('Products'), 'type' => 'custom', 'partial' => 'crm/fields/products', 'span' => 2, 'table' => false, 'import' => false, 'hide_show' => true, 'rules' => 'nullable',
                              'catalog' => $products],

            '_more'       => ['section' => __('More')],
            'description' => ['label' => __('Notes'), 'type' => 'richtext', 'span' => 2, 'rules' => 'nullable'],
        ] + $this->ownershipFields($row);
    }

    protected function columns(): array
    {
        return [
            'name'  => ['label' => __('Opportunity'), 'primary' => true, 'sort' => 't.name', 'render' => fn ($r) => Ui::person($r['name'], trim($r['code'].($r['priority'] === 'HIGH' ? ' · '.__('High priority') : ''), ' ·'), null, '/crm/opportunities/'.$r['id'])],
            'lead'  => ['label' => __('Lead'), 'sort' => 'l.name_en', 'tone' => 'amber', 'render' => fn ($r) => $r['lead_id'] ? Ui::person((string) $r['lead_name'], $r['lead_name_th'], $r['lead_image'], '/crm/leads/'.$r['lead_id']) : Ui::dash()],
            'contact' => ['label' => __('Contact'), 'sort' => 'c.name_en', 'render' => fn ($r) => e($r['contact_name'] ?: '—')],
            'stage' => ['label' => __('Stage'), 'sort' => 't.opportunity_stage', 'render' => fn ($r) => Ui::stageBadge($r['opportunity_stage'])],
            'amount' => ['label' => __('Amount'), 'sort' => 't.amount', 'render' => fn ($r) => '<span class="tabular-nums">'.Ui::money($r['amount'], $r['currency']).'</span>'],
            'probability' => ['label' => __('Chance'), 'sort' => 't.probability', 'render' => fn ($r) => $r['probability'] === null ? Ui::dash() : partial('crm/meter', ['value' => (float) $r['probability'], 'label' => number_clean($r['probability']).'%'])],
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

        // products: registered (product_id) or "other" typed by hand
        $catalog = array_column(DB::select('SELECT id, name, unit_price FROM products', [], 'crm'), null, 'id');
        $lines   = [];
        foreach ((array) ($data['products'] ?? []) as $p) {
            $pid  = (int) ($p['product_id'] ?? 0);
            $name = $pid && isset($catalog[$pid]) ? $catalog[$pid]['name'] : trim((string) ($p['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $qty   = is_numeric($p['quantity'] ?? null) && (float) $p['quantity'] > 0 ? (float) $p['quantity'] : 1.0;
            $price = isset($p['unit_price']) && $p['unit_price'] !== '' && is_numeric($p['unit_price']) ? (float) $p['unit_price'] : null;
            $lines[] = ['product_id' => $pid && isset($catalog[$pid]) ? $pid : null, 'name' => mb_substr($name, 0, 200), 'quantity' => $qty, 'unit_price' => $price, 'note' => mb_substr(trim((string) ($p['note'] ?? '')), 0, 250) ?: null];
        }
        $data['products'] = $lines;
        if (($data['amount'] ?? null) === null && $lines && ! in_array(null, array_column($lines, 'unit_price'), true)) {
            $data['amount'] = array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_price'], $lines));
        }

        return $data;
    }

    protected function afterSave(int $id, array $data, ?array $existing): void
    {
        DB::exec('DELETE FROM opportunity_products WHERE opportunity_id = ?', [$id], 'crm');
        foreach ($data['products'] as $i => $l) {
            DB::insert('opportunity_products', $l + ['opportunity_id' => $id, 'sort' => $i], 'crm', '');
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

        return can('crm_activities', 'create') ? [['url' => '/crm/activities/create?'.$q, 'label' => __('Log activity'), 'icon' => 'clock']] : [];
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
