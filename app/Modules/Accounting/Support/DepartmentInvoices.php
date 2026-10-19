<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Core\Auth\CurrentUser;
use App\Core\Support\DB;

/**
 * Stamped (INET-signed) Inets of one department, for ERP → Invoices in User Management.
 * Served through the module hooks 'erp.invoices' and 'erp.invoice_pdf', so the core page never reads this
 * module's database itself. Rules enforced here too: permission erp_invoices.view, people see only their own
 * department (inets.app_department_id, copied from the seller), administrators see every department.
 */
final class DepartmentInvoices
{
    private const C = 'accounting';
    public const DOCS = ['388' => 'Tax invoice', 't01' => 'Receipt', '81' => 'Credit note'];
    private const SIGNED = '(t.inet_pdf_388 IS NOT NULL OR t.inet_pdf_t01 IS NOT NULL OR t.inet_pdf_81 IS NOT NULL)';

    /** The department this person may see, or null for "every department" (administrators). @return int|null|false false = nothing */
    private static function department(CurrentUser $u, int $pick): int|null|false
    {
        if (! $u->can('erp_invoices', 'view')) {
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
        $w = [self::SIGNED];
        $p = [];
        if ($dept !== null) {
            $w[] = 't.app_department_id = ?';
            $p[] = $dept;
        }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 100)).'%';
            $w[] = '(t.no_invoice::text ILIKE ? OR t.customer_name ILIKE ? OR t.customer_code ILIKE ? OR t.customer_order_number ILIKE ?)';
            array_push($p, $like, $like, $like, $like);
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f[$k] ?? ''))) {
                $w[] = "t.invoice_date {$op} ?::date";
                $p[] = $f[$k];
            }
        }
        $where = implode(' AND ', $w);
        $total = (int) DB::scalar("SELECT count(*) FROM inets t WHERE {$where}", $p, self::C);
        $rows = DB::select("SELECT t.id, t.no_invoice, t.invoice_date, t.is_credit, t.customer_code, t.customer_name, t.customer_order_number, t.seller_name,
                t.total_amount, t.currency_code, t.app_department_id, t.inet_pdf_388 IS NOT NULL AS s388, t.inet_pdf_t01 IS NOT NULL AS st01, t.inet_pdf_81 IS NOT NULL AS s81
            FROM inets t WHERE {$where} ORDER BY t.invoice_date DESC NULLS LAST, t.no_invoice DESC LIMIT {$per} OFFSET ".(($page - 1) * $per), $p, self::C);
        foreach ($rows as &$r) {
            $r['documents'] = array_values(array_filter(['388', 't01', '81'], fn ($d) => filter_var($r['s'.$d], FILTER_VALIDATE_BOOL)));
            unset($r['s388'], $r['st01'], $r['s81']);
        }

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $per)), 'all_departments' => $dept === null];
    }

    /** The stamped PDF of one document, if this person may have it. @return array{path:string, name:string, label:string}|null */
    public static function pdf(CurrentUser $u, int $id, string $doc): ?array
    {
        $dept = self::department($u, 0);
        if ($dept === false || ! isset(self::DOCS[$doc])) {
            return null;
        }
        $r = DB::first('SELECT no_invoice, app_department_id, inet_pdf_'.$doc.' AS f FROM inets WHERE id = ?', [$id], self::C);
        if (! $r || ($dept !== null && (int) $r['app_department_id'] !== $dept)) {
            return null;                                    // not found, or another department's: same answer
        }
        $key = (string) $r['f'];
        if ($key === '' || ! str_starts_with($key, 'inet/') || str_contains($key, '..') || str_contains($key, "\0")) {
            return null;
        }
        $base = realpath(BASE_PATH.'/storage/inet');
        $path = realpath(BASE_PATH.'/storage/'.$key);
        if (! $base || ! $path || ! str_starts_with($path, $base.DIRECTORY_SEPARATOR) || ! is_file($path)) {
            return null;
        }

        return ['path' => $path, 'name' => $r['no_invoice'].'_'.strtoupper($doc).'_signed.pdf', 'label' => $r['no_invoice'].' '.self::DOCS[$doc]];
    }
}
