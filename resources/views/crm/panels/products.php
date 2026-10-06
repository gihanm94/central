<?php /* $row, $items (opportunity_products) */
$cur = $row['currency']; $total = 0.0; $complete = true;
foreach ($items as $it) { if ($it['unit_price'] === null) { $complete = false; } else { $total += (float) $it['quantity'] * (float) $it['unit_price']; } }
?>
<section class="panel overflow-hidden">
    <div class="panel-head"><h2 class="panel-title"><?= e(__('Products')) ?> <span class="ml-1 text-sm font-normal text-steel"><?= count($items) ?></span></h2></div>
    <?php if (! $items): ?>
        <p class="px-5 py-6 text-center text-sm text-steel"><?= e(__('No products added. Edit the opportunity to add products.')) ?></p>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="table">
            <thead class="bg-mist/60"><tr><th><?= e(__('Product')) ?></th><th class="text-right"><?= e(__('Qty')) ?></th><th class="text-right"><?= e(__('Unit price')) ?></th><th class="text-right"><?= e(__('Total')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr>
                    <td><span class="font-medium"><?= e($it['name']) ?></span>
                        <?php if (! $it['product_id']): ?><span class="badge ml-1 bg-amber-50 text-amber-800"><?= e(__('Not registered')) ?></span><?php endif ?>
                        <?php if ($it['note']): ?><span class="block text-xs text-steel"><?= e($it['note']) ?></span><?php endif ?></td>
                    <td class="text-right tabular-nums"><?= e(number_clean($it['quantity'])) ?></td>
                    <td class="text-right tabular-nums"><?= $it['unit_price'] !== null ? e(number_format((float) $it['unit_price'], 2)) : '—' ?></td>
                    <td class="text-right tabular-nums"><?= $it['unit_price'] !== null ? e(number_format((float) $it['quantity'] * (float) $it['unit_price'], 2)) : '—' ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
            <tfoot><tr class="bg-mist/40"><td colspan="3" class="px-5 py-3 text-right text-xs font-medium text-steel"><?= e($complete ? __('Total') : __('Total of priced lines')) ?> (<?= e($cur) ?>)</td><td class="border-t border-graphite-900/6 px-5 py-3 text-right font-semibold tabular-nums"><?= e(number_format($total, 2)) ?></td></tr></tfoot>
        </table>
    </div>
    <?php endif ?>
</section>
