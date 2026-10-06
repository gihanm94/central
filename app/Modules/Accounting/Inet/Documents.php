<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Inet;

use App\Core\Support\DB;
use App\Modules\Accounting\Erp\ErpSettings;
use App\Modules\Accounting\Support\Log;

/**
 * erp_documents: one prepared row per invoice number (customer, order, VAT no, delivery note, credit flag, remark from the order's comment).
 * It is refreshed right after the ERP sync, only for invoices whose invoice / order / comment rows changed since the last refresh,
 * so the Generate dialog is a single indexed query. Generating itself still reads the full source rows (DocumentData) — this table is for finding, not for building.
 *
 * Credit note = the order says so (is_credit) OR the invoice number starts with 4.
 */
final class Documents
{
    private const C = ErpSettings::CONN;
    public const CREDIT_SQL = "(coalesce(o.is_credit, false) OR i.invoice_number::text LIKE '4%')";

    public static function isCredit(int|string $invoiceNumber, bool $orderIsCredit): bool
    {
        return $orderIsCredit || str_starts_with((string) $invoiceNumber, '4');
    }

    /** Refresh what changed since the last time (everything the first time). @return int rows written */
    public static function refresh(bool $full = false): int
    {
        $started = (string) DB::scalar('SELECT now()', [], self::C);
        $since   = $full ? null : (ErpSettings::get('docs.watermark') ?: null);
        $t0 = microtime(true);
        $changed = "SELECT DISTINCT i.invoice_number FROM erp_invoices i
                    LEFT JOIN erp_orders o ON o.id = i.customer_order_id LEFT JOIN erp_comments cm ON cm.id = o.external_comment_id LEFT JOIN erp_customers cu ON cu.id = i.customer_id LEFT JOIN erp_comments pcm ON pcm.id = cu.comment_id
                    WHERE i.invoice_number IS NOT NULL AND (NOT EXISTS (SELECT 1 FROM erp_documents d WHERE d.invoice_number = i.invoice_number)".
                    ($since !== null ? ' OR i.synced_at >= ?::timestamptz - interval \'5 seconds\' OR o.synced_at >= ?::timestamptz - interval \'5 seconds\' OR cm.synced_at >= ?::timestamptz - interval \'5 seconds\' OR cu.synced_at >= ?::timestamptz - interval \'5 seconds\' OR pcm.synced_at >= ?::timestamptz - interval \'5 seconds\'' : '').')';
        $par = $since !== null ? array_fill(0, 5, $since) : [];
        $n = DB::exec("INSERT INTO erp_documents (invoice_number, invoice_date, customer_id, customer_code, customer_name, order_id, order_number, vat_no, delivery_no, is_credit, remark, currency_code, line_count, amount, payment_remark, seller_name, missing, search, refreshed_at)
            SELECT x.* , lower(concat_ws(' ', x.invoice_number, x.order_number, x.vat_no, x.customer_code, x.customer_name, x.delivery_no, x.remark, x.payment_remark, x.seller_name)), now() FROM (
              SELECT i.invoice_number, min(i.invoice_date) AS invoice_date, max(i.customer_id) AS customer_id, max(i.customer_code) AS customer_code, max(c.name) AS customer_name,
                     max(i.customer_order_id) AS order_id, coalesce(max(i.customer_order_number), max(o.order_number)) AS order_number,
                     coalesce(nullif(max(o.vat_number), ''), max(c.vat_number)) AS vat_no,
                     coalesce((SELECT oi2.delivery_note_number::text FROM erp_order_deliveries d JOIN erp_order_invoices oi2 ON oi2.id = d.invoice_id WHERE d.id = max(i.delivery_row_id)),
                       (SELECT oi.delivery_note_number::text FROM erp_order_invoices oi WHERE oi.business_contact_order_id = max(i.customer_order_id) ORDER BY oi.id LIMIT 1)) AS delivery_no,
                     bool_or(".self::CREDIT_SQL.") AS is_credit,
                     nullif(left(trim(max(cm.raw_text)), 400), '') AS remark, max(i.currency_code) AS currency_code, count(*)::int AS line_count,
                     sum(coalesce(i.invoiced_quantity, 0) * coalesce(i.price, 0) * (1 - coalesce(i.discount, 0) / 100)) AS amount,
                     nullif(left(trim(max(pc.raw_text)), 600), '') AS payment_remark, nullif(trim(max(coalesce(s.first_name, '') || ' ' || coalesce(s.last_name, ''))), '') AS seller_name,
                     concat_ws(',', CASE WHEN max(c.id) IS NULL THEN 'customer' END, CASE WHEN max(o.id) IS NULL THEN 'order' END, CASE WHEN nullif(trim(max(pc.raw_text)), '') IS NULL THEN 'payment remark' END) AS missing
              FROM erp_invoices i JOIN ({$changed}) ch ON ch.invoice_number = i.invoice_number
              LEFT JOIN erp_orders o ON o.id = i.customer_order_id LEFT JOIN erp_customers c ON c.id = i.customer_id LEFT JOIN erp_comments cm ON cm.id = o.external_comment_id LEFT JOIN erp_comments pc ON pc.id = c.comment_id LEFT JOIN erp_sellers s ON s.id = o.seller_id
              GROUP BY i.invoice_number) x
            ON CONFLICT (invoice_number) DO UPDATE SET invoice_date = EXCLUDED.invoice_date, customer_id = EXCLUDED.customer_id, customer_code = EXCLUDED.customer_code, customer_name = EXCLUDED.customer_name,
              order_id = EXCLUDED.order_id, order_number = EXCLUDED.order_number, vat_no = EXCLUDED.vat_no, delivery_no = EXCLUDED.delivery_no, is_credit = EXCLUDED.is_credit, remark = EXCLUDED.remark,
              currency_code = EXCLUDED.currency_code, line_count = EXCLUDED.line_count, amount = EXCLUDED.amount, payment_remark = EXCLUDED.payment_remark, seller_name = EXCLUDED.seller_name, missing = EXCLUDED.missing, search = EXCLUDED.search, refreshed_at = now()", $par, self::C);
        ErpSettings::put('docs.watermark', $started);
        if ($n > 0) { Log::info('sync', "documents: {$n} invoice(s) prepared for Generate", ['ms' => (int) round((microtime(true) - $t0) * 1000), 'full' => $since === null]); }

        return (int) $n;
    }

    /** Never let a problem here break a sync. */
    public static function refreshQuietly(): void
    {
        try { self::refresh(); } catch (\Throwable $e) { Log::exception('sync', $e, 'documents refresh failed'); }
    }
}
