<?php
use App\Core\Support\Period;
/* The period + select filter bar of the dashboards.
   $action (url), $range, $from, $to (DateTimeImmutable), $keep (other query params kept by the presets), $selects = [[name, label, options, value, placeholder]] */
$keep = array_filter($keep ?? [], fn ($v) => $v !== null && $v !== '' && $v !== 0 && $v !== '0');
?>
<form method="GET" action="<?= e($action) ?>" id="ctl-filter" class="panel mt-4 flex flex-wrap items-center gap-x-4 gap-y-3 p-3">
    <input type="hidden" name="range" value="<?= e($range) ?>" data-range>
    <?php $own = array_merge(['from', 'to', 'range'], array_column($selects ?? [], 0)); foreach ($keep as $k => $v): if (in_array($k, $own, true)) continue; ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach ?>
    <nav class="flex flex-wrap gap-1 rounded-lg bg-mist p-1" aria-label="<?= e(__('Period')) ?>">
        <?php foreach (Period::PRESETS as $k => $l): ?><a href="<?= e(url($action, $keep + ['range' => $k])) ?>" class="rounded-md px-3 py-1.5 text-sm <?= $range === $k ? 'bg-white font-medium shadow-sm' : 'text-steel hover:text-graphite-900' ?>"><?= e(__($l)) ?></a><?php endforeach ?>
    </nav>
    <div class="flex flex-wrap items-center gap-2 text-sm">
        <label class="sr-only" for="ctl-from"><?= e(__('From')) ?></label><input id="ctl-from" type="date" name="from" value="<?= e($from->format('Y-m-d')) ?>" class="input !w-40" data-custom>
        <span class="text-steel">→</span>
        <label class="sr-only" for="ctl-to"><?= e(__('To')) ?></label><input id="ctl-to" type="date" name="to" value="<?= e($to->format('Y-m-d')) ?>" class="input !w-40" data-custom>
    </div>
    <?php if (! empty($selects)): ?>
    <div class="ml-auto flex flex-wrap items-center gap-3">
        <?php foreach ($selects as [$name, $label, $options, $value, $placeholder]): ?>
        <div class="flex items-center gap-2"><span class="hidden text-sm text-steel sm:inline"><?= e($label) ?></span>
            <div class="min-w-[11rem]"><?= select_field($name, $options, (string) $value, ['aria' => $label, 'submit' => true, 'class' => 'input', 'placeholder' => $placeholder]) ?></div></div>
        <?php endforeach ?>
    </div>
    <?php endif ?>
</form>
<script>(function(){var f=document.getElementById('ctl-filter');if(!f)return;var r=f.querySelector('[data-range]');Array.prototype.forEach.call(f.querySelectorAll('[data-custom]'),function(i){i.addEventListener('change',function(){r.value='custom';f.submit();});});})();</script>
