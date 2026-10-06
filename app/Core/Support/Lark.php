<?php
declare(strict_types=1);

namespace App\Core\Support;

/**
 * Lark (Feishu) messages without any SDK.
 *  webhook mode: one message per event into a group chat (custom bot webhook, optional signature secret)
 *  app mode:     a direct message to each person, found by their work e-mail (needs a Lark app
 *                with the im:message:send_as_bot and contact:user.email:readonly scopes)
 */
final class Lark
{
    public static function mode(): string { return (string) (Settings::get('lark_mode') ?: 'off'); }

    public static function webhook(string $title, array $lines, ?string $url = null): bool
    {
        $hook = (string) Settings::get('lark_webhook_url');
        if (! $hook) {
            return false;
        }
        $payload = ['msg_type' => 'post', 'content' => ['post' => ['en_us' => self::post($title, $lines, $url)]]];

        if ($secret = (string) Settings::get('lark_webhook_secret')) {
            $ts = (string) time();
            $payload['timestamp'] = $ts;
            $payload['sign'] = base64_encode(hash_hmac('sha256', '', $ts."\n".$secret, true));
        }
        $res = self::http($hook, $payload);

        return ($res['code'] ?? $res['StatusCode'] ?? -1) === 0;
    }

    public static function direct(string $email, string $title, array $lines, ?string $url = null): bool
    {
        $token = self::token();
        if (! $token) {
            return false;
        }
        $res = self::http(self::domain().'/open-apis/im/v1/messages?receive_id_type=email', [
            'receive_id' => $email,
            'msg_type'   => 'post',
            'content'    => json_encode(['en_us' => self::post($title, $lines, $url)], JSON_UNESCAPED_UNICODE),
        ], ['Authorization: Bearer '.$token]);

        return ($res['code'] ?? -1) === 0;
    }

    /** Sends a test message and returns [ok, message]. */
    public static function test(string $email): array
    {
        $title = __('Test message from :app', ['app' => Settings::get('company_name', config('app.name'))]);
        $lines = [__('If you can read this, Lark notifications work.')];

        return match (self::mode()) {
            'webhook' => self::webhook($title, $lines) ? [true, __('Test message posted to the Lark group.')] : [false, __('Lark did not accept the message. Check the webhook address and secret.')],
            'app'     => self::direct($email, $title, $lines) ? [true, __('Test message sent to you on Lark.')] : [false, __('Lark did not accept the message. Check the app ID, secret and that your Lark e-mail matches.')],
            default   => [false, __('Lark is switched off.')],
        };
    }

    private static function domain(): string
    {
        return rtrim((string) (Settings::get('lark_domain') ?: 'https://open.larksuite.com'), '/');
    }

    private static function token(): ?string
    {
        $cache = BASE_PATH.'/storage/cache/lark_token.json';
        $saved = is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;
        if ($saved && ($saved['expires'] ?? 0) > time() + 60 && ($saved['app'] ?? '') === Settings::get('lark_app_id')) {
            return $saved['token'];
        }
        $res = self::http(self::domain().'/open-apis/auth/v3/tenant_access_token/internal', [
            'app_id' => (string) Settings::get('lark_app_id'), 'app_secret' => (string) Settings::get('lark_app_secret'),
        ]);
        if (($res['code'] ?? -1) !== 0 || empty($res['tenant_access_token'])) {
            error_log('[lark] token failed: '.json_encode($res));

            return null;
        }
        @file_put_contents($cache, json_encode(['token' => $res['tenant_access_token'], 'expires' => time() + (int) ($res['expire'] ?? 3600), 'app' => Settings::get('lark_app_id')]));

        return $res['tenant_access_token'];
    }

    private static function post(string $title, array $lines, ?string $url): array
    {
        $content = array_map(fn ($l) => [['tag' => 'text', 'text' => html_entity_decode(strip_tags((string) $l), ENT_QUOTES, 'UTF-8')]], $lines);
        if ($url) {
            $content[] = [['tag' => 'a', 'text' => __('Open'), 'href' => $url]];
        }

        return ['title' => $title, 'content' => $content];
    }

    private static function http(string $url, array $body, array $headers = []): array
    {
        $json    = json_encode($body, JSON_UNESCAPED_UNICODE);
        $headers = array_merge(['Content-Type: application/json; charset=utf-8'], $headers);
        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4]);
                $raw = curl_exec($ch);
                curl_close($ch);
            } else {
                $raw = @file_get_contents($url, false, stream_context_create(['http' => [
                    'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $json, 'timeout' => 6, 'ignore_errors' => true,
                ]]));
            }
            $res = json_decode((string) $raw, true) ?: [];
            if (($res['code'] ?? $res['StatusCode'] ?? 0) !== 0) {
                error_log('[lark] '.$url.' → '.$raw);
            }

            return $res;
        } catch (\Throwable $e) {
            error_log('[lark] '.$e->getMessage());

            return [];
        }
    }
}
