<?php
declare(strict_types=1);

namespace App\Core\Support;

/**
 * Reads the server's log files for Administrator → System logs, so errors can be checked without logging in to the
 * server. Files: storage/logs/*.log (the PHP / application error log is php-error.log) and any extra readable files
 * named in config 'logs.extra' (e.g. the web server's error log). Only the end of a big file is read.
 */
final class SystemLogs
{
    public const LEVELS = ['error' => 'Errors', 'warning' => 'Warnings', 'notice' => 'Notices', 'info' => 'Other'];
    private const TAIL = 4 * 1024 * 1024;       // read at most the last 4 MB of a file

    /** @return array<string, array{path: string, size: int, modified: int, extra: bool}> name => file */
    public static function files(): array
    {
        $out = [];
        foreach (glob(BASE_PATH.'/storage/logs/*.log') ?: [] as $f) {
            $out[basename($f)] = ['path' => $f, 'size' => (int) filesize($f), 'modified' => (int) filemtime($f), 'extra' => false];
        }
        foreach ((array) config('logs.extra', []) as $f) {
            if (is_string($f) && is_file($f) && is_readable($f)) {
                $out[basename($f).' ('.basename(dirname($f)).')'] = ['path' => $f, 'size' => (int) filesize($f), 'modified' => (int) filemtime($f), 'extra' => true];
            }
        }
        uasort($out, fn ($a, $b) => $b['modified'] <=> $a['modified']);

        return $out;
    }

    /** Level of one entry from its first line. */
    public static function level(string $head): string
    {
        return match (true) {
            (bool) preg_match('/PHP (Fatal|Parse|Recoverable fatal) error|Uncaught|Exception|\bError:|\[(error|crit|alert|emerg)\]|SQLSTATE|failed:/i', $head) => 'error',
            (bool) preg_match('/PHP Warning|\[warn(ing)?\]|warning/i', $head) => 'warning',
            (bool) preg_match('/PHP (Notice|Deprecated|Strict)|\[notice\]|deprecated/i', $head) => 'notice',
            default => 'info',
        };
    }

    /**
     * Entries of a file, newest first. A PHP entry starts with "[19-Oct-2026 12:34:56 Asia/Bangkok]"; following lines
     * (stack traces) belong to it. Other formats (web server) split per line with their own [date].
     * @return list<array{time: ?string, level: string, head: string, body: string}>
     */
    public static function entries(string $path): array
    {
        $size = (int) @filesize($path);
        $fh = @fopen($path, 'rb');
        if (! $fh) {
            return [];
        }
        $start = max(0, $size - self::TAIL);
        fseek($fh, $start);
        $raw = (string) stream_get_contents($fh);
        fclose($fh);
        if ($start > 0 && ($nl = strpos($raw, "\n")) !== false) {
            $raw = substr($raw, $nl + 1);                    // drop the cut first line
        }
        $parts = preg_split('/^(?=\[[^\]\n]{6,60}\]\s)/m', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = rtrim($p);
            if ($p === '') {
                continue;
            }
            $time = null;
            if (preg_match('/^\[([^\]\n]+)\]\s*/', $p, $m)) {
                $ts = strtotime(preg_replace('/\s+[A-Za-z_]+\/[A-Za-z_\/]+$/', '', $m[1]) ?? $m[1]);
                $time = $ts ? date('Y-m-d H:i:s', $ts) : $m[1];
                $p = substr($p, strlen($m[0]));
            }
            $lines = explode("\n", $p, 2);
            // an unexpected error caught by the app starts with "[ref …] METHOD /path": the exception is on the next line
            $isRef = str_starts_with($lines[0], '[ref ');
            $head = $isRef && isset($lines[1]) ? strtok($lines[1], "\n").'  ·  '.$lines[0] : $lines[0];
            $out[] = ['time' => $time, 'level' => $isRef ? 'error' : self::level($lines[0]), 'head' => mb_substr($head, 0, 500), 'body' => $p];
        }

        return array_reverse($out);
    }

    /** One entry by its reference code (written for every unexpected error: "[ref ABC123]"). */
    public static function byRef(string $ref): ?array
    {
        if (! preg_match('/^[A-Z0-9]{6,12}$/', $ref)) {
            return null;
        }
        foreach (self::entries(BASE_PATH.'/storage/logs/php-error.log') as $e) {
            if (str_contains($e['body'], '[ref '.$ref.']')) {
                return $e;
            }
        }

        return null;
    }

    /** Settings of this PHP that matter when something goes wrong. @return array<string, string> */
    public static function phpInfo(): array
    {
        $free = @disk_free_space(BASE_PATH.'/storage');

        return [
            'PHP' => PHP_VERSION.' ('.PHP_SAPI.')', 'Server' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? '—'), 'OS' => PHP_OS_FAMILY.' '.php_uname('r'),
            'memory_limit' => (string) ini_get('memory_limit'), 'max_execution_time' => ini_get('max_execution_time').' s',
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'), 'post_max_size' => (string) ini_get('post_max_size'),
            'OPcache' => function_exists('opcache_get_status') && @opcache_get_status(false) ? 'on' : 'off',
            'Free disk (storage)' => $free !== false ? Files::size((int) $free) : '—', 'Time zone' => date_default_timezone_get(), 'Error log' => (string) ini_get('error_log'),
        ];
    }
}
