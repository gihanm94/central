<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Inet;

/**
 * The INET text file (JSON) of one document: header C/H/B, lines L, footer F/T.
 * A straight port of InetTextBuilder + InetLineBuilder + InetValidator of the old system.
 * Amounts are always written in THB (each line is converted with its exchange rate), like before.
 */
final class JsonBuilder
{
    private const DEFAULT_CONTACT = 'Boonyawat';
    private const DEFAULT_DEPT    = 'Acme';

    /** @return array{content: array, json: string, totals: array} */
    public static function build(array $doc, array $lines, string $docType, array $company, ?array $credit = null): array
    {
        if (! $lines) {
            throw new \RuntimeException(__('Invoice :no has no lines.', ['no' => $doc['invoice_no']]));
        }
        $vat  = self::vat($doc);
        $rows = [];
        foreach ($lines as $i => $l) { $rows[] = self::line($i + 1, $l, (bool) $doc['is_foreign'], $vat); }
        $t = self::totals($lines, $vat);

        $schema = config('inet_schema');
        $c = array_fill_keys($schema['main'], '');
        $inv  = (string) $doc['invoice_no'];
        $date = self::dtm($doc['invoice_date']);

        $c['C01-SELLER_TAX_ID'] = self::clean($company['tax_id']);
        $c['C02-SELLER_BRANCH_ID'] = self::clean($company['branch_id']);
        $c['C03-FILE_NAME'] = $inv.'.txt';
        $c['H01-DOCUMENT_TYPE_CODE'] = $docType;
        $c['H02-DOCUMENT_NAME'] = 'acme-tax-'.$inv;
        $c['H03-DOCUMENT_ID'] = $inv;
        $c['H04-DOCUMENT_ISSUE_DTM'] = $date;
        if ($docType === '81') {
            $credit ??= ['reference' => null];
            $ref = self::clean((string) ($credit['reference'] ?? ''));
            $c['H05-CREATE_PURPOSE_CODE'] = 'CDNS99';
            $c['H06-CREATE_PURPOSE'] = (string) ($credit['purpose'] ?? '');
            $c['H08-ADDITIONAL_REF_ISSUE_DTM'] = ! empty($credit['reference_date']) ? self::dtm($credit['reference_date']) : $date;
            $c['H07-ADDITIONAL_REF_ASSIGN_ID'] = $ref;
            $c['H10-ADDITIONAL_REF_DOCUMENT_NAME'] = $ref === '' ? '' : $ref.'.txt';
            $c['H09-ADDITIONAL_REF_TYPE_CODE'] = '388';
        }
        $c['H12-BUYER_ORDER_ASSIGN_ID'] = self::clean((string) $doc['po_number']);
        $c['H17-SELLER_CONTACT_PERSON_NAME'] = self::clean($doc['seller_name']);
        $c['H18-SELLER_CONTACT_DEPARTMENT_NAME'] = self::clean($doc['seller_department']);
        $c['H26-SEND_MAIL_IND'] = 'N';

        $taxNo = self::clean($doc['customer_vat_no']);
        $c['B01-BUYER_ID'] = self::clean($doc['customer_code']);
        $c['B02-BUYER_NAME'] = self::clean($doc['customer_name']);
        $c['B03-BUYER_TAX_ID_TYPE'] = $taxNo === '' ? 'OTHR' : ($doc['is_private'] ? 'NIDN' : ($taxNo === '0000000000000' ? 'OTHR' : 'TXID'));
        $c['B04-BUYER_TAX_ID'] = ($taxNo === '0000000000000' || $taxNo === '') ? 'N/A' : $taxNo;
        $c['B05-BUYER_BRANCH_ID'] = '00000';
        $c['B06-BUYER_CONTACT_PERSON_NAME'] = self::or($doc['seller_name'], self::DEFAULT_CONTACT);
        $c['B07-BUYER_CONTACT_DEPARTMENT_NAME'] = self::or($doc['seller_department'], self::DEFAULT_DEPT);
        $c['B10-BUYER_POST_CODE'] = self::or($doc['postal_code'], '00000');
        if (self::clean($doc['mailing_address']) !== '') { $c['B13-BUYER_ADDRESS_LINE1'] = $doc['mailing_address']; }
        $c['B25-BUYER_COUNTRY_ID'] = self::or($doc['country_code'], 'TH');

        $cur = 'THB';
        $c['F01-LINE_TOTAL_COUNT'] = (string) count($lines);
        $c['F03-INVOICE_CURRENCY_CODE'] = $cur;
        $c['F04-TAX_TYPE_CODE1'] = $vat['type'];
        $c['F05-TAX_CAL_RATE1'] = Money::s(abs($vat['rate']));
        $c['F06-BASIS_AMOUNT1'] = Money::s($t['basis']);
        $c['F07-BASIS_CURRENCY_CODE1'] = $cur;
        $c['F08-TAX_CAL_AMOUNT1'] = Money::s($t['tax']);
        $c['F09-TAX_CAL_CURRENCY_CODE1'] = $cur;
        $c['F38-LINE_TOTAL_AMOUNT'] = Money::s($t['grand']);
        $c['F39-LINE_TOTAL_CURRENCY_CODE'] = $cur;
        $c['F42-ALLOWANCE_TOTAL_AMOUNT'] = Money::s($t['discount']);
        $c['F43-ALLOWANCE_TOTAL_CURRENCY_CODE'] = $cur;
        $c['F46-TAX_BASIS_TOTAL_AMOUNT'] = $vat['type'] === 'FRE' ? '0.00' : Money::s($t['basis']);
        $c['F47-TAX_BASIS_TOTAL_CURRENCY_CODE'] = $cur;
        $c['F48-TAX_TOTAL_AMOUNT'] = Money::s($t['tax']);
        $c['F49-TAX_TOTAL_CURRENCY_CODE'] = $cur;
        $c['F50-GRAND_TOTAL_AMOUNT'] = Money::s($t['grand']);
        $c['F51-GRAND_TOTAL_CURRENCY_CODE'] = $cur;
        if ($docType === '81') {
            $orig = self::clean((string) ($credit['original'] ?? ''));
            $c['F36-ORIGINAL_TOTAL_AMOUNT'] = $orig;
            $c['F37-ORIGINAL_TOTAL_CURRENCY_CODE'] = $orig === '' ? '' : $cur;
            $adj = trim((string) ($credit['adjusted'] ?? ''));
            $c['F40-ADJUSTED_INFORMATION_AMOUNT'] = $adj === '' ? '0.00' : $adj;
            $c['F41-ADJUSTED_INFORMATION_CURRENCY_CODE'] = $cur;
        }
        $c['T01-TOTAL_DOCUMENT_COUNT'] = '1';

        self::validate($c, $docType);

        // the lines sit between the buyer (B) and the footer (F) fields, like the old file
        $ordered = [];
        foreach ($schema['main'] as $k) {
            if ($k === 'F01-LINE_TOTAL_COUNT') { $ordered['LINE_ITEM_INFORMATION'] = $rows; }
            $ordered[$k] = $c[$k];
        }

        return ['content' => $ordered, 'json' => json_encode($ordered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 'totals' => $t];
    }

    /** @return array{rate: float, type: string} */
    public static function vat(array $doc): array
    {
        if ($doc['vat_percentage'] === null) { return ['rate' => 0.0, 'type' => 'VAT']; }
        $u = strtoupper((string) $doc['vat_name']);
        $type = (str_contains($u, 'FREE') || str_contains($u, 'FRE')) ? 'FRE' : ((str_contains($u, 'ZERO') || str_contains($u, '0%')) ? 'ZERO' : 'VAT');

        return ['rate' => (float) $doc['vat_percentage'], 'type' => $type];
    }

    private static function line(int $no, array $l, bool $foreign, array $vat): array
    {
        $fx   = $l['exchange_rate'] ?: 1.0;
        $conv = $l['conversion_factor'];
        $qty  = $conv != 0.0 ? Money::r($l['invoiced_quantity'] / $conv) : $l['invoiced_quantity'];
        $gross = $l['price'] * $qty;
        $disc  = Money::r($gross * $l['discount'] / 100);
        $basis = abs(Money::r(Money::r($gross - $disc) * $fx));
        $rateVal = Money::r($vat['rate'] / 100, 4);
        $tax   = abs(Money::r($basis * $rateVal));
        $net   = abs(Money::r($basis + $tax));
        $discOut = Money::r(abs($disc) * $fx);
        $cur   = 'THB';

        $s = array_fill_keys(config('inet_schema.line'), '');
        foreach (config('inet_schema.line_zero') as $k) { $s[$k] = '0.00'; }
        $s['L01-LINE_ID'] = (string) $no;
        $s['L02-PRODUCT_ID'] = self::clean($l['part_number']);
        $s['L10-PRODUCT_CHARGE_AMOUNT'] = Money::s($basis);
        $s['L11-PRODUCT_CHARGE_CURRENCY_CODE'] = $cur;
        $s['L17-PRODUCT_QUANTITY'] = number_format(abs($l['ordered_quantity']), 4, '.', '');
        $s['L20-LINE_TAX_TYPE_CODE'] = $vat['type'];
        $s['L21-LINE_TAX_CAL_RATE'] = Money::s($vat['rate']);
        $type = $l['invoice_item_type'] ?: 'OTHER';
        if ($type === 'DEPOSIT' || $type === 'DISCOUNT') {
            $s['L03-PRODUCT_NAME'] = $type === 'DEPOSIT' ? 'Deposit from customer' : 'Discount';
            $s['L22-LINE_BASIS_AMOUNT'] = '0.00';
            $s['L24-LINE_TAX_CAL_AMOUNT'] = '0.00';
            $s['L26-LINE_ALLOWANCE_CHARGE_IND'] = 'false';
            $s['L27-LINE_ALLOWANCE_ACTUAL_AMOUNT'] = Money::s($basis);
            $s['L28-LINE_ALLOWANCE_ACTUAL_CURRENCY_CODE'] = '';
        } else {
            $s['L03-PRODUCT_NAME'] = self::clean($l['part_name']);
            $s['L22-LINE_BASIS_AMOUNT'] = Money::s($basis);
            $s['L24-LINE_TAX_CAL_AMOUNT'] = Money::s($tax);
            $s['L26-LINE_ALLOWANCE_CHARGE_IND'] = '';
            $s['L27-LINE_ALLOWANCE_ACTUAL_AMOUNT'] = $foreign ? Money::s($basis) : ($discOut == 0.0 ? '0.00' : Money::s($discOut));
            $s['L28-LINE_ALLOWANCE_ACTUAL_CURRENCY_CODE'] = $cur;
        }
        $s['L31-LINE_TAX_TOTAL_AMOUNT'] = Money::s($tax);
        $s['L33-LINE_NET_TOTAL_AMOUNT'] = Money::s($basis);
        $s['L35-LINE_NET_INCLUDE_TAX_TOTAL_AMOUNT'] = Money::s($net);
        foreach (['L23-LINE_BASIS_CURRENCY_CODE', 'L25-LINE_TAX_CAL_CURRENCY_CODE', 'L32-LINE_TAX_TOTAL_CURRENCY_CODE', 'L34-LINE_NET_TOTAL_CURRENCY_CODE', 'L36-LINE_NET_INCLUDE_TAX_TOTAL_CURRENCY_CODE'] as $k) { $s[$k] = $cur; }

        return $s;
    }

    /** @return array{basis: float, discount: float, tax: float, grand: float} */
    public static function totals(array $lines, array $vat): array
    {
        $basis = $discount = 0.0;
        foreach ($lines as $l) {
            $fx   = $l['exchange_rate'] ?: 1.0;
            $conv = $l['conversion_factor'];
            $qty  = $conv != 0.0 ? Money::r($l['invoiced_quantity'] / $conv) : $l['invoiced_quantity'];
            $gross = $l['price'] * $qty;
            $disc  = Money::r($gross * $l['discount'] / 100);
            $b = Money::r(Money::r($gross - $disc) * $fx);
            $basis    += $b;
            $discount += Money::r(abs($disc) * $fx);
        }
        $basis    = abs(Money::r($basis));
        $discount = abs(Money::r($discount));
        $tax   = Money::r($basis * Money::r($vat['rate'] / 100, 4));
        $grand = Money::r($basis + $tax);

        return ['basis' => $basis, 'discount' => $discount, 'tax' => $tax, 'grand' => $grand];
    }

    /** InetValidator */
    private static function validate(array $c, string $docType): void
    {
        $err = [];
        if ($c['B03-BUYER_TAX_ID_TYPE'] !== 'OTHR' && strlen(trim($c['B04-BUYER_TAX_ID'])) < 13) {
            $err[] = 'Buyer tax ID must be at least 13 digits for non-OTHR tax ID types (B04)';
        }
        if ($docType === '81') {
            foreach (['H07-ADDITIONAL_REF_ASSIGN_ID' => 'Credit note reference ID is required (H07)', 'H06-CREATE_PURPOSE' => 'Credit note create purpose is required (H06)',
                      'H05-CREATE_PURPOSE_CODE' => 'Credit note create purpose code is required (H05)', 'F36-ORIGINAL_TOTAL_AMOUNT' => 'Credit note original amount is required (F36)',
                      'F40-ADJUSTED_INFORMATION_AMOUNT' => 'Credit note adjusted information amount is required (F40)'] as $k => $msg) {
                if (trim((string) $c[$k]) === '') { $err[] = $msg; }
            }
        }
        if ($err) { throw new \RuntimeException(implode('; ', $err)); }
    }

    private static function clean(?string $s): string { return $s === null ? '' : trim($s); }

    private static function or(?string $v, string $d): string { return trim((string) $v) !== '' ? trim((string) $v) : $d; }

    /** 2026-10-07T00:00:00 — the date and time as written in the ERP, no zone conversion. */
    private static function dtm(?string $v): string
    {
        if (! $v) { return ''; }
        try { return (new \DateTimeImmutable($v))->format('Y-m-d\TH:i:s'); } catch (\Throwable) { return $v; }
    }
}
