<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Core\Support\DB;

/**
 * Seller and department on every invoice line. Two departments: the seller's ERP department (Persons.DepartmentId →
 * erp_departments, copied as code and name) and the app department picked by hand on the seller. The invoice log's "seller" is the seller's employee number; the seller's
 * department is the one picked by hand on the seller (this app's departments, core database). Ids and names are copied
 * onto erp_sellers / erp_invoices so lists and reports read them without a lookup and keep them after a rename.
 * Called after an invoice or seller sync and after a seller is saved; only rows that change are written.
 */
final class SellerStamp
{
    private const C = 'accounting';

    /** @return int invoice lines whose seller or department changed */
    public static function run(): int
    {
        // 1. department names on the sellers (this app's departments live in the core database)
        $names = array_column(DB::select('SELECT id, name FROM departments', [], 'core'), 'name', 'id');
        foreach (DB::select('SELECT DISTINCT app_department_id AS id FROM erp_sellers WHERE app_department_id IS NOT NULL', [], self::C) as $r) {
            DB::exec('UPDATE erp_sellers SET app_department_name = ? WHERE app_department_id = ? AND app_department_name IS DISTINCT FROM ?',
                [$names[$r['id']] ?? null, (int) $r['id'], $names[$r['id']] ?? null], self::C);
        }
        DB::exec('UPDATE erp_sellers SET app_department_name = NULL WHERE app_department_id IS NULL AND app_department_name IS NOT NULL', [], self::C);

        // 2. the seller's ERP department code and name (cleared when the seller has none or it is not synced)
        DB::exec("UPDATE erp_sellers s SET department_code = nullif(trim(d.code), ''), department_name = coalesce(nullif(trim(d.name), ''), nullif(trim(d.description), ''))
                    FROM erp_departments d
                   WHERE d.id = s.department_id
                     AND (s.department_code IS DISTINCT FROM nullif(trim(d.code), '') OR s.department_name IS DISTINCT FROM coalesce(nullif(trim(d.name), ''), nullif(trim(d.description), '')))", [], self::C);
        DB::exec('UPDATE erp_sellers s SET department_code = NULL, department_name = NULL
                   WHERE (s.department_code IS NOT NULL OR s.department_name IS NOT NULL)
                     AND NOT EXISTS (SELECT 1 FROM erp_departments d WHERE d.id = s.department_id)', [], self::C);

        // 3. invoice lines: seller (by employee number) and the seller's departments
        return DB::exec("UPDATE erp_invoices i
                            SET seller_id = s.id,
                                seller_name = nullif(trim(concat_ws(' ', s.first_name, s.last_name)), ''),
                                app_department_id = s.app_department_id,
                                app_department_name = s.app_department_name,
                                erp_department_id = s.department_id,
                                department_code = s.department_code,
                                department_name = s.department_name
                           FROM erp_sellers s
                          WHERE upper(trim(i.seller)) = upper(trim(s.employee_number)) AND trim(coalesce(s.employee_number, '')) <> ''
                            AND (i.seller_id IS DISTINCT FROM s.id
                                 OR i.seller_name IS DISTINCT FROM nullif(trim(concat_ws(' ', s.first_name, s.last_name)), '')
                                 OR i.app_department_id IS DISTINCT FROM s.app_department_id
                                 OR i.app_department_name IS DISTINCT FROM s.app_department_name
                                 OR i.erp_department_id IS DISTINCT FROM s.department_id
                                 OR i.department_code IS DISTINCT FROM s.department_code
                                 OR i.department_name IS DISTINCT FROM s.department_name)", [], self::C);
    }

    /** Same as run(), but a failure is logged and never stops the sync or the save that called it. */
    public static function quietly(): void
    {
        try {
            $n = self::run();
            if ($n) { Log::info('sync', "invoice seller / department stamped on {$n} line(s)"); }
        } catch (\Throwable $e) {
            Log::exception('sync', $e, 'invoice seller / department not stamped');
        }
    }
}
