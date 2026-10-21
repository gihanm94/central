<?php /* Amount with its currency in one box: a currency list (name="currency") and the number. $name, $f (currencies, currency), $row, $v, $err */
$cur = (string) old('currency', $row['currency'] ?? ($f['currency'] ?? 'THB'));
$amt = old($name, $row[$name] ?? '');
$amt = $amt !== '' && $amt !== null ? str_replace(',', '', number_clean($amt)) : '';
$req = str_contains($f['rules'] ?? '', 'required'); ?>
<label for="f-<?= e($name) ?>" class="label"><?= e($f['label']) ?><?= $req ? ' <span class="text-signal-600" aria-hidden="true">*</span>' : '' ?></label>
<div class="flex">
    <select name="currency" aria-label="<?= e(__('Currency')) ?>" class="input !w-28 shrink-0 rounded-r-none">
        <?php foreach ($f['currencies'] as $code => $label): ?><option value="<?= e($code) ?>" <?= (string) $code === $cur ? 'selected' : '' ?> title="<?= e($label) ?>"><?= e($code) ?></option><?php endforeach ?>
    </select>
    <input id="f-<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e($amt) ?>" inputmode="decimal" placeholder="0.00" class="input -ml-px min-w-0 flex-1 rounded-l-none tabular-nums <?= $err ? 'input-error' : '' ?>">
</div>
<?php if (! empty($f['help'])): ?><p class="hint"><?= e($f['help']) ?></p><?php endif ?>
<?php if ($err): ?><p class="error"><?= e($err) ?></p><?php endif ?>
