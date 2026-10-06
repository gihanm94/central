<?php
declare(strict_types=1);

namespace App\Modules\CRM\Support;

use App\Core\Support\DB;

/** Yearly sales targets per department, and what was actually won (converted to the base currency). */
final class Targets
{
    public static function baseCurrency(): string
    {
        return (string) (DB::scalar('SELECT code FROM currencies WHERE is_base LIMIT 1', [], 'crm') ?: 'THB');
    }

    /** Won value per quarter of a year: [1 => x, 2 => x, 3 => x, 4 => x]. $departmentId null = every department. */
    public static function live(?int $departmentId, int $year): array
    {
        $sql = "SELECT extract(quarter FROM COALESCE(t.close_at, t.updated_at))::int AS q, COALESCE(sum(COALESCE(t.amount, 0) * COALESCE(c.rate_to_base, 1)), 0) AS v
                  FROM opportunities t LEFT JOIN currencies c ON c.code = t.currency
                 WHERE t.deleted_at IS NULL AND t.opportunity_stage = 'CLOSED_WON' AND extract(year FROM COALESCE(t.close_at, t.updated_at)) = ?"
              .($departmentId ? ' AND t.department_id = ?' : '').' GROUP BY 1';
        $out = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0];
        foreach (DB::select($sql, $departmentId ? [$year, $departmentId] : [$year], 'crm') as $r) {
            $out[(int) $r['q']] = (float) $r['v'];
        }

        return $out;
    }

    /** The saved row for a department and year (or an empty one). */
    public static function row(int $departmentId, int $year): array
    {
        return DB::first('SELECT * FROM sales_targets WHERE department_id = ? AND year = ?', [$departmentId, $year], 'crm')
            ?? ['department_id' => $departmentId, 'year' => $year, 'q1' => 0, 'q2' => 0, 'q3' => 0, 'q4' => 0, 'total' => 0, 'a1' => null, 'a2' => null, 'a3' => null, 'a4' => null, 'actuals_saved_at' => null];
    }

    /**
     * Target and actual for the dashboard. One department, or (null) all of them added up.
     * @return array{target: array, total: float, actual: array, actual_total: float}
     */
    public static function summary(?int $departmentId, int $year): array
    {
        $rows = $departmentId
            ? DB::select('SELECT q1, q2, q3, q4, total FROM sales_targets WHERE department_id = ? AND year = ?', [$departmentId, $year], 'crm')
            : DB::select('SELECT q1, q2, q3, q4, total FROM sales_targets WHERE year = ?', [$year], 'crm');
        $target = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0];
        $total  = 0.0;
        foreach ($rows as $r) {
            foreach ([1, 2, 3, 4] as $q) {
                $target[$q] += (float) $r['q'.$q];
            }
            $total += (float) $r['total'];
        }
        $actual = self::live($departmentId, $year);

        return ['target' => $target, 'total' => $total, 'actual' => $actual, 'actual_total' => array_sum($actual)];
    }
}
