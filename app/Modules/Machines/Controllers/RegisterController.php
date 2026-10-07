<?php
declare(strict_types=1);

namespace App\Modules\Machines\Controllers;

use App\Core\Support\DB;
use App\Core\Support\Notifier;
use App\Core\Support\Request;
use App\Core\Support\ValidationException;
use App\Modules\Machines\Support\Files;
use App\Modules\Machines\Support\Mx;

/**
 * "I want a new machine registered": anybody who buys or receives a machine fills this in
 * (what it is, who looks after it, its maintenance and calibration plan, papers). An administrator or manager
 * then turns the request into a machine (it gets a code and a QR label).
 */
class RegisterController extends MachinesController
{
    protected string $resource = 'machines_register';
    protected string $table = 'register_request';
    protected string $type = 'machine_register';
    protected string $singular = 'register request';
    protected string $plural = 'register requests';
    protected string $base = '/machines/register';
    protected string $icon = 'doc';
    protected bool $wizard = true;

    protected function scopeSql(): array
    {
        $u = $this->user();
        if ($u->scope() === 'all') {
            return ['sql' => 'TRUE', 'params' => []];
        }
        $mine = 't.created_by = ? OR t.supervisor_id = ? OR t.manager_id = ? OR t.responsible_id = ?';
        if ($u->scope() === 'department' && $u->department_id) {
            return ['sql' => "(t.department_id = ? OR {$mine})", 'params' => [$u->department_id, $u->id, $u->id, $u->id, $u->id]];
        }

        return ['sql' => "({$mine})", 'params' => [$u->id, $u->id, $u->id, $u->id]];
    }

    protected function select(): string
    {
        return 'SELECT t.*, (SELECT string_agg(m.machine_code, \', \' ORDER BY m.id) FROM machine m WHERE m.register_id = t.id AND m.deleted_at IS NULL) AS machine_codes,
                       (SELECT count(*) FROM machine m WHERE m.register_id = t.id AND m.deleted_at IS NULL) AS machine_count FROM register_request t';
    }

    protected function hydrate(array $rows): array
    {
        $rows  = $this->withPeople($rows, ['created_by', 'responsible_id', 'supervisor_id', 'manager_id']);
        $depts = Mx::departments();
        foreach ($rows as &$r) {
            $r['department_name'] = $depts[$r['department_id']] ?? null;
        }

        return $rows;
    }

    protected function label(array $row): string { return (string) ($row['machine_name'] ?: '#'.$row['id']); }

    protected function owner(array $row): ?int { return $row['created_by'] ? (int) $row['created_by'] : null; }

    protected function canModify(array $row, string $action): bool
    {
        $u = $this->user();
        if ((int) $row['machine_count'] > 0 && $action === 'delete') {
            return false;                       // a machine was made from it
        }

        return $u->scope() === 'all' || (int) $row['created_by'] === $u->id || ($u->scope() === 'department' && (int) $row['department_id'] === $u->department_id);
    }

    protected function searchable(): array { return ['t.machine_name', 't.brand', 't.model', 't.serial_number', 't.note']; }

    protected function filters(): array
    {
        return [
            'dept'  => ['label' => __('All departments'), 'options' => Mx::departments(), 'column' => 't.department_id'],
            'state' => ['label' => __('All requests'), 'options' => ['waiting' => __('Waiting for a machine'), 'done' => __('Machine created')],
                        'sql' => "(CASE ? WHEN 'done' THEN EXISTS (SELECT 1 FROM machine m WHERE m.register_id = t.id AND m.deleted_at IS NULL) ELSE NOT EXISTS (SELECT 1 FROM machine m WHERE m.register_id = t.id AND m.deleted_at IS NULL) END)"],
        ];
    }

    protected function fields(?array $row): array
    {
        $people = Mx::people();
        $row ??= [];
        $rows = fn (string $col) => array_values(json_decode((string) ($row[$col] ?? ''), true) ?: []);

        return [
            '_what'         => ['section' => __('Machine')],
            'machine_name'  => ['label' => __('Machine name'), 'rules' => 'required|max:200', 'span' => 2, 'example' => 'Hydraulic press 200 t'],
            'department_id' => ['label' => __('Department'), 'type' => 'select', 'options' => Mx::departments(), 'rules' => 'required', 'default' => $this->user()->department_id, 'example' => '1'],
            'is_new'        => ['label' => __('Bought new'), 'type' => 'checkbox', 'default' => true, 'help' => __('Untick for a second-hand or transferred machine.')],
            'brand'         => ['label' => __('Brand'), 'rules' => 'nullable|max:120'],
            'model'         => ['label' => __('Model'), 'rules' => 'nullable|max:120'],
            'serial_number' => ['label' => __('Serial number'), 'rules' => 'nullable|max:120'],
            'quantity'      => ['label' => __('Quantity'), 'type' => 'number', 'rules' => 'nullable|integer', 'default' => 1],
            'price'         => ['label' => __('Price'), 'type' => 'number', 'rules' => 'nullable|numeric'],
            'watt'          => ['label' => __('Power (watt)'), 'type' => 'number', 'rules' => 'nullable|integer'],
            'horse_power'   => ['label' => __('Power (horse power)'), 'type' => 'number', 'rules' => 'nullable|integer'],
            'note'          => ['label' => __('Note'), 'type' => 'textarea', 'rules' => 'nullable|max:3000', 'span' => 2],

            '_people'        => ['section' => __('Who looks after it')],
            'responsible_id' => ['label' => __('Responsible person'), 'type' => 'select', 'options' => $people, 'rules' => 'nullable', 'help' => __('Does the daily and weekly check.')],
            'supervisor_id'  => ['label' => __('Supervisor'), 'type' => 'select', 'options' => $people, 'rules' => 'nullable', 'help' => __('Approves the check first.')],
            'manager_id'     => ['label' => __('Manager'), 'type' => 'select', 'options' => $people, 'rules' => 'nullable', 'help' => __('Approves last.')],

            '_plans'      => ['section' => __('Maintenance and calibration plan')],
            'plan_maint' => ['label' => __('Maintenance rounds'), 'type' => 'custom', 'partial' => 'machines/fields/plan', 'plan' => 'maintenance', 'rows' => $rows('maintenance'), 'table' => false, 'import' => false, 'span' => 2, 'rules' => 'nullable', 'display' => fn () => '', 'help' => __('One row for each planned maintenance of the year.')],
            'plan_cal' => ['label' => __('Calibration due dates'), 'type' => 'custom', 'partial' => 'machines/fields/plan', 'plan' => 'calibration', 'rows' => $rows('calibration'), 'table' => false, 'import' => false, 'span' => 2, 'rules' => 'nullable', 'display' => fn () => '', 'help' => __('Leave empty when the machine is not calibrated.')],

            '_docs'            => ['section' => __('Documents')],
            'att_files'        => ['label' => __('Quotation, drawings, photos'), 'type' => 'custom', 'partial' => 'machines/fields/files', 'column' => 'attachment', 'table' => false, 'import' => false, 'span' => 2, 'rules' => 'nullable', 'display' => fn () => ''],
            'wi_files'         => ['label' => __('Work instruction'), 'type' => 'custom', 'partial' => 'machines/fields/files', 'column' => 'work_instruction', 'table' => false, 'import' => false, 'span' => 2, 'rules' => 'nullable', 'display' => fn () => ''],
            'has_warranty'     => ['label' => __('Warranty'), 'type' => 'select', 'options' => ['YES' => __('Yes'), 'NO' => __('No')], 'rules' => 'nullable', 'search' => false],
            'warranty_expire_date' => ['label' => __('Warranty ends'), 'type' => 'date', 'rules' => 'nullable|date', 'show_when' => 'has_warranty=YES'],
            'warranty_note'    => ['label' => __('Warranty note'), 'type' => 'textarea', 'rules' => 'nullable|max:2000', 'span' => 2, 'show_when' => 'has_warranty=YES'],
            'war_files'        => ['label' => __('Warranty papers'), 'type' => 'custom', 'partial' => 'machines/fields/files', 'column' => 'warranty_files', 'table' => false, 'import' => false, 'span' => 2, 'rules' => 'nullable', 'display' => fn () => '', 'show_when' => 'has_warranty=YES'],
        ];
    }

    protected function columns(): array
    {
        return [
            'machine' => ['label' => __('Machine'), 'primary' => true, 'sort' => 't.machine_name', 'render' => fn ($r) => '<span class="font-medium">'.e($r['machine_name']).'</span><span class="block text-xs text-steel">'.e(trim(($r['brand'] ?? '').' '.($r['model'] ?? ''))).'</span>'],
            'dept'    => ['label' => __('Department'), 'render' => fn ($r) => e($r['department_name'] ?? '—')],
            'by'      => ['label' => __('Requested by'), 'render' => fn ($r) => e($r['created_by_name'] ?? '—').'<span class="block text-xs text-steel">'.e(format_date($r['created_at'])).'</span>'],
            'resp'    => ['label' => __('Responsible'), 'render' => fn ($r) => e($r['responsible_id_name'] ?? '—')],
            'state'   => ['label' => __('Status'), 'render' => fn ($r) => (int) $r['machine_count'] > 0
                ? Mx::pill(__('Machine created'), 'ok').'<span class="mt-0.5 block text-xs text-steel">'.e($r['machine_codes']).'</span>' : Mx::pill(__('Waiting'), 'warn')],
        ];
    }

    protected function rowLinks(array $row): array
    {
        return can('machines_machines', 'create') && (int) $row['machine_count'] === 0 ? [['url' => '/machines/machines/create?register='.$row['id'], 'label' => __('Create the machine'), 'icon' => 'gear']] : [];
    }

    protected function prepare(array $data, ?array $existing): array
    {
        $cur = $existing ?? [];
        foreach (['att_files' => 'attachment', 'wi_files' => 'work_instruction', 'war_files' => 'warranty_files'] as $key => $col) {
            $keep = array_map('strval', (array) (Request::all()['keep_'.$col] ?? []));
            $data[$col] = Files::merge($cur[$col] ?? null, $keep, Files::saveMany($_FILES[$key] ?? null, $key));
        }
        if (($data['has_warranty'] ?? '') !== 'YES') {
            $data['warranty_expire_date'] = null;
            $data['warranty_note'] = null;
            Files::merge($data['warranty_files'] ?? null, [], []);        // a machine without warranty keeps no warranty papers
            $data['warranty_files'] = null;
        }
        foreach (['plan_maint' => ['maintenance', true], 'plan_cal' => ['calibration', false]] as $key => [$col, $withPlan]) {
            $list = [];
            foreach ((array) (Request::all()[$key] ?? []) as $p) {
                if (! is_array($p) || empty($p['due_date'])) {
                    continue;
                }
                if (! strtotime((string) $p['due_date'])) {
                    throw new ValidationException([$key => __('A due date is not a valid date.')]);
                }
                $item = ['due_date' => substr((string) $p['due_date'], 0, 10), 'note' => mb_substr(trim((string) ($p['note'] ?? '')), 0, 500)];
                if ($withPlan) {
                    $item['plan_date'] = ! empty($p['plan_date']) && strtotime((string) $p['plan_date']) ? substr((string) $p['plan_date'], 0, 10) : null;
                }
                $list[] = $item;
            }
            usort($list, fn ($a, $b) => strcmp($a['due_date'], $b['due_date']));
            $data[$col] = $list ? json_encode($list) : null;
        }

        return $this->stamp($data, $existing);
    }

    protected function saved(int $id, array $data, ?array $existing): void
    {
        if ($existing) {
            return;
        }
        // tell the people who turn requests into machines
        $row = DB::first('SELECT * FROM register_request WHERE id = ?', [$id], 'machines');
        $to  = array_column(DB::select("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active AND u.deleted_at IS NULL AND r.slug = 'admin'"), 'id');
        foreach (array_unique($to) as $uid) {
            if ((int) $uid !== $this->user()->id) {
                Notifier::deliver('machines', 'created', (int) $uid, ['key' => 'New machine register request: :name', 'params' => ['name' => $row['machine_name']]],
                    ['key' => ':who asks to register ":name". Open the request to create the machine.', 'params' => ['who' => $this->user()->name, 'name' => $row['machine_name']]], url('/machines/register/'.$id), $this->user()->id);
            }
        }
    }

    protected function beforeDelete(array $row): void
    {
        foreach (['attachment', 'work_instruction', 'warranty_files'] as $col) {
            Files::merge($row[$col] ?? null, [], []);
        }
    }

    public function show(int $id): string
    {
        $this->authorize($this->resource, 'view');
        $row = $this->find($id);

        return view('machines/register-show', ['title' => $this->label($row), 'row' => $row, 'c' => $this->meta(),
            'canEdit' => can($this->resource, 'edit') && $this->canModify($row, 'edit'), 'canDelete' => can($this->resource, 'delete') && $this->canModify($row, 'delete'),
            'canMake' => can('machines_machines', 'create') && (int) $row['machine_count'] === 0]);
    }
}
