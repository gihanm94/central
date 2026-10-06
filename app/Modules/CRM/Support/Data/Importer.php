<?php
declare(strict_types=1);

namespace App\Modules\CRM\Support\Data;

use App\Core\Support\DB;

/**
 * Puts the rows of a file into one CRM table, following a column mapping the admin chose:
 *   $map[column] = ['src' => position of the file column or null, 'fixed' => text used when the cell is empty / no column]
 * Values are converted per column type; names are turned into ids (lead "Siam Packaging" → its id, owner e-mail → user id),
 * stage / status names into their codes. A dry run does everything except write.
 */
final class Importer
{
    private array $lookup = [];
    private array $codes = [];
    private int $errorLimit = 200;

    public function __construct(private int $adminId, private ?int $adminDepartment) {}

    /**
     * @return array{inserted: int, updated: int, skipped: int, errors: array<int, array{0: int, 1: string}>, total: int}
     */
    public function run(string $table, array $rows, array $map, string $mode, bool $dry): array
    {
        $cols    = Schema::columns($table);
        $prefix  = Schema::TABLES[$table][1];
        $hasId   = isset($cols['id']);
        $res     = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [], 'total' => count($rows)];
        $idGiven = false;
        $seen    = [];

        foreach ($rows as $n => $row) {
            $line   = $n + 2;                               // row 1 of the sheet is the header
            $errors = [];
            $data   = [];
            foreach ($cols as $name => $c) {
                $m   = $map[$name] ?? null;
                $raw = '';
                if ($m && $m['src'] !== null && $m['src'] !== '') { $raw = trim((string) ($row[(int) $m['src']] ?? '')); }
                if ($raw === '' && $m && trim((string) ($m['fixed'] ?? '')) !== '') { $raw = trim((string) $m['fixed']); }
                if ($raw === '') { continue; }
                $v = $this->convert($table, $c, $raw, $errors);
                if ($v !== null) { $data[$name] = $v; }
            }
            if (! array_filter($row, fn ($v) => trim((string) $v) !== '')) { $res['total']--; continue; }

            // who owns it: the person importing, unless the file says otherwise
            if (isset($cols['owner_id']) && ! isset($data['owner_id'])) { $data['owner_id'] = $this->adminId; }
            if (isset($cols['department_id']) && ! isset($data['department_id'])) { $data['department_id'] = $this->departmentOf((int) ($data['owner_id'] ?? $this->adminId)); }
            if (isset($cols['created_by']) && ! isset($data['created_by']) && $mode === 'insert') { $data['created_by'] = $this->adminId; }
            if (isset($cols['updated_by']) && ! isset($data['updated_by'])) { $data['updated_by'] = $this->adminId; }

            $existing = null;
            if ($mode !== 'insert') {
                $key = $mode === 'update_id' ? 'id' : 'code';
                if (isset($data[$key])) { $existing = $this->find($table, $key, $data[$key]); }
                elseif (! $errors) { $errors[] = __('The :key is empty, so the existing row cannot be found.', ['key' => $key]); }
            }
            if (! $existing) {
                foreach ($cols as $name => $c) {
                    if ($c['required'] && ! isset($data[$name]) && ! (in_array($name, ['created_by'], true))) {
                        $errors[] = __(':col is required.', ['col' => $name]);
                    }
                }
                if (isset($data['code']) && $mode === 'insert') {
                    $lc = mb_strtolower((string) $data['code']);
                    if (isset($seen[$lc]) || $this->find($table, 'code', $data['code'])) { $errors[] = __('The code :code already exists.', ['code' => $data['code']]); }
                    $seen[$lc] = true;
                }
            }
            if ($errors) {
                $res['skipped']++;
                if (count($res['errors']) < $this->errorLimit) { $res['errors'][] = [$line, implode(' ', array_unique($errors))]; }
                continue;
            }
            if ($dry) {
                $existing ? $res['updated']++ : $res['inserted']++;
                continue;
            }
            try {
                if ($existing) {
                    $set = array_diff_key($data, ['id' => 1, 'created_by' => 1]);
                    if (isset($cols['updated_at'])) { $set['updated_at'] = now(); }
                    DB::update($table, $set, ['id' => $existing], 'crm');
                    $res['updated']++;
                } else {
                    $id = DB::insert($table, $data, 'crm', $hasId ? 'id' : '');
                    if ($hasId && $prefix && empty($data['code'])) {
                        DB::exec("UPDATE {$table} SET code = ? WHERE id = ? AND code IS NULL", [$prefix.'-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id], 'crm');
                    }
                    $idGiven = $idGiven || isset($data['id']);
                    $res['inserted']++;
                }
            } catch (\PDOException $e) {
                $res['skipped']++;
                if (count($res['errors']) < $this->errorLimit) { $res['errors'][] = [$line, self::friendly($e)]; }
            }
        }
        if (! $dry && $idGiven && $hasId) {      // rows came with their own ids: keep the counter ahead of them
            DB::exec("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), GREATEST((SELECT COALESCE(MAX(id), 1) FROM {$table}), 1))", [], 'crm');
        }

        return $res;
    }

    private static function friendly(\PDOException $e): string
    {
        $msg = $e->getMessage();
        return match (true) {
            str_contains($msg, '23505') => __('Duplicate: a row with the same unique value already exists.'),
            str_contains($msg, '23503') => __('It points to a record that does not exist (check the lead / contact / project it belongs to).'),
            str_contains($msg, '23502') => __('A required value is missing.'),
            str_contains($msg, '23514') => __('A value is not allowed for this column.'),
            str_contains($msg, '22001') => __('A value is too long for its column.'),
            default => str_limit(preg_replace('/^SQLSTATE\[[^\]]+\]:[^:]*:\s*/', '', $msg) ?? $msg, 160),
        };
    }

    private function departmentOf(int $userId): ?int
    {
        $this->lookup['__dept'] ??= array_column(DB::select('SELECT id, department_id FROM users'), 'department_id', 'id');
        $d = $this->lookup['__dept'][$userId] ?? $this->adminDepartment;

        return $d ? (int) $d : null;
    }

    private function find(string $table, string $key, mixed $value): ?int
    {
        if ($key === 'id') {
            return ctype_digit((string) $value) ? (int) DB::scalar("SELECT id FROM {$table} WHERE id = ?", [(int) $value], 'crm') ?: null : null;
        }
        $this->codes[$table] ??= array_column(DB::select("SELECT id, lower(code) AS c FROM {$table} WHERE code IS NOT NULL", [], 'crm'), 'id', 'c');

        return $this->codes[$table][mb_strtolower((string) $value)] ?? null;
    }

    /** @param array{name: string, type: string, length: ?int} $c */
    private function convert(string $table, array $c, string $raw, array &$errors): mixed
    {
        $name = $c['name'];
        $t    = $c['type'];
        if (isset(Schema::LOOKUPS[$name]) && in_array($t, ['bigint', 'integer'], true)) {
            return $this->resolve($name, $raw, $errors);
        }
        if (in_array($t, ['bigint', 'integer', 'smallint'], true)) {
            $v = str_replace([',', ' '], '', $raw);
            if (! preg_match('/^-?\d+(\.0+)?$/', $v)) { $errors[] = __(':col must be a whole number (got ":v").', ['col' => $name, 'v' => str_limit($raw, 30)]); return null; }

            return (int) $v;
        }
        if (in_array($t, ['numeric', 'double precision', 'real'], true)) {
            $v = str_replace([',', ' ', '฿', '$'], '', $raw);
            if (! is_numeric($v)) { $errors[] = __(':col must be a number (got ":v").', ['col' => $name, 'v' => str_limit($raw, 30)]); return null; }

            return $v;
        }
        if ($t === 'boolean') {
            $l = mb_strtolower($raw);
            if (in_array($l, ['1', 'true', 't', 'yes', 'y', 'x', 'ใช่', 'จริง'], true)) { return true; }
            if (in_array($l, ['0', 'false', 'f', 'no', 'n', 'ไม่', 'ไม่ใช่', 'เท็จ'], true)) { return false; }
            $errors[] = __(':col must be yes or no (got ":v").', ['col' => $name, 'v' => str_limit($raw, 30)]);

            return null;
        }
        if ($t === 'date' || str_starts_with($t, 'timestamp')) {
            $v = self::date($raw, $t !== 'date');
            if ($v === null) { $errors[] = __(':col must be a date (got ":v").', ['col' => $name, 'v' => str_limit($raw, 30)]); }

            return $v;
        }
        // text
        if (($choices = Schema::choices($table, $name)) !== null) {
            if (isset($choices[$raw])) { return $raw; }
            $up = strtoupper(str_replace([' ', '-', '&'], ['_', '_', ''], $raw));
            if (isset($choices[$up])) { return $up; }
            foreach ($choices as $code => $label) {
                if (mb_strtolower($label) === mb_strtolower($raw) || mb_strtolower(str_replace([' ', '&'], ['', ''], (string) $label)) === mb_strtolower(str_replace([' ', '-', '&', '_'], '', $raw))) { return (string) $code; }
            }
            $errors[] = __(':col ":v" is not one of: :list.', ['col' => $name, 'v' => str_limit($raw, 30), 'list' => implode(', ', array_values($choices))]);

            return null;
        }
        if ($c['length'] !== null && mb_strlen($raw) > $c['length']) {
            $errors[] = __(':col is too long (:n characters, at most :max).', ['col' => $name, 'n' => mb_strlen($raw), 'max' => $c['length']]);

            return null;
        }

        return $raw;
    }

    /** A number is taken as an id; anything else is looked up by name / code / e-mail. */
    private function resolve(string $col, string $raw, array &$errors): ?int
    {
        [$table, $keys, $conn] = Schema::LOOKUPS[$col];
        $ck = $table.'.'.$conn;
        if (! isset($this->lookup[$ck])) {
            $map = ['__ids' => []];
            foreach (DB::select('SELECT id, '.implode(', ', array_map(fn ($k) => "{$k} AS k_{$k}", $keys)).' FROM '.$table.($table === 'users' ? ' WHERE deleted_at IS NULL' : (in_array($table, ['leads', 'contacts', 'opportunities', 'projects', 'tasks'], true) ? ' WHERE deleted_at IS NULL' : '')), [], $conn) as $r) {
                $map['__ids'][(int) $r['id']] = true;
                foreach ($keys as $k) {
                    $v = mb_strtolower(trim((string) $r['k_'.$k]));
                    if ($v !== '') { $map[$v] ??= (int) $r['id']; }
                }
            }
            $this->lookup[$ck] = $map;
        }
        $m = $this->lookup[$ck];
        if (ctype_digit($raw) && isset($m['__ids'][(int) $raw])) { return (int) $raw; }
        $hit = $m[mb_strtolower($raw)] ?? null;
        if ($hit === null) {
            $errors[] = ctype_digit($raw)
                ? __(':col: no record with id :v.', ['col' => $col, 'v' => $raw])
                : __(':col: ":v" was not found.', ['col' => $col, 'v' => str_limit($raw, 40)]);
        }

        return $hit;
    }

    /** 2026-10-06, 06/10/2026, 6.10.2026 14:30, a Buddhist-era year (2569) or an Excel serial number. */
    public static function date(string $raw, bool $time): ?string
    {
        $raw = trim($raw);
        $y = $mo = $d = $h = $mi = $s = null;
        if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})(?:[ T]+(\d{1,2}):(\d{2})(?::(\d{2}))?)?#', $raw, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            [$h, $mi, $s] = [(int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0)];
        } elseif (preg_match('#^(\d{4})[/\-.](\d{1,2})[/\-.](\d{1,2})(?:[ T]+(\d{1,2}):(\d{2})(?::(\d{2}))?)?#', $raw, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            [$h, $mi, $s] = [(int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0)];
        } elseif (preg_match('/^\d{5}(\.\d+)?$/', $raw)) {
            $secs = (int) round(((float) $raw - 25569) * 86400);

            return $time ? gmdate('Y-m-d H:i:s', $secs) : gmdate('Y-m-d', $secs);
        } else {
            $ts = strtotime($raw);

            return $ts ? ($time ? date('Y-m-d H:i:s', $ts) : date('Y-m-d', $ts)) : null;
        }
        if ($y > 2400) { $y -= 543; }
        if (! checkdate($mo, $d, $y) || $h > 23 || $mi > 59) { return null; }

        return $time ? sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $mi, $s) : sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }
}
