<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\Activity;
use App\Core\Support\I18n;
use App\Core\Support\Lark;
use App\Core\Support\DB;
use App\Core\Support\Mailer;
use App\Core\Support\Notifier;
use App\Core\Support\Request;
use App\Core\Support\Settings;
use App\Core\Support\Upload;
use App\Core\Support\ValidationException;

/** Company settings: branding (logo, sign-in picture, language) and notifications (e-mail, Lark). */
class SettingsController extends Controller
{
    public function edit(): string
    {
        $this->authorize('settings', 'view');

        return view('settings/edit', ['title' => __('Company settings'), 'brand' => Settings::branding()]);
    }

    public function update(): never
    {
        $this->authorize('settings', 'edit');
        $data = $this->validate([
            'company_name'   => 'required|max:120',
            'login_tagline'  => 'nullable|max:160',
            'login_banner'   => 'nullable|max:60',
            'support_email'  => 'nullable|email|max:160',
            'default_locale' => 'required|in:'.implode(',', array_keys(I18n::available())),
        ], ['company_name' => __('Company name'), 'login_banner' => __('Sign-in banner'), 'support_email' => __('Support e-mail'), 'default_locale' => __('Default language')]);

        $changed = [];
        foreach ($data as $key => $value) {
            if (Settings::get($key) !== $value) {
                $changed[$key] = ['from' => Settings::get($key), 'to' => $value];
                Settings::put($key, $value);
            }
        }

        $logoOn = Request::boolean('auth_logo') ? '1' : '0';
        if ((Settings::get('auth_logo') ?? '1') !== $logoOn) {
            $changed['auth_logo'] = ['from' => Settings::get('auth_logo') ?? '1', 'to' => $logoOn];
            Settings::put('auth_logo', $logoOn);
        }

        foreach (['company_logo' => ['branding', 2048, true, 'remove_logo'], 'login_image' => ['branding', 5120, false, 'remove_login_image']] as $key => [$folder, $kb, $svg, $remove]) {
            $old = Settings::get($key);
            if ($file = Request::file($key)) {
                Settings::put($key, Upload::image($file, $folder, $kb, $svg));
            } elseif (Request::boolean($remove) && $old) {
                Settings::put($key, null);
            } else {
                continue;
            }
            Upload::delete($old);
            $changed[$key] = ['from' => $old ? 'old image' : 'none', 'to' => Settings::get($key) ? 'new image' : 'removed'];
        }

        if ($changed) {
            Activity::log('updated', 'settings', null, 'Company settings', 'Updated company settings', ['changes' => $changed]);
        }
        back('success', $changed ? __('Settings saved.') : __('Nothing changed.'));
    }

    public function notifications(): string
    {
        $this->authorize('settings', 'view');

        return view('settings/notifications', [
            'title'    => __('Notifications'),
            'matrix'   => Notifier::matrix(),
            'security' => Notifier::security(),
            'lark'     => [
                'mode'    => Lark::mode(),
                'domain'  => Settings::get('lark_domain') ?: 'https://open.larksuite.com',
                'webhook' => Settings::get('lark_webhook_url'),
                'secret'  => Settings::get('lark_webhook_secret') ? '••••••••' : '',
                'app_id'  => Settings::get('lark_app_id'),
                'app_secret' => Settings::get('lark_app_secret') ? '••••••••' : '',
            ],
        ]);
    }

    public function saveNotifications(): never
    {
        $this->authorize('settings', 'edit');
        $in = Request::all();

        $mode = (string) ($in['lark_mode'] ?? 'off');
        if (! in_array($mode, ['off', 'webhook', 'app'], true)) {
            throw new ValidationException(['lark_mode' => __('Choose how Lark messages are sent.')]);
        }
        $domain = (string) ($in['lark_domain'] ?? '');
        if (! in_array($domain, ['https://open.larksuite.com', 'https://open.feishu.cn'], true)) {
            $domain = 'https://open.larksuite.com';
        }
        $webhook = trim((string) ($in['lark_webhook_url'] ?? ''));
        if ($mode === 'webhook' && ! preg_match('#^https://open\.(larksuite\.com|feishu\.cn)/open-apis/bot/v2/hook/[\w-]+$#', $webhook)) {
            throw new ValidationException(['lark_webhook_url' => __('Paste the bot webhook address from Lark (it starts with https://open.larksuite.com/open-apis/bot/v2/hook/).')]);
        }
        if ($mode === 'app' && trim((string) ($in['lark_app_id'] ?? '')) === '') {
            throw new ValidationException(['lark_app_id' => __('Enter the Lark app ID.')]);
        }

        Settings::put('lark_mode', $mode);
        Settings::put('lark_domain', $domain);
        Settings::put('lark_webhook_url', $webhook ?: null);
        Settings::put('lark_app_id', trim((string) ($in['lark_app_id'] ?? '')) ?: null);
        foreach (['lark_webhook_secret', 'lark_app_secret'] as $secret) {
            $v = trim((string) ($in[$secret] ?? ''));
            if ($v !== '' && $v !== '••••••••') {
                Settings::put($secret, $v);
            } elseif (! empty($in['clear_'.$secret])) {
                Settings::put($secret, null);
            }
        }

        $matrix = [];
        foreach (array_keys(config('modules')) as $m) {
            foreach (Notifier::actions() as $a) {
                foreach (Notifier::channels() as $c) {
                    $matrix[$m][$a][$c] = ! empty($in['notify'][$m][$a][$c]);
                }
            }
        }
        $security = [];
        foreach (array_keys(config('core.security_events')) as $e) {
            foreach (Notifier::channels() as $c) {
                $security[$e][$c] = ! empty($in['security'][$e][$c]);
            }
        }
        Settings::put('notify_matrix', json_encode($matrix));
        Settings::put('notify_security', json_encode($security));
        @unlink(BASE_PATH.'/storage/cache/lark_token.json');

        Activity::log('updated', 'settings', null, 'Notifications', 'Updated notification settings', [], null, null, false);
        back('success', __('Notification settings saved.'));
    }

    /** The last e-mails the system tried to send, and whether each went out. */
    public function mailLog(): string
    {
        $this->authorize('settings', 'view');
        $rows = DB::select('SELECT * FROM mail_log ORDER BY id DESC LIMIT 100');

        return view('settings/mail-log', ['title' => __('Mail log'), 'rows' => $rows, 'driver' => config('mail.driver'), 'host' => config('mail.host'), 'from' => config('mail.from_address')]);
    }

    public function testMail(): never
    {
        $this->authorize('settings', 'edit');
        $u  = $this->user();
        $ok = Mailer::notify($u->email, $u->name, __('Test e-mail'), [__('If you can read this, e-mail delivery works.'), __('Driver').': <strong>'.e(config('mail.driver')).'</strong>']);
        back($ok ? 'success' : 'error', $ok
            ? (config('mail.driver') === 'log' ? __('Test e-mail written to storage/mail (driver is "log").') : __('Test e-mail sent to :email.', ['email' => $u->email]))
            : __('Sending failed. See Company settings → Mail log for the reason.'));
    }

    public function testLark(): never
    {
        $this->authorize('settings', 'edit');
        [$ok, $msg] = Lark::test($this->user()->email);
        back($ok ? 'success' : 'error', $msg);
    }
}
