<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Erp;

use App\Core\Support\DB;

/**
 * Copies one ERP entity (see config/erp.php) into its local table.
 *   latest  the newest N rows (what the hot / cold schedule uses)
 *   all     every row, 1000 at a time
 *   one     a single id
 */
final class Syncer
{
    private const PAGE = 1000;

    public function __construct(private Client $client = new Client()) {}

    public static function entities(): array { return config('erp.entities'); }

    /** @return array{fetched: int, saved: int} */
    public function run(string $key, string $mode = 'latest', ?string $id = null, ?int $size = null): array
    {
        $def = self::entities()[$key] ?? throw new \InvalidArgumentException("Unknown ERP entity {$key}");
        $start = microtime(true);
        DB::exec("INSERT INTO erp_sync_state (entity, status, mode, last_run_at, error) VALUES (?, 'running', ?, now(), NULL)
                  ON CONFLICT (entity) DO UPDATE SET status = 'running', mode = EXCLUDED.mode, last_run_at = now(), error = NULL", [$key, $mode], ErpSettings::CONN);
        $fetched = $saved = 0;
        try {
            $limit = $mode === 'latest' ? ($size ?? (int) $def['size']) : ($mode === 'one' ? 1 : null);
            $skip  = 0;
            while (true) {
                $top = $limit !== null ? min(self::PAGE, $limit - $skip) : self::PAGE;
                if ($top <= 0) { break; }
                $q = ['top' => $top, 'skip' => $skip, 'orderby' => $mode === 'latest' ? 'Id desc' : 'Id'];
                if (! empty($def['expand'])) { $q['expand'] = $def['expand']; }
                elseif (! empty($def['columns'])) { $q['select'] = $def['columns']; }      // nested columns are only safe with a plain select
                if ($mode === 'one') { $q['filter'] = "Id eq '".preg_replace('/[^0-9A-Za-z_-]/', '', (string) $id)."'"; }
                $page = $this->client->page($def['api'], $q);
                if ($page['error'] !== null) { throw new \RuntimeException($page['error']); }
                $rows = $page['rows'];
                $fetched += count($rows);
                $saved   += $this->save($def, $rows);
                $skip    += count($rows);
                if (count($rows) < $top || $mode === 'one') { break; }
            }
            DB::exec("UPDATE erp_sync_state SET status = 'ok', last_ok_at = now(), fetched = ?, saved = ?, duration_ms = ?, error = NULL WHERE entity = ?",
                [$fetched, $saved, (int) round((microtime(true) - $start) * 1000), $key], ErpSettings::CONN);
        } catch (\Throwable $e) {
            DB::exec("UPDATE erp_sync_state SET status = 'failed', fetched = ?, saved = ?, duration_ms = ?, error = ? WHERE entity = ?",
                [$fetched, $saved, (int) round((microtime(true) - $start) * 1000), mb_substr($e->getMessage(), 0, 500), $key], ErpSettings::CONN);
            throw $e;
        }

        return ['fetched' => $fetched, 'saved' => $saved];
    }

    /** Save the rows and everything found inside them (addresses of an order, rows of an order …). */
    private function save(array $def, array $raw): int
    {
        $main = [];
        $kids = [];                                       // entity => rows
        foreach ($raw as $r) {
            $row = Store::map($def['entity'], $r);
            if ($row === null) { continue; }
            $main[] = $row;
            foreach ($def['children'] ?? [] as $c) {
                $v = array_change_key_case($r, CASE_LOWER)[strtolower($c['path'])] ?? null;
                if (! is_array($v) || ! $v) { continue; }
                $list = ! empty($c['many']) ? $v : [$v];
                $stamp = [];
                foreach ($c['stamp'] ?? [] as $col => $from) { $stamp[$col] = $row[$from]; }
                foreach ($list as $item) {
                    if (is_array($item) && ($m = Store::map($c['entity'], $item, $stamp)) !== null) { $kids[$c['entity']][] = $m; }
                }
            }
        }
        // parents first, so rows that point at each other find what they need
        foreach ($kids as $entity => $rows) {
            try { Store::upsert($entity, $rows); } catch (\Throwable) { /* one child type failing must not stop the rest */ }
        }

        return Store::upsert($def['entity'], $main);
    }
}
