<?php
declare(strict_types=1);

namespace App\Modules\Machines\Support;

use App\Core\Auth\CurrentUser;
use App\Core\Support\DB;

/** Shared facts of the Machine Checklist module: statuses, who sees which machine, people and department look-ups, codes. */
final class Mx
{
    public const C = 'machines';

    /** Machine statuses. The first two are "in use": they are checked, maintained and calibrated. */
    public const STATUSES = ['OPERATIONAL' => 'Operational', 'UNDER MAINTENANCE' => 'Under maintenance', 'SCRAPPED' => 'Scrapped', 'CANCELLED' => 'Cancelled'];
    public const ACTIVE   = ['OPERATIONAL', 'UNDER MAINTENANCE'];
    public const PERIODS  = ['WEEKLY' => 'Weekly', 'MONTHLY' => 'Monthly'];
    public const MAINT_BY = ['INTERNAL' => 'Internal', 'EXTERNAL' => 'External'];
    public const MAINT_TYPES = ['PREVENTIVE' => 'Preventive', 'CORRECTIVE' => 'Corrective', 'PREDICTIVE' => 'Predictive', 'CONDITION_BASED' => 'Condition based', 'PRESCRIPTIVE' => 'Prescriptive'];

    /** The day rule of a checklist item (cron "0 0 0 <day-of-month> * <day-of-week>"): label per preset. '0 0 0 * * 1' = general check, done at every use. */
    public const GENERAL = '0 0 0 * * 1';
    public const RESETS = [
        '0 0 0 * * 1' => 'General (at every use)', '0 0 0 * * *' => 'Every day', '0 0 0 * * 2' => 'Every Tuesday', '0 0 0 * * 3' => 'Every Wednesday', '0 0 0 * * 4' => 'Every Thursday',
        '0 0 0 * * 5' => 'Every Friday', '0 0 0 * * 6' => 'Every Saturday', '0 0 0 * * 7' => 'Every Sunday', '0 0 0 1 * *' => 'Monthly (1st)', '0 0 0 15 * *' => 'Monthly (15th)',
    ];

    /** Readable (translatable) names of the status codes stored in the database. */
    public const LABELS = ['OPERATIONAL' => 'Operational', 'UNDER MAINTENANCE' => 'Under maintenance', 'SCRAPPED' => 'Scrapped', 'CANCELLED' => 'Cancelled', 'OUT OF SERVICE' => 'Out of service',
        'PENDING' => 'Pending', 'PENDING SUPERVISOR' => 'Pending supervisor', 'PENDING MANAGER' => 'Pending manager', 'COMPLETED' => 'Completed', 'COMPLETED (LATE)' => 'Completed (late)',
        'PENDING SUPERVISOR-OVERDUE' => 'Supervisor overdue', 'PENDING MANAGER-OVERDUE' => 'Manager overdue', 'ACTIVE' => 'Active', 'INACTIVE' => 'Inactive', 'PASS' => 'Pass', 'FAILED' => 'Failed'];

    public static function statusTone(?string $s): string
    {
        return match ($s) {
            'OPERATIONAL', 'COMPLETED', 'COMPLETED (LATE)', 'PASS', 'Pass' => 'ok',
            'UNDER MAINTENANCE', 'PENDING', 'PENDING SUPERVISOR', 'PENDING MANAGER' => 'warn',
            'SCRAPPED', 'CANCELLED', 'OUT OF SERVICE', 'REJECTED', 'FAILED' => 'bad',
            default => str_ends_with((string) $s, '-OVERDUE') ? 'bad' : 'neutral',
        };
    }

    /** A small coloured pill. */
    public static function pill(?string $text, ?string $tone = null): string
    {
        if ($text === null || $text === '') {
            return '<span class="text-steel">—</span>';
        }
        $tone ??= self::statusTone($text);
        $cls = ['ok' => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20', 'warn' => 'bg-amber-50 text-amber-800 ring-amber-600/20', 'bad' => 'bg-red-50 text-red-800 ring-red-600/20', 'neutral' => 'bg-graphite-900/5 text-graphite-700 ring-graphite-900/10', 'info' => 'bg-sky-50 text-sky-800 ring-sky-600/20'][$tone] ?? '';

        return '<span class="inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset '.$cls.'">'.e(isset(self::LABELS[$text]) ? __(self::LABELS[$text]) : $text).'</span>';
    }

    public static function isActive(?string $status): bool { return in_array($status, self::ACTIVE, true); }

    public static function activeIn(): string { return "'".implode("','", self::ACTIVE)."'"; }

    /* ------------------------------------------------------------------- who sees what */

    /**
     * Machines a person may see in the lists: a whole-company role sees all, a department head sees the department's,
     * everybody else sees the machines they are responsible for, supervise or manage.
     * (Scanning a machine's QR code is open to every signed-in person: anyone who uses a machine can do its general check.)
     *
     * @return array{sql: string, params: array}
     */
    public static function scope(CurrentUser $u, string $m = 'm'): array
    {
        $scope = $u->scope();
        if ($scope === 'all') {
            return ['sql' => 'TRUE', 'params' => []];
        }
        $mine = "{$m}.responsible_person_id = ? OR {$m}.supervisor_id = ? OR {$m}.manager_id = ?";
        if ($scope === 'department' && $u->department_id) {
            return ['sql' => "({$m}.department_id = ? OR {$mine})", 'params' => [$u->department_id, $u->id, $u->id, $u->id]];
        }

        return ['sql' => "({$mine})", 'params' => [$u->id, $u->id, $u->id]];
    }

    /** May this person see the machine with this code in the lists? */
    public static function canSee(CurrentUser $u, string $code): bool
    {
        $s = self::scope($u);

        return (bool) DB::scalar("SELECT 1 FROM machine m WHERE m.machine_code = ? AND m.deleted_at IS NULL AND {$s['sql']}", [$code, ...$s['params']], self::C);
    }

    /** Admin, whole-company roles and department heads can change any record they see; others only where they are responsible. */
    public static function manages(CurrentUser $u, array $machine): bool
    {
        if (in_array($u->scope(), ['all'], true)) {
            return true;
        }
        if ($u->scope() === 'department' && $u->department_id && (int) $machine['department_id'] === $u->department_id) {
            return true;
        }

        return in_array($u->id, array_map('intval', array_filter([$machine['responsible_person_id'] ?? null, $machine['supervisor_id'] ?? null, $machine['manager_id'] ?? null])), true);
    }

    /* ----------------------------------------------------------------------- people */

    /** [id => name] for the ids. */
    public static function names(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids) {
            return [];
        }

        return array_column(DB::select('SELECT id, name FROM users WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).')', $ids), 'name', 'id');
    }

    /** Active people for the pickers: [id => "Name · Department"]. */
    public static function people(): array
    {
        $out = [];
        foreach (DB::select('SELECT u.id, u.name, d.name AS department FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.deleted_at IS NULL AND u.is_active ORDER BY u.name') as $r) {
            $out[$r['id']] = $r['name'].($r['department'] ? ' · '.$r['department'] : '');
        }

        return $out;
    }

    public static function departments(): array
    {
        return array_column(DB::select('SELECT id, name FROM departments WHERE deleted_at IS NULL AND is_active ORDER BY name'), 'name', 'id');
    }

    public static function departmentCodes(): array
    {
        return array_column(DB::select('SELECT id, code FROM departments WHERE deleted_at IS NULL'), 'code', 'id');
    }

    /** People to tell about a machine: [user id, …] without duplicates. */
    public static function team(array $machine, bool $managers = true): array
    {
        $ids = [$machine['responsible_person_id'] ?? null, $machine['supervisor_id'] ?? null];
        if ($managers) {
            $ids[] = $machine['manager_id'] ?? null;
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /* ----------------------------------------------------------------------- machines */

    public static function machine(string $code): ?array
    {
        return DB::first('SELECT m.*, t.machine_group_name, t.machine_type_name FROM machine m
                          LEFT JOIN machine_type t ON t.machine_group_id = m.machine_group_id AND t.machine_type_id = m.machine_type_id AND t.deleted_at IS NULL
                          WHERE m.machine_code = ? AND m.deleted_at IS NULL', [$code], self::C);
    }

    public static function machineById(int $id): ?array
    {
        $code = DB::scalar('SELECT machine_code FROM machine WHERE id = ? AND deleted_at IS NULL', [$id], self::C);

        return $code ? self::machine((string) $code) : null;
    }

    /** QR content. Same JSON the old system printed, so existing labels keep working. */
    public static function qr(string $code): string { return json_encode(['status' => true, 'code' => $code], JSON_UNESCAPED_SLASHES); }

    /** Whatever a scanner read (JSON from our labels, or just the code) → machine code. */
    public static function codeFromScan(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        if ($text[0] === '{') {
            $j = json_decode($text, true);

            return is_array($j) && ! empty($j['code']) ? (string) $j['code'] : null;
        }
        if (preg_match('~/machines/m/([^/?#]+)~', $text, $m)) {
            return rawurldecode($m[1]);
        }

        return preg_match('/^[A-Za-z0-9._\-]{3,40}$/', $text) ? $text : null;
    }

    /** "<dept code>-<group><type>-<4 digit running number>" */
    public static function nextCode(?int $departmentId, string $group, string $type): string
    {
        $dept   = $departmentId ? (self::departmentCodes()[$departmentId] ?? '') : '';
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $dept) ?: 'MC').'-'.$group.$type;
        $max    = (int) DB::scalar("SELECT COALESCE(MAX(NULLIF(regexp_replace(substring(machine_code from '[0-9]+$'), '^0+', ''), '')::int), 0) FROM machine WHERE machine_code LIKE ?", [$prefix.'-%'], self::C);

        return $prefix.'-'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Does a cron-style day rule fall on this day? ("0 0 0 <day-of-month> * <day-of-week>", day-of-week 1 = Monday … 7 = Sunday) */
    public static function resetsOn(?string $rule, \DateTimeInterface $day): bool
    {
        $p = preg_split('/\s+/', trim((string) $rule));
        if (! $p || count($p) < 6) {
            return false;
        }
        $dom = $p[3]; $dow = $p[5];
        $dowNow = (int) $day->format('N');
        $domOk  = $dom === '*' || (int) $dom === (int) $day->format('j');
        $dowOk  = $dow === '*' || (int) $dow === $dowNow || ($dow === '0' && $dowNow === 7);

        return $domOk && $dowOk;
    }

    public static function resetLabel(?string $rule): string { return __(self::RESETS[$rule] ?? (string) $rule); }
}
