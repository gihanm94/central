<?php
declare(strict_types=1);

namespace App\Core\Support;

/**
 * Yearly appraisal score out of 100 (ACME-INTER 2569 rules), used by User Management → KPI calculator.
 *
 *   Final = Σ (section rate × section points) − warning deductions
 *
 * The rules themselves are plain functions (tier, rate, kpi, onTime, qms, absence, total) so they can be checked
 * against the worked examples (tests/kpi_score_test.php). gather() reads a person's KPIs and submissions.
 * Choices where the rule book is unclear: exactly 55% is in the 50% tier; decimals fall to the lower tier; absence
 * over 12 days loses 10% per day counted per hour; no action plan assigned = full 3 points; the leader's evaluation
 * is a 0–100% rate; only approved submissions count towards the KPI result.
 */
final class KpiScore
{
    public const GROUPS = ['bu' => '6 BU', 'iso' => 'ISO', 'non_iso' => 'Non-ISO (Acc, DX)', 'no_qo' => 'No QO (MM, MKT)'];

    /** Points of each section per employee group (sum 100). */
    public const POINTS = [
        'bu'      => ['kpi' => 30, 'ontime' => 5, 'qo' => 10, 'sales' => 5, 'qms' => 15, 'leader' => 20, 'absence' => 15],
        'iso'     => ['kpi' => 30, 'ontime' => 5, 'qo' => 15, 'sales' => 0, 'qms' => 15, 'leader' => 20, 'absence' => 15],
        'non_iso' => ['kpi' => 40, 'ontime' => 5, 'qo' => 15, 'sales' => 0, 'qms' => 0,  'leader' => 25, 'absence' => 15],
        'no_qo'   => ['kpi' => 55, 'ontime' => 5, 'qo' => 0,  'sales' => 0, 'qms' => 0,  'leader' => 25, 'absence' => 15],
    ];

    public const SECTIONS = ['kpi' => '1. KPI', 'ontime' => '2. KPI on time', 'qo' => '3. Department quality objective (QO)', 'sales' => '4. Sales performance',
        'qms' => '5. QMS (NCR / CAR)', 'leader' => '6. Evaluation from leader', 'absence' => '7. Absence rate'];

    public const PERIODS = ['month' => 'Monthly', 'quarter' => 'Quarterly', 'half' => 'Half-year', 'year' => 'Yearly'];

    /** Inputs typed in by HR / the BU manager, with their defaults. */
    public const INPUTS = [
        'qo_actual' => null, 'qo_target' => null, 'sales_actual' => null, 'sales_target' => null,
        'qms_open_car' => 0, 'qms_repeat_car' => 0, 'qms_docs' => 0, 'qms_projects' => 0, 'qms_plan_done' => 0, 'qms_plan_assigned' => 0,
        'leader' => null,
        'abs_unpaid_personal' => 0, 'abs_sick_no_cert' => 0, 'abs_absent' => 0, 'abs_late' => 0,
        'warn_written' => 0, 'warn_final' => 0, 'severe' => 0, 'note' => '',
    ];

    // ------------------------------------------------------------------ rules

    /** Multiplier (%) for an achievement (%). */
    public static function tier(float $achievement): float
    {
        return match (true) {
            $achievement >= 100 => 100.0,
            $achievement >= 85  => 85.0,
            $achievement >= 70  => 70.0,
            $achievement >= 55  => 50.0,
            default             => 0.0,
        };
    }

    /**
     * One measurement against its target.
     *  min (not less than): result = min(achievement, 100%) × tier multiplier
     *  max (not more than): 100% when within the limit, else 0%
     * @return array{achievement: ?float, multiplier: ?float, result: float}
     */
    public static function rate(float $actual, float $target, string $direction = 'min'): array
    {
        if ($direction === 'max') {
            return ['achievement' => null, 'multiplier' => null, 'result' => $actual <= $target ? 100.0 : 0.0];
        }
        if ($target <= 0) {
            return ['achievement' => null, 'multiplier' => null, 'result' => $actual > 0 ? 100.0 : 0.0];
        }
        $a = floor($actual / $target * 10000 + 1e-9) / 100;    // cut to 2 decimals, as the rule book does (88.888… → 88.88)
        $m = self::tier($a);

        return ['achievement' => round($a, 2), 'multiplier' => $m, 'result' => round(min($a, 100) * $m / 100, 2)];
    }

    /** Months of each measuring period up to $upTo. @return array<string, int[]> label => months */
    public static function periods(string $period, int $upTo): array
    {
        $out = [];
        $size = ['month' => 1, 'quarter' => 3, 'half' => 6, 'year' => 12][$period] ?? 1;
        for ($start = 1; $start <= $upTo; $start += $size) {
            $months = range($start, min($start + $size - 1, $upTo));
            $label = match ($period) {
                'quarter' => 'Q'.(intdiv($start - 1, 3) + 1),
                'half'    => 'H'.(intdiv($start - 1, 6) + 1),
                'year'    => 'Year',
                default   => date('M', mktime(0, 0, 0, $start, 1)),
            };
            $out[$label] = $months;
        }

        return $out;
    }

    /**
     * Result of one KPI over the year: per period the months are summed and rated; 0/0 periods are skipped;
     * the KPI result is the average of the periods that have data.
     * @param array{criteria: float|string|null, direction: string, measure: string, period: string} $kpi
     * @param array<int, array{finished: float, total: float}> $months month => figures (approved submissions)
     * @return array{result: float, periods: list<array>, has_data: bool}
     */
    public static function kpi(array $kpi, array $months, int $upTo): array
    {
        $target = (float) ($kpi['criteria'] ?? 0);
        $rows = [];
        $sum = 0.0;
        $n = 0;
        foreach (self::periods($kpi['period'] ?? 'month', $upTo) as $label => $list) {
            $fin = 0.0;
            $tot = 0.0;
            $seen = false;
            foreach ($list as $m) {
                if (isset($months[$m])) {
                    $fin += (float) $months[$m]['finished'];
                    $tot += (float) $months[$m]['total'];
                    $seen = true;
                }
            }
            if (! $seen || ($fin == 0.0 && $tot == 0.0)) {
                $rows[] = ['label' => $label, 'finished' => $fin, 'total' => $tot, 'skipped' => true];
                continue;                                      // no data (0/0): not in the count
            }
            if (($kpi['measure'] ?? 'percent') === 'percent') {
                if ($tot <= 0) {
                    $rows[] = ['label' => $label, 'finished' => $fin, 'total' => $tot, 'skipped' => true];
                    continue;
                }
                $actual = $fin / $tot * 100;
            } else {
                $actual = $fin;
            }
            $r = self::rate($actual, $target, $kpi['direction'] ?? 'min');
            $rows[] = ['label' => $label, 'finished' => $fin, 'total' => $tot, 'actual' => round($actual, 2), 'target' => $target, 'skipped' => false] + $r;
            $sum += $r['result'];
            $n++;
        }

        return ['result' => $n ? round($sum / $n, 2) : 0.0, 'periods' => $rows, 'has_data' => $n > 0];
    }

    /**
     * Section 1: Σ (KPI result × weight). A KPI with no data in the whole period (only 0/0) is left out, like a 0/0
     * month, and its weight goes to the others; missing weights share the rest equally; weights that do not add up
     * to 100 are scaled to 100. @param list<array{result: float, weight: ?float, has_data?: bool}> $kpis
     * @return array{rate: float, weights: list<float>, scaled: bool}
     */
    public static function kpiSection(array $kpis): array
    {
        $use = array_filter($kpis, fn ($k) => ($k['has_data'] ?? true) !== false);
        $weights = array_fill(0, count($kpis), 0.0);
        if (! $use) {
            return ['rate' => 0.0, 'weights' => $weights, 'scaled' => false];
        }
        $given = array_sum(array_map(fn ($k) => (float) ($k['weight'] ?? 0), $use));
        $missing = count(array_filter($use, fn ($k) => ($k['weight'] ?? null) === null || (float) $k['weight'] <= 0));
        $share = $missing ? max(0, 100 - $given) / $missing : 0;
        $w = array_map(fn ($k) => ($k['weight'] ?? null) !== null && (float) $k['weight'] > 0 ? (float) $k['weight'] : $share, $use);
        $total = array_sum($w);
        // Warn only when the weights typed in KPIs & OKRs do not add up to 100 (a KPI left out for lack of data is not a mistake)
        $typed = array_map(fn ($k) => (float) ($k['weight'] ?? 0), $kpis);
        $scaled = ! in_array(0.0, $typed, true) && abs(array_sum($typed) - 100) > 0.01;
        if ($total <= 0) {
            $w = array_map(fn () => 100 / count($use), $use);
            $total = 100;
        }
        $rate = 0.0;
        foreach ($use as $i => $k) {
            $weights[$i] = $w[$i] / $total * 100;
            $rate += $k['result'] * $weights[$i] / 100;
        }

        return ['rate' => round($rate, 2), 'weights' => array_map(fn ($x) => round($x, 2), $weights), 'scaled' => $scaled];
    }

    /** On-time rate for a deadline: 100% − 20% per day late, never below 0. */
    public static function lateRate(int $daysLate): float { return max(0.0, 100.0 - 20.0 * max(0, $daysLate)); }

    /**
     * Section 2: per month the submitter's rate (average over the KPIs due that month; missing = 0%, corrected = 0%)
     * and, for approvers, the approving rate; both → average of the two. Year = average of the months.
     * @param array<int, ?float> $submitter month => rate or null when nothing was due
     * @param array<int, ?float> $approver  month => rate or null when nothing was to approve
     * @return array{rate: float, months: array<int, array{submitter: ?float, approver: ?float, rate: float}>}
     */
    public static function onTime(array $submitter, array $approver): array
    {
        $months = [];
        foreach (array_unique([...array_keys($submitter), ...array_keys($approver)]) as $m) {
            $parts = array_values(array_filter([$submitter[$m] ?? null, $approver[$m] ?? null], fn ($x) => $x !== null));
            if (! $parts) {
                continue;
            }
            $months[$m] = ['submitter' => $submitter[$m] ?? null, 'approver' => $approver[$m] ?? null, 'rate' => round(array_sum($parts) / count($parts), 2)];
        }
        ksort($months);

        return ['rate' => $months ? round(array_sum(array_column($months, 'rate')) / count($months), 2) : 0.0, 'months' => $months];
    }

    /** Section 5: five items, 3 points each (15). @return array{points: float, items: array<string, float>} */
    public static function qms(array $in): array
    {
        $steps = fn (int $n, array $map) => $map[min($n, count($map) - 1)];
        $items = [
            'open_car'   => $steps((int) $in['qms_open_car'], [3, 2.5, 2, 1.25, 0]),
            'repeat_car' => $steps((int) $in['qms_repeat_car'], [3, 1.5, 0]),
            'docs'       => $steps((int) $in['qms_docs'], [3, 2.5, 2, 1.25, 0]),
            'projects'   => $steps((int) $in['qms_projects'], [0, 1.5, 3]),
            'plan'       => (float) $in['qms_plan_assigned'] > 0 ? round(min(1, (float) $in['qms_plan_done'] / (float) $in['qms_plan_assigned']) * 3, 2) : 3.0,
        ];

        return ['points' => round(array_sum($items), 2), 'items' => $items];
    }

    /** Section 7: counted hours → rate. @return array{hours: float, days: float, rate: float, no_raise: bool} */
    public static function absence(array $in): array
    {
        $h = (float) $in['abs_unpaid_personal'] + (float) $in['abs_sick_no_cert'] + (float) $in['abs_absent'] + (float) $in['abs_late'];
        $d = $h / 8;
        $rate = match (true) {
            $d <= 4  => 100.0,
            $d <= 8  => 90.0,
            $d <= 12 => 80.0,
            default  => max(0.0, 80.0 - 10.0 * ($d - 12)),     // −10% per day over 12, counted per hour (−1.25%/h)
        };

        return ['hours' => round($h, 2), 'days' => round($d, 2), 'rate' => round($rate, 2), 'no_raise' => $h > 96];
    }

    /**
     * The whole score. $rates holds the section rates in % (qms in points of 15).
     * @return array{sections: array<string, array{rate: float, points: int, earned: float}>, deduction: float, score: float}
     */
    public static function total(string $group, array $rates, array $in): array
    {
        $points = self::POINTS[$group] ?? self::POINTS['bu'];
        $sections = [];
        $sum = 0.0;
        foreach ($points as $k => $p) {
            $rate = (float) ($rates[$k] ?? 0);
            $earned = $k === 'qms' ? $rate / 15 * $p : $rate / 100 * $p;
            $sections[$k] = ['rate' => round($k === 'qms' ? $rate / 15 * 100 : $rate, 2), 'points' => $p, 'earned' => round($earned, 2)];
            $sum += $earned;
        }
        $deduction = 2 * (int) $in['warn_written'] + 5 * (int) $in['warn_final'];

        return ['sections' => $sections, 'deduction' => (float) $deduction, 'score' => round(max(0, $sum - $deduction), 2)];
    }

    // ------------------------------------------------------------------ data

    /** Inputs from a form / saved row, cleaned to numbers. */
    public static function inputs(array $raw): array
    {
        $out = [];
        foreach (self::INPUTS as $k => $default) {
            $v = $raw[$k] ?? $default;
            $out[$k] = match (true) {
                $k === 'note'   => mb_substr(trim((string) $v), 0, 2000),
                $k === 'severe' => ! empty($v) ? 1 : 0,
                $v === null || $v === '' => $default,
                default => max(0, min(1e9, (float) $v)),
            };
        }
        if ($out['leader'] !== null) {
            $out['leader'] = min(100, (float) $out['leader']);
        }

        return $out;
    }

    /**
     * Everything for one person and year, up to $upTo: KPI results from approved submissions, on-time months,
     * and the typed-in sections. @return array the details shown on the page and saved with the score
     */
    public static function calculate(int $userId, int $year, int $upTo, string $group, array $in): array
    {
        $group = isset(self::POINTS[$group]) ? $group : 'bu';
        $first = sprintf('%04d-01-01', $year);
        $last = date('Y-m-t', mktime(0, 0, 0, $upTo, 1, $year));
        $kpis = DB::select("SELECT id, title, kind, criteria, direction, measure, period, weight, period_start, period_end FROM kpis
            WHERE user_id = ? AND deleted_at IS NULL AND status <> 'archived' AND period_start <= ?::date AND period_end >= ?::date ORDER BY kind, title", [$userId, $last, $first]);
        $subs = DB::select('SELECT s.id, s.kpi_id, s.period_month, s.finished, s.total, s.percentage, s.approved_at,
                EXISTS (SELECT 1 FROM activity_logs l WHERE l.subject_type = ? AND l.subject_id = s.id AND l.action = ?) AS corrected
            FROM kpi_submissions s WHERE s.user_id = ? AND s.period_year = ? AND s.period_month <= ? AND s.deleted_at IS NULL', ['kpi_submission', 'updated', $userId, $year, $upTo]);
        $byKpi = [];
        foreach ($subs as $s) {
            $byKpi[(int) $s['kpi_id']][(int) $s['period_month']] = $s;
        }

        // 1. KPI
        $items = [];
        $waiting = 0;
        foreach ($kpis as $k) {
            $months = [];
            foreach ($byKpi[(int) $k['id']] ?? [] as $m => $s) {
                if ($s['approved_at']) {
                    $months[$m] = ['finished' => (float) $s['finished'], 'total' => (float) $s['total']];
                } else {
                    $waiting++;
                }
            }
            $r = self::kpi($k, $months, $upTo);
            $items[] = ['id' => (int) $k['id'], 'title' => $k['title'], 'kind' => $k['kind'], 'criteria' => $k['criteria'] !== null ? (float) $k['criteria'] : null,
                'direction' => $k['direction'], 'measure' => $k['measure'], 'period' => $k['period'], 'weight' => $k['weight'] !== null ? (float) $k['weight'] : null] + $r;
        }
        $section = self::kpiSection($items);
        foreach ($items as $i => &$it) {
            $it['used_weight'] = $section['weights'][$i] ?? 0;
            $it['weighted'] = round($it['result'] * ($section['weights'][$i] ?? 0) / 100, 2);
        }
        unset($it);

        // 2. On time — months whose deadline has passed (or that were sent anyway)
        $today = new \DateTimeImmutable('today');
        $submitter = [];
        for ($m = 1; $m <= $upTo; $m++) {
            $due = KpiSubmissions::dueDate($year, $m);
            $active = array_filter($kpis, fn ($k) => $k['period_start'] <= date('Y-m-t', mktime(0, 0, 0, $m, 1, $year)) && $k['period_end'] >= sprintf('%04d-%02d-01', $year, $m));
            if (! $active || ($due >= $today && ! array_filter($active, fn ($k) => isset($byKpi[(int) $k['id']][$m])))) {
                continue;
            }
            $rates = [];
            foreach ($active as $k) {
                $s = $byKpi[(int) $k['id']][$m] ?? null;
                $rates[] = $s === null ? 0.0 : (filter_var($s['corrected'], FILTER_VALIDATE_BOOL) ? 0.0 : (float) $s['percentage']);
            }
            $submitter[$m] = round(array_sum($rates) / count($rates), 2);
        }
        $approver = [];
        foreach (DB::select('SELECT period_month, period_year, approved_at FROM kpi_submissions WHERE approver_id = ? AND period_year = ? AND period_month <= ? AND deleted_at IS NULL', [$userId, $year, $upTo]) as $a) {
            $by = KpiSubmissions::approveBy($year, (int) $a['period_month']);
            $at = $a['approved_at'] ? new \DateTimeImmutable(substr((string) $a['approved_at'], 0, 10)) : ($today > $by ? $today : null);
            if ($at === null) {
                continue;                                      // still in time to approve
            }
            $late = (int) $by->diff($at)->format('%r%a');
            $approver[(int) $a['period_month']][] = self::lateRate($late);
        }
        $approver = array_map(fn ($list) => round(array_sum($list) / count($list), 2), $approver);
        $ontime = self::onTime($submitter, $approver);

        // 3, 4, 5, 6, 7
        $qo = $in['qo_actual'] !== null && $in['qo_target'] !== null ? self::rate((float) $in['qo_actual'], (float) $in['qo_target']) : null;
        $sales = $in['sales_actual'] !== null && $in['sales_target'] !== null ? self::rate((float) $in['sales_actual'], (float) $in['sales_target']) : null;
        $qms = self::qms($in);
        $absence = self::absence($in);
        $rates = ['kpi' => $section['rate'], 'ontime' => $ontime['rate'], 'qo' => $qo['result'] ?? 0, 'sales' => $sales['result'] ?? 0,
            'qms' => $qms['points'], 'leader' => (float) ($in['leader'] ?? 0), 'absence' => $absence['rate']];
        $total = self::total($group, $rates, $in);

        return ['year' => $year, 'month' => $upTo, 'group' => $group, 'kpis' => $items, 'kpi' => $section, 'waiting' => $waiting,
            'ontime' => $ontime, 'qo' => $qo, 'sales' => $sales, 'qms' => $qms, 'absence' => $absence, 'leader' => $in['leader']] + $total;
    }
}
