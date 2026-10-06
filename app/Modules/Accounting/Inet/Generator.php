<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Inet;

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

    /** @return array{docs: string[], errors: array<string, string>} */
    public static function generate(int $invoiceNumber, bool $autoPdf, int $by): array
    {
        $company = Company::require();
        ['doc' => $doc, 'lines' => $lines] = DocumentData::load($invoiceNumber);
        InetBuilder::save($invoiceNumber, $autoPdf, $by);                         // header row (customer, seller, amounts …)
        $credit = $doc['is_credit'] ? DocumentData::creditInfo($doc) : null;
        $types  = $doc['is_credit'] ? ['81'] : ['388', 'T01'];
        $out    = ['docs' => [], 'errors' => []];
        foreach ($types as $type) {
            $c = self::col($type);
            try {
                $built = JsonBuilder::build($doc, $lines, $type, $company, $credit);
                $pdfPath = null;
                if ($autoPdf) {
                    $pdf = PdfRenderer::render($doc, $lines, $company, $type, true, $built['totals'], $credit);
                    $pdfPath = self::store($invoiceNumber, $type, $pdf);
                }
                $sets = ["text_{$c} = ?", "is_{$c}_generate = TRUE", "message_{$c} = NULL", "modify_{$c}_by = ?", 'auto_pdf = ?', 'updated_at = now()'];
                $par  = [$built['json'], $by, $autoPdf ? 'true' : 'false'];
                if ($pdfPath !== null) { $sets[] = "pdf_{$c} = ?"; $par[] = $pdfPath; }
                // a new text invalidates what was sent before
                $sets[] = "is_{$c}_send = FALSE"; $sets[] = "is_{$c}_fetch = FALSE"; $sets[] = "is_{$c}_download = FALSE";
                DB::exec('UPDATE inets SET '.implode(', ', $sets).' WHERE no_invoice = ? AND is_credit = ?', [...$par, $invoiceNumber, $doc['is_credit'] ? 'true' : 'false'], self::C);
                // amounts as written in the file (THB)
                DB::exec('UPDATE inets SET amount = ?, tax_amount = ?, total_amount = ?, currency_code = ? WHERE no_invoice = ? AND is_credit = ?',
                    [$built['totals']['basis'], $built['totals']['tax'], $built['totals']['grand'], 'THB', $invoiceNumber, $doc['is_credit'] ? 'true' : 'false'], self::C);
                $out['docs'][] = $type;
            } catch (\Throwable $e) {
                $out['errors'][$type] = $e->getMessage();
                DB::exec("UPDATE inets SET message_{$c} = ?, modify_{$c}_by = ?, updated_at = now() WHERE no_invoice = ? AND is_credit = ?",
                    [mb_substr($e->getMessage(), 0, 1000), $by, $invoiceNumber, $doc['is_credit'] ? 'true' : 'false'], self::C);
            }
        }
        if ($credit && ! empty($credit['reference'])) {
            DB::exec('UPDATE inets SET reference_invoice = ? WHERE no_invoice = ? AND is_credit = TRUE', [$credit['reference'], $invoiceNumber], self::C);
        }

        return $out;
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
