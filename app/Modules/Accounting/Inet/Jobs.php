<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Inet;

use App\Modules\Accounting\Erp\Schedule;
use App\Modules\Accounting\Support\Log;

/**
 * Generate runs in its own process (bin/erp-sync.php generate <job>), never inside the web request: reading the data, building the JSON and
 * printing PDFs with Chromium can take a while, and a web worker must not be held for that. The page only asks "how far is it?" (a tiny
 * JSON file) every second. A job is one small file in storage/cache/jobs/.
 */
final class Jobs
{
    private static function dir(): string
    {
        $d = BASE_PATH.'/storage/cache/jobs';
        is_dir($d) || @mkdir($d, 0775, true);

        return $d;
    }

    private static function file(string $id): string { return self::dir().'/'.$id.'.json'; }

    public static function valid(string $id): bool { return (bool) preg_match('/^j[a-f0-9]{14}$/', $id); }

    /** @param array<int, array{invoice: int, auto_pdf: bool}> $items */
    public static function create(array $items, int $by): string
    {
        foreach (glob(self::dir().'/*.json') ?: [] as $f) { if (filemtime($f) < time() - 86400) { @unlink($f); } }
        $id = 'j'.bin2hex(random_bytes(7));
        self::write($id, ['id' => $id, 'status' => 'queued', 'by' => $by, 'items' => $items, 'total' => count($items), 'built' => 0, 'saved' => 0, 'made' => 0, 'errors' => [], 'message' => '', 'created' => time(), 'updated' => time()]);

        return $id;
    }

    public static function read(string $id): ?array
    {
        $j = self::valid($id) && is_file(self::file($id)) ? json_decode((string) @file_get_contents(self::file($id)), true) : null;
        if (is_array($j) && in_array($j['status'], ['queued', 'running'], true) && time() - (int) $j['updated'] > 150) {      // the process died
            $j['status'] = 'failed'; $j['message'] = 'The background process stopped. See Accounting → Logs.';
        }

        return is_array($j) ? $j : null;
    }

    private static function write(string $id, array $j): void
    {
        $j['updated'] = time();
        $tmp = self::file($id).'.tmp';
        @file_put_contents($tmp, json_encode($j, JSON_UNESCAPED_UNICODE));
        @rename($tmp, self::file($id));
    }

    /** Start it in the background; false = this server cannot start processes (the caller then runs it inline). */
    public static function start(string $id): bool { return Schedule::spawn(['generate', $id]); }

    /** The work (called by bin/erp-sync.php, or inline). */
    public static function run(string $id): void
    {
        $j = self::read($id);
        if (! $j || $j['status'] !== 'queued') { return; }
        @set_time_limit(0);
        $j['status'] = 'running';
        self::write($id, $j);
        Log::run($id);
        Log::info('inet', 'Generate: '.$j['total'].' invoice(s)', ['job' => $id]);
        try {
            $res = Generator::many($j['items'], (int) $j['by'], 5, function (string $stage, int $n) use (&$j, $id): void {
                $j[$stage] = $n;
                self::write($id, $j);
            });
            $made = 0; $errors = [];
            foreach ($res as $no => $r) {
                if ($r['docs']) { $made++; }
                foreach ($r['errors'] as $type => $m) { $errors[] = $no.($type === '-' ? '' : ' ('.$type.')').': '.$m; }
            }
            $j['made'] = $made; $j['errors'] = array_slice($errors, 0, 20); $j['status'] = 'done';
        } catch (\Throwable $e) {
            Log::exception('inet', $e, 'Generate failed');
            $j['status'] = 'failed'; $j['message'] = $e->getMessage();
        }
        Log::info('inet', 'Generate finished: '.($j['made'] ?? 0).' made, '.count($j['errors']).' with problems', ['job' => $id]);
        self::write($id, $j);
    }
}
