<?php
/*
 | Boots the application: autoloader, config, errors, session.
 | No Composer needed — classes under App\ are loaded from /app.
 */
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_START', microtime(true));

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = BASE_PATH.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require BASE_PATH.'/app/helpers.php';

App\Core\Support\Config::load(BASE_PATH.'/config');

date_default_timezone_set((string) config('app.timezone', 'UTC'));

$local = config('app.env') === 'local';
error_reporting(E_ALL);
ini_set('display_errors', $local ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH.'/storage/logs/php-error.log');

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_name('acme_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}
