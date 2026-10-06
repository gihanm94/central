<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\Activity;
use App\Core\Support\Csv;
use App\Core\Support\DB;
use App\Core\Support\Options;
use App\Core\Support\Permission;
use App\Core\Support\Request;

/** Read-only audit trail. People see the activity of members inside their own scope. */
class ActivityController extends Controller
{
    public static function actions(): array { return array_map(fn ($l) => __($l), self::ACTIONS); }

    public const ACTIONS = [
        'created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted', 'login' => 'Signed in', 'logout' => 'Signed out',
        'login_failed' => 'Failed sign-in', 'password_reset' => 'Password reset', 'password_changed' => 'Password changed',
        'import' => 'Import', 'export' => 'Export', 'download' => 'Download', 'passkey_added' => 'Passkey added', 'passkey_removed' => 'Passkey removed', 'password_reset_requested' => 'Reset link requested',
    ];

    private function where(): array
    {
        $u     = $this->user();
        $scope = Permission::scope($u, 'su', ['owner' => 'id', 'department' => 'department_id', 'team' => 'team_id']);
        $where = $u->scope() === 'all' ? ['TRUE'] : ["l.user_id IN (SELECT su.id FROM users su WHERE {$scope['sql']})"];
        $p     = $u->scope() === 'all' ? [] : $scope['params'];

        if (($a = Request::query('action')) && isset(self::ACTIONS[$a])) { $where[] = 'l.action = ?'; $p[] = $a; }
        if ($uid = (int) Request::query('user')) { $where[] = 'l.user_id = ?'; $p[] = $uid; }
        if (($m = Request::query('module')) && isset(config('modules')[$m])) { $where[] = 'l.module = ?'; $p[] = $m; }
        if (($from = Request::query('from')) && strtotime($from)) { $where[] = 'l.created_at >= ?'; $p[] = $from.' 00:00:00'; }
        if (($to = Request::query('to')) && strtotime($to)) { $where[] = 'l.created_at <= ?'; $p[] = $to.' 23:59:59'; }
        if ($q = Request::query('q')) { $where[] = '(l.description ILIKE ? OR l.subject_label ILIKE ? OR l.ip_address ILIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%"); }

        return [implode(' AND ', $where), $p];
    }

    public function index(): string
    {
        $this->authorize('activity_logs', 'view');
        [$w, $p] = $this->where();
        $page  = max(1, (int) Request::query('page', 1));
        $total = (int) DB::scalar("SELECT COUNT(*) FROM activity_logs l WHERE {$w}", $p);
        $logs  = DB::select("SELECT l.*, u.name AS user_name, u.avatar FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
                              WHERE {$w} ORDER BY l.created_at DESC, l.id DESC LIMIT 30 OFFSET ".(($page - 1) * 30), $p);

        return view('activity/index', [
            'title' => __('Activity log'), 'logs' => $logs, 'total' => $total, 'page' => $page, 'perPage' => 30,
            'users' => Options::users($this->user(), false),
        ]);
    }

    public function show(int $id): string
    {
        $this->authorize('activity_logs', 'view');
        [$w, $p] = $this->where();
        $log = DB::first("SELECT l.*, u.name AS user_name, u.email AS user_email, u.avatar FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.id = ? AND {$w}", [$id, ...$p])
            ?? abort(404, __('Log entry not found.'));

        return view('activity/show', ['title' => __('Activity #:id', ['id' => $id]), 'log' => $log]);
    }

    public function export(): never
    {
        $this->authorize('activity_logs', 'export');
        [$w, $p] = $this->where();
        $rows = DB::select("SELECT l.created_at, u.name, l.module, l.action, l.description, l.properties, l.ip_address, l.user_agent
                              FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE {$w} ORDER BY l.created_at DESC LIMIT 50000", $p);
        Activity::log('export', 'activity', null, 'Activity log', ['Exported :count activity log entries', ['count' => count($rows)]], ['count' => count($rows), 'filters' => array_filter($_GET)]);

        Csv::download('activity-log-'.date('Ymd-His').'.csv', [__('Time'), __('Person'), __('Module'), __('Action'), __('Description'), 'IP', __('Device')],
            array_map(fn ($r) => [$r['created_at'], $r['name'], $r['module'], self::actions()[$r['action']] ?? $r['action'], activity_text($r), $r['ip_address'], $r['user_agent']], $rows));
    }
}
