<?php
declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Auth\CurrentUser;

/** Dropdown options, already limited to what the signed-in user may see. */
final class Options
{
    public static function users(CurrentUser $u, bool $activeOnly = true): array
    {
        $s = Permission::scope($u, 'u', ['owner' => 'id', 'department' => 'department_id', 'team' => 'team_id']);
        $rows = DB::select('SELECT u.id, u.name FROM users u WHERE u.deleted_at IS NULL'.($activeOnly ? ' AND u.is_active' : '').' AND '.$s['sql'].' ORDER BY u.name', $s['params']);

        return array_column($rows, 'name', 'id');
    }

    public static function departments(CurrentUser $u): array
    {
        $rows = in_array($u->scope(), ['all'], true)
            ? DB::select('SELECT id, name FROM departments WHERE deleted_at IS NULL ORDER BY name')
            : DB::select('SELECT id, name FROM departments WHERE deleted_at IS NULL AND id = ?', [$u->department_id ?? 0]);

        return array_column($rows, 'name', 'id');
    }

    public static function teams(CurrentUser $u): array
    {
        $sql = 'SELECT t.id, d.code || \' / \' || t.name AS name FROM teams t JOIN departments d ON d.id = t.department_id WHERE t.deleted_at IS NULL';
        $rows = match ($u->scope()) {
            'all'        => DB::select($sql.' ORDER BY name'),
            'department' => DB::select($sql.' AND t.department_id = ? ORDER BY name', [$u->department_id]),
            default      => DB::select($sql.' AND t.id = ?', [$u->team_id ?? 0]),
        };

        return array_column($rows, 'name', 'id');
    }

    /** Roles this user may hand out: admins any, others only roles below their own level. */
    public static function roles(CurrentUser $u): array
    {
        $rows = $u->isAdmin()
            ? DB::select('SELECT id, name FROM roles ORDER BY level DESC')
            : DB::select('SELECT id, name FROM roles WHERE level < ? ORDER BY level DESC', [$u->role_level]);

        return array_column($rows, 'name', 'id');
    }

    public static function allRoles(): array
    {
        return array_column(DB::select('SELECT id, name FROM roles ORDER BY level DESC'), 'name', 'id');
    }
}
