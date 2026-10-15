<?php
declare(strict_types=1);

namespace App\Modules\Sheets\Support;

use App\Core\Support\DB;
use App\Core\Support\Notifier;

/**
 * Runs the syncs that are due. Called by cron (bin/sheets-sync.php, every 5 minutes) and, as a fallback,
 * by the module's 'tick' hook while somebody is signed in. Nothing runs during office hours.
 */
final class Runner
{
    /** @return list<string> one line per sync that ran */
    public static function tick(int $max = 1, int $budgetSeconds = 50): array
    {
        if (Schedule::inOffice()) {
            return [];                                    // due syncs simply wait until office hours end
        }
        $start = time();
        $done = [];
        $ids = DB::select("SELECT id FROM gsheet_syncs WHERE active AND next_run_at <= now()
            AND (running_since IS NULL OR running_since < now() - interval '30 minutes') ORDER BY next_run_at LIMIT ".max(1, $max));
        foreach (array_column($ids, 'id') as $id) {
            if (time() - $start > $budgetSeconds) {
                break;
            }
            $s = self::claim((int) $id);
            if ($s) {
                $done[] = self::run($s);
            }
        }

        return $done;
    }

    /** Marks the sync as running unless another process already did. */
    private static function claim(int $id, bool $manual = false): ?array
    {
        return DB::first("UPDATE gsheet_syncs SET running_since = now() WHERE id = ?".($manual ? '' : ' AND active AND next_run_at <= now()')."
            AND (running_since IS NULL OR running_since < now() - interval '30 minutes') RETURNING *", [$id]);
    }

    /** "Sync now" from the page. @return string the result line */
    public static function now(int $id): string
    {
        if (Schedule::inOffice()) {
            throw new \RuntimeException(__('Syncing is paused during office hours (:h).', ['h' => Schedule::officeLabel()]));
        }
        $s = self::claim($id, true) ?? throw new \RuntimeException(__('This sync is already running.'));

        return self::run($s, true);
    }

    private static function run(array $s, bool $manual = false): string
    {
        @set_time_limit(300);
        $ok = false;
        $rows = null;
        $keep = true;
        try {
            if (! Catalog::mayUse((int) $s['table_id'], (int) $s['user_id'])) {
                $keep = false;
                throw new \RuntimeException(__('You may no longer sync this table. Ask an administrator.'));
            }
            $t = DB::first('SELECT * FROM gsheet_tables WHERE id = ?', [$s['table_id']]);
            $data = Catalog::read($t, Schedule::maxRows());
            GSheets::write((int) $s['account_id'], (string) $s['spreadsheet_id'], (string) $s['sheet_tab'], $data['header'], $data['rows']);
            $rows = count($data['rows']);
            $msg = $data['truncated'] ? __('Only the first :n rows were sent (limit set by the administrator).', ['n' => number_format($rows)]) : __(':n rows sent.', ['n' => number_format($rows)]);
            $ok = true;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            error_log('[sheets] sync '.$s['id'].': '.$e);
        }

        $next = $manual ? ($s['next_run_at'] ?: null) : Schedule::next($s)?->format('c');
        $active = $keep && ($manual ? filter_var($s['active'], FILTER_VALIDATE_BOOL) && $next !== null : $s['mode'] === 'daily' && $next !== null);
        DB::exec('UPDATE gsheet_syncs SET running_since = NULL, last_run_at = now(), last_status = ?, last_message = ?, last_rows = coalesce(?, last_rows),
                  next_run_at = ?, active = ?, updated_at = now() WHERE id = ?',
            [$ok ? 'ok' : 'error', mb_substr($msg, 0, 500), $rows, $active ? $next : null, $active, $s['id']]);

        if (! $ok && ! $manual) {
            try {
                Notifier::bell((int) $s['user_id'], 'sheets', 'sync_failed', __('Google Sheets sync failed: :tab', ['tab' => $s['sheet_tab']]), $msg, '/sheets');
            } catch (\Throwable) { /* the bell is a courtesy */ }
        }

        return '#'.$s['id'].' '.($ok ? 'ok' : 'error').': '.$msg;
    }
}
