<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Auth\Auth;
use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\I18n;
use App\Core\Support\Mailer;
use App\Core\Support\Notifier;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\ValidationException;

/** Sign in, sign out, forgot / reset password. There is no public sign-up. */
class AuthController extends Controller
{
    public function showLogin(): string
    {
        return view('auth/login', ['title' => __('Sign in')]);
    }

    public function login(): never
    {
        $data  = $this->validate(['email' => 'required|email', 'password' => 'required'], ['email' => __('e-mail'), 'password' => __('password')]);
        $error = Auth::attempt($data['email'], $data['password'], Request::boolean('remember'));
        if ($error) {
            throw new ValidationException(['email' => $error]);
        }

        $to = $_SESSION['_intended'] ?? Auth::user()->homeUrl();
        unset($_SESSION['_intended']);
        redirect(str_starts_with($to, '/') && ! str_starts_with($to, '//') ? $to : '/dashboard');
    }

    /** Language switcher (sign-in page and top bar). Saves the choice for signed-in people too. */
    public function language(string $code): never
    {
        if (isset(I18n::available()[$code])) {
            setcookie('lang', $code, ['expires' => time() + 86400 * 365, 'path' => '/', 'samesite' => 'Lax']);
            if ($u = Auth::user()) {
                DB::exec('UPDATE users SET locale = ? WHERE id = ?', [$code, $u->id]);
            }
        }
        back();
    }

    public function logout(): never
    {
        Auth::logout();
        Session::flash('status', __('You have been signed out.'));
        redirect('/login');
    }

    public function showForgot(): string
    {
        return view('auth/forgot-password', ['title' => __('Reset password')]);
    }

    public function sendReset(): never
    {
        $data = $this->validate(['email' => 'required|email'], ['email' => __('e-mail')]);
        $user = DB::first('SELECT id, name, email, locale FROM users WHERE lower(email) = lower(?) AND is_active AND deleted_at IS NULL', [$data['email']]);

        if ($user) {
            $recent = DB::scalar("SELECT 1 FROM password_resets WHERE email = lower(?) AND created_at > now() - interval '1 minute'", [$user['email']]);
            if (! $recent) {
                $token = bin2hex(random_bytes(32));
                DB::exec('INSERT INTO password_resets (email, token_hash, created_at) VALUES (lower(?), ?, now())
                          ON CONFLICT (email) DO UPDATE SET token_hash = EXCLUDED.token_hash, created_at = now()', [$user['email'], hash('sha256', $token)]);
                $minutes = (int) config('security.reset_minutes', 60);
                I18n::with($user['locale'], fn () => Mailer::notify($user['email'], $user['name'], __('Reset your password'), [
                    __('We received a request to reset the password for your account.'),
                    __('This link expires in :minutes minutes.', ['minutes' => $minutes]),
                ], ['label' => __('Choose a new password'), 'url' => url('/reset-password', ['token' => $token, 'email' => $user['email']])],
                    __('If you did not ask for this, ignore this e-mail. Your password stays the same.')));
                Activity::log('password_reset_requested', 'user', (int) $user['id'], $user['name'], 'Requested a password reset link', [], null, (int) $user['id'], false);
            }
        }

        // Same answer either way, so nobody can probe which e-mails exist.
        Session::flash('status', __('If that e-mail belongs to an active account, a reset link is on its way.'));
        redirect('/forgot-password');
    }

    public function showReset(): string
    {
        return view('auth/reset-password', ['title' => __('Choose a new password'), 'token' => (string) Request::query('token'), 'email' => (string) Request::query('email')]);
    }

    public function reset(): never
    {
        $data = $this->validate([
            'token'                 => 'required',
            'email'                 => 'required|email',
            'password'              => 'required|password',
            'password_confirmation' => 'required|same:password',
        ], ['email' => __('e-mail'), 'password' => __('New password'), 'password_confirmation' => __('Confirm new password')]);

        $row = DB::first('SELECT * FROM password_resets WHERE email = lower(?)', [$data['email']]);
        $minutes = (int) config('security.reset_minutes', 60);
        if (! $row || ! hash_equals($row['token_hash'], hash('sha256', $data['token'])) || strtotime($row['created_at']) < time() - 60 * $minutes) {
            throw new ValidationException(['email' => __('This reset link is invalid or has expired. Request a new one.')]);
        }
        $user = DB::first('SELECT id, name, email FROM users WHERE lower(email) = lower(?) AND deleted_at IS NULL', [$data['email']])
            ?? throw new ValidationException(['email' => __('Account not found.')]);

        DB::exec('UPDATE users SET password = ?, session_version = session_version + 1, updated_at = now() WHERE id = ?', [password_hash($data['password'], PASSWORD_DEFAULT), $user['id']]);
        DB::exec('DELETE FROM remember_tokens WHERE user_id = ?', [$user['id']]);
        DB::exec('DELETE FROM password_resets WHERE email = lower(?)', [$data['email']]);
        Activity::log('password_reset', 'user', (int) $user['id'], $user['name'], 'Reset password with an e-mail link', [], null, (int) $user['id'], false);

        $ip = Request::ip();
        Notifier::securityAlert('password', (int) $user['id'], fn () => [
            __('Your password was changed'),
            [__('Your password was reset using an e-mail link.'), __('IP address').': '.e($ip).' | '.format_date(now(), 'd M Y, H:i')],
            __('If this was not you, contact your administrator immediately.'),
        ], ['label' => __('Sign in'), 'url' => url('/login')]);

        Session::flash('status', __('Password saved. Sign in with your new password.'));
        redirect('/login');
    }
}
