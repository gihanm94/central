<?php
declare(strict_types=1);

namespace App\Core\Api;

use App\Core\Api\Controllers\AuthController;
use App\Core\Api\Controllers\MeController;
use App\Core\Auth\Auth;
use App\Core\Support\DB;
use App\Core\Support\HttpException;
use App\Core\Support\I18n;
use App\Core\Support\Migrator;
use App\Core\Support\Modules;
use App\Core\Support\Request;
use App\Core\Support\Router;
use App\Core\Support\ValidationException;

/**
 * Front door of /api/v1. No PHP session and no cookies: every request but sign-in carries
 * "Authorization: Bearer <access token>" (see Tokens). Answers are JSON; errors are
 * {"error": {"code", "message", "fields"?}} without internals. Every request is written to api_log.
 *
 * Settings (config/config.php → 'api'): enabled, require_https, access_minutes, refresh_days,
 * max_days, rate_per_minute, login_per_minute.
 */
final class Kernel
{
    /** While true, json_response() and redirect() throw Captured instead of answering (see delegate()). */
    public static bool $capturing = false;

    private const PUBLIC = ['POST /api/v1/auth/login', 'POST /api/v1/auth/refresh'];
    private const MAX_BODY = 12 * 1024 * 1024;

    private static ?array $device = null;
    private static string $requestId = '';

    public static function device(): ?array { return self::$device; }

    public static function handle(string $path): never
    {
        $started = hrtime(true);
        self::$requestId = bin2hex(random_bytes(8));
        $_SERVER['HTTP_ACCEPT'] = 'application/json';      // web actions the API delegates to answer in JSON (also for multipart uploads)
        $method = Request::method();
        $status = 200;
        $code = null;
        $body = null;
        $file = null;
        $headers = [];

        try {
            if (! config('api.enabled', true)) {
                throw new ApiException(404, 'not_found', 'Not found.');
            }
            if (config('api.require_https', true) && config('app.env') !== 'local' && ! is_https()) {
                throw new ApiException(403, 'https_required', 'The API only answers over HTTPS.');
            }
            if (! in_array($method, ['GET', 'POST'], true)) {
                throw new ApiException(405, 'method_not_allowed', 'Use GET or POST.', ['Allow' => 'GET, POST']);
            }
            if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY) {
                throw new ApiException(413, 'too_large', 'The request is too large.');
            }
            if ($method === 'POST') {
                $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
                if (! str_starts_with($type, 'application/json') && ! str_starts_with($type, 'multipart/form-data')) {
                    throw new ApiException(415, 'unsupported_media_type', 'Send JSON (application/json) or multipart/form-data.');
                }
                if (str_starts_with($type, 'application/json') && trim((string) file_get_contents('php://input')) !== '' && ! is_array(json_decode((string) file_get_contents('php://input'), true))) {
                    throw new ApiException(400, 'invalid_json', 'The body is not valid JSON.');
                }
            }

            Migrator::autoRun();
            $public = in_array($method.' '.$path, self::PUBLIC, true);
            if ($public) {
                RateLimit::hit('ip:'.Request::ip(), max(3, (int) config('api.login_per_minute', 10)));
            } else {
                [$user, self::$device] = Tokens::authenticate(self::authorizationHeader());
                Auth::actAs($user);
                RateLimit::hit('dev:'.self::$device['id'], max(10, (int) config('api.rate_per_minute', 120)));
            }
            I18n::set(I18n::resolve(Auth::user()?->locale ?? self::language()));

            $router = new Router(csrf: false);
            self::routes($router);
            Modules::api($router, $path);
            $result = $router->dispatch($method, $path);

            if ($result instanceof File) {
                $file = $result;
            } else {
                $body = is_array($result) ? $result : ['ok' => true];
                $status = (int) ($body['_status'] ?? 200);
                unset($body['_status']);
            }
        } catch (ApiException $e) {
            [$status, $code, $headers] = [$e->status, $e->error, $e->headers];
            $body = ['error' => array_filter(['code' => $e->error, 'message' => $e->getMessage(), 'fields' => $e->fields ?: null])];
        } catch (ValidationException $e) {
            [$status, $code] = [422, 'invalid'];
            $body = ['error' => ['code' => 'invalid', 'message' => $e->getMessage(), 'fields' => $e->errors]];
        } catch (HttpException $e) {
            $status = $e->status;
            $code = [401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found', 405 => 'method_not_allowed', 419 => 'invalid', 503 => 'unavailable'][$e->status] ?? 'error';
            $body = ['error' => ['code' => $code, 'message' => $e->getMessage() ?: ($status === 404 ? 'Not found.' : 'Error '.$status)]];
        } catch (\Throwable $e) {
            error_log('[api '.self::$requestId.'] '.$e);
            [$status, $code] = [500, 'server_error'];
            $body = ['error' => ['code' => 'server_error', 'message' => 'Something went wrong. Quote request '.self::$requestId.' to your administrator.']];
        } finally {
            Request::replace(null);
            self::$capturing = false;
        }

        self::log($method, $path, $file ? 200 : $status, $code, $started);
        self::send($status, $body, $file, $headers);
    }

    /** Core endpoints; modules add theirs from their own api.php. */
    private static function routes(Router $r): void
    {
        $r->post('/api/v1/auth/login', [AuthController::class, 'login']);
        $r->post('/api/v1/auth/refresh', [AuthController::class, 'refresh']);
        $r->post('/api/v1/auth/logout', [AuthController::class, 'logout']);
        $r->get('/api/v1/me', [MeController::class, 'me']);
        $r->get('/api/v1/me/devices', [MeController::class, 'devices']);
        $r->post('/api/v1/me/devices/{id}/revoke', [MeController::class, 'revoke']);
        $r->get('/api/v1/me/notifications', [MeController::class, 'notifications']);
    }

    /**
     * Calls a web action that answers with json_response() and returns that answer, with
     * Request::all() limited to $input. Used where the web already has the full save logic
     * (validation, owner, visibility, notifications, activity log), so mobile follows the same rules.
     */
    public static function delegate(callable $action, array $input, bool $redirectMeansDone = false): array
    {
        Request::replace($input);
        self::$capturing = true;
        try {
            $action();
        } catch (Captured $c) {
            if (isset($c->data['redirect'])) {
                if ($redirectMeansDone) {                       // actions that answer success with a redirect (errors are exceptions)
                    return ['ok' => true];
                }
                throw new \LogicException('Delegated action redirected to '.$c->data['redirect']);
            }

            return $c->data + ['_status' => $c->status === 200 ? 201 : $c->status];
        } finally {
            self::$capturing = false;
            Request::replace(null);
        }

        throw new \LogicException('Delegated action gave no answer.');
    }

    private static function authorizationHeader(): ?string
    {
        return $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? null) : null);
    }

    private static function language(): ?string
    {
        return preg_match('/^(th|en)\b/i', (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), $m) ? strtolower($m[1]) : null;
    }

    private static function log(string $method, string $path, int $status, ?string $code, int|float $started): void
    {
        try {
            DB::insert('api_log', ['request_id' => self::$requestId, 'device_id' => self::$device['id'] ?? null, 'user_id' => Auth::user()?->id, 'method' => $method,
                'path' => mb_substr($path, 0, 255), 'status' => $status, 'ms' => (int) ((hrtime(true) - $started) / 1e6), 'ip' => Request::ip(), 'error' => $code], 'core', '');
            if (random_int(1, 1000) === 1) {
                DB::exec("DELETE FROM api_log WHERE at < now() - interval '90 days'");
            }
        } catch (\Throwable $e) {
            error_log('[api] log: '.$e->getMessage());
        }
    }

    private static function send(int $status, ?array $body, ?File $file, array $headers): never
    {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header_remove('X-Powered-By');
        header('X-Request-Id: '.self::$requestId);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        if (is_https()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
        foreach ($headers as $k => $v) {
            header($k.': '.$v);
        }
        if ($file) {
            http_response_code(200);
            header('Content-Type: '.$file->mime);
            header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '_', $file->name).'"');
            header('Content-Length: '.filesize($file->path));
            readfile($file->path);
            exit;
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}
