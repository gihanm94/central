<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Erp;

use App\Core\Support\DB;
use App\Modules\Accounting\Support\Log;

/**
 * The scheduler: bin/erp-sync.php tick (cron, every minute) decides what is due.
 *   hot   newest rows of the busy entities, every few minutes   (like the old SyncScheduler.runHot)
 *   cold  reference data, every half hour                       (runCold)
 * Only inside the working window (Mon–Fri 07:00–20:00 Bangkok by default). A group never runs twice at once.
 */
final class Schedule
{
    public static function inWindow(?int $now = null): bool
    {
        $tz   = new \DateTimeZone(preg_match('#^[A-Za-z0-9_+\-/]+$#', ErpSettings::schedule('tz')) ? ErpSettings::schedule('tz') : 'Asia/Bangkok');
        $d    = (new \DateTimeImmutable('@'.($now ?? time())))->setTimezone($tz);
        $days = array_map('intval', array_filter(explode(',', ErpSettings::schedule('days')), 'strlen'));

        return in_array((int) $d->format('N'), $days, true)
            && (int) $d->format('G') >= (int) ErpSettings::schedule('hour_start')
            && (int) $d->format('G') < (int) ErpSettings::schedule('hour_end');
    }

    /* ---- who is running the scheduler: cron (bin/erp-sync.php tick) or, when nobody set up cron, the website itself (kick) ---- */

    private static function beatFile(): string { return BASE_PATH.'/storage/cache/erp-heartbeat'; }

    /** @return array{at: int, by: string}|null the last time the scheduler looked */
    public static function heartbeat(): ?array
    {
        $raw = @file_get_contents(self::beatFile());
        if ($raw === false || ! str_contains($raw, '|')) { return null; }
        [$t, $by] = explode('|', trim($raw), 2);

        return ['at' => (int) $t, 'by' => $by];
    }

    private static function beat(string $by): void { @file_put_contents(self::beatFile(), time().'|'.$by, LOCK_EX); }

    public static function php(): string
    {
        foreach ([PHP_BINDIR.'/php', '/usr/bin/php', '/usr/local/bin/php'] as $p) { if (is_executable($p)) { return $p; } }

        return 'php';
    }

    /** Run bin/erp-sync.php in the background. */
    public static function spawn(array $args): bool
    {
        if (! function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) { return false; }
        @exec(escapeshellarg(self::php()).' '.escapeshellarg(BASE_PATH.'/bin/erp-sync.php').' '.implode(' ', array_map('escapeshellarg', $args)).' > /dev/null 2>&1 &');

        return true;
    }

    /**
     * Without cron nothing would ever run the schedule. Every accounting page (and the live ERP page) calls this: when the scheduler has not
     * looked for ~a minute, it starts a background tick. With cron running the file is always fresh, so this does nothing.
     */
    public static function kick(): void
    {
        $b = self::heartbeat();
        if ($b && time() - $b['at'] < 55) { return; }
        $erp = ErpSettings::schedule('enabled') === '1' && ErpSettings::configured() && self::inWindow();
        if (! $erp && ! \App\Modules\Accounting\Billing\BillingNotify::enabled()) { return; }
        self::beat('website');                                   // claim it first so parallel page loads do not all start one
        self::spawn(['tick', 'website']);
    }

    /** Called every minute. @return string[] groups that ran */
    public static function tick(string $by = 'cron'): array
    {
        self::beat($by);
        \App\Modules\Accounting\Billing\BillingNotify::tick();                    // the day's billing list on Lark (independent of the ERP working hours)
        if (ErpSettings::schedule('enabled') !== '1' || ! ErpSettings::configured() || ! self::inWindow()) {
            return [];
        }
        $ran = [];
        foreach (['hot' => (int) ErpSettings::schedule('hot_minutes'), 'cold' => (int) ErpSettings::schedule('cold_minutes')] as $group => $minutes) {
            $last = DB::scalar("SELECT max(finished_at) FROM erp_sync_runs WHERE kind = ? AND finished_at IS NOT NULL", [$group], ErpSettings::CONN);
            if ($last && time() - strtotime((string) $last) < max(1, $minutes) * 60) { continue; }
            if (self::runGroup($group, 'schedule') !== null) { $ran[] = $group; }
        }

        return $ran;
    }

    /** Run every entity of a group (hot | cold | full | all) one after the other. Returns the run id, or null when it is already running. */
    public static function runGroup(string $group, string $trigger = 'manual', ?int $by = null, ?string $forceMode = null): ?int
    {
        $lock = fopen(BASE_PATH.'/storage/cache/erp-'.$group.'.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            Log::warn('sync', "group {$group}: already running, skipped", ['trigger' => $trigger]);

            return null;
        }
        $keys = array_keys(array_filter(Syncer::entities(), fn ($d) => $group === 'all' || $d['group'] === $group));
        $mode = $forceMode ?? (in_array($group, ['hot', 'cold'], true) ? 'latest' : 'all');
        $fetched = $saved = 0;
        $id   = (int) DB::insert('erp_sync_runs', ['kind' => $group, 'source' => $trigger, 'started_by' => $by], ErpSettings::CONN);
        Log::info('sync', "group {$group}: run #{$id} start", ['trigger' => $trigger, 'mode' => $mode, 'entities' => count($keys), 'by' => $by]);
        $failed = [];
        $syncer = new Syncer();
        foreach ($keys as $k) {
            try { $r = $syncer->run($k, $mode); $fetched += $r['fetched']; $saved += $r['saved']; } catch (\Throwable $e) { $failed[] = $k.': '.$e->getMessage(); }
            if ($failed && str_contains((string) end($failed), 'Login failed')) { break; }        // no point asking the rest
        }
        DB::exec('UPDATE erp_sync_runs SET finished_at = now(), status = ?, message = ?, fetched = ?, saved = ? WHERE id = ?',
            [! $failed ? 'ok' : (count($failed) < count($keys) ? 'warning' : 'failed'), $failed ? mb_substr(implode(' | ', $failed), 0, 1500) : count($keys).' entities, '.$mode, $fetched, $saved, $id], ErpSettings::CONN);
        ($failed ? [Log::class, 'error'] : [Log::class, 'info'])('sync', "group {$group}: run #{$id} ".($failed ? 'FAILED' : 'finished'), ['fetched' => $fetched, 'saved' => $saved, 'failed' => $failed]);
        flock($lock, LOCK_UN);

        return $id;
    }

    /** Run one entity in its own run record. */
    public static function runEntity(string $key, string $mode = 'latest', ?string $one = null, ?int $by = null): int
    {
        $id = (int) DB::insert('erp_sync_runs', ['kind' => 'entity:'.$key, 'source' => 'manual', 'started_by' => $by], ErpSettings::CONN);
        try {
            $r = (new Syncer())->run($key, $mode, $one);
            DB::exec('UPDATE erp_sync_runs SET finished_at = now(), status = ?, message = ?, fetched = ?, saved = ? WHERE id = ?', ['ok', $mode, $r['fetched'], $r['saved'], $id], ErpSettings::CONN);
        } catch (\Throwable $e) {
            DB::exec('UPDATE erp_sync_runs SET finished_at = now(), status = ?, message = ? WHERE id = ?', ['failed', mb_substr($e->getMessage(), 0, 1500), $id], ErpSettings::CONN);
        }

        return $id;
    }
}
