<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Core\Support\DB;
use App\Modules\Accounting\Erp\ErpSettings;

/**
 * Revenue rankings for the Management overview (hook 'revenue.ranking'): total, months, top customers, sellers and
 * departments (the app department set on each seller) between two dates. Revenue = invoice goods lines in THB, credit
 * notes subtract themselves (Dashboard::LINE). Kept five minutes per date range in storage/cache.
 */
final class Ranking
{
    private const C = ErpSettings::CONN;
    private const TTL = 300;

    /** @return array{total: float, invoices: int, customers_count: int, previous: float, months: array<string,float>, customers: list<array>, sellers: list<array>, departments: list<array>} */
    public static function get(string $from, string $to): array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            throw new \InvalidArgumentException('Dates must be YYYY-MM-DD.');
        }
        $file = BASE_PATH.'/storage/cache/revenue-ranking-'.md5($from.$to).'.json';
        if (is_file($file) && time() - filemtime($file) < self::TTL && is_array($j = json_decode((string) file_get_contents($file), true))) {
            return $j;
        }
        $line = Dashboard::LINE;
        $day = "(i.invoice_date AT TIME ZONE 'Asia/Bangkok')::date";
        $base = "FROM erp_invoices i LEFT JOIN erp_orders o ON o.id = i.customer_order_id LEFT JOIN erp_sellers s ON s.id = o.seller_id
                 LEFT JOIN erp_customers c ON c.id = i.customer_id
                 WHERE i.invoice_date IS NOT NULL AND {$day} BETWEEN ?::date AND ?::date AND coalesce(i.order_row_type, 1) = 1";
        $p = [$from, $to];
        $sum = DB::first("SELECT coalesce(round(sum({$line})::numeric, 2), 0) AS v, count(DISTINCT i.invoice_number) AS n, count(DISTINCT i.customer_id) AS c {$base}", $p, self::C);

        // the same number of days just before, for the change
        $days = (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%a') + 1;
        $pTo = (new \DateTimeImmutable($from))->modify('-1 day');
        $pFrom = $pTo->modify('-'.($days - 1).' days');
        $prev = (float) DB::scalar("SELECT coalesce(round(sum({$line})::numeric, 2), 0) {$base}", [$pFrom->format('Y-m-d'), $pTo->format('Y-m-d')], self::C);

        $months = [];
        foreach (DB::select("SELECT to_char({$day}, 'YYYY-MM') AS ym, round(sum({$line})::numeric, 2) AS v {$base} GROUP BY 1 ORDER BY 1", $p, self::C) as $r) {
            $months[$r['ym']] = (float) $r['v'];
        }
        $customers = DB::select("SELECT coalesce(nullif(c.name, ''), i.customer_code, '—') AS name, max(i.customer_code) AS code, round(sum({$line})::numeric, 2) AS v,
                count(DISTINCT i.invoice_number) AS n {$base} GROUP BY 1 ORDER BY v DESC LIMIT 10", $p, self::C);
        $sellers = DB::select("SELECT s.id AS seller_id, coalesce(nullif(trim(concat_ws(' ', s.first_name, s.last_name)), ''), nullif(i.seller, ''), '—') AS name,
                max(s.user_id) AS user_id, max(s.app_department_id) AS department_id, round(sum({$line})::numeric, 2) AS v,
                count(DISTINCT i.invoice_number) AS n, count(DISTINCT i.customer_id) AS customers {$base} GROUP BY 1, 2 ORDER BY v DESC LIMIT 20", $p, self::C);
        $departments = DB::select("SELECT s.app_department_id AS department_id, round(sum({$line})::numeric, 2) AS v, count(DISTINCT s.id) AS sellers,
                count(DISTINCT i.invoice_number) AS n {$base} GROUP BY 1 ORDER BY v DESC", $p, self::C);

        $out = ['total' => (float) $sum['v'], 'invoices' => (int) $sum['n'], 'customers_count' => (int) $sum['c'], 'previous' => $prev,
            'previous_range' => [$pFrom->format('Y-m-d'), $pTo->format('Y-m-d')], 'months' => $months,
            'customers' => array_map(fn ($r) => ['name' => $r['name'], 'code' => $r['code'], 'v' => (float) $r['v'], 'n' => (int) $r['n']], $customers),
            'sellers' => array_map(fn ($r) => ['seller_id' => $r['seller_id'] !== null ? (int) $r['seller_id'] : null, 'name' => $r['name'], 'user_id' => $r['user_id'] !== null ? (int) $r['user_id'] : null,
                'department_id' => $r['department_id'] !== null ? (int) $r['department_id'] : null, 'v' => (float) $r['v'], 'n' => (int) $r['n'], 'customers' => (int) $r['customers']], $sellers),
            'departments' => array_map(fn ($r) => ['department_id' => $r['department_id'] !== null ? (int) $r['department_id'] : null, 'v' => (float) $r['v'], 'sellers' => (int) $r['sellers'], 'n' => (int) $r['n']], $departments),
            'built' => date('c')];
        @file_put_contents($file, json_encode($out));

        return $out;
    }
}
