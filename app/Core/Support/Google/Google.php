<?php
declare(strict_types=1);

namespace App\Core\Support\Google;

use App\Core\Support\Crypt;
use App\Core\Support\DB;
use App\Core\Support\Settings;

/**
 * Google OAuth + a tiny API client, without any SDK. One connection per person (google_connections).
 * The administrator enters the OAuth client id / secret once (Administration → Connectors); each person then connects their own account.
 * The three addresses can be changed in Settings (google_auth_url, google_token_url, google_api_url) — used by the test server.
 */
final class Google
{
    public const SCOPES = [
        'openid', 'email',
        'https://www.googleapis.com/auth/gmail.readonly', 'https://www.googleapis.com/auth/gmail.send',
        'https://www.googleapis.com/auth/calendar',
    ];

    public static function clientId(): string { return trim((string) Settings::get('google_client_id', '')); }
    public static function clientSecret(): string { return (string) Crypt::decrypt((string) Settings::get('google_client_secret', '')); }
    public static function configured(): bool { return self::clientId() !== '' && self::clientSecret() !== ''; }
    public static function redirectUri(): string { return url('/connect/google/callback'); }
    private static function authUrl(): string { return (string) (Settings::get('google_auth_url') ?: 'https://accounts.google.com/o/oauth2/v2/auth'); }
    private static function tokenUrl(): string { return (string) (Settings::get('google_token_url') ?: 'https://oauth2.googleapis.com/token'); }
    public static function apiUrl(): string { return rtrim((string) (Settings::get('google_api_url') ?: 'https://www.googleapis.com'), '/'); }

    public static function loginUrl(string $state, ?string $hint = null): string
    {
        return self::authUrl().'?'.http_build_query(array_filter([
            'client_id' => self::clientId(), 'redirect_uri' => self::redirectUri(), 'response_type' => 'code', 'scope' => implode(' ', self::SCOPES), 'access_type' => 'offline',
            'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state, 'login_hint' => $hint,
        ]));
    }

    public static function connection(int $userId): ?array
    {
        return DB::first('SELECT * FROM google_connections WHERE user_id = ?', [$userId]);
    }

    public static function connected(int $userId): bool
    {
        $c = self::connection($userId);

        return $c && ! empty($c['refresh_token']);
    }

    /** Finish the sign-in: swap the code for tokens and keep them. */
    public static function connect(int $userId, string $code): array
    {
        $t = self::token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirectUri()]);
        if (empty($t['access_token'])) { throw new \RuntimeException(__('Google did not accept the sign-in.').' '.($t['error_description'] ?? $t['error'] ?? '')); }
        $old = self::connection($userId);
        $refresh = $t['refresh_token'] ?? ($old ? Crypt::decrypt($old['refresh_token']) : null);
        if (! $refresh) { throw new \RuntimeException(__('Google gave no long-lived access. Remove the app under your Google account permissions and connect again.')); }
        $me = self::apiRaw($t['access_token'], 'GET', '/gmail/v1/users/me/profile');
        $row = ['google_email' => (string) ($me['body']['emailAddress'] ?? ''), 'access_token' => Crypt::encrypt($t['access_token']), 'refresh_token' => Crypt::encrypt((string) $refresh),
            'expires_at' => date('c', time() + (int) ($t['expires_in'] ?? 3600) - 30), 'scopes' => (string) ($t['scope'] ?? implode(' ', self::SCOPES)), 'last_error' => null, 'updated_at' => now()];
        DB::exec('INSERT INTO google_connections (user_id, google_email, access_token, refresh_token, expires_at, scopes, last_error, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, now())
                  ON CONFLICT (user_id) DO UPDATE SET google_email = EXCLUDED.google_email, access_token = EXCLUDED.access_token, refresh_token = EXCLUDED.refresh_token, expires_at = EXCLUDED.expires_at, scopes = EXCLUDED.scopes, last_error = NULL, updated_at = now()',
            [$userId, $row['google_email'], $row['access_token'], $row['refresh_token'], $row['expires_at'], $row['scopes'], null]);

        return $row;
    }

    public static function disconnect(int $userId): void
    {
        $c = self::connection($userId);
        if ($c && $c['refresh_token'] && ($rt = Crypt::decrypt($c['refresh_token']))) {
            self::post((string) (Settings::get('google_revoke_url') ?: 'https://oauth2.googleapis.com/revoke'), ['token' => $rt]);       // best effort
        }
        DB::exec('DELETE FROM google_connections WHERE user_id = ?', [$userId]);
    }

    /** A valid access token (refreshed when it is about to end). */
    public static function accessToken(int $userId): string
    {
        $c = self::connection($userId) ?? throw new \RuntimeException(__('Google is not connected.'));
        if ($c['expires_at'] && strtotime((string) $c['expires_at']) > time() + 60 && $c['access_token']) { return (string) Crypt::decrypt($c['access_token']); }
        $rt = Crypt::decrypt((string) $c['refresh_token']);
        $t = self::token(['grant_type' => 'refresh_token', 'refresh_token' => (string) $rt]);
        if (empty($t['access_token'])) {
            if (($t['error'] ?? '') === 'invalid_grant') { DB::exec("UPDATE google_connections SET access_token = NULL, refresh_token = NULL, last_error = 'The connection was removed on the Google side. Connect again.' WHERE user_id = ?", [$userId]); }
            throw new \RuntimeException(__('Google needs you to connect again.'));
        }
        DB::exec('UPDATE google_connections SET access_token = ?, expires_at = ?, last_error = NULL, updated_at = now() WHERE user_id = ?', [Crypt::encrypt($t['access_token']), date('c', time() + (int) ($t['expires_in'] ?? 3600) - 30), $userId]);

        return $t['access_token'];
    }

    /** @return array{status: int, body: array} */
    public static function api(int $userId, string $method, string $path, array $query = [], ?array $json = null): array
    {
        $r = self::apiRaw(self::accessToken($userId), $method, $path, $query, $json);
        if ($r['status'] === 401) {                                   // the token was refused: refresh once and retry
            DB::exec('UPDATE google_connections SET expires_at = now() - interval \'1 minute\' WHERE user_id = ?', [$userId]);
            $r = self::apiRaw(self::accessToken($userId), $method, $path, $query, $json);
        }

        return $r;
    }

    public static function apiRaw(string $token, string $method, string $path, array $query = [], ?array $json = null): array
    {
        $url = self::apiUrl().$path.($query ? '?'.preg_replace('/%5B\d+%5D/', '', http_build_query($query)) : '');
        $ch = curl_init($url);
        $h = ['Authorization: Bearer '.$token, 'Accept: application/json'];
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8]);
        if ($json !== null) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE)); }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        $out = curl_exec($ch);
        $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = $out === false ? curl_error($ch) : null;

        if ($err !== null) { throw new \RuntimeException('Google: '.$err); }

        return ['status' => $st, 'body' => json_decode((string) $out, true) ?: []];
    }

    private static function token(array $params): array
    {
        return self::post(self::tokenUrl(), $params + ['client_id' => self::clientId(), 'client_secret' => self::clientSecret()]);
    }

    private static function post(string $url, array $form): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8]);
        $out = curl_exec($ch);


        return json_decode((string) $out, true) ?: [];
    }
}
