<?php /* Amount with its currency in one box: the app's dropdown for the currency (name="currency") and the number (the one the required check reads). $name, $f (currencies, currency), $row, $v, $err */
$cur = (string) old('currency', $row['currency'] ?? ($f['currency'] ?? 'THB'));
$amt = old($name, $row[$name] ?? '');
$amt = $amt !== '' && $amt !== null ? str_replace(',', '', number_clean($amt)) : '';
$req = str_contains($f['rules'] ?? '', 'required'); ?>
<label for="f-<?= e($name) ?>" class="label"><?= e($f['label']) ?><?= $req ? ' <span class="text-signal-600" aria-hidden="true">*</span>' : '' ?></label>
<div class="flex">
    <?= select_field('currency', array_combine(array_keys($f['currencies']), array_keys($f['currencies'])), $cur, ['id' => 'f-currency', 'aria' => __('Currency'), 'search' => count($f['currencies']) > 8,
        'class' => 'input rounded-r-none tabular-nums', 'wrap' => 'w-28 shrink-0']) ?>
    <input id="f-<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e($amt) ?>" inputmode="decimal" placeholder="0.00" data-fill-check class="input -ml-px min-w-0 flex-1 rounded-l-none tabular-nums <?= $err ? 'input-error' : '' ?>">
</div>
<?php if (! empty($f['help'])): ?><p class="hint"><?= e($f['help']) ?></p><?php endif ?>
<?php if ($err): ?><p class="error"><?= e($err) ?></p><?php endif ?>
