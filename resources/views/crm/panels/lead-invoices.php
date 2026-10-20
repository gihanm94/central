<?php /* Lead → Invoices tab: ERP invoices with the lead's tax ID. $row (lead), $data ?{rows, total, sum} from Accounting (null = unavailable) */ ?>
<section class="panel overflow-hidden">
    <div class="panel-head">
        <h2 class="panel-title"><?= e(__('Invoices')) ?> <span class="ml-1 text-sm font-normal text-steel"><?= e(__('tax ID :tax', ['tax' => $row['tax_id']])) ?></span></h2>
        <?php if ($data && $data['total']): ?><span class="text-sm tabular-nums"><?= e(__(':n invoices · :v', ['n' => $data['total'], 'v' => number_format($data['sum'], 2)])) ?></span><?php endif ?>
    </div>
    <?php if ($data === null): ?>
        <p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('The ERP data is not available right now (the Accounting module is off or unavailable).')) ?></p>
    <?php elseif (! $data['rows']): ?>
        <p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('No ERP invoices with this tax ID.')) ?></p>
    <?php else: ?>
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead class="bg-mist/60 text-left text-xs text-steel"><tr>
            <th class="px-5 py-2 font-medium"><?= e(__('Invoice no.')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Seller')) ?></th>
            <th class="px-3 py-2 font-medium"><?= e(__('Date')) ?></th><th class="px-5 py-2 text-right font-medium"><?= e(__('Amount')) ?></th></tr></thead>
        <tbody class="divide-y divide-graphite-900/6">
        <?php foreach ($data['rows'] as $r): $credit = filter_var($r['is_credit'], FILTER_VALIDATE_BOOL); ?>
            <tr>
                <td class="px-5 py-2.5"><span class="font-medium tabular-nums"><?= e((string) $r['invoice_number']) ?></span><?php if ($credit): ?> <span class="badge bg-amber-50 text-amber-800">CN</span><?php endif ?>
                    <span class="block text-xs text-steel"><?= e(trim(($r['order_number'] ?: '').($r['customer_name'] ? ' · '.$r['customer_name'] : ''), ' ·')) ?></span></td>
                <td class="px-3 py-2.5"><?= e($r['seller'] ?: '—') ?></td>
                <td class="whitespace-nowrap px-3 py-2.5 text-steel"><?= $r['invoice_date'] ? e(format_date($r['invoice_date'])) : '—' ?></td>
                <td class="whitespace-nowrap px-5 py-2.5 text-right tabular-nums <?= $credit ? 'text-signal-700' : '' ?>"><?= e(($credit ? '−' : '').number_format((float) $r['amount'], 2).' '.($r['currency_code'] ?: '')) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php if ($data['total'] > count($data['rows'])): ?><p class="border-t border-graphite-900/8 px-5 py-2 text-xs text-steel"><?= e(__('Showing the newest :n of :t.', ['n' => count($data['rows']), 't' => $data['total']])) ?></p><?php endif ?>
    <p class="border-t border-graphite-900/8 px-5 py-2 text-xs text-steel"><?= e(__('Amounts before VAT, from the ERP. Credit notes are shown as minus.')) ?></p>
    <?php endif ?>
</section>
