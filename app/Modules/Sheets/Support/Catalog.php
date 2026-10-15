<?php
declare(strict_types=1);

namespace App\Modules\Sheets\Support;

use App\Core\Auth\CurrentUser;
use App\Core\Support\DB;
use App\Core\Support\Modules;

/**
 * Every table of the databases of core and the enabled modules, which ones the administrator opened
 * (gsheet_tables), who may sync them (gsheet_table_access) and reading their rows.
 * Tables and columns that hold passwords, tokens, sessions or keys are never offered.
 */
final class Catalog
{
    private const SECRET_TABLES = '/^(settings|erp_settings|google_connections|user_sessions|login_attempts|passkeys|password_resets|schema_migrations)$|^gsheet_|token|secret|password|session/i';
    private const SECRET_COLUMNS = '/password|passwd|token|secret|api_?key|private_?key|public_key|credential|cookie|(^|_)session|(^|_)otp($|_)|^key$|_hash$|sign_count|file_key/i';

    /** @return array<string,string> db connection key → name of the area it belongs to */
    public static function databases(): array
    {
        $out = [];
        foreach (Modules::all() as $key => $m) {
            $db = (string) ($m['db'] ?? $key);
            if (isset($out[$db]) || ! ($key === 'core' || (Modules::usable($key) && $m['dir']))) {
                continue;
            }
            $out[$db] = __((string) $m['name']);
        }

        return $out;
    }

    public static function secretTable(string $table): bool { return (bool) preg_match(self::SECRET_TABLES, $table); }
    public static function secretColumn(string $column): bool { return (bool) preg_match(self::SECRET_COLUMNS, $column); }

    /**
     * Tables of one database with their columns and an approximate row count.
     * @return array<string, array{columns: array<string,string>, rows: int}> name → columns (name → type)
     */
    public static function tables(string $db): array
    {
        $out = [];
        $rows = DB::select("SELECT c.table_name, c.column_name, c.data_type FROM information_schema.columns c
            JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name
            WHERE c.table_schema = 'public' AND t.table_type = 'BASE TABLE' ORDER BY c.table_name, c.ordinal_position", [], $db);
        foreach ($rows as $r) {
            if (! self::secretTable($r['table_name'])) {
                $out[$r['table_name']]['columns'][$r['column_name']] = $r['data_type'];
            }
        }
        $counts = array_column(DB::select("SELECT relname, GREATEST(reltuples, 0)::bigint AS n FROM pg_class WHERE relkind = 'r' AND relnamespace = 'public'::regnamespace", [], $db), 'n', 'relname');
        foreach ($out as $name => $t) {
            $out[$name]['rows'] = (int) ($counts[$name] ?? 0);
        }

        return $out;
    }

    /** The settings row of a table, created (closed) on first use. */
    public static function entry(string $db, string $table): array
    {
        $row = DB::first('SELECT * FROM gsheet_tables WHERE db = ? AND table_name = ?', [$db, $table]);
        if ($row) {
            return $row;
        }
        DB::exec('INSERT INTO gsheet_tables (db, table_name) VALUES (?, ?) ON CONFLICT DO NOTHING', [$db, $table]);

        return DB::first('SELECT * FROM gsheet_tables WHERE db = ? AND table_name = ?', [$db, $table]);
    }

    public static function label(array $t): string
    {
        return (string) ($t['label'] ?: ucfirst(str_replace('_', ' ', (string) $t['table_name'])));
    }

    /** @return list<string> */
    public static function hidden(array $t): array
    {
        return array_values(array_filter((array) json_decode((string) $t['hidden_columns'], true), 'is_string'));
    }

    /** Opened tables this person may sync (administrators: every opened table). @return list<array> */
    public static function allowed(CurrentUser $u): array
    {
        $dbs = array_keys(self::databases());
        if (! $dbs) {
            return [];
        }
        $in = implode(',', array_fill(0, count($dbs), '?'));
        if ($u->isAdmin()) {
            return DB::select("SELECT * FROM gsheet_tables WHERE enabled AND db IN ($in) ORDER BY db, coalesce(label, table_name)", $dbs);
        }

        return DB::select("SELECT t.* FROM gsheet_tables t WHERE t.enabled AND t.db IN ($in) AND EXISTS (
            SELECT 1 FROM gsheet_table_access a WHERE a.table_id = t.id AND (a.user_id = ? OR a.role_id = ?)) ORDER BY t.db, coalesce(t.label, t.table_name)",
            [...$dbs, $u->id, $u->role_id]);
    }

    public static function mayUse(int $tableId, int $userId): bool
    {
        $u = DB::first('SELECT u.id, u.role_id, r.slug FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.is_active', [$userId]);
        $t = DB::first('SELECT * FROM gsheet_tables WHERE id = ?', [$tableId]);
        if (! $u || ! $t || ! filter_var($t['enabled'], FILTER_VALIDATE_BOOL) || ! isset(self::databases()[$t['db']])) {
            return false;
        }

        return $u['slug'] === 'admin' || (bool) DB::scalar('SELECT 1 FROM gsheet_table_access WHERE table_id = ? AND (user_id = ? OR role_id = ?)', [$tableId, $userId, $u['role_id']]);
    }

    /** @return array{roles: list<int>, users: list<int>} */
    public static function access(int $tableId): array
    {
        $rows = DB::select('SELECT role_id, user_id FROM gsheet_table_access WHERE table_id = ?', [$tableId]);

        return ['roles' => array_map('intval', array_filter(array_column($rows, 'role_id'))), 'users' => array_map('intval', array_filter(array_column($rows, 'user_id')))];
    }

    /** Columns that are sent: the table's columns minus secret and hidden ones. @return array<string,string> name → type */
    public static function columns(array $t): array
    {
        $cols = self::tables((string) $t['db'])[(string) $t['table_name']]['columns'] ?? null;
        if ($cols === null) {
            throw new \RuntimeException(__('The table :t does not exist any more.', ['t' => $t['table_name']]));
        }
        $hidden = array_flip(self::hidden($t));

        return array_filter($cols, fn ($type, $name) => ! isset($hidden[$name]) && ! self::secretColumn($name), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Header + rows ready for Sheets (numbers as numbers, booleans as TRUE/FALSE, the rest as text).
     * Soft-deleted rows (deleted_at set) are left out.
     * @return array{header: list<string>, rows: list<list<mixed>>, truncated: bool}
     */
    public static function read(array $t, int $limit): array
    {
        $cols = self::columns($t);
        if (! $cols) {
            throw new \RuntimeException(__('Every column of this table is hidden.'));
        }
        $q = fn (string $id) => '"'.str_replace('"', '""', $id).'"';
        $all = self::tables((string) $t['db'])[(string) $t['table_name']]['columns'];
        $sql = 'SELECT '.implode(', ', array_map($q, array_keys($cols))).' FROM '.$q((string) $t['table_name'])
            .(isset($all['deleted_at']) ? ' WHERE deleted_at IS NULL' : '')
            .' ORDER BY '.(isset($all['id']) ? '"id"' : '1').' LIMIT '.($limit + 1);
        $rows = DB::select($sql, [], (string) $t['db']);
        $truncated = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $numeric = ['smallint', 'integer', 'bigint', 'numeric', 'real', 'double precision', 'decimal'];
        $out = [];
        foreach ($rows as $r) {
            $line = [];
            foreach ($cols as $name => $type) {
                $v = $r[$name];
                $line[] = match (true) {
                    $v === null => '',
                    $type === 'boolean' => filter_var($v, FILTER_VALIDATE_BOOL),
                    in_array($type, $numeric, true) && is_numeric($v) => $v + 0,
                    default => mb_substr((string) $v, 0, 50000),          // Sheets cell limit
                };
            }
            $out[] = $line;
        }

        return ['header' => array_keys($cols), 'rows' => $out, 'truncated' => $truncated];
    }
}
