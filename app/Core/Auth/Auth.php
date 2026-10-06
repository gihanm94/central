<?php
declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Notifier;
use App\Core\Support\Request;
use App\Core\Support\Session;

/**
 * Session sign-in, "remember me" cookies (selector/validator, rotated on use),
 * lockout after repeated failures, and sign-out of every device via session_version.
 */
final class Auth
{
    private const COOKIE = 'acme_remember';
    private static ?CurrentUser $user = null;
    private static bool $resolved = false;

    public static function user(): ?CurrentUser
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;

        if (! empty($_SESSION['uid'])) {
            $user = self::load((int) $_SESSION['uid']);
            if ($user && ($user->session_version === (int) ($_SESSION['sv'] ?? 0))) {
                return self::$user = $user;
            }
            self::clearSession();
        }

        if (! empty($_COOKIE[self::COOKIE])) {
            $user = self::fromRememberCookie((string) $_COOKIE[self::COOKIE]);
            if ($user) {
                self::startSession($user);
                Activity::log('login', 'user', $user->id, $user->name, 'Signed in automatically (remember me)', [], null, $user->id, false);

                return self::$user = $user;
            }
            self::forgetCookie();
        }

        return null;
    }

    public static function id(): ?int { return self::user()?->id; }

    public static function load(int $id): ?CurrentUser
    {
        $row = DB::first(
            'SELECT u.*, r.slug AS role_slug, r.name AS role_name, r.level AS role_level, r.data_scope AS role_scope,
                    d.name AS department_name, t.name AS team_name, p.job_title
               FROM users u
               JOIN roles r ON r.id = u.role_id
          LEFT JOIN departments d ON d.id = u.department_id
          LEFT JOIN teams t ON t.id = u.team_id
          LEFT JOIN employee_profiles p ON p.user_id = u.id
              WHERE u.id = ? AND u.is_active AND u.deleted_at IS NULL',
            [$id]
        );

        return $row ? new CurrentUser($row) : null;
    }

    /** Returns null on success or an error message. */
    public static function attempt(string $email, string $password, bool $remember): ?string
    {
        $key  = strtolower($email).'|'.Request::ip();
        $lock = DB::first('SELECT attempts, locked_until FROM login_attempts WHERE key = ?', [$key]);
        if ($lock && $lock['locked_until'] && strtotime($lock['locked_until']) > time()) {
            return __('Too many attempts. Try again in :seconds seconds.', ['seconds' => max(1, strtotime($lock['locked_until']) - time())]);
        }

        $row = DB::first('SELECT id, password, is_active FROM users WHERE lower(email) = lower(?) AND deleted_at IS NULL', [$email]);
        $ok  = $row && password_verify($password, $row['password']);

        if (! $ok || ! filter_var($row['is_active'], FILTER_VALIDATE_BOOL)) {
            $max      = (int) config('security.max_login_attempts', 5);
            $attempts = ((int) ($lock['attempts'] ?? 0)) + 1;
            DB::exec('INSERT INTO login_attempts (key, attempts, locked_until) VALUES (?, ?, ?)
                      ON CONFLICT (key) DO UPDATE SET attempts = EXCLUDED.attempts, locked_until = EXCLUDED.locked_until',
                [$key, $attempts >= $max ? 0 : $attempts, $attempts >= $max ? date('c', time() + 60 * (int) config('security.lockout_minutes', 1)) : null]);
            Activity::log('login_failed', null, null, null, ['Failed sign-in for :email', ['email' => $email]], ['email' => $email], null, $row['id'] ?? null, false);

            return $ok ? __('This account is deactivated. Contact your administrator.') : __('These details do not match an active account.');
        }

        DB::exec('DELETE FROM login_attempts WHERE key = ?', [$key]);
        if (password_needs_rehash($row['password'], PASSWORD_DEFAULT)) {
            DB::exec('UPDATE users SET password = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $row['id']]);
        }

        self::login(self::load((int) $row['id']), $remember, 'password');

        return null;
    }

    /** Final step for every sign-in method. */
    public static function login(CurrentUser $user, bool $remember, string $method): void
    {
        self::startSession($user);
        if ($remember) {
            self::issueRememberCookie($user->id);
        }
        DB::exec('UPDATE users SET last_login_at = now(), last_login_ip = ? WHERE id = ?', [Request::ip(), $user->id]);
        self::$user = $user;
        self::$resolved = true;

        Activity::log('login', 'user', $user->id, $user->name, ['Signed in with :method', ['method' => $method]], ['method' => $method], null, $user->id, false);

        if (config('notifications.login_alert', true)) {
            $ip = Request::ip();
            $ua = Request::userAgent();
            Notifier::securityAlert('login', $user->id, fn () => [
                __('New sign-in to your account'),
                [
                    __('Your account was signed in with :method.', ['method' => __($method)]),
                    __('Time').': '.format_date(now(), 'd M Y, H:i'),
                    __('IP address').': '.e($ip),
                    __('Device').': '.e(str_limit($ua, 120)),
                ],
                __('Not you? Reset your password now and tell your administrator.'),
            ], ['label' => __('Review security settings'), 'url' => url('/profile/security')]);
        }
    }

    public static function logout(): void
    {
        if ($u = self::user()) {
            Activity::log('logout', 'user', $u->id, $u->name, 'Signed out', [], null, $u->id, false);
        }
        if (! empty($_COOKIE[self::COOKIE])) {
            [$selector] = explode(':', (string) $_COOKIE[self::COOKIE]) + [''];
            DB::exec('DELETE FROM remember_tokens WHERE selector = ?', [$selector]);
        }
        self::forgetCookie();
        self::clearSession();
        self::$user = null;
    }

    /** Sign out everywhere else (password change). The current device stays signed in. */
    public static function logoutOtherDevices(int $userId): void
    {
        DB::exec('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$userId]);
        $_SESSION['sv'] = (int) DB::scalar('SELECT session_version FROM users WHERE id = ?', [$userId]);
        $current = explode(':', (string) ($_COOKIE[self::COOKIE] ?? ''))[0];
        DB::exec('DELETE FROM remember_tokens WHERE user_id = ? AND selector <> ?', [$userId, $current]);
    }

    /* ------------------------------------------------------------------ */

    private static function startSession(CurrentUser $user): void
    {
        Session::regenerate();
        $_SESSION['uid'] = $user->id;
        $_SESSION['sv']  = $user->session_version;
    }

    private static function clearSession(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    private static function issueRememberCookie(int $userId): void
    {
        $selector  = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $days      = (int) config('security.remember_days', 30);
        DB::insert('remember_tokens', [
            'user_id'    => $userId,
            'selector'   => $selector,
            'token_hash' => hash('sha256', $validator),
            'user_agent' => Request::userAgent(),
            'expires_at' => date('c', time() + 86400 * $days),
        ]);
        setcookie(self::COOKIE, $selector.':'.$validator, [
            'expires' => time() + 86400 * $days, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }

    private static function fromRememberCookie(string $cookie): ?CurrentUser
    {
        [$selector, $validator] = array_pad(explode(':', $cookie, 2), 2, '');
        $row = DB::first('SELECT * FROM remember_tokens WHERE selector = ? AND expires_at > now()', [$selector]);
        if (! $row || ! hash_equals($row['token_hash'], hash('sha256', $validator))) {
            return null;
        }
        // Rotate: one-time use protects against stolen cookies.
        DB::exec('DELETE FROM remember_tokens WHERE id = ?', [$row['id']]);
        $user = self::load((int) $row['user_id']);
        if ($user) {
            self::issueRememberCookie($user->id);
        }

        return $user;
    }

    private static function forgetCookie(): void
    {
        setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
        unset($_COOKIE[self::COOKIE]);
    }
}
