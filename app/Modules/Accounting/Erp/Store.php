<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Erp;

use App\Core\Support\DB;

/** Saves ERP rows into the erp_* tables (insert, or update when the id is already there). */
final class Store
{
    public static function schema(string $entity): array
    {
        return config('erp_schema.'.$entity) ?? throw new \InvalidArgumentException("Unknown ERP entity {$entity}");
    }

    /** Turn one ERP JSON object into [column => value] (keys matched without caring about upper / lower case). */
    public static function map(string $entity, array $raw, array $stamp = []): ?array
    {
        $fields = self::schema($entity)['fields'];
        $lower  = array_change_key_case($raw, CASE_LOWER);
        $row    = [];
        foreach ($fields as $col => $f) {
            $row[$col] = self::value($f['type'], $lower[strtolower($f['json'])] ?? null);
        }
        foreach ($stamp as $col => $v) {
            if (array_key_exists($col, $row)) { $row[$col] = $v; }
        }

        return $row['id'] === null ? null : $row;
    }

    private static function value(string $type, mixed $v): mixed
    {
        if ($v === null || $v === '') { return null; }
        if (is_array($v)) { return $type === 'text' ? json_encode($v, JSON_UNESCAPED_UNICODE) : null; }

        return match ($type) {
            'int'  => is_numeric($v) ? (int) $v : null,
            'num'  => is_numeric($v) ? (string) $v : null,
            'bool' => is_bool($v) ? ($v ? 'true' : 'false') : (in_array(strtolower((string) $v), ['true', '1', 't'], true) ? 'true' : 'false'),
            'date' => self::date((string) $v),
            default => (string) $v,
        };
    }

    /** ERP uses 0001-01-01 for "no date". */
    private static function date(string $v): ?string
    {
        if (! preg_match('/^(\d{4})-\d{2}-\d{2}/', $v, $m) || (int) $m[1] < 1900) { return null; }

        return strtotime($v) === false ? null : $v;
    }

    /**
     * @param array<int, array> $rows already mapped by map()
     * @param bool $overwrite false = only new ids are added, existing rows stay as they are
     * @return int rows written
     */
    public static function upsert(string $entity, array $rows, bool $overwrite = true): int
    {
        if (! $rows) { return 0; }
        $s     = self::schema($entity);
        $cols  = array_keys($s['fields']);
        $byId  = [];
        foreach ($rows as $r) { $byId[$r['id']] = $r; }           // the same id twice in one statement is an error
        $rows  = array_values($byId);
        $per   = max(1, intdiv(30000, count($cols)));
        $set   = implode(', ', array_map(fn ($c) => "{$c} = EXCLUDED.{$c}", array_diff($cols, ['id']))).', synced_at = now()';
        $pdo   = DB::connection(ErpSettings::CONN);
        $n     = 0;
        $pdo->beginTransaction();
        try {
            foreach (array_chunk($rows, $per) as $chunk) {
                $marks = '('.implode(',', array_fill(0, count($cols), '?')).')';
                $sql   = "INSERT INTO {$s['table']} (".implode(',', $cols).') VALUES '.implode(',', array_fill(0, count($chunk), $marks)).($overwrite ? " ON CONFLICT (id) DO UPDATE SET {$set}" : ' ON CONFLICT (id) DO NOTHING');   // overwrite off: rows already there are left as they are
                $par   = [];
                foreach ($chunk as $r) { foreach ($cols as $c) { $par[] = $r[$c]; } }
                $pdo->prepare($sql)->execute($par);
                $n += count($chunk);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $n;
    }
}
