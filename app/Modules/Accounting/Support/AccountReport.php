<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Core\Support\DB;
use App\Modules\Accounting\Controllers\AccountReportController;

/**
 * Figures of the account report for Management → Account report (through the 'account.report' hook):
 * income and expenses by year, by type, by department, by month, department × type and the biggest accounts.
 * $filters: year (int|null), department (string|null).
 */
final class AccountReport
{
    public static function summary(array $filters): array
    {
        $years = array_map('intval', array_column(DB::select('SELECT DISTINCT year FROM account_reports WHERE deleted_at IS NULL ORDER BY year', [], 'accounting'), 'year'));
        $departments = array_column(DB::select('SELECT DISTINCT department FROM account_reports WHERE deleted_at IS NULL ORDER BY department', [], 'accounting'), 'department');

        $year = in_array((int) ($filters['year'] ?? 0), $years, true) ? (int) $filters['year'] : null;
        $dept = in_array((string) ($filters['department'] ?? ''), $departments, true) ? (string) $filters['department'] : null;
        // months and the "vs last year" tile need one year: the one picked, else the latest
        $focus = $year ?? ($years ? max($years) : (int) date('Y'));

        $sum = "COALESCE(SUM(amount) FILTER (WHERE kind = 'income'), 0)::float AS income, COALESCE(SUM(amount) FILTER (WHERE kind = 'expense'), 0)::float AS expense";
        $where = function (bool $byYear, bool $byDept) use ($year, $dept): array {
            $w = ['deleted_at IS NULL'];
            $p = [];
            if ($byYear && $year !== null) { $w[] = 'year = ?'; $p[] = $year; }
            if ($byDept && $dept !== null) { $w[] = 'department = ?'; $p[] = $dept; }

            return [implode(' AND ', $w), $p];
        };

        [$w, $p] = $where(true, true);
        $totals = DB::first("SELECT {$sum}, COUNT(*) AS lines FROM account_reports WHERE {$w}", $p, 'accounting');

        [$w, $p] = $where(false, true);
        $byYear = DB::select("SELECT year, {$sum} FROM account_reports WHERE {$w} GROUP BY year ORDER BY year", $p, 'accounting');
        $focusRow = DB::first("SELECT {$sum} FROM account_reports WHERE {$w} AND year = ?", [...$p, $focus], 'accounting');
        $prevRow  = DB::first("SELECT {$sum}, COUNT(*) AS lines FROM account_reports WHERE {$w} AND year = ?", [...$p, $focus - 1], 'accounting');
        $byMonth  = DB::select("SELECT month, {$sum} FROM account_reports WHERE {$w} AND year = ? GROUP BY month ORDER BY month", [...$p, $focus], 'accounting');
        $prevMonth = DB::select("SELECT month, {$sum} FROM account_reports WHERE {$w} AND year = ? GROUP BY month ORDER BY month", [...$p, $focus - 1], 'accounting');

        [$w, $p] = $where(true, true);
        $byType = DB::select("SELECT type, kind, SUM(amount)::float AS amount FROM account_reports WHERE {$w} GROUP BY type, kind ORDER BY SUM(amount) DESC", $p, 'accounting');
        $top = DB::select("SELECT * FROM (SELECT account, MIN(name) AS name, kind, SUM(amount)::float AS amount, ROW_NUMBER() OVER (PARTITION BY kind ORDER BY SUM(amount) DESC) AS rn
                             FROM account_reports WHERE {$w} GROUP BY account, kind) x WHERE rn <= 6 ORDER BY amount DESC", $p, 'accounting');

        [$w, $p] = $where(true, false);
        $byDept = DB::select("SELECT department, {$sum} FROM account_reports WHERE {$w} GROUP BY department ORDER BY department", $p, 'accounting');
        $matrix = DB::select("SELECT department, type, SUM(amount)::float AS amount FROM account_reports WHERE {$w} GROUP BY department, type", $p, 'accounting');

        $months = [];
        foreach ([$byMonth, $prevMonth] as $i => $rows) {
            $m = array_fill(1, 12, ['income' => 0.0, 'expense' => 0.0]);
            foreach ($rows as $r) { $m[(int) $r['month']] = ['income' => (float) $r['income'], 'expense' => (float) $r['expense']]; }
            $months[$i] = $m;
        }

        return [
            'years' => $years, 'departments' => $departments, 'year' => $year, 'department' => $dept, 'focus' => $focus,
            'types' => AccountReportController::TYPES, 'kinds' => AccountReportController::KINDS,
            'totals' => ['income' => (float) $totals['income'], 'expense' => (float) $totals['expense'], 'lines' => (int) $totals['lines']],
            'focusYear' => ['income' => (float) $focusRow['income'], 'expense' => (float) $focusRow['expense']],
            'prevYear' => ['income' => (float) $prevRow['income'], 'expense' => (float) $prevRow['expense'], 'lines' => (int) $prevRow['lines']],
            'byYear' => $byYear, 'byType' => $byType, 'byDepartment' => $byDept, 'months' => $months[0], 'prevMonths' => $months[1],
            'matrix' => $matrix,
            'topIncome' => array_values(array_filter($top, fn ($r) => $r['kind'] === 'income')),
            'topExpense' => array_values(array_filter($top, fn ($r) => $r['kind'] === 'expense')),
        ];
    }
}
