<?php
declare(strict_types=1);

namespace App\Core\Support;

/** Encrypts small secrets (OAuth tokens, client secrets) with AES-256-GCM; the key comes from app.key. */
final class Crypt
{
    private static function key(): string { return hash('sha256', 'acme-secret|'.(string) config('app.key'), true); }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $c = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        return 'v1.'.base64_encode($iv.$tag.$c);
    }

    public static function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '' || ! str_starts_with($stored, 'v1.')) { return $stored === '' ? '' : null; }
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) < 29) { return null; }
        $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        return $p === false ? null : $p;
    }
}
