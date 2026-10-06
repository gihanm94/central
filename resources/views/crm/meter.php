<?php /* Small neutral progress bar with a number: $value 0-100, $label */ $pct = max(0, min(100, (float) $value)); ?>
<div class="flex min-w-24 items-center gap-2" title="<?= e($label) ?>">
    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-graphite-900/8" role="progressbar" aria-valuenow="<?= (int) $pct ?>" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full bg-graphite-700" style="width: <?= round($pct, 1) ?>%"></div></div>
    <span class="w-10 text-right text-xs tabular-nums text-steel"><?= e($label) ?></span>
</div>
