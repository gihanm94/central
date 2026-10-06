<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Inet;

use App\Modules\Accounting\Support\Log;
use App\Core\Support\View;
use App\Modules\Accounting\Erp\ErpSettings;

/**
 * Makes the PDF of a document: the HTML (resources/views/accounting/pdf/document.php, a port of the old templates)
 * is printed to PDF by headless Chrome / Chromium. Set the program under Accounting → Company when it is not found.
 */
final class PdfRenderer
{
    private const THEME = [
        '388' => ['bg' => '#C2FAD3', 'head' => '#0f3a26', 'text' => '#1a1a1a', 'border' => '#6cc992'],
        'T01' => ['bg' => '#FFB9FF', 'head' => '#5c1a5c', 'text' => '#1a1a1a', 'border' => '#d98ad9'],
        '81'  => ['bg' => '#FFDBB7', 'head' => '#5c3a1a', 'text' => '#1a1a1a', 'border' => '#d9a86c'],
    ];
    private const TITLES = [
        '388' => [['ใบกำกับภาษี', 'TAX INVOICE', 'เลขที่ใบกำกับภาษี', 'TAX INV. NO.']],
        'T01' => [['ใบเสร็จรับเงิน', 'RECEIPT', 'เลขที่ใบเสร็จรับเงิน', 'RECEIPT NO.']],
        '81'  => [['ใบลดหนี้', 'CREDIT NOTE', 'เลขที่ใบลดหนี้', 'CN NO.']],
    ];

    public static function chromium(): ?string
    {
        $set = ErpSettings::get('pdf.chromium');
        $cands = array_filter([$set, ...(glob('/opt/pw-browsers/chromium-*/chrome-linux/chrome') ?: []), '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/snap/bin/chromium']);
        foreach ($cands as $c) { if (is_file($c) && is_executable($c)) { return $c; } }

        return null;
    }

    /** @return string the PDF bytes (one document) */
    public static function render(array $doc, array $lines, array $company, string $docType, bool $thai, array $totals, ?array $credit = null): string
    {
        $r = self::renderMany(['one' => self::html($doc, $lines, $company, $docType, $thai, $totals, $credit)], 1)['one'];
        if (! is_string($r)) { throw $r; }

        return $r;
    }

    /** The billing note page (grey theme, invoices table, bank cards, total, signature row). */
    public static function billingHtml(array $note, array $rows, array $company, array $banks): string
    {
        $thai = filter_var($note['is_thai'], FILTER_VALIDATE_BOOL);
        $total = 0.0;
        foreach ($rows as $r) { $total += filter_var($r['is_credit'], FILTER_VALIDATE_BOOL) ? -(float) $r['amount'] : (float) $r['amount']; }
        $d = fn (?string $v) => $v ? ($thai ? date('d/m/', strtotime($v)).((int) date('Y', strtotime($v)) + 543) : date('d/m/Y', strtotime($v))) : '';

        return View::render('accounting/pdf/billing', [
            'n' => $note, 'rows' => $rows, 'company' => $company, 'thai' => $thai, 'banks' => $banks, 'total' => $total, 'dfmt' => $d,
            'symbol' => \App\Modules\Accounting\Billing\BillingService::symbol($note['currency_code']), 'fonts' => self::fonts(), 'logo' => self::data('logo.jpg', 'image/jpeg'),
            'payNote' => (string) ($thai ? $company['payment_note_th'] : $company['payment_note_en']),
        ]);
    }

    /** The page that is printed (HTML). */
    public static function html(array $doc, array $lines, array $company, string $docType, bool $thai, array $totals, ?array $credit = null): string
    {
        $vat = JsonBuilder::vat($doc);
        $fxd = (float) ($doc['exchange_rate'] ?: 1.0);
        $items = [];
        $sum = 0.0;
        $rows = DocumentData::pdfRows($doc);                                  // the order's rows, like the old system
        if ($rows) {
            $credit81 = $docType === '81';
            foreach ($rows as $r) {
                $conv  = $r['conversion_factor'];
                $q     = $conv != 0.0 ? Money::r($r['qty'] / $conv) : $r['qty'];
                $gross = $r['price'] * $q;
                $amt   = Money::r(Money::r($gross - Money::r($gross * $r['discount'] / 100)) * $fxd);
                $sum  += abs($amt);
                $items[] = ['no' => $r['no'], 'part_number' => $r['part_number'], 'part_name' => $r['part_name'], 'batch' => $r['batch'], 'sub' => $r['sub'], 'delivery_date' => self::dmy($r['delivery_date']),
                    'qty' => abs($r['qty']), 'unit' => $r['unit'], 'price' => abs($r['price'] * $fxd), 'discount' => abs($r['discount']), 'amount' => abs($amt)];
            }
            $sum = Money::r($sum);
            $tax = Money::r($sum * Money::r($vat['rate'] / 100, 4));
            $totals = ['basis' => $sum, 'tax' => $tax, 'grand' => Money::r($sum + $tax)] + $totals;
            $totals['basis'] = $sum; $totals['tax'] = $tax; $totals['grand'] = Money::r($sum + $tax);
        } else {                                                              // no order rows in the copy: the invoice lines
            $no = 0;
            foreach ($lines as $l) {
                $fx   = $l['exchange_rate'] ?: 1.0;
                $conv = $l['conversion_factor'];
                $qty  = $conv != 0.0 ? Money::r($l['invoiced_quantity'] / $conv) : $l['invoiced_quantity'];
                $gross = $l['price'] * $qty;
                $amount = abs(Money::r(Money::r($gross - Money::r($gross * $l['discount'] / 100)) * $fx));
                $items[] = ['no' => ($no += 10), 'part_number' => $l['part_number'], 'part_name' => $l['part_name'], 'batch' => '', 'sub' => [], 'delivery_date' => self::dmy($l['delivery_date']), 'qty' => abs($l['ordered_quantity']),
                    'unit' => $l['unit_code'], 'price' => abs($l['price'] * $fx), 'discount' => abs($l['discount']), 'amount' => $amount];
            }
        }
        $note = match ($docType) { 'T01' => $thai ? $company['receipt_note_th'] : $company['receipt_note_en'], '81' => $thai ? $company['credit_note_th'] : $company['credit_note_en'], default => '' };
        $title = self::TITLES[$docType][0];
        $number = (string) $doc['invoice_no'];
        $refDate = $credit && ! empty($credit['reference_date']) ? date('d/m/y', strtotime((string) $credit['reference_date'])) : '';
        $footer = $thai ? '**เอกสารนี้ได้จัดทำและส่งข้อมูลให้แก่กรมสรรพากรด้วยวิธีการทางอิเล็กทรอนิกส์**' : '**This document has been prepared and submitted to the Revenue Department electronically.**';
        return View::render('accounting/pdf/document', [
            'd' => $doc, 'company' => $company, 'items' => $items, 't' => $totals, 'thai' => $thai, 'docType' => $docType, 'number' => $number, 'date' => self::dmy($doc['invoice_date'], 'd-m-Y'),
            'titles' => $title, 'theme' => self::THEME[$docType], 'showShip' => $docType === '388', 'vatRate' => $vat['rate'], 'words' => Money::words($totals['grand'], 'THB', $thai),
            'credit' => $docType === '81' && $credit ? $credit + ['ref_date' => $refDate] : null, 'fonts' => self::fonts(), 'logo' => self::data('logo.jpg', 'image/jpeg'),
            'signature' => self::data('signature.jpeg', 'image/jpeg'), 'footerText' => $footer, 'note' => (string) $note,
        ]);

    }

    /**
     * Print many pages to PDF, up to $parallel Chromium processes at the same time.
     * @param array<string, string> $pages key => html
     * @return array<string, string|\RuntimeException> key => PDF bytes, or the problem
     */
    public static function renderMany(array $pages, int $parallel = 5, ?callable $each = null): array
    {
        $bin = self::chromium() ?? throw new \RuntimeException(__('PDF needs Chrome or Chromium on the server. Set its path under Accounting → Company.'));
        $dir = BASE_PATH.'/storage/cache/pdf';
        is_dir($dir) || @mkdir($dir, 0775, true);
        $home = $dir.'/home';
        is_dir($home) || @mkdir($home, 0775, true);
        $queue = array_keys($pages);
        $run = [];                                                    // key => [proc, id, t0]
        $out = [];
        $t00 = microtime(true);
        $parallel = max(1, min(8, $parallel));
        while ($queue || $run) {
            while ($queue && count($run) < $parallel) {              // start another one
                $k = array_shift($queue);
                $id = bin2hex(random_bytes(6));
                file_put_contents("{$dir}/{$id}.html", $pages[$k]);
                $cmd = [$bin, '--headless', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--no-pdf-header-footer', '--disable-crash-reporter', '--disable-breakpad', '--no-first-run', '--disable-extensions', '--disable-background-networking', '--disable-sync', '--mute-audio', '--hide-scrollbars', '--user-data-dir='."{$dir}/profile-{$id}", '--print-to-pdf='."{$dir}/{$id}.pdf", 'file://'."{$dir}/{$id}.html"];
                $proc = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$dir}/{$id}.log", 'w'], 2 => ['file', "{$dir}/{$id}.log", 'w']], $pipes, null,
                    ['HOME' => $home, 'XDG_CONFIG_HOME' => $home.'/.config', 'XDG_CACHE_HOME' => $home.'/.cache', 'PATH' => '/usr/local/bin:/usr/bin:/bin', 'LANG' => 'C.UTF-8']);          // the web user often has no writable home
                if (! is_resource($proc)) { $out[$k] = new \RuntimeException(__('The PDF could not be made.').' (cannot start Chromium)'); self::clean($dir, $id); continue; }
                $run[$k] = [$proc, $id, microtime(true)];
            }
            foreach ($run as $k => [$proc, $id, $t0]) {
                $st = proc_get_status($proc);
                $file = "{$dir}/{$id}.pdf";
                // Chromium sometimes writes the PDF and then does not exit (crash reporter, a profile it cannot lock …): the finished file is enough
                $size = is_file($file) ? (int) filesize($file) : 0;
                $ready = $size > 500 && $size === ($run[$k][3] ?? -1) && str_contains((string) substr((string) file_get_contents($file, false, null, max(0, $size - 64)), 0), '%%EOF');
                $run[$k][3] = $size;
                $timeout = microtime(true) - $t0 > 60;
                if ($st['running'] && ! $timeout && ! $ready) { continue; }
                if ($st['running']) {
                    proc_terminate($proc, 9);
                    @exec('pkill -9 -f '.escapeshellarg("profile-{$id}").' >/dev/null 2>&1');          // its helper processes too
                    $timeout = $timeout && ! $ready;
                }
                proc_close($proc);
                $pdf = is_file("{$dir}/{$id}.pdf") ? (string) file_get_contents("{$dir}/{$id}.pdf") : '';
                $ms = (int) round((microtime(true) - $t0) * 1000);
                if (strlen($pdf) < 500 || ! str_starts_with($pdf, '%PDF')) {
                    $log = is_file("{$dir}/{$id}.log") ? array_slice(array_filter(explode("\n", trim((string) file_get_contents("{$dir}/{$id}.log")))), -4) : [];
                    Log::error('pdf', 'Chromium did not make a PDF'.($timeout ? ' (timed out)' : ''), ['ms' => $ms, 'binary' => $bin, 'output' => $log, 'doc' => $k]);
                    $out[$k] = new \RuntimeException(__('The PDF could not be made.').' '.mb_substr(implode(' ', array_slice($log, -2)), 0, 200));
                } else {
                    Log::info('pdf', 'PDF rendered', ['ms' => $ms, 'bytes' => strlen($pdf), 'doc' => $k]);
                    $out[$k] = $pdf;
                }
                self::clean($dir, $id);
                unset($run[$k]);
                if ($each) { $each(count($out)); }
            }
            if ($run) { usleep(60000); }
        }
        if (count($pages) > 1) { Log::info('pdf', count($pages).' PDFs printed, '.min($parallel, count($pages)).' at a time', ['ms' => (int) round((microtime(true) - $t00) * 1000)]); }

        return $out;
    }

    private static function clean(string $dir, string $id): void
    {
        @unlink("{$dir}/{$id}.html"); @unlink("{$dir}/{$id}.pdf"); @unlink("{$dir}/{$id}.log");
        foreach (glob("{$dir}/profile-{$id}") ?: [] as $p) { self::rrmdir($p); }
    }

    private static function dmy(?string $v, string $f = 'd/m/Y'): string
    {
        if (! $v) { return '-'; }
        try { return (new \DateTimeImmutable($v))->format($f); } catch (\Throwable) { return $v; }
    }

    private static function data(string $file, string $mime): string
    {
        $p = BASE_PATH.'/resources/pdf/'.$file;

        return is_file($p) ? 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($p)) : '';
    }

    private static function fonts(): string
    {
        static $css = null;
        if ($css !== null) { return $css; }
        $css = '';
        foreach ([400 => 'Regular', 500 => 'Medium', 600 => 'SemiBold', 700 => 'Bold'] as $w => $n) {
            $p = BASE_PATH."/resources/pdf/sarabun/Sarabun-{$n}.ttf";
            if (is_file($p)) { $css .= "@font-face{font-family:'Sarabun';font-weight:{$w};src:url('data:font/truetype;base64,".base64_encode((string) file_get_contents($p))."') format('truetype');}\n"; }
        }

        return $css;
    }

    private static function rrmdir(string $d): void
    {
        foreach (glob($d.'/*') ?: [] as $f) { is_dir($f) ? self::rrmdir($f) : @unlink($f); }
        @rmdir($d);
    }
}
