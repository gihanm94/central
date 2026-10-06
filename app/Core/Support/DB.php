<?php
declare(strict_types=1);

namespace App\Core\Support;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper. One connection per database:
 *   DB::select('SELECT …', [$id])              → core (user_db)
 *   DB::select('SELECT …', [], 'crm')           → crm_db
 */
final class DB
{
    /** @var PDO[] */
    private static array $connections = [];

    public static function connection(string $name = 'core'): PDO
    {
        if (! isset(self::$connections[$name])) {
            $c  = config('db');
            $db = $c['databases'][$name] ?? $name;
            self::$connections[$name] = self::connect($c['host'], (int) $c['port'], $db, $c['username'], $c['password']);
            // Timestamps typed in the app are in the company time zone, so PostgreSQL must read them the same way.
            if (preg_match('#^[A-Za-z0-9_+\-/]+$#', (string) config('app.timezone', ''))) {
                self::$connections[$name]->exec("SET TIME ZONE '".config('app.timezone')."'");
            }
        }

        return self::$connections[$name];
    }

    public static function connect(string $host, int $port, string $db, string $user, string $pass): PDO
    {
        return new PDO("pgsql:host={$host};port={$port};dbname={$db}", $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public static function statement(string $sql, array $params = [], string $conn = 'core'): PDOStatement
    {
        $stmt = self::connection($conn)->prepare($sql);
        $stmt->execute(array_map(self::normalise(...), $params));

        return $stmt;
    }

    public static function select(string $sql, array $params = [], string $conn = 'core'): array
    {
        return self::statement($sql, $params, $conn)->fetchAll();
    }

    public static function first(string $sql, array $params = [], string $conn = 'core'): ?array
    {
        return self::statement($sql, $params, $conn)->fetch() ?: null;
    }

    public static function scalar(string $sql, array $params = [], string $conn = 'core'): mixed
    {
        $v = self::statement($sql, $params, $conn)->fetchColumn();

        return $v === false ? null : $v;
    }

    public static function exec(string $sql, array $params = [], string $conn = 'core'): int
    {
        return self::statement($sql, $params, $conn)->rowCount();
    }

    public static function insert(string $table, array $data, string $conn = 'core', string $returning = 'id'): mixed
    {
        $cols = array_keys($data);
        $sql  = 'INSERT INTO '.$table.' ('.implode(', ', $cols).') VALUES ('.implode(', ', array_fill(0, count($cols), '?')).')'
              .($returning ? ' RETURNING '.$returning : '');

        return $returning ? self::scalar($sql, array_values($data), $conn) : self::exec($sql, array_values($data), $conn);
    }

    public static function update(string $table, array $data, array $where, string $conn = 'core'): int
    {
        $set = implode(', ', array_map(fn ($c) => "$c = ?", array_keys($data)));
        $w   = implode(' AND ', array_map(fn ($c) => "$c = ?", array_keys($where)));

        return self::exec("UPDATE {$table} SET {$set} WHERE {$w}", [...array_values($data), ...array_values($where)], $conn);
    }

    public static function transaction(callable $callback, string $conn = 'core'): mixed
    {
        $pdo = self::connection($conn);
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** PostgreSQL wants real booleans and NULLs, not '' */
    private static function normalise(mixed $v): mixed
    {
        return match (true) {
            is_bool($v)  => $v ? 't' : 'f',
            is_array($v) => json_encode($v, JSON_UNESCAPED_UNICODE),
            default      => $v,
        };
    }
}
