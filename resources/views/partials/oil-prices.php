<?php /* Fuel price tiles (PTT style). $oil = OilPrices::current(). The fuels changed in the newest update get a red "Latest" tag. */
$newest = $oil ? max(array_column($oil, 'effective_at')) : null;
?>
<ul class="grid gap-x-6 gap-y-2.5 p-5 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
    <?php foreach ($oil as $o): $name = locale() === 'th' && $o['name_th'] ? $o['name_th'] : $o['name_en']; $isNew = $o['effective_at'] === $newest; ?>
    <li class="flex items-stretch overflow-hidden rounded-lg ring-1 ring-graphite-900/8">
        <span class="flex w-36 shrink-0 items-center px-3 py-2 text-sm font-bold leading-tight text-white" style="background: <?= e($o['color']) ?>"><?= e($name) ?></span>
        <span class="flex min-w-0 flex-1 items-center justify-between gap-2 bg-white px-3 py-1.5">
            <span class="min-w-0">
                <span class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide <?= $isNew ? 'bg-signal-50 text-signal-700' : 'bg-graphite-900/6 text-steel' ?>"><?= e(__('Latest')) ?> · <?= e(format_date($o['effective_at'], 'd M')) ?></span>
                <?php if ($o['change']): ?><span class="mt-0.5 block text-xs tabular-nums <?= $o['change'] > 0 ? 'text-signal-700' : 'text-emerald-700' ?>"><?= $o['change'] > 0 ? '▲ +' : '▼ ' ?><?= e(number_format($o['change'], 2)) ?></span><?php endif ?>
            </span>
            <span class="font-display text-2xl font-semibold italic tabular-nums text-[#1e3a8a]"><?= e(number_format($o['price'], 2)) ?></span>
        </span>
    </li>
    <?php endforeach ?>
</ul>
