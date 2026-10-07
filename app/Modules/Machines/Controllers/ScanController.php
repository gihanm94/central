<?php
declare(strict_types=1);

namespace App\Modules\Machines\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Modules\Machines\Support\Checklists;
use App\Modules\Machines\Support\Mx;

/** The shop-floor entry: scan a machine's QR code (or type its code) and land on the machine, ready to check. */
class ScanController extends Controller
{
    public function home(): never
    {
        redirect(can('machines_checklists', 'view') ? '/machines/checklists' : '/machines/scan');
    }

    public function scan(): string
    {
        $u = $this->user();
        $s = Mx::scope($u, 'm');
        $mine = DB::select("SELECT m.machine_code, m.machine_name, m.check_status, m.machine_status FROM machine m WHERE m.deleted_at IS NULL AND m.machine_status IN (".Mx::activeIn().") AND m.responsible_person_id = ? ORDER BY (m.check_status = 'PENDING') DESC, m.machine_code LIMIT 12", [$u->id], 'machines');

        return view('machines/scan', ['title' => __('Scan QR code'), 'mine' => $mine, 'canCheck' => can('machines_checklists', 'create')]);
    }

    /** What did the scanner read? → where to go. */
    public function lookup(): never
    {
        $this->user();
        $code = Mx::codeFromScan((string) Request::query('text', ''));
        $m = $code ? Mx::machine($code) : null;
        if (! $m) {
            json_response(['ok' => false, 'message' => __('No machine with this code.')], 404);
        }
        json_response(['ok' => true, 'code' => $m['machine_code'], 'url' => url('/machines/m/'.rawurlencode($m['machine_code']))]);
    }

    /** Type-ahead for the code box: any machine in use. */
    public function find(): never
    {
        $this->user();
        $q = trim((string) Request::query('q', ''));
        if (mb_strlen($q) < 2) {
            json_response(['rows' => []]);
        }
        $rows = DB::select("SELECT machine_code, machine_name, brand, model FROM machine WHERE deleted_at IS NULL AND machine_status IN (".Mx::activeIn().") AND (machine_code ILIKE ? OR machine_name ILIKE ? OR serial_number ILIKE ? OR machine_number ILIKE ?) ORDER BY machine_code LIMIT 12", array_fill(0, 4, '%'.$q.'%'), 'machines');
        json_response(['rows' => array_map(fn ($r) => ['code' => $r['machine_code'], 'name' => $r['machine_name'], 'sub' => trim(($r['brand'] ?? '').' '.($r['model'] ?? '')), 'url' => url('/machines/m/'.rawurlencode($r['machine_code']))], $rows)]);
    }

    /** The page a QR label opens. */
    public function machine(string $code): string
    {
        $u = $this->user();
        $m = Mx::machine($code) ?? abort(404, __('No machine with this code.'));
        $plan = Mx::isActive($m['machine_status']) ? Checklists::plan($m, $u) : null;
        $names = Mx::names([$m['responsible_person_id'], $m['supervisor_id'], $m['manager_id']]);
        $checks = DB::select('SELECT id, check_type, recheck, checklist_status, machine_status, user_name, created_at FROM checklist_record WHERE machine_code = ? ORDER BY created_at DESC LIMIT 5', [$m['machine_code']], 'machines');
        $due = [
            'maintenance' => DB::first('SELECT due_date FROM maintenance_record WHERE machine_code = ? AND actual_date IS NULL AND NOT is_canceled ORDER BY due_date LIMIT 1', [$m['machine_code']], 'machines'),
            'calibration' => DB::first('SELECT due_date FROM calibration_record WHERE machine_code = ? AND certificate_date IS NULL AND NOT is_canceled ORDER BY due_date LIMIT 1', [$m['machine_code']], 'machines'),
        ];

        return view('machines/landing', ['title' => $m['machine_code'], 'm' => $m, 'plan' => $plan, 'names' => $names, 'checks' => $checks, 'due' => $due,
            'canCheck' => $plan && can('machines_checklists', 'create'), 'canOpen' => can('machines_machines', 'view') && Mx::canSee($u, $m['machine_code'])]);
    }
}
