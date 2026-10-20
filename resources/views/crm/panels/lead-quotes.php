<?php /* Lead → Quotes tab. $row (lead), $erp (Accounting usable), $quotes ?list (connected), $choices (ERP customers to pick from), $canEdit */
$connected = ! empty($row['erp_customer_id']);
$base = url('/crm/leads/'.(int) $row['id']);
?>
<section class="panel overflow-hidden">
    <div class="panel-head">
        <h2 class="panel-title"><?= e(__('Quotes')) ?></h2>
        <?php if ($connected): ?>
            <span class="flex flex-wrap items-center gap-2 text-sm"><span class="inline-flex items-center gap-1.5 rounded-md bg-emerald-50 px-2 py-0.5 text-emerald-800"><?= icon('link', 'size-4') ?> <?= e($row['erp_customer_name'] ?: '#'.$row['erp_customer_id']) ?> <span class="text-xs text-emerald-700/80">#<?= e((string) $row['erp_customer_id']) ?></span></span>
                <?php if ($canEdit): ?><form method="POST" action="<?= $base ?>/erp-disconnect" data-confirm="<?= e(__('Disconnect this lead from the ERP customer?')) ?>"><?= csrf_field() ?><button class="btn-ghost h-8 px-2 text-xs"><?= e(__('Disconnect')) ?></button></form><?php endif ?></span>
        <?php endif ?>
    </div>

    <?php if (! $erp): ?>
        <p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('The ERP data is not available right now (the Accounting module is off or unavailable).')) ?></p>
    <?php elseif (! $connected): ?>
        <div class="px-5 py-8 text-center">
            <span class="mx-auto flex size-12 items-center justify-center rounded-full bg-mist text-steel"><?= icon('link', 'size-6') ?></span>
            <p class="mt-3 font-medium"><?= e(__('Not connected to an ERP customer yet')) ?></p>
            <p class="mt-1 text-sm text-steel"><?= e(__('Connect finds the ERP customer with tax ID :tax and keeps its customer ID on this lead, so its quotes show here.', ['tax' => $row['tax_id']])) ?></p>
            <?php if ($choices): ?>
                <p class="mt-5 text-sm font-medium"><?= e(__('Several ERP customers have this tax ID. Choose one:')) ?></p>
                <ul class="mx-auto mt-2 max-w-lg divide-y divide-graphite-900/6 rounded-lg text-left ring-1 ring-graphite-900/10">
                    <?php foreach ($choices as $c): ?>
                    <li class="flex items-center gap-3 px-4 py-2.5">
                        <div class="min-w-0 flex-1"><p class="truncate font-medium"><?= e($c['name']) ?></p>
                            <p class="truncate text-xs text-steel"><?= e(implode(' · ', array_filter([$c['code'], '#'.$c['id'], __(':n invoices', ['n' => $c['invoices']]), __(':n quotes', ['n' => $c['quotes']])]))) ?></p></div>
                        <?php if ($canEdit): ?><form method="POST" action="<?= $base ?>/erp-connect"><?= csrf_field() ?><input type="hidden" name="customer_id" value="<?= e($c['id']) ?>"><button class="btn-secondary h-8 px-3 text-xs"><?= e(__('Connect')) ?></button></form><?php endif ?>
                    </li>
                    <?php endforeach ?>
                </ul>
            <?php elseif ($canEdit): ?>
                <form method="POST" action="<?= $base ?>/erp-connect" class="mt-4"><?= csrf_field() ?><button class="btn-primary"><?= icon('link', 'size-4') ?> <?= e(__('Connect')) ?></button></form>
            <?php else: ?>
                <p class="mt-3 text-xs text-steel"><?= e(__('Someone who can edit this lead can connect it.')) ?></p>
            <?php endif ?>
        </div>
    <?php elseif (! $quotes): ?>
        <p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('This ERP customer has no quotes.')) ?></p>
    <?php else: ?>
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead class="bg-mist/60 text-left text-xs text-steel"><tr>
            <th class="px-5 py-2 font-medium"><?= e(__('Quote no.')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Seller')) ?></th>
            <th class="px-3 py-2 font-medium"><?= e(__('Date')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Valid until')) ?></th>
            <th class="px-5 py-2 text-right font-medium"><?= e(__('Amount')) ?></th></tr></thead>
        <tbody class="divide-y divide-graphite-900/6">
        <?php foreach ($quotes as $q): $expired = $q['valid_through_date'] && strtotime((string) $q['valid_through_date']) < strtotime('today'); ?>
            <tr>
                <td class="max-w-[18rem] px-5 py-2.5"><span class="font-medium tabular-nums"><?= e($q['order_number'] ?: '#'.$q['id']) ?></span>
                    <?php if ($q['quote_description']): ?><span class="block truncate text-xs text-steel" title="<?= e($q['quote_description']) ?>"><?= e($q['quote_description']) ?></span><?php endif ?></td>
                <td class="px-3 py-2.5"><?= e($q['seller'] ?: ($q['our_reference_name'] ?: '—')) ?></td>
                <td class="whitespace-nowrap px-3 py-2.5 text-steel"><?= $q['quote_date'] ? e(format_date($q['quote_date'])) : '—' ?></td>
                <td class="whitespace-nowrap px-3 py-2.5"><?php if ($q['valid_through_date']): ?><span class="<?= $expired ? 'text-signal-700' : 'text-steel' ?>"><?= e(format_date($q['valid_through_date'])) ?></span><?php if ($expired): ?> <span class="badge bg-signal-50 text-signal-800"><?= e(__('Expired')) ?></span><?php endif ?><?php else: ?>—<?php endif ?></td>
                <td class="whitespace-nowrap px-5 py-2.5 text-right tabular-nums"><?= $q['amount'] !== null ? e(number_format((float) $q['amount'], 2)) : '—' ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <p class="border-t border-graphite-900/8 px-5 py-2 text-xs text-steel"><?= e(__('Connected :date. Amounts before VAT, from the ERP.', ['date' => $row['erp_connected_at'] ? format_date($row['erp_connected_at'], 'd M Y') : ''])) ?></p>
    <?php endif ?>
</section>
