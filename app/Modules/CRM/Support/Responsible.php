<?php
declare(strict_types=1);

namespace App\Modules\CRM\Support;

use App\Core\Support\DB;
use App\Core\Support\Notifier;

/**
 * The people responsible for a project (project_members) or a task (task_assignees), several at once.
 * Whoever stops being responsible is written to ownership_history, so the record keeps who had it before.
 */
final class Responsible
{
    private const MAP = [
        'project' => ['table' => 'project_members', 'fk' => 'project_id', 'path' => 'projects'],
        'task'    => ['table' => 'task_assignees', 'fk' => 'task_id', 'path' => 'tasks'],
    ];

    /** @return int[] */
    public static function ids(string $type, int $id): array
    {
        $m = self::MAP[$type];

        return array_map('intval', array_column(DB::select("SELECT user_id FROM {$m['table']} WHERE {$m['fk']} = ? ORDER BY created_at, user_id", [$id], 'crm'), 'user_id'));
    }

    /** [record id => [user ids]] for a page of records. */
    public static function idsFor(string $type, array $recordIds): array
    {
        $recordIds = array_values(array_unique(array_map('intval', $recordIds)));
        if (! $recordIds) {
            return [];
        }
        $m   = self::MAP[$type];
        $in  = implode(',', array_fill(0, count($recordIds), '?'));
        $out = [];
        foreach (DB::select("SELECT {$m['fk']} AS rid, user_id FROM {$m['table']} WHERE {$m['fk']} IN ({$in}) ORDER BY created_at, user_id", $recordIds, 'crm') as $r) {
            $out[(int) $r['rid']][] = (int) $r['user_id'];
        }

        return $out;
    }

    /** Make the list exactly $wanted; tell the new people; remember the ones who left. */
    public static function sync(string $type, int $id, array $wanted, int $by, string $label, bool $notify = true): void
    {
        $m      = self::MAP[$type];
        $people = Access::people();
        $wanted = array_values(array_unique(array_filter(array_map('intval', $wanted), fn ($u) => isset($people[$u]))));
        $have   = self::ids($type, $id);

        foreach (array_diff($wanted, $have) as $uid) {
            DB::exec("INSERT INTO {$m['table']} ({$m['fk']}, user_id, added_by) VALUES (?, ?, ?) ON CONFLICT DO NOTHING", [$id, $uid, $by], 'crm');
            if ($notify && $uid !== $by) {
                self::tell($type, $id, $uid, $by, $label);
            }
        }
        foreach (array_diff($have, $wanted) as $uid) {
            DB::exec("DELETE FROM {$m['table']} WHERE {$m['fk']} = ? AND user_id = ?", [$id, $uid], 'crm');
            self::remember($type, $id, $uid, null, $by, null);
        }
    }

    /** Hand one person's part to somebody else (keeps the history). */
    public static function replace(string $type, int $id, int $from, int $to, int $by, string $label, ?string $note = null): bool
    {
        $m = self::MAP[$type];
        if (! DB::scalar("SELECT 1 FROM {$m['table']} WHERE {$m['fk']} = ? AND user_id = ?", [$id, $from], 'crm')) {
            return false;
        }
        DB::exec("DELETE FROM {$m['table']} WHERE {$m['fk']} = ? AND user_id = ?", [$id, $from], 'crm');
        DB::exec("INSERT INTO {$m['table']} ({$m['fk']}, user_id, added_by) VALUES (?, ?, ?) ON CONFLICT DO NOTHING", [$id, $to, $by], 'crm');
        self::remember($type, $id, $from, $to, $by, $note);

        return true;
    }

    public static function remember(string $type, int $id, ?int $from, ?int $to, int $by, ?string $note, string $role = 'responsible'): void
    {
        DB::insert('ownership_history', ['entity_type' => $type, 'entity_id' => $id, 'role' => $role, 'from_user_id' => $from, 'to_user_id' => $to, 'changed_by' => $by, 'note' => $note ? mb_substr($note, 0, 255) : null], 'crm');
    }

    /** Who had the record before: newest first, with names. */
    public static function history(string $type, int $id): array
    {
        $rows = DB::select('SELECT * FROM ownership_history WHERE entity_type = ? AND entity_id = ? ORDER BY created_at DESC, id DESC LIMIT 30', [$type, $id], 'crm');
        $names = Access::names('users', array_merge(array_column($rows, 'from_user_id'), array_column($rows, 'to_user_id'), array_column($rows, 'changed_by')));
        foreach ($rows as &$r) {
            $r['from_name'] = $names[$r['from_user_id'] ?? 0] ?? null;
            $r['to_name']   = $names[$r['to_user_id'] ?? 0] ?? null;
            $r['by_name']   = $names[$r['changed_by'] ?? 0] ?? null;
        }

        return $rows;
    }

    public static function tell(string $type, int $id, int $userId, int $by, string $label): void
    {
        $actor = Access::names('users', [$by])[$by] ?? __('Someone');
        Notifier::deliver('crm', 'assigned', $userId,
            ['key' => ':name made you responsible for :type ":label"', 'params' => ['name' => $actor, 'type' => $type, 'label' => $label]],
            ['key' => ':label', 'params' => ['label' => $label]],
            url('/crm/'.self::MAP[$type]['path'].'/'.$id), $by);
    }
}
