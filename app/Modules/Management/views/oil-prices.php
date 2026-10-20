<?php
/* $oil (current), $products [code => row], $dates, $series [{code,label,color,data,active}], $range, $fuel, $history, $syncs, $times, $last, $due */
use App\Modules\Management\Controllers\OilPriceController;

$name = fn (?array $p, string $code) => $p ? (locale() === 'th' && $p['name_th'] ? $p['name_th'] : $p['name_en']) : $code;
$src = ['schedule' => __('Scheduled'), 'manual' => __('Sync now'), 'history' => __('History')];
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0"><h1 class="page-title"><?= e(__('Oil prices')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(__('PTT retail prices in Bangkok, baht per litre. Synced every morning; a price is saved only when it changes.')) ?></p></div>
    <form method="POST" action="<?= url('/management/oil-prices/sync') ?>" class="flex items-center gap-3">
        <?= csrf_field() ?>
        <span class="text-right text-xs text-steel"><?= $last ? e(__('Last synced :t', ['t' => format_date($last['run_at'], 'd M Y H:i')])) : e(__('Never synced yet')) ?></span>
        <button class="btn-primary"><?= icon('refresh', 'size-4') ?> <?= e(__('Sync now')) ?></button>
    </form>
</div>

<section class="panel mt-6" aria-labelledby="oil-now">
    <div class="panel-head"><h2 class="panel-title" id="oil-now"><?= e(__('Current prices')) ?></h2></div>
    <?php if ($oil): ?><?= partial('partials/oil-prices', ['oil' => $oil]) ?>
    <?php else: ?><p class="px-5 py-10 text-center text-sm text-steel"><?= e(__('No prices yet. Press "Sync now", or load the history below.')) ?></p><?php endif ?>
</section>

<section class="panel mt-4 p-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="panel-title"><?= e(__('Price changes')) ?></h2>
        <nav class="flex rounded-md bg-graphite-900/6 p-0.5 text-sm" aria-label="<?= e(__('Period')) ?>">
            <?php foreach (OilPriceController::RANGES as $k => $l): ?>
                <a href="<?= e(url('/management/oil-prices', array_filter(['range' => $k, 'fuel' => $fuel ?: null]))) ?>" class="rounded px-3 py-1 <?= $range === $k ? 'bg-white font-medium shadow-sm' : 'text-steel hover:text-graphite-900' ?>"><?= e(__($l)) ?></a>
            <?php endforeach ?>
        </nav>
    </div>
    <?php if (count($dates) < 2): ?>
        <p class="py-12 text-center text-sm text-steel"><?= e(__('Not enough history for a chart yet. Load past days below to fill it.')) ?></p>
    <?php else: ?>
        <div class="relative mt-4 h-80"><canvas id="oil-chart" aria-label="<?= e(__('Oil price changes')) ?>" role="img"></canvas></div>
        <p class="mt-2 text-xs text-steel"><?= e(__('Each step is a price change. Click a fuel in the legend to hide or show it.')) ?></p>
    <?php endif ?>
</section>

<div class="mt-4 grid items-start gap-4 xl:grid-cols-5">
    <section class="panel overflow-hidden xl:col-span-3">
        <div class="panel-head flex-wrap gap-2">
            <h2 class="panel-title"><?= e(__('History')) ?></h2>
            <form method="GET" action="<?= url('/management/oil-prices') ?>" class="w-56"><input type="hidden" name="range" value="<?= e($range) ?>">
                <?= select_field('fuel', array_map(fn ($p) => $name($p, $p['code']), $products), $fuel, ['placeholder' => __('All fuels'), 'submit' => true, 'aria' => __('Fuel')]) ?></form>
        </div>
        <?php if (! $history): ?><p class="px-5 py-10 text-center text-sm text-steel"><?= e(__('No price changes saved yet.')) ?></p><?php else: ?>
        <div class="max-h-[32rem] overflow-y-auto"><table class="w-full text-sm">
            <thead class="sticky top-0 bg-mist text-left text-xs text-steel"><tr>
                <th class="px-5 py-2 font-medium"><?= e(__('Since')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Fuel')) ?></th>
                <th class="px-3 py-2 text-right font-medium"><?= e(__('Before')) ?></th><th class="px-3 py-2 text-right font-medium"><?= e(__('Price')) ?></th><th class="px-5 py-2 text-right font-medium"><?= e(__('Change')) ?></th></tr></thead>
            <tbody class="divide-y divide-graphite-900/6">
            <?php foreach ($history as $h): $d = $h['before'] !== null ? round((float) $h['price'] - (float) $h['before'], 2) : null; $p = $products[$h['product_code']] ?? null; ?>
                <tr>
                    <td class="whitespace-nowrap px-5 py-2 text-steel"><?= e(format_date($h['effective_at'], 'd M Y')) ?></td>
                    <td class="px-3 py-2"><span class="inline-flex items-center gap-2"><span class="size-2.5 rounded-full" style="background: <?= e(App\Core\Support\OilPrices::color($h['product_code'])) ?>"></span><?= e($name($p, $h['product_code'])) ?></span></td>
                    <td class="px-3 py-2 text-right tabular-nums text-steel"><?= $h['before'] !== null ? e(number_format((float) $h['before'], 2)) : '—' ?></td>
                    <td class="px-3 py-2 text-right font-medium tabular-nums"><?= e(number_format((float) $h['price'], 2)) ?></td>
                    <td class="px-5 py-2 text-right tabular-nums <?= $d === null ? 'text-steel' : ($d > 0 ? 'text-signal-700' : 'text-emerald-700') ?>"><?= $d === null ? e(__('first')) : ($d > 0 ? '▲ +' : '▼ ').e(number_format($d, 2)) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
        <?php endif ?>
    </section>

    <div class="space-y-4 xl:col-span-2">
        <section class="panel p-5">
            <h2 class="panel-title"><?= e(__('Morning sync')) ?></h2>
            <p class="mt-1 text-xs text-steel"><?= e(__('Every day at these times (Bangkok). A run that finds no change saves nothing.')) ?></p>
            <form method="POST" action="<?= url('/management/oil-prices/times') ?>" class="mt-3" data-oil-times>
                <?= csrf_field() ?>
                <ul class="space-y-2" data-list>
                    <?php foreach ($times as $t): ?>
                    <li class="flex items-center gap-2"><div class="w-40"><input type="time" name="times[]" value="<?= e($t) ?>" class="input" required aria-label="<?= e(__('Sync time')) ?>"></div>
                        <button type="button" class="btn-ghost px-2 text-steel" data-remove aria-label="<?= e(__('Remove')) ?>"><?= icon('x', 'size-4') ?></button></li>
                    <?php endforeach ?>
                </ul>
                <?= field_error('times') ?>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" class="btn-secondary" data-add><?= icon('plus', 'size-4') ?> <?= e(__('Add time')) ?></button>
                    <button class="btn-primary"><?= e(__('Save times')) ?></button>
                </div>
            </form>
            <p class="mt-3 text-xs text-steel"><?= e($due ? __('The :t sync is due and runs within a minute.', ['t' => $due]) : __('Runs from the server\'s cron (bin/oil-prices.php), or by the website itself while someone is signed in.')) ?></p>
        </section>

        <section class="panel p-5">
            <h2 class="panel-title"><?= e(__('Load past prices')) ?></h2>
            <p class="mt-1 text-xs text-steel"><?= e(__('Ask PTT for earlier days to fill the history and the chart. Takes up to a few minutes.')) ?></p>
            <form method="POST" action="<?= url('/management/oil-prices/history') ?>" class="mt-3 flex flex-wrap items-center gap-2" data-busy-text="<?= e(__('Loading…')) ?>">
                <?= csrf_field() ?>
                <div class="w-40"><?= select_field('days', [30 => __('Last 30 days'), 90 => __('Last 90 days'), 180 => __('Last 6 months'), 365 => __('Last year')], '90', ['search' => false, 'aria' => __('How far back')]) ?></div>
                <button class="btn-secondary"><?= icon('download', 'size-4') ?> <?= e(__('Load')) ?></button>
            </form>
        </section>

        <section class="panel overflow-hidden">
            <div class="panel-head"><h2 class="panel-title"><?= e(__('Sync log')) ?></h2></div>
            <ul class="divide-y divide-graphite-900/6 text-sm">
                <?php foreach ($syncs as $s): ?>
                <li class="flex items-start gap-3 px-5 py-2.5">
                    <span class="mt-1.5 size-2 shrink-0 rounded-full <?= $s['status'] === 'ok' ? 'bg-emerald-500' : 'bg-signal-600' ?>"></span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate"><?= e(format_date($s['run_at'], 'd M Y H:i')) ?> · <?= e($src[$s['source']] ?? $s['source']) ?><?= $s['user_name'] ? ' · '.e($s['user_name']) : '' ?></p>
                        <p class="truncate text-xs <?= $s['status'] === 'ok' ? 'text-steel' : 'text-signal-700' ?>" title="<?= e((string) $s['message']) ?>">
                            <?= $s['status'] === 'ok' ? e(__(':c change(s) of :p fuels', ['c' => $s['changed'], 'p' => $s['products']])).($s['source'] === 'history' ? ' · '.e(format_date($s['for_date'], 'd M Y')) : '') : e((string) $s['message']) ?></p>
                    </div>
                </li>
                <?php endforeach ?>
                <?php if (! $syncs): ?><li class="px-5 py-6 text-center text-steel"><?= e(__('No syncs yet.')) ?></li><?php endif ?>
            </ul>
        </section>
    </div>
</div>

<?php if (count($dates) >= 2): ?>
<script src="<?= asset('assets/vendor/chartjs/chart.umd.min.js') ?>"></script>
<script>
(function () {
    var dates = <?= json_encode($dates) ?>, series = <?= json_encode($series, JSON_UNESCAPED_UNICODE) ?>;
    var loc = <?= json_encode(locale() === 'th' ? 'th-TH' : 'en-GB') ?>;
    var label = function (d) { return new Date(d + 'T00:00:00').toLocaleDateString(loc, { day: 'numeric', month: 'short', year: '2-digit' }); };
    new Chart(document.getElementById('oil-chart'), {
        type: 'line',
        data: { labels: dates.map(label), datasets: series.map(function (s) { return { label: s.label, data: s.data, borderColor: s.color, backgroundColor: s.color, stepped: 'before', borderWidth: 2, pointRadius: 0, pointHoverRadius: 4, hidden: !s.active, spanGaps: true }; }) },
        options: {
            maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, usePointStyle: true } },
                tooltip: { itemSort: function (a, b) { return b.parsed.y - a.parsed.y; }, callbacks: { label: function (c) { return c.dataset.label + ': ' + (c.parsed.y == null ? '—' : c.parsed.y.toFixed(2)); } } } },
            scales: { x: { ticks: { maxTicksLimit: 10, autoSkip: true }, grid: { display: false } }, y: { ticks: { callback: function (v) { return v.toFixed(2); } } } }
        }
    });
})();
</script>
<?php endif ?>
<script>
(function () {
    var f = document.querySelector('[data-oil-times]'); if (!f) return;
    var list = f.querySelector('[data-list]');
    f.addEventListener('click', function (e) {
        if (e.target.closest('[data-remove]')) { if (list.children.length > 1) e.target.closest('li').remove(); return; }
        if (e.target.closest('[data-add]')) {
            var li = list.lastElementChild.cloneNode(false), tpl = <?= json_encode('<div class="w-40"><input type="time" name="times[]" value="08:00" class="input" required aria-label="'.e(__('Sync time')).'"></div><button type="button" class="btn-ghost px-2 text-steel" data-remove aria-label="'.e(__('Remove')).'">'.icon('x', 'size-4').'</button>') ?>;
            li.innerHTML = tpl; list.appendChild(li);
        }
    });
})();
</script>
