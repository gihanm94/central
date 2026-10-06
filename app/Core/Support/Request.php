<?php
declare(strict_types=1);

namespace App\Core\Support;

final class Request
{
    private static ?array $json = null;

    public static function method(): string { return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'); }

    public static function path(): string
    {
        $uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        if ($script !== '/' && $script !== '.' && str_starts_with($uri, $script)) {
            $uri = substr($uri, strlen($script));
        }
        $uri = '/'.trim(preg_replace('#^/index\.php#', '', $uri), '/');

        return $uri === '' ? '/' : $uri;
    }

    public static function isJson(): bool
    {
        return str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    public static function all(): array
    {
        if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            return self::$json ??= (json_decode(file_get_contents('php://input') ?: '[]', true) ?: []);
        }

        return array_merge($_GET, $_POST);
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        $v = self::all()[$key] ?? $default;

        return is_string($v) ? trim($v) : $v;
    }

    public static function boolean(string $key): bool
    {
        return filter_var(self::input($key, false), FILTER_VALIDATE_BOOL);
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        $v = $_GET[$key] ?? $default;

        return is_string($v) ? trim($v) : $v;
    }

    public static function file(string $key): ?array
    {
        $f = $_FILES[$key] ?? null;

        return $f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $f : null;
    }

    public static function ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

    public static function userAgent(): string { return mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250); }

    public static function host(): string { return explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0]; }

    public static function origin(): string
    {
        return (is_https() ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
}
