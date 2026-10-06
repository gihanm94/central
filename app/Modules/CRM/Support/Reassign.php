<?php
declare(strict_types=1);

namespace App\Modules\CRM\Support;

use App\Core\Auth\CurrentUser;
use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Notifier;

/**
 * Hand records to somebody else (somebody left, changed team, is overloaded) without losing who had them before:
 * every change is written to ownership_history and the record keeps its comments, files, steps and logs.
 */
final class Reassign
{
    /** type => table, label column, SQL that means "still open", status column (for one-click bulk change) and status list */
    public static function types(): array
    {
        return [
            'opportunity' => ['table' => 'opportunities', 'label' => 'name', 'open' => "t.opportunity_stage IN ('QUALIFICATION','SURVEY_PROPOSAL','EVALUATION_TESTING','NEGOTIATION','ON_HOLD')", 'status' => 't.opportunity_stage', 'statuses' => Catalog::stages(), 'name' => __('Opportunities')],
            'activity'    => ['table' => 'activities',    'label' => 'topic', 'open' => "t.status = 'PLANNED'", 'status' => 't.status', 'statuses' => Catalog::tr(Catalog::ACTIVITY_STATUSES), 'name' => __('Activities')],
            'project'     => ['table' => 'projects',      'label' => 'name',  'open' => "t.status IN ('PLANNING','ACTIVE','ON_HOLD')", 'status' => 't.status', 'statuses' => Catalog::tr(Catalog::PROJECT_STATUSES), 'name' => __('Projects'), 'resp' => 'project'],
            'task'        => ['table' => 'tasks',         'label' => 'name',  'open' => "t.status NOT IN ('DONE','CANCELLED')", 'status' => 't.status', 'statuses' => Catalog::tr(Catalog::TASK_STATUSES), 'name' => __('Tasks'), 'resp' => 'task'],
            'campaign'    => ['table' => 'campaigns',     'label' => 'name',  'open' => "t.status IN ('DRAFT','PLANNED','ACTIVE')", 'status' => 't.status', 'statuses' => Catalog::tr(Catalog::CAMPAIGN_STATUSES), 'name' => __('Campaigns')],
            'lead'        => ['table' => 'leads',         'label' => 'name_en', 'open' => 'TRUE', 'status' => null, 'statuses' => [], 'name' => __('Leads')],
            'contact'     => ['table' => 'contacts',      'label' => 'name_en', 'open' => 'TRUE', 'status' => null, 'statuses' => [], 'name' => __('Contacts')],
        ];
    }

    /** May this person reassign / bulk-change this record? Whole-company roles: any; department heads: their own department. */
    public static function inScope(CurrentUser $u, array $row): bool
    {
        return can('crm_reassign', 'edit') && (Access::seesAll($u) || ($u->department_id && (int) ($row['department_id'] ?? 0) === $u->department_id));
    }

    /** SQL "this record is the person's": owner, or (projects, tasks) one of the responsible. */
    public static function mineSql(string $type): string
    {
        $r = self::types()[$type]['resp'] ?? null;

        return $r === 'project' ? '(t.owner_id = ? OR EXISTS (SELECT 1 FROM project_members x WHERE x.project_id = t.id AND x.user_id = ?))'
            : ($r === 'task' ? '(t.owner_id = ? OR EXISTS (SELECT 1 FROM task_assignees x WHERE x.task_id = t.id AND x.user_id = ?))' : 't.owner_id = ?');
    }

    public static function mineParams(string $type, int $userId): array
    {
        return isset(self::types()[$type]['resp']) ? [$userId, $userId] : [$userId];
    }

    /** Records of one person that this viewer may change: [rows with id, label, status, department_id]. */
    public static function recordsOf(CurrentUser $viewer, string $type, int $userId, bool $openOnly, int $limit = 300): array
    {
        $t     = self::types()[$type];
        $where = ['t.deleted_at IS NULL', self::mineSql($type)];
        $par   = self::mineParams($type, $userId);
        if ($openOnly) {
            $where[] = $t['open'];
        }
        if (! Access::seesAll($viewer)) {
            $where[] = 't.department_id = ?';
            $par[]   = (int) $viewer->department_id;
        }
        $status = $t['status'] ? ", {$t['status']} AS status" : ', NULL AS status';

        return DB::select("SELECT t.id, t.{$t['label']} AS label, t.owner_id, t.department_id, t.updated_at{$status} FROM {$t['table']} t WHERE ".implode(' AND ', $where)." ORDER BY t.updated_at DESC, t.id DESC LIMIT {$limit}", $par, 'crm');
    }

    /** How many of each type a person has (open / all), for the cards on the Team screen. */
    public static function counts(CurrentUser $viewer, int $userId): array
    {
        $out = [];
        foreach (self::types() as $type => $t) {
            $par   = self::mineParams($type, $userId);
            $scope = '';
            if (! Access::seesAll($viewer)) {
                $scope = ' AND t.department_id = ?';
                $par[] = (int) $viewer->department_id;
            }
            $row = DB::first("SELECT count(*) AS total, count(*) FILTER (WHERE {$t['open']}) AS open FROM {$t['table']} t WHERE t.deleted_at IS NULL AND ".self::mineSql($type).$scope, $par, 'crm');
            $out[$type] = ['total' => (int) $row['total'], 'open' => (int) $row['open']];
        }

        return $out;
    }

    /**
     * Give records to $to. $from (when known) is the person who is being replaced — for projects and tasks their place
     * among the responsible people is taken over too. Returns [moved, skipped].
     */
    public static function apply(CurrentUser $by, string $type, array $ids, int $to, ?int $from, ?string $note, bool $moveDepartment): array
    {
        $t      = self::types()[$type] ?? throw new \InvalidArgumentException('Unknown type');
        $people = Access::everyone();
        $target = $people[$to] ?? null;
        if (! $target || ! $target['active']) {
            throw new \App\Core\Support\ValidationException(['to' => __('Pick somebody who is active.')]);
        }
        if (! Access::seesAll($by) && (int) $target['department_id'] !== (int) $by->department_id) {
            throw new \App\Core\Support\ValidationException(['to' => __('You can only hand records to people in your own department.')]);
        }
        $moved = $skipped = 0;
        $first = null;
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $row = DB::first("SELECT * FROM {$t['table']} WHERE id = ? AND deleted_at IS NULL", [$id], 'crm');
            if (! $row || ! self::inScope($by, $row)) {
                $skipped++;
                continue;
            }
            $label = (string) $row[$t['label']];
            $oldOwner = (int) ($row['owner_id'] ?? 0);
            $changed  = false;
            if ($oldOwner !== $to) {
                $set = ['owner_id' => $to, 'updated_by' => $by->id, 'updated_at' => now()];
                if ($moveDepartment && $target['department_id'] && (int) $target['department_id'] !== (int) $row['department_id']) {
                    $set['department_id'] = (int) $target['department_id'];
                }
                DB::update($t['table'], $set, ['id' => $id], 'crm');
                Responsible::remember($type, $id, $oldOwner ?: null, $to, $by->id, $note, 'owner');
                $changed = true;
            }
            if (isset($t['resp'])) {
                foreach (array_filter([$from, $oldOwner ?: null]) as $replaced) {
                    if ($replaced !== $to && Responsible::replace($type, $id, (int) $replaced, $to, $by->id, $label, $note)) {
                        $changed = true;
                    }
                }
            }
            if (! $changed) {
                $skipped++;
                continue;
            }
            $moved++;
            $first ??= ['id' => $id, 'label' => $label];
            Activity::log('updated', $type, $id, $label, ['Reassigned :type ":label" from :from to :to', ['type' => $type, 'label' => $label, 'from' => $people[$oldOwner]['name'] ?? '—', 'to' => $target['name']]],
                ['changes' => ['owner' => ['from' => $people[$oldOwner]['name'] ?? '—', 'to' => $target['name']]], '_url' => url('/crm/'.Access::ENTITIES[$type]['path'].'/'.$id)], $to, notify: false, module: 'crm');
        }
        if ($moved && $to !== $by->id) {
            $url = $moved === 1 && $first ? url('/crm/'.Access::ENTITIES[$type]['path'].'/'.$first['id']) : url('/crm/'.Access::ENTITIES[$type]['path'].'?scope=mine');
            Notifier::deliver('crm', 'assigned', $to,
                ['key' => ':name gave you :n :type', 'params' => ['name' => $by->name, 'n' => $moved, 'type' => $moved === 1 ? $type : mb_strtolower($t['name'])]],
                ['key' => ':label', 'params' => ['label' => $moved === 1 && $first ? $first['label'] : ($note ?: $t['name'])]], $url, $by->id);
        }

        return [$moved, $skipped];
    }
}
