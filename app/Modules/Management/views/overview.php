<?php /* $from, $to, $period, $year, $data ?array (Accounting revenue.ranking), $departments [id => row], $people [id => user], $kpi {n, avg} */
use App\Modules\Management\Controllers\OverviewController;

$baht = fn (float $v) => '฿'.number_format($v, 0);
$short = function (float $v): string {
    $a = abs($v);
    return '฿'.($a >= 1e9 ? number_format($v / 1e9, 2).'B' : ($a >= 1e6 ? number_format($v / 1e6, 2).'M' : ($a >= 1e3 ? number_format($v / 1e3, 1).'K' : number_format($v, 0))));
};
$medal = fn (int $i) => $i === 0 ? 'bg-amber-400 text-white' : ($i === 1 ? 'bg-graphite-300 text-graphite-900' : ($i === 2 ? 'bg-amber-700 text-white' : 'bg-graphite-900/6 text-graphite-700'));
$years = range((int) date('Y'), (int) date('Y') - 5);
$tab = fn (string $k) => url('/management', array_filter(['period' => $k === 'ytd' ? null : $k, 'year' => $k === 'year' ? $year : null]));
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0"><h1 class="page-title"><?= e(__('Overview')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(format_date($from, 'j M Y').' – '.format_date($to, 'j M Y')) ?> · <?= e(__('Revenue from ERP invoices in THB, credit notes subtracted.')) ?></p></div>
</div>

<div class="mt-4 flex flex-wrap items-center gap-2">
    <nav class="flex flex-wrap rounded-md bg-graphite-900/6 p-0.5 text-sm" aria-label="<?= e(__('Period')) ?>">
        <?php foreach (OverviewController::PERIODS as $k => $label): if ($k === 'custom') { continue; } ?>
            <a href="<?= e($tab($k)) ?>" class="rounded px-3 py-1.5 <?= $period === $k ? 'bg-white font-medium shadow-sm' : 'text-steel hover:text-graphite-900' ?>"><?= e(__($label)) ?></a>
        <?php endforeach ?>
    </nav>
    <?php if ($period === 'year'): ?>
        <form method="GET" action="<?= url('/management') ?>" class="w-28"><input type="hidden" name="period" value="year"><?= select_field('year', array_combine($years, $years), $year, ['id' => 'mg-year', 'submit' => true, 'search' => false, 'aria' => __('Year')]) ?></form>
    <?php endif ?>
    <form method="GET" action="<?= url('/management') ?>" class="ml-auto flex flex-wrap items-center gap-2">
        <input type="hidden" name="period" value="custom">
        <input type="date" name="from" value="<?= e($from) ?>" class="input !w-40" aria-label="<?= e(__('From')) ?>"><span class="text-steel">→</span>
        <input type="date" name="to" value="<?= e($to) ?>" class="input !w-40" aria-label="<?= e(__('To')) ?>">
        <button class="btn-secondary"><?= e(__('Show')) ?></button>
    </form>
</div>

<?php if ($data === null): ?>
    <section class="panel mt-4 px-5 py-10 text-center text-sm text-steel"><?= e(__('Revenue is not available right now (the Accounting module is off or unavailable). The KPI calculator still works.')) ?></section>
<?php else:
    $total = $data['total'];
    $change = $data['previous'] > 0 ? ($total - $data['previous']) / $data['previous'] * 100 : null;
    $maxMonth = $data['months'] ? max(array_map('abs', $data['months'])) : 0;
?>
<div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
    <div class="panel p-4"><p class="text-xs font-medium uppercase tracking-wide text-steel"><?= e(__('Revenue')) ?></p>
        <p class="mt-1 font-display text-4xl font-semibold tabular-nums" title="<?= e($baht($total)) ?>"><?= e($short($total)) ?></p>
        <p class="mt-1 text-xs <?= $change === null ? 'text-steel' : ($change >= 0 ? 'text-emerald-700' : 'text-signal-700') ?>">
            <?= $change === null ? e(__('No revenue in the period before')) : e(($change >= 0 ? '▲ ' : '▼ ').number_format(abs($change), 1).'% '.__('vs :from – :to', ['from' => format_date($data['previous_range'][0], 'j M'), 'to' => format_date($data['previous_range'][1], 'j M Y')])) ?></p></div>
    <div class="panel p-4"><p class="text-xs font-medium uppercase tracking-wide text-steel"><?= e(__('Invoices')) ?></p>
        <p class="mt-1 font-display text-4xl font-semibold tabular-nums"><?= e(number_format($data['invoices'])) ?></p>
        <p class="mt-1 text-xs text-steel"><?= e(__('Average :v per invoice', ['v' => $data['invoices'] ? $short($total / $data['invoices']) : '—'])) ?></p></div>
    <div class="panel p-4"><p class="text-xs font-medium uppercase tracking-wide text-steel"><?= e(__('Customers billed')) ?></p>
        <p class="mt-1 font-display text-4xl font-semibold tabular-nums"><?= e(number_format($data['customers_count'])) ?></p>
        <p class="mt-1 text-xs text-steel"><?= e(__('Top 10 bring :p% of revenue', ['p' => $total > 0 ? number_format(array_sum(array_column($data['customers'], 'v')) / $total * 100, 1) : '0'])) ?></p></div>
    <a href="<?= url('/management/kpi-calculator', ['year' => substr($from, 0, 4)]) ?>" class="panel block p-4 hover:ring-graphite-900/20"><p class="text-xs font-medium uppercase tracking-wide text-steel"><?= e(__('KPI scores :y', ['y' => substr($from, 0, 4)])) ?></p>
        <p class="mt-1 font-display text-4xl font-semibold tabular-nums"><?= $kpi['n'] ? e(number_format((float) $kpi['avg'], 2)) : '—' ?></p>
        <p class="mt-1 text-xs text-steel"><?= e(__('Average of :n saved scores · open the KPI calculator', ['n' => (int) $kpi['n']])) ?></p></a>
</div>

<?php if (count($data['months']) > 1): ?>
<section class="panel mt-4 p-5">
    <h2 class="panel-title"><?= e(__('Revenue by month')) ?></h2>
    <div class="mt-4 flex h-44 items-end gap-2">
        <?php foreach ($data['months'] as $ym => $v): $h = $maxMonth > 0 ? max(2, abs($v) / $maxMonth * 100) : 2; ?>
            <div class="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1" title="<?= e(format_date($ym.'-01', 'F Y').': '.$baht($v)) ?>">
                <span class="truncate text-[10px] tabular-nums text-steel"><?= e($short($v)) ?></span>
                <div class="w-full max-w-12 rounded-t <?= $v < 0 ? 'bg-signal-400' : 'bg-signal-600' ?>" style="height: <?= round($h, 1) ?>%"></div>
                <span class="text-[11px] text-steel"><?= e(format_date($ym.'-01', 'M')) ?></span>
            </div>
        <?php endforeach ?>
    </div>
</section>
<?php endif ?>

<div class="mt-4 grid gap-4 xl:grid-cols-2">
    <section class="panel overflow-hidden">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Top sellers')) ?></h2><span class="text-xs text-steel"><?= e(__('by revenue')) ?></span></div>
        <?php if (! $data['sellers']): ?><p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('No revenue in this period.')) ?></p><?php endif ?>
        <ol class="divide-y divide-graphite-900/6">
            <?php foreach ($data['sellers'] as $i => $s):
                $u = $s['user_id'] ? ($people[$s['user_id']] ?? null) : null;
                $dep = $departments[$s['department_id'] ?? 0] ?? null; ?>
                <li class="flex items-center gap-3 px-5 py-2.5">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-bold <?= $medal($i) ?>"><?= $i + 1 ?></span>
                    <?= partial('partials/avatar', ['name' => $u['name'] ?? $s['name'], 'avatar' => $u['avatar'] ?? null, 'size' => 'size-9']) ?>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium"><?= e($s['name']) ?></p>
                        <p class="truncate text-xs text-steel"><?= e(($dep['name'] ?? __('No department set')).' · '.__(':n invoices · :c customers', ['n' => $s['n'], 'c' => $s['customers']])) ?></p>
                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-graphite-900/6"><div class="h-full rounded-full bg-signal-600" style="width: <?= $total > 0 ? round(max(0, $s['v']) / $total * 100, 1) : 0 ?>%"></div></div>
                    </div>
                    <div class="text-right"><p class="font-display text-lg font-semibold tabular-nums" title="<?= e($baht($s['v'])) ?>"><?= e($short($s['v'])) ?></p>
                        <p class="text-xs tabular-nums text-steel"><?= $total > 0 ? e(number_format($s['v'] / $total * 100, 1)).'%' : '—' ?></p></div>
                </li>
            <?php endforeach ?>
        </ol>
    </section>

    <div class="space-y-4">
        <section class="panel overflow-hidden">
            <div class="panel-head"><h2 class="panel-title"><?= e(__('Department ranking')) ?></h2><span class="text-xs text-steel"><?= e(__('by revenue of their sellers')) ?></span></div>
            <?php if (! $data['departments']): ?><p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('No revenue in this period.')) ?></p><?php endif ?>
            <ol class="divide-y divide-graphite-900/6">
                <?php $rank = 0; foreach ($data['departments'] as $d): $dep = $d['department_id'] ? ($departments[$d['department_id']] ?? null) : null; $named = $dep !== null; ?>
                    <li class="flex items-center gap-3 px-5 py-2.5 <?= $named ? '' : 'bg-mist/40' ?>">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-bold <?= $named ? $medal($rank) : 'bg-graphite-900/6 text-graphite-400' ?>"><?= $named ? ++$rank : '–' ?></span>
                        <span class="size-3 shrink-0 rounded-full" style="background: <?= e($dep['color'] ?? '#9ca3af') ?>"></span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium"><?= e($dep['name'] ?? __('Sellers without a department')) ?></p>
                            <p class="text-xs text-steel"><?= e(__(':s sellers · :n invoices', ['s' => $d['sellers'], 'n' => $d['n']])) ?><?= $named ? '' : ' · '.e(__('set it in Accounting → Sellers')) ?></p>
                            <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-graphite-900/6"><div class="h-full rounded-full" style="background: <?= e($dep['color'] ?? '#9ca3af') ?>; width: <?= $total > 0 ? round(max(0, $d['v']) / $total * 100, 1) : 0 ?>%"></div></div>
                        </div>
                        <div class="text-right"><p class="font-display text-lg font-semibold tabular-nums" title="<?= e($baht($d['v'])) ?>"><?= e($short($d['v'])) ?></p>
                            <p class="text-xs tabular-nums text-steel"><?= $total > 0 ? e(number_format($d['v'] / $total * 100, 1)).'%' : '—' ?></p></div>
                    </li>
                <?php endforeach ?>
            </ol>
        </section>

        <section class="panel overflow-hidden">
            <div class="panel-head"><h2 class="panel-title"><?= e(__('Top revenue customers')) ?></h2><span class="text-xs text-steel"><?= e(__('top 10')) ?></span></div>
            <?php if (! $data['customers']): ?><p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('No revenue in this period.')) ?></p><?php endif ?>
            <ol class="divide-y divide-graphite-900/6">
                <?php foreach ($data['customers'] as $i => $c): ?>
                    <li class="flex items-center gap-3 px-5 py-2.5">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-bold <?= $medal($i) ?>"><?= $i + 1 ?></span>
                        <div class="min-w-0 flex-1"><p class="truncate font-medium"><?= e($c['name']) ?></p>
                            <p class="text-xs text-steel"><?= e(($c['code'] ?: '—').' · '.__(':n invoices', ['n' => $c['n']])) ?></p>
                            <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-graphite-900/6"><div class="h-full rounded-full bg-graphite-700" style="width: <?= $total > 0 ? round(max(0, $c['v']) / $total * 100, 1) : 0 ?>%"></div></div></div>
                        <div class="text-right"><p class="font-display text-lg font-semibold tabular-nums" title="<?= e($baht($c['v'])) ?>"><?= e($short($c['v'])) ?></p>
                            <p class="text-xs tabular-nums text-steel"><?= $total > 0 ? e(number_format($c['v'] / $total * 100, 1)).'%' : '—' ?></p></div>
                    </li>
                <?php endforeach ?>
            </ol>
        </section>
    </div>
</div>
<?php endif ?>
