<?php
/* $n note + rows, $status, $canEdit, $canDelete, $by */
$crumbs = [[__('Billing notes'), '/accounting/billing']];
$sym  = \App\Modules\Accounting\Billing\BillingService::symbol($n['currency_code']);
$tone = ['pending' => 'neutral', 'overdue' => 'danger', 'paid' => 'info', 'complete' => 'success'];
$money = fn ($v) => number_format((float) $v, 2);
$thai = filter_var($n['is_thai'], FILTER_VALIDATE_BOOL);
$b = '/accounting/billing/'.$n['id'];
?>
<div class="flex flex-wrap items-start justify-between gap-4">
    <div class="min-w-0">
        <p class="text-sm text-steel"><a href="<?= url('/accounting/billing') ?>" class="hover:text-signal-700"><?= e(__('Billing notes')) ?></a> ›</p>
        <h1 class="page-title"><?= e($n['billing_number']) ?></h1>
        <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-steel"><?= \App\Modules\CRM\Support\Ui::badge(__(ucfirst($status)), $tone[$status] ?? 'neutral') ?> <span><?= e($thai ? 'ไทย' : 'English') ?></span> · <span><?= e(__(':n invoices', ['n' => count($n['rows'])])) ?></span></p>
    </div>
    <div class="flex w-full flex-wrap gap-2 sm:w-auto">
        <a href="<?= url($b.'/pdf') ?>" data-bill-view data-title="<?= e($n['billing_number']) ?>" class="btn-dark flex-1 sm:flex-none"><?= icon('eye', 'size-4') ?> <?= e(__('View PDF')) ?></a>
        <a href="<?= url($b.'/pdf', ['download' => 1]) ?>" class="btn-secondary flex-1 sm:flex-none"><?= icon('download', 'size-4') ?> <?= e(__('Download')) ?></a>
        <?php if ($canEdit): ?><a href="<?= url($b.'/edit') ?>" class="btn-primary flex-1 sm:flex-none"><?= icon('pencil', 'size-4') ?> <?= e(__('Edit')) ?></a><?php endif ?>
    </div>
</div>

<div class="mt-5 grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
    <section class="panel overflow-hidden">
        <h2 class="border-b border-graphite-900/6 bg-mist/50 px-5 py-2 text-xs font-semibold text-steel"><?= e(__('Invoices')) ?></h2>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm">
            <thead class="text-xs text-steel"><tr><th class="px-5 py-2 font-medium"><?= e(__('Invoice')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Order')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Invoice date')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Due date')) ?></th><th class="px-5 py-2 text-right font-medium"><?= e(__('Amount')) ?></th></tr></thead>
            <tbody class="divide-y divide-graphite-900/6">
            <?php foreach ($n['rows'] as $r): $cr = filter_var($r['is_credit'], FILTER_VALIDATE_BOOL); ?>
                <tr><td class="px-5 py-2.5 font-medium tabular-nums"><?= e($r['invoice_number']) ?><?= $cr ? ' <span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-800">CN</span>' : '' ?></td><td class="px-3 py-2.5"><?= e($r['order_no']) ?></td>
                    <td class="px-3 py-2.5 tabular-nums text-steel"><?= e($r['invoice_date'] ? format_date($r['invoice_date']) : '—') ?></td><td class="px-3 py-2.5 tabular-nums text-steel"><?= e($r['due_date'] ? format_date($r['due_date']) : '—') ?></td>
                    <td class="px-5 py-2.5 text-right tabular-nums"><?= $cr ? '-' : '' ?><?= e($money($r['amount'])) ?></td></tr>
            <?php endforeach ?>
            </tbody>
            <tfoot><tr class="border-t border-graphite-900/10"><td colspan="4" class="px-5 py-3 text-right font-semibold"><?= e(__('Total')) ?></td><td class="px-5 py-3 text-right text-base font-semibold tabular-nums"><?= e($sym.' '.$money($n['total_amount'])) ?></td></tr></tfoot>
        </table></div>
    </section>

    <aside class="space-y-4">
        <section class="panel p-5 text-sm">
            <p class="text-xs text-steel"><?= e(__('Customer')) ?></p><p class="font-semibold"><?= e($n['customer_name']) ?></p><p class="text-xs text-steel"><?= e($n['customer_code']) ?><?= $n['vat_number'] ? ' · '.e($n['vat_number']) : '' ?></p>
            <p class="mt-4 text-xs text-steel"><?= e(__('Address')) ?></p><p class="whitespace-pre-line"><?= e($n['address'] ?: '—') ?></p>
            <dl class="mt-4 grid grid-cols-2 gap-3"><div><dt class="text-xs text-steel"><?= e(__('Billing date')) ?></dt><dd class="font-medium"><?= e(format_date($n['billing_at'])) ?></dd></div><div><dt class="text-xs text-steel"><?= e(__('Remind date')) ?></dt><dd class="font-medium"><?= e($n['remind_date'] ? format_date($n['remind_date']) : '—') ?></dd></div></dl>
            <?php if ($n['note'] || $n['remark']): ?><p class="mt-4 text-xs text-steel"><?= e(__('Note')) ?></p><p class="whitespace-pre-line"><?= e(trim($n['note']."\n".$n['remark'])) ?></p><?php endif ?>
            <p class="mt-4 text-xs text-steel"><?= e(__('Created by :n', ['n' => $by[(int) $n['created_by']] ?? '—'])) ?> · <?= e(format_date($n['created_at'], 'd M Y H:i')) ?><?= $n['updated_by'] && $n['updated_at'] !== $n['created_at'] ? '<br>'.e(__('Last changed by :n', ['n' => $by[(int) $n['updated_by']] ?? '—'])).' · '.e(format_date($n['updated_at'], 'd M Y H:i')) : '' ?></p>
        </section>
        <?php if ($canEdit): ?>
        <section class="panel flex flex-wrap gap-2 p-4">
            <?php if (! filter_var($n['is_paid'], FILTER_VALIDATE_BOOL)): ?><form method="POST" action="<?= url($b.'/state/paid') ?>" class="flex-1"><?= csrf_field() ?><button class="btn-secondary w-full"><?= icon('check', 'size-4') ?> <?= e(__('Mark as paid')) ?></button></form><?php endif ?>
            <?php if (! filter_var($n['is_complete'], FILTER_VALIDATE_BOOL)): ?><form method="POST" action="<?= url($b.'/state/complete') ?>" class="flex-1"><?= csrf_field() ?><button class="btn-secondary w-full"><?= icon('check', 'size-4') ?> <?= e(__('Mark as complete')) ?></button></form><?php endif ?>
            <form method="POST" action="<?= url($b.'/regenerate') ?>" class="w-full"><?= csrf_field() ?><button class="btn-ghost w-full"><?= icon('bolt', 'size-4') ?> <?= e(__('Build the PDF again')) ?></button></form>
        </section>
        <?php endif ?>
        <?php if ($canDelete): ?><button type="button" class="btn-danger w-full" data-delete-url="<?= e(url($b.'/delete')) ?>" data-delete-name="<?= e($n['billing_number']) ?>" data-delete-kind="<?= e(__('billing note')) ?>"><?= icon('trash', 'size-4') ?> <?= e(__('Delete')) ?></button><?php endif ?>
    </aside>
</div>
<?= partial('accounting/billing-viewer') ?>
<script src="<?= asset('assets/billing.js') ?>" defer></script>
