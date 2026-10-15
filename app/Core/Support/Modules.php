<?php
declare(strict_types=1);

namespace App\Core\Support;

/**
 * Module registry. Every folder app/Modules/<Name>/ with a module.php is a module:
 * the manifest gives its name, menu, dashboards, database and hooks (see app/Modules/README.md).
 * config/modules.php keeps the core entry and can override any manifest key (e.g. 'enabled' => false).
 *
 * A module that fails (broken manifest, routes.php or migrations, or a hook that throws) is logged,
 * recorded in storage/cache/modules-status.json and fenced off; core and the other modules keep working.
 */
final class Modules
{
    /** Failures under these keys make the module's pages unavailable; hook failures are only reported. */
    private const BLOCKING = ['manifest', 'migrations', 'routes'];

    private static ?array $status = null;

    /** Reads the manifests into config('modules'). Called once from bootstrap.php. */
    public static function boot(): void
    {
        $all = (array) Config::get('modules', []);
        foreach (glob(BASE_PATH.'/app/Modules/*/module.php') ?: [] as $file) {
            $dir = dirname($file);
            if (str_starts_with(basename($dir), '_')) {
                continue;                                   // _Template and other starters are never loaded
            }
            $guess = strtolower(basename($dir));
            try {
                $m = require $file;
                if (! is_array($m) || ! is_string($m['key'] ?? null) || $m['key'] === '' || $m['key'] === 'core') {
                    throw new \UnexpectedValueException('module.php must return an array with a "key" (not "core").');
                }
            } catch (\Throwable $e) {
                self::fail($guess, 'manifest', $e);
                $all[$guess] = array_replace(self::defaults($guess, null, ['name' => basename($dir)]), $all[$guess] ?? []);
                continue;
            }
            self::ok($guess, 'manifest');
            $key = $m['key'];
            $all[$key] = array_replace(self::defaults($key, $dir, $m), $m, $all[$key] ?? []);
        }
        foreach ($all as $key => $m) {                      // placeholders in config (no folder yet) get the same shape
            $all[$key] = array_replace(self::defaults((string) $key, null, ['enabled' => false]), $m);
        }
        Config::set('modules', $all);
    }

    private static function defaults(string $key, ?string $dir, array $m): array
    {
        return ['name' => $m['name'] ?? ucfirst($key), 'icon' => 'grid', 'home' => '/'.$key, 'enabled' => $m['enabled'] ?? true,
            'db' => $key, 'dashboards' => [], 'menu' => [], 'hooks' => [], 'dir' => $dir];
    }

    /** @return array<string,array> every module, core first */
    public static function all(): array { return (array) Config::get('modules', []); }

    public static function enabled(string $key): bool { return (bool) (self::all()[$key]['enabled'] ?? false); }

    /** Enabled and not fenced off by a failure. */
    public static function usable(string $key): bool { return self::enabled($key) && ! self::problems($key); }

    /** The module a URL belongs to (/crm/leads → crm), or null for core pages. */
    public static function forPath(string $path): ?string
    {
        $segment = explode('/', trim($path, '/'))[0];

        return $segment !== 'core' && isset(self::all()[$segment]) ? $segment : null;
    }

    /**
     * Registers the routes of the module this request is for, and only that one: a mistake in one
     * module's routes.php cannot break any other page. Unavailable modules answer 503 on their URLs.
     */
    public static function routes(Router $r, string $path): void
    {
        $key = self::forPath($path);
        if ($key === null || ! self::enabled($key)) {
            return;
        }
        $file = (self::all()[$key]['dir'] ?? '').'/routes.php';
        $fenced = array_intersect_key(self::status()[$key] ?? [], ['manifest' => 1, 'migrations' => 1]);
        if (! $fenced && is_file($file)) {
            try {
                $r->attempt(function (Router $r) use ($file) { require $file; });
                self::ok($key, 'routes');

                return;
            } catch (\Throwable $e) {
                self::fail($key, 'routes', $e);
            }
        } elseif (! $fenced) {
            self::fail($key, 'routes', new \RuntimeException('No routes.php in the module folder.'));
        }
        $r->any('/'.$key, [\App\Core\Http\Controllers\ModuleController::class, 'unavailable']);
    }

    /**
     * API endpoints of the module named by /api/v1/<key>/…, from its api.php (only that module's file is loaded).
     * A module that is off, fenced or whose api.php fails answers 503 on its API URLs; the rest of the API keeps working.
     */
    public static function api(Router $r, string $path): void
    {
        if (! preg_match('#^/api/v1/([a-z0-9_]+)(/|$)#', $path, $m) || $m[1] === 'core' || ! isset(self::all()[$m[1]])) {
            return;
        }
        $key = $m[1];
        $file = (self::all()[$key]['dir'] ?? '').'/api.php';
        if (! self::enabled($key) || ! is_file($file)) {
            return;                                     // no API → plain 404
        }
        $fenced = array_intersect_key(self::status()[$key] ?? [], ['manifest' => 1, 'migrations' => 1]);
        if (! $fenced) {
            try {
                $r->attempt(function (Router $r) use ($file) { require $file; });
                self::ok($key, 'api');

                return;
            } catch (\Throwable $e) {
                self::fail($key, 'api', $e);
            }
        }
        $r->any('/api/v1/'.$key, [\App\Core\Api\Controllers\SystemController::class, 'unavailable']);
    }

    /**
     * Runs hook $name of every usable module (or only of $only) with $args. A hook that throws is logged
     * and skipped, so one module never stops the others' work.
     * @return array<string,mixed> results by module key
     */
    public static function hook(string $name, array $args = [], ?string $only = null): array
    {
        $out = [];
        foreach (self::all() as $key => $m) {
            $fn = $m['hooks'][$name] ?? null;
            if (($only !== null && $key !== $only) || ! is_callable($fn) || ! self::enabled($key)
                || array_intersect_key(self::status()[$key] ?? [], ['manifest' => 1, 'migrations' => 1])) {
                continue;
            }
            try {
                $out[$key] = $fn(...$args);
                self::ok($key, 'hook:'.$name);
            } catch (\Throwable $e) {
                self::fail($key, 'hook:'.$name, $e);
            }
        }

        return $out;
    }

    /** Called by the Migrator: migrations of database $db failed / succeeded. */
    public static function migrations(string $db, ?\Throwable $e): void
    {
        $hit = false;
        foreach (self::all() as $key => $m) {
            if ($key !== 'core' && ($m['db'] ?? $key) === $db) {
                $e ? self::fail($key, 'migrations', $e) : self::ok($key, 'migrations');
                $hit = true;
            }
        }
        if ($e && ! $hit) {
            error_log("[migrations:{$db}] ".$e);
        }
    }

    // ---- health status (storage/cache/modules-status.json) ----

    /** @return array<string,array<string,array{message:string,class:string,file:string,line:int,at:string}>> */
    public static function status(): array
    {
        if (self::$status === null) {
            $raw = @file_get_contents(self::statusFile());
            self::$status = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        }

        return self::$status;
    }

    /** Blocking failures of a module (manifest / migrations / routes), empty when it is healthy. */
    public static function problems(string $key): array
    {
        return array_intersect_key(self::status()[$key] ?? [], array_flip(self::BLOCKING));
    }

    public static function fail(string $key, string $where, \Throwable $e): void
    {
        error_log("[module:{$key}] {$where} failed: ".$e);
        $entry = ['message' => $e->getMessage(), 'class' => $e::class, 'file' => str_replace(BASE_PATH.'/', '', $e->getFile()), 'line' => $e->getLine()];
        $s = self::status();
        if (array_diff_key($s[$key][$where] ?? [], ['at' => 1]) === $entry) {
            return;                                         // same failure already recorded
        }
        $s[$key][$where] = $entry + ['at' => date('c')];
        self::save($s);
    }

    public static function ok(string $key, string $where): void
    {
        $s = self::status();
        if (! isset($s[$key][$where])) {
            return;
        }
        unset($s[$key][$where]);
        if (! $s[$key]) {
            unset($s[$key]);
        }
        self::save($s);
    }

    /** Forgets recorded failures (all, or one module's); the next request tries again. */
    public static function clear(?string $key = null): void
    {
        $s = self::status();
        if ($key === null) { $s = []; } else { unset($s[$key]); }
        self::save($s);
    }

    private static function statusFile(): string { return BASE_PATH.'/storage/cache/modules-status.json'; }

    private static function save(array $s): void
    {
        self::$status = $s;
        @file_put_contents(self::statusFile(), json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
