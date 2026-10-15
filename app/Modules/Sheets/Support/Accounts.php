<?php
declare(strict_types=1);

namespace App\Modules\Sheets\Support;

use App\Core\Support\Crypt;
use App\Core\Support\DB;
use App\Core\Support\Google\Google;

/**
 * Google accounts a person connected for Sheets (gsheet_accounts): several per person, apart from the
 * Gmail/Calendar connection. Uses the administrator's OAuth client (Administration → Connectors).
 */
final class Accounts
{
    public const SCOPES = [
        'openid', 'email',
        'https://www.googleapis.com/auth/spreadsheets',            // write the synced tabs
        'https://www.googleapis.com/auth/drive.metadata.readonly',  // list the person's spreadsheets to pick from
    ];

    /** @return list<array> */
    public static function of(int $userId): array
    {
        return DB::select('SELECT id, user_id, google_email, last_error, connected_at, refresh_token IS NOT NULL AS usable FROM gsheet_accounts WHERE user_id = ? ORDER BY google_email', [$userId]);
    }

    public static function find(int $id, int $userId): ?array
    {
        return DB::first('SELECT * FROM gsheet_accounts WHERE id = ? AND user_id = ?', [$id, $userId]);
    }

    /** Finish the Google sign-in: keep (or refresh) the account. @return array the row */
    public static function connect(int $userId, string $code): array
    {
        $t = Google::oauth(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => Google::redirectUri()]);
        if (empty($t['access_token'])) {
            throw new \RuntimeException(__('Google did not accept the sign-in.').' '.($t['error_description'] ?? $t['error'] ?? ''));
        }
        $granted = explode(' ', (string) ($t['scope'] ?? implode(' ', self::SCOPES)));
        if (! in_array('https://www.googleapis.com/auth/spreadsheets', $granted, true)) {
            throw new \RuntimeException(__('Google Sheets access was not granted. Connect again and allow access to your spreadsheets.'));
        }
        $me = Google::apiRaw($t['access_token'], 'GET', '/oauth2/v3/userinfo');
        $email = (string) ($me['body']['email'] ?? '');
        if ($email === '') {
            throw new \RuntimeException(__('Google did not tell which account this is.'));
        }
        $old = DB::first('SELECT refresh_token FROM gsheet_accounts WHERE user_id = ? AND google_email = ?', [$userId, $email]);
        $refresh = $t['refresh_token'] ?? ($old && $old['refresh_token'] ? Crypt::decrypt((string) $old['refresh_token']) : null);
        if (! $refresh) {
            throw new \RuntimeException(__('Google gave no long-lived access. Remove the app under your Google account permissions and connect again.'));
        }
        DB::exec('INSERT INTO gsheet_accounts (user_id, google_email, access_token, refresh_token, expires_at) VALUES (?, ?, ?, ?, ?)
                  ON CONFLICT (user_id, google_email) DO UPDATE SET access_token = EXCLUDED.access_token, refresh_token = EXCLUDED.refresh_token,
                  expires_at = EXCLUDED.expires_at, last_error = NULL, updated_at = now()',
            [$userId, $email, Crypt::encrypt($t['access_token']), Crypt::encrypt((string) $refresh), date('c', time() + (int) ($t['expires_in'] ?? 3600) - 30)]);

        return ['google_email' => $email];
    }

    /** Removes the account and (by cascade) its syncs. */
    public static function disconnect(int $id, int $userId): void
    {
        $a = self::find($id, $userId);
        if (! $a) {
            return;
        }
        if ($a['refresh_token'] && ($rt = Crypt::decrypt((string) $a['refresh_token']))) {
            try { Google::revoke((string) $rt); } catch (\Throwable) { /* best effort */ }
        }
        DB::exec('DELETE FROM gsheet_accounts WHERE id = ?', [$id]);
    }

    public static function accessToken(int $accountId): string
    {
        $a = DB::first('SELECT * FROM gsheet_accounts WHERE id = ?', [$accountId]) ?? throw new \RuntimeException(__('The Google account was disconnected.'));
        if ($a['access_token'] && $a['expires_at'] && strtotime((string) $a['expires_at']) > time() + 60) {
            return (string) Crypt::decrypt((string) $a['access_token']);
        }
        if (! $a['refresh_token']) {
            throw new \RuntimeException(__('Google needs you to connect :e again.', ['e' => $a['google_email']]));
        }
        $t = Google::oauth(['grant_type' => 'refresh_token', 'refresh_token' => (string) Crypt::decrypt((string) $a['refresh_token'])]);
        if (empty($t['access_token'])) {
            if (($t['error'] ?? '') === 'invalid_grant') {
                DB::exec("UPDATE gsheet_accounts SET access_token = NULL, refresh_token = NULL, last_error = 'The access was removed on the Google side. Connect again.', updated_at = now() WHERE id = ?", [$accountId]);
            }
            throw new \RuntimeException(__('Google needs you to connect :e again.', ['e' => $a['google_email']]));
        }
        DB::exec('UPDATE gsheet_accounts SET access_token = ?, expires_at = ?, last_error = NULL, updated_at = now() WHERE id = ?',
            [Crypt::encrypt($t['access_token']), date('c', time() + (int) ($t['expires_in'] ?? 3600) - 30), $accountId]);

        return (string) $t['access_token'];
    }

    /** Google API call with the account's token; refreshes once on 401. @return array{status:int, body:array} */
    public static function api(int $accountId, string $method, string $url, array $query = [], ?array $json = null): array
    {
        $r = Google::apiRaw(self::accessToken($accountId), $method, $url, $query, $json);
        if ($r['status'] === 401) {
            DB::exec("UPDATE gsheet_accounts SET expires_at = now() - interval '1 minute' WHERE id = ?", [$accountId]);
            $r = Google::apiRaw(self::accessToken($accountId), $method, $url, $query, $json);
        }

        return $r;
    }
}
