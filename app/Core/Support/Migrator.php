<?php
declare(strict_types=1);

namespace App\Core\Support;

/**
 * Runs database/sql/migrations/<db>/*.sql once each, in name order,
 * and remembers them in that database's schema_migrations table.
 * Called by the installer and automatically on the first request after an update.
 */
final class Migrator
{
    /** @var array<string,\Throwable> databases whose migrations failed in the last isolated run */
    private static array $failed = [];

    public static function dir(): string { return BASE_PATH.'/database/sql/migrations'; }

    /** Fingerprint of all migration files; changes when you drop in new ones. */
    public static function fingerprint(): string
    {
        return md5(implode('|', array_map('basename', glob(self::dir().'/*/*.sql') ?: [])));
    }

    /**
     * Cheap check on every request; real work only when files changed. A module whose migrations fail
     * is fenced off (see Modules) and retried a minute later; only a core failure stops the app.
     */
    public static function autoRun(): void
    {
        $stamp = BASE_PATH.'/storage/cache/schema.version';
        $fp    = self::fingerprint();
        $saved = is_file($stamp) ? trim((string) file_get_contents($stamp)) : '';
        if ($saved === $fp || (str_starts_with($saved, $fp.'|retry|') && time() - (int) substr($saved, strlen($fp) + 7) < 60)) {
            return;
        }
        self::run(null, true);
        @file_put_contents($stamp, self::$failed ? $fp.'|retry|'.time() : $fp);
    }

    /**
     * @param bool $isolate true: a failing database is recorded against its module and the others still run (core still throws)
     * @return string[] names of migrations that ran now
     */
    public static function run(?callable $connect = null, bool $isolate = false): array
    {
        $ran = [];
        self::$failed = [];
        foreach (glob(self::dir().'/*', GLOB_ONLYDIR) ?: [] as $dbDir) {
            $db = basename($dbDir);
            try {
                $pdo = $connect ? $connect($db) : DB::connection($db);
                $pdo->exec('SET statement_timeout = 0');          // a large upgrade may take long; the limit returns below
                $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(190) PRIMARY KEY, ran_at TIMESTAMPTZ NOT NULL DEFAULT now())');
                $done = $pdo->query('SELECT name FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN);

                $files = glob($dbDir.'/*.sql') ?: [];
                sort($files);
                foreach ($files as $file) {
                    $name = basename($file);
                    if (in_array($name, $done, true)) {
                        continue;
                    }
                    try {
                        $pdo->exec((string) file_get_contents($file));
                    } catch (\Throwable $e) {
                        throw new \RuntimeException("Migration {$db}/{$name} failed: ".$e->getMessage(), 0, $e);
                    }
                    $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
                    $ran[] = "{$db}/{$name}";
                }
                if (! $connect) {
                    $pdo->exec(DB::timeoutSql());
                }
                if ($isolate) {
                    Modules::migrations($db, null);
                }
            } catch (\Throwable $e) {
                if (! $isolate || $db === 'core') {
                    throw $e;
                }
                self::$failed[$db] = $e;
                Modules::migrations($db, $e);
            }
        }

        return $ran;
    }
}
