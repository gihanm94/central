<?php /* $row project, $total, $done, $late tasks */
$pct  = $total ? (int) round($done / $total * 100) : 0;
$left = $row['end_date'] ? (int) floor((strtotime((string) $row['end_date'].' 23:59:59') - time()) / 86400) : null;
?>
<section class="mt-6 grid gap-4 rounded-xl bg-white p-5 shadow-sm ring-1 ring-graphite-900/8 sm:grid-cols-[1fr_auto] sm:items-center">
    <div>
        <div class="flex items-baseline justify-between gap-3"><p class="text-sm font-medium"><?= e(__('Progress')) ?></p><p class="text-sm tabular-nums text-steel"><span class="font-semibold text-graphite-900"><?= $pct ?>%</span> · <?= e(__(':done of :total tasks done', ['done' => $done, 'total' => $total])) ?></p></div>
        <div class="mt-2 h-2 overflow-hidden rounded-full bg-graphite-900/8" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full bg-emerald-600" style="width:<?= $pct ?>%"></div></div>
    </div>
    <dl class="flex gap-6 text-sm">
        <div><dt class="text-xs text-steel"><?= e(__('Late tasks')) ?></dt><dd class="font-semibold tabular-nums <?= $late ? 'text-signal-700' : '' ?>"><?= (int) $late ?></dd></div>
        <?php if ($left !== null && ! in_array($row['status'], ['DONE', 'CANCELLED'], true)): ?>
        <div><dt class="text-xs text-steel"><?= e($left >= 0 ? __('Days left') : __('Days late')) ?></dt><dd class="font-semibold tabular-nums <?= $left < 0 ? 'text-signal-700' : '' ?>"><?= abs($left) ?></dd></div>
        <?php endif ?>
    </dl>
</section>
