<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Erp;

use App\Core\Support\DB;
use App\Modules\Accounting\Support\Log;

/**
 * Talks to the ERP: logs in once, keeps the session (id + cookies) in erp_tokens for an hour, asks again on a 401,
 * and reads the OData endpoints page by page.
 */
final class Client
{
    private const TTL = 3600;
    private ?array $token = null;
    private array $cookies = [];
    public ?string $lastError = null;

    /* ------------------------------------------------------------- session */

    /** The saved session if it is still fresh, otherwise a new login. */
    public function session(bool $force = false): array
    {
        if (! $force) {
            $t = $this->token ?? DB::first('SELECT * FROM erp_tokens WHERE is_primary ORDER BY id DESC LIMIT 1', [], ErpSettings::CONN);
            if ($t && ! $this->expired($t)) {
                $this->token   = $t;
                $this->cookies = json_decode((string) ($t['cookies'] ?? '[]'), true) ?: [];

                return $t;
            }
        }

        return $this->login();
    }

    private function expired(array $t): bool
    {
        return empty($t['session_id']) || in_array($t['session_suspended'], [true, 't', 'true', 1, '1'], true)
            || time() - strtotime((string) $t['updated_at']) > self::TTL;
    }

    /** POST the user name and password; the ERP answers with a session id and cookies. */
    public function login(): array
    {
        $user = ErpSettings::conn('username');
        $pass = ErpSettings::password();
        if (ErpSettings::baseUrl() === '' || $user === '' || $pass === '') {
            throw new \RuntimeException('Fill in the ERP address, user name and password first.');
        }
        $this->cookies = [];
        $r = $this->send('POST', ErpSettings::endpoint('tokenUrl'), json_encode(['Username' => $user, 'Password' => $pass, 'ForceRelogin' => true]), false);
        if ($r['status'] >= 400 || $r['status'] === 0) {
            throw new \RuntimeException('Login failed ('.($r['status'] ?: 'no answer').'): '.($r['error'] ?: mb_substr(trim($r['body']), 0, 200)));
        }
        $j = array_change_key_case((array) json_decode($r['body'], true), CASE_LOWER);
        if (empty($j['sessionid'])) {
            throw new \RuntimeException('Login answer has no session id.');
        }
        $row = ['session_id' => (string) $j['sessionid'], 'session_suspended' => ! empty($j['sessionsuspended']), 'cookies' => json_encode($this->cookies)];
        $id  = DB::scalar('SELECT id FROM erp_tokens WHERE is_primary ORDER BY id DESC LIMIT 1', [], ErpSettings::CONN);
        if ($id) {
            DB::update('erp_tokens', $row + ['updated_at' => now()], ['id' => $id], ErpSettings::CONN);
        } else {
            DB::insert('erp_tokens', $row + ['is_primary' => true], ErpSettings::CONN);
        }

        return $this->token = DB::first('SELECT * FROM erp_tokens WHERE is_primary ORDER BY id DESC LIMIT 1', [], ErpSettings::CONN);
    }

    /* ------------------------------------------------------------- reading */

    /**
     * One page of an endpoint. $q: filter, select[], expand[], top, skip, orderby.
     * @return array{status: int, rows: array, error: ?string}
     */
    public function page(string $endpointKey, array $q): array
    {
        $params = [];
        if (! empty($q['filter']))  { $params['$filter']  = $q['filter']; }
        if (! empty($q['select']))  { $params['$select']  = implode(',', str_replace('.', '/', $q['select'])); }
        if (! empty($q['expand']))  { $params['$expand']  = implode(',', $q['expand']); }
        if (! empty($q['orderby'])) { $params['$orderby'] = $q['orderby']; }
        $params['$top']  = (string) ($q['top'] ?? 1000);
        $params['$skip'] = (string) ($q['skip'] ?? 0);
        $path = ErpSettings::endpoint($endpointKey).'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        for ($try = 0; $try < 2; $try++) {
            $s = $this->session($try > 0);
            $r = $this->send('GET', $path, null, true, (string) $s['session_id']);
            if ($r['status'] === 401 && $try === 0) {
                continue;
            }
            break;
        }
        if ($r['status'] < 200 || $r['status'] >= 300) {
            return ['status' => $r['status'], 'rows' => [], 'error' => $r['error'] ?: 'HTTP '.$r['status'].': '.mb_substr(trim(strip_tags($r['body'])), 0, 200)];
        }
        $j = json_decode($r['body'], true);
        if (! is_array($j)) {
            return ['status' => $r['status'], 'rows' => [], 'error' => 'The answer is not JSON.'];
        }
        $rows = array_is_list($j) ? $j : ($j['value'] ?? []);

        return ['status' => $r['status'], 'rows' => array_values(array_filter($rows, 'is_array')), 'error' => null];
    }

    /* ----------------------------------------------------------------- http */

    /** @return array{status: int, body: string, error: ?string} */
    private function send(string $method, string $path, ?string $body, bool $withSession, string $sessionId = ''): array
    {
        $headers = ['Accept: application/json', 'Accept-Encoding: gzip, deflate'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($withSession && $sessionId !== '') {
            $headers[] = 'X-Monitor-SessionId: '.$sessionId;
        }
        if ($this->cookies) {
            $headers[] = 'Cookie: '.implode('; ', array_map(fn ($k, $v) => $k.'='.$v, array_keys($this->cookies), $this->cookies));
        }
        $setCookies = [];
        $t0 = microtime(true);
        $ch = curl_init(ErpSettings::baseUrl().$path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_ENCODING => '',
            CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => max(10, (int) ErpSettings::conn('timeout') ?: 120), CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => ErpSettings::conn('verify_tls') === '1', CURLOPT_SSL_VERIFYHOST => ErpSettings::conn('verify_tls') === '1' ? 2 : 0,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$setCookies) {
                if (stripos($line, 'set-cookie:') === 0) { $setCookies[] = trim(substr($line, 11)); }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        $err = $out === false ? curl_error($ch) : null;
        $st  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $shown = preg_replace('/^(\/[^?]*).*/', '$1', $path).(str_contains($path, '?') ? '?'.rawurldecode(substr($path, strpos($path, '?') + 1)) : '');
        $ctx = ['status' => $st, 'ms' => $ms, 'bytes' => $out === false ? 0 : strlen((string) $out)];
        if ($err !== null) { Log::error('erp', $method.' '.$shown.' failed: '.$err, $ctx); }
        elseif ($st >= 400) { Log::error('erp', $method.' '.$shown.' → HTTP '.$st, $ctx + ['body' => mb_substr(trim(strip_tags((string) $out)), 0, 400)]); }
        else { Log::info('erp', $method.' '.$shown.' → HTTP '.$st, $ctx); }
        if ($setCookies) {                 // a new answer replaces the old cookies, like the old client did
            $this->cookies = [];
            foreach ($setCookies as $raw) {
                [$k, $v] = array_pad(explode('=', explode(';', $raw)[0], 2), 2, null);
                if ($v !== null) { $this->cookies[trim($k)] = trim($v); }
            }
        }

        return ['status' => $st, 'body' => $out === false ? '' : (string) $out, 'error' => $err];
    }
}
