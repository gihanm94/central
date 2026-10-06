<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Inet;

use App\Core\Support\DB;
use App\Modules\Accounting\Erp\ErpSettings;

/**
 * Everything one invoice needs, read from the ERP copy: the header (customer, addresses, seller, VAT, order …)
 * and the lines. This replaces the old database views v_documents / v_inet_line.
 */
final class DocumentData
{
    private const C = ErpSettings::CONN;

    /** @return array{doc: array, lines: array[]} */
    public static function load(int $invoiceNumber): array
    {
        $lines = DB::select("SELECT i.*, p.description AS part_name, p.part_number AS product_part_number
                               FROM erp_invoices i LEFT JOIN erp_products p ON p.id = i.part_id
                              WHERE i.invoice_number = ? AND coalesce(i.order_row_type, 1) = 1 ORDER BY i.id", [$invoiceNumber], self::C);
        if (! $lines) {
            throw new \RuntimeException(__('Invoice :no is not in the ERP copy. Press Sync first.', ['no' => $invoiceNumber]));
        }
        $f      = $lines[0];
        $order  = $f['customer_order_id'] ? self::one('SELECT * FROM erp_orders WHERE id = ?', [$f['customer_order_id']]) : null;
        $cust   = $f['customer_id'] ? self::one('SELECT * FROM erp_customers WHERE id = ?', [$f['customer_id']]) : null;
        $seller = $order && $order['seller_id'] ? self::one('SELECT * FROM erp_sellers WHERE id = ?', [$order['seller_id']]) : null;
        $dept   = $seller && $seller['department_id'] ? self::one('SELECT * FROM erp_departments WHERE id = ?', [$seller['department_id']]) : null;

        $mailId  = $order['mailing_address_id'] ?? $cust['mailing_address_id'] ?? null;
        $mail    = $mailId ? self::one('SELECT * FROM erp_addresses WHERE id = ?', [$mailId]) : null;
        $delId   = $order['delivery_address_id'] ?? null;
        $del     = $delId ? (self::one('SELECT * FROM erp_addresses WHERE id = ?', [$delId]) ?? self::one('SELECT * FROM erp_delivery_addresses WHERE id = ?', [$delId])) : null;
        $country = $mail && $mail['country_id'] ? DB::scalar('SELECT code FROM erp_countries WHERE id = ?', [$mail['country_id']], self::C) : null;

        $vatGroup = $order && $order['vat_group_id'] ? self::one('SELECT * FROM erp_vat_groups WHERE id = ?', [$order['vat_group_id']]) : null;
        $vatRow   = $vatGroup && $vatGroup['default_vat_rate_id'] ? self::one('SELECT * FROM erp_vats WHERE id = ?', [$vatGroup['default_vat_rate_id']]) : null;
        if (! $vatRow && $order) {
            $vatRow = self::one('SELECT v.* FROM erp_order_rows r JOIN erp_vats v ON v.id = r.vat_rate_id WHERE r.parent_order_id = ? ORDER BY r.row_index LIMIT 1', [$order['id']]);
        }
        $term  = $order && $order['payment_term_id'] ? self::one('SELECT * FROM erp_payment_terms WHERE id = ?', [$order['payment_term_id']]) : null;
        $wh    = $order && $order['warehouse_id'] ? DB::scalar('SELECT name FROM erp_warehouses WHERE id = ?', [$order['warehouse_id']], self::C) : null;
        $dm    = $order && $order['delivery_method_id'] ? self::one('SELECT * FROM erp_delivery_methods WHERE id = ?', [$order['delivery_method_id']]) : null;
        $note  = $order ? DB::scalar('SELECT delivery_note_number FROM erp_order_invoices WHERE business_contact_order_id = ? ORDER BY id LIMIT 1', [$order['id']], self::C) : null;
        $ext   = $order && $order['external_comment_id'] ? DB::scalar('SELECT raw_text FROM erp_comments WHERE id = ?', [$order['external_comment_id']], self::C) : null;
        $contacts = $cust ? DB::select('SELECT * FROM erp_delivery_contacts WHERE customer_id = ? ORDER BY id', [$cust['id']], self::C) : [];
        $email = $phone = '';
        foreach ($contacts as $c) {
            $v = (string) $c['communication_address_value'];
            if ($email === '' && str_contains($v, '@')) { $email = $v; }
            elseif ($phone === '' && ! str_contains($v, '@') && preg_match('/\d{5,}/', $v)) { $phone = $v; }
        }
        $isThaiBuyer = strtoupper((string) $country ?: 'TH') === 'TH';
        $vat = trim((string) ($cust['vat_number'] ?? '')) ?: trim((string) ($order['vat_number'] ?? ''));
        $cur = strtoupper(trim((string) ($f['currency_code'] ?? ''))) ?: 'THB';

        $doc = [
            'invoice_no' => $invoiceNumber, 'invoice_date' => $f['invoice_date'], 'currency_code' => $cur, 'exchange_rate' => $f['exchange_rate'],
            'is_credit' => $order ? self::bool($order['is_credit']) : false, 'is_foreign' => ! $isThaiBuyer,
            'order_id' => $order['id'] ?? null, 'order_number' => $order['order_number'] ?? ($f['customer_order_number'] ?? ''), 'order_date' => $order['order_date'] ?? null,
            'po_number' => $order['business_contact_order_number'] ?? null, 'external_comment_id' => $order['external_comment_id'] ?? null,
            'customer_id' => $cust['id'] ?? $f['customer_id'], 'customer_code' => $cust['code'] ?? $f['customer_code'], 'customer_name' => $cust['name'] ?? '',
            'customer_vat_no' => $vat === '' ? '0000000000000' : $vat, 'is_private' => $cust ? self::bool($cust['is_private_customer']) : false,
            'phone' => $phone, 'email' => $email, 'mailing_address' => self::addr($mail), 'delivery_address' => self::addr($del) ?: self::addr($mail),
            'postal_code' => $mail['postal_code'] ?? '', 'country_code' => $country ?: 'TH',
            'vat_percentage' => $vatRow ? (float) $vatRow['percentage'] : null, 'vat_name' => $vatGroup['description'] ?? ($vatRow['description'] ?? null),
            'seller_name' => $seller ? trim($seller['first_name'].' '.$seller['last_name']) : (string) ($f['seller'] ?? ''), 'seller_department' => $dept ? (string) ($dept['name'] ?: $dept['description']) : '',
            'warehouse' => $wh, 'grace_days' => $term ? $term['grace_period_in_days'] : null,
            'due_date' => $order && $order['order_date'] && $term && $term['grace_period_in_days'] !== null ? date('d-m-Y', strtotime((string) $order['order_date'].' +'.(int) $term['grace_period_in_days'].' days')) : null,
            'delivery_by' => $dm ? ($dm['description'] ?: $dm['code']) : null, 'delivery_note_number' => $note !== null ? (string) $note : null,
            'external_comment' => $ext, 'payment_remark' => null,
        ];

        $out = [];
        foreach ($lines as $l) {
            $out[] = [
                'part_number' => $l['part_number'] ?: ($l['product_part_number'] ?? ''), 'part_name' => $l['part_name'] ?? '', 'ordered_quantity' => (float) ($l['ordered_quantity'] ?: $l['invoiced_quantity']),
                'invoiced_quantity' => (float) $l['invoiced_quantity'], 'price' => (float) $l['price'], 'discount' => (float) $l['discount'],
                'conversion_factor' => (float) $l['conversion_factor'], 'exchange_rate' => $l['exchange_rate'] !== null ? (float) $l['exchange_rate'] : 1.0,
                'invoice_item_type' => 'OTHER', 'order_row_type' => $l['order_row_type'], 'unit_code' => $l['unit_code'] ?? '', 'delivery_date' => $l['invoice_date'],
            ];
        }

        return ['doc' => $doc, 'lines' => $out];
    }

    private static function one(string $sql, array $p): ?array { return DB::first($sql, $p, self::C); }

    private static function bool(mixed $v): bool { return $v === true || $v === 't' || $v === 'true' || $v === 1 || $v === '1'; }

    /** One line of address text: lines 1–5, city, region, post code. */
    private static function addr(?array $a): string
    {
        if (! $a) { return ''; }
        $parts = [];
        foreach (['field1', 'field2', 'field3', 'field4', 'field5', 'locality', 'region', 'postal_code'] as $k) {
            $v = trim((string) ($a[$k] ?? ''));
            if ($v !== '') { $parts[] = $v; }
        }

        return implode(' ', $parts);
    }

    /* ------------------------------------------------------------------ credit note (81) */

    private const REF_TH = '/การเครดิตเลขที่ใบกำกับสินค้า\s*(\d{6})/u';
    private const REF_EN = '/Crediting\s+of\s+invoice\s+number[\s\S]{0,60}?(\d{6})/i';

    /** Which invoice a credit note credits, how much it was and what it is now (from the order's texts). */
    public static function creditInfo(array $doc): array
    {
        $ref = null;
        $orderId = $doc['order_id'];
        if ($orderId) {
            $txt = DB::scalar('SELECT free_text FROM erp_order_rows WHERE parent_order_id = ? AND order_row_type = 4 ORDER BY row_index LIMIT 1', [$orderId], self::C);
            $ref = self::extract((string) $txt) ?? self::extract((string) $doc['external_comment']);
        }
        $comment = (string) $doc['external_comment'];
        $cl = $comment !== '' ? preg_split('/\r?\n/', $comment) : [];
        $last = count($cl) - 1;
        for ($i = count($cl) - 1; $i >= 0; $i--) { if (preg_match('/^-?[\d,]+\.\d+$/', trim($cl[$i]))) { $last = $i; break; } }
        $clean = fn (?string $v) => $v !== null && trim($v) !== '' ? str_replace(',', '', trim($v)) : '0.00';
        $purpose = trim(implode(' ', array_map('trim', array_slice($cl, 0, max(0, $last - 1)))));
        $refDate = null;
        if ($ref) {
            $refDate = DB::scalar('SELECT max(invoice_date) FROM erp_invoices WHERE invoice_number = ?', [(int) $ref], self::C);
        }

        return ['reference' => $ref, 'reference_date' => $refDate, 'purpose' => $purpose, 'original' => $clean($cl[$last - 1] ?? ''), 'adjusted' => $clean($cl[$last] ?? '')];
    }

    private static function extract(string $t): ?string
    {
        if (preg_match(self::REF_TH, $t, $m) || preg_match(self::REF_EN, $t, $m)) { return $m[1]; }

        return null;
    }
}
