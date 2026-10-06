<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Auth\SessionGuard;
use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\Session;

/** Administrators: every active session of every person, the sign-in log, and ending sessions. */
class SessionAdminController extends Controller
{
    private function gate(): void
    {
        $this->user()->isAdmin() || abort(403, __('Only an administrator can open this.'));
    }

    public function index(): string
    {
        $this->gate();
        $tab = Request::query('tab') === 'log' ? 'log' : 'active';
        $q = trim((string) Request::query('q', ''));
        $page = max(1, (int) Request::query('page', 1));
        $per = 20;
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)).'%';
        if ($tab === 'active') {
            $where = 's.revoked_at IS NULL AND s.expires_at > now()'.($q !== '' ? ' AND (lower(u.name) LIKE ? OR lower(u.email) LIKE ? OR s.ip LIKE ? OR lower(s.device) LIKE ?)' : '');
            $par = $q !== '' ? [$like, $like, $like, $like] : [];
            $total = (int) DB::scalar("SELECT count(*) FROM user_sessions s JOIN users u ON u.id = s.user_id WHERE {$where}", $par);
            $rows = DB::select("SELECT s.*, u.name, u.email FROM user_sessions s JOIN users u ON u.id = s.user_id WHERE {$where} ORDER BY s.last_seen_at DESC LIMIT {$per} OFFSET ".(($page - 1) * $per), $par);
        } else {
            $where = $q !== '' ? "WHERE (lower(coalesce(u.name, '')) LIKE ? OR lower(coalesce(e.email, u.email, '')) LIKE ? OR e.ip LIKE ? OR lower(coalesce(e.device, '')) LIKE ?)" : '';
            $par = $q !== '' ? [$like, $like, $like, $like] : [];
            $total = (int) DB::scalar("SELECT count(*) FROM login_events e LEFT JOIN users u ON u.id = e.user_id {$where}", $par);
            $rows = DB::select("SELECT e.*, u.name, coalesce(u.email, e.email) AS mail FROM login_events e LEFT JOIN users u ON u.id = e.user_id {$where} ORDER BY e.id DESC LIMIT {$per} OFFSET ".(($page - 1) * $per), $par);
        }

        return view('admin/sessions', ['title' => __('Sessions'), 'tab' => $tab, 'q' => $q, 'rows' => $rows, 'page' => $page, 'pages' => max(1, (int) ceil($total / $per)), 'total' => $total,
            'activeCount' => (int) DB::scalar('SELECT count(*) FROM user_sessions WHERE revoked_at IS NULL AND expires_at > now()'), 'maxHours' => SessionGuard::maxHours()]);
    }

    public function revoke(int $id): never
    {
        $this->gate();
        $row = DB::first('SELECT * FROM user_sessions WHERE id = ?', [$id]) ?? abort(404);
        $mine = SessionGuard::isCurrent($row);
        SessionGuard::revoke($id, $this->user()->id);
        if ($mine) {
            \App\Core\Auth\Auth::logout();
            Session::flash('success', __('You were signed out.'));
            redirect('/login');
        }
        Session::flash('success', __('Session ended.'));
        redirect('/sessions');
    }

    /** End every session of one person. */
    public function revokeUser(int $id): never
    {
        $this->gate();
        $n = SessionGuard::revokeAll($id, ! empty($_SESSION['sid']) && (int) $this->user()->id === $id ? (int) $_SESSION['sid'] : null, $this->user()->id);
        Session::flash('success', __(':n session(s) ended.', ['n' => $n]));
        redirect('/sessions');
    }
}
