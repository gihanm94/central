<?php
declare(strict_types=1);

namespace App\Modules\Machines\Controllers;

use App\Core\Support\DB;
use App\Core\Support\Notifier;
use App\Core\Support\Request;
use App\Core\Support\ValidationException;
use App\Modules\Machines\Support\Files;
use App\Modules\Machines\Support\Mx;

/** Calibration of measuring machines: the due date, the certificate and what the lab measured. */
class CalibrationController extends MachinesController
{
    protected string $resource = 'machines_calibration';
    protected string $table = 'calibration_record';
    protected string $type = 'machine_calibration';
    protected string $singular = 'calibration';
    protected string $plural = 'calibration';
    protected string $base = '/machines/calibration';
    protected string $icon = 'target';
    protected bool $softDelete = false;
    protected bool $wizard = true;
    protected string $orderBy = 't.due_date ASC NULLS LAST, t.id';

    protected function select(): string
    {
        return 'SELECT t.*, m.id AS machine_id, m.department_id, m.responsible_person_id, m.supervisor_id, m.manager_id, m.machine_status, m.machine_name AS m_name FROM calibration_record t LEFT JOIN machine m ON m.machine_code = t.machine_code';
    }

    protected function scopeSql(): array
    {
        $u = $this->user();
        if ($u->scope() === 'all') {
            return ['sql' => 'NOT t.is_canceled', 'params' => []];
        }
        $s = Mx::scope($u, 'm');

        return ['sql' => "(NOT t.is_canceled AND m.id IS NOT NULL AND {$s['sql']})", 'params' => $s['params']];
    }

    protected function hydrate(array $rows): array
    {
        $depts = Mx::departments();
        foreach ($rows as &$r) {
            $r['department_name'] = $depts[$r['department_id']] ?? null;
            $r['state'] = $r['certificate_date'] ? ($r['due_date'] && $r['certificate_date'] > $r['due_date'] ? 'late' : 'done') : ($r['due_date'] && $r['due_date'] < date('Y-m-d') ? 'overdue' : 'planned');
        }

        return $rows;
    }

    protected function label(array $row): string { return $row['machine_code'].' · '.($row['due_date'] ? format_date($row['due_date']) : $row['years']); }

    protected function owner(array $row): ?int { return ! empty($row['responsible_person_id']) ? (int) $row['responsible_person_id'] : null; }

    protected function canModify(array $row, string $action): bool
    {
        $u = $this->user();
        if ($action === 'delete') {
            return $u->scope() === 'all' && ! $row['certificate_date'];
        }

        return $row['machine_id'] ? Mx::manages($u, ['department_id' => $row['department_id'], 'responsible_person_id' => $row['responsible_person_id'], 'supervisor_id' => $row['supervisor_id'], 'manager_id' => $row['manager_id']]) : $u->scope() === 'all';
    }

    protected function searchable(): array { return ['t.machine_code', 't.machine_name', 't.note', 't.comment']; }

    protected function filters(): array
    {
        $today = date('Y-m-d');

        return [
            'year'  => ['label' => __('All years'), 'options' => array_column(DB::select('SELECT DISTINCT years FROM calibration_record ORDER BY years DESC', [], 'machines'), 'years', 'years'), 'column' => 't.years'],
            'state' => ['label' => __('All states'), 'options' => ['planned' => __('Planned'), 'overdue' => __('Overdue'), 'done' => __('Done on time'), 'late' => __('Done late')],
                        'sql' => "(CASE ? WHEN 'planned' THEN t.certificate_date IS NULL AND (t.due_date IS NULL OR t.due_date >= '{$today}') WHEN 'overdue' THEN t.certificate_date IS NULL AND t.due_date < '{$today}' WHEN 'done' THEN t.certificate_date IS NOT NULL AND (t.due_date IS NULL OR t.certificate_date <= t.due_date) ELSE t.certificate_date IS NOT NULL AND t.certificate_date > t.due_date END)"],
            'result' => ['label' => __('Any result'), 'options' => ['PASS' => __('Pass'), 'FAILED' => __('Failed')], 'column' => 't.results'],
            'dept'  => ['label' => __('All departments'), 'options' => Mx::departments(), 'column' => 'm.department_id'],
        ];
    }

    private function machineOptions(): array
    {
        $s = Mx::scope($this->user(), 'm');

        return array_column(DB::select("SELECT m.machine_code, m.machine_code || ' · ' || m.machine_name AS label FROM machine m WHERE m.deleted_at IS NULL AND m.is_calibration AND m.machine_status IN (".Mx::activeIn().") AND {$s['sql']} ORDER BY m.machine_code", $s['params'], 'machines'), 'label', 'machine_code');
    }

    protected function fields(?array $row): array
    {
        $pf = ['PASS' => __('Pass'), 'FAIL' => __('Fail')];
        $f = [];
        if (! $row) {
            $f['_what'] = ['section' => __('Calibration')];
            $f['machine_code'] = ['label' => __('Machine'), 'type' => 'select', 'options' => $this->machineOptions(), 'rules' => 'required', 'span' => 2, 'default' => Request::query('machine'), 'help' => __('Only machines marked "needs calibration" are listed.')];
        } else {
            $f['_what'] = ['section' => __('Calibration')];
        }
        $f += [
            'due_date'         => ['label' => __('Due date'), 'type' => 'date', 'rules' => 'required|date'],
            'start_date'       => ['label' => __('Sent for calibration'), 'type' => 'date', 'rules' => 'nullable|date'],
            'certificate_date' => ['label' => __('Certificate date'), 'type' => 'date', 'rules' => 'nullable|date', 'help' => __('Filling this in closes the round.')],
            'calibration_status' => ['label' => __('Progress'), 'rules' => 'nullable|max:30', 'example' => 'In progress'],
            'results'          => ['label' => __('Result'), 'type' => 'select', 'options' => ['PASS' => __('Pass'), 'FAILED' => __('Failed')], 'rules' => 'nullable', 'search' => false],
            'reason_not_pass'  => ['label' => __('Why it failed'), 'type' => 'textarea', 'rules' => 'nullable|max:2000', 'span' => 2, 'show_when' => 'results=FAILED'],
            'note'             => ['label' => __('Note'), 'type' => 'textarea', 'rules' => 'nullable|max:3000', 'span' => 2],
            'docs'             => ['label' => __('Certificate and reports'), 'type' => 'custom', 'partial' => 'machines/fields/files', 'column' => 'attachment', 'table' => false, 'import' => false, 'span' => 2, 'rules' => 'nullable', 'display' => fn () => ''],

            '_spec'              => ['section' => __('Measurements')],
            'criteria'           => ['label' => __('Acceptance criteria'), 'rules' => 'nullable|max:500'],
            'measuring_range'    => ['label' => __('Measuring range'), 'rules' => 'nullable|max:500'],
            'accuracy'           => ['label' => __('Accuracy'), 'rules' => 'nullable|max:500'],
            'calibration_range'  => ['label' => __('Calibration range'), 'rules' => 'nullable|max:500'],
            'permissible_capacity' => ['label' => __('Permissible capacity'), 'rules' => 'nullable|max:500'],
            'resolution'         => ['label' => __('Resolution'), 'rules' => 'nullable|max:500'],
            'max_uncertainty'    => ['label' => __('Max. uncertainty'), 'rules' => 'nullable|max:500'],
            'mpe'                => ['label' => __('MPE (maximum permissible error)'), 'rules' => 'nullable|max:500'],
            'check_mpe'          => ['label' => __('MPE check'), 'type' => 'select', 'options' => $pf, 'rules' => 'nullable', 'search' => false],
            'check_resolution'   => ['label' => __('Resolution check'), 'type' => 'select', 'options' => $pf, 'rules' => 'nullable', 'search' => false],
            'check_result'       => ['label' => __('Overall check'), 'type' => 'select', 'options' => $pf, 'rules' => 'nullable', 'search' => false],
            'comment'            => ['label' => __('Comment'), 'type' => 'textarea', 'rules' => 'nullable|max:3000', 'span' => 2],
        ];

        return $f;
    }

    protected function columns(): array
    {
        return [
            'machine' => ['label' => __('Machine'), 'primary' => true, 'sort' => 't.machine_code', 'render' => fn ($r) => '<span class="font-medium tabular-nums">'.e($r['machine_code']).'</span><span class="block text-xs text-steel">'.e($r['machine_name'] ?? $r['m_name']).'</span>'],
            'due'     => ['label' => __('Due'), 'sort' => 't.due_date', 'render' => fn ($r) => e($r['due_date'] ? format_date($r['due_date']) : '—')],
            'cert'    => ['label' => __('Certificate'), 'sort' => 't.certificate_date', 'render' => fn ($r) => e($r['certificate_date'] ? format_date($r['certificate_date']) : '—')],
            'state'   => ['label' => __('Status'), 'render' => fn ($r) => match ($r['state']) { 'done' => Mx::pill(__('Done on time'), 'ok'), 'late' => Mx::pill(__('Done late'), 'warn'), 'overdue' => Mx::pill(__('Overdue'), 'bad'), default => Mx::pill(__('Planned'), 'neutral') }.($r['calibration_status'] ? '<span class="block text-xs text-steel">'.e($r['calibration_status']).'</span>' : '')],
            'result'  => ['label' => __('Result'), 'sort' => 't.results', 'render' => fn ($r) => $r['results'] ? Mx::pill($r['results'] === 'PASS' ? __('Pass') : __('Failed'), $r['results'] === 'PASS' ? 'ok' : 'bad') : '<span class="text-steel">—</span>'],
            'dept'    => ['label' => __('Department'), 'render' => fn ($r) => e($r['department_name'] ?? '—')],
        ];
    }

    protected function prepare(array $data, ?array $existing): array
    {
        if (! $existing) {
            $m = Mx::machine((string) $data['machine_code']);
            if (! $m || ! $m['is_calibration'] || ! Mx::isActive($m['machine_status']) || ! Mx::canSee($this->user(), $m['machine_code'])) {
                throw new ValidationException(['machine_code' => __('Pick a machine.')]);
            }
            $data['machine_name'] = $m['machine_name'];
        }
        $data['years'] = substr((string) $data['due_date'], 0, 4);
        if (($data['results'] ?? '') === 'FAILED' && trim((string) ($data['reason_not_pass'] ?? '')) === '') {
            throw new ValidationException(['reason_not_pass' => __('Write why the calibration failed.')]);
        }
        if (($data['results'] ?? '') !== 'FAILED') {
            $data['reason_not_pass'] = null;
        }
        if (! empty($data['certificate_date']) && empty($data['results'])) {
            throw new ValidationException(['results' => __('Choose pass or failed for a calibration with a certificate.')]);
        }
        $keep = array_map('strval', (array) (Request::all()['keep_attachment'] ?? []));
        $data['attachment'] = Files::merge($existing['attachment'] ?? null, $keep, Files::saveMany($_FILES['docs'] ?? null, 'docs'));

        return $this->stamp($data, $existing);
    }

    protected function saved(int $id, array $data, ?array $existing): void
    {
        $row = DB::first('SELECT c.*, m.id AS mid, m.machine_name AS mname, m.responsible_person_id, m.supervisor_id, m.manager_id FROM calibration_record c JOIN machine m ON m.machine_code = c.machine_code WHERE c.id = ?', [$id], 'machines');
        if (! $row || ! $row['certificate_date'] || ($existing && $existing['certificate_date'])) {
            return;                                           // only the moment the certificate arrives
        }
        foreach (Mx::team($row) as $uid) {
            if ($uid !== $this->user()->id) {
                Notifier::deliver('machines', 'updated', $uid, ['key' => 'Calibration of :code: :result', 'params' => ['code' => $row['machine_code'], 'result' => $row['results'] === 'PASS' ? __('pass') : __('failed')]],
                    ['key' => 'The calibration of :code · :name was closed as :result.', 'params' => ['code' => $row['machine_code'], 'name' => $row['mname'], 'result' => $row['results'] === 'PASS' ? __('pass') : __('failed')]], url('/machines/calibration/'.$id), $this->user()->id);
            }
        }
    }

    protected function beforeDelete(array $row): void { Files::merge($row['attachment'], [], []); }
}
