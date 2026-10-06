<?php
/* Repeating product rows: pick a registered product, or type a product that is not in the system. $name, $f (catalog), $row, $err */
$catalog = $f['catalog'];                                   // id => [id, name, unit_price]
$opts = array_map(fn ($p) => $p['name'], $catalog);
$prices = array_map(fn ($p) => $p['unit_price'], $catalog);
$rows = old('products');
if (! is_array($rows)) { $rows = array_map(fn ($p) => ['product_id' => $p['product_id'], 'name' => $p['name'], 'quantity' => $p['quantity'], 'unit_price' => $p['unit_price'], 'note' => $p['note']], $row['product_list'] ?? []); }
if (! $rows) { $rows = [['product_id' => '', 'name' => '', 'quantity' => 1, 'unit_price' => '']]; }
$rowHtml = function ($i, array $p) use ($opts) { ob_start();
    $pid = (string) ($p['product_id'] ?? ''); $registered = $pid !== '' && isset($opts[(int) $pid]); ?>
    <div data-repeat-row class="grid grid-cols-12 items-start gap-2 rounded-md bg-mist/50 p-3">
        <div class="col-span-12 sm:col-span-5" data-product-row>
            <?= select_field('products['.$i.'][product_id]', $opts, $registered ? $pid : '', ['placeholder' => __('Other product (not in the system)'), 'id' => 'prod-'.$i, 'aria' => __('Product')]) ?>
            <input name="products[<?= e($i) ?>][name]" value="<?= $registered ? '' : e($p['name'] ?? '') ?>" maxlength="200" placeholder="<?= e(__('Type the product name')) ?>" aria-label="<?= e(__('Product name')) ?>" data-product-name class="input mt-2" <?= $registered ? 'hidden' : '' ?>>
        </div>
        <div class="col-span-4 sm:col-span-2"><input name="products[<?= e($i) ?>][quantity]" value="<?= e(isset($p['quantity']) ? number_clean($p['quantity']) : '1') ?>" inputmode="decimal" placeholder="<?= e(__('Qty')) ?>" aria-label="<?= e(__('Quantity')) ?>" class="input"></div>
        <div class="col-span-8 sm:col-span-3"><input name="products[<?= e($i) ?>][unit_price]" value="<?= isset($p['unit_price']) && $p['unit_price'] !== '' && $p['unit_price'] !== null ? e(str_replace(',', '', number_clean($p['unit_price']))) : '' ?>" data-product-price inputmode="decimal" placeholder="<?= e(__('Unit price')) ?>" aria-label="<?= e(__('Unit price')) ?>" class="input"></div>
        <div class="col-span-12 flex justify-end sm:col-span-2"><button type="button" data-repeat-remove class="btn-ghost px-2 py-1.5 hover:text-signal-700" aria-label="<?= e(__('Remove this product')) ?>"><?= icon('trash', 'size-4') ?> <span class="sm:sr-only"><?= e(__('Remove')) ?></span></button></div>
    </div>
<?php return ob_get_clean(); };
?>
<div data-repeat data-min="0" data-prices="<?= e(json_encode($prices)) ?>" class="space-y-2">
    <div data-repeat-rows class="space-y-2">
        <?php $next = 0; foreach (array_values($rows) as $i => $p) { echo $rowHtml($i, $p); $next = $i + 1; } ?>
    </div>
    <template data-repeat-template><?= $rowHtml('__i__', ['quantity' => 1]) ?></template>
    <input type="hidden" data-repeat-next value="<?= $next ?>">
    <button type="button" data-repeat-add class="btn-secondary py-1.5"><?= icon('plus', 'size-4') ?> <?= e(__('Add product')) ?></button>
    <p class="hint"><?= e(__('Choose a registered product, or leave the product empty and type a name for something that is not in the system yet.')) ?></p>
</div>
<?php if ($err): ?><p class="error"><?= e($err) ?></p><?php endif ?>
