<?php
declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Support\Device;
use App\Core\Support\DB;
use App\Core\Support\Request;

/**
 * Every signed-in browser has a row in user_sessions.
 *  - the PHP session id is replaced every security.session_rotate_minutes (the old one stays valid for 60 s so parallel requests do not fail)
 *  - a session ends security.session_max_hours after sign-in, whatever the activity (then the person signs in again)
 *  - the person or an administrator can end a session; the next request of that browser is signed out
 * Sessions that started before this existed are adopted on their next request.
 */
final class SessionGuard
{
    private const GRACE = 60;

    public static function maxHours(): int { return max(1, (int) config('security.session_max_hours', 12)); }
    public static function rotateMinutes(): int { return max(1, (int) config('security.session_rotate_minutes', 10)); }
    private static function hash(string $id): string { return hash('sha256', $id); }

    /** A new browser signed in. @return int the user_sessions id */
    public static function start(int $userId, string $method, ?string $startedAt = null): int
    {
        $started = $startedAt ?: date('c');
        $ua = Request::userAgent();
        $id = (int) DB::insert('user_sessions', [
            'user_id' => $userId, 'session_hash' => self::hash(session_id()), 'method' => $method, 'ip' => Request::ip(), 'user_agent' => mb_substr((string) $ua, 0, 255), 'device' => Device::label($ua),
            'created_at' => $started, 'expires_at' => date('c', strtotime($started) + self::maxHours() * 3600),
        ]);
        self::event($userId, 'login', $method);
        $_SESSION['sid'] = $id;

        return $id;
    }

    public static function attachRemember(string $selector): void
    {
        if (! empty($_SESSION['sid'])) { DB::exec('UPDATE user_sessions SET remember_selector = ? WHERE id = ?', [$selector, (int) $_SESSION['sid']]); }
    }

    public static function event(?int $userId, string $event, ?string $method = null, ?string $note = null, ?string $email = null): void
    {
        try {
            $ua = Request::userAgent();
            DB::insert('login_events', ['user_id' => $userId, 'email' => $email ? mb_substr($email, 0, 190) : null, 'event' => $event, 'method' => $method, 'ip' => Request::ip(),
                'user_agent' => mb_substr((string) $ua, 0, 255), 'device' => Device::label($ua), 'note' => $note ? mb_substr($note, 0, 190) : null], 'core', '');
        } catch (\Throwable) { /* the log must never block a sign-in */ }
    }

    /**
     * Is this browser's session still allowed? Rotates the id when it is due.
     * @return string|null null = fine, otherwise why it ended: revoked | expired
     */
    public static function check(int $userId): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) { return null; }
        $cur = self::hash(session_id());
        $row = DB::first('SELECT * FROM user_sessions WHERE user_id = ? AND (session_hash = ? OR prev_hash = ?) ORDER BY id DESC LIMIT 1', [$userId, $cur, $cur]);
        if (! $row) {
            if (DB::scalar('SELECT 1 FROM user_sessions WHERE session_hash = ? OR prev_hash = ?', [$cur, $cur])) { return 'revoked'; }
            self::start($userId, 'session');                                             // began before sessions were tracked: adopt it
            return null;
        }
        $_SESSION['sid'] = (int) $row['id'];
        if ($row['revoked_at']) { return (string) ($row['revoke_reason'] ?: 'revoked'); }
        if (strtotime((string) $row['expires_at']) <= time()) {
            DB::exec("UPDATE user_sessions SET revoked_at = now(), revoke_reason = 'expired' WHERE id = ?", [$row['id']]);
            self::event($userId, 'expired', (string) $row['method'], self::maxHours().' h limit');

            return 'expired';
        }
        if ($row['session_hash'] !== $cur) {                                             // an old id inside its grace period
            return (strtotime((string) $row['prev_until']) >= time()) ? null : 'revoked';
        }
        if (time() - strtotime((string) $row['rotated_at']) >= self::rotateMinutes() * 60) {
            session_regenerate_id(false);                                               // new id now; the old one lives on for GRACE seconds
            DB::exec('UPDATE user_sessions SET prev_hash = ?, prev_until = ?, session_hash = ?, rotated_at = now(), last_seen_at = now(), ip = ? WHERE id = ?',
                [$cur, date('c', time() + self::GRACE), self::hash(session_id()), Request::ip(), $row['id']]);
        } elseif (time() - strtotime((string) $row['last_seen_at']) >= 60) {
            DB::exec('UPDATE user_sessions SET last_seen_at = now() WHERE id = ?', [$row['id']]);
        }

        return null;
    }

    /** The person signs out of this browser. */
    public static function end(string $reason = 'logout'): void
    {
        if (! empty($_SESSION['sid'])) {
            DB::exec('UPDATE user_sessions SET revoked_at = now(), revoke_reason = ? WHERE id = ? AND revoked_at IS NULL', [$reason, (int) $_SESSION['sid']]);
        }
    }

    /** End one session (own or, for an administrator, anybody's). @return array|null the session that was ended */
    public static function revoke(int $id, int $by): ?array
    {
        $row = DB::first('SELECT * FROM user_sessions WHERE id = ? AND revoked_at IS NULL', [$id]);
        if (! $row) { return null; }
        DB::exec("UPDATE user_sessions SET revoked_at = now(), revoked_by = ?, revoke_reason = 'revoked' WHERE id = ?", [$by, $id]);
        if ($row['remember_selector']) { DB::exec('DELETE FROM remember_tokens WHERE selector = ?', [$row['remember_selector']]); }
        self::event((int) $row['user_id'], 'revoked', (string) $row['method'], $by === (int) $row['user_id'] ? 'by the person' : 'by an administrator', null);

        return $row;
    }

    /** End every session of a person except one. @return int how many */
    public static function revokeAll(int $userId, ?int $exceptId, int $by): int
    {
        $n = 0;
        foreach (DB::select('SELECT id FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL AND expires_at > now()'.($exceptId ? ' AND id <> '.(int) $exceptId : ''), [$userId]) as $r) {
            if (self::revoke((int) $r['id'], $by)) { $n++; }
        }

        return $n;
    }

    /** Active = not ended and not past the maximum. */
    public static function isCurrent(array $row): bool { return ! empty($_SESSION['sid']) && (int) $_SESSION['sid'] === (int) $row['id']; }
}
