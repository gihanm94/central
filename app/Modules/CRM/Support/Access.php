<?php
declare(strict_types=1);

namespace App\Modules\CRM\Support;

use App\Core\Auth\CurrentUser;
use App\Core\Support\DB;

/**
 * Who may see and change CRM records.
 *
 * SEE:    whole-company roles (Admin, Management …) see everything.
 *         Everyone else sees records of their own department, records they own,
 *         and records that were shared with them (a person) or with their department.
 * CHANGE: the owner, whoever created it, the head of the department (a department-scope role),
 *         people it was shared with as "can edit", and whole-company roles — always on top of the
 *         role's own create / edit / delete permission.
 * SHARE:  the owner, the creator, the department head and whole-company roles decide who else gets in.
 */
final class Access
{
    /** type => [path, table, permission, label] */
    public const ENTITIES = [
        'lead'        => ['path' => 'leads',         'table' => 'leads',         'perm' => 'crm_leads',         'label' => 'Lead'],
        'contact'     => ['path' => 'contacts',      'table' => 'contacts',      'perm' => 'crm_contacts',      'label' => 'Contact'],
        'opportunity' => ['path' => 'opportunities', 'table' => 'opportunities', 'perm' => 'crm_opportunities', 'label' => 'Opportunity'],
        'campaign'    => ['path' => 'campaigns',     'table' => 'campaigns',     'perm' => 'crm_campaigns',     'label' => 'Campaign'],
        'activity'    => ['path' => 'activities',    'table' => 'activities',    'perm' => 'crm_activities',    'label' => 'Activity'],
        'project'     => ['path' => 'projects',      'table' => 'projects',      'perm' => 'crm_projects',      'label' => 'Project'],
        'task'        => ['path' => 'tasks',         'table' => 'tasks',         'perm' => 'crm_tasks',         'label' => 'Task'],
    ];

    public static function typeForPath(string $path): ?string
    {
        foreach (self::ENTITIES as $type => $e) {
            if ($e['path'] === $path) {
                return $type;
            }
        }

        return null;
    }

    public static function seesAll(CurrentUser $u): bool { return $u->scope() === 'all'; }

    /** SQL limiting $alias (a CRM table with department_id + owner_id) to what $u may see. */
    public static function visible(CurrentUser $u, string $alias, string $type): array
    {
        if (self::seesAll($u)) {
            return ['sql' => 'TRUE', 'params' => []];
        }
        $parts  = ["{$alias}.owner_id = ?", "{$alias}.created_by = ?"];
        $params = [$u->id, $u->id];
        if ($u->department_id) {
            $parts[]  = "{$alias}.department_id = ?";
            $params[] = $u->department_id;
        }
        $share = "EXISTS (SELECT 1 FROM record_shares rs WHERE rs.entity_type = ? AND rs.entity_id = {$alias}.id AND ((rs.target_type = 'user' AND rs.target_id = ?)";
        $params[] = $type;
        $params[] = $u->id;
        if ($u->department_id) {
            $share   .= " OR (rs.target_type = 'department' AND rs.target_id = ?)";
            $params[] = $u->department_id;
        }
        $parts[] = $share.'))';

        // People who are responsible for a project / task always see it; tasks follow the project they belong to.
        if ($type === 'project') {
            $parts[]  = "EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = {$alias}.id AND pm.user_id = ?)";
            $parts[]  = "EXISTS (SELECT 1 FROM tasks k JOIN task_assignees ta ON ta.task_id = k.id WHERE k.project_id = {$alias}.id AND k.deleted_at IS NULL AND ta.user_id = ?)";
            array_push($params, $u->id, $u->id);
        } elseif ($type === 'task') {
            $parts[]  = "EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = {$alias}.id AND ta.user_id = ?)";
            $parts[]  = "EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = {$alias}.project_id AND pm.user_id = ?)";
            array_push($params, $u->id, $u->id);
            $pshare = "EXISTS (SELECT 1 FROM record_shares rs2 WHERE rs2.entity_type = 'project' AND rs2.entity_id = {$alias}.project_id AND ((rs2.target_type = 'user' AND rs2.target_id = ?)";
            $params[] = $u->id;
            if ($u->department_id) {
                $pshare  .= " OR (rs2.target_type = 'department' AND rs2.target_id = ?)";
                $params[] = $u->department_id;
            }
            $parts[] = $pshare.'))';
        }

        return ['sql' => '('.implode(' OR ', $parts).')', 'params' => $params];
    }

    /** Owner, creator, department head or whole-company role: may share, re-assign and (with permission) change the record. */
    public static function isManager(CurrentUser $u, array $row): bool
    {
        return self::seesAll($u)
            || (int) ($row['owner_id'] ?? 0) === $u->id
            || (int) ($row['created_by'] ?? 0) === $u->id
            || ($u->scope() === 'department' && $u->department_id && (int) ($row['department_id'] ?? 0) === $u->department_id);
    }

    public static function canChange(CurrentUser $u, string $type, array $row): bool
    {
        if (self::isManager($u, $row)) {
            return true;
        }
        if ($type === 'project' && self::isMember($u, (int) $row['id'])) {
            return true;
        }
        if ($type === 'task') {
            $project = DB::first('SELECT * FROM projects WHERE id = ?', [(int) $row['project_id']], 'crm');
            if ($project && (self::isManager($u, $project) || self::isMember($u, (int) $project['id']) || self::shareLevel($u, 'project', (int) $project['id']) === 'edit')) {
                return true;
            }
            if (DB::scalar('SELECT 1 FROM task_assignees WHERE task_id = ? AND user_id = ?', [(int) $row['id'], $u->id], 'crm')) {
                return true;
            }
        }

        return self::shareLevel($u, $type, (int) $row['id']) === 'edit';
    }

    public static function isMember(CurrentUser $u, int $projectId): bool
    {
        return (bool) DB::scalar('SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ?', [$projectId, $u->id], 'crm');
    }

    /** Best access the user got through shares: 'edit', 'view' or null. */
    public static function shareLevel(CurrentUser $u, string $type, int $id): ?string
    {
        $sql    = "SELECT access FROM record_shares WHERE entity_type = ? AND entity_id = ? AND ((target_type = 'user' AND target_id = ?)";
        $params = [$type, $id, $u->id];
        if ($u->department_id) {
            $sql    .= " OR (target_type = 'department' AND target_id = ?)";
            $params[] = $u->department_id;
        }
        $levels = array_column(DB::select($sql.')', $params, 'crm'), 'access');

        return in_array('edit', $levels, true) ? 'edit' : ($levels ? 'view' : null);
    }

    /* ----------------------------------------------------------- shares */

    public static function shares(string $type, int $id): array
    {
        $rows = DB::select('SELECT * FROM record_shares WHERE entity_type = ? AND entity_id = ? ORDER BY target_type DESC, id', [$type, $id], 'crm');
        $users = self::names('users', array_column(array_filter($rows, fn ($r) => $r['target_type'] === 'user'), 'target_id'));
        $deps  = self::names('departments', array_column(array_filter($rows, fn ($r) => $r['target_type'] === 'department'), 'target_id'));
        foreach ($rows as &$r) {
            $r['target_name'] = ($r['target_type'] === 'user' ? $users : $deps)[$r['target_id']] ?? '—';
        }

        return $rows;
    }

    public static function addShare(string $type, int $id, string $targetType, int $targetId, string $access, int $by): void
    {
        DB::exec("INSERT INTO record_shares (entity_type, entity_id, target_type, target_id, access, shared_by) VALUES (?, ?, ?, ?, ?, ?)
                  ON CONFLICT (entity_type, entity_id, target_type, target_id) DO UPDATE SET access = EXCLUDED.access",
            [$type, $id, $targetType, $targetId, $access, $by], 'crm');
    }

    /* ------------------------------------------------- core look-ups (other database) */

    /** [id => name] for users or departments. */
    public static function names(string $table, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids || ! in_array($table, ['users', 'departments'], true)) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));

        return array_column(DB::select("SELECT id, name FROM {$table} WHERE id IN ({$in})", $ids), 'name', 'id');
    }

    /** Active people with their department: [id => ['name', 'department_id', 'department']] */
    public static function people(): array
    {
        $rows = DB::select('SELECT u.id, u.name, u.department_id, d.name AS department FROM users u LEFT JOIN departments d ON d.id = u.department_id
                             WHERE u.deleted_at IS NULL AND u.is_active ORDER BY u.name');

        return array_column($rows, null, 'id');
    }

    /** Everybody who still has a login record, also people switched off (they may have left): [id => [name, department_id, department, active]] */
    public static function everyone(): array
    {
        $rows = DB::select('SELECT u.id, u.name, u.department_id, u.is_active AS active, d.name AS department FROM users u LEFT JOIN departments d ON d.id = u.department_id
                             WHERE u.deleted_at IS NULL ORDER BY u.is_active DESC, u.name');

        return array_column($rows, null, 'id');
    }

    /** Who this user may hand a record to: everyone for whole-company roles, otherwise the own department. */
    public static function assignable(CurrentUser $u): array
    {
        $out = [];
        foreach (self::people() as $id => $p) {
            if (self::seesAll($u) || $id === $u->id || ($u->department_id && (int) $p['department_id'] === $u->department_id)) {
                $out[$id] = $p['name'].($p['department'] && self::seesAll($u) ? ' · '.$p['department'] : '');
            }
        }

        return $out;
    }

    public static function departments(): array
    {
        return array_column(DB::select('SELECT id, name FROM departments WHERE deleted_at IS NULL AND is_active ORDER BY name'), 'name', 'id');
    }
}
