<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Support;

/**
 * Accounting log — plain text files, never the database.
 * One file per day: storage/logs/accounting/YYYY-MM-DD.log, one JSON object per line:
 *   {"t":"2026-10-06 10:00:01.123","level":"info","ch":"erp","run":"abc","msg":"…","ctx":{…}}
 * Channels: erp (API calls), sync (sync runs / rows), inet (generate, send, fetch), pdf, app (uncaught errors).
 * The viewer reads a file from a byte offset, so "live" is just asking again for what is new.
 */
final class Log
{
    public const LEVELS = ['debug' => 0, 'info' => 1, 'warn' => 2, 'error' => 3];
    public const KEEP_DAYS = 30;
    private const SECRET = '/pass|secret|token|authorization|session|cookie|key/i';

    /** a "run" groups the lines of one action (one Generate click) so the dialog can follow just its own lines */
    private static ?string $run = null;

    public static function run(?string $id): void { self::$run = $id !== null && preg_match('/^[A-Za-z0-9]{6,32}$/', $id) ? $id : null; }

    public static function dir(): string { return BASE_PATH.'/storage/logs/accounting'; }

    public static function file(?string $day = null): string
    {
        $day = $day !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? $day : date('Y-m-d');

        return self::dir().'/'.$day.'.log';
    }

    public static function debug(string $ch, string $msg, array $ctx = []): void { self::write('debug', $ch, $msg, $ctx); }
    public static function info(string $ch, string $msg, array $ctx = []): void  { self::write('info', $ch, $msg, $ctx); }
    public static function warn(string $ch, string $msg, array $ctx = []): void  { self::write('warn', $ch, $msg, $ctx); }
    public static function error(string $ch, string $msg, array $ctx = []): void { self::write('error', $ch, $msg, $ctx); }

    public static function exception(string $ch, \Throwable $e, string $what = '', array $ctx = []): void
    {
        self::write('error', $ch, ($what !== '' ? $what.': ' : '').$e->getMessage(), $ctx + [
            'exception' => get_class($e), 'at' => str_replace(BASE_PATH.'/', '', $e->getFile()).':'.$e->getLine(),
            'trace' => array_slice(array_map(fn ($l) => str_replace(BASE_PATH.'/', '', $l), explode("\n", $e->getTraceAsString())), 0, 8),
        ]);
    }

    public static function write(string $level, string $ch, string $msg, array $ctx = []): void
    {
        try {
            $dir = self::dir();
            if (! is_dir($dir)) { @mkdir($dir, 0775, true); }
            $mt = microtime(true);
            $line = json_encode(array_filter([
                't' => date('Y-m-d H:i:s', (int) $mt).sprintf('.%03d', (int) (($mt - floor($mt)) * 1000)), 'level' => $level, 'ch' => $ch, 'run' => self::$run,
                'msg' => mb_substr($msg, 0, 2000), 'ctx' => $ctx ? self::clean($ctx) : null,
            ], fn ($v) => $v !== null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $new = ! is_file(self::file());
            @file_put_contents(self::file(), $line."\n", FILE_APPEND | LOCK_EX);
            if ($new) { self::prune(); }
        } catch (\Throwable) { /* logging must never break the work */ }
    }

    private static function clean(array $ctx): array
    {
        foreach ($ctx as $k => $v) {
            if (is_string($k) && preg_match(self::SECRET, $k)) { $ctx[$k] = '••••'; }
            elseif (is_array($v)) { $ctx[$k] = self::clean($v); }
            elseif (is_string($v) && mb_strlen($v) > 1500) { $ctx[$k] = mb_substr($v, 0, 1500).'… ('.mb_strlen($v).' chars)'; }
        }

        return $ctx;
    }

    private static function prune(): void
    {
        foreach (glob(self::dir().'/*.log') ?: [] as $f) {
            if (filemtime($f) < time() - self::KEEP_DAYS * 86400) { @unlink($f); }
        }
    }

    /** Days that have a file, newest first. @return array<string, int> day => bytes */
    public static function days(): array
    {
        $out = [];
        foreach (glob(self::dir().'/*.log') ?: [] as $f) { $out[basename($f, '.log')] = (int) filesize($f); }
        krsort($out);

        return $out;
    }

    /**
     * Lines after a byte offset. $after < 0 = the last $tail lines. Filters: min level, channel, run, text.
     * @return array{entries: array, offset: int, size: int}
     */
    public static function read(string $day, int $after = -1, int $tail = 300, array $f = []): array
    {
        $file = self::file($day);
        if (! is_file($file)) { return ['entries' => [], 'offset' => 0, 'size' => 0]; }
        $size = (int) filesize($file);
        $h = fopen($file, 'rb');
        if ($after < 0) {                                     // last lines: read backwards in blocks until enough
            $want = max(1, $tail) * ($f ? 20 : 1);
            $pos = $size; $buf = '';
            while ($pos > 0 && substr_count($buf, "\n") <= $want && strlen($buf) < 8_000_000) {
                $step = min(65536, $pos); $pos -= $step; fseek($h, $pos); $buf = fread($h, $step).$buf;
            }
            $start = $pos;
            if ($pos > 0 && ($nl = strpos($buf, "\n")) !== false) { $buf = substr($buf, $nl + 1); $start = $pos + $nl + 1; }
        } else {
            $start = min($after, $size);
            fseek($h, $start);
            $buf = $size > $start ? (string) fread($h, min($size - $start, 4_000_000)) : '';
        }
        fclose($h);
        $end = strrpos($buf, "\n");                           // never return half a line
        if ($end === false) { return ['entries' => [], 'offset' => $after < 0 ? $size : $start, 'size' => $size]; }
        $offset = $start + $end + 1;
        $min = self::LEVELS[$f['level'] ?? 'debug'] ?? 0;
        $q = mb_strtolower(trim((string) ($f['q'] ?? '')));
        $entries = [];
        foreach (explode("\n", substr($buf, 0, $end)) as $line) {
            $e = json_decode($line, true);
            if (! is_array($e)) { continue; }
            if ((self::LEVELS[$e['level'] ?? 'info'] ?? 1) < $min) { continue; }
            if (! empty($f['ch']) && ! in_array($e['ch'] ?? '', explode(',', (string) $f['ch']), true)) { continue; }
            if (! empty($f['run']) && ($e['run'] ?? '') !== $f['run']) { continue; }
            if ($q !== '' && ! str_contains(mb_strtolower($line), $q)) { continue; }
            $entries[] = $e;
        }
        if ($after < 0) { $entries = array_slice($entries, -$tail); }

        return ['entries' => $entries, 'offset' => $offset, 'size' => $size];
    }
}
