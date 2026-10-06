<?php
declare(strict_types=1);

namespace App\Core\Support;

/** Flash messages, old input, validation errors and the CSRF token. */
final class Session
{
    public static function csrf(): string
    {
        return $_SESSION['_token'] ??= bin2hex(random_bytes(32));
    }

    public static function verifyCsrf(?string $token): bool
    {
        return is_string($token) && isset($_SESSION['_token']) && hash_equals($_SESSION['_token'], $token);
    }

    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash_next'][$key] = $value;
    }

    /** Called once per request: last request's flash becomes readable, then cleared. */
    public static function ageFlash(): void
    {
        $_SESSION['_flash'] = $_SESSION['_flash_next'] ?? [];
        unset($_SESSION['_flash_next']);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_flash'][$key] ?? $default;
    }

    public static function old(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_flash']['_old'][$key] ?? $default;
    }

    public static function errors(): array
    {
        return $_SESSION['_flash']['_errors'] ?? [];
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
        unset($_SESSION['_token']);
    }
}
