<?php
declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Auth\CurrentUser;

/**
 * Monthly KPI / OKR results (User Management → KPI submissions).
 *  - The KPIs and OKRs come from User Management → KPIs & OKRs (kpis); each can be submitted once per month.
 *  - Month M is due on the 7th of month M+1: on time 100 %, then 20 % less per day late, 0 % from 5 days.
 *    The approver should approve by the 10th; a later approval is flagged.
 *  - The submitter cannot change a submission. The BU manager of the department (or an administrator) can correct or
 *    delete it; deleting frees the KPI again. Every change is in the activity log and on the KPI's page.
 */
final class KpiSubmissions
{
    public const DUE_DAY = 7;
    public const APPROVE_DAY = 10;
    public const MAX_FILES = 5;
    public const MAX_FILE_MB = 10;
    private const EXT = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt', 'zip'];

    /** Last day to submit month $m of year $y (the 7th of the next month). */
    public static function dueDate(int $y, int $m): \DateTimeImmutable { return self::nextMonthDay($y, $m, self::DUE_DAY); }

    /** The approver should approve by the 10th of the next month. */
    public static function approveBy(int $y, int $m): \DateTimeImmutable { return self::nextMonthDay($y, $m, self::APPROVE_DAY); }

    private static function nextMonthDay(int $y, int $m, int $day): \DateTimeImmutable
    {
        [$y, $m] = $m === 12 ? [$y + 1, 1] : [$y, $m + 1];

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $day));
    }

    /** @return array{days_late:int, percentage:int} for a submission made on $on */
    public static function timeliness(int $y, int $m, \DateTimeImmutable $on): array
    {
        $late = max(0, (int) self::dueDate($y, $m)->diff($on->setTime(0, 0))->format('%r%a'));

        return ['days_late' => $late, 'percentage' => max(0, 100 - 20 * $late)];
    }

    /** Months people may submit now: this month and the eleven before it, newest first. @return list<array{0:int,1:int}> */
    public static function periods(): array
    {
        $out = [];
        $d = new \DateTimeImmutable('first day of this month');
        for ($i = 0; $i < 12; $i++) {
            $out[] = [(int) $d->format('Y'), (int) $d->format('n')];
            $d = $d->modify('-1 month');
        }

        return $out;
    }

    /**
     * The person's active KPIs & OKRs whose period covers this month and that are not yet submitted for it.
     * @return array<int, array{title:string,kind:string,criteria:?string}>
     */
    public static function available(CurrentUser $u, int $y, int $m): array
    {
        $first = sprintf('%04d-%02d-01', $y, $m);
        $rows = DB::select("SELECT k.id, k.title, k.kind, k.criteria FROM kpis k
            WHERE k.deleted_at IS NULL AND k.status = 'active' AND k.user_id = ?
              AND k.period_start <= (?::date + interval '1 month - 1 day') AND k.period_end >= ?::date
              AND NOT EXISTS (SELECT 1 FROM kpi_submissions s WHERE s.kpi_id = k.id AND s.period_year = ? AND s.period_month = ? AND s.deleted_at IS NULL)
            ORDER BY k.kind, k.title", [$u->id, $first, $first, $y, $m]);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = ['title' => $r['title'], 'kind' => $r['kind'], 'criteria' => $r['criteria']];
        }

        return $out;
    }

    /** People who can approve, with photo: active managers and above (not the submitter themselves). @return array<int,string> */
    public static function approvers(CurrentUser|int $u): array
    {
        $rows = DB::select('SELECT u.id, u.name, u.avatar, r.name AS role_name, d.name AS department_name FROM users u JOIN roles r ON r.id = u.role_id
            LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.deleted_at IS NULL AND u.is_active AND u.id <> ? AND r.level >= 40 ORDER BY u.name', [is_int($u) ? $u : $u->id]);

        return self::people($rows);
    }

    /** Dropdown options with photo: [id => [label, sub, img]]. */
    public static function people(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = ['label' => $r['name'], 'sub' => trim(($r['role_name'] ?? '').(! empty($r['department_name']) ? ' · '.$r['department_name'] : ''), ' ·'),
                'img' => ! empty($r['avatar']) ? upload_url($r['avatar']) : null];
        }

        return $out;
    }

    /**
     * Who sees KPIs & OKRs and their submissions: administrators and roles that see everything (Management) see all;
     * a department role (BU manager) sees its department and its own; everyone else only their own records.
     */
    public static function scopeSql(CurrentUser $u, string $alias, string $ownerCol = 'user_id'): array
    {
        $scope = $u->isAdmin() ? 'all' : $u->scope();
        if ($scope === 'all') {
            return ['sql' => 'TRUE', 'params' => []];
        }
        if ($scope === 'department' && $u->department_id) {
            return ['sql' => "({$alias}.department_id = ? OR {$alias}.{$ownerCol} = ?)", 'params' => [$u->department_id, $u->id]];
        }

        return ['sql' => "{$alias}.{$ownerCol} = ?", 'params' => [$u->id]];
    }

    /** Submissions: the scope above, plus the ones this person has to approve. */
    public static function visibleSql(CurrentUser $u, string $alias = 's'): array
    {
        $scope = self::scopeSql($u, $alias);

        return ['sql' => "({$scope['sql']} OR {$alias}.approver_id = ?)", 'params' => [...$scope['params'], $u->id]];
    }

    /** Adding, changing and deleting KPIs & OKRs and submissions: administrators, and the BU manager of that department. */
    public static function canManage(CurrentUser $u, mixed $departmentId): bool
    {
        if ($u->isAdmin()) {
            return true;
        }

        return $u->scope() === 'department' && $u->department_id && (int) $departmentId === $u->department_id;
    }

    /** May this person add KPIs & OKRs at all (administrator or a department role)? */
    public static function canAdd(CurrentUser $u): bool { return $u->isAdmin() || ($u->scope() === 'department' && $u->department_id); }

    public static function find(CurrentUser $u, int $id): ?array
    {
        $v = self::visibleSql($u);

        return DB::first("SELECT s.* FROM kpi_submissions s WHERE s.id = ? AND s.deleted_at IS NULL AND {$v['sql']}", [$id, ...$v['params']]);
    }

    public static function canApprove(CurrentUser $u, array $row): bool
    {
        return (int) $row['approver_id'] === $u->id || $u->isAdmin();
    }

    /** Correcting or deleting a submission: only the BU manager of its department (or an administrator). */
    public static function canEdit(CurrentUser $u, array $row): bool { return self::canManage($u, $row['department_id']); }

    public static function canDelete(CurrentUser $u, array $row): bool { return self::canManage($u, $row['department_id']); }

    // ---- attachments: storage/kpi/<year>/<random>.<ext>, never under public/

    /** Checks the uploaded files before anything is saved. @return list<array{tmp:string,name:string,ext:string,size:int}> */
    public static function uploads(?array $f): array
    {
        $out = [];
        foreach (is_array($f['name'] ?? null) ? array_keys($f['name']) : [] as $i) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $name = (string) $f['name'][$i];
            if (($f['error'][$i] ?? 1) !== UPLOAD_ERR_OK || ! is_uploaded_file($f['tmp_name'][$i])) {
                throw new ValidationException(['files' => __('":name" could not be uploaded. Try a smaller file.', ['name' => $name])]);
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (! in_array($ext, self::EXT, true)) {
                throw new ValidationException(['files' => __('":name": this kind of file is not allowed.', ['name' => $name])]);
            }
            if ($f['size'][$i] > self::MAX_FILE_MB * 1024 * 1024) {
                throw new ValidationException(['files' => __('":name" is larger than :n MB.', ['name' => $name, 'n' => self::MAX_FILE_MB])]);
            }
            try { $clean = Files::name($name); } catch (\RuntimeException) { $clean = 'file.'.$ext; }
            $out[] = ['tmp' => $f['tmp_name'][$i], 'name' => $clean, 'ext' => $ext, 'size' => (int) $f['size'][$i]];
        }
        if (count($out) > self::MAX_FILES) {
            throw new ValidationException(['files' => __('Attach at most :n files.', ['n' => self::MAX_FILES])]);
        }

        return $out;
    }

    /** @param list<array{tmp:string,name:string,ext:string,size:int}> $files */
    public static function store(int $submissionId, array $files): void
    {
        $dir = BASE_PATH.'/storage/kpi/'.date('Y');
        if ($files && ! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        foreach ($files as $f) {
            $rel = date('Y').'/'.bin2hex(random_bytes(16)).'.'.$f['ext'];
            if (! move_uploaded_file($f['tmp'], BASE_PATH.'/storage/kpi/'.$rel)) {
                continue;
            }
            DB::insert('kpi_submission_files', ['submission_id' => $submissionId, 'name' => mb_substr($f['name'], 0, 200), 'path' => $rel,
                'mime' => (new \finfo(FILEINFO_MIME_TYPE))->file(BASE_PATH.'/storage/kpi/'.$rel) ?: 'application/octet-stream', 'size' => $f['size']]);
        }
    }

    /** Absolute path of a stored attachment, or null if the stored name looks wrong. */
    public static function path(string $rel): ?string
    {
        if (! preg_match('#^\d{4}/[a-f0-9]{32}\.[a-z0-9]{2,5}$#', $rel)) {
            return null;
        }
        $abs = BASE_PATH.'/storage/kpi/'.$rel;

        return is_file($abs) ? $abs : null;
    }
}
