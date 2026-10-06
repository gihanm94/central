<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Core\Support\DB;
use App\Modules\Accounting\Erp\ErpSettings;

/**
 * Numbers of the Accounting overview. Revenue = invoice lines (goods rows) × price × (1 − discount) × exchange rate, in THB, credit notes
 * subtract themselves. Heavy sums are kept for five minutes in storage/cache/acct-dashboard.json.
 */
final class Dashboard
{
    private const C = ErpSettings::CONN;
    private const TTL = 300;

    /** invoice line value in THB (the same rule as the e-tax JSON) */
    private const LINE = "(coalesce(i.invoiced_quantity, 0) / CASE WHEN coalesce(i.conversion_factor, 0) = 0 THEN 1 ELSE i.conversion_factor END) * coalesce(i.price, 0) * (1 - coalesce(i.discount, 0) / 100) * coalesce(nullif(i.exchange_rate, 0), 1)";
    private const BKK = "(i.invoice_date AT TIME ZONE 'Asia/Bangkok')";

    public static function get(bool $fresh = false): array
    {
        $file = BASE_PATH.'/storage/cache/acct-dashboard.json';
        if (! $fresh && is_file($file) && time() - filemtime($file) < self::TTL) {
            $j = json_decode((string) file_get_contents($file), true);
            if (is_array($j)) { return $j; }
        }
        $d = self::build();
        @file_put_contents($file, json_encode($d));

        return $d;
    }

    private static function q(string $sql, array $p = []): array { return DB::select($sql, $p, self::C); }

    private static function build(): array
    {
        $line = self::LINE;
        $bkk = self::BKK;
        $year = (int) date('Y');
        $month = (int) date('n');
        $d = ['built' => date('c'), 'year' => $year];

        // ---------- revenue
        $d['by_year'] = self::q("SELECT extract(year FROM {$bkk})::int AS y, round(sum({$line})::numeric, 2) AS v FROM erp_invoices i WHERE i.invoice_date IS NOT NULL AND coalesce(i.order_row_type, 1) = 1 GROUP BY 1 ORDER BY 1");
        $m = self::q("SELECT extract(year FROM {$bkk})::int AS y, extract(month FROM {$bkk})::int AS m, round(sum({$line})::numeric, 2) AS v, count(DISTINCT i.invoice_number) AS n
                        FROM erp_invoices i WHERE i.invoice_date >= make_date(?, 1, 1) AND coalesce(i.order_row_type, 1) = 1 GROUP BY 1, 2", [$year - 1]);
        $cur = array_fill(1, 12, 0.0); $prev = array_fill(1, 12, 0.0); $cnt = array_fill(1, 12, 0);
        foreach ($m as $r) {
            if ((int) $r['y'] === $year) { $cur[(int) $r['m']] = (float) $r['v']; $cnt[(int) $r['m']] = (int) $r['n']; }
            else { $prev[(int) $r['m']] = (float) $r['v']; }
        }
        $d['month_cur'] = array_values($cur); $d['month_prev'] = array_values($prev);
        $d['kpi'] = [
            'month' => $cur[$month], 'month_prev_year' => $prev[$month], 'month_last' => $month > 1 ? $cur[$month - 1] : ($prev[12] ?? 0),
            'ytd' => array_sum(array_slice($cur, 0, $month)), 'ytd_prev' => array_sum(array_slice($prev, 0, $month)), 'invoices_month' => $cnt[$month],
        ];
        $since = date('Y-m-d', strtotime('-12 months'));
        $d['customers'] = self::q("SELECT coalesce(nullif(c.name, ''), i.customer_code, '—') AS name, round(sum({$line})::numeric, 2) AS v FROM erp_invoices i LEFT JOIN erp_customers c ON c.id = i.customer_id
                                    WHERE i.invoice_date >= ? AND coalesce(i.order_row_type, 1) = 1 GROUP BY 1 ORDER BY v DESC LIMIT 10", [$since]);
        $d['sellers'] = self::q("SELECT coalesce(nullif(i.seller, ''), '—') AS name, round(sum({$line})::numeric, 2) AS v FROM erp_invoices i WHERE i.invoice_date >= ? AND coalesce(i.order_row_type, 1) = 1 GROUP BY 1 ORDER BY v DESC LIMIT 8", [$since]);
        $d['products'] = self::q("SELECT coalesce(nullif(i.part_number, ''), '—') AS name, round(sum({$line})::numeric, 2) AS v FROM erp_invoices i WHERE i.invoice_date >= ? AND coalesce(i.order_row_type, 1) = 1 GROUP BY 1 ORDER BY v DESC LIMIT 8", [$since]);

        // ---------- orders
        $d['orders_month'] = self::q("SELECT to_char(date_trunc('month', o.order_date AT TIME ZONE 'Asia/Bangkok'), 'YYYY-MM') AS ym, count(*)::int AS n FROM erp_orders o WHERE o.order_date >= date_trunc('month', now()) - interval '11 months' GROUP BY 1 ORDER BY 1");
        $d['orders_this_month'] = (int) DB::scalar("SELECT count(*) FROM erp_orders o WHERE (o.order_date AT TIME ZONE 'Asia/Bangkok') >= date_trunc('month', now() AT TIME ZONE 'Asia/Bangkok')", [], self::C);

        // ---------- receivables
        $d['aging'] = self::q("SELECT b, round(sum(rest)::numeric, 2) AS v FROM (SELECT coalesce(r.rest_amount, 0) * coalesce(nullif(r.exchange_rate, 0), 1) AS rest,
                                  CASE WHEN r.due_date IS NULL OR r.due_date >= now() THEN '0 not due' WHEN r.due_date >= now() - interval '30 days' THEN '1 1–30' WHEN r.due_date >= now() - interval '60 days' THEN '2 31–60'
                                       WHEN r.due_date >= now() - interval '90 days' THEN '3 61–90' ELSE '4 90+' END AS b FROM erp_receivables r WHERE coalesce(r.rest_amount, 0) > 0 AND coalesce(r.is_credit, false) = false) x GROUP BY b ORDER BY b");
        $d['receivable'] = (float) DB::scalar('SELECT coalesce(sum(coalesce(rest_amount, 0) * coalesce(nullif(exchange_rate, 0), 1)), 0) FROM erp_receivables WHERE coalesce(rest_amount, 0) > 0 AND coalesce(is_credit, false) = false', [], self::C);

        // ---------- e-tax (inets)
        $d['inet'] = self::q("SELECT
              count(*) FILTER (WHERE coalesce(text_388, text_t01, text_81) IS NOT NULL AND NOT (coalesce(is_388_send, false) OR coalesce(is_t01_send, false) OR coalesce(is_81_send, false)) AND NOT (coalesce(inet_pdf_388, '') <> '' OR coalesce(inet_pdf_t01, '') <> '' OR coalesce(inet_pdf_81, '') <> '')) AS waiting,
              count(*) FILTER (WHERE (coalesce(transaction_388, '') <> '' OR coalesce(transaction_t01, '') <> '' OR coalesce(transaction_81, '') <> '') AND NOT (coalesce(inet_pdf_388, '') <> '' OR coalesce(inet_pdf_t01, '') <> '' OR coalesce(inet_pdf_81, '') <> '')) AS processing,
              count(*) FILTER (WHERE coalesce(inet_pdf_388, '') <> '' OR coalesce(inet_pdf_t01, '') <> '' OR coalesce(inet_pdf_81, '') <> '') AS signed,
              count(*) FILTER (WHERE coalesce(text_388, text_t01, text_81) IS NULL) AS not_generated,
              count(*) AS total FROM inets")[0] ?? [];
        $d['inet_month'] = self::q("SELECT to_char(date_trunc('month', created_at AT TIME ZONE 'Asia/Bangkok'), 'YYYY-MM') AS ym, count(*)::int AS n FROM inets WHERE created_at >= date_trunc('month', now()) - interval '5 months' GROUP BY 1 ORDER BY 1");
        $d['not_in_inet'] = (int) DB::scalar("SELECT count(*) FROM erp_documents dd WHERE NOT EXISTS (SELECT 1 FROM inets n WHERE n.no_invoice = dd.invoice_number) AND dd.invoice_date >= now() - interval '60 days'", [], self::C);

        // ---------- billing notes
        $d['billing_status'] = self::q("SELECT CASE WHEN is_complete THEN 'complete' WHEN is_paid THEN 'paid' WHEN remind_date < CURRENT_DATE THEN 'overdue' ELSE 'pending' END AS s, count(*)::int AS n, round(sum(total_amount)::numeric, 2) AS v FROM billing_notes GROUP BY 1");
        $d['billing_month'] = self::q("SELECT to_char(date_trunc('month', billing_at), 'YYYY-MM') AS ym, round(sum(total_amount)::numeric, 2) AS v FROM billing_notes WHERE billing_at >= date_trunc('month', now()) - interval '11 months' GROUP BY 1 ORDER BY 1");
        $d['billing_today'] = (int) DB::scalar("SELECT count(*) FROM billing_notes WHERE remind_date = CURRENT_DATE AND NOT is_complete", [], self::C);
        $d['billing_open'] = (float) DB::scalar("SELECT coalesce(sum(total_amount), 0) FROM billing_notes WHERE NOT is_paid AND NOT is_complete", [], self::C);

        return $d;
    }
}
