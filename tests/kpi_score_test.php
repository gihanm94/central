<?php
// Checks the appraisal rules against the worked examples of the 2569 rule book.  Run:  php tests/kpi_score_test.php
declare(strict_types=1);
require __DIR__.'/../app/Core/Support/KpiScore.php';

use App\Core\Support\KpiScore as S;

$fail = 0;
$is = function (string $what, float $got, float $want) use (&$fail) {
    $ok = abs($got - $want) < 0.01;
    $fail += $ok ? 0 : 1;
    printf("%s %-58s %8.2f %s\n", $ok ? 'ok  ' : 'FAIL', $what, $got, $ok ? '' : "(want {$want})");
};

// KPI tiers
$is('80 against a target of 90 (not less than)', S::rate(80, 90)['result'], 75.55);
$is('QO at 90%', S::rate(90, 100)['result'], 76.5);
$is('Sales 75M of 120M', S::rate(75, 120)['result'], 31.25);
$is('Exactly 55% is in the 50% tier', S::rate(55, 100)['result'], 27.5);
$is('54.9% is 0', S::rate(54.9, 100)['result'], 0);
$is('Defects 2% within a 2% limit (not more than)', S::rate(2, 2, 'max')['result'], 100);
$is('Defects 2.1% over a 2% limit', S::rate(2.1, 2, 'max')['result'], 0);

// Measuring periods
$q = S::kpi(['criteria' => 10, 'direction' => 'min', 'measure' => 'count', 'period' => 'quarter'],
    [1 => ['finished' => 4, 'total' => 0], 2 => ['finished' => 4, 'total' => 0], 3 => ['finished' => 3, 'total' => 0],
     4 => ['finished' => 3, 'total' => 0], 5 => ['finished' => 3, 'total' => 0], 6 => ['finished' => 2, 'total' => 0]], 6);
$is('Quarters: Q1 11 of 10, Q2 8 of 10 → average', $q['result'], 78);
$t = S::kpi(['criteria' => 2, 'direction' => 'min', 'measure' => 'count', 'period' => 'year'], [5 => ['finished' => 1, 'total' => 1]], 12);
$is('Training twice a year, done once', $t['result'], 0);
$z = S::kpi(['criteria' => 90, 'direction' => 'min', 'measure' => 'percent', 'period' => 'month'],
    [1 => ['finished' => 9, 'total' => 10], 2 => ['finished' => 0, 'total' => 0], 3 => ['finished' => 8, 'total' => 10]], 3);
$is('0/0 month is not counted (2 of 3 months)', $z['result'], (100 + 75.55) / 2);

// KPI section and points
$k = S::kpiSection([['result' => 61.63, 'weight' => 30], ['result' => 100, 'weight' => 40], ['result' => 75, 'weight' => 30]]);
$is('Total KPI 61.63×30% + 100×40% + 75×30%', $k['rate'], 80.99);
$nd = S::kpiSection([['result' => 80, 'weight' => 50], ['result' => 0, 'weight' => 50, 'has_data' => false]]);
$is('A KPI with no data at all is left out (its weight moves)', $nd['rate'], 80);
$is('6 BU: 80.99% × 30 points', S::total('bu', ['kpi' => $k['rate']], S::INPUTS)['sections']['kpi']['earned'], 24.30);

// On time, QMS, absence, warnings
$is('Submitted on day 12', S::lateRate(5), 0);
$is('Submitted on day 9', S::lateRate(2), 60);
$is('Submitter 100 + approver 60 → month', S::onTime([1 => 100.0], [1 => 60.0])['rate'], 80);
$in = ['qms_open_car' => 1, 'qms_repeat_car' => 0, 'qms_docs' => 2, 'qms_projects' => 1, 'qms_plan_done' => 2, 'qms_plan_assigned' => 4] + S::INPUTS;
$is('QMS 2.5 + 3 + 2 + 1.5 + 1.5', S::qms($in)['points'], 10.5);
$is('QMS with no action plan assigned', S::qms(['qms_plan_assigned' => 0] + S::INPUTS)['points'], 3 + 3 + 3 + 0 + 3);
$is('Absence 32 h (4 days)', S::absence(['abs_absent' => 32] + S::INPUTS)['rate'], 100);
$is('Absence 60 h (7.5 days)', S::absence(['abs_absent' => 60] + S::INPUTS)['rate'], 90);
$is('Absence 104 h (13 days)', S::absence(['abs_absent' => 104] + S::INPUTS)['rate'], 70);
$is('Absence 100 h (12.5 days, per hour)', S::absence(['abs_absent' => 100] + S::INPUTS)['rate'], 75);
$full = ['kpi' => 100, 'ontime' => 100, 'qo' => 100, 'sales' => 100, 'qms' => 15, 'leader' => 100, 'absence' => 100];
$is('Everything perfect = 100', S::total('bu', $full, S::INPUTS)['score'], 100);
$is('Every group adds up to 100', array_sum(array_map(fn ($g) => S::total($g, $full, S::INPUTS)['score'], array_keys(S::GROUPS))), 400);
$is('1 written + 1 final warning', S::total('bu', $full, ['warn_written' => 1, 'warn_final' => 1] + S::INPUTS)['score'], 93);

echo $fail ? "\n{$fail} failed\n" : "\nall passed\n";
exit($fail ? 1 : 0);
