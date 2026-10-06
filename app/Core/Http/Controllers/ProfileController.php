<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Auth\Auth;
use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\I18n;
use App\Core\Support\Notifier;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\Upload;
use App\Core\Support\ValidationException;

/** Every employee manages their own details, security, preferences and notifications. */
class ProfileController extends Controller
{
    public function edit(): string
    {
        $u = $this->user();

        return view('profile/edit', [
            'title'   => __('Profile'),
            'me'      => $u,
            'profile' => DB::first('SELECT * FROM employee_profiles WHERE user_id = ?', [$u->id]) ?? [],
            'manager' => DB::scalar('SELECT m.name FROM users u JOIN users m ON m.id = u.manager_id WHERE u.id = ?', [$u->id]),
        ]);
    }

    public function update(): never
    {
        $u    = $this->user();
        $data = $this->validate([
            'name'                    => 'required|max:120',
            'phone'                   => 'nullable|max:30',
            'address'                 => 'nullable|max:255',
            'date_of_birth'           => 'nullable|date',
            'gender'                  => 'nullable|in:'.implode(',', array_keys(config('core.employee.genders'))),
            'emergency_contact_name'  => 'nullable|max:120',
            'emergency_contact_phone' => 'nullable|max:30',
        ], ['name' => __('Full name'), 'gender' => __('Gender'), 'date_of_birth' => __('Date of birth')]);

        $before = DB::first('SELECT u.name, p.phone, p.address, p.date_of_birth, p.gender, p.emergency_contact_name, p.emergency_contact_phone
                               FROM users u LEFT JOIN employee_profiles p ON p.user_id = u.id WHERE u.id = ?', [$u->id]);

        $avatar = $u->avatar;
        if ($file = Request::file('avatar')) {
            $avatar = Upload::image($file, 'avatars', 2048);
            Upload::delete($u->avatar);
        } elseif (Request::boolean('remove_avatar')) {
            Upload::delete($u->avatar);
            $avatar = null;
        }

        DB::exec('UPDATE users SET name = ?, avatar = ?, updated_at = now() WHERE id = ?', [$data['name'], $avatar, $u->id]);
        $profile = $data;
        unset($profile['name']);
        $cols = array_keys($profile);
        DB::exec('INSERT INTO employee_profiles (user_id, '.implode(', ', $cols).') VALUES (?'.str_repeat(', ?', count($cols)).')
                  ON CONFLICT (user_id) DO UPDATE SET '.implode(', ', array_map(fn ($c) => "{$c} = EXCLUDED.{$c}", $cols)).', updated_at = now()',
            [$u->id, ...array_values($profile)]);

        $changes = Activity::diff($before ?? [], $data, array_keys($data));
        if ($avatar !== $u->avatar) {
            $changes['photo'] = ['from' => $u->avatar ? 'old photo' : 'none', 'to' => $avatar ? 'new photo' : 'removed'];
        }
        if ($changes) {
            Activity::log('updated', 'user', $u->id, $data['name'], 'Updated own profile', ['changes' => $changes], $u->id);
        }
        back('success', $changes ? __('Profile saved.') : __('Nothing changed.'));
    }

    /* --------------------------------------------------------- security */

    public function security(): string
    {
        $u = $this->user();

        return view('profile/security', [
            'title'    => __('Security'),
            'me'       => $u,
            'passkeys' => DB::select('SELECT * FROM passkeys WHERE user_id = ? ORDER BY created_at DESC', [$u->id]),
            'devices'  => DB::select('SELECT * FROM remember_tokens WHERE user_id = ? AND expires_at > now() ORDER BY created_at DESC', [$u->id]),
            'logins'   => DB::select("SELECT created_at, ip_address, user_agent, description, properties FROM activity_logs WHERE user_id = ? AND action IN ('login','login_failed') ORDER BY created_at DESC LIMIT 6", [$u->id]),
        ]);
    }

    public function password(): never
    {
        $u    = $this->user();
        $data = $this->validate([
            'current_password'      => 'required',
            'password'              => 'required|password',
            'password_confirmation' => 'required|same:password',
        ], ['current_password' => __('Current password'), 'password' => __('New password'), 'password_confirmation' => __('Confirm new password')]);

        $hash = DB::scalar('SELECT password FROM users WHERE id = ?', [$u->id]);
        if (! password_verify($data['current_password'], (string) $hash)) {
            throw new ValidationException(['current_password' => __('Your current password is not correct.')]);
        }

        DB::exec('UPDATE users SET password = ?, updated_at = now() WHERE id = ?', [password_hash($data['password'], PASSWORD_DEFAULT), $u->id]);
        Auth::logoutOtherDevices($u->id);
        Activity::log('password_changed', 'user', $u->id, $u->name, 'Changed password from profile', [], null, null, false);

        $ip = Request::ip();
        Notifier::securityAlert('password', $u->id, fn () => [
            __('Your password was changed'),
            [__('Your password was changed from your profile. Other devices were signed out.'), __('IP address').': '.e($ip).' | '.format_date(now(), 'd M Y, H:i')],
            __('If this was not you, contact your administrator immediately.'),
        ]);
        back('success', __('Password changed. Other devices were signed out.'));
    }

    public function forgetDevice(int $id): never
    {
        DB::exec('DELETE FROM remember_tokens WHERE id = ? AND user_id = ?', [$id, $this->user()->id]);
        back('success', __('That device will need to sign in again.'));
    }

    /* ------------------------------------------------------ preferences */

    public function preferences(): string
    {
        $u = $this->user();

        return view('profile/preferences', ['title' => __('Preferences'), 'me' => $u, 'dashboards' => self::dashboardsFor($u)]);
    }

    public function savePreferences(): never
    {
        $u       = $this->user();
        $modules = array_keys(array_filter(config('modules'), fn ($m) => $m['enabled']));
        $boards  = self::dashboardsFor($u);
        $data    = $this->validate([
            'locale'            => 'required|in:'.implode(',', array_keys(I18n::available())),
            'default_module'    => 'required|in:'.implode(',', $modules),
            'default_dashboard' => 'nullable|in:'.implode(',', array_keys($boards[Request::input('default_module')] ?? [])),
        ], ['locale' => __('Language'), 'default_module' => __('Module to open after sign-in'), 'default_dashboard' => __('Dashboard to open')]);

        DB::exec('UPDATE users SET locale = ?, default_module = ?, default_dashboard = ?, updated_at = now() WHERE id = ?',
            [$data['locale'], $data['default_module'], $data['default_dashboard'], $u->id]);
        setcookie('lang', $data['locale'], ['expires' => time() + 86400 * 365, 'path' => '/', 'samesite' => 'Lax']);
        I18n::set($data['locale']);
        back('success', __('Preferences saved.'));
    }

    /** Display size (zoom) — saved per person, applied as the page's base text size. */
    public function scale(): never
    {
        $pct = (int) Request::input('scale', 100);
        $pct = max(70, min(150, $pct));
        DB::exec('UPDATE users SET ui_scale = ? WHERE id = ?', [$pct, $this->user()->id]);
        json_response(['scale' => $pct]);
    }

    /** Dashboards per enabled module that this person may open: [module => [key => label]] */
    public static function dashboardsFor(\App\Core\Auth\CurrentUser $u): array
    {
        $out = [];
        foreach (config('modules') as $key => $m) {
            if (! $m['enabled']) {
                continue;
            }
            foreach ($m['dashboards'] ?? [] as $dk => $d) {
                if (! empty($d['admin_only']) && ! $u->isAdmin()) {
                    continue;
                }
                $out[$key][$dk] = __($d['label']);
            }
        }

        return $out;
    }

    /* ---------------------------------------------------- notifications */

    public function notifications(): string
    {
        $u = $this->user();

        return view('profile/notifications', [
            'title'    => __('My notifications'),
            'me'       => $u,
            'company'  => Notifier::matrix(),
            'security' => Notifier::security(),
            'prefs'    => $u->notify_prefs,
            'larkOn'   => \App\Core\Support\Lark::mode() === 'app',
        ]);
    }

    public function saveNotifications(): never
    {
        $u     = $this->user();
        $in    = (array) Request::input('notify', []);
        $prefs = [];
        foreach (array_keys(config('modules')) as $m) {
            foreach (Notifier::actions() as $a) {
                foreach (Notifier::channels() as $c) {
                    $prefs[$m][$a][$c] = ! empty($in[$m][$a][$c]);
                }
            }
        }
        foreach (array_keys(config('core.security_events')) as $e) {
            foreach (Notifier::channels() as $c) {
                $prefs['_security'][$e][$c] = ! empty($in['_security'][$e][$c]);
            }
        }
        DB::exec('UPDATE users SET notify_prefs = ?, updated_at = now() WHERE id = ?', [json_encode($prefs), $u->id]);
        Session::flash('success', __('Your notification choices were saved.'));
        redirect('/profile/notifications');
    }
}
