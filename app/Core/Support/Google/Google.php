<?php
declare(strict_types=1);

namespace App\Core\Support\Google;

use App\Core\Support\Crypt;
use App\Core\Support\DB;
use App\Core\Support\Modules;
use App\Core\Support\Settings;

/**
 * Google OAuth + a tiny API client, without any SDK. One connection per person (google_connections).
 * The administrator enters the OAuth client id / secret once (Administration → Connectors); each person then connects their own account.
 * The three addresses can be changed in Settings (google_auth_url, google_token_url, google_api_url) — used by the test server.
 *
 * One sign-in serves everything: the consent asks for Gmail + Calendar and the scopes modules add (hook 'google.scopes',
 * e.g. Sheets), and modules are told about it (hook 'google.connected'). Disconnecting only forgets the tokens here; it does
 * not revoke the grant at Google, because that would also cut the other parts using the same Google account.
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

    /** Gmail + Calendar and every scope a module asks for (hook 'google.scopes'), so one consent covers all. @return string[] */
    public static function allScopes(): array
    {
        $all = self::SCOPES;
        foreach (Modules::hook('google.scopes') as $more) {
            $all = [...$all, ...array_filter((array) $more, 'is_string')];
        }

        return array_values(array_unique($all));
    }

    /** @param string[]|null $scopes null = allScopes() */
    public static function loginUrl(string $state, ?string $hint = null, ?array $scopes = null, string $prompt = 'consent'): string
    {
        return self::authUrl().'?'.http_build_query(array_filter([
            'client_id' => self::clientId(), 'redirect_uri' => self::redirectUri(), 'response_type' => 'code', 'scope' => implode(' ', $scopes ?? self::allScopes()), 'access_type' => 'offline',
            // keep what was granted before: one refresh token keeps working for Gmail, Calendar and Sheets together
            'prompt' => $prompt, 'include_granted_scopes' => 'true', 'state' => $state, 'login_hint' => $hint,
        ]));
    }

    /**
     * Sign-in flows of modules use the same redirect URI: they put their own callback path in
     * $_SESSION['google_forward'] and /connect/google/callback hands the answer on to it.
     */
    public static function forwardTo(string $path): void { $_SESSION['google_forward'] = $path; }

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
        self::save($userId, $row);
        // Modules that use the same Google account (Sheets) take it too, when the grant covers them.
        Modules::hook('google.connected', [$userId, $row['google_email'], $t['access_token'], (string) $refresh, (int) ($t['expires_in'] ?? 3600), explode(' ', $row['scopes'])]);

        return $row;
    }

    private static function save(int $userId, array $row): void
    {
        DB::exec('INSERT INTO google_connections (user_id, google_email, access_token, refresh_token, expires_at, scopes, last_error, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, now())
                  ON CONFLICT (user_id) DO UPDATE SET google_email = EXCLUDED.google_email, access_token = EXCLUDED.access_token, refresh_token = EXCLUDED.refresh_token, expires_at = EXCLUDED.expires_at, scopes = EXCLUDED.scopes, last_error = NULL, updated_at = now()',
            [$userId, $row['google_email'], $row['access_token'], $row['refresh_token'], $row['expires_at'], $row['scopes'], null]);
    }

    /**
     * A module's own Google sign-in also granted Gmail + Calendar: use it for Mail and Calendar too, unless the person
     * already has a working connection to another Google account. @param string[] $scopes granted
     */
    public static function adopt(int $userId, string $email, string $access, string $refresh, int $expiresIn, array $scopes): bool
    {
        if (array_diff(self::SCOPES, $scopes, ['openid', 'email'])) {
            return false;                                         // Gmail / Calendar not granted
        }
        $c = self::connection($userId);
        if ($c && ! empty($c['refresh_token']) && ! $c['last_error'] && strcasecmp((string) $c['google_email'], $email) !== 0) {
            return false;                                         // keep their own, different account
        }
        self::save($userId, ['google_email' => $email, 'access_token' => Crypt::encrypt($access), 'refresh_token' => Crypt::encrypt($refresh),
            'expires_at' => date('c', time() + $expiresIn - 30), 'scopes' => implode(' ', $scopes)]);

        return true;
    }

    /** Forgets the tokens here. The grant stays at Google (revoking it would also cut Sheets on the same account). */
    public static function disconnect(int $userId): void
    {
        DB::exec('DELETE FROM google_connections WHERE user_id = ?', [$userId]);
    }

    /** A valid access token (refreshed when it is about to end). */
    public static function accessToken(int $userId): string
    {
        $c = self::connection($userId) ?? throw new \RuntimeException(__('Google is not connected.'));
        if ($c['expires_at'] && strtotime((string) $c['expires_at']) > time() + 60 && $c['access_token']) { return (string) Crypt::decrypt($c['access_token']); }
        $rt = Crypt::decrypt((string) $c['refresh_token']);
        if (! $rt) {                                              // saved under another app key (config/config.php was rebuilt)
            DB::exec("UPDATE google_connections SET access_token = NULL, refresh_token = NULL, last_error = 'The saved Google key can no longer be read (the app key changed). Connect again.' WHERE user_id = ?", [$userId]);
            throw new \RuntimeException(__('Google needs you to connect again.'));
        }
        $t = self::token(['grant_type' => 'refresh_token', 'refresh_token' => (string) $rt]);
        if (empty($t['access_token'])) {
            if (($t['error'] ?? '') === 'invalid_grant') {          // ended at Google: removed by the person, password change, or a 'Testing' OAuth app (7-day limit)
                DB::exec("UPDATE google_connections SET access_token = NULL, refresh_token = NULL, last_error = ? WHERE user_id = ?",
                    ['Google ended the connection ('.mb_substr((string) ($t['error_description'] ?? 'invalid_grant'), 0, 120).'). Connect again.', $userId]);
            } else {                                                // network or Google trouble: keep the connection, try again later
                DB::exec('UPDATE google_connections SET last_error = ? WHERE user_id = ?', ['Google could not be reached just now: '.mb_substr((string) ($t['error'] ?? 'no answer'), 0, 120), $userId]);
            }
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
        $url = (str_starts_with($path, 'https://') || str_starts_with($path, 'http://') ? $path : self::apiUrl().$path).($query ? '?'.preg_replace('/%5B\d+%5D/', '', http_build_query($query)) : '');
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

    /** Token endpoint: exchange a code ('authorization_code') or renew ('refresh_token'). Raw answer, never throws for an OAuth error. */
    public static function oauth(array $params): array { return self::token($params); }

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
