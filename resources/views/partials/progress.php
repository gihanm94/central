<?php $pct = max(0, min(100, (int) $value)); $tone = $value >= 100 ? 'bg-emerald-600' : ($value >= 70 ? 'bg-graphite-800' : 'bg-signal-600'); ?>
<div class="h-1.5 w-full overflow-hidden rounded-full bg-graphite-900/8" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
    <div class="h-full rounded-full <?= $tone ?>" style="width: <?= $pct ?>%"></div>
</div>
