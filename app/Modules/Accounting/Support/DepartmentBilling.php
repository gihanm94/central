<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Core\Auth\CurrentUser;
use App\Core\Support\DB;
use App\Modules\Accounting\Billing\BillingService;

/**
 * Billing notes of one department, for ERP → Billing notes in User Management (hooks 'erp.billing', 'erp.billing_pdf').
 * A note belongs to a department when one of its invoices does: the stamped Inet's department, or else the department
 * set on the order's seller (Accounting → Sellers). Permission erp_billing.view; administrators see every department.
 */
final class DepartmentBilling
{
    private const C = 'accounting';

    /** The department of an invoice number, as SQL (used for filtering and for the department column). */
    private const DEPT_OF = "coalesce(
        (SELECT i.app_department_id FROM inets i WHERE i.no_invoice = bi.invoice_number AND i.app_department_id IS NOT NULL LIMIT 1),
        (SELECT s.app_department_id FROM erp_invoices ei JOIN erp_orders o ON o.id = ei.customer_order_id JOIN erp_sellers s ON s.id = o.seller_id
          WHERE ei.invoice_number = bi.invoice_number AND s.app_department_id IS NOT NULL LIMIT 1))";

    /** @return int|null|false the department to show, null = every department (admin), false = nothing */
    private static function department(CurrentUser $u, int $pick): int|null|false
    {
        if (! $u->can('erp_billing', 'view')) {
            return false;
        }
        if ($u->isAdmin()) {
            return $pick > 0 ? $pick : null;
        }

        return $u->department_id ?: false;
    }

    /**
     * @param array{q?:string, from?:string, to?:string, department?:int, page?:int} $f
     * @return array{rows: list<array>, total: int, page: int, pages: int, all_departments: bool}
     */
    public static function list(CurrentUser $u, array $f): array
    {
        $per = 25;
        $page = max(1, (int) ($f['page'] ?? 1));
        $dept = self::department($u, (int) ($f['department'] ?? 0));
        if ($dept === false) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'all_departments' => false];
        }
        $w = ['TRUE'];
        $p = [];
        if ($dept !== null) {
            $w[] = 'EXISTS (SELECT 1 FROM billing_note_invoices bi WHERE bi.billing_id = n.id AND '.self::DEPT_OF.' = ?)';
            $p[] = $dept;
        }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 100)).'%';
            $w[] = '(n.billing_number ILIKE ? OR n.customer_name ILIKE ? OR n.customer_code ILIKE ? OR EXISTS (SELECT 1 FROM billing_note_invoices x WHERE x.billing_id = n.id AND x.invoice_number::text ILIKE ?))';
            array_push($p, $like, $like, $like, $like);
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f[$k] ?? ''))) {
                $w[] = "n.billing_at {$op} ?::date";
                $p[] = $f[$k];
            }
        }
        $where = implode(' AND ', $w);
        $total = (int) DB::scalar("SELECT count(*) FROM billing_notes n WHERE {$where}", $p, self::C);
        $rows = DB::select("SELECT n.id, n.billing_number, n.billing_at, n.remind_date, n.customer_code, n.customer_name, n.total_amount, n.currency_code, n.is_paid, n.is_complete,
                (SELECT count(*) FROM billing_note_invoices c WHERE c.billing_id = n.id) AS invoice_count,
                (SELECT string_agg(c.invoice_number::text, ', ' ORDER BY c.invoice_number) FROM billing_note_invoices c WHERE c.billing_id = n.id) AS invoice_numbers,
                (SELECT ".self::DEPT_OF." FROM billing_note_invoices bi WHERE bi.billing_id = n.id ORDER BY bi.id LIMIT 1) AS app_department_id
            FROM billing_notes n WHERE {$where} ORDER BY n.billing_at DESC, n.id DESC LIMIT {$per} OFFSET ".(($page - 1) * $per), $p, self::C);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $per)), 'all_departments' => $dept === null];
    }

    /** The PDF of one note, if this person may have it (made on the spot when it was never made). @return array{path:string, name:string, label:string}|null */
    public static function pdf(CurrentUser $u, int $id): ?array
    {
        $dept = self::department($u, 0);
        if ($dept === false) {
            return null;
        }
        $n = DB::first('SELECT id, billing_number FROM billing_notes n WHERE n.id = ?'
            .($dept !== null ? ' AND EXISTS (SELECT 1 FROM billing_note_invoices bi WHERE bi.billing_id = n.id AND '.self::DEPT_OF.' = ?)' : ''),
            $dept !== null ? [$id, $dept] : [$id], self::C);
        if (! $n) {
            return null;                                    // not found, or another department's: same answer
        }
        $path = BillingService::pdf((int) $n['id']);
        $base = realpath(BASE_PATH.'/storage/billing');
        $real = realpath($path);
        if (! $base || ! $real || ! str_starts_with($real, $base.DIRECTORY_SEPARATOR) || ! is_file($real)) {
            return null;
        }

        return ['path' => $real, 'name' => preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $n['billing_number']).'.pdf', 'label' => (string) $n['billing_number']];
    }
}
