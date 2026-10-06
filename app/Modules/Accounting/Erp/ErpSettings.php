<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Erp;

use App\Core\Support\DB;

/** Connection settings of the ERP and the e-Tax portal: defaults from config/erp.php, changes saved in erp_settings. */
final class ErpSettings
{
    public const CONN = 'accounting';
    private static ?array $saved = null;

    public static function saved(): array
    {
        if (self::$saved === null) {
            try {
                self::$saved = array_column(DB::select('SELECT key, value FROM erp_settings', [], self::CONN), 'value', 'key');
            } catch (\Throwable) {
                self::$saved = [];
            }
        }

        return self::$saved;
    }

    public static function forget(): void { self::$saved = null; }

    public static function get(string $key, ?string $default = null): ?string
    {
        $v = self::saved()[$key] ?? null;

        return $v !== null && $v !== '' ? $v : $default;
    }

    public static function put(string $key, ?string $value, ?int $by = null): void
    {
        DB::exec('INSERT INTO erp_settings (key, value, updated_by, updated_at) VALUES (?, ?, ?, now())
                  ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_by = EXCLUDED.updated_by, updated_at = now()', [$key, $value, $by], self::CONN);
        self::$saved = null;
    }

    /** connection.* with defaults */
    public static function conn(string $key): string
    {
        return (string) self::get('conn.'.$key, (string) config('erp.connection.'.$key, ''));
    }

    public static function schedule(string $key): string
    {
        return (string) self::get('schedule.'.$key, (string) config('erp.schedule.'.$key, ''));
    }

    public static function password(): string { return (string) self::get('conn.password', ''); }

    public static function baseUrl(): string
    {
        $ip = trim(self::conn('ip'));
        if ($ip === '') {
            return '';
        }
        $port = trim(self::conn('port'));

        return (str_starts_with($ip, 'http') ? $ip : 'https://'.$ip).($port !== '' ? ':'.$port : '');
    }

    public static function endpoint(string $key): string
    {
        return (string) self::get('api.'.$key, (string) (config('erp.endpoints.'.$key)[0] ?? ''));
    }

    public static function inet(string $key): string
    {
        return (string) self::get('inet.'.$key, (string) config('erp.inet.'.$key, ''));
    }

    public static function configured(): bool
    {
        return self::baseUrl() !== '' && self::conn('username') !== '' && self::password() !== '';
    }
}
