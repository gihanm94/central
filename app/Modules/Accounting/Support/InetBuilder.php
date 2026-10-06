<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Core\Support\DB;
use App\Modules\Accounting\Erp\ErpSettings;

/** Builds (or refreshes) the e-tax row of an invoice from the copied ERP data. */
final class InetBuilder
{
    private const C = ErpSettings::CONN;

    /** @return array|null the values of the inets row, null when the invoice is not in the copy */
    public static function compute(int $invoiceNumber): ?array
    {
        $lines = DB::select('SELECT * FROM erp_invoices WHERE invoice_number = ?', [$invoiceNumber], self::C);
        if (! $lines) {
            return null;
        }
        $f      = $lines[0];
        $order  = $f['customer_order_id'] ? DB::first('SELECT * FROM erp_orders WHERE id = ?', [$f['customer_order_id']], self::C) : null;
        $cust   = $f['customer_id'] ? DB::first('SELECT * FROM erp_customers WHERE id = ?', [$f['customer_id']], self::C) : null;
        $seller = $order && $order['seller_id'] ? DB::first('SELECT * FROM erp_sellers WHERE id = ?', [$order['seller_id']], self::C) : null;
        $dept   = $seller && $seller['department_id'] ? DB::scalar('SELECT coalesce(nullif(name, \'\'), description) FROM erp_departments WHERE id = ?', [$seller['department_id']], self::C) : null;
        $amount = 0.0;
        foreach ($lines as $l) {
            $amount += (float) $l['invoiced_quantity'] * (float) $l['price'] * (1 - (float) $l['discount'] / 100);
        }
        $rate = $order ? DB::scalar('SELECT v.percentage FROM erp_order_rows r JOIN erp_vats v ON v.id = r.vat_rate_id WHERE r.parent_order_id = ? AND r.vat_rate_id IS NOT NULL ORDER BY r.row_index LIMIT 1', [$order['id']], self::C) : null;
        $rate   = $rate !== null ? (float) $rate : 0.0;
        $email  = $cust ? DB::scalar("SELECT communication_address_value FROM erp_delivery_contacts WHERE customer_id = ? AND communication_address_value LIKE '%@%' ORDER BY id LIMIT 1", [$cust['id']], self::C) : null;
        $note   = $order ? DB::scalar('SELECT delivery_note_number FROM erp_order_invoices WHERE business_contact_order_id = ? ORDER BY id LIMIT 1', [$order['id']], self::C) : null;
        $cur    = $f['currency_code'] ?: 'THB';
        $isCredit = $order ? filter_var($order['is_credit'], FILTER_VALIDATE_BOOL) : false;

        return [
            'no_invoice' => $invoiceNumber, 'invoice_date' => $f['invoice_date'], 'is_credit' => $isCredit, 'is_international' => strtoupper($cur) !== 'THB',
            'order_id' => $order['id'] ?? null, 'customer_order_number' => $f['customer_order_number'] ?: ($order['order_number'] ?? null), 'delivery_note_number' => $note,
            'seller_name' => $seller ? trim($seller['first_name'].' '.$seller['last_name']) : ($f['seller'] ?: null), 'department_name' => $dept,
            'customer_id' => $f['customer_id'], 'customer_code' => $f['customer_code'] ?: ($cust['code'] ?? null), 'customer_name' => $cust['name'] ?? null,
            'currency_code' => $cur, 'tax_id' => $cust ? ($cust['vat_number'] ?: $cust['corporation_identification_number']) : ($order['vat_number'] ?? null), 'email' => $email,
            'amount' => round($amount, 6), 'tax_amount' => round($amount * $rate / 100, 6), 'total_amount' => round($amount * (1 + $rate / 100), 6),
            'exchange_rate' => $f['exchange_rate'], 'tax_rate' => $rate, 'product_count' => count($lines),
            '_vat_no' => $order['vat_number'] ?? ($cust['vat_number'] ?? null),
        ];
    }

    /** Insert, or refresh the figures of an existing row (the document states stay). */
    public static function save(int $invoiceNumber, bool $autoPdf, int $by): bool
    {
        $v = self::compute($invoiceNumber);
        if ($v === null) {
            return false;
        }
        unset($v['_vat_no']);
        $v["auto_pdf"] = $autoPdf;
        $cols = array_keys($v);
        $set  = implode(', ', array_map(fn ($c) => "{$c} = EXCLUDED.{$c}", array_diff($cols, ['no_invoice', 'is_credit']))).', updated_by = EXCLUDED.updated_by, updated_at = now()';
        DB::exec('INSERT INTO inets ('.implode(',', $cols).', created_by, updated_by) VALUES ('.implode(',', array_fill(0, count($cols), '?')).', ?, ?)
                  ON CONFLICT (no_invoice, is_credit) DO UPDATE SET '.$set, [...array_map(fn ($x) => is_bool($x) ? ($x ? 'true' : 'false') : $x, array_values($v)), $by, $by], self::C);

        return true;
    }
}
