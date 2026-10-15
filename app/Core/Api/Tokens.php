<?php
declare(strict_types=1);

namespace App\Core\Api;

use App\Core\Auth\Auth;
use App\Core\Auth\CurrentUser;
use App\Core\Auth\SessionGuard;
use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Notifier;
use App\Core\Support\Request;

/**
 * API sign-ins, one row per device (api_devices).
 *  - access token: random 256-bit, lives api.access_minutes (default 15), sent as "Authorization: Bearer …"
 *  - refresh token: random 256-bit, rotates on every use and idles out after api.refresh_days (default 30)
 *  - a refresh token that was already used comes back → it was copied: the device is signed out and the person warned
 *  - every sign-in ends after api.max_days (default 90), when the person is deactivated, loses "Mobile app & API",
 *    changes password / signs out everywhere (users.session_version), or the device is revoked
 * Only SHA-256 hashes of tokens are stored.
 */
final class Tokens
{
    private const ACCESS = 'acme_at_';
    private const REFRESH = 'acme_rt_';

    public static function accessMinutes(): int { return max(1, min(60, (int) config('api.access_minutes', 15))); }
    public static function refreshDays(): int { return max(1, (int) config('api.refresh_days', 30)); }
    public static function maxDays(): int { return max(1, (int) config('api.max_days', 90)); }

    private static function hash(string $token): string { return hash('sha256', $token); }
    private static function random(string $prefix): string { return $prefix.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }

    /** May this person use the API at all? */
    public static function allowed(CurrentUser $u): bool { return $u->can('api_access', 'view'); }

    /** After a correct e-mail + password: a new device sign-in. @return array the token pair for the app */
    public static function issue(CurrentUser $u, string $name, ?string $deviceKey, ?string $platform): array
    {
        $ua = Request::userAgent();
        if ($deviceKey !== null) {                     // signing in again on the same install replaces its old sign-in
            DB::exec("UPDATE api_devices SET revoked_at = now(), revoked_reason = 'replaced', access_hash = NULL, refresh_hash = NULL
                      WHERE user_id = ? AND device_key = ? AND revoked_at IS NULL", [$u->id, $deviceKey]);
        }
        $id = (int) DB::insert('api_devices', [
            'user_id' => $u->id, 'name' => $name, 'device_key' => $deviceKey, 'platform' => $platform, 'session_version' => $u->session_version,
            'expires_at' => date('c', time() + self::maxDays() * 86400), 'created_ip' => Request::ip(), 'last_ip' => Request::ip(),
            'last_used_at' => date('c'), 'user_agent' => mb_substr($ua, 0, 255),
        ]);

        return self::rotate($id, null);
    }

    /** New token pair for a device; the refresh token it replaces is remembered to catch reuse. */
    private static function rotate(int $deviceId, ?string $oldRefreshHash): array
    {
        $access = self::random(self::ACCESS);
        $refresh = self::random(self::REFRESH);
        $d = DB::first('SELECT expires_at FROM api_devices WHERE id = ?', [$deviceId]);
        $accessEnd = min(time() + self::accessMinutes() * 60, strtotime((string) $d['expires_at']));
        $refreshEnd = min(time() + self::refreshDays() * 86400, strtotime((string) $d['expires_at']));
        DB::exec('UPDATE api_devices SET access_hash = ?, access_expires_at = ?, refresh_hash = ?, refresh_expires_at = ?, prev_refresh_hash = ?,
                  last_used_at = now(), last_ip = ? WHERE id = ?',
            [self::hash($access), date('c', $accessEnd), self::hash($refresh), date('c', $refreshEnd), $oldRefreshHash, Request::ip(), $deviceId]);

        return ['token_type' => 'Bearer', 'access_token' => $access, 'expires_in' => max(0, $accessEnd - time()),
            'refresh_token' => $refresh, 'refresh_expires_in' => max(0, $refreshEnd - time()), 'device_id' => $deviceId];
    }

    /** Swap a refresh token for a new pair. */
    public static function refresh(string $refresh): array
    {
        if (! str_starts_with($refresh, self::REFRESH)) {
            throw new ApiException(401, 'invalid_token', __('Sign in again.'));
        }
        $h = self::hash($refresh);
        // Atomically take the current refresh token, so two parallel refreshes cannot both win.
        $d = DB::first('UPDATE api_devices SET refresh_hash = NULL WHERE refresh_hash = ? AND revoked_at IS NULL RETURNING *', [$h]);
        if (! $d) {
            $stolen = DB::first('SELECT * FROM api_devices WHERE prev_refresh_hash = ? AND revoked_at IS NULL', [$h]);
            if ($stolen) {
                self::revoke((int) $stolen['id'], 'token_reuse');
                Activity::log('api_token_reuse', 'user', (int) $stolen['user_id'], null, ['An old sign-in token of :device was used again; the device was signed out', ['device' => $stolen['name']]], ['ip' => Request::ip()], null, (int) $stolen['user_id'], false);
                self::alert((int) $stolen['user_id'], (string) $stolen['name']);
            }
            throw new ApiException(401, 'invalid_token', __('Sign in again.'));
        }
        if (strtotime((string) $d['refresh_expires_at']) <= time() || strtotime((string) $d['expires_at']) <= time()) {
            self::revoke((int) $d['id'], 'expired');
            throw new ApiException(401, 'session_ended', __('Your sign-in on this device has ended. Sign in again.'));
        }
        $u = self::user($d);

        return self::rotate((int) $d['id'], $h) + ['user_id' => $u->id];
    }

    /**
     * The person and device behind "Authorization: Bearer …", or an ApiException(401/403).
     * @return array{0: CurrentUser, 1: array} [user, device row]
     */
    public static function authenticate(?string $header): array
    {
        if (! $header || ! preg_match('/^Bearer\s+(acme_at_[A-Za-z0-9_-]{43})$/', trim($header), $m)) {
            throw new ApiException(401, 'unauthenticated', __('Sign in first.'), ['WWW-Authenticate' => 'Bearer']);
        }
        $d = DB::first('SELECT * FROM api_devices WHERE access_hash = ? AND revoked_at IS NULL', [self::hash($m[1])]);
        if (! $d || strtotime((string) $d['access_expires_at']) <= time()) {
            throw new ApiException(401, 'token_expired', __('The access token has expired. Refresh it.'), ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
        }
        $u = self::user($d);
        if (! $d['last_used_at'] || strtotime((string) $d['last_used_at']) < time() - 60 || $d['last_ip'] !== Request::ip()) {
            DB::exec('UPDATE api_devices SET last_used_at = now(), last_ip = ? WHERE id = ?', [Request::ip(), $d['id']]);
        }

        return [$u, $d];
    }

    /** The device's person, if they may still use the API; otherwise the device is signed out. */
    private static function user(array $d): CurrentUser
    {
        $u = Auth::load((int) $d['user_id']);
        $why = match (true) {
            ! $u => 'user_inactive',
            $u->session_version !== (int) $d['session_version'] => 'signed_out_everywhere',
            strtotime((string) $d['expires_at']) <= time() => 'expired',
            ! self::allowed($u) => 'no_api_access',
            default => null,
        };
        if ($why !== null) {
            self::revoke((int) $d['id'], $why);
            throw new ApiException(401, $why === 'no_api_access' ? 'no_api_access' : 'session_ended', $why === 'no_api_access'
                ? __('Your account may not use the mobile app. Ask an administrator.') : __('Your sign-in on this device has ended. Sign in again.'));
        }

        return $u;
    }

    public static function revoke(int $deviceId, string $reason): void
    {
        DB::exec('UPDATE api_devices SET revoked_at = coalesce(revoked_at, now()), revoked_reason = coalesce(revoked_reason, ?), access_hash = NULL, refresh_hash = NULL, prev_refresh_hash = NULL WHERE id = ?',
            [mb_substr($reason, 0, 40), $deviceId]);
    }

    public static function revokeAll(int $userId, string $reason): int
    {
        return DB::exec("UPDATE api_devices SET revoked_at = now(), revoked_reason = ?, access_hash = NULL, refresh_hash = NULL, prev_refresh_hash = NULL
                         WHERE user_id = ? AND revoked_at IS NULL", [mb_substr($reason, 0, 40), $userId]);
    }

    private static function alert(int $userId, string $device): void
    {
        try {
            SessionGuard::event($userId, 'revoked', 'api', 'Token reuse: '.$device);
            Notifier::securityAlert('login', $userId, fn () => [
                __('A device was signed out for your safety'),
                [__('An old sign-in token of :d was used again, which can mean it was copied. That device was signed out.', ['d' => e($device)]), __('IP address').': '.e(Request::ip())],
                __('If this was not you, change your password now and tell your administrator.'),
            ], ['label' => __('Review security settings'), 'url' => url('/profile/security')]);
        } catch (\Throwable) { /* the alert must never break the response */ }
    }
}
