<?php
declare(strict_types=1);

namespace App\Core\Support;

/**
 * Decides who hears about what, and how.
 *
 * Company switches (Company settings → Notifications): per module × action × channel.
 * Personal switches (Profile → Notifications): each person can turn off what they receive.
 * Recipients of a record event: the person who did it and the record owner.
 * Every message is written in the recipient's own language.
 */
final class Notifier
{
    public static function actions(): array { return array_keys(config('core.notify_actions')); }
    public static function channels(): array { return array_keys(config('core.notify_channels')); }

    /** Company matrix with defaults filled in: [module][action][channel] => bool */
    public static function matrix(): array
    {
        $saved = json_decode((string) Settings::get('notify_matrix'), true) ?: [];
        $out   = [];
        foreach (array_keys(config('modules')) as $m) {
            foreach (self::actions() as $a) {
                foreach (self::channels() as $c) {
                    $out[$m][$a][$c] = (bool) ($saved[$m][$a][$c] ?? true);
                }
            }
        }

        return $out;
    }

    public static function security(): array
    {
        $saved = json_decode((string) Settings::get('notify_security'), true) ?: [];
        $out   = [];
        foreach (array_keys(config('core.security_events')) as $e) {
            foreach (self::channels() as $c) {
                $out[$e][$c] = (bool) ($saved[$e][$c] ?? ($c === 'email'));
            }
        }

        return $out;
    }

    /** Personal opt-outs. Missing = on. */
    public static function prefs(mixed $json): array
    {
        return is_array($json) ? $json : (json_decode((string) $json, true) ?: []);
    }

    /**
     * A record event (create / edit / delete / import / export / download).
     * $text: ['key' => 'Created :type ":label"', 'params' => [...]] rendered per recipient language.
     */
    public static function event(string $module, string $action, array $text, array $changes, ?int $actorId, ?int $ownerId): void
    {
        if (! in_array($action, self::actions(), true)) {
            return;
        }
        $rule = self::matrix()[$module][$action] ?? ['email' => false, 'lark' => false];
        if (! $rule['email'] && ! $rule['lark']) {
            return;
        }

        $actorName = $actorId ? (string) DB::scalar('SELECT name FROM users WHERE id = ?', [$actorId]) : __('System');
        $build = function (bool $isActor) use ($text, $changes, $actorName, $action) {
            $desc  = activity_render($text);
            $lines = [
                $isActor ? __('This confirms an action you just took:') : __('Someone acted on a record that belongs to you:'),
                '<strong>'.e($isActor ? __('You') : $actorName).'</strong>: '.e($desc),
                __('Time').': '.format_date(now(), 'd M Y, H:i').'  |  IP: '.e(Request::ip()),
            ];
            foreach (array_slice($changes, 0, 8, true) as $field => $c) {
                $lines[] = '&bull; '.e(__(ucfirst(str_replace('_', ' ', (string) $field)))).': '.e(str_limit((string) ($c['from'] ?? '—'), 40)).' &rarr; '.e(str_limit((string) ($c['to'] ?? '—'), 40));
            }

            return [__(ucfirst(config('core.notify_actions')[$action] ?? $action)).': '.str_limit($desc, 70), $lines];
        };

        foreach (self::recipients($actorId, $ownerId) as $u) {
            $isActor = (int) $u['id'] === $actorId;
            $prefs   = self::prefs($u['notify_prefs'])[$module][$action] ?? [];

            I18n::with($u['locale'], function () use ($u, $rule, $prefs, $isActor, $build) {
                [$subject, $lines] = $build($isActor);
                if ($rule['email'] && ($prefs['email'] ?? true)) {
                    Mailer::notify($u['email'], $u['name'], $subject, $lines, ['label' => __('Open dashboard'), 'url' => url('/dashboard')], __('If you did not expect this, contact your administrator.'));
                }
                if ($rule['lark'] && Lark::mode() === 'app' && ($prefs['lark'] ?? true)) {
                    Lark::direct($u['email'], $subject, array_slice($lines, 1), url('/dashboard'));
                }
            });
        }

        if ($rule['lark'] && Lark::mode() === 'webhook') {
            I18n::with(null, function () use ($build) {
                [$subject, $lines] = $build(false);
                Lark::webhook($subject, array_slice($lines, 1), url('/activity'));
            });
        }
    }

    /** Security alerts (sign-in, password) — always about the person themselves. */
    public static function securityAlert(string $event, int $userId, callable $build, ?array $button = null): void
    {
        $rule = self::security()[$event] ?? ['email' => true, 'lark' => false];
        $u    = DB::first('SELECT id, name, email, locale, notify_prefs FROM users WHERE id = ?', [$userId]);
        if (! $u) {
            return;
        }
        $prefs = self::prefs($u['notify_prefs'])['_security'][$event] ?? [];

        I18n::with($u['locale'], function () use ($u, $rule, $prefs, $build, $button, $event) {
            [$subject, $lines, $note] = $build() + [2 => null];
            // A password change e-mail is never silenced: it protects the account.
            if ($rule['email'] && (($prefs['email'] ?? true) || $event === 'password')) {
                Mailer::notify($u['email'], $u['name'], $subject, $lines, $button, $note);
            }
            if ($rule['lark'] && ($prefs['lark'] ?? true)) {
                Lark::mode() === 'app'
                    ? Lark::direct($u['email'], $subject, $lines, $button['url'] ?? null)
                    : (Lark::mode() === 'webhook' && $event === 'password' ? Lark::webhook($subject.' — '.$u['name'], $lines) : null);
            }
        });
    }

    private static function recipients(?int $actorId, ?int $ownerId): array
    {
        $ids = array_values(array_unique(array_filter([$actorId, $ownerId])));
        if (! $ids) {
            return [];
        }

        return DB::select('SELECT id, name, email, locale, notify_prefs FROM users WHERE is_active AND deleted_at IS NULL AND id IN ('.implode(',', array_map('intval', $ids)).')');
    }
}
