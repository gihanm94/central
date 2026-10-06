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

// Not installed yet → installer.
if (! is_file(dirname(__DIR__).'/storage/installed.lock')) {
    header('Location: '.rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/').'/install.php');
    exit;
}

require dirname(__DIR__).'/app/bootstrap.php';

use App\Core\Auth\Auth;
use App\Core\Support\HttpException;
use App\Core\Support\I18n;
use App\Core\Support\Migrator;
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
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

try {
    Migrator::autoRun();                       // applies new database/sql/migrations after an update
    I18n::set(I18n::resolve(Auth::user()?->locale));
    View::share('branding', Settings::branding());

    $router = new Router();
    require BASE_PATH.'/routes/web.php';
    if (Request::method() === 'GET' && str_starts_with($path, '/accounting')) {
        try { \App\Modules\Accounting\Erp\Schedule::kick(); } catch (Throwable) { /* the schedule must never break a page */ }
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
    http_response_code($e->status);
    if (Request::isJson()) {
        json_response(['message' => $e->getMessage() ?: 'Error '.$e->status], $e->status);
    }
    echo View::render('errors/http', ['status' => $e->status, 'message' => $e->getMessage(), 'title' => 'Error '.$e->status]);
} catch (Throwable $e) {
    error_log((string) $e);
    if (str_starts_with(Request::path(), '/accounting')) { \App\Modules\Accounting\Support\Log::exception('app', $e, 'Uncaught on '.Request::method().' '.Request::path()); }
    http_response_code(500);
    if (config('app.env') === 'local') {
        echo '<pre style="padding:2rem;white-space:pre-wrap;font:13px/1.5 monospace">'.e(get_class($e).': '.$e->getMessage()."\n\n".$e->getTraceAsString()).'</pre>';
    } else {
        echo View::render('errors/http', ['status' => 500, 'message' => 'Something went wrong. The error was logged.', 'title' => 'Error']);
    }
}
