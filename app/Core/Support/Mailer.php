<?php
declare(strict_types=1);

namespace App\Core\Support;

/**
 * Sends HTML e-mail without any library.
 *  driver "log":  writes each message to storage/mail/*.html (open them in a browser)
 *  driver "smtp": talks SMTP directly (TLS 587, SSL 465 or plain), AUTH LOGIN
 */
final class Mailer
{
    public static function send(string $to, string $toName, string $subject, string $html): bool
    {
        $error = null;
        try {
            $ok = config('mail.driver') === 'smtp'
                ? self::smtp($to, $toName, $subject, $html)
                : self::log($to, $subject, $html);
        } catch (\Throwable $e) {
            $ok    = false;
            $error = $e->getMessage();
            error_log('[mail] '.$error);
        }
        self::record($to, $subject, $ok, $error);

        return $ok;
    }

    /** Remember the outcome so Company settings → Mail log can show it. Never lets logging break the request. */
    private static function record(string $to, string $subject, bool $ok, ?string $error): void
    {
        try {
            DB::insert('mail_log', ['to_email' => mb_substr($to, 0, 190), 'subject' => mb_substr($subject, 0, 250), 'driver' => (string) config('mail.driver', 'log'),
                'status' => $ok ? 'sent' : 'failed', 'error' => $ok ? null : mb_substr((string) ($error ?: 'Unknown error'), 0, 1000)], 'core', '');
            if (random_int(1, 50) === 1) {
                DB::exec('DELETE FROM mail_log WHERE id < (SELECT COALESCE(max(id), 0) - 1000 FROM mail_log)');
            }
        } catch (\Throwable) {
        }
    }

    /** Branded message built from lines, an optional button and footer note. */
    public static function notify(string $to, string $toName, string $subject, array $lines, ?array $button = null, ?string $note = null): bool
    {
        $html = View::partial('emails/message', [
            'subject' => $subject, 'name' => $toName, 'lines' => $lines, 'button' => $button, 'note' => $note,
            'brand'   => Settings::branding(),
        ]);

        return self::send($to, $toName, '['.Settings::get('company_name', config('app.name')).'] '.$subject, $html);
    }

    private static function log(string $to, string $subject, string $html): bool
    {
        $dir = BASE_PATH.'/storage/mail';
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir.'/'.date('Ymd-His').'-'.substr(bin2hex(random_bytes(3)), 0, 6).'-'.preg_replace('/[^a-z0-9]+/i', '-', $to).'.html';

        return (bool) file_put_contents($file, "<!-- To: {$to}\n     Subject: ".htmlspecialchars($subject)." -->\n".$html);
    }

    private static function smtp(string $to, string $toName, string $subject, string $html): bool
    {
        $c    = config('mail');
        $enc  = $c['encryption'] ?? 'tls';
        $host = ($enc === 'ssl' ? 'ssl://' : 'tcp://').$c['host'].':'.$c['port'];
        $verify = (bool) ($c['verify_tls'] ?? true);
        $ctx  = stream_context_create(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify, 'SNI_enabled' => true, 'peer_name' => $c['host']]]);
        $sock = @stream_socket_client($host, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (! $sock) {
            throw new \RuntimeException("SMTP connect failed ({$c['host']}:{$c['port']}): {$errstr}");
        }
        stream_set_timeout($sock, 10);

        $read = function () use ($sock): string {
            $data = '';
            while (($line = fgets($sock, 515)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }

            return $data;
        };
        $cmd = function (string $command, array $expect) use ($sock, $read): string {
            fwrite($sock, $command."\r\n");
            $res = $read();
            if (! in_array((int) substr($res, 0, 3), $expect, true)) {
                throw new \RuntimeException('SMTP error after "'.explode(' ', $command)[0].'": '.trim($res));
            }

            return $res;
        };

        $read();
        $ehlo = 'EHLO '.(gethostname() ?: 'localhost');
        $cmd($ehlo, [250]);
        if ($enc === 'tls') {
            $cmd('STARTTLS', [220]);
            if (! @stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new \RuntimeException('TLS handshake with the mail server failed (certificate not trusted? set mail.verify_tls to false in config.php only if you must)');
            }
            $cmd($ehlo, [250]);
        }
        if (! empty($c['username'])) {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($c['username']), [334]);
            $cmd(base64_encode($c['password']), [235]);
        }

        $from = $c['from_address'];
        $cmd("MAIL FROM:<{$from}>", [250]);
        $cmd("RCPT TO:<{$to}>", [250, 251]);
        $cmd('DATA', [354]);

        $enc8 = fn ($s) => '=?UTF-8?B?'.base64_encode($s).'?=';
        $headers = [
            'Date: '.date('r'),
            'From: '.$enc8($c['from_name']).' <'.$from.'>',
            'To: '.$enc8($toName).' <'.$to.'>',
            'Subject: '.$enc8($subject),
            'Message-ID: <'.bin2hex(random_bytes(8)).'@'.(explode('@', $from)[1] ?? 'localhost').'>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $body = implode("\r\n", $headers)."\r\n\r\n".chunk_split(base64_encode($html))."\r\n.";
        $cmd($body, [250]);
        $cmd('QUIT', [221]);
        fclose($sock);

        return true;
    }
}
