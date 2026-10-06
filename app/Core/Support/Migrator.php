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
    public static function dir(): string { return BASE_PATH.'/database/sql/migrations'; }

    /** Fingerprint of all migration files; changes when you drop in new ones. */
    public static function fingerprint(): string
    {
        return md5(implode('|', array_map('basename', glob(self::dir().'/*/*.sql') ?: [])));
    }

    /** Cheap check on every request; real work only when files changed. */
    public static function autoRun(): void
    {
        $stamp = BASE_PATH.'/storage/cache/schema.version';
        $fp    = self::fingerprint();
        if (is_file($stamp) && trim((string) file_get_contents($stamp)) === $fp) {
            return;
        }
        self::run();
        @file_put_contents($stamp, $fp);
    }

    /** @return string[] names of migrations that ran now */
    public static function run(?callable $connect = null): array
    {
        $ran = [];
        foreach (glob(self::dir().'/*', GLOB_ONLYDIR) ?: [] as $dbDir) {
            $db  = basename($dbDir);
            $pdo = $connect ? $connect($db) : DB::connection($db);
            $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(190) PRIMARY KEY, ran_at TIMESTAMPTZ NOT NULL DEFAULT now())');
            $done = $pdo->query('SELECT name FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN);

            $files = glob($dbDir.'/*.sql') ?: [];
            sort($files);
            foreach ($files as $file) {
                $name = basename($file);
                if (in_array($name, $done, true)) {
                    continue;
                }
                $pdo->exec((string) file_get_contents($file));
                $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
                $ran[] = "{$db}/{$name}";
            }
        }

        return $ran;
    }
}
