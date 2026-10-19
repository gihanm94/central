<?php
declare(strict_types=1);

namespace App\Core\Support;

use PDO;

/**
 * Rebuilds config/config.php when the system is installed but the file is gone (it is not in git, so a fresh
 * clone or a pull that predates the change has none). Starts from config/config.php.example and finds the
 * existing database by itself:
 *   1. DB_HOST / DB_PORT / DB_USERNAME / DB_PASSWORD environment variables, if set
 *   2. the template's login, the login of the account running PHP (Homebrew / Postgres.app: no password), postgres
 * A login counts only if it opens the core database and finds the installed tables in it, so nothing a visitor
 * sends is ever used here. When nothing works, public/install.php asks for the login (on this computer only).
 */
final class AutoConfig
{
    public static function ensure(): void
    {
        if (is_file(BASE_PATH.'/config/config.php') || ! is_file(BASE_PATH.'/storage/installed.lock')) {
            return;
        }
        $config = require BASE_PATH.'/config/config.php.example';
        foreach (self::candidates($config) as $login) {
            if (self::try($config, $login) === null) {
                self::write($config, $login);

                return;                                     // carry on with the new file
            }
        }
        self::fail();
    }

    /** @return list<array{host:string,port:int,username:string,password:string}> */
    private static function candidates(array $config): array
    {
        $host = (string) (getenv('DB_HOST') ?: $config['db']['host']);
        $port = (int) (getenv('DB_PORT') ?: $config['db']['port']);
        $list = [];
        if (getenv('DB_USERNAME') !== false) {
            $list[] = [(string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD')];
        }
        $list[] = [(string) $config['db']['username'], (string) $config['db']['password']];
        $me = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : get_current_user();
        if ($me !== '' && $me !== 'root') {
            $list[] = [$me, ''];
        }
        $list[] = ['postgres', ''];
        $out = [];
        foreach ($list as [$user, $pass]) {
            $out[$user."\0".$pass] = ['host' => $host, 'port' => $port, 'username' => $user, 'password' => $pass];
        }

        return array_values($out);
    }

    /** null when this login opens the installed core database, otherwise why not. */
    public static function try(array $config, array $login): ?string
    {
        if ($login['username'] === '' || ! preg_match('/^[A-Za-z0-9.\-:]+$/', $login['host'])) {
            return 'Enter the host and user name.';
        }
        try {
            $pdo = new PDO("pgsql:host={$login['host']};port={$login['port']};dbname={$config['db']['databases']['core']};connect_timeout=2",
                $login['username'], $login['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->query('SELECT 1 FROM users LIMIT 1');

            return null;
        } catch (\PDOException $e) {
            return $e->getMessage();
        }
    }

    public static function write(array $config, array $login): void
    {
        $config['db'] = array_merge($config['db'], $login);
        $config['app']['key'] = bin2hex(random_bytes(32));
        // The site address comes from the Host header only on this computer (a visitor could send any Host);
        // everywhere else the system starts in production mode and app.url / app.hosts are set by hand.
        $local = PHP_SAPI !== 'cli' && in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)
            && preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', (string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($local) {
            $config['app']['url'] = 'http://'.$_SERVER['HTTP_HOST'];
        }
        $config['app']['env'] = $local ? 'local' : 'production';
        $file = BASE_PATH.'/config/config.php';
        if (@file_put_contents($file, "<?php\n// Rebuilt automatically on ".date('Y-m-d H:i')." (the file was missing) — edit freely. Holds passwords: never put it in git.\nreturn ".var_export($config, true).";\n", LOCK_EX) === false) {
            throw new \RuntimeException('Found the database, but config/ is not writable.');
        }
        @chmod($file, 0640);
        error_log('[autoconfig] config/config.php was missing; rebuilt it for database user "'.$login['username'].'"');
    }

    /** No login worked: the installer's "Reconnect database" form takes over (on this computer only). */
    private static function fail(): never
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "config/config.php is missing and no database login worked. Set DB_USERNAME / DB_PASSWORD (and DB_HOST) and run again, or open /install.php on this computer.\n");
            exit(1);
        }
        http_response_code(503);
        if (defined('API_REQUEST')) {
            header('Content-Type: application/json');
            exit('{"error":{"code":"not_configured","message":"The server is not configured."}}');
        }
        header('Location: '.rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/').'/install.php', true, 302);
        exit;
    }
}
