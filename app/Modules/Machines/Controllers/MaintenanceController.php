<?php
declare(strict_types=1);

namespace App\Modules\Machines\Controllers;

use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Notifier;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\Upload;
use App\Core\Support\ValidationException;
use App\Modules\Machines\Support\Checklists;
use App\Modules\Machines\Support\Files;
use App\Modules\Machines\Support\Mx;

/** Planned maintenance rounds of every machine, and doing them (with the machine's maintenance checklist). */
class MaintenanceController extends MachinesController
{
    protected string $resource = 'machines_maintenance';
    protected string $table = 'maintenance_record';
    protected string $type = 'machine_maintenance';
    protected string $singular = 'maintenance';
    protected string $plural = 'maintenance';
    protected string $base = '/machines/maintenance';
    protected string $icon = 'tools';
    protected bool $softDelete = false;
    protected string $orderBy = 't.due_date ASC NULLS LAST, t.id';

    protected function select(): string
    {
        return 'SELECT t.*, m.id AS machine_id, m.department_id, m.responsible_person_id, m.supervisor_id, m.manager_id, m.machine_status, m.machine_name AS m_name FROM maintenance_record t LEFT JOIN machine m ON m.machine_code = t.machine_code';
    }

    protected function scopeSql(): array
    {
        $u = $this->user();
        if ($u->scope() === 'all') {
            return ['sql' => 'NOT t.is_canceled', 'params' => []];
        }
        $s = Mx::scope($u, 'm');

        return ['sql' => "(NOT t.is_canceled AND (t.responsible_maintenance = ? OR (m.id IS NOT NULL AND {$s['sql']})))", 'params' => [$u->id, ...$s['params']]];
    }

    protected function hydrate(array $rows): array
    {
        $rows  = $this->withPeople($rows, ['responsible_maintenance']);
        $depts = Mx::departments();
        foreach ($rows as &$r) {
            $r['department_name'] = $depts[$r['department_id']] ?? null;
            $r['state'] = $this->state($r);
        }

        return $rows;
    }

    /** planned | overdue | completed | late */
    private function state(array $r): string
    {
        if ($r['actual_date']) {
            return $r['status'] === 'COMPLETED (LATE)' || ($r['due_date'] && $r['actual_date'] > $r['due_date']) ? 'late' : 'completed';
        }

        return $r['due_date'] && $r['due_date'] < date('Y-m-d') ? 'overdue' : 'planned';
    }

    protected function label(array $row): string { return $row['machine_code'].' · '.$row['years'].' #'.($row['round'] ?? '?'); }

    protected function owner(array $row): ?int { return ! empty($row['responsible_person_id']) ? (int) $row['responsible_person_id'] : null; }

    protected function canModify(array $row, string $action): bool
    {
        $u = $this->user();
        if ($action === 'delete') {
            return $u->scope() === 'all' && ! $row['actual_date'];
        }
        if ((int) ($row['responsible_maintenance'] ?? 0) === $u->id) {
            return true;
        }

        return $row['machine_id'] ? Mx::manages($u, ['department_id' => $row['department_id'], 'responsible_person_id' => $row['responsible_person_id'], 'supervisor_id' => $row['supervisor_id'], 'manager_id' => $row['manager_id']]) : $u->scope() === 'all';
    }

    protected function searchable(): array { return ['t.machine_code', 't.machine_name', 't.note']; }

    protected function filters(): array
    {
        $years = array_column(DB::select('SELECT DISTINCT years FROM maintenance_record ORDER BY years DESC', [], 'machines'), 'years', 'years');
        $today = date('Y-m-d');

        return [
            'year'   => ['label' => __('All years'), 'options' => $years, 'column' => 't.years'],
            'state'  => ['label' => __('All states'), 'options' => ['planned' => __('Planned'), 'overdue' => __('Overdue'), 'completed' => __('Done on time'), 'late' => __('Done late')],
                         'sql' => "(CASE ? WHEN 'planned' THEN t.actual_date IS NULL AND (t.due_date IS NULL OR t.due_date >= '{$today}') WHEN 'overdue' THEN t.actual_date IS NULL AND t.due_date < '{$today}' WHEN 'completed' THEN t.actual_date IS NOT NULL AND (t.due_date IS NULL OR t.actual_date <= t.due_date) ELSE t.actual_date IS NOT NULL AND t.actual_date > t.due_date END)"],
            'dept'   => ['label' => __('All departments'), 'options' => Mx::departments(), 'column' => 'm.department_id'],
            'by'     => ['label' => __('Internal and external'), 'options' => array_map(fn ($l) => __($l), Mx::MAINT_BY), 'column' => 't.maintenance_by'],
            'type'   => ['label' => __('All types'), 'options' => array_map(fn ($l) => __($l), Mx::MAINT_TYPES), 'column' => 't.maintenance_type'],
            'mine'   => ['label' => __('All rounds'), 'options' => ['1' => __('Machines I look after')], 'sql' => '(m.responsible_person_id = ? OR t.responsible_maintenance = ?)', 'bind' => fn () => $this->user()->id],
        ];
    }

    private function machineOptions(): array
    {
        $s = Mx::scope($this->user(), 'm');

        return array_column(DB::select("SELECT m.machine_code, m.machine_code || ' · ' || m.machine_name AS label FROM machine m WHERE m.deleted_at IS NULL AND m.machine_status IN (".Mx::activeIn().") AND {$s['sql']} ORDER BY m.machine_code", $s['params'], 'machines'), 'label', 'machine_code');
    }

    protected function fields(?array $row): array
    {
        $f = [];
        if (! $row) {
            $f['machine_code'] = ['label' => __('Machine'), 'type' => 'select', 'options' => $this->machineOptions(), 'rules' => 'required', 'default' => Request::query('machine'), 'span' => 2];
        }
        $f += [
            'maintenance_type' => ['label' => __('Type'), 'type' => 'select', 'options' => array_map(fn ($l) => __($l), Mx::MAINT_TYPES), 'rules' => 'required', 'default' => 'PREVENTIVE', 'search' => false],
            'due_date'   => ['label' => __('Due date'), 'type' => 'date', 'rules' => 'required|date'],
            'plan_date'  => ['label' => __('Planned date'), 'type' => 'date', 'rules' => 'nullable|date'],
            'start_date' => ['label' => __('Started'), 'type' => 'date', 'rules' => 'nullable|date'],
            'maintenance_by' => ['label' => __('Done by'), 'type' => 'select', 'options' => array_map(fn ($l) => __($l), Mx::MAINT_BY), 'rules' => 'nullable', 'search' => false],
            'responsible_maintenance' => ['label' => __('Person doing the maintenance'), 'type' => 'select', 'options' => Mx::people(), 'rules' => 'nullable', 'display' => fn ($r) => $r['responsible_maintenance_name'] ?? ''],
            'note'       => ['label' => __('Note'), 'type' => 'textarea', 'rules' => 'nullable|max:3000', 'span' => 2],
            'docs'       => ['label' => __('Documents'), 'type' => 'custom', 'partial' => 'machines/fields/files', 'column' => 'attachment', 'table' => false, 'import' => false, 'span' => 2, 'rules' => 'nullable', 'display' => fn () => ''],
        ];

        return $f;
    }

    protected function columns(): array
    {
        return [
            'machine' => ['label' => __('Machine'), 'primary' => true, 'sort' => 't.machine_code', 'render' => fn ($r) => '<span class="font-medium tabular-nums">'.e($r['machine_code']).'</span><span class="block text-xs text-steel">'.e($r['machine_name'] ?? $r['m_name']).'</span>'],
            'round'   => ['label' => __('Round'), 'sort' => 't.years', 'render' => fn ($r) => e($r['years']).' <span class="text-steel">#'.e($r['round'] ?? '—').'</span><span class="block text-xs text-steel">'.e(__(Mx::MAINT_TYPES[$r['maintenance_type']] ?? $r['maintenance_type'])).'</span>'],
            'due'     => ['label' => __('Due'), 'sort' => 't.due_date', 'render' => fn ($r) => e($r['due_date'] ? format_date($r['due_date']) : '—').($r['plan_date'] ? '<span class="block text-xs text-steel">'.e(__('plan')).' '.e(format_date($r['plan_date'])).'</span>' : '')],
            'done'    => ['label' => __('Done'), 'sort' => 't.actual_date', 'render' => fn ($r) => e($r['actual_date'] ? format_date($r['actual_date']) : '—')],
            'state'   => ['label' => __('Status'), 'render' => fn ($r) => match ($r['state']) { 'completed' => Mx::pill(__('Done on time'), 'ok'), 'late' => Mx::pill(__('Done late'), 'warn'), 'overdue' => Mx::pill(__('Overdue'), 'bad'), default => Mx::pill(__('Planned'), 'neutral') }],
            'by'      => ['label' => __('Done by'), 'render' => fn ($r) => e($r['maintenance_by'] ? __(Mx::MAINT_BY[$r['maintenance_by']] ?? $r['maintenance_by']) : '—').($r['responsible_maintenance_name'] ? '<span class="block text-xs text-steel">'.e($r['responsible_maintenance_name']).'</span>' : '')],
            'dept'    => ['label' => __('Department'), 'render' => fn ($r) => e($r['department_name'] ?? '—')],
        ];
    }

    protected function rowLinks(array $row): array
    {
        return ! $row['actual_date'] && $this->canModify($row, 'edit') && can($this->resource, 'edit') ? [['url' => '/machines/maintenance/'.$row['id'].'/perform', 'label' => __('Do the maintenance'), 'icon' => 'tools']] : [];
    }

    protected function prepare(array $data, ?array $existing): array
    {
        if (! $existing) {
            $m = Mx::machine((string) $data['machine_code']);
            if (! $m || ! Mx::isActive($m['machine_status']) || ! Mx::canSee($this->user(), $m['machine_code'])) {
                throw new ValidationException(['machine_code' => __('Pick a machine.')]);
            }
            $data['machine_name'] = $m['machine_name'];
        }
        $due = (string) $data['due_date'];
        $data['years'] = substr($due, 0, 4);
        if (! $existing) {
            $data['round'] = 1 + (int) DB::scalar('SELECT COALESCE(MAX(round), 0) FROM maintenance_record WHERE machine_code = ? AND years = ?', [$data['machine_code'], $data['years']], 'machines');
        }
        $data['responsible_maintenance'] = $data['responsible_maintenance'] ?: null;
        $keep = array_map('strval', (array) (Request::all()['keep_attachment'] ?? []));
        $data['attachment'] = Files::merge($existing['attachment'] ?? null, $keep, Files::saveMany($_FILES['docs'] ?? null, 'docs'));

        return $this->stamp($data, $existing);
    }

    protected function beforeDelete(array $row): void { Files::merge($row['attachment'], [], []); }

    /* ---------------------------------------------------------------- do it */

    public function perform(int $id): string
    {
        $r = $this->findForChange($id, 'edit');
        if ($r['actual_date']) {
            back('error', __('This maintenance is already done.'));
        }
        $m = Mx::machine($r['machine_code']) ?? abort(404);
        $items = DB::select('SELECT i.id, i.question_id, q.detail, q.description, q.is_choice FROM maintenance_checklist i JOIN question q ON q.id = i.question_id WHERE i.machine_code = ? AND q.deleted_at IS NULL ORDER BY i.id', [$m['machine_code']], 'machines');

        return view('machines/maintenance-perform', ['title' => __('Do the maintenance'), 'r' => $r, 'm' => $m, 'items' => $items, 'people' => Mx::people(), 'me' => $this->user()->id]);
    }

    public function complete(int $id): never
    {
        $r = $this->findForChange($id, 'edit');
        if ($r['actual_date']) {
            back('error', __('This maintenance is already done.'));
        }
        $u = $this->user();
        $m = Mx::machine($r['machine_code']) ?? abort(404);
        $items = DB::select('SELECT i.id, i.question_id, i.reset_time, q.detail, q.description, q.is_choice FROM maintenance_checklist i JOIN question q ON q.id = i.question_id WHERE i.machine_code = ? AND q.deleted_at IS NULL ORDER BY i.id', [$m['machine_code']], 'machines');
        $answers = $items ? Checklists::answers($items, (array) Request::input('answers', [])) : [];

        $date = (string) Request::input('actual_date') ?: date('Y-m-d');
        if (! strtotime($date) || $date > date('Y-m-d')) {
            throw new ValidationException(['actual_date' => __('The date cannot be in the future.')]);
        }
        $by = (string) Request::input('maintenance_by');
        if (! isset(Mx::MAINT_BY[$by])) {
            throw new ValidationException(['maintenance_by' => __('Choose internal or external.')]);
        }
        $status = (string) Request::input('machine_status');
        if (! Mx::isActive($status)) {
            throw new ValidationException(['machine_status' => __('Choose the machine status after the maintenance.')]);
        }
        $job = trim((string) Request::input('job_detail'));
        if ($job === '') {
            throw new ValidationException(['job_detail' => __('Write what was done.')]);
        }
        $person = (int) Request::input('responsible_maintenance') ?: $u->id;
        $late   = $r['due_date'] && $date > $r['due_date'];
        $image  = ($file = Request::file('image')) ? Upload::image($file, 'machines') : null;
        $docs   = Files::merge($r['attachment'], array_column(Files::decode($r['attachment']), 'f'), Files::saveMany($_FILES['docs'] ?? null, 'docs'));
        $note   = trim((string) Request::input('note')) ?: null;

        $cid = (int) DB::transaction(function () use ($r, $m, $u, $answers, $job, $image, $date, $by, $status, $person, $late, $docs, $note) {
            $cid = (int) DB::insert('checklist_record', [
                'check_type' => 'MAINTENANCE', 'recheck' => false, 'machine_code' => $m['machine_code'], 'machine_name' => $m['machine_name'], 'machine_status' => $status,
                'machine_checklist' => json_encode($answers, JSON_UNESCAPED_UNICODE), 'machine_note' => $note, 'image' => $image, 'user_id' => $u->email, 'user_name' => $u->name,
                'supervisor' => $m['supervisor_id'], 'manager' => $m['manager_id'], 'checklist_status' => 'COMPLETED', 'job_detail' => $job, 'created_by' => $u->id, 'updated_by' => $u->id,
            ], 'machines');
            DB::update('maintenance_record', ['actual_date' => $date, 'start_date' => $r['start_date'] ?: $date, 'status' => $late ? 'COMPLETED (LATE)' : 'COMPLETED', 'maintenance_by' => $by, 'responsible_maintenance' => $person,
                'note' => $note ?? $r['note'], 'attachment' => $docs, 'checklist_record_id' => $cid, 'updated_by' => $u->id, 'updated_at' => now()], ['id' => $r['id']], 'machines');
            DB::exec('UPDATE machine SET machine_status = ?, updated_at = now() WHERE id = ?', [$status, $m['id']], 'machines');

            return $cid;
        }, 'machines');

        Activity::log('updated', $this->type, (int) $r['id'], $this->label($r), ['Finished the maintenance of :code', ['code' => $m['machine_code']]],
            ['changes' => ['status' => ['from' => $r['status'] ?: 'planned', 'to' => $late ? 'COMPLETED (LATE)' : 'COMPLETED']], '_url' => url('/machines/checklists/'.$cid)], $m['responsible_person_id'] ? (int) $m['responsible_person_id'] : null, module: 'machines');
        foreach (Mx::team($m) as $uid) {
            if ($uid !== $u->id) {
                Notifier::deliver('machines', 'updated', $uid, ['key' => 'Maintenance of :code is done', 'params' => ['code' => $m['machine_code']]],
                    ['key' => ':who finished round :n of :code · :name:late.', 'params' => ['who' => $u->name, 'n' => (string) ($r['round'] ?? '?'), 'code' => $m['machine_code'], 'name' => $m['machine_name'], 'late' => $late ? ' ('.__('late').')' : '']], url('/machines/checklists/'.$cid), $u->id);
            }
        }
        Session::flash('success', __('Maintenance saved.'));
        redirect('/machines/checklists/'.$cid);
    }
}
