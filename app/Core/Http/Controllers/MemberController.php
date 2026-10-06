<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\I18n;
use App\Core\Support\Mailer;
use App\Core\Support\Options;
use App\Core\Support\Permission;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\ValidationException;

/**
 * Members = employees (users + employee_profiles).
 * Non-admins can only manage people whose role is below their own, inside their scope.
 */
class MemberController extends ResourceController
{
    protected string $resource = 'members';
    protected string $table = 'users';
    protected string $type = 'user';
    protected string $singular = 'member';
    protected string $plural = 'members';
    protected string $base = '/members';
    protected string $icon = 'users';
    protected array $scopeCols = ['owner' => 'id', 'department' => 'department_id', 'team' => 'team_id'];
    protected string $orderBy = 't.is_active DESC, r.level DESC, t.name';

    private const PROFILE = ['employee_code', 'job_title', 'phone', 'joined_at', 'date_of_birth', 'gender', 'employee_level', 'employment_status', 'employment_type'];

    protected function select(): string
    {
        return 'SELECT t.*, r.name AS role_name, r.slug AS role_slug, r.level AS role_level,
                       d.name AS department_name, tm.name AS team_name, m.name AS manager_name,
                       p.employee_code, p.job_title, p.phone, p.joined_at, p.date_of_birth,
                       p.gender, p.employee_level, p.employment_status, p.employment_type
                  FROM users t
                  JOIN roles r ON r.id = t.role_id
             LEFT JOIN departments d ON d.id = t.department_id
             LEFT JOIN teams tm ON tm.id = t.team_id
             LEFT JOIN users m ON m.id = t.manager_id
             LEFT JOIN employee_profiles p ON p.user_id = t.id';
    }

    protected function fields(?array $row): array
    {
        $u    = $this->user();
        $id   = $row['id'] ?? null;
        $self = $row && (int) $row['id'] === $u->id;

        return [
            'name'              => ['label' => __('Full name'), 'rules' => 'required|max:120', 'example' => 'Jane Perera'],
            'email'             => ['label' => __('Work e-mail'), 'type' => 'email', 'rules' => 'required|email|max:190|unique:users,email'.($id ? ','.$id : ''), 'example' => 'jane@acmeinter.com'],
            'password'          => ['label' => $row ? __('New password') : __('Password'), 'type' => 'password', 'import' => false, 'rules' => 'nullable|password',
                                    'help' => $row ? __('Leave empty to keep the current password.') : __('Leave empty to e-mail them a link to set their own.')],
            'role_id'           => ['label' => __('Role'), 'type' => 'select', 'options' => array_map(fn ($n) => __($n), Options::roles($u)), 'rules' => 'required',
                                    'all_options' => array_map(fn ($n) => __($n), Options::allRoles()), 'readonly' => $self],
            'is_active'         => ['label' => __('Can sign in'), 'type' => 'checkbox', 'default' => true, 'help' => __('Untick to block access without deleting the record.'), 'readonly' => $self],

            '_employment'       => ['section' => __('Employment')],
            'employee_code'     => ['label' => __('Employee code'), 'table' => false, 'rules' => 'nullable|max:30|unique:employee_profiles,employee_code'.($id ? ','.$id.',user_id' : ''), 'example' => 'EMP-0100'],
            'job_title'         => ['label' => __('Job title'), 'table' => false, 'rules' => 'nullable|max:120', 'example' => 'Technician'],
            'department_id'     => ['label' => __('Department'), 'type' => 'select', 'options' => Options::departments($u), 'rules' => $u->scope() === 'all' ? 'nullable' : 'required'],
            'team_id'           => ['label' => __('Team'), 'type' => 'select', 'options' => Options::teams($u), 'rules' => 'nullable'],
            'manager_id'        => ['label' => __('Reports to'), 'type' => 'select', 'options' => array_diff_key(Options::users($u), $id ? [$id => 1] : []), 'rules' => 'nullable',
                                    'all_options' => $row && $row['manager_id'] ? [$row['manager_id'] => $row['manager_name']] : []],
            'employee_level'    => ['label' => __('Employee level'), 'type' => 'select', 'table' => false, 'options' => enum_options('levels'), 'rules' => 'nullable', 'example' => 'C3'],
            'employment_status' => ['label' => __('Employment status'), 'type' => 'select', 'table' => false, 'options' => enum_options('statuses'), 'rules' => 'required', 'default' => 'ACTIVE'],
            'employment_type'   => ['label' => __('Employment type'), 'type' => 'select', 'table' => false, 'options' => enum_options('types'), 'rules' => 'required', 'default' => 'FULL_TIME'],
            'joined_at'         => ['label' => __('Joined on'), 'type' => 'date', 'table' => false, 'rules' => 'nullable|date', 'example' => date('Y-m-d')],

            '_personal'         => ['section' => __('Personal')],
            'gender'            => ['label' => __('Gender'), 'type' => 'select', 'table' => false, 'options' => enum_options('genders'), 'rules' => 'nullable'],
            'date_of_birth'     => ['label' => __('Date of birth'), 'type' => 'date', 'table' => false, 'rules' => 'nullable|date'],
            'phone'             => ['label' => __('Phone'), 'table' => false, 'rules' => 'nullable|max:30'],
        ];
    }

    protected function columns(): array
    {
        $statusTone = ['ACTIVE' => 'bg-emerald-50 text-emerald-800', 'PROBATION' => 'bg-amber-50 text-amber-800', 'SUSPENDED' => 'bg-signal-50 text-signal-800'];

        return [
            'name'   => ['label' => __('Name'), 'primary' => true, 'render' => fn ($r) => '<a href="'.url('/members/'.$r['id']).'" class="flex items-center gap-3">'
                .partial('partials/avatar', ['name' => $r['name'], 'avatar' => $r['avatar'], 'size' => 'size-8'])
                .'<span class="min-w-0"><span class="block truncate font-medium hover:text-signal-700">'.e($r['name']).'</span><span class="block truncate text-xs text-steel">'.e($r['email']).'</span></span></a>'],
            'role'   => ['label' => __('Role'), 'render' => fn ($r) => e(__($r['role_name'])).($r['data_scope'] ? ' <span class="badge ml-1 bg-amber-50 text-amber-800" title="'.e(__('Custom data scope')).'">'.e(__('custom')).'</span>' : '')],
            'where'  => ['label' => __('Department / team'), 'render' => fn ($r) => e($r['department_name'] ?? '—').($r['team_name'] ? '<span class="block text-xs text-steel">'.e($r['team_name']).'</span>' : '')],
            'job'    => ['label' => __('Job title'), 'render' => fn ($r) => e($r['job_title'] ?? '—').($r['employee_level'] ? ' <span class="badge ml-1 bg-graphite-900/6 text-graphite-800">'.e(enum_label('levels', $r['employee_level'])).'</span>' : '')],
            'status' => ['label' => __('Employment'), 'render' => fn ($r) => '<span class="badge '.($statusTone[$r['employment_status']] ?? 'bg-graphite-900/6 text-steel').'">'.e(enum_label('statuses', $r['employment_status'])).'</span>'
                .(filter_var($r['is_active'], FILTER_VALIDATE_BOOL) ? '' : ' <span class="badge bg-graphite-900 text-white">'.e(__('Blocked')).'</span>')
                .'<span class="mt-0.5 block text-xs text-steel">'.e(enum_label('types', $r['employment_type'])).'</span>'],
            'login'  => ['label' => __('Last sign-in'), 'render' => fn ($r) => '<span class="text-xs text-steel">'.e(time_ago($r['last_login_at'])).'</span>'],
        ];
    }

    protected function searchable(): array { return ['t.name', 't.email', 'p.employee_code', 'p.job_title']; }

    protected function filters(): array
    {
        return [
            'role'       => ['label' => __('All roles'), 'options' => array_map(fn ($n) => __($n), Options::allRoles()), 'column' => 't.role_id'],
            'department' => ['label' => __('All departments'), 'options' => Options::departments($this->user()), 'column' => 't.department_id'],
            'status'     => ['label' => __('Any employment status'), 'options' => enum_options('statuses'), 'column' => 'p.employment_status'],
            'active'     => ['label' => __('Can and cannot sign in'), 'options' => ['true' => __('Can sign in'), 'false' => __('Blocked')], 'column' => 't.is_active'],
        ];
    }

    protected function owner(array $row): ?int { return (int) $row['id']; }

    /** Admin can manage anyone (but not delete themselves); others only people ranked below them. */
    protected function canModify(array $row, string $action): bool
    {
        $u = $this->user();
        if ((int) $row['id'] === $u->id) {
            return $action === 'edit';
        }

        return $u->isAdmin() || (int) $row['role_level'] < $u->role_level;
    }

    protected function prepare(array $data, ?array $existing): array
    {
        if (! empty($data['password'])) {
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        } elseif ($existing) {
            unset($data['password']);
        } else {
            $data['password'] = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT); // they set their own via e-mail link
            $data['_invite']  = true;
        }

        if (! empty($data['team_id'])) {
            $teamDept = DB::scalar('SELECT department_id FROM teams WHERE id = ?', [$data['team_id']]);
            if (! empty($data['department_id']) && (int) $teamDept !== (int) $data['department_id']) {
                throw new ValidationException(['team_id' => __('This team belongs to another department.')]);
            }
            $data['department_id'] ??= $teamDept;
        }

        return $data;
    }

    protected function tableData(array $fields, array $data): array
    {
        unset($data['_invite']);

        return parent::tableData($fields, $data);
    }

    protected function saved(int $id, array $data, ?array $existing): void
    {
        $profile = array_intersect_key($data, array_flip(self::PROFILE));
        if ($profile) {
            $cols = array_keys($profile);
            DB::exec('INSERT INTO employee_profiles (user_id, '.implode(', ', $cols).') VALUES (?'.str_repeat(', ?', count($cols)).')
                      ON CONFLICT (user_id) DO UPDATE SET '.implode(', ', array_map(fn ($c) => "{$c} = EXCLUDED.{$c}", $cols)).', updated_at = now()',
                [$id, ...array_map(fn ($v) => $v === '' ? null : $v, array_values($profile))]);
        }

        // Losing access or getting a new password ends their other sessions.
        if ($existing && (isset($data['password']) || (array_key_exists('is_active', $data) && ! $data['is_active']))) {
            DB::exec('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$id]);
            DB::exec('DELETE FROM remember_tokens WHERE user_id = ?', [$id]);
        }
        Permission::forget($id);

        if (! empty($data['_invite'])) {
            $this->sendInvite($id);
        }
    }

    private function sendInvite(int $id): void
    {
        $u     = DB::first('SELECT name, email, locale FROM users WHERE id = ?', [$id]);
        $token = bin2hex(random_bytes(32));
        DB::exec('INSERT INTO password_resets (email, token_hash, created_at) VALUES (lower(?), ?, now())
                  ON CONFLICT (email) DO UPDATE SET token_hash = EXCLUDED.token_hash, created_at = now()', [$u['email'], hash('sha256', $token)]);
        $by = $this->user()->name;
        I18n::with($u['locale'] ?? null, fn () => Mailer::notify($u['email'], $u['name'], __('Your account is ready'), [
            __('An account was created for you by :name.', ['name' => e($by)]),
            __('Choose your password with the button below. The link works for :minutes minutes; after that, use “Forgot password” on the sign-in page.', ['minutes' => (int) config('security.reset_minutes', 60)]),
        ], ['label' => __('Set my password'), 'url' => url('/reset-password', ['token' => $token, 'email' => $u['email']])]));
    }

    protected function beforeDelete(array $row): void
    {
        DB::exec('UPDATE users SET is_active = FALSE, session_version = session_version + 1 WHERE id = ?', [$row['id']]);
        DB::exec('DELETE FROM remember_tokens WHERE user_id = ?', [$row['id']]);
        // Free the e-mail so it can be used again
        DB::exec("UPDATE users SET email = email || '.deleted.' || id WHERE id = ?", [$row['id']]);
    }

    protected function rowLinks(array $row): array
    {
        return can('roles', 'view') ? [['label' => __('Access rules'), 'url' => '/members/'.$row['id'].'/access', 'icon' => 'shield']] : [];
    }

    protected function intro(): string
    {
        return $this->user()->isAdmin() ? '' : __('You can manage people in your :scope view whose role is below yours.', ['scope' => mb_strtolower(__(config('core.scopes')[$this->user()->scope()]))]);
    }

    /* ------------------------------------------------- per-member access */

    public function access(int $id): string
    {
        $this->authorize('roles', 'view');
        $row = $this->find($id);

        $perms     = DB::select('SELECT * FROM permissions ORDER BY module, sort');
        $roleRows  = DB::select('SELECT * FROM role_permissions WHERE role_id = ?', [$row['role_id']]);
        $userRows  = DB::select('SELECT * FROM user_permissions WHERE user_id = ?', [$id]);
        $roleMap   = array_column($roleRows, null, 'permission_id');
        $userMap   = array_column($userRows, null, 'permission_id');
        $canChange = can('roles', 'edit') && $this->canModify($row, 'edit') && $row['role_slug'] !== 'admin';

        return view('members/access', compact('row', 'perms', 'roleMap', 'userMap', 'canChange') + [
            'title' => __('Access rules for :name', ['name' => $row['name']]), 'roleScope' => DB::scalar('SELECT data_scope FROM roles WHERE id = ?', [$row['role_id']]),
        ]);
    }

    public function saveAccess(int $id): never
    {
        $this->authorize('roles', 'edit');
        $row = $this->find($id);
        if (! $this->canModify($row, 'edit') || $row['role_slug'] === 'admin') {
            abort(403, __("This member's access can't be changed."));
        }

        $scope = (string) Request::input('data_scope', '');
        if ($scope !== '' && ! isset(config('core.scopes')[$scope])) {
            throw new ValidationException(['data_scope' => __('Choose a valid data scope.')]);
        }

        $actions = config('core.actions');
        $input   = (array) Request::input('perm', []);
        $changes = [];

        DB::transaction(function () use ($id, $row, $scope, $actions, $input, &$changes) {
            if (($row['data_scope'] ?? '') !== $scope) {
                DB::exec('UPDATE users SET data_scope = ?, updated_at = now() WHERE id = ?', [$scope ?: null, $id]);
                $changes['data scope'] = ['from' => $row['data_scope'] ?: 'role default', 'to' => $scope ?: 'role default'];
            }
            $old = array_column(DB::select('SELECT up.*, p.key FROM user_permissions up JOIN permissions p ON p.id = up.permission_id WHERE user_id = ?', [$id]), null, 'key');
            DB::exec('DELETE FROM user_permissions WHERE user_id = ?', [$id]);

            foreach (DB::select('SELECT id, key FROM permissions') as $p) {
                $vals = [];
                foreach ($actions as $a) {
                    $v = $input[$p['key']][$a] ?? '';
                    $vals["can_{$a}"] = $v === 'allow' ? true : ($v === 'deny' ? false : null);
                    $before = $old[$p['key']]["can_{$a}"] ?? null;
                    $before = $before === null ? null : filter_var($before, FILTER_VALIDATE_BOOL);
                    if ($before !== $vals["can_{$a}"]) {
                        $label = fn ($x) => $x === null ? 'inherit' : ($x ? 'allow' : 'deny');
                        $changes["{$p['key']}.{$a}"] = ['from' => $label($before), 'to' => $label($vals["can_{$a}"])];
                    }
                }
                if (array_filter($vals, fn ($v) => $v !== null)) {
                    DB::insert('user_permissions', ['user_id' => $id, 'permission_id' => $p['id']] + $vals, 'core', '');
                }
            }
        });

        Permission::forget($id);
        if ($changes) {
            Activity::log('updated', 'user', $id, $row['name'], ['Changed access rules for ":label"', ['label' => $row['name']]], ['changes' => $changes], $id);
        }
        Session::flash('success', $changes ? __('Access rules saved.') : __('Nothing changed.'));
        redirect('/members/'.$id.'/access');
    }
}
