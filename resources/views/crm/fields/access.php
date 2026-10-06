<?php
/* "Share with" step of a new record. $f['targets'] = ['user:5' => 'Name · Dept', 'department:2' => 'Department: Sales'], $f['dept'] = the creator's own department */
$targets = $f['targets'];
$levels  = ['view' => __('View only'), 'edit' => __('Can edit')];
$rowHtml = function ($i, array $r) use ($targets, $levels) { ob_start(); ?>
    <div data-repeat-row class="grid grid-cols-12 items-start gap-2 rounded-md bg-mist/50 p-3">
        <div class="col-span-12 sm:col-span-7"><?= select_field('access['.$i.'][target]', $targets, $r['target'] ?? '', ['placeholder' => __('Choose a person or department…'), 'id' => 'acc-t-'.$i, 'aria' => __('Person or department')]) ?></div>
        <div class="col-span-9 sm:col-span-3"><?= select_field('access['.$i.'][level]', $levels, $r['level'] ?? 'view', ['id' => 'acc-l-'.$i, 'search' => false, 'required' => true, 'aria' => __('Access level')]) ?></div>
        <div class="col-span-3 flex justify-end sm:col-span-2"><button type="button" data-repeat-remove class="btn-ghost px-2 hover:text-signal-700" aria-label="<?= e(__('Remove')) ?>"><?= icon('trash', 'size-4') ?></button></div>
    </div>
<?php return ob_get_clean(); };
$rows = old('access'); $rows = is_array($rows) ? array_values($rows) : [];
?>
<div class="rounded-lg bg-mist/60 p-4 text-sm">
    <p class="flex items-start gap-2.5"><?= icon('building', 'mt-0.5 size-4 shrink-0 text-steel') ?>
        <span><?php if ($f['dept']): ?><span class="font-medium"><?= e(__('Everyone in :dept can already see this record.', ['dept' => $f['dept']])) ?></span><?php else: ?><span class="font-medium"><?= e(__('Only you can see this record until you share it.')) ?></span><?php endif ?>
        <span class="block text-xs text-steel"><?= e(__('It belongs to you and your department automatically. Admin and management roles see everything. Add anyone else who should have access:')) ?></span></span></p>
</div>
<div data-repeat data-min="0" class="mt-3 space-y-2">
    <div data-repeat-rows class="space-y-2"><?php foreach ($rows as $i => $r) { echo $rowHtml($i, $r); } ?></div>
    <template data-repeat-template><?= $rowHtml('__i__', []) ?></template>
    <input type="hidden" data-repeat-next value="<?= count($rows) ?>">
    <button type="button" data-repeat-add class="btn-secondary"><?= icon('plus', 'size-4') ?> <?= e(__('Add a person or department')) ?></button>
</div>
<?php if ($err): ?><p class="error"><?= e($err) ?></p><?php endif ?>
