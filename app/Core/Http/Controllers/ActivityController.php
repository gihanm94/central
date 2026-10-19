<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\Activity;
use App\Core\Support\Xlsx;
use App\Core\Support\DB;
use App\Core\Support\Options;
use App\Core\Support\Permission;
use App\Core\Support\Request;

/**
 * Read-only audit trail. Administrator → Activity log: the activity of members inside the viewer's scope.
 * User Management → My activity (/my-activity): everyone's own entries only, no permission needed.
 */
class ActivityController extends Controller
{
    public static function actions(): array { return array_map(fn ($l) => __($l), self::ACTIONS); }

    public const ACTIONS = [
        'created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted', 'login' => 'Signed in', 'logout' => 'Signed out',
        'login_failed' => 'Failed sign-in', 'password_reset' => 'Password reset', 'password_changed' => 'Password changed',
        'import' => 'Import', 'export' => 'Export', 'download' => 'Download', 'passkey_added' => 'Passkey added', 'passkey_removed' => 'Passkey removed', 'password_reset_requested' => 'Reset link requested',
    ];

    private function where(bool $mine = false): array
    {
        $u = $this->user();
        if ($mine) {
            $where = ['l.user_id = ?'];
            $p     = [$u->id];
        } else {
            $scope = Permission::scope($u, 'su', ['owner' => 'id', 'department' => 'department_id', 'team' => 'team_id']);
            $where = $u->scope() === 'all' ? ['TRUE'] : ["l.user_id IN (SELECT su.id FROM users su WHERE {$scope['sql']})"];
            $p     = $u->scope() === 'all' ? [] : $scope['params'];
        }

        if (($a = Request::query('action')) && isset(self::ACTIONS[$a])) { $where[] = 'l.action = ?'; $p[] = $a; }
        if (! $mine && ($uid = (int) Request::query('user'))) { $where[] = 'l.user_id = ?'; $p[] = $uid; }
        if (($m = Request::query('module')) && isset(config('modules')[$m])) { $where[] = 'l.module = ?'; $p[] = $m; }
        if (($from = Request::query('from')) && strtotime($from)) { $where[] = 'l.created_at >= ?'; $p[] = $from.' 00:00:00'; }
        if (($to = Request::query('to')) && strtotime($to)) { $where[] = 'l.created_at <= ?'; $p[] = $to.' 23:59:59'; }
        if ($q = Request::query('q')) { $where[] = '(l.description ILIKE ? OR l.subject_label ILIKE ? OR l.ip_address ILIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%"); }

        return [implode(' AND ', $where), $p];
    }

    public function index(): string
    {
        $this->authorize('activity_logs', 'view');

        return $this->list(false);
    }

    /** User Management → My activity. */
    public function mine(): string
    {
        return $this->list(true);
    }

    private function list(bool $mine): string
    {
        [$w, $p] = $this->where($mine);
        $page  = max(1, (int) Request::query('page', 1));
        $total = (int) DB::scalar("SELECT COUNT(*) FROM activity_logs l WHERE {$w}", $p);
        $logs  = DB::select("SELECT l.*, u.name AS user_name, u.avatar FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
                              WHERE {$w} ORDER BY l.created_at DESC, l.id DESC LIMIT 30 OFFSET ".(($page - 1) * 30), $p);

        return view('activity/index', [
            'title' => $mine ? __('My activity') : __('Activity log'), 'logs' => $logs, 'total' => $total, 'page' => $page, 'perPage' => 30,
            'users' => $mine ? [] : Options::users($this->user(), false), 'mine' => $mine, 'base' => $mine ? '/my-activity' : '/activity',
            'canExport' => $mine || can('activity_logs', 'export'),
        ]);
    }

    public function show(int $id): string
    {
        $this->authorize('activity_logs', 'view');

        return $this->entry($id, false);
    }

    public function mineShow(int $id): string
    {
        return $this->entry($id, true);
    }

    private function entry(int $id, bool $mine): string
    {
        [$w, $p] = $this->where($mine);
        $log = DB::first("SELECT l.*, u.name AS user_name, u.email AS user_email, u.avatar FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.id = ? AND {$w}", [$id, ...$p])
            ?? abort(404, __('Log entry not found.'));

        return view('activity/show', ['title' => __('Activity #:id', ['id' => $id]), 'log' => $log, 'base' => $mine ? '/my-activity' : '/activity', 'mine' => $mine]);
    }

    public function export(): never
    {
        $this->authorize('activity_logs', 'export');
        $this->download(false);
    }

    /** Everyone may download their own entries. */
    public function mineExport(): never
    {
        $this->download(true);
    }

    private function download(bool $mine): never
    {
        [$w, $p] = $this->where($mine);
        $rows = DB::select("SELECT l.created_at, u.name, l.module, l.action, l.description, l.properties, l.ip_address, l.user_agent
                              FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE {$w} ORDER BY l.created_at DESC LIMIT 50000", $p);
        Activity::log('export', 'activity', null, 'Activity log', ['Exported :count activity log entries', ['count' => count($rows)]], ['count' => count($rows), 'filters' => array_filter($_GET)]);

        Xlsx::download(($mine ? 'my-activity-' : 'activity-log-').date('Ymd-His').'.xlsx', [__('Time'), __('Person'), __('Module'), __('Action'), __('Description'), 'IP', __('Device')],
            array_map(fn ($r) => [$r['created_at'], $r['name'], $r['module'], self::actions()[$r['action']] ?? $r['action'], activity_text($r), $r['ip_address'], $r['user_agent']], $rows));
    }
}
