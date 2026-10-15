<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Api\Tokens;
use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\Session;

/** Mobile app & API on the website: administrators see every signed-in device and the request log; each person sees and ends their own. */
class ApiAccessController extends Controller
{
    private function gate(): void
    {
        $this->user()->isAdmin() || abort(403, __('Only an administrator can open this.'));
    }

    public function admin(): string
    {
        $this->gate();
        $errors = Request::query('log') === 'errors';

        return view('admin/api', ['title' => __('Mobile app & API'),
            'devices' => DB::select('SELECT d.id, d.name, d.platform, d.created_at, d.last_used_at, d.last_ip, d.expires_at, u.name AS user_name, u.email
                FROM api_devices d JOIN users u ON u.id = d.user_id WHERE d.revoked_at IS NULL ORDER BY d.last_used_at DESC NULLS LAST LIMIT 500'),
            'roles' => DB::select("SELECT r.name FROM roles r JOIN role_permissions rp ON rp.role_id = r.id JOIN permissions p ON p.id = rp.permission_id
                WHERE p.key = 'api_access' AND rp.can_view ORDER BY r.level DESC"),
            'log' => DB::select('SELECT l.*, u.name AS user_name FROM api_log l LEFT JOIN users u ON u.id = l.user_id'.($errors ? ' WHERE l.status >= 400' : '').' ORDER BY l.id DESC LIMIT 200'),
            'errors' => $errors,
            'counts' => DB::first("SELECT count(*) FILTER (WHERE at > now() - interval '24 hours') AS day, count(*) FILTER (WHERE at > now() - interval '24 hours' AND status >= 400) AS day_errors,
                count(*) FILTER (WHERE at > now() - interval '24 hours' AND status = 401) AS day_401, count(*) FILTER (WHERE at > now() - interval '24 hours' AND status = 429) AS day_429 FROM api_log"),
            'settings' => ['enabled' => (bool) config('api.enabled', true), 'https' => (bool) config('api.require_https', true) && config('app.env') !== 'local',
                'access' => Tokens::accessMinutes(), 'refresh' => Tokens::refreshDays(), 'max' => Tokens::maxDays(), 'rate' => (int) config('api.rate_per_minute', 120)],
        ]);
    }

    public function adminRevoke(int $id): never
    {
        $this->gate();
        Tokens::revoke($id, 'revoked_by_admin');
        Session::flash('success', __('The device was signed out.'));
        redirect('/api-access');
    }

    /** Profile → Mobile devices. */
    public function mine(): string
    {
        $u = $this->user();

        return view('profile/devices', ['title' => __('Mobile devices'), 'me' => $u, 'allowed' => Tokens::allowed($u),
            'devices' => DB::select('SELECT id, name, platform, created_at, last_used_at, last_ip, expires_at FROM api_devices WHERE user_id = ? AND revoked_at IS NULL ORDER BY last_used_at DESC NULLS LAST', [$u->id])]);
    }

    public function revokeMine(int $id): never
    {
        $u = $this->user();
        if (DB::scalar('SELECT 1 FROM api_devices WHERE id = ? AND user_id = ?', [$id, $u->id])) {
            Tokens::revoke($id, 'revoked_by_user');
        }
        Session::flash('success', __('The device was signed out.'));
        redirect('/profile/devices');
    }
}
