<?php
declare(strict_types=1);

namespace App\Modules\CRM\Support\Data;

use App\Core\Support\DB;

/** Writes whole CRM tables as Excel, CSV or SQL INSERT statements. */
final class Exporter
{
    /**
     * @param array{department?: int, from?: string, to?: string, names?: bool} $opt
     * @return array{0: string[], 1: array<int, array<int, mixed>>, 2: array<string, array>} header, rows, column info
     */
    public static function table(string $table, array $opt = []): array
    {
        $cols  = Schema::columns($table);
        $where = [];
        $par   = [];
        if (self::has($table, 'deleted_at')) { $where[] = 't.deleted_at IS NULL'; }
        if (! empty($opt['department']) && isset($cols['department_id'])) { $where[] = 't.department_id = ?'; $par[] = (int) $opt['department']; }
        if (! empty($opt['from']) && isset($cols['created_at'])) { $where[] = 't.created_at >= ?'; $par[] = $opt['from'].' 00:00:00'; }
        if (! empty($opt['to']) && isset($cols['created_at'])) { $where[] = 't.created_at <= ?'; $par[] = $opt['to'].' 23:59:59'; }
        $order = isset($cols['id']) ? 't.id' : '1';
        $list  = implode(', ', array_map(fn ($c) => 't.'.$c, array_keys($cols)));
        $rows  = DB::select("SELECT {$list} FROM {$table} t".($where ? ' WHERE '.implode(' AND ', $where) : '')." ORDER BY {$order}", $par, 'crm');

        $names = ! empty($opt['names']) ? self::nameMaps(array_keys($cols)) : [];
        $out   = [];
        foreach ($rows as $r) {
            $line = [];
            foreach ($cols as $name => $c) {
                $v = $r[$name] ?? null;
                if ($v !== null && isset($names[$name])) { $v = $names[$name][(int) $v] ?? $v; }
                if ($v !== null && $c['type'] === 'boolean') { $v = ($v === true || $v === 't' || $v === 'true' || $v === 1 || $v === '1') ? 'true' : 'false'; }
                $line[] = $v;
            }
            $out[] = $line;
        }

        return [array_keys($cols), $out, $cols];
    }

    private static function has(string $table, string $col): bool
    {
        return (bool) DB::scalar("SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?", [$table, $col], 'crm');
    }

    /** id → readable text for the columns that point at other records / people. */
    private static function nameMaps(array $columns): array
    {
        $maps = [];
        foreach ($columns as $col) {
            if (! isset(Schema::LOOKUPS[$col])) { continue; }
            [$table, $keys, $conn] = Schema::LOOKUPS[$col];
            $show = $table === 'users' ? 'email' : (in_array('name_en', $keys, true) ? 'name_en' : (in_array('name', $keys, true) ? 'name' : $keys[0]));
            $maps[$col] = array_column(DB::select("SELECT id, {$show} AS v FROM {$table}", [], $conn), 'v', 'id');
        }

        return $maps;
    }

    /** Numbers as numbers in Excel; everything else text. */
    public static function forExcel(array $rows, array $cols): array
    {
        $numeric = [];
        $i = 0;
        foreach ($cols as $c) {
            if (in_array($c['type'], ['integer', 'smallint', 'bigint', 'numeric', 'double precision', 'real'], true) && $c['name'] !== 'id' && ! str_ends_with($c['name'], '_id')) { $numeric[$i] = true; }
            $i++;
        }
        foreach ($rows as &$r) {
            foreach ($numeric as $i => $_) {
                if ($r[$i] !== null && $r[$i] !== '' && is_numeric($r[$i])) { $r[$i] = str_contains((string) $r[$i], '.') ? (float) $r[$i] : (int) $r[$i]; }
            }
        }

        return $rows;
    }

    public static function sql(string $table, array $header, array $rows, array $cols): string
    {
        $out  = '';
        $cl   = implode(', ', $header);
        foreach ($rows as $r) {
            $vals = [];
            foreach ($r as $i => $v) {
                if ($v === null) { $vals[] = 'NULL'; continue; }
                $t = $cols[$header[$i]]['type'] ?? 'text';
                if ($t === 'boolean') { $vals[] = $v === 'true' ? 'TRUE' : 'FALSE'; }
                elseif (in_array($t, ['integer', 'smallint', 'bigint', 'numeric', 'double precision', 'real'], true) && is_numeric($v)) { $vals[] = (string) $v; }
                else { $vals[] = "'".str_replace("'", "''", (string) $v)."'"; }
            }
            $out .= "INSERT INTO {$table} ({$cl}) VALUES (".implode(', ', $vals).");\n";
        }

        return $out;
    }
}
