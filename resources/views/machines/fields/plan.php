<?php
/* Yearly plan rows. $f['plan'] = maintenance | calibration; rows come from $f['rows'] or the last post. $name, $f, $row, $err */
$maint = $f['plan'] === 'maintenance';
$rows  = old($name);
if (! is_array($rows)) { $rows = $f['rows'] ?? []; }
if (! $rows) { $rows = []; }
$rowHtml = function ($i, array $p) use ($name, $maint) { ob_start(); ?>
    <div data-repeat-row class="grid grid-cols-12 items-start gap-2 rounded-md bg-mist/50 p-3">
        <input type="hidden" name="<?= e($name) ?>[<?= e($i) ?>][id]" value="<?= e($p['id'] ?? '') ?>">
        <div class="col-span-6 <?= $maint ? 'sm:col-span-3' : 'sm:col-span-4' ?>"><label class="mb-1 block text-xs text-steel"><?= e(__('Due date')) ?></label><input type="date" name="<?= e($name) ?>[<?= e($i) ?>][due_date]" value="<?= e(substr((string) ($p['due_date'] ?? ''), 0, 10)) ?>" class="input"></div>
        <?php if ($maint): ?><div class="col-span-6 sm:col-span-3"><label class="mb-1 block text-xs text-steel"><?= e(__('Plan date')) ?></label><input type="date" name="<?= e($name) ?>[<?= e($i) ?>][plan_date]" value="<?= e(substr((string) ($p['plan_date'] ?? ''), 0, 10)) ?>" class="input"></div><?php endif ?>
        <div class="col-span-12 <?= $maint ? 'sm:col-span-5' : 'sm:col-span-7' ?>"><label class="mb-1 block text-xs text-steel"><?= e(__('Note')) ?></label><input name="<?= e($name) ?>[<?= e($i) ?>][note]" value="<?= e($p['note'] ?? '') ?>" maxlength="500" class="input"></div>
        <div class="col-span-12 flex justify-end sm:col-span-1 sm:pt-5"><button type="button" data-repeat-remove class="btn-ghost px-2 py-1.5 hover:text-signal-700" aria-label="<?= e(__('Remove')) ?>"><?= icon('trash', 'size-4') ?></button></div>
    </div>
<?php return ob_get_clean(); };
?>
<span class="label"><?= e($f['label']) ?></span>
<div data-repeat data-min="0" class="space-y-2">
    <div data-repeat-rows class="space-y-2"><?php $next = 0; foreach (array_values($rows) as $i => $p) { echo $rowHtml($i, $p); $next = $i + 1; } ?></div>
    <template data-repeat-template><?= $rowHtml('__i__', []) ?></template>
    <input type="hidden" data-repeat-next value="<?= $next ?>">
    <button type="button" data-repeat-add class="btn-secondary py-1.5"><?= icon('plus', 'size-4') ?> <?= e($maint ? __('Add a maintenance round') : __('Add a calibration due date')) ?></button>
    <?php if (! empty($f['help'])): ?><p class="hint"><?= e($f['help']) ?></p><?php endif ?>
</div>
<?php if ($err): ?><p class="error"><?= e($err) ?></p><?php endif ?>
