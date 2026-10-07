<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\DB;
use App\Core\Support\Permission;

class DashboardController extends Controller
{
    /** /dashboard opens the person's chosen dashboard (Preferences), else the sensible default. */
    public function index(): never
    {
        $u      = $this->user();
        $boards = ProfileController::dashboardsFor($u)['core'] ?? [];
        $pick   = $u->default_module === 'core' || ! $u->default_module ? $u->default_dashboard : null;
        $key    = $pick && isset($boards[$pick]) ? $pick : ($u->isAdmin() ? 'admin' : 'personal');
        redirect(config('modules.core.dashboards.'.$key.'.url'));
    }

    /** The period the control room shows: [from, to (inclusive), preset key]. Default: this month. */
    private function period(): array
    {
        $tz = new \DateTimeZone((string) config('app.timezone', 'Asia/Bangkok'));
        $today = new \DateTimeImmutable('today', $tz);
        $key = (string) \App\Core\Support\Request::query('range', 'month');
        $from = $today->modify('first day of this month'); $to = $today;
        switch ($key) {
            case 'today':      $from = $today; break;
            case '7d':         $from = $today->modify('-6 days'); break;
            case '30d':        $from = $today->modify('-29 days'); break;
            case '90d':        $from = $today->modify('-89 days'); break;
            case 'last_month': $from = $today->modify('first day of last month'); $to = $today->modify('last day of last month'); break;
            case 'year':       $from = $today->setDate((int) $today->format('Y'), 1, 1); break;
            case 'custom':
                $f = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) \App\Core\Support\Request::query('from'), $tz);
                $t = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) \App\Core\Support\Request::query('to'), $tz);
                if ($f && $t) { $from = min($f, $t); $to = max($f, $t); } else { $key = 'month'; }
                break;
            default: $key = 'month'; $to = $today->modify('last day of this month');      // the whole month: days still to come show as empty
        }
        if ($from > $to) { $from = $to; }
        if ($to->diff($from)->days > 730) { $from = $to->modify('-730 days'); }

        return [$from, $to, $key];
    }

    /** Company-wide control room for administrators. */
    public function admin(): string
    {
        if (! $this->user()->isAdmin()) {
            abort(403, __('Only administrators can open the control room.'));
        }
        [$from, $to, $range] = $this->period();
        $tzName = (string) config('app.timezone', 'Asia/Bangkok');
        $days   = $from->diff($to)->days + 1;
        $dept   = (int) \App\Core\Support\Request::query('department', 0);
        $unit   = $days <= 62 ? 'day' : ($days <= 250 ? 'week' : 'month');

        $deptRows = DB::select('SELECT id, name, code, color FROM departments WHERE deleted_at IS NULL ORDER BY name');
        $depts = [];
        foreach ($deptRows as $d) {
            $depts[(int) $d['id']] = ['id' => (int) $d['id'], 'name' => $d['name'], 'code' => $d['code'], 'color' => \App\Core\Support\DeptColor::of((int) $d['id'], $d['color'])];
        }
        $depts[0] = ['id' => 0, 'name' => __('No department'), 'code' => '—', 'color' => \App\Core\Support\DeptColor::NONE];
        if ($dept && ! isset($depts[$dept])) { $dept = 0; }
        $onlyDept = $dept ? 'AND u.department_id = ?' : '';

        // window [from 00:00, to+1 day 00:00) in the company time zone, plus the period just before it for the arrows
        $lo  = $from->format('Y-m-d'); $hi = $to->modify('+1 day')->format('Y-m-d');
        $plo = $from->modify('-'.$days.' days')->format('Y-m-d');
        $win = fn (string $a, string $b) => ["(l.created_at AT TIME ZONE '{$tzName}') >= ?::date AND (l.created_at AT TIME ZONE '{$tzName}') < ?::date", [$a, $b]];
        [$wSql, $wPar] = $win($lo, $hi);
        [$pSql, $pPar] = $win($plo, $lo);
        $dp = $dept ? [$dept] : [];

        $figures = function (string $sql, array $par) use ($dept, $dp) {
            return DB::first("SELECT COUNT(*) FILTER (WHERE l.action = 'login') AS logins,
                                     COUNT(DISTINCT l.user_id) FILTER (WHERE l.action = 'login') AS signers,
                                     COUNT(*) FILTER (WHERE l.action NOT IN ('login', 'login_failed')) AS changes,
                                     COUNT(*) FILTER (WHERE l.action = 'login_failed') AS failed
                                FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
                               WHERE {$sql} ".($dept ? "AND (u.department_id = ?)" : ''), [...$par, ...$dp]);
        };
        $now  = $figures($wSql, $wPar);
        $prev = $figures($pSql, $pPar);
        $members = (int) DB::scalar('SELECT COUNT(*) FROM users u WHERE u.deleted_at IS NULL AND u.is_active '.$onlyDept, $dp);

        // sign-ins per bucket and department (stacked chart)
        $trunc = ['day' => 'day', 'week' => 'week', 'month' => 'month'][$unit];
        $rows = DB::select("SELECT to_char(date_trunc('{$trunc}', l.created_at AT TIME ZONE '{$tzName}'), 'YYYY-MM-DD') AS b, COALESCE(u.department_id, 0) AS d, COUNT(*) AS n
                              FROM activity_logs l JOIN users u ON u.id = l.user_id
                             WHERE l.action = 'login' AND {$wSql} {$onlyDept} GROUP BY 1, 2", [...$wPar, ...$dp]);
        $buckets = []; $cur = $unit === 'day' ? $from : ($unit === 'week' ? $from->modify('monday this week') : $from->modify('first day of this month'));
        while ($cur <= $to) { $buckets[] = $cur->format('Y-m-d'); $cur = $unit === 'day' ? $cur->modify('+1 day') : ($unit === 'week' ? $cur->modify('+1 week') : $cur->modify('+1 month')); }
        $grid = []; $totals = [];
        foreach ($rows as $r) { $grid[(int) $r['d']][$r['b']] = (int) $r['n']; $totals[(int) $r['d']] = ($totals[(int) $r['d']] ?? 0) + (int) $r['n']; }
        $series = [];
        $order = array_keys($totals); usort($order, fn ($a, $b) => $totals[$b] <=> $totals[$a]);
        foreach ($order as $id) { $series[] = ['id' => $id, 'name' => $depts[$id]['name'], 'color' => $depts[$id]['color'], 'total' => $totals[$id], 'data' => array_map(fn ($b) => $grid[$id][$b] ?? 0, $buckets)]; }

        // busiest hours
        $hours = array_fill(0, 24, 0);
        foreach (DB::select("SELECT EXTRACT(HOUR FROM l.created_at AT TIME ZONE '{$tzName}')::int AS h, COUNT(*) AS n FROM activity_logs l JOIN users u ON u.id = l.user_id
                              WHERE l.action = 'login' AND {$wSql} {$onlyDept} GROUP BY 1", [...$wPar, ...$dp]) as $h) { $hours[(int) $h['h']] = (int) $h['n']; }

        // most active people (all actions except failed sign-ins)
        $people = DB::select("SELECT u.id, u.name, u.avatar, COALESCE(u.department_id, 0) AS d, COUNT(*) AS total,
                                     COUNT(*) FILTER (WHERE l.action = 'login') AS logins, COUNT(*) FILTER (WHERE l.action <> 'login') AS changes, MAX(l.created_at) AS last_at
                                FROM activity_logs l JOIN users u ON u.id = l.user_id
                               WHERE l.action <> 'login_failed' AND {$wSql} {$onlyDept} GROUP BY u.id ORDER BY total DESC, u.name LIMIT 8", [...$wPar, ...$dp]);

        // each department: ranking + its most frequent sign-ins
        $rank = DB::select("SELECT COALESCE(u.department_id, 0) AS d, COUNT(*) FILTER (WHERE l.action = 'login') AS logins, COUNT(*) FILTER (WHERE l.action NOT IN ('login', 'login_failed')) AS changes,
                                   COUNT(*) FILTER (WHERE l.action <> 'login_failed') AS total, COUNT(DISTINCT l.user_id) FILTER (WHERE l.action <> 'login_failed') AS people
                              FROM activity_logs l JOIN users u ON u.id = l.user_id WHERE {$wSql} {$onlyDept} GROUP BY 1 ORDER BY total DESC", [...$wPar, ...$dp]);
        $heads = array_column(DB::select('SELECT COALESCE(department_id, 0) AS d, COUNT(*) AS n FROM users WHERE deleted_at IS NULL AND is_active GROUP BY 1'), 'n', 'd');
        $top = DB::select("SELECT * FROM (SELECT COALESCE(u.department_id, 0) AS d, u.id, u.name, u.avatar, COUNT(*) AS logins, MAX(l.created_at) AS last_at,
                                  ROW_NUMBER() OVER (PARTITION BY COALESCE(u.department_id, 0) ORDER BY COUNT(*) DESC, u.name) AS rk
                             FROM activity_logs l JOIN users u ON u.id = l.user_id WHERE l.action = 'login' AND {$wSql} {$onlyDept} GROUP BY u.id) x WHERE rk <= 3 ORDER BY d, rk", [...$wPar, ...$dp]);
        $topBy = [];
        foreach ($top as $t) { $topBy[(int) $t['d']][] = $t; }

        $recent = DB::select("SELECT l.*, u.name AS user_name, u.avatar FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE {$wSql} ".($dept ? 'AND u.department_id = ?' : '').' ORDER BY l.created_at DESC LIMIT 8', [...$wPar, ...$dp]);

        return view('dashboard/admin', [
            'title' => __('Control room'), 'from' => $from, 'to' => $to, 'range' => $range, 'days' => $days, 'unit' => $unit, 'dept' => $dept, 'depts' => $depts,
            'now' => $now, 'prev' => $prev, 'members' => $members, 'series' => $series, 'buckets' => $buckets, 'hours' => $hours, 'people' => $people, 'rank' => $rank, 'heads' => $heads, 'topBy' => $topBy, 'recent' => $recent,
        ]);
    }

    /** Personal workspace; figures widen with the user's data scope. */
    public function personal(): string
    {
        $u     = $this->user();
        $scope = $u->scope();

        $myTasks = DB::select("SELECT * FROM tasks WHERE assigned_to = ? AND deleted_at IS NULL AND status <> 'done'
                                ORDER BY CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END, due_date NULLS LAST LIMIT 8", [$u->id]);
        $myKpis  = DB::select("SELECT * FROM kpis WHERE user_id = ? AND deleted_at IS NULL AND status = 'active' ORDER BY period_end LIMIT 6", [$u->id]);

        $figures = [];
        if (can('members')) {
            $s = Permission::scope($u, 'u', ['owner' => 'id', 'department' => 'department_id', 'team' => 'team_id']);
            $figures['members'] = (int) DB::scalar("SELECT COUNT(*) FROM users u WHERE u.deleted_at IS NULL AND u.is_active AND {$s['sql']}", $s['params']);
        }
        if (can('tasks')) {
            $s = Permission::scope($u, 't', ['owner' => ['assigned_to', 'created_by'], 'department' => 'department_id', 'team' => 'team_id']);
            $row = DB::first("SELECT
                    COUNT(*) FILTER (WHERE status <> 'done') AS open,
                    COUNT(*) FILTER (WHERE status <> 'done' AND due_date < CURRENT_DATE) AS overdue,
                    COUNT(*) FILTER (WHERE status = 'done' AND completed_at >= date_trunc('month', now())) AS done_month
                  FROM tasks t WHERE t.deleted_at IS NULL AND {$s['sql']}", $s['params']);
            $figures += ['open_tasks' => (int) $row['open'], 'overdue' => (int) $row['overdue'], 'done_month' => (int) $row['done_month']];
        }

        $teammates = [];
        if ($scope !== 'own' && can('members')) {
            $s = Permission::scope($u, 'u', ['owner' => 'id', 'department' => 'department_id', 'team' => 'team_id']);
            $teammates = DB::select("SELECT u.id, u.name, u.avatar, p.job_title FROM users u LEFT JOIN employee_profiles p ON p.user_id = u.id
                                      WHERE u.deleted_at IS NULL AND u.is_active AND u.id <> ? AND {$s['sql']} ORDER BY u.name LIMIT 8", [$u->id, ...$s['params']]);
        }

        $s = Permission::scope($u, 'su', ['owner' => 'id', 'department' => 'department_id', 'team' => 'team_id']);
        $activity = DB::select("SELECT l.*, u.name AS user_name, u.avatar FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
                                 WHERE l.action <> 'login_failed' AND ".($scope === 'all' ? 'TRUE' : "l.user_id IN (SELECT su.id FROM users su WHERE {$s['sql']})")."
                                 ORDER BY l.created_at DESC LIMIT 8", $scope === 'all' ? [] : $s['params']);

        return view('dashboard/employee', ['title' => __('My workspace')] + compact('u', 'scope', 'myTasks', 'myKpis', 'figures', 'teammates', 'activity'));
    }
}
