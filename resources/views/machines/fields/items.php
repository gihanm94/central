<?php
use App\Modules\Machines\Support\Mx;
/* Checklist items of a machine: a question from the bank + (general list only) how often it is reset. $name, $f ['questions', 'resets' => bool], $row, $err */
$qs   = $f['questions'];
$rows = old($name);
if (! is_array($rows)) { $rows = $f['rows'] ?? []; }
$resets = ! empty($f['resets']);
$rowHtml = function ($i, array $p) use ($name, $qs, $resets) { ob_start(); ?>
    <div data-repeat-row class="grid grid-cols-12 items-center gap-2 rounded-md bg-mist/50 p-3">
        <div class="col-span-12 <?= $resets ? 'sm:col-span-7' : 'sm:col-span-10' ?>"><?= select_field($name.'['.$i.'][question_id]', $qs, (string) ($p['question_id'] ?? ''), ['placeholder' => __('Choose a question…'), 'aria' => __('Question')]) ?></div>
        <?php if ($resets): ?><div class="col-span-9 sm:col-span-4"><?= select_field($name.'['.$i.'][reset_time]', array_map(fn ($l) => __($l), Mx::RESETS), (string) ($p['reset_time'] ?? Mx::GENERAL), ['aria' => __('When'), 'search' => false]) ?></div><?php endif ?>
        <div class="col-span-3 flex justify-end sm:col-span-1"><button type="button" data-repeat-remove class="btn-ghost px-2 py-1.5 hover:text-signal-700" aria-label="<?= e(__('Remove')) ?>"><?= icon('trash', 'size-4') ?></button></div>
    </div>
<?php return ob_get_clean(); };
?>
<span class="label"><?= e($f['label']) ?></span>
<div data-repeat data-min="0" class="space-y-2">
    <div data-repeat-rows class="space-y-2"><?php $next = 0; foreach (array_values($rows) as $i => $p) { echo $rowHtml($i, $p); $next = $i + 1; } ?></div>
    <template data-repeat-template><?= $rowHtml('__i__', []) ?></template>
    <input type="hidden" data-repeat-next value="<?= $next ?>">
    <button type="button" data-repeat-add class="btn-secondary py-1.5"><?= icon('plus', 'size-4') ?> <?= e(__('Add a question')) ?></button>
    <?php if (! empty($f['help'])): ?><p class="hint"><?= e($f['help']) ?></p><?php endif ?>
</div>
<?php if ($err): ?><p class="error"><?= e($err) ?></p><?php endif ?>
