<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Inet;

use App\Modules\Accounting\Support\Log;
use App\Core\Support\DB;
use App\Modules\Accounting\Erp\ErpSettings;
use App\Modules\Accounting\Support\InetBuilder;

/**
 * Generate = build the INET text file (JSON) of each document of an invoice, keep it in the database and, when
 * "Auto PDF" was chosen, make the PDF too. An invoice gives 388 + T01; a credit note gives 81.
 * Nothing is sent to INET here — that is the Send button.
 */
final class Generator
{
    private const C = ErpSettings::CONN;

    /** column suffix of a document type */
    public static function col(string $type): string
    {
        return match (strtoupper($type)) { '388' => '388', 'T01' => 't01', '81' => '81', default => throw new \InvalidArgumentException('Unknown document type') };
    }

    public static function type(string $col): string { return strtoupper($col); }

    /** One invoice. @return array{docs: string[], errors: array<string, string>} */
    public static function generate(int $invoiceNumber, bool $autoPdf, int $by): array
    {
        return self::many([['invoice' => $invoiceNumber, 'auto_pdf' => $autoPdf]], $by)[$invoiceNumber];
    }

    /**
     * Several invoices at once. Reading and building the JSON is quick and done one by one; the slow part, printing PDFs, runs
     * $parallel Chromium processes at the same time (5 by default). A problem with one invoice never stops the others.
     * @param array<int, array{invoice: int, auto_pdf: bool}> $items
     * @return array<int, array{docs: string[], errors: array<string, string>}>  by invoice number
     */
    public static function many(array $items, int $by, int $parallel = 5): array
    {
        $company = Company::require();
        $res = $prep = $pages = [];
        foreach ($items as $it) {                                                         // 1. read + build
            $no = (int) $it['invoice'];
            $auto = ! empty($it['auto_pdf']);
            $res[$no] = ['docs' => [], 'errors' => []];
            try {
                Log::info('inet', "{$no}: generate start", ['auto_pdf' => $auto, 'by' => $by]);
                ['doc' => $doc, 'lines' => $lines] = DocumentData::load($no);
                InetBuilder::save($no, $auto, $by);                                       // header row (customer, seller, amounts …)
                $credit = $doc['is_credit'] ? DocumentData::creditInfo($doc) : null;
                $types  = $doc['is_credit'] ? ['81'] : ['388', 'T01'];
                Log::info('inet', "{$no}: ".($doc['is_credit'] ? 'credit note' : 'invoice').' read from the ERP copy', ['lines' => count($lines), 'documents' => $types, 'reference' => $credit['reference'] ?? null]);
                foreach ($types as $type) {
                    try {
                        $built = JsonBuilder::build($doc, $lines, $type, $company, $credit);
                        $prep[$no][$type] = ['built' => $built, 'doc' => $doc, 'credit' => $credit];
                        if ($auto) { $pages["{$no}|{$type}"] = PdfRenderer::html($doc, $lines, $company, $type, true, $built['totals'], $credit); }
                    } catch (\Throwable $e) {
                        self::fail($no, $type, $doc, $e, $by, $res);
                    }
                }
                if ($credit && ! empty($credit['reference'])) {
                    DB::exec('UPDATE inets SET reference_invoice = ? WHERE no_invoice = ? AND is_credit = TRUE', [$credit['reference'], $no], self::C);
                }
            } catch (\Throwable $e) {
                Log::exception('inet', $e, "{$no} generate failed");
                $res[$no]['errors']['-'] = $e->getMessage();
            }
        }

        $pdfs = [];                                                                       // 2. print the PDFs, several at a time
        if ($pages) {
            Log::info('inet', 'Printing '.count($pages).' PDF(s), up to '.$parallel.' at a time');
            try { $pdfs = PdfRenderer::renderMany($pages, $parallel); }
            catch (\Throwable $e) { foreach ($pages as $k => $_) { $pdfs[$k] = $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage()); } }
        }

        foreach ($prep as $no => $types) {                                                // 3. save
            foreach ($types as $type => $p) {
                $type = (string) $type;                                                   // numeric keys such as 388 come back as ints
                $doc = $p['doc'];
                $pdf = $pdfs["{$no}|{$type}"] ?? null;
                $pdfError = null;
                if ($pdf instanceof \Throwable) { $pdfError = $pdf; $pdf = null; }                  // keep the JSON; only the PDF is missing
                try {
                    $c = self::col($type);
                    $path = $pdf !== null ? self::store($no, $type, $pdf) : null;
                    $sets = ["text_{$c} = ?", "is_{$c}_generate = TRUE", "message_{$c} = NULL", "modify_{$c}_by = ?", 'auto_pdf = ?', 'updated_at = now()',
                             "is_{$c}_send = FALSE", "is_{$c}_fetch = FALSE", "is_{$c}_download = FALSE"];                    // a new text invalidates what was sent before
                    $par  = [$p['built']['json'], $by, $pdf !== null ? 'true' : 'false'];
                    if ($path !== null) { $sets[] = "pdf_{$c} = ?"; $par[] = $path; }
                    $cr = $doc['is_credit'] ? 'true' : 'false';
                    DB::exec('UPDATE inets SET '.implode(', ', $sets).' WHERE no_invoice = ? AND is_credit = ?', [...$par, $no, $cr], self::C);
                    $t = $p['built']['totals'];                                           // amounts as written in the file (THB)
                    DB::exec('UPDATE inets SET amount = ?, tax_amount = ?, total_amount = ?, currency_code = ? WHERE no_invoice = ? AND is_credit = ?', [$t['basis'], $t['tax'], $t['grand'], 'THB', $no, $cr], self::C);
                    $res[$no]['docs'][] = $type;
                    if ($pdfError) { self::fail($no, $type, $doc, new \RuntimeException('JSON saved, PDF failed: '.$pdfError->getMessage()), $by, $res); continue; }
                    Log::info('inet', "{$no} {$type}: JSON saved".($path ? ' + PDF' : ''), ['total' => $t['grand'], 'tax' => $t['tax'], 'pdf' => $path]);
                } catch (\Throwable $e) {
                    self::fail($no, $type, $doc, $e, $by, $res);
                }
            }
        }

        return $res;
    }

    private static function fail(int $no, string $type, array $doc, \Throwable $e, int $by, array &$res): void
    {
        $res[$no]['errors'][$type] = $e->getMessage();
        Log::exception('inet', $e, "{$no} {$type} not generated");
        try {
            DB::exec('UPDATE inets SET message_'.self::col($type).' = ?, modify_'.self::col($type).'_by = ?, updated_at = now() WHERE no_invoice = ? AND is_credit = ?',
                [mb_substr($e->getMessage(), 0, 1000), $by, $no, $doc['is_credit'] ? 'true' : 'false'], self::C);
        } catch (\Throwable) { /* the row may not exist yet */ }
    }

    /** Keep a PDF under storage/inet/<invoice>/ ; returns the key saved in the database. */
    public static function store(int $invoiceNumber, string $type, string $bytes, string $kind = 'doc'): string
    {
        $key = 'inet/'.$invoiceNumber.'/'.$invoiceNumber.'_'.$type.($kind === 'signed' ? '_signed' : '').'.pdf';
        $abs = BASE_PATH.'/storage/'.$key;
        is_dir(dirname($abs)) || @mkdir(dirname($abs), 0775, true);
        file_put_contents($abs, $bytes);

        return $key;
    }

    public static function read(?string $key): ?string
    {
        if (! $key || str_contains($key, '..') || ! str_starts_with($key, 'inet/')) { return null; }
        $p = BASE_PATH.'/storage/'.$key;

        return is_file($p) ? (string) file_get_contents($p) : null;
    }
}
