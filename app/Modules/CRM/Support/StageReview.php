<?php
declare(strict_types=1);

namespace App\Modules\CRM\Support;

use App\Core\Auth\CurrentUser;
use App\Core\Support\DB;

/**
 * Sales control → By stage: how many opportunities sit in each stage, and which ones, so a manager can find the ones
 * that have been open far too long and move them on (e.g. close them). Admins see every department, BU managers their own.
 */
final class StageReview
{
    /** Dates the range can apply to: key => [label, SQL]. "In stage since" = the last stage move, or creation when never moved. */
    public static function dates(): array
    {
        return [
            'since'   => [__('In stage since'), "coalesce((SELECT max(s.done_at) FROM opportunity_steps s WHERE s.opportunity_id = t.id AND s.kind = 'stage'), t.created_at)"],
            'created' => [__('Created'), 't.created_at'],
            'updated' => [__('Last updated'), 't.updated_at'],
        ];
    }

    /**
     * @param array{department?:int, owner?:int, from?:string, to?:string, date?:string} $f
     * @return array{0: string, 1: list<mixed>} WHERE clause (without the stage) and its parameters
     */
    private static function where(CurrentUser $u, array $f): array
    {
        $w = ['t.deleted_at IS NULL'];
        $p = [];
        if (! Access::seesAll($u)) {
            $w[] = 't.department_id = ?';
            $p[] = (int) $u->department_id;
        } elseif (! empty($f['department'])) {
            $w[] = 't.department_id = ?';
            $p[] = (int) $f['department'];
        }
        if (! empty($f['owner'])) {
            $w[] = 't.owner_id = ?';
            $p[] = (int) $f['owner'];
        }
        $date = self::dates()[$f['date'] ?? 'since'][1] ?? self::dates()['since'][1];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['from'] ?? ''))) {
            $w[] = "({$date}) >= ?::date";
            $p[] = $f['from'];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['to'] ?? ''))) {
            $w[] = "({$date}) < ?::date + 1";
            $p[] = $f['to'];
        }

        return [implode(' AND ', $w), $p];
    }

    /** @return array<string, array{n: int, amount: float}> stage => count and total amount */
    public static function counts(CurrentUser $u, array $f): array
    {
        [$w, $p] = self::where($u, $f);
        $out = array_fill_keys(array_keys(Catalog::STAGES), ['n' => 0, 'amount' => 0.0]);
        foreach (DB::select("SELECT t.opportunity_stage AS stage, count(*) AS n, coalesce(sum(t.amount), 0) AS amount FROM opportunities t WHERE {$w} GROUP BY 1", $p, 'crm') as $r) {
            if (isset($out[$r['stage']])) {
                $out[$r['stage']] = ['n' => (int) $r['n'], 'amount' => (float) $r['amount']];
            }
        }

        return $out;
    }

    /** Opportunities in one stage, longest in that stage first. */
    public static function rows(CurrentUser $u, string $stage, array $f, int $limit = 300): array
    {
        [$w, $p] = self::where($u, $f);
        $since = self::dates()['since'][1];
        $p[] = $stage;

        return DB::select("SELECT t.id, t.code, t.name, t.amount, t.currency, t.owner_id, t.department_id, t.created_at, t.updated_at, t.follow_at,
                ({$since}) AS since, l.name_en AS customer
            FROM opportunities t LEFT JOIN leads l ON l.id = t.lead_id
            WHERE {$w} AND t.opportunity_stage = ? ORDER BY since ASC, t.id ASC LIMIT {$limit}", $p, 'crm');
    }
}
