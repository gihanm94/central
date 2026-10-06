<?php
declare(strict_types=1);

namespace App\Modules\CRM\Support\Data;

/** An uploaded file (Excel, CSV or SQL) as named sheets of rows: ['Sheet' => ['header' => [...], 'rows' => [[...], ...]]]. */
final class Source
{
    public const MAX_BYTES = 25 * 1024 * 1024;

    public static function dir(): string
    {
        $d = BASE_PATH.'/storage/imports';
        if (! is_dir($d)) { @mkdir($d, 0775, true); }

        return $d;
    }

    /** Throw away files older than a day. */
    public static function sweep(): void
    {
        foreach (glob(self::dir().'/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 86400) { @unlink($f); }
        }
    }

    public static function path(string $token): ?string
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $token)) { return null; }
        foreach (['xlsx', 'csv', 'sql'] as $ext) {
            if (is_file(self::dir().'/'.$token.'.'.$ext)) { return self::dir().'/'.$token.'.'.$ext; }
        }

        return null;
    }

    public static function forget(string $token): void
    {
        foreach (glob(self::dir().'/'.$token.'.*') ?: [] as $f) { @unlink($f); }
    }

    /** @return array<string, array{header: string[], rows: array}> */
    public static function load(string $path, bool $firstRowHeader = true): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'sql') {
            $out = [];
            foreach (SqlDump::parse((string) file_get_contents($path)) as $table => $d) {
                $out[$table] = ['header' => $d['columns'], 'rows' => $d['rows']];
            }

            return $out;
        }
        $sheets = $ext === 'xlsx' ? Xlsx::read($path) : ['CSV' => self::csv($path)];
        $out = [];
        foreach ($sheets as $name => $rows) {
            if (! $rows) { $out[$name] = ['header' => [], 'rows' => []]; continue; }
            if ($firstRowHeader) {
                $header = array_map(fn ($h) => trim((string) $h), array_shift($rows));
                $width  = max(count($header), ...array_map('count', $rows ?: [[]]));
                for ($i = 0; $i < $width; $i++) { if (($header[$i] ?? '') === '') { $header[$i] = self::letter($i); } }
            } else {
                $width  = max(array_map('count', $rows));
                $header = array_map(fn ($i) => self::letter($i), range(0, $width - 1));
            }
            $out[$name] = ['header' => $header, 'rows' => $rows];
        }

        return $out;
    }

    public static function letter(int $i): string
    {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) { $s = chr(65 + ($n - 1) % 26).$s; }

        return __('Column').' '.$s;
    }

    private static function csv(string $path): array
    {
        $raw = (string) file_get_contents($path);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        if (! mb_check_encoding($raw, 'UTF-8')) {
            foreach (['CP874', 'Windows-874', 'CP1252', 'ISO-8859-1'] as $enc) {
                try { $conv = @mb_convert_encoding($raw, 'UTF-8', $enc); } catch (\Throwable) { $conv = false; }
                if ($conv !== false && $conv !== '') { $raw = $conv; break; }
            }
        }
        $first = strtok($raw, "\n") ?: '';
        $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($counts);
        $delim = (string) array_key_first($counts);
        $h = fopen('php://temp', 'r+');
        fwrite($h, $raw);
        rewind($h);
        $rows = [];
        while (($line = fgetcsv($h, 0, $delim)) !== false) {
            if ($line === [null]) { continue; }
            $rows[] = array_map(fn ($v) => (string) $v, $line);
            if (count($rows) > 100001) { throw new \RuntimeException('The file has more than 100000 rows. Split it into smaller files.'); }
        }
        fclose($h);

        return $rows;
    }
}
