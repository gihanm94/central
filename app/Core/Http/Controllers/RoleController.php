<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Permission;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\ValidationException;

/** Roles and the permission matrix (view / create / edit / delete / import / export / download). */
class RoleController extends Controller
{
    public function index(): string
    {
        $this->authorize('roles', 'view');
        $roles = DB::select('SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id AND u.deleted_at IS NULL) AS users_count
                               FROM roles r ORDER BY r.level DESC');
        $summary = [];
        foreach (DB::select('SELECT rp.*, p.key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id') as $rp) {
            foreach (config('core.actions') as $a) {
                if (filter_var($rp["can_{$a}"], FILTER_VALIDATE_BOOL)) {
                    $summary[$rp['role_id']][$rp['key']][] = $a;
                }
            }
        }

        return view('roles/index', ['title' => __('Roles & access'), 'roles' => $roles, 'summary' => $summary]);
    }

    public function create(): string
    {
        $this->authorize('roles', 'create');

        return view('roles/form', ['title' => __('New role'), 'role' => null]);
    }

    public function store(): never
    {
        $this->authorize('roles', 'create');
        $data = $this->validated(null);
        $id   = (int) DB::insert('roles', $data + ['is_system' => false]);
        Activity::log('created', 'role', $id, $data['name'], ['Created :type ":label"', ['type' => 'role', 'label' => $data['name']]], ['attributes' => $data]);
        Session::flash('success', __('Role created. Now choose what it can do.'));
        redirect('/roles/'.$id.'/permissions');
    }

    public function edit(int $id): string
    {
        $this->authorize('roles', 'edit');

        return view('roles/form', ['title' => __('Edit role'), 'role' => $this->find($id)]);
    }

    public function update(int $id): never
    {
        $this->authorize('roles', 'edit');
        $role = $this->find($id);
        $data = $this->validated($role);
        if ($role['slug'] === 'admin') {
            $data['data_scope'] = 'all';
            $data['level'] = 100;
        }
        DB::update('roles', $data + ['updated_at' => now()], ['id' => $id]);
        Permission::forget();
        $changes = Activity::diff($role, $data, array_keys($data));
        if ($changes) {
            Activity::log('updated', 'role', $id, $data['name'], ['Updated :type ":label"', ['type' => 'role', 'label' => $data['name']]], ['changes' => $changes]);
        }
        Session::flash('success', __('Role saved.'));
        redirect('/roles');
    }

    public function destroy(int $id): never
    {
        $this->authorize('roles', 'delete');
        $role = $this->find($id);
        if (filter_var($role['is_system'], FILTER_VALIDATE_BOOL)) {
            throw new ValidationException(['role' => __('Built-in roles cannot be deleted.')]);
        }
        if (DB::scalar('SELECT COUNT(*) FROM users WHERE role_id = ? AND deleted_at IS NULL', [$id]) > 0) {
            throw new ValidationException(['role' => __('Move everyone out of this role before deleting it.')]);
        }
        DB::exec('DELETE FROM roles WHERE id = ?', [$id]);
        Activity::log('deleted', 'role', $id, $role['name'], ['Deleted :type ":label"', ['type' => 'role', 'label' => $role['name']]]);
        Session::flash('success', __('Role deleted.'));
        redirect('/roles');
    }

    public function permissions(int $id): string
    {
        $this->authorize('roles', 'view');
        $role = $this->find($id);

        return view('roles/permissions', [
            'title' => __(':role permissions', ['role' => __($role['name'])]), 'role' => $role,
            'perms' => DB::select('SELECT * FROM permissions ORDER BY module, sort'),
            'map'   => array_column(DB::select('SELECT * FROM role_permissions WHERE role_id = ?', [$id]), null, 'permission_id'),
            'canChange' => can('roles', 'edit') && $role['slug'] !== 'admin',
        ]);
    }

    public function savePermissions(int $id): never
    {
        $this->authorize('roles', 'edit');
        $role = $this->find($id);
        if ($role['slug'] === 'admin') {
            abort(403, __('Admin always has full access.'));
        }

        $input   = (array) Request::input('perm', []);
        $changes = [];
        DB::transaction(function () use ($id, $input, &$changes) {
            $old = array_column(DB::select('SELECT rp.*, p.key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE role_id = ?', [$id]), null, 'key');
            foreach (DB::select('SELECT id, key FROM permissions') as $p) {
                $vals = [];
                foreach (config('core.actions') as $a) {
                    $vals["can_{$a}"] = ! empty($input[$p['key']][$a]);
                    $before = filter_var($old[$p['key']]["can_{$a}"] ?? false, FILTER_VALIDATE_BOOL);
                    if ($before !== $vals["can_{$a}"]) {
                        $changes["{$p['key']}.{$a}"] = ['from' => $before ? 'yes' : 'no', 'to' => $vals["can_{$a}"] ? 'yes' : 'no'];
                    }
                }
                $cols = array_keys($vals);
                DB::exec('INSERT INTO role_permissions (role_id, permission_id, '.implode(', ', $cols).') VALUES (?, ?'.str_repeat(', ?', count($cols)).')
                          ON CONFLICT (role_id, permission_id) DO UPDATE SET '.implode(', ', array_map(fn ($c) => "{$c} = EXCLUDED.{$c}", $cols)),
                    [$id, $p['id'], ...array_values($vals)]);
            }
        });

        Permission::forget();
        if ($changes) {
            Activity::log('updated', 'role', $id, $role['name'], ['Changed permissions of role ":label"', ['label' => $role['name']]], ['changes' => $changes]);
        }
        Session::flash('success', $changes ? __(':n permission(s) changed for :role.', ['n' => count($changes), 'role' => __($role['name'])]) : __('Nothing changed.'));
        redirect('/roles/'.$id.'/permissions');
    }

    private function find(int $id): array
    {
        return DB::first('SELECT * FROM roles WHERE id = ?', [$id]) ?? abort(404, __('Role not found.'));
    }

    private function validated(?array $role): array
    {
        $data = $this->validate([
            'name'        => 'required|max:80',
            'description' => 'nullable|max:255',
            'level'       => 'required|integer',
            'data_scope'  => 'required|in:'.implode(',', array_keys(config('core.scopes'))),
        ], ['name' => __('Role name'), 'level' => __('Rank'), 'data_scope' => __('Records they can see')]);
        $data['level'] = max(1, min(99, (int) $data['level']));
        if (! $role) {
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($data['name'])), '_') ?: 'role';
            $data['slug'] = DB::scalar('SELECT 1 FROM roles WHERE slug = ?', [$slug]) ? $slug.'_'.substr(bin2hex(random_bytes(2)), 0, 4) : $slug;
        }

        return $data;
    }
}
