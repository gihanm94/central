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
use App\Modules\Machines\Support\Files;
use App\Modules\Machines\Support\Mx;

/** The machines: one row per machine with code + QR label, people, plans, documents and its checklist items. */
class MachineController extends MachinesController
{
    protected string $resource = 'machines_machines';
    protected string $table = 'machine';
    protected string $type = 'machine';
    protected string $singular = 'machine';
    protected string $plural = 'machines';
    protected string $base = '/machines/machines';
    protected string $icon = 'gear';
    protected bool $wizard = true;
    protected string $orderBy = 't.machine_code';

    private ?array $reg = null;

    protected function scopeSql(): array { return Mx::scope($this->user(), 't'); }

    protected function select(): string
    {
        return 'SELECT t.*, ty.machine_group_name, ty.machine_type_name FROM machine t
                LEFT JOIN machine_type ty ON ty.machine_group_id = t.machine_group_id AND ty.machine_type_id = t.machine_type_id AND ty.deleted_at IS NULL';
    }

    protected function hydrate(array $rows): array
    {
        $rows  = $this->withPeople($rows, ['responsible_person_id', 'supervisor_id', 'manager_id']);
        $depts = Mx::departments();
        foreach ($rows as &$r) {
            $r['department_name'] = $depts[$r['department_id']] ?? null;
        }

        return $rows;
    }

    protected function label(array $row): string { return $row['machine_code'].' · '.$row['machine_name']; }

    protected function owner(array $row): ?int { return $row['responsible_person_id'] ? (int) $row['responsible_person_id'] : null; }

    protected function canModify(array $row, string $action): bool
    {
        $u = $this->user();
        if ($action === 'delete') {
            return $u->scope() === 'all';
        }

        return Mx::manages($u, $row);
    }

    protected function searchable(): array { return ['t.machine_code', 't.machine_name', 't.brand', 't.model', 't.serial_number', 't.responsible_person_name']; }

    protected function filters(): array
    {
        return [
            'status' => ['label' => __('All statuses'), 'options' => $this->tr(Mx::STATUSES), 'column' => 't.machine_status'],
            'check'  => ['label' => __('All check states'), 'options' => ['PENDING' => __('Pending'), 'PENDING SUPERVISOR' => __('Pending supervisor'), 'PENDING MANAGER' => __('Pending manager'), 'COMPLETED' => __('Completed'), 'OUT OF SERVICE' => __('Out of service')], 'column' => 't.check_status'],
            'dept'   => ['label' => __('All departments'), 'options' => Mx::departments(), 'column' => 't.department_id'],
            'group'  => ['label' => __('All groups'), 'options' => array_column(DB::select('SELECT DISTINCT machine_group_id, machine_group_name FROM machine_type WHERE deleted_at IS NULL ORDER BY 1', [], 'machines'), 'machine_group_name', 'machine_group_id'), 'column' => 't.machine_group_id'],
            'period' => ['label' => __('Any check period'), 'options' => $this->tr(Mx::PERIODS), 'column' => 't.reset_period'],
            'mine'   => ['label' => __('All machines'), 'options' => ['1' => __('Mine (responsible)')], 'sql' => 't.responsible_person_id = ?', 'bind' => fn () => $this->user()->id],
        ];
    }

    private function tr(array $a): array { return array_map(fn ($l) => __($l), $a); }

    /** The register request this machine is being made from (?register=ID), if the person may see it. */
    private function register(): ?array
    {
        if ($this->reg !== null) {
            return $this->reg ?: null;
        }
        $id = (int) Request::query('register', Request::input('register_id', 0));
        $this->reg = [];
        if ($id) {
            $r = DB::first('SELECT * FROM register_request WHERE id = ? AND deleted_at IS NULL', [$id], 'machines');
            $this->reg = $r ?: [];
        }

        return $this->reg ?: null;
    }

    private function typeOptions(): array
    {
        $out = [];
        foreach (DB::select("SELECT machine_group_id g, machine_group_name gn, machine_type_id t, machine_type_name tn FROM machine_type WHERE deleted_at IS NULL AND status = 'ACTIVE' ORDER BY 1, 3", [], 'machines') as $r) {
            $out[$r['g'].'.'.$r['t']] = $r['g'].'-'.$r['t'].' · '.$r['gn'].' / '.$r['tn'];
        }

        return $out;
    }

    private function questionOptions(): array
    {
        return array_column(DB::select('SELECT id, detail FROM question WHERE deleted_at IS NULL ORDER BY detail', [], 'machines'), 'detail', 'id');
    }

    protected function fields(?array $row): array
    {
        $people = Mx::people();
        $reg    = $row ? null : $this->register();
        $d      = fn (string $k, mixed $fallback = null) => $row[$k] ?? ($reg[$k] ?? $fallback);
        $edit   = $row !== null;
        $planRows = function (string $kind) use ($row, $reg) {
            if ($row) {
                return $kind === 'maintenance'
                    ? DB::select('SELECT id, due_date, plan_date, note FROM maintenance_record WHERE machine_code = ? AND actual_date IS NULL AND NOT is_canceled ORDER BY due_date', [$row['machine_code']], 'machines')
                    : DB::select('SELECT id, due_date, note FROM calibration_record WHERE machine_code = ? AND certificate_date IS NULL AND NOT is_canceled ORDER BY due_date', [$row['machine_code']], 'machines');
            }

            return array_values(json_decode((string) ($reg[$kind] ?? ''), true) ?: []);
        };
        $items = function (string $tbl) use ($row) {
            return $row ? DB::select("SELECT question_id, reset_time FROM {$tbl} WHERE machine_code = ? ORDER BY id", [$row['machine_code']], 'machines') : [];
        };
        $q = $this->questionOptions();

        $f = [
            '_machine'      => ['section' => __('Machine')],
        ];
        if ($edit) {
            $f['machine_code'] = ['label' => __('Machine code'), 'readonly' => true, 'rules' => 'nullable', 'help' => __('Printed on the QR label. It never changes.')];
        }
        $f += [
            'machine_name'  => ['label' => __('Machine name'), 'rules' => 'required|max:200', 'span' => 2, 'example' => 'Hydraulic press 200 t', 'default' => $reg['machine_name'] ?? null],
            'type_key'      => ['label' => __('Machine type'), 'type' => 'select', 'options' => $this->typeOptions(), 'rules' => 'required', 'table' => false, 'import' => false, 'default' => $edit ? ($row['machine_group_id'].'.'.$row['machine_type_id']) : '', 'display' => fn ($r) => ($r['machine_group_name'] ?? '').' / '.($r['machine_type_name'] ?? ''),
                                'help' => $edit ? null : __('The code is made from the department and the type.')],
            'department_id' => ['label' => __('Department'), 'type' => 'select', 'options' => Mx::departments(), 'rules' => 'required', 'default' => $d('department_id', $this->user()->department_id)],
            'machine_status' => ['label' => __('Status'), 'type' => 'select', 'options' => $this->tr(Mx::STATUSES), 'rules' => 'required', 'default' => 'OPERATIONAL', 'search' => false,
                                 'help' => __('Scrapped or cancelled machines leave the checklists, maintenance and calibration.')],
            'brand'         => ['label' => __('Brand'), 'rules' => 'nullable|max:120', 'default' => $d('brand')],
            'model'         => ['label' => __('Model'), 'rules' => 'nullable|max:120', 'default' => $d('model')],
            'serial_number' => ['label' => __('Serial number'), 'rules' => 'nullable|max:120', 'default' => $d('serial_number')],
            'machine_number' => ['label' => __('Asset number'), 'rules' => 'nullable|max:60'],
            'is_new'        => ['label' => __('Bought new'), 'type' => 'checkbox', 'default' => $d('is_new', true)],
            'image'         => ['label' => __('Photo'), 'type' => 'file', 'import' => false, 'span' => 2, 'rules' => 'nullable', 'help' => __('PNG, JPG or WebP, up to 2 MB.')],
            'note'          => ['label' => __('Note'), 'type' => 'textarea', 'rules' => 'nullable|max:3000', 'span' => 2, 'default' => $reg['note'] ?? null],
            'register_id'   => ['label' => __('Register request'), 'type' => 'custom', 'partial' => 'machines/fields/hidden', 'rules' => 'nullable|integer', 'default' => $reg['id'] ?? ($row['register_id'] ?? ''), 'import' => false, 'display' => fn ($r) => $r['register_id'] ? '#'.$r['register_id'] : ''],

            '_people'       => ['section' => __('Who looks after it')],
            'responsible_person_id' => ['label' => __('Responsible person'), 'type' => 'select', 'options' => $people, 'rules' => 'required', 'default' => $d('responsible_id', null) ?? ($row['responsible_person_id'] ?? null), 'help' => __('Does the weekly or monthly check.'), 'display' => fn ($r) => $r['responsible_person_id_name'] ?? ''],
            'supervisor_id' => ['label' => __('Supervisor'), 'type' => 'select', 'options' => $people, 'rules' => 'nullable', 'default' => $d('supervisor_id'), 'help' => __('Approves the check first. Without one, the manager approves.'), 'display' => fn ($r) => $r['supervisor_id_name'] ?? ''],
            'manager_id'    => ['label' => __('Manager'), 'type' => 'select', 'options' => $people, 'rules' => 'nullable', 'default' => $d('manager_id'), 'help' => __('Gives the final approval.'), 'display' => fn ($r) => $r['manager_id_name'] ?? ''],
            'reset_period'  => ['label' => __('Responsible re-checks'), 'type' => 'select', 'options' => $this->tr(Mx::PERIODS), 'rules' => 'required', 'default' => 'WEEKLY', 'search' => false, 'help' => __('How often the responsible person checks and sends it for approval.')],

            '_plans'        => ['section' => __('Maintenance and calibration')],
            'maintenance_period' => ['label' => __('Maintenance every'), 'rules' => 'nullable|max:20', 'example' => '6 months'],
            'is_calibration' => ['label' => __('This machine needs calibration'), 'type' => 'checkbox', 'default' => $reg ? ! empty($reg['calibration']) : false],
            'certificate_period' => ['label' => __('Calibration certificate valid for'), 'rules' => 'nullable|max:20', 'example' => '12 months'],
            'plan_maint'    => ['label' => __('Maintenance rounds'), 'type' => 'custom', 'partial' => 'machines/fields/plan', 'plan' => 'maintenance', 'rows' => $planRows('maintenance'), 'table' => false, 'import' => false, 'span' => 2, 'rules' => 'nullable', 'display' => fn () => '',
                                'help' => $edit ? __('Rounds that are not done yet. Finished ones stay in the history and are not changed here.') : null],
            'plan_cal'      => ['label' => __('Calibration due dates'), 'type' => 'custom', 'partial' => 'machines/fields/plan', 'plan' => 'calibration', 'rows' => $planRows('calibration'), 'table' => false, 'import' => false, 'span' => 2, 'rules' => 'nullable', 'display' => fn () => ''],

            '_docs'         => ['section' => __('Documents')],
            'wi_files'      => ['label' => __('Work instruction'), 'type' => 'custom', 'partial' => 'machines/fields/files', 'column' => 'work_instruction', 'table' => false, 'import' => false, 'span' => 2, 'rules' => 'nullable', 'display' => fn () => '',
                                'current' => $row ?: ($reg ? ['work_instruction' => $reg['work_instruction']] : null)],
            'has_warranty'  => ['label' => __('Warranty'), 'type' => 'select', 'options' => ['YES' => __('Yes'), 'NO' => __('No')], 'rules' => 'nullable', 'default' => $d('has_warranty'), 'search' => false],
            'warranty_expire_date' => ['label' => __('Warranty ends'), 'type' => 'date', 'rules' => 'nullable|date', 'default' => $d('warranty_expire_date'), 'show_when' => 'has_warranty=YES'],
            'warranty_note' => ['label' => __('Warranty note'), 'type' => 'textarea', 'rules' => 'nullable|max:2000', 'span' => 2, 'default' => $d('warranty_note'), 'show_when' => 'has_warranty=YES'],
            'war_files'     => ['label' => __('Warranty papers'), 'type' => 'custom', 'partial' => 'machines/fields/files', 'column' => 'warranty_files', 'table' => false, 'import' => false, 'span' => 2, 'rules' => 'nullable', 'display' => fn () => '', 'show_when' => 'has_warranty=YES'],

            '_check'        => ['section' => __('Checklists')],
            'items_general' => ['label' => __('Checklist (what is checked, and when it starts again)'), 'type' => 'custom', 'partial' => 'machines/fields/items', 'questions' => $q, 'resets' => true, 'rows' => $items('machine_checklist'), 'table' => false, 'import' => false, 'span' => 2, 'rules' => 'nullable', 'display' => fn () => '',
                                'help' => __('"General" items are checked by whoever uses the machine. The others are the responsible person\'s own re-check and start again on their day.')],
            'items_maint'   => ['label' => __('Maintenance checklist'), 'type' => 'custom', 'partial' => 'machines/fields/items', 'questions' => $q, 'resets' => false, 'rows' => $items('maintenance_checklist'), 'table' => false, 'import' => false, 'span' => 2, 'rules' => 'nullable', 'display' => fn () => '',
                                'help' => __('Checked by the person who does the maintenance.')],
        ];

        return $f;
    }

    protected function columns(): array
    {
        return [
            'code'   => ['label' => __('Machine'), 'primary' => true, 'sort' => 't.machine_code', 'render' => fn ($r) => '<span class="font-medium tabular-nums">'.e($r['machine_code']).'</span><span class="block text-xs text-steel">'.e($r['machine_name']).'</span>'],
            'type'   => ['label' => __('Type'), 'render' => fn ($r) => e(trim(($r['machine_group_name'] ?? '').' / '.($r['machine_type_name'] ?? ''), ' /') ?: '—').'<span class="block text-xs text-steel">'.e(trim(($r['brand'] ?? '').' '.($r['model'] ?? ''))).'</span>'],
            'dept'   => ['label' => __('Department'), 'render' => fn ($r) => e($r['department_name'] ?? '—')],
            'resp'   => ['label' => __('Responsible'), 'sort' => 't.responsible_person_name', 'render' => fn ($r) => e($r['responsible_person_id_name'] ?? $r['responsible_person_name'] ?? '—')],
            'status' => ['label' => __('Status'), 'sort' => 't.machine_status', 'render' => fn ($r) => Mx::pill($r['machine_status'])],
            'check'  => ['label' => __('Check'), 'sort' => 't.check_status', 'render' => fn ($r) => Mx::pill($r['check_status'])],
        ];
    }

    protected function rowLinks(array $row): array
    {
        $l = [];
        if (Mx::isActive($row['machine_status']) && can('machines_checklists', 'create')) {
            $l[] = ['url' => '/machines/checklists/new?machine='.rawurlencode($row['machine_code']), 'label' => __('Check now'), 'icon' => 'check'];
        }
        $l[] = ['url' => '/machines/label?ids='.$row['id'], 'label' => __('QR label'), 'icon' => 'qr'];

        return $l;
    }

    /* ------------------------------------------------------------------ save */

    protected function prepare(array $data, ?array $existing): array
    {
        [$g, $t] = array_pad(explode('.', (string) ($data['type_key'] ?? ''), 2), 2, '');
        $type = DB::first('SELECT machine_group_id, machine_type_id FROM machine_type WHERE machine_group_id = ? AND machine_type_id = ? AND deleted_at IS NULL', [$g, $t], 'machines');
        if (! $type) {
            throw new ValidationException(['type_key' => __('Pick a machine type.')]);
        }
        $data['machine_group_id'] = $type['machine_group_id'];
        $data['machine_type_id']  = $type['machine_type_id'];
        unset($data['type_key'], $data['machine_code']);

        $data['responsible_person_name'] = Mx::names([$data['responsible_person_id']])[(int) $data['responsible_person_id']] ?? null;
        $data['supervisor_id'] = $data['supervisor_id'] ?: null;
        $data['manager_id']    = $data['manager_id'] ?: null;
        if (! $data['supervisor_id'] && ! $data['manager_id']) {
            throw new ValidationException(['manager_id' => __('Pick a supervisor or a manager: somebody has to approve the checks.')]);
        }
        $data['register_id'] = ! empty($data['register_id']) ? (int) $data['register_id'] : ($existing['register_id'] ?? null);

        if ($file = Request::file('image')) {
            $data['image'] = Upload::image($file, 'machines');
            if ($existing && $existing['image']) {
                Upload::delete($existing['image']);
            }
        } else {
            unset($data['image']);
        }

        foreach (['wi_files' => 'work_instruction', 'war_files' => 'warranty_files'] as $key => $col) {
            $cur  = $existing[$col] ?? ($existing ? null : ($this->register()[$col] ?? null));
            $keep = array_map('strval', (array) (Request::all()['keep_'.$col] ?? []));
            $data[$col] = Files::merge($cur, $existing ? $keep : array_column(Files::decode($cur), 'f'), Files::saveMany($_FILES[$key] ?? null, $key));
        }
        if (($data['has_warranty'] ?? '') !== 'YES') {
            $data['warranty_expire_date'] = null; $data['warranty_note'] = null;
            Files::merge($data['warranty_files'] ?? null, [], []);
            $data['warranty_files'] = null;
        }

        $active = Mx::isActive($data['machine_status']);
        if (! $active) {
            $data['check_status'] = 'OUT OF SERVICE';
            $data['cancel_date']  = $existing['cancel_date'] ?? date('Y-m-d');
        } else {
            $data['cancel_date'] = null;
            if ($existing && $existing['check_status'] === 'OUT OF SERVICE') {
                $data['check_status'] = 'PENDING';
            }
        }
        $data['is_calibration'] = ! empty($data['is_calibration']);

        if (! $existing) {
            $code = Mx::nextCode((int) $data['department_id'], $g, $t);
            $data['machine_code'] = $code;
            $data['qr_code']      = Mx::qr($code);
            $data['check_status'] = $active ? 'PENDING' : 'OUT OF SERVICE';
            $data['register_date'] = $data['register_id'] ? date('Y-m-d') : null;
        }

        return $this->stamp($data, $existing);
    }

    protected function saved(int $id, array $data, ?array $existing): void
    {
        $m    = DB::first('SELECT * FROM machine WHERE id = ?', [$id], 'machines');
        $code = $m['machine_code'];
        $req  = Request::all();

        // who was responsible when (history)
        $open = DB::first('SELECT id, responsible_person_id FROM responsible_history WHERE machine_code = ? AND effective_to IS NULL', [$code], 'machines');
        $active = Mx::isActive($m['machine_status']);
        if ($open && (! $active || (int) $open['responsible_person_id'] !== (int) $m['responsible_person_id'])) {
            DB::exec('UPDATE responsible_history SET effective_to = CURRENT_DATE - 1 WHERE id = ?', [$open['id']], 'machines');
            $open = null;
        }
        if (! $open && $active && $m['responsible_person_id']) {
            DB::insert('responsible_history', ['machine_code' => $code, 'responsible_person_id' => $m['responsible_person_id'], 'effective_from' => date('Y-m-d')], 'machines');
        }

        if (! $active) {
            // a machine that is out of use leaves the plans
            DB::exec('UPDATE maintenance_record SET is_canceled = TRUE, canceled_at = CURRENT_DATE WHERE machine_code = ? AND actual_date IS NULL AND NOT is_canceled', [$code], 'machines');
            DB::exec('UPDATE calibration_record SET is_canceled = TRUE, canceled_at = CURRENT_DATE WHERE machine_code = ? AND certificate_date IS NULL AND NOT is_canceled', [$code], 'machines');
        } else {
            $this->syncMaintenance($m, (array) ($req['plan_maint'] ?? []));
            $this->syncCalibration($m, $m['is_calibration'] ? (array) ($req['plan_cal'] ?? []) : []);
        }
        $this->syncItems('machine_checklist', $code, (array) ($req['items_general'] ?? []), true);
        $this->syncItems('maintenance_checklist', $code, (array) ($req['items_maint'] ?? []), false);

        // the new responsible person hears about it
        if ($m['responsible_person_id'] && (int) $m['responsible_person_id'] !== $this->user()->id && (! $existing || (int) $existing['responsible_person_id'] !== (int) $m['responsible_person_id'])) {
            Notifier::deliver('machines', 'assigned', (int) $m['responsible_person_id'], ['key' => 'You look after machine :code', 'params' => ['code' => $code]],
                ['key' => ':who made you responsible for :code · :name. Do its checklist when it is due.', 'params' => ['who' => $this->user()->name, 'code' => $code, 'name' => $m['machine_name']]], url('/machines/machines/'.$id), $this->user()->id);
        }
    }

    private function syncMaintenance(array $m, array $rows): void
    {
        $code = $m['machine_code'];
        $keep = [];
        $perYear = [];
        foreach ($rows as $p) {
            if (! is_array($p) || empty($p['due_date']) || ! strtotime((string) $p['due_date'])) {
                continue;
            }
            $due  = substr((string) $p['due_date'], 0, 10);
            $year = substr($due, 0, 4);
            $perYear[$year] = ($perYear[$year] ?? (int) DB::scalar('SELECT count(*) FROM maintenance_record WHERE machine_code = ? AND years = ? AND actual_date IS NOT NULL', [$code, $year], 'machines')) + 1;
            $vals = ['machine_name' => $m['machine_name'], 'years' => $year, 'round' => $perYear[$year], 'due_date' => $due,
                     'plan_date' => ! empty($p['plan_date']) && strtotime((string) $p['plan_date']) ? substr((string) $p['plan_date'], 0, 10) : null,
                     'note' => trim((string) ($p['note'] ?? '')) ?: null, 'updated_by' => $this->user()->id, 'updated_at' => now()];
            $id = (int) ($p['id'] ?? 0);
            if ($id && DB::scalar('SELECT 1 FROM maintenance_record WHERE id = ? AND machine_code = ? AND actual_date IS NULL', [$id, $code], 'machines')) {
                DB::update('maintenance_record', $vals, ['id' => $id], 'machines');
                $keep[] = $id;
            } else {
                $keep[] = (int) DB::insert('maintenance_record', $vals + ['machine_code' => $code, 'created_by' => $this->user()->id], 'machines');
            }
        }
        $this->dropPending('maintenance_record', 'actual_date', $code, $keep);
    }

    private function syncCalibration(array $m, array $rows): void
    {
        $code = $m['machine_code'];
        $keep = [];
        foreach ($rows as $p) {
            if (! is_array($p) || empty($p['due_date']) || ! strtotime((string) $p['due_date'])) {
                continue;
            }
            $due  = substr((string) $p['due_date'], 0, 10);
            $vals = ['machine_name' => $m['machine_name'], 'years' => substr($due, 0, 4), 'due_date' => $due, 'note' => trim((string) ($p['note'] ?? '')) ?: null, 'updated_by' => $this->user()->id, 'updated_at' => now()];
            $id   = (int) ($p['id'] ?? 0);
            if ($id && DB::scalar('SELECT 1 FROM calibration_record WHERE id = ? AND machine_code = ? AND certificate_date IS NULL', [$id, $code], 'machines')) {
                DB::update('calibration_record', $vals, ['id' => $id], 'machines');
                $keep[] = $id;
            } else {
                $keep[] = (int) DB::insert('calibration_record', $vals + ['machine_code' => $code, 'created_by' => $this->user()->id], 'machines');
            }
        }
        $this->dropPending('calibration_record', 'certificate_date', $code, $keep);
    }

    /** Planned rows that are not in the form any more disappear; ones that already hold work are only cancelled. */
    private function dropPending(string $table, string $doneCol, string $code, array $keep): void
    {
        $not = $keep ? ' AND id NOT IN ('.implode(',', array_map('intval', $keep)).')' : '';
        DB::exec("DELETE FROM {$table} WHERE machine_code = ? AND {$doneCol} IS NULL AND NOT is_canceled{$not}", [$code], 'machines');
    }

    private function syncItems(string $table, string $code, array $rows, bool $withReset): void
    {
        $want = [];
        foreach ($rows as $p) {
            $qid = (int) ($p['question_id'] ?? 0);
            if (! $qid || ! DB::scalar('SELECT 1 FROM question WHERE id = ? AND deleted_at IS NULL', [$qid], 'machines')) {
                continue;
            }
            $reset = $withReset ? (isset(Mx::RESETS[$p['reset_time'] ?? '']) ? $p['reset_time'] : Mx::GENERAL) : null;
            $want[$qid.'|'.$reset] = [$qid, $reset];
        }
        $have = DB::select("SELECT id, question_id, reset_time FROM {$table} WHERE machine_code = ?", [$code], 'machines');
        foreach ($have as $h) {
            $k = $h['question_id'].'|'.($withReset ? $h['reset_time'] : null);
            if (isset($want[$k])) {
                unset($want[$k]);
            } else {
                DB::exec("DELETE FROM {$table} WHERE id = ?", [$h['id']], 'machines');
            }
        }
        foreach ($want as [$qid, $reset]) {
            $row = ['machine_code' => $code, 'question_id' => $qid, 'created_by' => $this->user()->id];
            if ($withReset) { $row['reset_time'] = $reset; }
            DB::exec("INSERT INTO {$table} (".implode(',', array_keys($row)).') VALUES ('.implode(',', array_fill(0, count($row), '?')).') ON CONFLICT DO NOTHING', array_values($row), 'machines');
        }
    }

    protected function beforeDelete(array $row): void
    {
        if (DB::scalar('SELECT 1 FROM checklist_record WHERE machine_code = ? LIMIT 1', [$row['machine_code']], 'machines')) {
            back('error', __('This machine has checklist history. Set its status to Scrapped or Cancelled instead of deleting it.'));
        }
        DB::exec('DELETE FROM maintenance_record WHERE machine_code = ?', [$row['machine_code']], 'machines');
        DB::exec('DELETE FROM calibration_record WHERE machine_code = ?', [$row['machine_code']], 'machines');
        DB::exec('DELETE FROM machine_checklist WHERE machine_code = ?', [$row['machine_code']], 'machines');
        DB::exec('DELETE FROM maintenance_checklist WHERE machine_code = ?', [$row['machine_code']], 'machines');
        DB::exec('DELETE FROM responsible_history WHERE machine_code = ?', [$row['machine_code']], 'machines');
        Files::merge($row['work_instruction'], [], []);
        Files::merge($row['warranty_files'], [], []);
    }

    /* ------------------------------------------------------------ detail page */

    public function show(int $id): string
    {
        $this->authorize($this->resource, 'view');
        $m    = $this->find($id);
        $code = $m['machine_code'];
        $u    = $this->user();

        $items = fn (string $tbl) => DB::select("SELECT i.*, q.detail, q.description, q.is_choice FROM {$tbl} i JOIN question q ON q.id = i.question_id WHERE i.machine_code = ? ORDER BY i.id", [$code], 'machines');
        $hist  = DB::select('SELECT h.* FROM responsible_history h WHERE h.machine_code = ? ORDER BY h.effective_from DESC, h.id DESC', [$code], 'machines');
        $names = Mx::names(array_column($hist, 'responsible_person_id'));
        foreach ($hist as &$h) { $h['name'] = $names[$h['responsible_person_id']] ?? '—'; }
        unset($h);

        return view('machines/machine', [
            'title' => $this->label($m), 'm' => $m, 'c' => $this->meta(),
            'canEdit' => can($this->resource, 'edit') && $this->canModify($m, 'edit'), 'canDelete' => can($this->resource, 'delete') && $this->canModify($m, 'delete'),
            'canCheck' => Mx::isActive($m['machine_status']) && can('machines_checklists', 'create'),
            'general' => $items('machine_checklist'), 'maint' => $items('maintenance_checklist'),
            'maintRecords' => DB::select('SELECT * FROM maintenance_record WHERE machine_code = ? AND NOT is_canceled ORDER BY years DESC, round, due_date', [$code], 'machines'),
            'calRecords'   => DB::select('SELECT * FROM calibration_record WHERE machine_code = ? AND NOT is_canceled ORDER BY due_date DESC', [$code], 'machines'),
            'checks'       => DB::select('SELECT id, check_type, recheck, checklist_status, machine_status, user_name, created_at FROM checklist_record WHERE machine_code = ? ORDER BY created_at DESC LIMIT 12', [$code], 'machines'),
            'history' => $hist, 'people' => Mx::people(), 'canChange' => can($this->resource, 'edit') && $this->canModify($m, 'edit'),
            'qrText' => Mx::qr($code), 'link' => url('/machines/m/'.rawurlencode($code)),
        ]);
    }

    /** Hand the machine to another responsible person (history is kept, the new person is told). */
    public function changeResponsible(int $id): never
    {
        $m = $this->findForChange($id, 'edit');
        $new = (int) Request::input('responsible_person_id');
        $name = Mx::names([$new])[$new] ?? null;
        if (! $name) {
            back('error', __('Pick a person.'));
        }
        if ($new === (int) $m['responsible_person_id']) {
            back('success', __('Nothing changed.'));
        }
        DB::update('machine', ['responsible_person_id' => $new, 'responsible_person_name' => $name, 'updated_at' => now(), 'updated_by' => $this->user()->id], ['id' => $id], 'machines');
        DB::exec('UPDATE responsible_history SET effective_to = CURRENT_DATE - 1 WHERE machine_code = ? AND effective_to IS NULL', [$m['machine_code']], 'machines');
        DB::insert('responsible_history', ['machine_code' => $m['machine_code'], 'responsible_person_id' => $new, 'effective_from' => date('Y-m-d')], 'machines');
        Activity::log('updated', $this->type, $id, $this->label($m), ['Changed the person responsible for ":label"', ['label' => $this->label($m)]],
            ['changes' => ['responsible' => ['from' => $m['responsible_person_name'], 'to' => $name]], '_url' => url($this->base.'/'.$id)], $new, module: 'machines');
        if ($new !== $this->user()->id) {
            Notifier::deliver('machines', 'assigned', $new, ['key' => 'You look after machine :code', 'params' => ['code' => $m['machine_code']]],
                ['key' => ':who made you responsible for :code · :name. Do its checklist when it is due.', 'params' => ['who' => $this->user()->name, 'code' => $m['machine_code'], 'name' => $m['machine_name']]], url('/machines/machines/'.$id), $this->user()->id);
        }
        Session::flash('success', __('Responsible person changed.'));
        redirect($this->base.'/'.$id);
    }

    /** Printable QR labels for the machines in ?ids=1,2,3 (or every machine in the list when empty). */
    public function labels(): string
    {
        $this->authorize($this->resource, 'view');
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) Request::query('ids', '')))));
        $s   = Mx::scope($this->user(), 't');
        $sql = 'SELECT t.id, t.machine_code, t.machine_name, t.department_id FROM machine t WHERE t.deleted_at IS NULL AND '.$s['sql'];
        $par = $s['params'];
        if ($ids) {
            $sql .= ' AND t.id IN ('.implode(',', $ids).')';
        }
        $rows  = DB::select($sql.' ORDER BY t.machine_code LIMIT 200', $par, 'machines');
        $depts = Mx::departments();
        foreach ($rows as &$r) {
            $r['qr'] = Mx::qr($r['machine_code']);
            $r['department'] = $depts[$r['department_id']] ?? '';
        }
        unset($r);

        return view('machines/labels', ['title' => __('QR labels'), 'rows' => $rows, 'layout' => false]);
    }
}
