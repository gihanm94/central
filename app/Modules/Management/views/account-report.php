<?php /* $data ?array (Accounting 'account.report'), $deptColor fn(name) => hex, $canOpen bool */
$short = function (float $v): string {
    $a = abs($v);
    return ($a >= 1e9 ? number_format($v / 1e9, 2).'B' : ($a >= 1e6 ? number_format($v / 1e6, 2).'M' : ($a >= 1e3 ? number_format($v / 1e3, 1).'K' : number_format($v, 0))));
};
$pct = fn (float $now, float $before) => $before != 0.0 ? ($now - $before) / abs($before) * 100 : null;
$delta = function (?float $p, bool $upIsGood = true): string {
    if ($p === null) { return '<span class="text-steel">'.e(__('no data last year')).'</span>'; }
    $good = $upIsGood ? $p >= 0 : $p <= 0;
    return '<span class="font-medium '.($good ? 'text-emerald-700' : 'text-signal-700').'">'.($p >= 0 ? '▲ +' : '▼ ').number_format($p, 1).'%</span> <span class="text-steel">'.e(__('vs last year')).'</span>';
};
$IN = '#2a78d6'; $EX = '#eb6834'; $NET = '#1baf7a';
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0"><h1 class="page-title"><?= e(__('Account report')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(__('Income and expenses of the whole company: by year, type, department and month.')) ?></p></div>
    <?php if ($canOpen): ?><a href="<?= url('/accounting/account-report') ?>" class="btn-secondary"><?= icon('doc', 'size-4') ?> <?= e(__('Open the lines')) ?></a><?php endif ?>
</div>

<?php if ($data === null): ?>
    <section class="panel mt-4 px-5 py-10 text-center text-sm text-steel"><?= e(__('Account figures are not available right now (the Accounting module is off or unavailable). The rest of Management still works.')) ?></section>
<?php elseif (! $data['years']): ?>
    <section class="panel mt-4 px-5 py-12 text-center text-sm text-steel"><?= e(__('No account lines yet. Import them in Accounting → Management → Account report.')) ?></section>
<?php else:
    $y = $data['year']; $d = $data['department']; $t = $data['totals'];
    $net = $t['income'] - $t['expense'];
    $margin = $t['income'] > 0 ? $net / $t['income'] * 100 : null;
    $ratio = $t['income'] > 0 ? min(100, $t['expense'] / $t['income'] * 100) : 100;
    $scope = ($y ?? __('All years')).' · '.($d ?? __('All departments'));
    $fy = $data['focusYear']; $py = $data['prevYear'];
    $typeLabels = array_map('__', $data['types']);
    $months = []; for ($m = 1; $m <= 12; $m++) { $months[] = __(date('M', mktime(0, 0, 0, $m, 1))); }
    // department × type: amount per cell, coloured by its share of the largest cell
    $grid = []; $usedTypes = [];
    foreach ($data['matrix'] as $r) { $grid[$r['department']][$r['type']] = (float) $r['amount']; $usedTypes[$r['type']] = true; }
    $gridTypes = array_values(array_filter(array_keys($data['types']), fn ($k) => isset($usedTypes[$k])));
    $gridMax = $data['matrix'] ? max(array_map(fn ($r) => (float) $r['amount'], $data['matrix'])) : 0;
    $depts = $data['byDepartment'];
    usort($depts, fn ($a, $b) => ($b['income'] - $b['expense']) <=> ($a['income'] - $a['expense']));
    $netMax = $depts ? max(array_map(fn ($r) => abs($r['income'] - $r['expense']), $depts)) : 0;
?>

<form method="GET" action="<?= url('/management/account-report') ?>" class="mt-4 flex flex-wrap items-end gap-3 rounded-xl bg-white p-3 shadow-sm ring-1 ring-graphite-900/8" aria-label="<?= e(__('Year and department')) ?>">
    <div class="w-36"><label class="label !mb-1 text-xs text-steel"><?= e(__('Year')) ?></label>
        <?= select_field('year', ['' => __('All years')] + array_combine(array_reverse($data['years']), array_reverse($data['years'])), $y ?? '', ['id' => 'ar-year', 'placeholder' => __('All years'), 'submit' => true, 'search' => false, 'aria' => __('Year')]) ?></div>
    <div class="w-60"><label class="label !mb-1 text-xs text-steel"><?= e(__('Department')) ?></label>
        <?= select_field('department', ['' => __('All departments')] + array_combine($data['departments'], $data['departments']), $d ?? '', ['id' => 'ar-dept', 'placeholder' => __('All departments'), 'submit' => true, 'aria' => __('Department')]) ?></div>
    <?php if ($y !== null || $d !== null): ?><a href="<?= url('/management/account-report') ?>" class="btn-ghost"><?= e(__('Clear')) ?></a><?php endif ?>
    <p class="ml-auto text-xs text-steel"><?= e(__(':n lines', ['n' => number_format($t['lines'])])) ?> · <?= e($scope) ?></p>
</form>

<!-- Headline figures -->
<section class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="<?= e(__('Totals')) ?>">
    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-graphite-900/8">
        <p class="flex items-center gap-2 text-xs text-steel"><span class="size-2.5 rounded-sm" style="background:<?= $IN ?>"></span><?= e(__('Total income')) ?></p>
        <p class="mt-1 text-2xl font-semibold tabular-nums" title="<?= e(number_format($t['income'], 2)) ?>"><?= e($short($t['income'])) ?></p>
        <p class="mt-1 text-xs"><?= $y !== null ? $delta($pct($fy['income'], $py['income'])) : '<span class="text-steel">'.e($scope).'</span>' ?></p>
    </div>
    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-graphite-900/8">
        <p class="flex items-center gap-2 text-xs text-steel"><span class="size-2.5 rounded-sm" style="background:<?= $EX ?>"></span><?= e(__('Total expenses')) ?></p>
        <p class="mt-1 text-2xl font-semibold tabular-nums" title="<?= e(number_format($t['expense'], 2)) ?>"><?= e($short($t['expense'])) ?></p>
        <p class="mt-1 text-xs"><?= $y !== null ? $delta($pct($fy['expense'], $py['expense']), false) : '<span class="text-steel">'.e($scope).'</span>' ?></p>
    </div>
    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-graphite-900/8">
        <p class="text-xs text-steel"><?= e($net >= 0 ? __('Net profit') : __('Net loss')) ?></p>
        <p class="mt-1 text-2xl font-semibold tabular-nums <?= $net < 0 ? 'text-signal-700' : '' ?>" title="<?= e(number_format($net, 2)) ?>"><?= e($short($net)) ?></p>
        <p class="mt-1 text-xs text-steel"><?= $margin !== null ? e(__('Margin :p%', ['p' => number_format($margin, 1)])) : e(__('No income')) ?></p>
    </div>
    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-graphite-900/8">
        <p class="text-xs text-steel"><?= e(__('Expenses per 100 of income')) ?></p>
        <p class="mt-1 text-2xl font-semibold tabular-nums"><?= $t['income'] > 0 ? e(number_format($t['expense'] / $t['income'] * 100, 1)) : '—' ?></p>
        <div class="mt-2 h-2 overflow-hidden rounded-full bg-graphite-900/6" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) round($ratio) ?>" aria-label="<?= e(__('Expenses per 100 of income')) ?>">
            <div class="h-full rounded-full" style="width: <?= number_format($ratio, 1, '.', '') ?>%; background: <?= $EX ?>"></div></div>
    </div>
</section>

<!-- Years -->
<div class="mt-4 grid items-start gap-4 xl:grid-cols-3">
    <section class="panel p-5 xl:col-span-2" aria-labelledby="ar-years">
        <h2 class="panel-title" id="ar-years"><?= e(__('Income and expenses by year')) ?></h2>
        <p class="text-xs text-steel"><?= e($d ?? __('All departments')) ?><?= e(__(' · bars: income and expenses, line: net')) ?></p>
        <div class="relative mt-4 h-72"><canvas id="ar-chart-years" role="img" aria-label="<?= e(__('Income and expenses by year')) ?>"></canvas></div>
    </section>
    <section class="panel p-5" aria-labelledby="ar-net">
        <h2 class="panel-title" id="ar-net"><?= e(__('Net result per year')) ?></h2>
        <ul class="mt-3 divide-y divide-graphite-900/6 text-sm">
            <?php $prev = null; foreach (array_reverse($data['byYear']) as $r): $n = $r['income'] - $r['expense']; ?>
            <li class="flex items-center justify-between gap-3 py-2 <?= $y !== null && (int) $r['year'] === $y ? 'font-semibold' : '' ?>">
                <a href="<?= e(url('/management/account-report', array_filter(['year' => $r['year'], 'department' => $d]))) ?>" class="tabular-nums hover:text-signal-700"><?= (int) $r['year'] ?></a>
                <span class="text-xs text-steel"><?= $r['income'] > 0 ? e(__('Margin :p%', ['p' => number_format($n / $r['income'] * 100, 1)])) : '' ?></span>
                <span class="w-28 text-right tabular-nums <?= $n < 0 ? 'text-signal-700' : '' ?>"><?= e($short($n)) ?></span>
            </li>
            <?php endforeach ?>
        </ul>
    </section>
</div>

<!-- Types and months -->
<div class="mt-4 grid items-start gap-4 xl:grid-cols-2">
    <section class="panel p-5" aria-labelledby="ar-types">
        <h2 class="panel-title" id="ar-types"><?= e(__('Amount by type')) ?></h2>
        <p class="text-xs text-steel"><?= e($scope) ?></p>
        <?php if (! $data['byType']): ?><p class="py-10 text-center text-sm text-steel"><?= e(__('Nothing for this selection.')) ?></p>
        <?php else: ?><div class="relative mt-4" style="height: <?= 60 + count($data['byType']) * 38 ?>px"><canvas id="ar-chart-types" role="img" aria-label="<?= e(__('Amount by type')) ?>"></canvas></div><?php endif ?>
    </section>
    <section class="panel p-5" aria-labelledby="ar-months">
        <h2 class="panel-title" id="ar-months"><?= e(__('Month by month :y', ['y' => $data['focus']])) ?></h2>
        <p class="text-xs text-steel"><?= e($d ?? __('All departments')) ?><?= e(__(' · dashed: :y', ['y' => $data['focus'] - 1])) ?></p>
        <div class="relative mt-4 h-72"><canvas id="ar-chart-months" role="img" aria-label="<?= e(__('Month by month :y', ['y' => $data['focus']])) ?>"></canvas></div>
    </section>
</div>

<!-- Departments -->
<div class="mt-4 grid items-start gap-4 xl:grid-cols-3">
    <section class="panel p-5 xl:col-span-2" aria-labelledby="ar-depts">
        <h2 class="panel-title" id="ar-depts"><?= e(__('Departments')) ?></h2>
        <p class="text-xs text-steel"><?= e($y ?? __('All years')) ?></p>
        <?php if (! $data['byDepartment']): ?><p class="py-10 text-center text-sm text-steel"><?= e(__('Nothing for this selection.')) ?></p>
        <?php else: ?><div class="relative mt-4" style="height: <?= 70 + count($data['byDepartment']) * 52 ?>px"><canvas id="ar-chart-depts" role="img" aria-label="<?= e(__('Departments')) ?>"></canvas></div><?php endif ?>
    </section>
    <section class="panel p-5" aria-labelledby="ar-rank">
        <h2 class="panel-title" id="ar-rank"><?= e(__('Net by department')) ?></h2>
        <ol class="mt-3 space-y-3 text-sm">
            <?php foreach ($depts as $i => $r): $n = $r['income'] - $r['expense']; $w = $netMax > 0 ? abs($n) / $netMax * 100 : 0; ?>
            <li>
                <div class="flex items-center justify-between gap-3">
                    <a href="<?= e(url('/management/account-report', array_filter(['year' => $y, 'department' => $r['department']]))) ?>" class="flex min-w-0 items-center gap-2 hover:text-signal-700 <?= $d === $r['department'] ? 'font-semibold' : '' ?>">
                        <span class="inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-graphite-900/6 text-[11px] font-semibold text-graphite-700"><?= $i + 1 ?></span>
                        <span class="size-2.5 shrink-0 rounded-full" style="background: <?= e($deptColor($r['department'])) ?>"></span>
                        <span class="truncate"><?= e($r['department']) ?></span></a>
                    <span class="shrink-0 tabular-nums <?= $n < 0 ? 'text-signal-700' : '' ?>"><?= e($short($n)) ?></span>
                </div>
                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-graphite-900/6"><div class="h-full rounded-full" style="width: <?= number_format($w, 1, '.', '') ?>%; background: <?= $n < 0 ? $EX : $NET ?>"></div></div>
            </li>
            <?php endforeach ?>
        </ol>
    </section>
</div>

<!-- Department × type -->
<?php if ($grid): ?>
<section class="panel mt-4 overflow-hidden" aria-labelledby="ar-grid">
    <div class="panel-head"><div><h2 class="panel-title" id="ar-grid"><?= e(__('Department × type')) ?></h2>
        <p class="text-xs text-steel"><?= e($y ?? __('All years')) ?> · <?= e(__('darker = larger amount')) ?></p></div></div>
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead class="bg-mist text-left text-xs text-steel"><tr>
            <th class="px-5 py-2 font-medium"><?= e(__('Department')) ?></th>
            <?php foreach ($gridTypes as $k): ?><th class="px-3 py-2 text-right font-medium"><?= e($typeLabels[$k]) ?></th><?php endforeach ?>
        </tr></thead>
        <tbody class="divide-y divide-graphite-900/6">
        <?php foreach ($grid as $dept => $cells): ?>
            <tr class="<?= $d === $dept ? 'bg-amber-50/60' : '' ?>">
                <td class="whitespace-nowrap px-5 py-2"><span class="mr-2 inline-block size-2.5 rounded-full align-middle" style="background: <?= e($deptColor((string) $dept)) ?>"></span><?= e((string) $dept) ?></td>
                <?php foreach ($gridTypes as $k): $v = $cells[$k] ?? null; $a = $v !== null && $gridMax > 0 ? 0.08 + 0.72 * abs($v) / $gridMax : 0; ?>
                <td class="px-3 py-2 text-right tabular-nums" title="<?= $v !== null ? e(number_format($v, 2)) : '' ?>"
                    style="<?= $v !== null ? 'background: rgba(42,120,214,'.number_format($a, 2, '.', '').');'.($a > 0.5 ? ' color: #fff;' : '') : '' ?>"><?= $v !== null ? e($short($v)) : '<span class="text-graphite-300">·</span>' ?></td>
                <?php endforeach ?>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
</section>
<?php endif ?>

<!-- Biggest accounts -->
<div class="mt-4 grid items-start gap-4 lg:grid-cols-2">
    <?php foreach (['topIncome' => [__('Biggest income accounts'), $IN], 'topExpense' => [__('Biggest expense accounts'), $EX]] as $key => [$label, $color]):
        $rows = $data[$key]; $max = $rows ? max(array_map(fn ($r) => abs((float) $r['amount']), $rows)) : 0; ?>
    <section class="panel p-5">
        <h2 class="panel-title flex items-center gap-2"><span class="size-2.5 rounded-sm" style="background: <?= $color ?>"></span><?= e($label) ?></h2>
        <p class="text-xs text-steel"><?= e($scope) ?></p>
        <?php if (! $rows): ?><p class="py-8 text-center text-sm text-steel"><?= e(__('Nothing for this selection.')) ?></p><?php else: ?>
        <ul class="mt-3 space-y-3 text-sm">
            <?php foreach ($rows as $r): ?>
            <li><div class="flex items-baseline justify-between gap-3"><span class="min-w-0 truncate"><span class="tabular-nums text-steel"><?= e($r['account']) ?></span> · <?= e($r['name']) ?></span><span class="shrink-0 tabular-nums" title="<?= e(number_format((float) $r['amount'], 2)) ?>"><?= e($short((float) $r['amount'])) ?></span></div>
                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-graphite-900/6"><div class="h-full rounded-full" style="width: <?= $max > 0 ? number_format(abs((float) $r['amount']) / $max * 100, 1, '.', '') : 0 ?>%; background: <?= $color ?>"></div></div></li>
            <?php endforeach ?>
        </ul>
        <?php endif ?>
    </section>
    <?php endforeach ?>
</div>

<script src="<?= asset('assets/vendor/chartjs/chart.umd.min.js') ?>"></script>
<script>
(function () {
    var IN = '<?= $IN ?>', EX = '<?= $EX ?>', NET = '<?= $NET ?>';
    var L = <?= json_encode(['income' => __('Income'), 'expense' => __('Expenses'), 'net' => __('Net')], JSON_UNESCAPED_UNICODE) ?>;
    var years = <?= json_encode($data['byYear']) ?>, types = <?= json_encode($data['byType']) ?>, typeLabels = <?= json_encode($typeLabels, JSON_UNESCAPED_UNICODE) ?>;
    var depts = <?= json_encode($data['byDepartment'], JSON_UNESCAPED_UNICODE) ?>, months = <?= json_encode($months, JSON_UNESCAPED_UNICODE) ?>;
    var gap = function (m) { return m.income === 0 && m.expense === 0 ? { income: null, expense: null } : m; };   // a month with no lines is not reported yet
    var cur = <?= json_encode(array_values($data['months'])) ?>.map(gap), prev = <?= json_encode(array_values($data['prevMonths'])) ?>.map(gap);
    var focus = <?= (int) $data['focus'] ?>;
    var compact = new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 });
    var full = new Intl.NumberFormat(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = '#52514e';
    var grid = { color: 'rgba(15,23,42,0.06)' };
    var tip = { callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ' + full.format(c.parsed[c.chart.options.indexAxis === 'y' ? 'x' : 'y']); } } };
    var legend = { position: 'bottom', labels: { boxWidth: 12, usePointStyle: true } };
    var bar = { borderRadius: 4, borderSkipped: 'start', maxBarThickness: 36, categoryPercentage: 0.7, barPercentage: 0.9 };

    new Chart(document.getElementById('ar-chart-years'), {
        data: { labels: years.map(function (r) { return r.year; }), datasets: [
            Object.assign({ type: 'bar', label: L.income, data: years.map(function (r) { return r.income; }), backgroundColor: IN, order: 2 }, bar),
            Object.assign({ type: 'bar', label: L.expense, data: years.map(function (r) { return r.expense; }), backgroundColor: EX, order: 2 }, bar),
            { type: 'line', label: L.net, data: years.map(function (r) { return r.income - r.expense; }), borderColor: NET, backgroundColor: NET, borderWidth: 2, pointRadius: 4, pointHoverRadius: 6, order: 1 }
        ] },
        options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, usePointStyle: true, sort: function (a, b) { return a.datasetIndex - b.datasetIndex; } } }, tooltip: tip },
            scales: { x: { grid: { display: false } }, y: { grid: grid, ticks: { callback: function (v) { return compact.format(v); } } } } }
    });

    var tEl = document.getElementById('ar-chart-types');
    if (tEl) new Chart(tEl, {
        type: 'bar',
        data: { labels: types.map(function (r) { return typeLabels[r.type] || r.type; }), datasets: [
            Object.assign({ label: L.income + ' / ' + L.expense, data: types.map(function (r) { return r.amount; }), backgroundColor: types.map(function (r) { return r.kind === 'income' ? IN : EX; }) }, bar, { categoryPercentage: 0.8 })
        ] },
        options: { indexAxis: 'y', maintainAspectRatio: false,
            plugins: { legend: { display: true, position: 'bottom', labels: { boxWidth: 12, usePointStyle: true, generateLabels: function () { return [{ text: L.income, fillStyle: IN, strokeStyle: IN, pointStyle: 'circle' }, { text: L.expense, fillStyle: EX, strokeStyle: EX, pointStyle: 'circle' }]; } }, onClick: function () {} },
                tooltip: { callbacks: { label: function (c) { return ' ' + (types[c.dataIndex].kind === 'income' ? L.income : L.expense) + ': ' + full.format(c.parsed.x); } } } },
            scales: { x: { grid: grid, ticks: { callback: function (v) { return compact.format(v); } } }, y: { grid: { display: false } } } }
    });

    new Chart(document.getElementById('ar-chart-months'), {
        type: 'line',
        data: { labels: months, datasets: [
            { label: L.income + ' ' + focus, data: cur.map(function (m) { return m.income; }), borderColor: IN, backgroundColor: IN, borderWidth: 2, pointRadius: 3, tension: 0.3 },
            { label: L.expense + ' ' + focus, data: cur.map(function (m) { return m.expense; }), borderColor: EX, backgroundColor: EX, borderWidth: 2, pointRadius: 3, tension: 0.3 },
            { label: L.income + ' ' + (focus - 1), data: prev.map(function (m) { return m.income; }), borderColor: 'rgba(42,120,214,0.45)', backgroundColor: 'rgba(42,120,214,0.45)', borderDash: [5, 4], borderWidth: 2, pointRadius: 0, tension: 0.3 },
            { label: L.expense + ' ' + (focus - 1), data: prev.map(function (m) { return m.expense; }), borderColor: 'rgba(235,104,52,0.45)', backgroundColor: 'rgba(235,104,52,0.45)', borderDash: [5, 4], borderWidth: 2, pointRadius: 0, tension: 0.3 }
        ] },
        options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: legend, tooltip: tip },
            scales: { x: { grid: { display: false } }, y: { grid: grid, ticks: { callback: function (v) { return compact.format(v); } } } } }
    });

    var dEl = document.getElementById('ar-chart-depts');
    if (dEl) new Chart(dEl, {
        type: 'bar',
        data: { labels: depts.map(function (r) { return r.department; }), datasets: [
            Object.assign({ label: L.income, data: depts.map(function (r) { return r.income; }), backgroundColor: IN }, bar),
            Object.assign({ label: L.expense, data: depts.map(function (r) { return r.expense; }), backgroundColor: EX }, bar)
        ] },
        options: { indexAxis: 'y', maintainAspectRatio: false, interaction: { mode: 'index', intersect: false, axis: 'y' }, plugins: { legend: legend, tooltip: tip },
            scales: { x: { grid: grid, ticks: { callback: function (v) { return compact.format(v); } } }, y: { grid: { display: false } } } }
    });
})();
</script>
<?php endif ?>
