<?php /* $p person, $year, $month, $group, $in inputs, $r result of KpiScore::calculate, $saved ?row, $preview, $canEdit */
use App\Core\Support\KpiScore as S;

$n2 = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
$pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 2).'%';
$tone = fn (float $v) => $v >= 80 ? 'text-emerald-700' : ($v >= 60 ? 'text-amber-700' : 'text-signal-700');
$chip = fn (float $v) => $v >= 100 ? 'bg-emerald-50 text-emerald-800' : ($v >= 70 ? 'bg-amber-50 text-amber-800' : 'bg-signal-50 text-signal-800');
$points = S::POINTS[$group];
$ro = $canEdit ? '' : 'disabled';
$num = function (string $name, string $label, ?string $hint = null, string $step = 'any') use ($in, $ro) {
    return '<div><label class="label" for="kc-'.$name.'">'.e($label).'</label><input id="kc-'.$name.'" name="'.$name.'" type="number" min="0" step="'.$step.'" inputmode="decimal" value="'.e($in[$name] === null ? '' : number_clean($in[$name])).'" class="input" '.$ro.'>'
        .($hint ? '<p class="hint">'.e($hint).'</p>' : '').'</div>';
};
$months = [];
for ($i = 1; $i <= 12; $i++) { $months[$i] = format_date(sprintf('%04d-%02d-01', $year, $i), 'F'); }
$years = range((int) date('Y'), (int) date('Y') - 4);
$monthName = fn (int $m) => format_date(sprintf('%04d-%02d-01', $year, $m), 'M');
$dirLabel = ['min' => __('Not less than'), 'max' => __('Not more than')];
$measureLabel = ['percent' => __('Finished ÷ total %'), 'count' => __('Count')];
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="flex min-w-0 items-center gap-4">
        <?= partial('partials/avatar', ['name' => $p['name'], 'avatar' => $p['avatar'], 'size' => 'size-14', 'extra' => 'text-base']) ?>
        <div class="min-w-0"><h1 class="page-title break-words"><?= e($p['name']) ?></h1>
            <p class="text-sm text-steel"><?= e(($p['department_name'] ?? __('No department')).' · '.$p['role_name']) ?></p></div>
    </div>
    <a href="<?= url('/kpi-calculator', ['year' => $year]) ?>" class="btn-secondary"><?= icon('arrowleft', 'size-4') ?><?= e(__('All people')) ?></a>
</div>

<form method="GET" action="<?= url('/kpi-calculator/'.(int) $p['id']) ?>" class="mt-4 flex flex-wrap items-end gap-3">
    <div class="w-28"><label class="label" for="kc-y"><?= e(__('Year')) ?></label><?= select_field('year', array_combine($years, $years), $year, ['id' => 'kc-y', 'submit' => true, 'search' => false]) ?></div>
    <div class="w-40"><label class="label" for="kc-m"><?= e(__('Up to month')) ?></label><?= select_field('month', $months, $month, ['id' => 'kc-m', 'submit' => true, 'search' => false]) ?></div>
    <div class="w-56"><label class="label" for="kc-g"><?= e(__('Employee group')) ?></label><?= select_field('group', array_map(fn ($l) => __($l), S::GROUPS), $group, ['id' => 'kc-g', 'submit' => true, 'search' => false]) ?></div>
</form>

<?php if ($preview): ?><p class="mt-4 rounded-lg bg-sky-50 px-4 py-2.5 text-sm text-sky-900"><?= e(__('This is a calculation with the figures you entered. It is not saved yet — press "Save score" to keep it.')) ?></p><?php endif ?>

<div class="mt-4 grid gap-4 lg:grid-cols-3">
    <section class="panel p-5">
        <p class="text-xs font-medium uppercase tracking-wide text-steel"><?= e(__('KPI score :year', ['year' => $year])) ?></p>
        <p class="mt-1 font-display text-6xl font-semibold tabular-nums <?= $tone($r['score']) ?>"><?= e(number_format($r['score'], 2)) ?><span class="text-2xl text-steel"> / 100</span></p>
        <div class="mt-3 h-2.5 overflow-hidden rounded-full bg-graphite-900/8"><div class="h-full rounded-full <?= $r['score'] >= 80 ? 'bg-emerald-600' : ($r['score'] >= 60 ? 'bg-amber-500' : 'bg-signal-600') ?>" style="width: <?= min(100, $r['score']) ?>%"></div></div>
        <p class="mt-3 text-xs text-steel"><?= e(__(':g · January – :m :y', ['g' => __(S::GROUPS[$group]), 'm' => $months[$month], 'y' => $year])) ?></p>
        <p class="mt-1 text-xs <?= $saved ? 'text-steel' : 'text-amber-700' ?>"><?= $saved
            ? e(__('Saved score :s on :d by :n', ['s' => number_format((float) $saved['score'], 2), 'd' => format_date($saved['calculated_at'], 'j M Y H:i'), 'n' => $saved['calculated_by_name'] ?? '—']))
            : e(__('Not saved yet: the list shows 0 until the score is saved.')) ?></p>
        <?php if ($r['absence']['no_raise']): ?><p class="mt-3 rounded-md bg-signal-50 px-3 py-2 text-xs text-signal-800"><?= e(__('Absence over 12 days (96 h): no yearly salary raise, decided case by case.')) ?></p><?php endif ?>
        <?php if (! empty($in['severe'])): ?><p class="mt-2 rounded-md bg-signal-50 px-3 py-2 text-xs text-signal-800"><?= e(__('Severe warning: the result goes to the committee and may become "needs improvement".')) ?></p><?php endif ?>
    </section>

    <section class="panel overflow-hidden lg:col-span-2">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Score by section')) ?></h2><span class="text-xs text-steel"><?= e(__('rate × points')) ?></span></div>
        <table class="w-full text-sm">
            <thead class="bg-mist/60 text-left text-xs text-steel"><tr><th class="px-5 py-2 font-medium"><?= e(__('Section')) ?></th><th class="px-3 py-2 text-right font-medium"><?= e(__('Rate')) ?></th><th class="px-3 py-2 text-right font-medium"><?= e(__('Points')) ?></th><th class="px-5 py-2 text-right font-medium"><?= e(__('Earned')) ?></th></tr></thead>
            <tbody class="divide-y divide-graphite-900/6">
            <?php foreach ($r['sections'] as $k => $s): ?>
                <tr class="<?= $s['points'] ? '' : 'text-graphite-400' ?>"><td class="px-5 py-2"><a href="#sec-<?= e($k) ?>" class="hover:text-signal-700"><?= e(__(S::SECTIONS[$k])) ?></a></td>
                    <td class="px-3 py-2 text-right tabular-nums"><?= $s['points'] ? e($pct($s['rate'])) : '—' ?></td>
                    <td class="px-3 py-2 text-right tabular-nums"><?= (int) $s['points'] ?></td>
                    <td class="px-5 py-2 text-right font-medium tabular-nums"><?= e($n2($s['earned'])) ?></td></tr>
            <?php endforeach ?>
            <?php if ($r['deduction']): ?><tr class="text-signal-700"><td class="px-5 py-2"><?= e(__('8. Warning deductions')) ?></td><td></td><td></td><td class="px-5 py-2 text-right font-medium tabular-nums">−<?= e($n2($r['deduction'])) ?></td></tr><?php endif ?>
            <tr class="bg-mist/60 font-semibold"><td class="px-5 py-2.5"><?= e(__('Final score')) ?></td><td></td><td class="px-3 py-2.5 text-right tabular-nums">100</td><td class="px-5 py-2.5 text-right font-display text-lg tabular-nums <?= $tone($r['score']) ?>"><?= e($n2($r['score'])) ?></td></tr>
            </tbody>
        </table>
    </section>
</div>

<section class="panel mt-4 overflow-hidden" id="sec-kpi">
    <div class="panel-head"><h2 class="panel-title"><?= e(__(S::SECTIONS['kpi'])) ?> · <?= (int) $points['kpi'] ?> <?= e(__('points')) ?></h2>
        <span class="text-sm"><?= e(__('KPI total')) ?> <strong class="tabular-nums"><?= e($pct($r['kpi']['rate'])) ?></strong></span></div>
    <?php if ($r['waiting']): ?><p class="border-b border-graphite-900/8 bg-amber-50 px-5 py-2 text-xs text-amber-900"><?= e(__(':n submissions are still waiting for approval and are not counted yet.', ['n' => $r['waiting']])) ?></p><?php endif ?>
    <?php if ($r['kpi']['scaled']): ?><p class="border-b border-graphite-900/8 bg-amber-50 px-5 py-2 text-xs text-amber-900"><?= e(__('The weights do not add up to 100%, so they were scaled to 100%. Set the weights in KPIs & OKRs.')) ?></p><?php endif ?>
    <?php if (! $r['kpis']): ?>
        <p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('No KPIs & OKRs for this person in :y. Add them in KPIs & OKRs.', ['y' => $year])) ?></p>
    <?php endif ?>
    <div class="divide-y divide-graphite-900/8">
    <?php foreach ($r['kpis'] as $k): ?>
        <details class="group" <?= count($r['kpis']) <= 6 ? 'open' : '' ?>>
            <summary class="flex cursor-pointer list-none flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 hover:bg-mist/40">
                <?= icon('right', 'size-4 text-steel transition group-open:rotate-90') ?>
                <span class="badge <?= $k['kind'] === 'okr' ? 'bg-violet-50 text-violet-800' : 'bg-sky-50 text-sky-800' ?>"><?= e(strtoupper($k['kind'])) ?></span>
                <a href="<?= url('/kpis/'.(int) $k['id']) ?>" class="min-w-0 flex-1 font-medium hover:text-signal-700"><?= e($k['title']) ?></a>
                <span class="text-xs text-steel"><?= e($dirLabel[$k['direction']] ?? '').' '.($k['criteria'] !== null ? number_clean($k['criteria']) : '—').' · '.($measureLabel[$k['measure']] ?? '').' · '.__(S::PERIODS[$k['period']] ?? '') ?></span>
                <span class="text-xs text-steel"><?php if ($k['has_data']): ?><?= e(__('Weight')) ?> <strong class="tabular-nums text-graphite-900"><?= e(number_clean($k['used_weight'])) ?>%</strong><?php if ($k['weight'] !== null && abs($k['weight'] - $k['used_weight']) > 0.01): ?> <span class="text-graphite-400">(<?= e(number_clean($k['weight'])) ?>%)</span><?php endif ?><?php else: ?><?= e(__('Not counted: no data this year')) ?><?php endif ?></span>
                <span class="inline-flex min-w-16 justify-center rounded-md px-2 py-0.5 text-sm font-semibold tabular-nums <?= $k['has_data'] ? $chip($k['result']) : 'bg-graphite-900/6 text-graphite-500' ?>"><?= $k['has_data'] ? e($pct($k['result'])) : e(__('No data')) ?></span>
                <span class="w-20 text-right text-xs tabular-nums text-steel">= <?= e($pct($k['weighted'])) ?></span>
            </summary>
            <div class="overflow-x-auto px-5 pb-4"><table class="w-full text-xs">
                <thead class="text-left text-steel"><tr><th class="py-1.5 pr-3 font-medium"><?= e(__('Period')) ?></th><th class="px-3 py-1.5 text-right font-medium"><?= e(__('Finished')) ?></th><th class="px-3 py-1.5 text-right font-medium"><?= e(__('Total tasks')) ?></th>
                    <th class="px-3 py-1.5 text-right font-medium"><?= e(__('Actual')) ?></th><th class="px-3 py-1.5 text-right font-medium"><?= e(__('Target')) ?></th><th class="px-3 py-1.5 text-right font-medium"><?= e(__('Achievement')) ?></th>
                    <th class="px-3 py-1.5 text-right font-medium"><?= e(__('Multiplier')) ?></th><th class="py-1.5 pl-3 text-right font-medium"><?= e(__('Result')) ?></th></tr></thead>
                <tbody class="divide-y divide-graphite-900/6">
                <?php foreach ($k['periods'] as $row): ?>
                    <tr class="<?= $row['skipped'] ? 'text-graphite-400' : '' ?>">
                        <td class="py-1.5 pr-3 font-medium"><?= e(__($row['label'])) ?></td>
                        <td class="px-3 py-1.5 text-right tabular-nums"><?= e(number_clean($row['finished'])) ?></td>
                        <td class="px-3 py-1.5 text-right tabular-nums"><?= e(number_clean($row['total'])) ?></td>
                        <?php if ($row['skipped']): ?>
                            <td colspan="5" class="py-1.5 pl-3 text-right italic"><?= e(__('No data (0/0 or not approved): not counted')) ?></td>
                        <?php else: ?>
                            <td class="px-3 py-1.5 text-right tabular-nums"><?= e($k['measure'] === 'percent' ? $pct($row['actual']) : number_clean($row['actual'])) ?></td>
                            <td class="px-3 py-1.5 text-right tabular-nums"><?= e(($k['direction'] === 'max' ? '≤ ' : '≥ ').number_clean($row['target']).($k['measure'] === 'percent' ? '%' : '')) ?></td>
                            <td class="px-3 py-1.5 text-right tabular-nums"><?= e($pct($row['achievement'])) ?></td>
                            <td class="px-3 py-1.5 text-right tabular-nums"><?= e($row['multiplier'] === null ? ($k['direction'] === 'max' ? __('limit') : '—') : number_clean($row['multiplier']).'%') ?></td>
                            <td class="py-1.5 pl-3 text-right font-semibold tabular-nums"><?= e($pct($row['result'])) ?></td>
                        <?php endif ?>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
        </details>
    <?php endforeach ?>
    </div>
    <p class="border-t border-graphite-900/8 bg-mist/40 px-5 py-2.5 text-xs text-steel"><?= e(__('Tiers: achievement ≥100% → ×100%, ≥85% → ×85%, ≥70% → ×70%, ≥55% → ×50%, below → 0. "Not more than": 100% within the limit, else 0. Periods with no data (0/0) are not counted; only approved submissions count.')) ?></p>
</section>

<section class="panel mt-4 overflow-hidden" id="sec-ontime">
    <div class="panel-head"><h2 class="panel-title"><?= e(__(S::SECTIONS['ontime'])) ?> · <?= (int) $points['ontime'] ?> <?= e(__('points')) ?></h2>
        <span class="text-sm"><?= e(__('Year average')) ?> <strong class="tabular-nums"><?= e($pct($r['ontime']['rate'])) ?></strong></span></div>
    <?php if (! $r['ontime']['months']): ?><p class="px-5 py-6 text-center text-sm text-steel"><?= e(__('Nothing due yet in this period.')) ?></p><?php else: ?>
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead class="bg-mist/60 text-left text-xs text-steel"><tr><th class="px-5 py-2 font-medium"><?= e(__('Month')) ?></th><th class="px-3 py-2 text-right font-medium"><?= e(__('As submitter (due day 7)')) ?></th>
            <th class="px-3 py-2 text-right font-medium"><?= e(__('As approver (due day 10)')) ?></th><th class="px-5 py-2 text-right font-medium"><?= e(__('Month')) ?></th></tr></thead>
        <tbody class="divide-y divide-graphite-900/6">
        <?php foreach ($r['ontime']['months'] as $m => $row): ?>
            <tr><td class="px-5 py-2 font-medium"><?= e($months[$m]) ?></td>
                <td class="px-3 py-2 text-right tabular-nums"><?= e($pct($row['submitter'])) ?></td>
                <td class="px-3 py-2 text-right tabular-nums"><?= e($pct($row['approver'])) ?></td>
                <td class="px-5 py-2 text-right"><span class="inline-flex min-w-16 justify-center rounded-md px-2 py-0.5 font-semibold tabular-nums <?= $chip($row['rate']) ?>"><?= e($pct($row['rate'])) ?></span></td></tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
    <p class="border-t border-graphite-900/8 bg-mist/40 px-5 py-2.5 text-xs text-steel"><?= e(__('Each day late −20% (day 12 / day 15 = 0%). Not submitted, or corrected afterwards = 0% for that month. Submitter and approver: the average of both.')) ?></p>
</section>

<form method="POST" action="<?= url('/kpi-calculator/'.(int) $p['id']) ?>" class="mt-4 space-y-4">
    <?= csrf_field() ?>
    <input type="hidden" name="year" value="<?= (int) $year ?>"><input type="hidden" name="month" value="<?= (int) $month ?>"><input type="hidden" name="group" value="<?= e($group) ?>">

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="panel <?= $points['qo'] ? '' : 'opacity-60' ?>" id="sec-qo">
            <div class="panel-head"><h2 class="panel-title"><?= e(__(S::SECTIONS['qo'])) ?> · <?= (int) $points['qo'] ?></h2><strong class="tabular-nums"><?= e($pct($r['qo']['result'] ?? 0)) ?></strong></div>
            <div class="grid gap-4 p-5 sm:grid-cols-2"><?= $num('qo_actual', __('Actual')) ?><?= $num('qo_target', __('Target')) ?>
                <p class="text-xs text-steel sm:col-span-2"><?= $r['qo'] ? e(__('Achievement :a × tier :m', ['a' => $pct($r['qo']['achievement']), 'm' => number_clean($r['qo']['multiplier']).'%'])) : e(__('Enter the department QO actual and target.')) ?></p></div>
        </section>
        <section class="panel <?= $points['sales'] ? '' : 'opacity-60' ?>" id="sec-sales">
            <div class="panel-head"><h2 class="panel-title"><?= e(__(S::SECTIONS['sales'])) ?> · <?= (int) $points['sales'] ?></h2><strong class="tabular-nums"><?= e($pct($r['sales']['result'] ?? 0)) ?></strong></div>
            <div class="grid gap-4 p-5 sm:grid-cols-2"><?= $num('sales_actual', __('Actual')) ?><?= $num('sales_target', __('Target')) ?>
                <p class="text-xs text-steel sm:col-span-2"><?= $r['sales'] ? e(__('Achievement :a × tier :m', ['a' => $pct($r['sales']['achievement']), 'm' => number_clean($r['sales']['multiplier']).'%'])) : e(__('Enter sales actual and target (same unit).')) ?></p></div>
        </section>
    </div>

    <section class="panel <?= $points['qms'] ? '' : 'opacity-60' ?>" id="sec-qms">
        <div class="panel-head"><h2 class="panel-title"><?= e(__(S::SECTIONS['qms'])) ?> · <?= (int) $points['qms'] ?></h2><strong class="tabular-nums"><?= e(number_clean($r['qms']['points'])) ?> / 15</strong></div>
        <div class="grid gap-4 p-5 sm:grid-cols-3 lg:grid-cols-6">
            <?= $num('qms_open_car', __('CARs still open'), __('Points :p', ['p' => number_clean($r['qms']['items']['open_car'])]), '1') ?>
            <?= $num('qms_repeat_car', __('Repeated CAR'), __('Points :p', ['p' => number_clean($r['qms']['items']['repeat_car'])]), '1') ?>
            <?= $num('qms_docs', __('Controlled documents'), __('Points :p', ['p' => number_clean($r['qms']['items']['docs'])]), '1') ?>
            <?= $num('qms_projects', __('Ever Better projects'), __('Points :p', ['p' => number_clean($r['qms']['items']['projects'])]), '1') ?>
            <?= $num('qms_plan_done', __('Action plans done')) ?>
            <?= $num('qms_plan_assigned', __('Action plans assigned'), __('Points :p', ['p' => number_clean($r['qms']['items']['plan'])])) ?>
        </div>
    </section>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="panel" id="sec-leader">
            <div class="panel-head"><h2 class="panel-title"><?= e(__(S::SECTIONS['leader'])) ?> · <?= (int) $points['leader'] ?></h2><strong class="tabular-nums"><?= e($pct($r['leader'] ?? 0)) ?></strong></div>
            <div class="p-5"><?= $num('leader', __('Rate from the direct supervisor (0–100%)'), __('Competency, behavior and attitude.')) ?></div>
        </section>
        <section class="panel" id="sec-absence">
            <div class="panel-head"><h2 class="panel-title"><?= e(__(S::SECTIONS['absence'])) ?> · <?= (int) $points['absence'] ?></h2><strong class="tabular-nums"><?= e($pct($r['absence']['rate'])) ?></strong></div>
            <div class="grid gap-4 p-5 sm:grid-cols-2">
                <?= $num('abs_unpaid_personal', __('Unpaid personal leave (hours)')) ?><?= $num('abs_sick_no_cert', __('Sick leave without certificate (hours)')) ?>
                <?= $num('abs_absent', __('Absence (hours)')) ?><?= $num('abs_late', __('Lateness (hours)')) ?>
                <p class="text-xs text-steel sm:col-span-2"><?= e(__(':h hours = :d days counted. ≤4 days 100%, ≤8 90%, ≤12 80%, then −10% per day.', ['h' => number_clean($r['absence']['hours']), 'd' => number_clean($r['absence']['days'])])) ?></p>
            </div>
        </section>
    </div>

    <section class="panel" id="sec-warn">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('8. Warnings')) ?></h2><strong class="tabular-nums text-signal-700">−<?= e(number_clean($r['deduction'])) ?></strong></div>
        <div class="grid gap-4 p-5 sm:grid-cols-3">
            <?= $num('warn_written', __('Written warnings (−2 each)'), null, '1') ?><?= $num('warn_final', __('Final warnings (−5 each)'), null, '1') ?>
            <label class="flex items-center gap-2 self-end pb-2 text-sm"><input type="checkbox" name="severe" value="1" class="size-4 accent-signal-600" <?= ! empty($in['severe']) ? 'checked' : '' ?> <?= $ro ?>> <?= e(__('Severe warning (goes to the committee)')) ?></label>
            <div class="sm:col-span-3"><label class="label" for="kc-note"><?= e(__('Note')) ?></label><textarea id="kc-note" name="note" rows="2" maxlength="2000" class="input h-auto py-2" <?= $ro ?>><?= e($in['note']) ?></textarea></div>
        </div>
    </section>

    <?php if ($canEdit): ?>
    <div class="sticky bottom-3 z-10 flex flex-wrap items-center justify-end gap-2 rounded-lg bg-white/95 p-3 shadow-lg ring-1 ring-graphite-900/10 backdrop-blur">
        <span class="mr-auto text-sm text-steel"><?= e(__('Score now')) ?> <strong class="font-display text-xl tabular-nums <?= $tone($r['score']) ?>"><?= e(number_format($r['score'], 2)) ?></strong></span>
        <button name="action" value="calculate" class="btn-secondary"><?= icon('calc', 'size-4') ?><?= e(__('Calculate')) ?></button>
        <button name="action" value="save" class="btn-primary"><?= icon('check', 'size-4') ?><?= e(__('Save score')) ?></button>
    </div>
    <?php endif ?>
</form>
