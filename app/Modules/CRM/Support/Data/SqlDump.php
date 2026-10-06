<?php
declare(strict_types=1);

namespace App\Modules\CRM\Support\Data;

/**
 * Reads the DATA of an SQL file without running it: INSERT … VALUES (…),(…); and PostgreSQL's COPY … FROM stdin blocks.
 * Everything else in the file (CREATE, ALTER, SET …) is ignored, so a dump can never change the database structure.
 * Result: table name => ['columns' => string[], 'rows' => array<int, array<int, ?string>>]
 */
final class SqlDump
{
    public static function parse(string $sql, int $maxRows = 100000): array
    {
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
        $backslash = str_contains($sql, '`') || preg_match("/\\bE'/i", $sql) === 1;      // MySQL-style dumps escape quotes with a backslash
        $out = [];
        $n   = strlen($sql);
        $i   = 0;
        $total = 0;
        while ($i < $n) {
            // skip whitespace and comments
            $c = $sql[$i];
            if (ctype_space($c)) { $i++; continue; }
            if ($c === '-' && ($sql[$i + 1] ?? '') === '-') { $e = strpos($sql, "\n", $i); $i = $e === false ? $n : $e + 1; continue; }
            if ($c === '#') { $e = strpos($sql, "\n", $i); $i = $e === false ? $n : $e + 1; continue; }
            if ($c === '/' && ($sql[$i + 1] ?? '') === '*') { $e = strpos($sql, '*/', $i + 2); $i = $e === false ? $n : $e + 2; continue; }

            if (preg_match('/\GINSERT\s+(?:IGNORE\s+)?INTO\s+/i', $sql, $m, 0, $i)) {
                $i += strlen($m[0]);
                $table = self::ident($sql, $i);
                $cols  = [];
                self::skipWs($sql, $i);
                if (($sql[$i] ?? '') === '(') {
                    $i++;
                    while ($i < $n) {
                        self::skipWs($sql, $i);
                        $cols[] = self::ident($sql, $i);
                        self::skipWs($sql, $i);
                        if (($sql[$i] ?? '') === ',') { $i++; continue; }
                        if (($sql[$i] ?? '') === ')') { $i++; break; }
                        break;
                    }
                }
                self::skipWs($sql, $i);
                if (! preg_match('/\GVALUES?\s*/i', $sql, $m, 0, $i)) { $i = self::skipStatement($sql, $i, $backslash); continue; }
                $i += strlen($m[0]);
                $out[$table] ??= ['columns' => $cols, 'rows' => []];
                if (! $out[$table]['columns'] && $cols) { $out[$table]['columns'] = $cols; }
                while ($i < $n) {
                    self::skipWs($sql, $i);
                    if (($sql[$i] ?? '') !== '(') { break; }
                    $i++;
                    $row = [];
                    while ($i < $n) {
                        $row[] = self::value($sql, $i, $backslash);
                        self::skipWs($sql, $i);
                        $ch = $sql[$i] ?? '';
                        if ($ch === ',') { $i++; continue; }
                        if ($ch === ')') { $i++; break; }
                        break;
                    }
                    $out[$table]['rows'][] = $row;
                    if (++$total > $maxRows) { throw new \RuntimeException("The file has more than {$maxRows} rows. Split it into smaller files."); }
                    self::skipWs($sql, $i);
                    if (($sql[$i] ?? '') === ',') { $i++; continue; }
                    break;
                }
                $i = self::skipStatement($sql, $i, $backslash);
                continue;
            }

            if (preg_match('/\GCOPY\s+/i', $sql, $m, 0, $i)) {
                $i += strlen($m[0]);
                $table = self::ident($sql, $i);
                $cols  = [];
                self::skipWs($sql, $i);
                if (($sql[$i] ?? '') === '(') {
                    $e    = strpos($sql, ')', $i);
                    $list = substr($sql, $i + 1, $e - $i - 1);
                    $cols = array_map(fn ($x) => trim($x, " \t\"`"), explode(',', $list));
                    $i    = $e + 1;
                }
                $nl = strpos($sql, "\n", $i);
                if ($nl === false || ! preg_match('/\s*FROM\s+stdin/i', substr($sql, $i, $nl - $i))) { $i = self::skipStatement($sql, $i, $backslash); continue; }
                $i = $nl + 1;
                $end = preg_match('/^\\\\\.\s*$/m', $sql, $mm, PREG_OFFSET_CAPTURE, $i) ? $mm[0][1] : $n;
                $out[$table] ??= ['columns' => $cols, 'rows' => []];
                foreach (explode("\n", substr($sql, $i, $end - $i)) as $line) {
                    if ($line === '') { continue; }
                    $row = [];
                    foreach (explode("\t", rtrim($line, "\r")) as $cell) {
                        $row[] = $cell === '\N' ? null : strtr($cell, ['\\\\' => '\\', '\\t' => "\t", '\\n' => "\n", '\\r' => "\r"]);
                    }
                    $out[$table]['rows'][] = $row;
                    if (++$total > $maxRows) { throw new \RuntimeException("The file has more than {$maxRows} rows. Split it into smaller files."); }
                }
                $i = $end + 2;
                continue;
            }

            $i = self::skipStatement($sql, $i, $backslash);
        }

        foreach ($out as $t => &$d) {
            $width = max(array_map('count', $d['rows']) ?: [0]);
            if (! $d['columns']) { $d['columns'] = array_map(fn ($k) => 'column_'.$k, range(1, max(1, $width))); }
            foreach ($d['rows'] as &$r) { $r = array_pad($r, count($d['columns']), null); }
        }

        return array_filter($out, fn ($d) => $d['rows']);
    }

    private static function skipWs(string $s, int &$i): void
    {
        $n = strlen($s);
        while ($i < $n && ctype_space($s[$i])) { $i++; }
    }

    /** Table or column name: optional quotes (", `, [ ]) and a schema prefix, which is dropped. */
    private static function ident(string $s, int &$i): string
    {
        $name = '';
        for (;;) {
            self::skipWs($s, $i);
            $c = $s[$i] ?? '';
            if ($c === '"' || $c === '`') { $e = strpos($s, $c, $i + 1); $name = substr($s, $i + 1, $e - $i - 1); $i = $e + 1; }
            elseif ($c === '[') { $e = strpos($s, ']', $i); $name = substr($s, $i + 1, $e - $i - 1); $i = $e + 1; }
            else { preg_match('/\G[A-Za-z0-9_$]+/', $s, $m, 0, $i); $name = $m[0] ?? ''; $i += strlen($name); }
            if (($s[$i] ?? '') === '.') { $i++; continue; }

            return $name;
        }
    }

    /** One value: 'text', number, NULL, TRUE/FALSE, or something like now() / '2026-01-01'::date (casts are dropped). */
    private static function value(string $s, int &$i, bool $backslash): ?string
    {
        self::skipWs($s, $i);
        $n = strlen($s);
        $c = $s[$i] ?? '';
        if (($c === 'E' || $c === 'e' || $c === 'N') && ($s[$i + 1] ?? '') === "'") { $i++; $c = "'"; }
        if ($c === "'") {
            $i++;
            $v = '';
            while ($i < $n) {
                $ch = $s[$i];
                if ($ch === "'") {
                    if (($s[$i + 1] ?? '') === "'") { $v .= "'"; $i += 2; continue; }
                    $i++; break;
                }
                if ($ch === '\\' && $backslash && $i + 1 < $n) {
                    $nx = $s[$i + 1];
                    $v .= ['n' => "\n", 't' => "\t", 'r' => "\r", '0' => "\0"][$nx] ?? $nx;
                    $i += 2; continue;
                }
                $v .= $ch; $i++;
            }
            self::skipCast($s, $i);

            return $v;
        }
        // bare token up to the next comma / closing bracket at this level
        $start = $i;
        $depth = 0;
        while ($i < $n) {
            $ch = $s[$i];
            if ($ch === '(') { $depth++; }
            elseif ($ch === ')') { if ($depth === 0) { break; } $depth--; }
            elseif ($ch === ',' && $depth === 0) { break; }
            elseif ($ch === "'") { $e = strpos($s, "'", $i + 1); $i = $e === false ? $n : $e; }
            $i++;
        }
        $tok = trim(substr($s, $start, $i - $start));
        if ($tok === '' || strcasecmp($tok, 'NULL') === 0 || str_contains($tok, '(')) { return null; }
        if (strcasecmp($tok, 'TRUE') === 0) { return 'true'; }
        if (strcasecmp($tok, 'FALSE') === 0) { return 'false'; }

        return preg_replace('/::[a-z_ ]+(\(\d+(,\d+)?\))?$/i', '', $tok);
    }

    private static function skipCast(string $s, int &$i): void
    {
        if (preg_match('/\G::[A-Za-z_ ]+(\(\d+(,\s*\d+)?\))?/', $s, $m, 0, $i)) { $i += strlen($m[0]); }
    }

    /** Move past the end of the current statement (the next ; outside quotes). */
    private static function skipStatement(string $s, int $i, bool $backslash): int
    {
        $n = strlen($s);
        while ($i < $n) {
            $ch = $s[$i];
            if ($ch === "'") {
                $i++;
                while ($i < $n) {
                    if ($s[$i] === '\\' && $backslash) { $i += 2; continue; }
                    if ($s[$i] === "'") { if (($s[$i + 1] ?? '') === "'") { $i += 2; continue; } break; }
                    $i++;
                }
            } elseif ($ch === '$' && preg_match('/\G\$([A-Za-z_]*)\$/', $s, $m, 0, $i)) {      // $$ … $$ function bodies
                $e = strpos($s, $m[0], $i + strlen($m[0]));
                $i = $e === false ? $n : $e + strlen($m[0]) - 1;
            } elseif ($ch === ';') { return $i + 1; }
            $i++;
        }

        return $n;
    }
}
