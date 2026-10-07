<?php
declare(strict_types=1);

namespace App\Core\Support;

/** Key/value company settings (name, logo, sign-in picture …), cached per request. */
final class Settings
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            try {
                self::$cache = array_column(DB::select('SELECT key, value FROM settings'), 'value', 'key');
            } catch (\Throwable) {
                self::$cache = [];
            }
        }

        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        DB::exec('INSERT INTO settings (key, value, updated_at) VALUES (?, ?, now())
                  ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()', [$key, $value]);
        self::$cache = null;
    }

    public static function branding(): array
    {
        $s = self::all();

        return [
            'name'        => $s['company_name'] ?? config('app.name'),
            'tagline'     => $s['login_tagline'] ?? 'Every department, one sign-in.',
            'logo'        => upload_url($s['company_logo'] ?? null),
            'login_image' => upload_url($s['login_image'] ?? null),
            'support'     => $s['support_email'] ?? null,
            'banner'      => trim((string) ($s['login_banner'] ?? $s['company_name'] ?? config('app.name'))),
            'auth_logo'   => ($s['auth_logo'] ?? '1') !== '0',
        ];
    }
}
