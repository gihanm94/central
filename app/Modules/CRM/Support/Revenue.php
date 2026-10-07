<?php
declare(strict_types=1);

namespace App\Modules\CRM\Support;

use App\Core\Support\DB;

/**
 * Revenue from Accounting: invoiced amount (credit notes subtracted) by quarter, for an ERP department.
 * The ERP links an invoice to an order, the order to a seller, and the seller to an ERP department; a CRM department
 * points at its ERP department with departments.erp_department_id. Other currencies are converted with the CRM rates.
 */
final class Revenue
{
    /** ERP department id of a CRM/core department, or null when it has none. */
    public static function erpId(int $departmentId): ?int
    {
        $v = DB::scalar('SELECT erp_department_id FROM departments WHERE id = ?', [$departmentId]);

        return $v ? (int) $v : null;
    }

    /** True when at least one department is tied to an ERP department. */
    public static function anyMapped(): bool { return (bool) DB::scalar('SELECT 1 FROM departments WHERE erp_department_id IS NOT NULL AND deleted_at IS NULL LIMIT 1'); }

    /**
     * Invoiced revenue per quarter [1 => x … 4 => x] in the base currency. $erpDepartmentId null = the whole company.
     * @return array<int, float>
     */
    public static function quarters(?int $erpDepartmentId, int $year): array
    {
        $out = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0];
        try {
            $sql = "SELECT extract(quarter FROM (d.invoice_date AT TIME ZONE 'Asia/Bangkok'))::int AS q, COALESCE(NULLIF(d.currency_code, ''), 'THB') AS cur,
                           COALESCE(sum(CASE WHEN d.is_credit THEN -d.amount ELSE d.amount END), 0) AS v
                      FROM erp_documents d ".($erpDepartmentId ? 'JOIN erp_orders o ON o.id = d.order_id JOIN erp_sellers s ON s.id = o.seller_id ' : '')."
                     WHERE d.invoice_date IS NOT NULL AND extract(year FROM (d.invoice_date AT TIME ZONE 'Asia/Bangkok')) = ?".($erpDepartmentId ? ' AND s.department_id = ?' : '').' GROUP BY 1, 2';
            $rows = DB::select($sql, $erpDepartmentId ? [$year, $erpDepartmentId] : [$year], 'accounting');
        } catch (\Throwable) {
            return $out;                                  // Accounting not set up yet
        }
        $rates = array_column(DB::select('SELECT code, rate_to_base FROM currencies', [], 'crm'), 'rate_to_base', 'code');
        foreach ($rows as $r) {
            $out[(int) $r['q']] += (float) $r['v'] * (float) ($rates[strtoupper((string) $r['cur'])] ?? 1);
        }

        return $out;
    }
}
