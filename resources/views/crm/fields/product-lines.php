<?php
/* Opportunity products in two lists (crm.js [data-lines]): "Products" picked from Accounting (ERP products) and
   "New products" typed by hand. Each line is hidden inputs products[i][…]; "+ Add" opens a small card to fill in.
   $name, $f (units), $row, $err */
$units = $f['units'];
$rows = old('products');
if (! is_array($rows)) {
    $rows = array_map(fn ($p) => ['kind' => $p['erp_product_id'] || $p['product_id'] ? 'catalog' : 'new', 'erp_product_id' => $p['erp_product_id'], 'product_id' => $p['product_id'],
        'name' => $p['name'], 'part_number' => $p['part_number'], 'quantity' => $p['quantity'], 'unit' => $p['unit'], 'unit_price' => $p['unit_price']], $row['product_list'] ?? []);
}
$rows = array_values($rows);
$line = function ($i, array $p) { ob_start();
    $qty = (float) ($p['quantity'] ?? 1); $price = ($p['unit_price'] ?? '') !== '' && $p['unit_price'] !== null ? (float) $p['unit_price'] : null; ?>
    <li data-line class="flex items-center gap-3 rounded-lg bg-white px-3 py-2.5 ring-1 ring-graphite-900/10">
        <?php foreach (['kind', 'erp_product_id', 'product_id', 'name', 'part_number', 'quantity', 'unit', 'unit_price'] as $k): ?><input type="hidden" name="products[<?= e($i) ?>][<?= $k ?>]" value="<?= e((string) ($p[$k] ?? '')) ?>"><?php endforeach ?>
        <span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium" data-l-name><?= e($p['name'] ?? '') ?></span>
            <span class="block truncate text-xs text-steel" data-l-sub><?= e(implode(' · ', array_filter([$p['part_number'] ?? '', number_clean($qty).' '.($p['unit'] ?? ''), $price !== null ? number_format($price, 2) : '']))) ?></span></span>
        <span class="shrink-0 text-sm font-medium tabular-nums" data-l-total><?= $price !== null ? e(number_format($qty * $price, 2)) : '' ?></span>
        <button type="button" data-line-remove class="btn-ghost shrink-0 px-2 py-1 hover:text-signal-700" aria-label="<?= e(__('Remove')) ?>"><?= icon('trash', 'size-4') ?></button>
    </li>
<?php return ob_get_clean(); };
$card = function (string $kind) use ($units) { ob_start(); ?>
    <div data-line-form="<?= $kind ?>" hidden class="rounded-lg p-4 ring-1 <?= $kind === 'catalog' ? 'bg-signal-50/40 ring-signal-600/30' : 'bg-mist/50 ring-graphite-900/10' ?>">
        <p class="text-xs font-semibold uppercase tracking-wide <?= $kind === 'catalog' ? 'text-signal-700' : 'text-steel' ?>"><?= e($kind === 'catalog' ? __('New product line') : __('New product')) ?></p>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <?php if ($kind === 'catalog'): ?>
            <div class="sm:col-span-2"><span class="label"><?= e(__('Product')) ?></span>
                <?= partial('partials/remote-select', ['name' => '_pick_product', 'value' => '', 'current' => null, 'kind' => 'erp-products', 'o' => ['id' => 'pick-product', 'placeholder' => __('Search product')]]) ?>
                <input type="hidden" data-l="erp_product_id"></div>
            <?php endif ?>
            <label class="sm:col-span-2"><span class="label"><?= e(__('Product name')) ?> <span class="text-signal-600">*</span></span><input data-l="name" maxlength="200" class="input" placeholder="<?= e(__('Product name')) ?>"></label>
            <label><span class="label"><?= e(__('Product no.')) ?></span><input data-l="part_number" maxlength="80" class="input" placeholder="<?= e(__('e.g. SKU-001')) ?>"></label>
            <label><span class="label"><?= e(__('Qty')) ?></span><input data-l="quantity" value="1" inputmode="decimal" class="input"></label>
            <label><span class="label"><?= e(__('Unit price')) ?></span><input data-l="unit_price" inputmode="decimal" class="input" placeholder="0.00"></label>
            <div data-l-unit data-first="<?= e($units[0]) ?>"><span class="label"><?= e(__('Unit')) ?></span>
                <?= select_field('_unit_'.$kind, array_combine($units, $units), $units[0], ['id' => 'unit-'.$kind, 'aria' => __('Unit'), 'search' => false]) ?></div>
        </div>
        <p data-l-error hidden class="error"></p>
        <div class="mt-3 flex justify-end gap-2">
            <button type="button" class="btn-ghost" data-line-cancel><?= e(__('Cancel')) ?></button>
            <button type="button" class="btn-primary" data-line-add><?= icon('plus', 'size-4') ?> <?= e(__('Add')) ?></button>
        </div>
    </div>
<?php return ob_get_clean(); };
$sections = ['catalog' => [__('Products'), 'box', __('Add product'), __('No products added yet'), __('Pick products of Accounting (from the ERP).')],
             'new' => [__('New products'), 'cart', __('Add new product'), __('No new products added yet'), __('Products that are not in Accounting yet: type them in.')]];
?>
<div data-lines data-counter="<?= count($rows) ?>" data-msg-name="<?= e(__('Type the product name.')) ?>" data-msg-qty="<?= e(__('Quantity must be a number above 0.')) ?>" data-msg-price="<?= e(__('Unit price must be a number.')) ?>">
    <?php foreach ($sections as $kind => [$title, $ic, $add, $empty, $hint]): $mine = array_filter($rows, fn ($p) => ($p['kind'] ?? 'new') === $kind); ?>
    <section class="<?= $kind === 'new' ? 'mt-6 border-t border-graphite-900/8 pt-6' : '' ?>" data-line-kind="<?= $kind ?>">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2"><span class="flex size-7 items-center justify-center rounded-md bg-signal-50 text-signal-700"><?= icon($ic, 'size-4') ?></span>
                <span><span class="block text-sm font-semibold"><?= e($title) ?></span><span class="block text-xs text-steel"><?= e($hint) ?></span></span></div>
            <button type="button" data-line-open="<?= $kind ?>" class="inline-flex h-8 items-center gap-1.5 rounded-md px-2.5 text-sm font-medium ring-1 <?= $kind === 'catalog' ? 'text-signal-700 ring-signal-600/40 hover:bg-signal-50' : 'ring-graphite-900/15 hover:bg-mist' ?>"><?= icon('plus', 'size-4') ?> <?= e($add) ?></button>
        </div>
        <div class="mt-3"><?= $card($kind) ?></div>
        <ul data-line-list="<?= $kind ?>" class="mt-3 space-y-2"><?php foreach ($mine as $i => $p) { echo $line($i, $p); } ?></ul>
        <div data-line-empty="<?= $kind ?>" <?= $mine ? 'hidden' : '' ?> class="mt-3 flex flex-col items-center gap-2 rounded-lg border border-dashed border-graphite-900/15 px-4 py-8 text-sm text-steel">
            <?= icon($ic, 'size-7 text-graphite-300') ?><?= e($empty) ?></div>
    </section>
    <?php endforeach ?>
    <template data-line-template><?= $line('__i__', ['kind' => '', 'quantity' => 1]) ?></template>
    <p class="mt-4 text-right text-sm"><?= e(__('Total')) ?>: <span class="font-semibold tabular-nums" data-lines-total>0.00</span></p>
</div>
<?php if ($err): ?><p class="error"><?= e($err) ?></p><?php endif ?>
