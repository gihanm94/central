<?php
declare(strict_types=1);

namespace App\Modules\Sheets\Support;

use App\Core\Support\Settings;
use App\Core\Support\ValidationException;

/**
 * When syncs may run. Two kinds: 'daily' (once a day at a time, on chosen weekdays) and 'once' (one date).
 * Office hours (default Mon–Fri 08:30–17:30, set on the module's Settings page) are never used:
 * a schedule cannot point into them, "Sync now" is refused in them, and a sync that falls due
 * during them waits until they end.
 */
final class Schedule
{
    public const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /** @return array{start:string, end:string, days:list<int>} */
    public static function office(): array
    {
        $days = array_values(array_filter(array_map('intval', explode(',', (string) Settings::get('gsheet_office_days', '1,2,3,4,5'))), fn ($d) => $d >= 1 && $d <= 7));

        return ['start' => (string) Settings::get('gsheet_office_start', '08:30'), 'end' => (string) Settings::get('gsheet_office_end', '17:30'), 'days' => $days];
    }

    public static function maxRows(): int { return max(100, (int) Settings::get('gsheet_max_rows', '100000')); }

    /** Is $hm (HH:MM) inside office hours (start inclusive, end exclusive)? */
    public static function officeTime(string $hm): bool
    {
        $o = self::office();

        return $hm >= $o['start'] && $hm < $o['end'];
    }

    public static function inOffice(?\DateTimeImmutable $at = null): bool
    {
        $at ??= new \DateTimeImmutable();

        return in_array((int) $at->format('N'), self::office()['days'], true) && self::officeTime($at->format('H:i'));
    }

    /** "Mon–Fri 08:30–17:30" */
    public static function officeLabel(): string
    {
        $o = self::office();
        $d = $o['days'];
        $days = $d === range($d[0] ?? 1, end($d) ?: 1) && count($d) > 2 ? __(self::DAYS[$d[0]]).'–'.__(self::DAYS[end($d)]) : implode(', ', array_map(fn ($x) => __(self::DAYS[$x]), $d));

        return $days.' '.$o['start'].'–'.$o['end'];
    }

    /**
     * Checks the schedule part of a form.
     * @return array{mode:string, days:string, run_time:string, run_date:?string}
     */
    public static function validate(array $in, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $errors = [];
        $mode = ($in['mode'] ?? '') === 'once' ? 'once' : 'daily';
        $time = (string) ($in['run_time'] ?? '');
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $errors['run_time'] = __('Choose a time.');
        }
        $office = self::office();
        $days = [];
        $date = null;
        if ($mode === 'daily') {
            $days = array_values(array_unique(array_filter(array_map('intval', (array) ($in['days'] ?? [])), fn ($d) => $d >= 1 && $d <= 7)));
            sort($days);
            if (! $days) {
                $errors['days'] = __('Choose at least one day.');
            } elseif (! isset($errors['run_time']) && self::officeTime($time) && array_intersect($days, $office['days'])) {
                $errors['run_time'] = __('Office hours (:h) are kept free: choose a time before :s or from :e.', ['h' => self::officeLabel(), 's' => $office['start'], 'e' => $office['end']]);
            }
        } else {
            $date = (string) ($in['run_date'] ?? '');
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$time);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! $d) {
                $errors['run_date'] = __('Choose a date.');
            } elseif (! isset($errors['run_time'])) {
                if ($d <= $now) {
                    $errors['run_date'] = __('Choose a moment in the future.');
                } elseif (self::inOffice($d)) {
                    $errors['run_time'] = __('Office hours (:h) are kept free: choose a time before :s or from :e.', ['h' => self::officeLabel(), 's' => $office['start'], 'e' => $office['end']]);
                }
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }

        return ['mode' => $mode, 'days' => implode(',', $days ?: [1, 2, 3, 4, 5]), 'run_time' => $time, 'run_date' => $mode === 'once' ? $date : null];
    }

    /** The next moment after $after at which the sync is due, or null (a 'once' sync that is past). */
    public static function next(array $s, ?\DateTimeImmutable $after = null): ?\DateTimeImmutable
    {
        $after ??= new \DateTimeImmutable();
        $time = substr((string) $s['run_time'], 0, 5);
        if ($s['mode'] === 'once') {
            $at = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $s['run_date'].' '.$time);

            return $at && $at > $after ? $at : null;
        }
        $days = array_map('intval', explode(',', (string) $s['days']));
        for ($i = 0; $i <= 7; $i++) {
            $at = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $after->modify("+{$i} day")->format('Y-m-d').' '.$time);
            if ($at > $after && in_array((int) $at->format('N'), $days, true)) {
                return $at;
            }
        }

        return null;
    }

    /** "Every weekday at 19:00" / "Mon, Wed at 06:00" / "Once on 24 Oct 2026 at 18:00" */
    public static function describe(array $s): string
    {
        $time = substr((string) $s['run_time'], 0, 5);
        if ($s['mode'] === 'once') {
            return __('Once on :d at :t', ['d' => format_date((string) $s['run_date'], 'd M Y'), 't' => $time]);
        }
        $days = array_map('intval', explode(',', (string) $s['days']));
        $label = match ($days) {
            [1, 2, 3, 4, 5, 6, 7] => __('Every day'),
            [1, 2, 3, 4, 5] => __('Every weekday'),
            [6, 7] => __('Every weekend day'),
            default => implode(', ', array_map(fn ($d) => __(self::DAYS[$d]), $days)),
        };

        return __(':days at :t', ['days' => $label, 't' => $time]);
    }
}
