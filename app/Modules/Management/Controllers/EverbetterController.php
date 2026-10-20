<?php
declare(strict_types=1);

namespace App\Modules\Management\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Everbetter;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\ValidationException;

/**
 * Management → Everbetter: who presents on this quarter's day, every request (acknowledge, then record the result after
 * the presentation), all projects, and the presentation day of each quarter.
 */
class EverbetterController extends Controller
{
    public function index(): string
    {
        $this->authorize('management', 'view');
        $u = $this->user();
        [$y, $q] = Everbetter::quarterOf();
        $year = (int) Request::query('year', $y);
        $year = $year >= 2020 && $year <= 2100 ? $year : $y;
        $status = (string) Request::query('status', 'open');
        $session = Everbetter::session($y, $q);

        $w = ["p.deleted_at IS NULL"]; $pp = [];
        if ($status === 'open') { $w[] = "x.status <> 'withdrawn' AND x.result IS NULL"; }
        elseif ($status === 'submitted') { $w[] = "x.status = 'submitted'"; }
        elseif ($status === 'decided') { $w[] = 'x.result IS NOT NULL'; }
        if ($sid = (int) Request::query('session', 0)) { $w[] = 'x.session_id = ?'; $pp[] = $sid; }
        $requests = DB::select('SELECT x.*, s.present_on, s.year, s.quarter, p.title, p.owner_name, p.start_date, p.end_date, p.status AS project_status, d.name AS department_name, u.avatar
            FROM everbetter_presentations x JOIN everbetter_sessions s ON s.id = x.session_id JOIN everbetter_projects p ON p.id = x.project_id
            LEFT JOIN departments d ON d.id = p.department_id LEFT JOIN users u ON u.id = x.requested_by
            WHERE '.implode(' AND ', $w).' ORDER BY s.present_on, x.requested_at LIMIT 300', $pp);
        $thisQuarter = $session ? DB::select("SELECT x.*, p.title, p.owner_name, u.avatar FROM everbetter_presentations x JOIN everbetter_projects p ON p.id = x.project_id
            LEFT JOIN users u ON u.id = x.requested_by WHERE x.session_id = ? AND x.status <> 'withdrawn' AND p.deleted_at IS NULL ORDER BY x.kind, x.requested_at", [$session['id']]) : [];

        $tab = (string) Request::query('tab', 'presentations');
        $tab = in_array($tab, ['presentations', 'departments', 'ranking'], true) ? $tab : 'presentations';

        return view('management::everbetter', [
            'tab' => $tab, 'byDepartment' => $tab === 'departments' ? $this->byDepartment($y) : null, 'ranking' => $tab === 'ranking' ? $this->ranking($y, $year) : null,
            'title' => __('Everbetter'), 'session' => $session, 'y' => $y, 'q' => $q, 'thisQuarter' => $thisQuarter, 'requests' => $requests, 'status' => $status,
            'projects' => Everbetter::projects($u, ['q' => Request::query('q', ''), 'status' => Request::query('pstatus', '')]),
            'year' => $year, 'sessions' => array_column(DB::select('SELECT * FROM everbetter_sessions WHERE year = ?', [$year]), null, 'quarter'),
            'allSessions' => array_column(DB::select("SELECT id, to_char(present_on, 'DD Mon YYYY') || ' (Q' || quarter || '/' || year || ')' AS label FROM everbetter_sessions ORDER BY present_on DESC LIMIT 12"), 'label', 'id'),
            'counts' => DB::first("SELECT count(*) FILTER (WHERE x.status = 'submitted') AS waiting, count(*) FILTER (WHERE x.status = 'acknowledged' AND x.result IS NULL) AS accepted
                FROM everbetter_presentations x JOIN everbetter_projects p ON p.id = x.project_id WHERE p.deleted_at IS NULL"),
        ]);
    }

    /** Years shown in the department table and the ranking: this year back to the first year with a project (at most 6). */
    private function years(int $thisYear): array
    {
        $first = (int) (DB::scalar("SELECT min(EXTRACT(YEAR FROM requested_at))::int FROM everbetter_presentations WHERE status <> 'withdrawn'") ?: $thisYear);

        return range($thisYear, max($first, $thisYear - 5));
    }

    /**
     * Projects submitted per department and year. A project counts in the year it first asked to present
     * (its first request that was not withdrawn), in the department it belongs to.
     * @return array{years: list<int>, rows: list<array>, totals: array, drafts: int}
     */
    private function byDepartment(int $thisYear): array
    {
        $years = $this->years($thisYear);
        $data = DB::select("SELECT p.department_id, EXTRACT(YEAR FROM f.first_at)::int AS yr, count(*) AS submitted,
                count(*) FILTER (WHERE p.status IN ('approved', 'closed')) AS approved, count(*) FILTER (WHERE p.status = 'closed') AS closed
            FROM everbetter_projects p
            JOIN LATERAL (SELECT min(x.requested_at) AS first_at FROM everbetter_presentations x WHERE x.project_id = p.id AND x.status <> 'withdrawn') f ON f.first_at IS NOT NULL
            WHERE p.deleted_at IS NULL GROUP BY 1, 2");
        $deps = array_column(DB::select('SELECT id, name, color FROM departments WHERE deleted_at IS NULL ORDER BY name'), null, 'id');
        $rows = []; $totals = [];
        foreach ($data as $r) {
            $d = (int) $r['department_id'];
            $rows[$d] ??= ['id' => $d, 'name' => $deps[$d]['name'] ?? __('No department'), 'color' => $deps[$d]['color'] ?? '#9ca3af', 'years' => []];
            $rows[$d]['years'][(int) $r['yr']] = ['submitted' => (int) $r['submitted'], 'approved' => (int) $r['approved'], 'closed' => (int) $r['closed']];
            $totals[(int) $r['yr']] = ($totals[(int) $r['yr']] ?? 0) + (int) $r['submitted'];
        }
        foreach ($deps as $id => $d) {          // departments without a project yet show as zero
            $rows[$id] ??= ['id' => $id, 'name' => $d['name'], 'color' => $d['color'] ?? '#9ca3af', 'years' => []];
        }
        uasort($rows, fn ($a, $b) => [($b['years'][$thisYear]['submitted'] ?? 0), array_sum(array_column($b['years'], 'submitted'))] <=> [($a['years'][$thisYear]['submitted'] ?? 0), array_sum(array_column($a['years'], 'submitted'))] ?: strcmp($a['name'], $b['name']));
        $drafts = (int) DB::scalar("SELECT count(*) FROM everbetter_projects p WHERE p.deleted_at IS NULL
            AND NOT EXISTS (SELECT 1 FROM everbetter_presentations x WHERE x.project_id = p.id AND x.status <> 'withdrawn')");

        return ['years' => $years, 'rows' => array_values($rows), 'totals' => $totals, 'drafts' => $drafts];
    }

    /**
     * The 1st, 2nd and 3rd project of every year, and the projects that can be ranked in the chosen year
     * (those that presented, or asked to present, on one of that year's days).
     */
    private function ranking(int $thisYear, int $year): array
    {
        $winners = [];
        foreach (DB::select('SELECT p.id, p.title, p.owner_name, p.award_year, p.award_rank, p.status, d.name AS department_name, u.avatar
                FROM everbetter_projects p LEFT JOIN departments d ON d.id = p.department_id LEFT JOIN users u ON u.id = p.owner_id
                WHERE p.award_rank IS NOT NULL AND p.deleted_at IS NULL ORDER BY p.award_year DESC, p.award_rank') as $w) {
            $winners[(int) $w['award_year']][(int) $w['award_rank']] = $w;
        }
        $candidates = DB::select("SELECT DISTINCT p.id, p.title, p.owner_name, d.name AS department_name FROM everbetter_projects p
            JOIN everbetter_presentations x ON x.project_id = p.id AND x.status <> 'withdrawn' JOIN everbetter_sessions s ON s.id = x.session_id
            LEFT JOIN departments d ON d.id = p.department_id WHERE s.year = ? AND p.deleted_at IS NULL ORDER BY p.title", [$year]);

        return ['years' => array_values(array_unique(array_merge($this->years($thisYear), array_keys($winners)))), 'winners' => $winners, 'candidates' => $candidates, 'year' => $year];
    }

    /** Save the 1st, 2nd and 3rd project of a year (empty = nobody in that place). */
    public function saveRanking(): never
    {
        $this->authorize('management', 'view');
        $year = (int) Request::input('year');
        if ($year < 2020 || $year > 2100) {
            throw new ValidationException(['year' => __('Choose a year.')]);
        }
        $ok = array_map('intval', array_column(DB::select("SELECT DISTINCT p.id FROM everbetter_projects p JOIN everbetter_presentations x ON x.project_id = p.id AND x.status <> 'withdrawn'
            JOIN everbetter_sessions s ON s.id = x.session_id WHERE s.year = ? AND p.deleted_at IS NULL", [$year]), 'id'));
        $pick = [];
        foreach ([1, 2, 3] as $r) {
            $id = (int) Request::input('rank_'.$r, 0);
            if (! $id) { continue; }
            if (! in_array($id, $ok, true)) { throw new ValidationException(['rank_'.$r => __('Choose a project that presented in :y.', ['y' => $year])]); }
            if (in_array($id, $pick, true)) { throw new ValidationException(['rank_'.$r => __('A project can only have one place.')]); }
            $pick[$r] = $id;
        }
        DB::exec('UPDATE everbetter_projects SET award_year = NULL, award_rank = NULL WHERE award_year = ?', [$year]);
        foreach ($pick as $r => $id) {
            DB::exec('UPDATE everbetter_projects SET award_year = ?, award_rank = ? WHERE id = ?', [$year, $r, $id]);
        }
        $titles = $pick ? array_column(DB::select('SELECT id, title FROM everbetter_projects WHERE id IN ('.implode(',', $pick).')'), 'title', 'id') : [];
        foreach ($pick as $r => $id) {             // tell the winning teams
            foreach (array_unique(array_merge([(int) DB::scalar('SELECT owner_id FROM everbetter_projects WHERE id = ?', [$id])],
                array_map('intval', array_column(DB::select('SELECT user_id FROM everbetter_members WHERE project_id = ?', [$id]), 'user_id')))) as $uid) {
                if ($uid && $uid !== $this->user()->id) {
                    \App\Core\Support\Notifier::deliver('core', 'updated', $uid, ['key' => 'Everbetter :y: ":title" is ranked #:r', 'params' => ['y' => $year, 'title' => $titles[$id] ?? '', 'r' => $r]],
                        ['key' => 'Congratulations to the team!', 'params' => []], url('/everbetter/'.$id), $this->user()->id);
                }
            }
        }
        Activity::log('updated', 'everbetter', null, 'Everbetter', ['Set the Everbetter ranking of :year', ['year' => $year]], ['ranking' => array_map(fn ($id) => $titles[$id] ?? $id, $pick)], null, notify: false, module: 'management');
        Session::flash('success', __('Ranking of :year saved.', ['year' => $year]));
        redirect('/management/everbetter?tab=ranking&year='.$year);
    }

    public function acknowledge(int $id): never
    {
        $this->authorize('management', 'view');
        $x = Everbetter::acknowledge($this->user(), $id);
        Session::flash('success', __('Accepted. :name has been told.', ['name' => $x['requested_name']]));
        back();
    }

    public function decide(int $id): never
    {
        $this->authorize('management', 'view');
        $x = Everbetter::decide($this->user(), $id, (string) Request::input('result'), (string) Request::input('note', ''));
        Session::flash('success', __('Result saved for ":title".', ['title' => $x['title']]));
        back();
    }

    /** The presentation day of each quarter of one year. */
    public function sessions(): never
    {
        $this->authorize('management', 'view');
        $year = (int) Request::input('year');
        if ($year < 2020 || $year > 2100) {
            throw new ValidationException(['year' => __('Choose a year.')]);
        }
        $saved = 0;
        foreach ([1, 2, 3, 4] as $q) {
            $day = trim((string) Request::input('day_'.$q, ''));
            $place = mb_substr(trim((string) Request::input('place_'.$q, '')), 0, 200) ?: null;
            $old = Everbetter::session($year, $q);
            if ($day === '') {
                if ($old && ! DB::scalar('SELECT 1 FROM everbetter_presentations WHERE session_id = ? LIMIT 1', [$old['id']])) {
                    DB::exec('DELETE FROM everbetter_sessions WHERE id = ?', [$old['id']]);
                }
                continue;
            }
            $from = sprintf('%04d-%02d-01', $year, ($q - 1) * 3 + 1);
            $to = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $q * 3)));
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $day < $from || $day > $to) {
                throw new ValidationException(['day_'.$q => __('The Q:q day must fall between :from and :to.', ['q' => $q, 'from' => format_date($from), 'to' => format_date($to)])]);
            }
            DB::exec('INSERT INTO everbetter_sessions (year, quarter, present_on, place, created_by) VALUES (?, ?, ?, ?, ?)
                      ON CONFLICT (year, quarter) DO UPDATE SET present_on = EXCLUDED.present_on, place = EXCLUDED.place, updated_at = now()', [$year, $q, $day, $place, $this->user()->id]);
            $saved++;
        }
        Activity::log('updated', 'everbetter', null, 'Everbetter', ['Set the Everbetter presentation days of :year', ['year' => $year]], [], null, notify: false, module: 'management');
        Session::flash('success', __('Presentation days of :year saved.', ['year' => $year]));
        redirect('/management/everbetter?year='.$year.'#days');
    }
}
