<?php
declare(strict_types=1);

namespace App\Modules\CRM\Support;

use App\Core\Support\DB;

/**
 * Whole-company opportunity figures for Management → CRM overview (hook 'management.crm'). Management is for
 * administrators and Management, so there is no department scope here. Amounts are in the CRM base currency
 * (each opportunity converted with its currency's rate). People and departments are returned as ids; the
 * Management page adds names and photos from the core database.
 */
final class ManagementOverview
{
    private const VAL  = 'COALESCE(t.amount, 0) * COALESCE(c.rate_to_base, 1)';
    private const WHEN = 'COALESCE(t.close_at, t.updated_at)';            // when a closed opportunity was closed
    private const FROM = 'FROM opportunities t LEFT JOIN currencies c ON c.code = t.currency WHERE t.deleted_at IS NULL';

    /** @param string $from Y-m-d  @param string $to Y-m-d (both validated by the caller) */
    public static function data(string $from, string $to): array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            throw new \InvalidArgumentException('Dates must be Y-m-d');
        }
        $v = self::VAL; $w = self::WHEN; $base = self::FROM;
        $open = "('".implode("','", Catalog::OPEN)."')";
        $in   = "{$w}::date BETWEEN '{$from}' AND '{$to}'";
        $since = "coalesce((SELECT max(s.done_at) FROM opportunity_steps s WHERE s.opportunity_id = t.id AND s.kind = 'stage'), t.created_at)";

        $tot = (array) DB::first("SELECT
              count(*) FILTER (WHERE t.opportunity_stage IN {$open}) AS open_n,
              COALESCE(sum({$v}) FILTER (WHERE t.opportunity_stage IN {$open}), 0) AS open_v,
              COALESCE(sum({$v} * COALESCE(t.probability, 0) / 100) FILTER (WHERE t.opportunity_stage IN {$open} AND t.opportunity_stage <> 'ON_HOLD'), 0) AS weighted,
              count(*) FILTER (WHERE t.opportunity_stage = 'CLOSED_WON' AND {$in}) AS won_n,
              COALESCE(sum({$v}) FILTER (WHERE t.opportunity_stage = 'CLOSED_WON' AND {$in}), 0) AS won_v,
              count(*) FILTER (WHERE t.opportunity_stage = 'CLOSED_LOST' AND {$in}) AS lost_n,
              COALESCE(sum({$v}) FILTER (WHERE t.opportunity_stage = 'CLOSED_LOST' AND {$in}), 0) AS lost_v,
              count(*) FILTER (WHERE t.created_at::date BETWEEN '{$from}' AND '{$to}') AS new_n,
              COALESCE(sum({$v}) FILTER (WHERE t.created_at::date BETWEEN '{$from}' AND '{$to}'), 0) AS new_v,
              count(*) FILTER (WHERE t.opportunity_stage IN {$open} AND ({$since}) < now() - interval '90 days') AS stale_n
            {$base}", [], 'crm');
        $tot = array_map('floatval', $tot);
        $closed = $tot['won_n'] + $tot['lost_n'];
        $tot['win_rate'] = $closed > 0 ? round($tot['won_n'] / $closed * 100, 1) : null;
        $tot['avg_won']  = $tot['won_n'] > 0 ? $tot['won_v'] / $tot['won_n'] : null;

        $stages = [];
        $rows = array_column(DB::select("SELECT t.opportunity_stage AS s, count(*) AS n, COALESCE(sum({$v}), 0) AS v {$base} AND t.opportunity_stage IN {$open} GROUP BY 1", [], 'crm'), null, 's');
        foreach (Catalog::OPEN as $s) {
            $stages[$s] = ['label' => Catalog::stageLabel($s), 'n' => (int) ($rows[$s]['n'] ?? 0), 'v' => (float) ($rows[$s]['v'] ?? 0)];
        }

        $group = fn (string $col) => DB::select("SELECT t.{$col} AS id,
              count(*) FILTER (WHERE t.opportunity_stage IN {$open}) AS open_n,
              COALESCE(sum({$v}) FILTER (WHERE t.opportunity_stage IN {$open}), 0) AS open_v,
              count(*) FILTER (WHERE t.opportunity_stage = 'CLOSED_WON' AND {$in}) AS won_n,
              COALESCE(sum({$v}) FILTER (WHERE t.opportunity_stage = 'CLOSED_WON' AND {$in}), 0) AS won_v,
              count(*) FILTER (WHERE t.opportunity_stage = 'CLOSED_LOST' AND {$in}) AS lost_n
            {$base} GROUP BY 1
            HAVING count(*) FILTER (WHERE t.opportunity_stage IN {$open} OR ((t.opportunity_stage IN ('CLOSED_WON','CLOSED_LOST')) AND {$in})) > 0
            ORDER BY won_v DESC, open_v DESC", [], 'crm');
        $rank = function (array $rows): array {
            return array_map(function ($r) {
                $c = (int) $r['won_n'] + (int) $r['lost_n'];

                return ['id' => $r['id'] !== null ? (int) $r['id'] : null, 'open_n' => (int) $r['open_n'], 'open_v' => (float) $r['open_v'], 'won_n' => (int) $r['won_n'],
                    'won_v' => (float) $r['won_v'], 'lost_n' => (int) $r['lost_n'], 'win_rate' => $c ? round((int) $r['won_n'] / $c * 100) : null];
            }, $rows);
        };

        // won amount and new opportunities per month, the last 12 months up to the end of the period
        $months = DB::select("SELECT to_char(m, 'YYYY-MM') AS ym,
                COALESCE((SELECT sum({$v}) {$base} AND t.opportunity_stage = 'CLOSED_WON' AND date_trunc('month', {$w}) = m), 0) AS won_v,
                (SELECT count(*) FROM opportunities t WHERE t.deleted_at IS NULL AND date_trunc('month', t.created_at) = m) AS new_n
            FROM generate_series(date_trunc('month', '{$to}'::date) - interval '11 months', date_trunc('month', '{$to}'::date), interval '1 month') m ORDER BY m", [], 'crm');

        $top = DB::select("SELECT t.id, t.name, t.code, t.opportunity_stage AS stage, t.owner_id, t.department_id, t.probability, t.close_at, {$v} AS v, l.name_en AS customer,
                ({$since}) AS since
            FROM opportunities t LEFT JOIN currencies c ON c.code = t.currency LEFT JOIN leads l ON l.id = t.lead_id
            WHERE t.deleted_at IS NULL AND t.opportunity_stage IN {$open} ORDER BY v DESC NULLS LAST, t.id LIMIT 8", [], 'crm');

        $year = (int) substr($to, 0, 4);
        $target = Targets::summary(null, $year);

        return [
            'cur' => Targets::baseCurrency(), 'totals' => $tot, 'stages' => $stages,
            'departments' => $rank($group('department_id')), 'sellers' => array_slice($rank($group('owner_id')), 0, 10),
            'months' => array_map(fn ($m) => ['ym' => $m['ym'], 'won_v' => (float) $m['won_v'], 'new_n' => (int) $m['new_n']], $months),
            'top' => $top, 'target' => ['year' => $year, 'total' => (float) $target['total'], 'actual' => (float) $target['actual_total']],
        ];
    }
}
