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

    /** Company-wide control room for administrators. */
    public function admin(): string
    {
        if (! $this->user()->isAdmin()) {
            abort(403, __('Only administrators can open the control room.'));
        }
        $stats = DB::first("SELECT
            (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL) AS members,
            (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND is_active) AS active,
            (SELECT COUNT(*) FROM departments WHERE deleted_at IS NULL) AS departments,
            (SELECT COUNT(*) FROM teams WHERE deleted_at IS NULL) AS teams,
            (SELECT COUNT(*) FROM roles) AS roles,
            (SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL AND status <> 'done') AS open_tasks,
            (SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL AND status <> 'done' AND due_date < CURRENT_DATE) AS overdue,
            (SELECT COUNT(*) FROM activity_logs WHERE action = 'login' AND created_at >= CURRENT_DATE) AS logins_today,
            (SELECT COUNT(*) FROM activity_logs WHERE action = 'login_failed' AND created_at >= CURRENT_DATE) AS failed_today");

        $trend = DB::select("SELECT d::date AS day, COUNT(l.id) AS count
                               FROM generate_series(CURRENT_DATE - 13, CURRENT_DATE, interval '1 day') d
                          LEFT JOIN activity_logs l ON l.action = 'login' AND l.created_at::date = d::date
                           GROUP BY d ORDER BY d");

        return view('dashboard/admin', [
            'title'       => __('Control room'),
            'stats'       => $stats,
            'trend'       => $trend,
            'roles'       => DB::select('SELECT r.name, r.data_scope, COUNT(u.id) AS users_count FROM roles r LEFT JOIN users u ON u.role_id = r.id AND u.deleted_at IS NULL GROUP BY r.id ORDER BY r.level DESC'),
            'departments' => DB::select('SELECT d.name, COUNT(u.id) AS members_count FROM departments d LEFT JOIN users u ON u.department_id = d.id AND u.deleted_at IS NULL AND u.is_active WHERE d.deleted_at IS NULL GROUP BY d.id ORDER BY members_count DESC, d.name'),
            'recent'      => DB::select('SELECT l.*, u.name AS user_name, u.avatar FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.created_at DESC LIMIT 12'),
            'newMembers'  => DB::select('SELECT u.*, r.name AS role_name, d.name AS department_name FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN departments d ON d.id = u.department_id WHERE u.deleted_at IS NULL ORDER BY u.created_at DESC LIMIT 5'),
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
