<?php
declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Auth\CurrentUser;

/**
 * Monthly KPI / OKR results (User Management → KPI submissions).
 *  - The KPIs and OKRs are tasks (tasks.kind) assigned to the person; each can be submitted once per month.
 *  - Month M is due on the 7th of month M+1: on time 100 %, then 20 % less per day late, 0 % from 5 days.
 *    The approver should approve by the 10th; a later approval is flagged.
 *  - A submission never changes. Only its approver (or an administrator) can delete it, which frees the KPI again.
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

    /** The person's KPI / OKR tasks not yet submitted for this month. @return array<int, array{title:string,kind:string,criteria:?string}> */
    public static function available(CurrentUser $u, int $y, int $m): array
    {
        $rows = DB::select("SELECT t.id, t.title, t.kind, t.criteria FROM tasks t
            WHERE t.deleted_at IS NULL AND t.kind IN ('kpi','okr') AND t.assigned_to = ?
              AND NOT EXISTS (SELECT 1 FROM kpi_submissions s WHERE s.task_id = t.id AND s.period_year = ? AND s.period_month = ? AND s.deleted_at IS NULL)
            ORDER BY t.kind, t.title", [$u->id, $y, $m]);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = ['title' => $r['title'], 'kind' => $r['kind'], 'criteria' => $r['criteria']];
        }

        return $out;
    }

    /** People who can approve: active managers and above (not the person themselves). @return array<int,string> */
    public static function approvers(CurrentUser $u): array
    {
        $rows = DB::select('SELECT u.id, u.name FROM users u JOIN roles r ON r.id = u.role_id
            WHERE u.deleted_at IS NULL AND u.is_active AND u.id <> ? AND r.level >= 40 ORDER BY u.name', [$u->id]);

        return array_column($rows, 'name', 'id');
    }

    /** Rows this person may see: their own, the ones they approve, and their role's data scope. */
    public static function visibleSql(CurrentUser $u, string $alias = 's'): array
    {
        $scope = Permission::scope($u, $alias, ['owner' => 'user_id', 'department' => 'department_id', 'team' => 'team_id']);

        return ['sql' => "({$scope['sql']} OR {$alias}.approver_id = ?)", 'params' => [...$scope['params'], $u->id]];
    }

    public static function find(CurrentUser $u, int $id): ?array
    {
        $v = self::visibleSql($u);

        return DB::first("SELECT s.* FROM kpi_submissions s WHERE s.id = ? AND s.deleted_at IS NULL AND {$v['sql']}", [$id, ...$v['params']]);
    }

    public static function canApprove(CurrentUser $u, array $row): bool
    {
        return (int) $row['approver_id'] === $u->id || $u->isAdmin();
    }

    /** Deleting is how a wrong submission is corrected: the approver or an administrator, never the submitter alone. */
    public static function canDelete(CurrentUser $u, array $row): bool
    {
        return (int) $row['approver_id'] === $u->id || $u->isAdmin();
    }

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
