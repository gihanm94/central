<?php
declare(strict_types=1);

/*
 | Front controller. Run locally with:
 |   php -S localhost:8000 -t public public/index.php
 */

// Built-in server: let real files (css, js, uploads) through.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

// /api/... is the mobile/API front door: no PHP session, no cookies (see App\Core\Api\Kernel).
$apiPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$apiBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($apiBase !== '' && str_starts_with($apiPath, $apiBase)) {
    $apiPath = substr($apiPath, strlen($apiBase));
}
if (str_starts_with($apiPath, '/api/')) {
    define('API_REQUEST', true);
}

// Not installed yet → installer.
if (! is_file(dirname(__DIR__).'/storage/installed.lock')) {
    if (defined('API_REQUEST')) {
        http_response_code(503);
        header('Content-Type: application/json');
        exit('{"error":{"code":"not_installed","message":"The system is not installed yet."}}');
    }
    header('Location: '.rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/').'/install.php');
    exit;
}

require dirname(__DIR__).'/app/bootstrap.php';

if (defined('API_REQUEST')) {
    App\Core\Api\Kernel::handle(App\Core\Support\Request::path());
}

use App\Core\Auth\Auth;
use App\Core\Support\HttpException;
use App\Core\Support\I18n;
use App\Core\Support\Migrator;
use App\Core\Support\Modules;
use App\Core\Support\Request;
use App\Core\Support\Router;
use App\Core\Support\Session;
use App\Core\Support\Settings;
use App\Core\Support\ValidationException;
use App\Core\Support\View;

$path = Request::path();

// Missing static files (favicon.ico …) should not eat flash messages.
if (preg_match('/\.[a-z0-9]{2,5}$/i', $path)) {
    http_response_code(404);
    exit('Not found');
}

Session::ageFlash();
header_remove('X-Powered-By');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cross-Origin-Opener-Policy: same-origin');
header('Permissions-Policy: geolocation=(), microphone=(), payment=(), usb=(), camera=(self)');
if (is_https()) {
    header('Strict-Transport-Security: max-age=31536000');
}

try {
    Migrator::autoRun();                       // applies new database/sql/migrations after an update (a failing module is fenced off, not fatal)
    I18n::set(I18n::resolve(Auth::user()?->locale));
    View::share('branding', Settings::branding());

    $router = new Router();
    require BASE_PATH.'/routes/web.php';
    if ($module = Modules::forPath($path)) {
        Modules::hook('request', [], $module);  // e.g. Accounting starts its ERP schedule; errors are logged, never shown
    }
    $router->dispatch(Request::method(), $path);
} catch (ValidationException $e) {
    if (Request::isJson()) {
        json_response(['message' => (string) reset($e->errors), 'errors' => array_map(fn ($m) => [$m], $e->errors)], 422);
    }
    $old = Request::all();
    foreach (['password', 'password_confirmation', 'current_password', '_token'] as $secret) {
        unset($old[$secret]);
    }
    Session::flash('_errors', $e->errors);
    Session::flash('_old', $old);
    back();
} catch (HttpException $e) {
    if (Request::isJson()) {
        http_response_code($e->status);
        json_response(['message' => $e->getMessage() ?: 'Error '.$e->status], $e->status);
    }
    // A form that was refused (no right, gone, expired, too many) goes back to the form with the reason as a toast.
    if (Request::method() === 'POST' && in_array($e->status, [403, 404, 405, 409, 419, 422, 429], true) && Auth::id() !== null) {
        $msg = $e->getMessage() ?: ([403 => __("You don't have access to this page"), 404 => __("We couldn't find that page"), 419 => __('Your session expired. Try again.'), 429 => __('Too many requests. Wait a moment and try again.')][$e->status] ?? __('Error :code.', ['code' => $e->status]));
        back('error', $msg);
    }
    http_response_code($e->status);
    echo View::render('errors/http', ['status' => $e->status, 'message' => $e->getMessage(), 'title' => 'Error '.$e->status]);
} catch (Throwable $e) {
    // Full details with a reference code, so the error can be found in Administrator → System logs.
    $ref = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
    $who = Auth::id() !== null ? 'user #'.Auth::id() : 'guest';
    error_log("[ref {$ref}] ".Request::method().' '.Request::path()." · {$who} · ".Request::ip()."\n".$e);
    if ($module = Modules::forPath(Request::path())) {
        Modules::hook('error', [$e], $module);   // the module may keep its own log too
    }
    if (Request::isJson()) {
        http_response_code(500);
        json_response(['message' => __('Something went wrong. The error was logged (reference :ref).', ['ref' => $ref]), 'reference' => $ref], 500);
    }
    if (Request::method() === 'POST' && Auth::id() !== null && ! headers_sent()) {
        back('error', __('Something went wrong and nothing was saved. The error was logged (reference :ref).', ['ref' => $ref]));
    }
    http_response_code(500);
    if (config('app.env') === 'local') {
        echo '<pre style="padding:2rem;white-space:pre-wrap;font:13px/1.5 monospace">'.e("Reference {$ref}\n".get_class($e).': '.$e->getMessage()."\n\n".$e->getTraceAsString()).'</pre>';
    } else {
        echo View::render('errors/http', ['status' => 500, 'message' => __('Something went wrong. The error was logged (reference :ref).', ['ref' => $ref]), 'title' => 'Error', 'ref' => $ref]);
    }
}
