<?php
declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Auth\CurrentUser;

/**
 * 1. Permission: role matrix (role_permissions) + member overrides (user_permissions, NULL = inherit).
 * 2. Data scope: which rows a user may see — all | department | team | own.
 */
final class Permission
{
    private static array $cache = [];

    public static function actions(): array { return config('core.actions'); }

    public static function matrix(int $userId, int $roleId): array
    {
        if (isset(self::$cache[$userId])) {
            return self::$cache[$userId];
        }
        $cols   = implode(', ', array_map(fn ($a) => "rp.can_{$a}", self::actions()));
        $matrix = [];

        foreach (DB::select("SELECT p.key, {$cols} FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?", [$roleId]) as $row) {
            foreach (self::actions() as $a) {
                $matrix[$row['key']][$a] = (bool) $row["can_{$a}"];
            }
        }
        $cols = str_replace('rp.', 'up.', $cols);
        foreach (DB::select("SELECT p.key, {$cols} FROM user_permissions up JOIN permissions p ON p.id = up.permission_id WHERE up.user_id = ?", [$userId]) as $row) {
            foreach (self::actions() as $a) {
                if ($row["can_{$a}"] !== null) {
                    $matrix[$row['key']][$a] = (bool) $row["can_{$a}"];
                }
            }
        }

        return self::$cache[$userId] = $matrix;
    }

    public static function forget(?int $userId = null): void
    {
        if ($userId) {
            unset(self::$cache[$userId]);
        } else {
            self::$cache = [];
        }
    }

    /**
     * SQL fragment limiting rows to what $user may see.
     * $cols: ['owner' => 'col'|[cols], 'department' => 'col'|null, 'team' => 'col'|null]
     * Returns ['sql' => '(...)', 'params' => [...]] — always safe to AND into a WHERE.
     */
    public static function scope(CurrentUser $user, string $alias, array $cols): array
    {
        $scope = $user->isAdmin() ? 'all' : $user->scope();
        if ($scope === 'all') {
            return ['sql' => 'TRUE', 'params' => []];
        }

        $parts  = [];
        $params = [];
        if ($scope === 'department' && ! empty($cols['department'])) {
            $parts[]  = "{$alias}.{$cols['department']} = ?";
            $params[] = $user->department_id;
        }
        if ($scope === 'team' && ! empty($cols['team'])) {
            $parts[]  = "{$alias}.{$cols['team']} = ?";
            $params[] = $user->team_id;
        }
        foreach ((array) ($cols['owner'] ?? []) as $c) {
            $parts[]  = "{$alias}.{$c} = ?";
            $params[] = $user->id;
        }

        return ['sql' => $parts ? '('.implode(' OR ', $parts).')' : 'FALSE', 'params' => $params];
    }
}

