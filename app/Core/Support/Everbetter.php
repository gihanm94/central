<?php
declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Auth\CurrentUser;

/**
 * Everbetter improvement projects.
 *  - People (User Management → Everbetter) run projects: title, dates, members. Owner and members see them.
 *  - To present, they pick the quarter's presentation day and say what it is: Project Initiation, Closure or Extend time.
 *    Management is told at once.
 *  - Management (Management → Everbetter) sets each quarter's day, acknowledges requests (the person sees "Accepted")
 *    and records the result after the presentation: an approved initiation approves the project, an approved closure
 *    closes it, an approved extension moves its end date.
 */
final class Everbetter
{
    public const KINDS = ['initiation' => 'Project Initiation', 'closure' => 'Project Closure', 'extension' => 'Extend time'];
    public const PROJECT = ['draft' => 'Not approved yet', 'approved' => 'Approved', 'rejected' => 'Not approved', 'closed' => 'Closed'];

    public static function kinds(): array { return array_map(fn ($l) => __($l), self::KINDS); }

    /** Management and administrators run the presentations. */
    public static function manages(CurrentUser $u): bool
    {
        return $u->isAdmin() || $u->can('management', 'view');
    }

    /* -------------------------------------------------------------- projects */

    public static function visibleSql(CurrentUser $u, string $alias = 'p'): array
    {
        if (self::manages($u)) {
            return ['sql' => "{$alias}.deleted_at IS NULL", 'params' => []];
        }

        return ['sql' => "{$alias}.deleted_at IS NULL AND ({$alias}.owner_id = ? OR EXISTS (SELECT 1 FROM everbetter_members m WHERE m.project_id = {$alias}.id AND m.user_id = ?))", 'params' => [$u->id, $u->id]];
    }

    /** Projects with their members and latest presentation. */
    public static function projects(CurrentUser $u, array $f = [], bool $mineOnly = false): array
    {
        $v = $mineOnly
            ? ['sql' => 'p.deleted_at IS NULL AND (p.owner_id = ? OR EXISTS (SELECT 1 FROM everbetter_members m WHERE m.project_id = p.id AND m.user_id = ?))', 'params' => [$u->id, $u->id]]
            : self::visibleSql($u);
        $w = [$v['sql']]; $p = $v['params'];
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $w[] = '(p.title ILIKE ? OR p.owner_name ILIKE ?)';
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 100)).'%';
            array_push($p, $like, $like);
        }
        if (! empty($f['status']) && isset(self::PROJECT[$f['status']])) { $w[] = 'p.status = ?'; $p[] = $f['status']; }
        $rows = DB::select('SELECT p.*, d.name AS department_name,
                (SELECT count(*) FROM everbetter_presentations x WHERE x.project_id = p.id AND x.result IS NOT NULL) AS presented,
                (SELECT row_to_json(l) FROM (SELECT x.id, x.kind, x.status, x.result, s.present_on FROM everbetter_presentations x JOIN everbetter_sessions s ON s.id = x.session_id
                    WHERE x.project_id = p.id AND x.status <> \'withdrawn\' ORDER BY x.requested_at DESC LIMIT 1) l) AS last_request
            FROM everbetter_projects p LEFT JOIN departments d ON d.id = p.department_id
            WHERE '.implode(' AND ', $w).' ORDER BY p.updated_at DESC, p.id DESC LIMIT 500', $p);

        return self::withMembers($rows);
    }

    public static function withMembers(array $rows): array
    {
        if (! $rows) {
            return $rows;
        }
        $ids = array_map(fn ($r) => (int) $r['id'], $rows);
        $all = DB::select('SELECT m.project_id, u.id, u.name, u.avatar FROM everbetter_members m JOIN users u ON u.id = m.user_id
                           WHERE m.project_id IN ('.implode(',', $ids).') ORDER BY u.name');
        foreach ($rows as &$r) {
            $r['members'] = array_values(array_filter($all, fn ($m) => (int) $m['project_id'] === (int) $r['id']));
            $r['last_request'] = isset($r['last_request']) ? json_decode((string) $r['last_request'], true) : null;
        }
        unset($r);

        return $rows;
    }

    public static function find(CurrentUser $u, int $id): ?array
    {
        $v = self::visibleSql($u);
        $row = DB::first("SELECT p.*, d.name AS department_name, NULL AS last_request FROM everbetter_projects p LEFT JOIN departments d ON d.id = p.department_id
                          WHERE p.id = ? AND {$v['sql']}", [$id, ...$v['params']]);

        return $row ? self::withMembers([$row])[0] : null;
    }

    public static function canEdit(CurrentUser $u, array $p): bool
    {
        return $p['status'] !== 'closed' && ((int) $p['owner_id'] === $u->id || in_array($u->id, array_map('intval', array_column($p['members'], 'id')), true));
    }

    /** @param list<int> $memberIds */
    public static function setMembers(int $projectId, array $memberIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $memberIds))));
        $valid = $ids ? array_map('intval', array_column(DB::select('SELECT id FROM users WHERE deleted_at IS NULL AND id IN ('.implode(',', $ids).')'), 'id')) : [];
        DB::exec('DELETE FROM everbetter_members WHERE project_id = ?', [$projectId]);
        foreach ($valid as $uid) {
            DB::exec('INSERT INTO everbetter_members (project_id, user_id) VALUES (?, ?) ON CONFLICT DO NOTHING', [$projectId, $uid]);
        }
    }

    /* ---------------------------------------------------- presentation days */

    public static function quarterOf(?string $date = null): array
    {
        $t = $date ? strtotime($date) : time();

        return [(int) date('Y', $t), intdiv((int) date('n', $t) - 1, 3) + 1];
    }

    /** Presentation days from today on (to choose from), oldest first. */
    public static function upcomingSessions(): array
    {
        return DB::select('SELECT * FROM everbetter_sessions WHERE present_on >= CURRENT_DATE ORDER BY present_on LIMIT 8');
    }

    public static function session(int $year, int $q): ?array
    {
        return DB::first('SELECT * FROM everbetter_sessions WHERE year = ? AND quarter = ?', [$year, $q]);
    }

    /* --------------------------------------------------------- presentations */

    public static function presentations(int $projectId): array
    {
        return DB::select('SELECT x.*, s.present_on, s.year, s.quarter, s.place FROM everbetter_presentations x JOIN everbetter_sessions s ON s.id = x.session_id
                           WHERE x.project_id = ? ORDER BY x.requested_at DESC, x.id DESC', [$projectId]);
    }

    /** A request still waiting to be presented (submitted or acknowledged, no result). */
    public static function open(int $projectId): ?array
    {
        return DB::first("SELECT x.*, s.present_on FROM everbetter_presentations x JOIN everbetter_sessions s ON s.id = x.session_id
                          WHERE x.project_id = ? AND x.status <> 'withdrawn' AND x.result IS NULL ORDER BY x.requested_at DESC LIMIT 1", [$projectId]);
    }

    /** What this project may present now. */
    public static function allowedKinds(array $p): array
    {
        return match ($p['status']) {
            'draft', 'rejected' => ['initiation'],
            'approved' => ['closure', 'extension'],
            default => [],
        };
    }

    /** Label and tone of a request for people to read. */
    public static function stateOf(array $x): array
    {
        return match (true) {
            $x['status'] === 'withdrawn' => [__('Withdrawn'), 'bg-graphite-900/6 text-steel'],
            $x['result'] === 'approved' => [__('Approved'), 'bg-emerald-50 text-emerald-800'],
            $x['result'] === 'rejected' => [__('Not approved'), 'bg-signal-50 text-signal-800'],
            $x['status'] === 'acknowledged' => [__('Accepted by Management'), 'bg-sky-50 text-sky-800'],
            default => [__('Waiting for Management'), 'bg-amber-50 text-amber-800'],
        };
    }

    public static function request(CurrentUser $u, array $p, int $sessionId, string $kind, ?string $newEnd, ?string $note): int
    {
        if (! in_array($kind, self::allowedKinds($p), true)) {
            throw new ValidationException(['kind' => $p['status'] === 'approved' ? __('An approved project can present its closure or ask to extend its time.') : __('Present the project initiation first.')]);
        }
        if (self::open((int) $p['id'])) {
            throw new ValidationException(['kind' => __('This project already has a presentation waiting. Withdraw it first to change it.')]);
        }
        $s = DB::first('SELECT * FROM everbetter_sessions WHERE id = ? AND present_on >= CURRENT_DATE', [$sessionId]);
        if (! $s) {
            throw new ValidationException(['session_id' => __('Choose a presentation day.')]);
        }
        if ($kind === 'extension') {
            if (! $newEnd || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $newEnd) || $newEnd <= (string) $p['end_date']) {
                throw new ValidationException(['new_end_date' => __('Choose a new end date after :d.', ['d' => format_date($p['end_date'])])]);
            }
        } else {
            $newEnd = null;
        }
        $id = (int) DB::insert('everbetter_presentations', ['project_id' => $p['id'], 'session_id' => $sessionId, 'kind' => $kind, 'new_end_date' => $newEnd,
            'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 4000) : null, 'requested_by' => $u->id, 'requested_name' => $u->name]);
        DB::exec('UPDATE everbetter_projects SET updated_at = now() WHERE id = ?', [$p['id']]);
        Activity::log('created', 'everbetter', (int) $p['id'], $p['title'], ['Asked to present ":label" (:kind) on :date', ['label' => $p['title'], 'kind' => self::KINDS[$kind], 'date' => format_date($s['present_on'])]], ['kind' => $kind, 'session' => $sessionId], null, notify: false);
        foreach (self::managers() as $mid) {
            if ($mid !== $u->id) {
                Notifier::deliver('core', 'created', $mid, ['key' => 'Everbetter: :name will present ":title"', 'params' => ['name' => $u->name, 'title' => $p['title']]],
                    ['key' => ':kind · :date', 'params' => ['kind' => self::KINDS[$kind], 'date' => format_date($s['present_on'], 'd M Y')]], url('/management/everbetter'), $u->id);
            }
        }

        return $id;
    }

    public static function withdraw(CurrentUser $u, array $x): void
    {
        if ($x['status'] !== 'submitted' || $x['result'] !== null) {
            throw new ValidationException(['request' => __('Only a request Management has not accepted yet can be withdrawn.')]);
        }
        DB::exec("UPDATE everbetter_presentations SET status = 'withdrawn' WHERE id = ?", [$x['id']]);
    }

    public static function acknowledge(CurrentUser $u, int $id): array
    {
        $x = self::presentation($id) ?? throw new HttpException(404);
        if ($x['status'] !== 'submitted') {
            throw new ValidationException(['request' => __('This request was already handled.')]);
        }
        DB::exec("UPDATE everbetter_presentations SET status = 'acknowledged', acknowledged_by = ?, acknowledged_name = ?, acknowledged_at = now() WHERE id = ?", [$u->id, $u->name, $id]);
        self::tell($x, ['key' => 'Everbetter: your presentation of ":title" is accepted', 'params' => ['title' => $x['title']]],
            ['key' => ':kind · :date', 'params' => ['kind' => self::KINDS[$x['kind']], 'date' => format_date($x['present_on'], 'd M Y')]], $u->id);
        Activity::log('updated', 'everbetter', (int) $x['project_id'], $x['title'], ['Accepted the presentation of ":label" (:kind)', ['label' => $x['title'], 'kind' => self::KINDS[$x['kind']]]], [], null, notify: false);

        return $x;
    }

    /** After the presentation: approved or not, and what that does to the project. */
    public static function decide(CurrentUser $u, int $id, string $result, ?string $note): array
    {
        $x = self::presentation($id) ?? throw new HttpException(404);
        if (! in_array($result, ['approved', 'rejected'], true)) {
            throw new ValidationException(['result' => __('Choose approved or not approved.')]);
        }
        if ($x['status'] === 'withdrawn' || $x['result'] !== null) {
            throw new ValidationException(['request' => __('This request was already handled.')]);
        }
        DB::exec("UPDATE everbetter_presentations SET result = ?, result_note = ?, decided_by = ?, decided_name = ?, decided_at = now(),
                  status = 'acknowledged', acknowledged_by = COALESCE(acknowledged_by, ?), acknowledged_name = COALESCE(acknowledged_name, ?), acknowledged_at = COALESCE(acknowledged_at, now())
                  WHERE id = ?", [$result, $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 4000) : null, $u->id, $u->name, $u->id, $u->name, $id]);
        $set = match (true) {
            $x['kind'] === 'initiation' && $result === 'approved' => "status = 'approved', approved_at = now()",
            $x['kind'] === 'initiation' => "status = 'rejected'",
            $x['kind'] === 'closure' && $result === 'approved' => "status = 'closed', closed_at = now()",
            $x['kind'] === 'extension' && $result === 'approved' => 'end_date = ?::date',
            default => null,
        };
        $params = $set === 'end_date = ?::date' ? [$x['new_end_date'], $x['project_id']] : [$x['project_id']];
        DB::exec('UPDATE everbetter_projects SET '.($set ? $set.', ' : '').'updated_at = now() WHERE id = ?', $params);
        self::tell($x, ['key' => $result === 'approved' ? 'Everbetter: ":title" approved (:kind)' : 'Everbetter: ":title" not approved (:kind)', 'params' => ['title' => $x['title'], 'kind' => self::KINDS[$x['kind']]]],
            ['key' => ':note', 'params' => ['note' => trim((string) $note) !== '' ? (string) $note : '—']], $u->id);
        Activity::log('updated', 'everbetter', (int) $x['project_id'], $x['title'], [$result === 'approved' ? 'Approved ":label" (:kind)' : 'Did not approve ":label" (:kind)', ['label' => $x['title'], 'kind' => self::KINDS[$x['kind']]]], ['note' => $note], null, notify: false);

        return $x;
    }

    public static function presentation(int $id): ?array
    {
        return DB::first('SELECT x.*, s.present_on, p.title, p.owner_id, p.end_date FROM everbetter_presentations x JOIN everbetter_sessions s ON s.id = x.session_id
                          JOIN everbetter_projects p ON p.id = x.project_id WHERE x.id = ? AND p.deleted_at IS NULL', [$id]);
    }

    /** Owner, members and whoever asked: one message each. */
    private static function tell(array $x, array $title, array $body, int $actor): void
    {
        $to = array_map('intval', array_column(DB::select('SELECT user_id FROM everbetter_members WHERE project_id = ?', [$x['project_id']]), 'user_id'));
        $to[] = (int) $x['owner_id'];
        $to[] = (int) $x['requested_by'];
        foreach (array_unique(array_filter($to)) as $uid) {
            if ($uid !== $actor) {
                Notifier::deliver('core', 'updated', $uid, $title, $body, url('/everbetter/'.(int) $x['project_id']), $actor);
            }
        }
    }

    /** Active people whose role opens Management, and administrators. @return list<int> */
    public static function managers(): array
    {
        return array_map('intval', array_column(DB::select("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
            WHERE u.is_active AND u.deleted_at IS NULL AND (r.slug = 'admin' OR EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions pm ON pm.id = rp.permission_id
                WHERE rp.role_id = r.id AND pm.key = 'management' AND rp.can_view))"), 'id'));
    }
}
