<?php
/* Repeating mobile-number rows. $name, $f, $row, $err */
$rows = old('mobiles');
if (! is_array($rows)) { $rows = array_map(fn ($m) => ['number' => $m['number'], 'ext' => $m['ext'], 'label' => $m['label'], 'primary' => filter_var($m['is_primary'], FILTER_VALIDATE_BOOL)], $row['mobile_list'] ?? []); }
if (! $rows) { $rows = [['number' => '', 'ext' => '', 'label' => '']]; }
$primary = old('mobile_primary'); $hasPrimary = $primary !== null;
$rowHtml = function ($i, array $m, bool $checked) { ob_start(); ?>
    <div data-repeat-row class="grid grid-cols-12 items-start gap-2">
        <div class="col-span-12 sm:col-span-5"><input name="mobiles[<?= e($i) ?>][number]" value="<?= e($m['number'] ?? '') ?>" type="tel" inputmode="tel" maxlength="40" placeholder="<?= e(__('Mobile number')) ?>" aria-label="<?= e(__('Mobile number')) ?>" class="input"></div>
        <div class="col-span-4 sm:col-span-2"><input name="mobiles[<?= e($i) ?>][ext]" value="<?= e($m['ext'] ?? '') ?>" maxlength="10" placeholder="<?= e(__('Ext.')) ?>" aria-label="<?= e(__('Extension')) ?>" class="input"></div>
        <div class="col-span-8 sm:col-span-3"><input name="mobiles[<?= e($i) ?>][label]" value="<?= e($m['label'] ?? '') ?>" maxlength="30" placeholder="<?= e(__('Label (Work, Personal)')) ?>" aria-label="<?= e(__('Label')) ?>" class="input"></div>
        <div class="col-span-12 flex items-center justify-between gap-2 sm:col-span-2 sm:justify-end">
            <label class="flex cursor-pointer items-center gap-1.5 whitespace-nowrap py-2 text-xs"><input type="radio" name="mobile_primary" value="<?= e($i) ?>" class="size-4 accent-signal-600" <?= $checked ? 'checked' : '' ?>> <?= e(__('Main')) ?></label>
            <button type="button" data-repeat-remove class="btn-ghost px-2 py-1.5 hover:text-signal-700" aria-label="<?= e(__('Remove this number')) ?>"><?= icon('x', 'size-4') ?></button>
        </div>
    </div>
<?php return ob_get_clean(); };
$next = 0;
?>
<span class="label"><?= e($f['label']) ?> <span class="font-normal text-steel">(<?= e(__('optional')) ?>)</span></span>
<div data-repeat data-min="1" class="space-y-2">
    <div data-repeat-rows class="space-y-2">
        <?php foreach (array_values($rows) as $i => $m): $isMain = $hasPrimary ? (string) $primary === (string) $i : (! empty($m['primary']) || ($i === 0 && ! array_filter(array_column($rows, 'primary')))); echo $rowHtml($i, $m, $isMain); $next = $i + 1; endforeach ?>
    </div>
    <template data-repeat-template><?= $rowHtml('__i__', [], false) ?></template>
    <input type="hidden" data-repeat-next value="<?= $next ?>">
    <button type="button" data-repeat-add class="btn-secondary py-1.5"><?= icon('plus', 'size-4') ?> <?= e(__('Add another mobile')) ?></button>
</div>
<?php if (! empty($f['help'])): ?><p class="hint"><?= e($f['help']) ?></p><?php endif ?>
<?php if ($err): ?><p class="error"><?= e($err) ?></p><?php endif ?>
