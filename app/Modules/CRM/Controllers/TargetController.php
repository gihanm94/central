<?php
declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\ValidationException;
use App\Modules\CRM\Support\Access;
use App\Modules\CRM\Support\Targets;

/** Yearly sales targets (per quarter and total) and the actuals saved for each year. BU Managers set their own department's. */
class TargetController extends Controller
{
    /** The department being looked at: whole-company roles choose, everyone else gets their own. */
    private function department(): int
    {
        $u    = $this->user();
        $deps = Access::departments();
        if (Access::seesAll($u)) {
            $pick = (int) Request::input('department', 0);

            return isset($deps[$pick]) ? $pick : (int) (array_key_first($deps) ?? 0);
        }

        return (int) $u->department_id ?: abort(403, __('You are not part of a department.'));
    }

    private function year(): int
    {
        $y = (int) Request::input('year', date('Y'));

        return $y >= 2000 && $y <= 2100 ? $y : (int) date('Y');
    }

    public function index(): string
    {
        $this->authorize('crm_targets', 'view');
        $dep  = $this->department();
        $year = $this->year();
        $deps = Access::departments();
        $row  = Targets::row($dep, $year);
        $u    = $this->user();

        // a year's history: saved actuals of earlier years of this department
        $history = DB::select('SELECT year, total, a1, a2, a3, a4, actuals_saved_at FROM sales_targets WHERE department_id = ? AND actuals_saved_at IS NOT NULL ORDER BY year DESC LIMIT 6', [$dep], 'crm');

        return view('crm/targets', [
            'title' => __('Sales targets'), 'tabs' => LookupController::tabs(), 'deps' => $deps, 'dep' => $dep, 'year' => $year, 'row' => $row, 'live' => Targets::live($dep, $year), 'source' => Targets::source($dep), 'erp' => \App\Modules\CRM\Support\Revenue::erpId($dep),
            'cur' => Targets::baseCurrency(), 'canEdit' => can('crm_targets', 'edit') && (Access::seesAll($u) || $dep === $u->department_id), 'pickDept' => Access::seesAll($u), 'history' => $history,
        ]);
    }

    private function money(string $key): float
    {
        $v = str_replace(',', '', trim((string) Request::input($key, '0')));
        if ($v === '') {
            return 0.0;
        }
        if (! is_numeric($v) || (float) $v < 0 || (float) $v > 1e13) {
            throw new ValidationException([$key => __('Enter an amount of zero or more.')]);
        }

        return round((float) $v, 2);
    }

    public function save(): never
    {
        $this->authorize('crm_targets', 'edit');
        $u    = $this->user();
        $dep  = $this->department();
        $year = $this->year();
        if (! Access::seesAll($u) && $dep !== $u->department_id) {
            abort(403, __('You can only set targets for your own department.'));
        }
        $action = (string) Request::input('action', 'targets');
        $row    = Targets::row($dep, $year);
        $exists = isset($row['id']);

        if ($action === 'targets') {
            $q     = [1 => $this->money('q1'), 2 => $this->money('q2'), 3 => $this->money('q3'), 4 => $this->money('q4')];
            $total = $this->money('total');
            $total = $total > 0 ? $total : array_sum($q);
            $data  = ['q1' => $q[1], 'q2' => $q[2], 'q3' => $q[3], 'q4' => $q[4], 'total' => $total, 'updated_by' => $u->id, 'updated_at' => now()];
            $msg   = __('Targets for :year saved.', ['year' => $year]);
        } else {
            // actuals: take the live figures, or the numbers typed in the "saved" column
            $live = Targets::live($dep, $year);
            $a    = [];
            foreach ([1, 2, 3, 4] as $i) {
                $a[$i] = $action === 'actuals_live' ? round($live[$i], 2) : $this->money('a'.$i);
            }
            $data = ['a1' => $a[1], 'a2' => $a[2], 'a3' => $a[3], 'a4' => $a[4], 'actuals_saved_at' => now(), 'actuals_saved_by' => $u->id, 'updated_by' => $u->id, 'updated_at' => now()];
            $msg  = __('Actuals for :year saved.', ['year' => $year]);
        }

        $exists
            ? DB::update('sales_targets', $data, ['id' => $row['id']], 'crm')
            : DB::insert('sales_targets', $data + ['department_id' => $dep, 'year' => $year, 'created_by' => $u->id], 'crm');

        Activity::log('updated', 'sales_target', null, $year.' · '.(Access::departments()[$dep] ?? $dep), ['Updated sales targets for :year', ['year' => $year]], ['department_id' => $dep], null, null, false, 'crm');
        Session::flash('success', $msg);
        redirect('/crm/settings/targets?year='.$year.'&department='.$dep);
    }
}
