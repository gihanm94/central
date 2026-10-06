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

    /** Called every minute. @return string[] groups that ran */
    public static function tick(): array
    {
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
            [$failed ? 'failed' : 'ok', $failed ? mb_substr(implode(' | ', $failed), 0, 1500) : count($keys).' entities, '.$mode, $fetched, $saved, $id], ErpSettings::CONN);
        Log::info('sync', "group {$group}: run #{$id} ".($failed ? 'FAILED' : 'finished'), ['fetched' => $fetched, 'saved' => $saved, 'failed' => $failed]);
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
