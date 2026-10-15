<?php
declare(strict_types=1);

namespace App\Core\Api\Controllers;

use App\Core\Api\ApiException;
use App\Core\Api\Controller;
use App\Core\Api\Kernel;
use App\Core\Api\Tokens;
use App\Core\Auth\CurrentUser;
use App\Core\Support\DB;
use App\Core\Support\Modules;
use App\Core\Support\Request;

/** The signed-in person: profile, what the app may show, their devices and notifications. */
class MeController extends Controller
{
    /** Permissions the mobile app cares about, by module. */
    private const RESOURCES = [
        'crm' => ['crm_leads', 'crm_contacts', 'crm_opportunities', 'crm_activities', 'crm_projects', 'crm_tasks'],
        'machines' => ['machines_checklists', 'machines_machines', 'machines_maintenance', 'machines_calibration'],
        'accounting' => ['accounting_customers', 'accounting_products', 'accounting_orders', 'accounting_invoices', 'accounting_sellers', 'accounting_inet', 'accounting_billing'],
    ];

    public static function profile(CurrentUser $u): array
    {
        $perms = [];
        foreach (self::RESOURCES as $module => $resources) {
            if (! Modules::usable($module)) {
                continue;
            }
            foreach ($resources as $r) {
                $actions = array_values(array_filter(['view', 'create', 'edit', 'delete', 'download'], fn ($a) => $u->can($r, $a)));
                if ($actions) {
                    $perms[$module][$r] = $actions;
                }
            }
        }

        return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role_name, 'department' => $u->department_name, 'team' => $u->team_name,
            'job_title' => $u->job_title, 'locale' => $u->locale, 'avatar_url' => $u->avatarUrl(), 'is_admin' => $u->isAdmin(), 'modules' => array_keys($perms), 'permissions' => (object) $perms];
    }

    public function me(): array
    {
        return ['data' => self::profile($this->user())];
    }

    /** My signed-in devices (this one is marked). */
    public function devices(): array
    {
        $current = (int) Kernel::device()['id'];
        $rows = DB::select('SELECT id, name, platform, created_at, last_used_at, last_ip, expires_at FROM api_devices WHERE user_id = ? AND revoked_at IS NULL ORDER BY last_used_at DESC NULLS LAST', [$this->user()->id]);

        return ['data' => array_map(fn ($r) => $r + ['current' => (int) $r['id'] === $current], $rows)];
    }

    /** Sign out one of my devices (e.g. a lost phone). */
    public function revoke(int $id): array
    {
        $ok = DB::scalar('SELECT 1 FROM api_devices WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [$id, $this->user()->id]);
        if (! $ok) {
            throw new ApiException(404, 'not_found', __('Not found.'));
        }
        Tokens::revoke($id, 'revoked_by_user');

        return ['ok' => true];
    }

    /** My latest notifications (?unread=1 for unread only). */
    public function notifications(): array
    {
        $unread = Request::query('unread') === '1';

        return $this->paged('SELECT id, module, type, title, body, url, read_at, created_at FROM notifications WHERE user_id = ?'.($unread ? ' AND read_at IS NULL' : ''),
            [$this->user()->id], 'created_at DESC, id DESC');
    }
}
