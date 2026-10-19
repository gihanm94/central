<?php
declare(strict_types=1);

namespace App\Core\Api\Controllers;

use App\Core\Api\ApiException;
use App\Core\Api\Controller;
use App\Core\Api\Kernel;
use App\Core\Api\RateLimit;
use App\Core\Api\Tokens;
use App\Core\Auth\Auth;
use App\Core\Auth\SessionGuard;
use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Notifier;
use App\Core\Support\Request;
use App\Core\Support\ValidationException;

/** Sign in, refresh, sign out (mobile app). */
class AuthController extends Controller
{
    /** POST {username (or email), password, device_name, device_id?, platform?} → token pair + who you are. */
    public function login(): array
    {
        $in = $this->input(['username', 'email', 'password', 'device_name', 'device_id', 'platform']);
        $email = mb_strtolower(trim((string) (($in['username'] ?? '') !== '' ? $in['username'] : ($in['email'] ?? ''))));   // sign-in name: username, or e-mail
        $password = (string) ($in['password'] ?? '');
        $name = trim((string) ($in['device_name'] ?? ''));
        $deviceKey = trim((string) ($in['device_id'] ?? '')) ?: null;
        $platform = in_array($in['platform'] ?? null, ['ios', 'android', 'web', 'other'], true) ? $in['platform'] : 'other';
        $errors = [];
        if ($email === '' || mb_strlen($email) > 190) { $errors['username'] = __('Enter your username.'); }
        if ($password === '' || strlen($password) > 200) { $errors['password'] = __('Enter your password.'); }
        if ($name === '' || mb_strlen($name) > 120) { $errors['device_name'] = __('Name this device (up to 120 characters).'); }
        if ($deviceKey !== null && ! preg_match('/^[A-Za-z0-9._-]{8,120}$/', $deviceKey)) { $errors['device_id'] = __('Invalid device id.'); }
        if ($errors) {
            throw new ValidationException($errors);
        }
        RateLimit::hit('login:'.$email, 10);                 // one address from many IPs

        [$userId, $error, $locked] = Auth::verify($email, $password, 'api') + [2 => null];
        if ($locked !== null) {
            throw new ApiException(429, 'locked', $error, ['Retry-After' => (string) $locked]);
        }
        if ($error !== null) {
            throw new ApiException(401, 'invalid_credentials', $error);
        }
        $u = Auth::load($userId) ?? throw new ApiException(401, 'invalid_credentials', __('These details do not match an active account.'));
        if (! Tokens::allowed($u)) {
            SessionGuard::event($u->id, 'failed', 'api', 'No mobile app & API permission', $email);
            throw new ApiException(403, 'no_api_access', __('Your account may not use the mobile app. Ask an administrator.'));
        }

        $tokens = Tokens::issue($u, $name, $deviceKey, $platform);
        Auth::actAs($u);
        DB::exec('UPDATE users SET last_login_at = now(), last_login_ip = ? WHERE id = ?', [Request::ip(), $u->id]);
        SessionGuard::event($u->id, 'login', 'api', mb_substr($name, 0, 120));
        Activity::log('login', 'user', $u->id, $u->name, ['Signed in on the mobile app (:device)', ['device' => $name]], ['method' => 'api', 'device_id' => $tokens['device_id']], null, $u->id, false);
        if (config('notifications.login_alert', true)) {
            $ip = Request::ip();
            Notifier::securityAlert('login', $u->id, fn () => [
                __('New sign-in to your account'),
                [__('Your account was signed in on the mobile app.'), __('Device').': '.e($name), __('Time').': '.format_date(now(), 'd M Y, H:i'), __('IP address').': '.e($ip)],
                __('Not you? Reset your password now and tell your administrator.'),
            ], ['label' => __('Review security settings'), 'url' => url('/profile/security')]);
        }

        return $tokens + ['user' => MeController::profile($u), '_status' => 200];
    }

    /** POST {refresh_token} → new token pair (the old refresh token stops working). */
    public function refresh(): array
    {
        $token = (string) ($this->input(['refresh_token'])['refresh_token'] ?? '');
        if ($token === '' || strlen($token) > 100) {
            throw new ApiException(401, 'invalid_token', __('Sign in again.'));
        }
        $pair = Tokens::refresh($token);
        unset($pair['user_id']);

        return $pair;
    }

    /** POST → this device is signed out (both tokens stop working). */
    public function logout(): array
    {
        $d = Kernel::device();
        Tokens::revoke((int) $d['id'], 'logout');
        SessionGuard::event($this->user()->id, 'logout', 'api', mb_substr((string) $d['name'], 0, 120));

        return ['ok' => true];
    }
}
