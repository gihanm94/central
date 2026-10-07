<?php
declare(strict_types=1);

namespace App\Modules\Machines\Support;

use App\Core\Support\DB;
use App\Core\Support\Notifier;

/**
 * The Machine Checklist background jobs (what the old Spring schedulers did). Each job knows when it is due and remembers
 * when it last ran (job_state), so it does not matter whether `php bin/machines-cron.php` runs every few minutes or the
 * website's once-a-minute check does it: a job that was missed (server off) runs as soon as possible, and never twice for the same period.
 *
 *   every day 00:00      items that came due start again (a "Tuesday" question is unticked on Tuesday)
 *   every Monday 00:05   weekly re-checks nobody approved become *-OVERDUE; weekly machines are PENDING again
 *   the 1st 00:10        the same for monthly machines
 *   every Friday 15:00   a weekly machine whose responsible person did not check gets an automatic "no action taken" record
 *   last day 23:55       the same for monthly machines
 *   weekdays 09:00       maintenance and calibration due within 30 days: responsible person and supervisor are reminded (managers: Mon and Wed 09:15)
 *   25 December          next year's maintenance and calibration rounds are made from this year's
 */
final class Jobs
{
    private const C = 'machines';

    /** @return string[] names of the jobs that ran */
    public static function tick(bool $force = false, ?\DateTimeImmutable $now = null): array
    {
        $stamp = BASE_PATH.'/storage/cache/machines-jobs.stamp';
        if (! $force && ! $now && is_file($stamp) && time() - (int) @filemtime($stamp) < 55) {
            return [];
        }
        @touch($stamp);
        $now ??= new \DateTimeImmutable();
        $lock = fopen(BASE_PATH.'/storage/cache/machines-jobs.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return [];                                        // another request is already doing it
        }
        $ran = [];
        try {
            $day   = $now->setTime(0, 0);
            $dow   = (int) $now->format('N');
            $dom   = (int) $now->format('j');
            $month = (int) $now->format('n');
            $weekend = $dow >= 6;

            $jobs = [
                'items_reset'  => [$day, fn () => self::resetItems($now)],
                'weekly_close' => [$day->modify('monday this week')->setTime(0, 5), fn () => self::closePeriod('WEEKLY')],
                'monthly_close' => [$day->modify('first day of this month')->setTime(0, 10), fn () => self::closePeriod('MONTHLY')],
                'auto_weekly'  => [$day->modify('friday this week')->setTime(15, 0), fn () => self::autoRecord('WEEKLY', $now->modify('monday this week')->setTime(0, 0), $now->modify('friday this week')->setTime(23, 59, 59))],
                'auto_monthly' => [$day->modify('last day of this month')->setTime(23, 55), fn () => self::autoRecord('MONTHLY', $now->modify('first day of this month')->setTime(0, 0), $now->modify('last day of this month')->setTime(23, 59, 59))],
                'remind_team'  => [$day->setTime(9, 0), fn () => $weekend ? 0 : self::remind(false)],
                'remind_managers' => [$day->setTime(9, 15), fn () => in_array($dow, [1, 3], true) ? self::remind(true) : 0],
                'next_year'    => [$now->setDate((int) $now->format('Y'), 12, 25)->setTime(1, 0), fn () => self::nextYear((int) $now->format('Y'))],
            ];
            if (! DB::scalar('SELECT 1 FROM job_state LIMIT 1', [], self::C)) {
                // first run ever: start counting from now, do not "catch up" periods that passed before the module was used
                foreach ($jobs as $name => $_) {
                    self::mark($name, $now);
                }

                return [];
            }
            foreach ($jobs as $name => [$due, $run]) {
                if ($now < $due || self::last($name) >= $due->getTimestamp()) {
                    continue;
                }
                if ($name === 'next_year' && ($month !== 12 || $dom < 25)) {
                    continue;
                }
                self::mark($name, $now);                      // claim first: a crash must not make it run every minute
                try {
                    $run();
                    $ran[] = $name;
                } catch (\Throwable $e) {
                    error_log('[machines-jobs] '.$name.': '.$e->getMessage());
                }
            }
        } finally {
            flock($lock, LOCK_UN);
        }

        return $ran;
    }

    private static function last(string $name): int
    {
        return (int) strtotime((string) DB::scalar('SELECT value FROM job_state WHERE key = ?', [$name], self::C));
    }

    private static function mark(string $name, \DateTimeInterface $at): void
    {
        DB::exec('INSERT INTO job_state (key, value, updated_at) VALUES (?, ?, now()) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()', [$name, $at->format('c')], self::C);
    }

    /** Questions that came due today start again. */
    public static function resetItems(\DateTimeInterface $now): int
    {
        $n = 0;
        foreach (DB::select('SELECT id, reset_time FROM machine_checklist WHERE check_status AND reset_time <> ?', [Mx::GENERAL], self::C) as $i) {
            if (Mx::resetsOn($i['reset_time'], $now)) {
                DB::exec('UPDATE machine_checklist SET check_status = FALSE, updated_at = now() WHERE id = ?', [$i['id']], self::C);
                $n++;
            }
        }

        return $n;
    }

    /** A new week/month: what nobody approved is overdue, and the machines wait for their responsible person again. */
    public static function closePeriod(string $period): int
    {
        DB::exec("UPDATE checklist_record c SET checklist_status = c.checklist_status || '-OVERDUE', updated_at = now()
                  WHERE c.recheck AND c.checklist_status IN ('PENDING SUPERVISOR', 'PENDING MANAGER')
                    AND c.machine_code IN (SELECT machine_code FROM machine WHERE reset_period = ? AND deleted_at IS NULL)", [$period], self::C);

        return DB::exec('UPDATE machine SET check_status = \'PENDING\', updated_at = now() WHERE reset_period = ? AND deleted_at IS NULL AND machine_status IN ('.Mx::activeIn().')', [$period], self::C);
    }

    /** The responsible person did not do the re-check in time: record "no action taken" so the approvers see it. */
    public static function autoRecord(string $period, \DateTimeInterface $from, \DateTimeInterface $to): int
    {
        $n = 0;
        $machines = DB::select("SELECT * FROM machine WHERE deleted_at IS NULL AND reset_period = ? AND check_status = 'PENDING' AND responsible_person_id IS NOT NULL AND machine_status IN (".Mx::activeIn().')', [$period], self::C);
        foreach ($machines as $m) {
            if (DB::scalar("SELECT 1 FROM checklist_record WHERE machine_code = ? AND created_by = ? AND recheck AND check_type = 'GENERAL' AND created_at BETWEEN ? AND ?", [$m['machine_code'], $m['responsible_person_id'], $from->format('c'), $to->format('c')], self::C)) {
                continue;
            }
            $who    = DB::first('SELECT name, email FROM users WHERE id = ?', [$m['responsible_person_id']]);
            $status = $m['supervisor_id'] ? 'PENDING SUPERVISOR' : 'PENDING MANAGER';
            DB::transaction(function () use ($m, $who, $status) {
                DB::insert('checklist_record', [
                    'check_type' => 'GENERAL', 'recheck' => true, 'machine_code' => $m['machine_code'], 'machine_name' => $m['machine_name'], 'machine_status' => $m['machine_status'],
                    'machine_checklist' => '[]', 'machine_note' => 'Automatic recording', 'user_id' => $who['email'] ?? null, 'user_name' => $who['name'] ?? $m['responsible_person_name'],
                    'supervisor' => $m['supervisor_id'], 'manager' => $m['manager_id'], 'checklist_status' => $status, 'reason_not_checked' => 'NO ACTION TAKEN',
                    'created_by' => $m['responsible_person_id'], 'updated_by' => $m['responsible_person_id'],
                ], self::C);
                DB::exec('UPDATE machine SET check_status = ?, updated_at = now() WHERE id = ?', [$status, $m['id']], self::C);
            }, self::C);
            $to = (int) ($m['supervisor_id'] ?: $m['manager_id']);
            if ($to) {
                Notifier::deliver('machines', 'reminder', $to, ['key' => ':code was not checked in time', 'params' => ['code' => $m['machine_code']]],
                    ['key' => ':who did not do the :period re-check of :code · :name. An automatic record waits for your approval.', 'params' => ['who' => $who['name'] ?? '—', 'period' => strtolower($period), 'code' => $m['machine_code'], 'name' => $m['machine_name']]], url('/machines/checklists?view=approve'));
            }
            $n++;
        }

        return $n;
    }

    /** Maintenance and calibration due within 30 days (or already late), one message per person. */
    public static function remind(bool $managers): int
    {
        $limit = date('Y-m-d', strtotime('+30 days'));
        $per = [];
        $sources = [
            'maintenance' => "SELECT r.machine_code, r.machine_name, r.due_date, m.responsible_person_id, m.supervisor_id, m.manager_id FROM maintenance_record r JOIN machine m ON m.machine_code = r.machine_code
                              WHERE NOT r.is_canceled AND r.actual_date IS NULL AND r.start_date IS NULL AND r.due_date <= ? AND m.deleted_at IS NULL AND m.machine_status IN (".Mx::activeIn().') ORDER BY r.due_date',
            'calibration' => "SELECT r.machine_code, r.machine_name, r.due_date, m.responsible_person_id, m.supervisor_id, m.manager_id FROM calibration_record r JOIN machine m ON m.machine_code = r.machine_code
                              WHERE NOT r.is_canceled AND r.certificate_date IS NULL AND r.start_date IS NULL AND r.due_date <= ? AND m.deleted_at IS NULL AND m.machine_status IN (".Mx::activeIn().') ORDER BY r.due_date',
        ];
        foreach ($sources as $kind => $sql) {
            foreach (DB::select($sql, [$limit], self::C) as $r) {
                $people = $managers ? [$r['manager_id']] : [$r['responsible_person_id'], $r['supervisor_id']];
                foreach (array_unique(array_filter(array_map('intval', $people))) as $uid) {
                    $per[$uid][$kind][] = $r;
                }
            }
        }
        $sent = 0;
        foreach ($per as $uid => $kinds) {
            foreach ($kinds as $kind => $rows) {
                $list = implode('; ', array_map(fn ($r) => $r['machine_code'].' ('.($r['due_date'] < date('Y-m-d') ? __('overdue') : format_date($r['due_date'], 'd M')).')', array_slice($rows, 0, 8))).(count($rows) > 8 ? ' …' : '');
                Notifier::deliver('machines', 'reminder', (int) $uid,
                    ['key' => $kind === 'maintenance' ? ':n machines need maintenance soon' : ':n machines need calibration soon', 'params' => ['n' => (string) count($rows)]],
                    ['key' => ':list', 'params' => ['list' => $list]], url('/machines/'.$kind));
                $sent++;
            }
        }

        return $sent;
    }

    /** Dec 25: copy this year's rounds to the next year (once; skipped when next year already has rounds). */
    public static function nextYear(int $year): int
    {
        $next = $year + 1;
        $n = 0;
        if (! DB::scalar('SELECT 1 FROM maintenance_record WHERE years = ? LIMIT 1', [(string) $next], self::C)) {
            foreach (DB::select("SELECT r.* FROM maintenance_record r JOIN machine m ON m.machine_code = r.machine_code WHERE r.years = ? AND NOT r.is_canceled AND m.deleted_at IS NULL AND m.machine_status IN (".Mx::activeIn().')', [(string) $year], self::C) as $r) {
                DB::insert('maintenance_record', ['machine_code' => $r['machine_code'], 'machine_name' => $r['machine_name'], 'years' => (string) $next, 'round' => $r['round'], 'note' => $r['note'], 'maintenance_type' => $r['maintenance_type'],
                    'due_date' => $r['due_date'] ? self::shift($r['due_date'], $next) : null, 'plan_date' => $r['plan_date'] ? self::shift($r['plan_date'], $next) : null], self::C);
                $n++;
            }
        }
        if (! DB::scalar('SELECT 1 FROM calibration_record WHERE years = ? LIMIT 1', [(string) $next], self::C)) {
            foreach (DB::select("SELECT r.* FROM calibration_record r JOIN machine m ON m.machine_code = r.machine_code WHERE r.years = ? AND NOT r.is_canceled AND m.deleted_at IS NULL AND m.machine_status IN (".Mx::activeIn().')', [(string) $year], self::C) as $r) {
                DB::insert('calibration_record', ['machine_code' => $r['machine_code'], 'machine_name' => $r['machine_name'], 'years' => (string) $next, 'due_date' => $r['due_date'] ? self::shift($r['due_date'], $next) : null,
                    'criteria' => $r['criteria'], 'measuring_range' => $r['measuring_range'], 'accuracy' => $r['accuracy'], 'calibration_range' => $r['calibration_range'], 'permissible_capacity' => $r['permissible_capacity'],
                    'resolution' => $r['resolution'], 'max_uncertainty' => $r['max_uncertainty'], 'mpe' => $r['mpe'], 'note' => $r['note']], self::C);
                $n++;
            }
        }

        return $n;
    }

    /** Same month and day in another year (29 Feb → 28 Feb). */
    private static function shift(string $date, int $year): string
    {
        [$m, $d] = [(int) substr($date, 5, 2), (int) substr($date, 8, 2)];

        return sprintf('%04d-%02d-%02d', $year, $m, min($d, (int) date('t', mktime(0, 0, 0, $m, 1, $year))));
    }
}
