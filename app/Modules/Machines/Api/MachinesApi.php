<?php
declare(strict_types=1);

namespace App\Modules\Machines\Api;

use App\Core\Api\ApiException;
use App\Core\Api\Controller;
use App\Core\Api\Kernel;
use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Modules\Machines\Controllers\ChecklistController;
use App\Modules\Machines\Support\Checklists;
use App\Modules\Machines\Support\Mx;

/**
 * Machine Checklist for the mobile app: scan a QR code, see the machine, do today's check (with a photo),
 * approve re-checks, see what is due. Same rules as the website: scanning is open to every signed-in person
 * (anyone who uses a machine does its general check); lists follow Mx::scope; saving and approving run the
 * website's own actions.
 */
class MachinesApi extends Controller
{
    private const C = 'machines';
    private const MACHINE = ['id', 'machine_code', 'machine_name', 'machine_group_name', 'machine_type_name', 'brand', 'model', 'serial_number', 'machine_number',
        'machine_status', 'check_status', 'department_id', 'is_calibration', 'reset_period', 'maintenance_period', 'image_url', 'note'];

    private function machineOut(array $m): array
    {
        $m['image_url'] = $m['image'] ? upload_url((string) $m['image']) : null;

        return self::pick($m, self::MACHINE);
    }

    /** POST {text: what the camera read} → the machine (JSON QR, a /machines/m/{code} link or a bare code). */
    public function scan(): array
    {
        $code = Mx::codeFromScan(mb_substr((string) ($this->input(['text'])['text'] ?? ''), 0, 500));
        if ($code === null || ! Mx::machine($code)) {
            throw new ApiException(404, 'machine_not_found', __('No machine with this code.'));
        }

        return $this->machine($code);
    }

    /** ?q= (2+ characters) → machines in use whose code, name, serial or number match. */
    public function find(): array
    {
        $q = $this->q();
        if (mb_strlen($q) < 2) {
            return ['data' => []];
        }
        $like = $this->like($q);
        $rows = DB::select('SELECT m.*, t.machine_group_name, t.machine_type_name FROM machine m
            LEFT JOIN machine_type t ON t.machine_group_id = m.machine_group_id AND t.machine_type_id = m.machine_type_id AND t.deleted_at IS NULL
            WHERE m.deleted_at IS NULL AND m.machine_status IN ('.Mx::activeIn().') AND (m.machine_code ILIKE ? OR m.machine_name ILIKE ? OR m.serial_number ILIKE ? OR m.machine_number ILIKE ?)
            ORDER BY m.machine_code LIMIT 20', [$like, $like, $like, $like], self::C);

        return ['data' => array_map(fn ($m) => $this->machineOut($m), $rows)];
    }

    /** The scan landing: machine, people, today's checklist, last checks, next maintenance / calibration. */
    public function machine(string|int $code): array            // digit-only codes arrive as int
    {
        $code = (string) $code;
        $u = $this->user();
        $m = Mx::machine($code) ?? throw new ApiException(404, 'machine_not_found', __('No machine with this code.'));
        $plan = Mx::isActive($m['machine_status']) ? Checklists::plan($m, $u) : null;
        $names = Mx::names([$m['responsible_person_id'], $m['supervisor_id'], $m['manager_id']]);
        $person = fn ($id) => $id ? ['id' => (int) $id, 'name' => $names[$id] ?? null] : null;

        return ['data' => [
            'machine' => $this->machineOut($m),
            'people' => ['responsible' => $person($m['responsible_person_id']), 'supervisor' => $person($m['supervisor_id']), 'manager' => $person($m['manager_id'])],
            'checklist' => $plan ? [
                'can_check' => ! $plan['empty'] && $u->can('machines_checklists', 'create'),
                'recheck' => $plan['recheck'], 'weekend' => $plan['weekend'],
                'machine_statuses' => Mx::ACTIVE,
                'items' => array_map(fn ($i) => ['id' => (int) $i['id'], 'question' => $i['detail'], 'description' => $i['description'],
                    'answer_type' => filter_var($i['is_choice'], FILTER_VALIDATE_BOOL) ? 'choice' : 'text', 'choices' => filter_var($i['is_choice'], FILTER_VALIDATE_BOOL) ? ['OK', 'NG', 'NA'] : null], $plan['items']),
            ] : null,
            'last_checks' => DB::select('SELECT id, check_type, recheck, checklist_status, machine_status, user_name, created_at FROM checklist_record WHERE machine_code = ? ORDER BY created_at DESC LIMIT 5', [$m['machine_code']], self::C),
            'next_maintenance' => DB::scalar('SELECT due_date FROM maintenance_record WHERE machine_code = ? AND actual_date IS NULL AND NOT is_canceled ORDER BY due_date LIMIT 1', [$m['machine_code']], self::C),
            'next_calibration' => DB::scalar('SELECT due_date FROM calibration_record WHERE machine_code = ? AND certificate_date IS NULL AND NOT is_canceled ORDER BY due_date LIMIT 1', [$m['machine_code']], self::C),
        ]];
    }

    /**
     * Save today's check. JSON {machine_status, answers: {<item id>: {answer, remark}}, machine_note}
     * or multipart/form-data with the same fields plus "image" (photo, up to 2 MB).
     */
    public function check(string|int $code): array
    {
        $code = (string) $code;
        $in = $this->input(['machine_status', 'answers', 'machine_note']);
        $in['machine_code'] = $code;
        $r = Kernel::delegate(fn () => (new ChecklistController())->store(), $in);

        return $this->record((int) $r['id']) + ['_status' => 201];
    }

    /** My checks, newest first (?pending=1: re-checks waiting for my approval instead). */
    public function checklists(): array
    {
        $this->need('machines_checklists');
        $u = $this->user();
        if (Request::query('pending') === '1') {
            $sql = "SELECT * FROM checklist_record WHERE (checklist_status = 'PENDING SUPERVISOR' AND supervisor = ?) OR (checklist_status = 'PENDING MANAGER' AND manager = ?)";
            $params = [$u->id, $u->id];
        } else {
            $sql = 'SELECT * FROM checklist_record WHERE created_by = ?';
            $params = [$u->id];
        }

        return $this->paged($sql, $params, 'created_at DESC, id DESC', self::C, fn ($r) => $this->recordOut($r, false));
    }

    public function record(int $id): array
    {
        $this->need('machines_checklists');
        $u = $this->user();
        $r = DB::first('SELECT * FROM checklist_record WHERE id = ?', [$id], self::C) ?? $this->notFound();
        if ((int) $r['created_by'] !== $u->id && ! Checklists::canApprove($r, $u) && ! Mx::canSee($u, (string) $r['machine_code'])) {
            $this->notFound();
        }

        return ['data' => $this->recordOut($r, true)];
    }

    private function recordOut(array $r, bool $full): array
    {
        $u = $this->user();
        $out = self::pick($r, ['id', 'check_type', 'recheck', 'machine_code', 'machine_name', 'machine_status', 'checklist_status', 'machine_note', 'user_name', 'created_at',
            'date_supervisor_checked', 'date_manager_checked', 'reason_not_checked']);
        $out['can_approve'] = Checklists::canApprove($r, $u);
        if ($full) {
            $out['answers'] = array_map(fn ($a) => ['question' => $a['detail'] ?? null, 'answer' => $a['answer'] ?? null, 'remark' => $a['remark'] ?? null],
                (array) json_decode((string) $r['machine_checklist'], true));
            $out['image_url'] = $r['image'] ? upload_url((string) $r['image']) : null;
        }

        return $out;
    }

    /** Approve a re-check that waits for me (supervisor, then manager). {note?} */
    public function approve(int $id): array
    {
        $this->need('machines_checklists');
        $r = DB::first('SELECT * FROM checklist_record WHERE id = ?', [$id], self::C) ?? $this->notFound();
        if (! Checklists::canApprove($r, $this->user())) {
            throw new ApiException(403, 'not_waiting_for_you', __('This checklist is not waiting for you.'));
        }
        Kernel::delegate(fn () => (new ChecklistController())->approve($id), ['note' => mb_substr((string) ($this->input(['note'])['note'] ?? ''), 0, 500)], true);

        return $this->record($id);
    }

    /** Maintenance and calibration of my machines that are overdue or due within ?days= (default 30, max 90). */
    public function due(): array
    {
        $u = $this->user();
        $days = max(1, min(90, (int) Request::query('days', 30)));
        $s = Mx::scope($u);
        $out = [];
        if ($u->can('machines_maintenance', 'view')) {
            $out['maintenance'] = DB::select("SELECT r.id, r.machine_code, r.machine_name, r.maintenance_type, r.due_date, r.plan_date, r.start_date, r.due_date < CURRENT_DATE AS overdue
                FROM maintenance_record r JOIN machine m ON m.machine_code = r.machine_code AND m.deleted_at IS NULL
                WHERE r.actual_date IS NULL AND NOT r.is_canceled AND r.due_date <= CURRENT_DATE + ?::int AND {$s['sql']} ORDER BY r.due_date LIMIT 200", [$days, ...$s['params']], self::C);
        }
        if ($u->can('machines_calibration', 'view')) {
            $out['calibration'] = DB::select("SELECT r.id, r.machine_code, m.machine_name, r.due_date, r.start_date, r.due_date < CURRENT_DATE AS overdue
                FROM calibration_record r JOIN machine m ON m.machine_code = r.machine_code AND m.deleted_at IS NULL
                WHERE r.certificate_date IS NULL AND NOT r.is_canceled AND r.due_date <= CURRENT_DATE + ?::int AND {$s['sql']} ORDER BY r.due_date LIMIT 200", [$days, ...$s['params']], self::C);
        }

        return ['data' => $out];
    }
}
