<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Inet;

use App\Core\Support\DB;
use App\Modules\Accounting\Support\Log;
use App\Modules\Accounting\Erp\ErpSettings;

/**
 * The INET e-Tax portal: send a signed document (text + PDF), ask for its status, look a document up by number,
 * and download the signed PDF. Port of the old SendService.
 */
final class InetClient
{
    private const C = ErpSettings::CONN;

    /** Authorization header: a value that already says Bearer / Basic is used as it is, otherwise the scheme from the settings is put in front. */
    private static function auth(): string
    {
        $v = trim(ErpSettings::inet('authorization'));
        if ($v === '') { throw new \RuntimeException(__('The INET authorization key is not set (Accounting → ERP connection).')); }
        if (preg_match('/^(Bearer|Basic)\s/i', $v)) { return $v; }

        return (ErpSettings::get('inet.auth_scheme', 'Bearer') ?: 'Bearer').' '.$v;
    }

    /** @return array{status: int, body: string} */
    public static function post(string $url, array $payload): array
    {
        $t0 = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: '.self::auth()]]);
        $body = curl_exec($ch);
        $err  = $body === false ? curl_error($ch) : null;
        $st   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $ctx = ['status' => $st, 'ms' => (int) round((microtime(true) - $t0) * 1000), 'fields' => array_keys($payload), 'answer' => mb_substr(trim((string) $body), 0, 600)];
        if ($err !== null) { Log::error('inet', 'POST '.$url.' failed: '.$err, $ctx); throw new \RuntimeException('INET: '.$err); }
        if ($st < 200 || $st >= 300) { Log::error('inet', 'POST '.$url.' → HTTP '.$st, $ctx); }
        else { Log::info('inet', 'POST '.$url.' → HTTP '.$st, $ctx); }
        if ($st < 200 || $st >= 300) { throw new \RuntimeException('INET answered HTTP '.$st.': '.mb_substr(trim(strip_tags((string) $body)), 0, 300)); }

        return ['status' => $st, 'body' => (string) $body];
    }

    private static function j(string $body): array
    {
        $j = json_decode($body, true);
        if (! is_array($j)) { throw new \RuntimeException('INET answer is not JSON.'); }

        return array_change_key_case($j, CASE_LOWER);
    }

    public static function download(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => true]);
        $b = curl_exec($ch);
        $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        ($b === false || $st >= 400 ? [Log::class, 'error'] : [Log::class, 'info'])('inet', 'GET signed PDF → HTTP '.$st, ['bytes' => is_string($b) ? strlen($b) : 0, 'url' => preg_replace('/\?.*/', '?…', $url)]);
        if ($b === false || $st >= 400 || $b === '') { throw new \RuntimeException('The signed PDF could not be downloaded (HTTP '.$st.').'); }

        return (string) $b;
    }

    /* ------------------------------------------------------------------ send */

    /** @return string message for the person */
    public static function send(array $row, string $type, ?string $pdfBytes): string
    {
        $c    = Generator::col($type);
        Log::info('inet', $row['no_invoice'].' '.$type.': send start', ['upload' => $pdfBytes !== null]);
        $text = (string) ($row["text_{$c}"] ?? '');
        if ($text === '') { throw new \RuntimeException(__('Generate the document first.')); }
        $pdfBytes ??= Generator::read($row["pdf_{$c}"] ?? null);
        if ($pdfBytes === null) { throw new \RuntimeException(__('There is no PDF yet: generate with Auto PDF, or choose a PDF file when sending.')); }
        $company = Company::require();
        $id = (int) $row['id'];
        $inv = (int) $row['no_invoice'];

        $r = self::j(self::post(ErpSettings::inet('sendApi'), [
            'SellerTaxId' => $company['tax_id'], 'SellerBranchId' => $company['branch_id'], 'ServiceCode' => (string) $company['service_code'], 'ServiceType' => 'Recurring',
            'TextContent' => json_decode($text, true), 'PDFContent' => base64_encode($pdfBytes),
        ])['body']);
        $status = (string) ($r['status'] ?? '');
        $tx     = (string) ($r['transactioncode'] ?? '');
        $url    = (string) ($r['pdfurl'] ?? '');

        if ($status === 'ER' && ($r['errorcode'] ?? '') === 'ER011' && $url !== '') { $status = 'OK'; }        // already in INET: take its copy
        if ($status === 'OK') {
            if ($url === '') {
                self::mark($id, $c, ['send' => true, 'tx' => $tx, 'msg' => 'OK – pdfURL missing']);

                return __('Sent. INET has no PDF yet — press Inet to fetch it.');
            }
            $key = Generator::store($inv, $type, self::download($url), 'signed');
            self::mark($id, $c, ['send' => true, 'fetch' => true, 'download' => true, 'tx' => $tx, 'msg' => 'OK', 'signed' => $key]);

            return __('Sent and signed. The signed PDF is saved.');
        }
        if ($status === 'PC') {
            self::mark($id, $c, ['send' => true, 'tx' => $tx, 'msg' => 'PC – '.($r['errormessage'] ?? 'processing')]);

            return __('INET is still processing it. Press Inet in a minute to fetch the signed PDF.');
        }
        $msg = '['.($r['errorcode'] ?? '?').'] '.($r['errormessage'] ?? 'Unknown error');
        Log::error('inet', $inv.' '.$type.': INET refused it — '.$msg);
        self::mark($id, $c, ['msg' => $msg]);
        throw new \RuntimeException($msg);
    }

    /** Get the signed PDF: by transaction code, else by invoice number. */
    public static function fetch(array $row, string $type): string
    {
        $c = Generator::col($type);
        Log::info('inet', $row['no_invoice'].' '.$type.': fetch start');
        $company = Company::require();
        $id = (int) $row['id'];
        $inv = (int) $row['no_invoice'];
        $tx = (string) ($row["transaction_{$c}"] ?? '');
        $found = null;                                                       // [pdfUrl, transaction]
        if ($tx !== '') {
            for ($try = 0; $try < 4 && ! $found; $try++) {
                $r = self::j(self::post(ErpSettings::inet('statusApi'), ['sellerTaxId' => $company['tax_id'], 'sellerBranchId' => $company['branch_id'], 'serviceCode' => (string) $company['service_code'], 'transactionCode' => $tx])['body']);
                $st = (string) ($r['status'] ?? '');
                if ($st === 'OK' && ! empty($r['pdfurl'])) { $found = [(string) $r['pdfurl'], $tx]; }
                elseif ($st === 'PC' && $try < 3) { sleep(5); }
                else { break; }
            }
        }
        if (! $found) {
            $r = self::j(self::post(ErpSettings::inet('paramsApi'), ['SellerTaxId' => $company['tax_id'], 'SellerBranchId' => $company['branch_id'], 'InvoiceNumber' => (string) $inv, 'InvoiceType' => strtoupper($type), 'OutputType' => 'PDF'])['body']);
            foreach ((array) ($r['responseinvoices'] ?? []) as $d) {
                if (($d['status'] ?? '') === 'OK' && ! empty($d['pdfURL'])) { $found = [(string) $d['pdfURL'], (string) ($d['processId'] ?? '')]; if ((string) ($d['invoiceId'] ?? '') === (string) $inv) { break; } }
            }
        }
        if (! $found) { throw new \RuntimeException(__('INET has no signed PDF for this document yet.')); }
        $key = Generator::store($inv, $type, self::download($found[0]), 'signed');
        self::mark($id, $c, ['send' => true, 'fetch' => true, 'download' => true, 'tx' => $found[1], 'msg' => 'OK', 'signed' => $key]);

        return __('The signed PDF was fetched from INET.');
    }

    /** Write what happened to the inets row. */
    private static function mark(int $id, string $c, array $x): void
    {
        $sets = []; $par = [];
        foreach (['send', 'fetch', 'download'] as $f) { if (isset($x[$f])) { $sets[] = "is_{$c}_{$f} = ?"; $par[] = $x[$f] ? 'true' : 'false'; } }
        if (! empty($x['tx'])) { $sets[] = "transaction_{$c} = ?"; $par[] = $x['tx']; }
        if (isset($x['msg']))  { $sets[] = "message_{$c} = ?"; $par[] = mb_substr($x['msg'], 0, 1000); }
        if (! empty($x['signed'])) { $sets[] = "inet_pdf_{$c} = ?"; $par[] = $x['signed']; }
        $sets[] = 'updated_at = now()';
        DB::exec('UPDATE inets SET '.implode(', ', $sets).' WHERE id = ?', [...$par, $id], self::C);
    }
}
