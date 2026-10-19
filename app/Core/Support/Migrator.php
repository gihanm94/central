<?php
declare(strict_types=1);

namespace App\Core\Support;

/**
 * Database upgrades, each SQL file run once in name order and remembered in that database's schema_migrations:
 *  - database/sql/migrations/<db>/*.sql      core and the older modules (folder name = database connection)
 *  - app/Modules/<Name>/migrations/*.sql     a module's own upgrades, run on the database its manifest names ('db');
 *                                            remembered as "<key>/<file>"
 * A failing module upgrade fences off only that module (retried a minute later); only a core failure stops the app.
 * Called by the installer and automatically on the first request after an update.
 */
final class Migrator
{
    /** @var array<string,\Throwable> what failed in the last isolated run */
    private static array $failed = [];

    public static function dir(): string { return BASE_PATH.'/database/sql/migrations'; }

    /**
     * Every set of upgrades: [owner, database connection, files, module key or null].
     * @return list<array{0:string,1:string,2:list<string>,3:?string}>
     */
    private static function sets(): array
    {
        $sets = [];
        foreach (glob(self::dir().'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $files = glob($dir.'/*.sql') ?: [];
            sort($files);
            $sets[] = [basename($dir), basename($dir), $files, null];
        }
        foreach (glob(BASE_PATH.'/app/Modules/*/migrations', GLOB_ONLYDIR) ?: [] as $dir) {
            $moduleDir = dirname($dir);
            if (str_starts_with(basename($moduleDir), '_')) {
                continue;
            }
            try {
                $m = require $moduleDir.'/module.php';
            } catch (\Throwable) {
                continue;                                   // a broken manifest is reported by Modules::boot()
            }
            if (! is_array($m) || ! is_string($m['key'] ?? null)) {
                continue;
            }
            $files = glob($dir.'/*.sql') ?: [];
            sort($files);
            $sets[] = [$m['key'], is_string($m['db'] ?? null) ? $m['db'] : $m['key'], $files, $m['key']];
        }

        return $sets;
    }

    /** Fingerprint of all migration files; changes when you drop in new ones. */
    public static function fingerprint(): string
    {
        $names = [];
        foreach (self::sets() as [$owner, , $files]) {
            foreach ($files as $f) {
                $names[] = $owner.'/'.basename($f);
            }
        }

        return md5(implode('|', $names));
    }

    /**
     * Cheap check on every request; real work only when files changed. Only one request upgrades at a time;
     * the others carry on meanwhile.
     */
    public static function autoRun(): void
    {
        $stamp = BASE_PATH.'/storage/cache/schema.version';
        $fp    = self::fingerprint();
        $saved = is_file($stamp) ? trim((string) file_get_contents($stamp)) : '';
        if ($saved === $fp || (str_starts_with($saved, $fp.'|retry|') && time() - (int) substr($saved, strlen($fp) + 7) < 60)) {
            return;
        }
        $lock = @fopen(BASE_PATH.'/storage/cache/migrate.lock', 'c');
        if ($lock && ! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return;                                         // another request is upgrading right now
        }
        try {
            self::run(null, true);
            @file_put_contents($stamp, self::$failed ? $fp.'|retry|'.time() : $fp);
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * @param bool $isolate true: a failing module is recorded against it and the others still run (core still throws)
     * @return string[] names of migrations that ran now
     */
    public static function run(?callable $connect = null, bool $isolate = false): array
    {
        $ran = [];
        self::$failed = [];
        foreach (self::sets() as [$owner, $db, $files, $module]) {
            try {
                $pdo = $connect ? $connect($db) : DB::connection($db);
                $pdo->exec('SET statement_timeout = 0');          // a large upgrade may take long; the limit returns below
                $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(190) PRIMARY KEY, ran_at TIMESTAMPTZ NOT NULL DEFAULT now())');
                $done = $pdo->query('SELECT name FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN);

                foreach ($files as $file) {
                    $name = ($module ? $module.'/' : '').basename($file);
                    if (in_array($name, $done, true)) {
                        continue;
                    }
                    try {
                        $pdo->exec((string) file_get_contents($file));
                    } catch (\Throwable $e) {
                        throw new \RuntimeException("Migration {$owner}/".basename($file)." failed: ".$e->getMessage(), 0, $e);
                    }
                    $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
                    $ran[] = "{$owner}/".basename($file);
                }
                if (! $connect) {
                    $pdo->exec(DB::timeoutSql());
                }
                if ($isolate && ($module || $db !== 'core')) {
                    $module ? Modules::ok($module, 'migrations') : Modules::migrations($db, null);
                }
            } catch (\Throwable $e) {
                if (! $isolate || ($module === null && $db === 'core')) {
                    throw $e;
                }
                self::$failed[$owner] = $e;
                $module ? Modules::fail($module, 'migrations', $e) : Modules::migrations($db, $e);
            }
        }

        return $ran;
    }
}
