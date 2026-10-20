<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Core\Support\DB;

/**
 * ERP data for a CRM lead (hooks 'customer.by_tax', 'customer.invoices', 'customer.quotes').
 * Tax IDs are compared on their digits only, so "0-1055-51234-56-7" and "0105551234567" match.
 * The CRM never reads these tables itself.
 */
final class CustomerLink
{
    private const C = 'accounting';
    private const VAT = "regexp_replace(coalesce(%s, ''), '\\D', '', 'g')";

    public static function digits(string $taxId): string
    {
        return (string) preg_replace('/\D/', '', $taxId);
    }

    /** ERP customers with this tax ID: [{id (string), name, code, vat_number, invoices, quotes}] */
    public static function byTax(string $taxId): array
    {
        $d = self::digits($taxId);
        if (strlen($d) < 5) {
            return [];
        }
        $rows = DB::select('SELECT c.id::text AS id, c.name, c.code, c.vat_number,
                (SELECT count(*) FROM erp_documents x WHERE x.customer_id = c.id) AS invoices,
                (SELECT count(*) FROM erp_quotes q WHERE q.customer_id = c.id) AS quotes
            FROM erp_customers c WHERE '.sprintf(self::VAT, 'c.vat_number').' = ? ORDER BY invoices DESC, c.name LIMIT 20', [$d], self::C);

        return array_map(fn ($r) => $r + ['invoices' => (int) $r['invoices'], 'quotes' => (int) $r['quotes']], $rows);
    }

    /** One ERP customer by id (string: ERP ids can be larger than a browser counts exactly). */
    public static function customer(string $id): ?array
    {
        if (! preg_match('/^\d{1,19}$/', $id)) {
            return null;
        }

        return DB::first('SELECT id::text AS id, name, code, vat_number FROM erp_customers WHERE id = ?::bigint', [$id], self::C);
    }

    /**
     * Invoices with this tax ID: on the invoice itself, or of a customer that has it. Newest first.
     * @return array{rows: list<array>, total: int, sum: float}
     */
    public static function invoices(string $taxId, int $limit = 200): array
    {
        $d = self::digits($taxId);
        if (strlen($d) < 5) {
            return ['rows' => [], 'total' => 0, 'sum' => 0.0];
        }
        $where = sprintf(self::VAT, 'd.vat_no').' = ? OR d.customer_id IN (SELECT c.id FROM erp_customers c WHERE '.sprintf(self::VAT, 'c.vat_number').' = ?)';
        $tot = DB::first("SELECT count(*) AS n, COALESCE(sum(CASE WHEN d.is_credit THEN -d.amount ELSE d.amount END), 0) AS v FROM erp_documents d WHERE {$where}", [$d, $d], self::C);
        $rows = DB::select("SELECT d.invoice_number, d.invoice_date, d.customer_name, d.order_number, d.currency_code, d.amount, d.is_credit,
                coalesce((SELECT i.seller FROM erp_invoices i WHERE i.invoice_number = d.invoice_number AND coalesce(i.seller, '') <> '' LIMIT 1),
                         (SELECT trim(concat_ws(' ', s.first_name, s.last_name)) FROM erp_orders o JOIN erp_sellers s ON s.id = o.seller_id WHERE o.id = d.order_id LIMIT 1)) AS seller
            FROM erp_documents d WHERE {$where} ORDER BY d.invoice_date DESC NULLS LAST, d.invoice_number DESC LIMIT {$limit}", [$d, $d], self::C);

        return ['rows' => $rows, 'total' => (int) $tot['n'], 'sum' => (float) $tot['v']];
    }

    /** Quotes of one ERP customer, newest first. */
    public static function quotes(string $customerId, int $limit = 200): array
    {
        if (! preg_match('/^\d{1,19}$/', $customerId)) {
            return [];
        }

        return DB::select("SELECT q.id::text AS id, q.order_number, q.quote_date, q.valid_through_date, q.quote_description, q.status, q.life_cycle_state,
                q.edited_order_amount_excluding_vat AS amount, q.our_reference_name,
                (SELECT trim(concat_ws(' ', s.first_name, s.last_name)) FROM erp_sellers s WHERE s.id = q.seller_id) AS seller
            FROM erp_quotes q WHERE q.customer_id = ?::bigint ORDER BY q.quote_date DESC NULLS LAST, q.id DESC LIMIT {$limit}", [$customerId], self::C);
    }
}
