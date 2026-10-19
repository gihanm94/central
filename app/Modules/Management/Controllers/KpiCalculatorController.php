<?php
declare(strict_types=1);

namespace App\Modules\Management\Controllers;

use App\Core\Http\Controllers\Controller;

use App\Core\Support\Activity;
use App\Core\Support\Csv;
use App\Core\Support\DB;
use App\Modules\Management\Support\KpiScore as S;
use App\Core\Support\KpiSubmissions as K;
use App\Core\Support\Request;
use App\Core\Support\Session;

/**
 * Management → KPI calculator: everyone's yearly appraisal score (0 until calculated), and per person the
 * calculation with every figure. Sections 1 and 2 come from KPIs & OKRs and KPI submissions; 3–7 and warnings are typed in.
 * Who: the Management module (administrators and Management); saving needs kpi_calculator.edit.
 */
class KpiCalculatorController extends Controller
{
    private function year(): int
    {
        $y = (int) Request::query('year', Request::input('year', date('Y')));

        return $y >= 2000 && $y <= 2100 ? $y : (int) date('Y');
    }

    /** Calculate up to this month: by default this month for the current year, December for earlier years. */
    private function month(int $year, ?int $saved = null): int
    {
        $m = (int) Request::query('month', Request::input('month', $saved ?? ($year === (int) date('Y') ? (int) date('n') : 12)));

        return max(1, min(12, $m));
    }

    private function person(int $id): array
    {
        $s = K::scopeSql($this->user(), 'u', 'id');

        return DB::first('SELECT u.id, u.name, u.email, u.avatar, u.department_id, d.name AS department_name, r.name AS role_name
            FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.id = ? AND u.deleted_at IS NULL AND '.$s['sql'], [$id, ...$s['params']]) ?? abort(404, __('This person is not found, or is outside what you can see.'));
    }

    private function list(int $year, string $q): array
    {
        $s = K::scopeSql($this->user(), 'u', 'id');
        $where = ['u.deleted_at IS NULL', 'u.is_active', $s['sql']];
        $params = [$year, ...$s['params']];
        if ($q !== '') {
            $where[] = '(u.name ILIKE ? OR d.name ILIKE ?)';
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            array_push($params, $like, $like);
        }

        return DB::select('SELECT u.id, u.name, u.avatar, d.name AS department_name, r.name AS role_name, ks.month, ks.score, ks.emp_group, ks.calculated_at, cb.name AS calculated_by_name
              FROM users u JOIN roles r ON r.id = u.role_id
         LEFT JOIN departments d ON d.id = u.department_id
         LEFT JOIN kpi_scores ks ON ks.user_id = u.id AND ks.year = ?
         LEFT JOIN users cb ON cb.id = ks.calculated_by
             WHERE '.implode(' AND ', $where).' ORDER BY d.name NULLS LAST, u.name', $params);
    }

    public function index(): string
    {
        $this->authorize('kpi_calculator', 'view');
        $year = $this->year();
        $q = mb_substr(trim((string) Request::query('q', '')), 0, 100);

        return view('management::kpi-calc/index', ['title' => __('KPI calculator'), 'year' => $year, 'q' => $q, 'rows' => $this->list($year, $q),
            'years' => range((int) date('Y'), (int) date('Y') - 4)]);
    }

    public function show(int $id): string
    {
        $this->authorize('kpi_calculator', 'view');
        $p = $this->person($id);
        $year = $this->year();
        $saved = DB::first('SELECT ks.*, cb.name AS calculated_by_name FROM kpi_scores ks LEFT JOIN users cb ON cb.id = ks.calculated_by WHERE ks.user_id = ? AND ks.year = ?', [$id, $year]);
        $in = S::inputs($saved ? (json_decode((string) $saved['inputs'], true) ?: []) : []);
        $group = (string) Request::query('group', $saved['emp_group'] ?? $this->lastGroup($id));
        $month = $this->month($year, $saved ? (int) $saved['month'] : null);

        return $this->page($p, $year, $month, $group, $in, $saved);
    }

    /** POST: 'calculate' shows the result with the typed figures; 'save' keeps it as the person's score for the year. */
    public function calculate(int $id): string
    {
        $this->authorize('kpi_calculator', 'edit');
        $p = $this->person($id);
        $this->mayEdit($p) || abort(403, __('You can view this score but not change it.'));
        $year = $this->year();
        $month = $this->month($year);
        $group = (string) Request::input('group', 'bu');
        $group = isset(S::POINTS[$group]) ? $group : 'bu';
        $in = S::inputs(Request::all());
        $r = S::calculate($id, $year, $month, $group, $in);

        if (Request::input('action') === 'save') {
            $before = DB::scalar('SELECT score FROM kpi_scores WHERE user_id = ? AND year = ?', [$id, $year]);
            DB::exec('INSERT INTO kpi_scores (user_id, year, month, emp_group, inputs, details, score, calculated_by, calculated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, now())
                ON CONFLICT (user_id, year) DO UPDATE SET month = EXCLUDED.month, emp_group = EXCLUDED.emp_group, inputs = EXCLUDED.inputs, details = EXCLUDED.details,
                score = EXCLUDED.score, calculated_by = EXCLUDED.calculated_by, calculated_at = now()',
                [$id, $year, $month, $group, json_encode($in, JSON_UNESCAPED_UNICODE), json_encode($r, JSON_UNESCAPED_UNICODE), $r['score'], $this->user()->id]);
            Activity::log('updated', 'kpi_score', $id, $p['name'], ['Calculated the :year KPI score of :name: :score', ['year' => $year, 'name' => $p['name'], 'score' => number_format($r['score'], 2)]],
                ['changes' => ['score' => ['from' => $before !== null ? number_format((float) $before, 2) : '', 'to' => number_format($r['score'], 2)]], 'month' => $month, 'group' => $group], $id, notify: false);
            Session::flash('success', __('Score saved: :score of 100.', ['score' => number_format($r['score'], 2)]));
            redirect('/management/kpi-calculator/'.$id.'?'.http_build_query(['year' => $year]));
        }
        $saved = DB::first('SELECT ks.*, cb.name AS calculated_by_name FROM kpi_scores ks LEFT JOIN users cb ON cb.id = ks.calculated_by WHERE ks.user_id = ? AND ks.year = ?', [$id, $year]);

        return $this->page($p, $year, $month, $group, $in, $saved, $r, true);
    }

    private function page(array $p, int $year, int $month, string $group, array $in, ?array $saved, ?array $r = null, bool $preview = false): string
    {
        $group = isset(S::POINTS[$group]) ? $group : 'bu';

        return view('management::kpi-calc/show', ['title' => __('KPI calculator').' · '.$p['name'], 'p' => $p, 'year' => $year, 'month' => $month, 'group' => $group, 'in' => $in,
            'r' => $r ?? S::calculate((int) $p['id'], $year, $month, $group, $in), 'saved' => $saved, 'preview' => $preview,
            'canEdit' => $this->mayEdit($p)]);
    }

    /** Saving: kpi_calculator.edit, and a role that sees everyone (Management) or the BU manager of the department / an administrator. */
    private function mayEdit(array $p): bool
    {
        $u = $this->user();

        return can('kpi_calculator', 'edit') && ($u->isAdmin() || $u->scope() === 'all' || K::canManage($u, $p['department_id']));
    }

    private function lastGroup(int $id): string
    {
        return (string) (DB::scalar('SELECT emp_group FROM kpi_scores WHERE user_id = ? ORDER BY year DESC LIMIT 1', [$id]) ?: 'bu');
    }

    public function export(): never
    {
        $this->authorize('kpi_calculator', 'export');
        $year = $this->year();
        Activity::log('export', 'kpi_score', null, null, ['Exported the :year KPI scores', ['year' => $year]], [], notify: false);
        Csv::download('kpi-scores-'.$year.'.csv', ['Name', 'Department', 'Role', 'Year', 'Up to month', 'Group', 'Score', 'Calculated at', 'Calculated by'],
            (function () use ($year) {
                foreach ($this->list($year, '') as $r) {
                    yield [$r['name'], $r['department_name'], $r['role_name'], $year, $r['month'], $r['emp_group'] ? S::GROUPS[$r['emp_group']] ?? $r['emp_group'] : '',
                        $r['score'] !== null ? number_format((float) $r['score'], 2, '.', '') : '0.00', $r['calculated_at'], $r['calculated_by_name']];
                }
            })());
    }
}
