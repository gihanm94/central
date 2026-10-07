<?php
/* $n note + rows, $choices (invoice no => row incl. on_note), $isAdmin */
$crumbs = [[__('Billing notes'), '/accounting/billing']];
$sym = \App\Modules\Accounting\Billing\BillingService::symbol($n['currency_code']);
$thai = old('lang') ? old('lang') === 'th' : filter_var($n['is_thai'], FILTER_VALIDATE_BOOL);
$picked = old('invoices');
$checked = fn ($no, $row) => is_array($picked) ? in_array((string) $no, array_map('strval', $picked), true) : $row['on_note'];
?>
<form method="POST" action="<?= url('/accounting/billing/'.$n['id']) ?>" class="mx-auto max-w-4xl pb-24" data-no-busy data-bill-edit novalidate><?= csrf_field() ?>
    <p class="text-sm text-steel"><a href="<?= url('/accounting/billing') ?>" class="hover:text-signal-700"><?= e(__('Billing notes')) ?></a> › <a href="<?= url('/accounting/billing/'.$n['id']) ?>" class="hover:text-signal-700"><?= e($n['billing_number']) ?></a></p>
    <h1 class="page-title"><?= e(__('Edit :n', ['n' => $n['billing_number']])) ?></h1>
    <p class="mt-1 text-sm text-steel"><?= e($n['customer_name']) ?> · <?= e($n['customer_code']) ?></p>

    <section class="panel mt-5 grid gap-x-6 gap-y-5 p-5 sm:grid-cols-2">
        <?php if ($isAdmin): ?><div><label class="label" for="f-no"><?= e(__('Billing number')) ?></label><input id="f-no" name="billing_number" value="<?= e(old('billing_number', $n['billing_number'])) ?>" maxlength="40" class="input"><p class="hint"><?= e(__('Administrators can change the number.')) ?></p></div><?php endif ?>
        <div><span class="label"><?= e(__('Language of the document')) ?></span>
            <div class="inline-flex rounded-lg bg-mist p-1 text-sm font-semibold" role="radiogroup"><?php foreach (['th' => 'TH', 'en' => 'EN'] as $k => $l): ?><label class="cursor-pointer"><input type="radio" name="lang" value="<?= $k ?>" class="peer sr-only" <?= ($thai ? 'th' : 'en') === $k ? 'checked' : '' ?>><span class="block rounded-md px-4 py-1.5 text-steel peer-checked:bg-white peer-checked:text-graphite-900 peer-checked:shadow-sm"><?= $l ?></span></label><?php endforeach ?></div></div>
        <div><label class="label" for="f-bd"><?= e(__('Billing date')) ?></label><input id="f-bd" type="date" name="billing_date" value="<?= e(old('billing_date', substr((string) $n['billing_at'], 0, 10))) ?>" class="input"></div>
        <div><label class="label" for="f-rd"><?= e(__('Remind date')) ?></label><input id="f-rd" type="date" name="remind_date" value="<?= e(old('remind_date', substr((string) $n['remind_date'], 0, 10))) ?>" class="input"></div>
        <div class="sm:col-span-2"><label class="label" for="f-addr"><?= e(__('Address')) ?></label><textarea id="f-addr" name="address" rows="3" maxlength="600" class="input"><?= e(old('address', $n['address'])) ?></textarea></div>
        <div class="sm:col-span-2"><label class="label" for="f-note"><?= e(__('Note')) ?></label><textarea id="f-note" name="note" rows="2" maxlength="1000" class="input"><?= e(old('note', $n['note'])) ?></textarea></div>
    </section>

    <section class="panel mt-5 overflow-hidden">
        <div class="flex items-center justify-between border-b border-graphite-900/6 bg-mist/50 px-5 py-2"><h2 class="text-xs font-semibold text-steel"><?= e(__('Invoices on this note')) ?></h2><span class="text-xs text-steel"><?= e(__('Untick to take one off, tick to add one.')) ?></span></div>
        <?php if ($err = error_for('invoices')): ?><p class="px-5 py-2 text-sm text-red-700"><?= e($err) ?></p><?php endif ?>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm">
            <thead class="text-xs text-steel"><tr><th class="w-10 px-5 py-2"></th><th class="px-3 py-2 font-medium"><?= e(__('Invoice')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Order')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Invoice date')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Due date')) ?></th><th class="px-5 py-2 text-right font-medium"><?= e(__('Amount')) ?></th></tr></thead>
            <tbody class="divide-y divide-graphite-900/6">
            <?php foreach ($choices as $no => $r): ?>
                <tr class="<?= $r['on_note'] ? '' : 'bg-sky-50/40' ?> hover:bg-mist/40"><td class="px-5 py-2.5"><input type="checkbox" name="invoices[]" value="<?= (int) $no ?>" data-amount="<?= e(($r['is_credit'] ? -1 : 1) * $r['amount']) ?>" class="size-4 accent-signal-600" <?= $checked($no, $r) ? 'checked' : '' ?> aria-label="<?= e(__('Invoice :n', ['n' => $no])) ?>"></td>
                    <td class="px-3 py-2.5 font-medium tabular-nums"><?= (int) $no ?><?= $r['is_credit'] ? ' <span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-800">CN</span>' : '' ?><?= ! $r['on_note'] ? ' <span class="ml-1 rounded bg-sky-100 px-1.5 py-0.5 text-[11px] text-sky-800">'.e(__('not billed yet')).'</span>' : '' ?></td>
                    <td class="px-3 py-2.5"><?= e($r['order_no']) ?></td><td class="px-3 py-2.5 tabular-nums text-steel"><?= e($r['invoice_date'] ? format_date($r['invoice_date']) : '—') ?></td><td class="px-3 py-2.5 tabular-nums text-steel"><?= e($r['due_date'] ? format_date($r['due_date']) : '—') ?></td>
                    <td class="px-5 py-2.5 text-right tabular-nums"><?= $r['is_credit'] ? '-' : '' ?><?= e(number_format($r['amount'], 2)) ?></td></tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
    </section>

    <div class="fixed inset-x-0 bottom-0 z-30 border-t border-graphite-900/10 bg-white/95 px-4 py-3 backdrop-blur lg:left-64">
        <div class="mx-auto flex max-w-4xl items-center gap-3">
            <a href="<?= url('/accounting/billing/'.$n['id']) ?>" class="btn-secondary"><?= e(__('Cancel')) ?></a>
            <p class="ml-auto text-sm text-steel"><span data-count>0</span> <?= e(__('invoices')) ?> · <?= e(__('Total')) ?> <strong class="text-graphite-900 tabular-nums"><?= e($sym) ?> <span data-total>0.00</span></strong></p>
            <button class="btn-primary"><?= icon('check', 'size-4') ?> <?= e(__('Save and build the PDF')) ?></button>
        </div>
    </div>
</form>
<script>
(function () {
    var f = document.querySelector('[data-bill-edit]'); if (!f) return;
    function sum() { var n = 0, t = 0; f.querySelectorAll('input[name="invoices[]"]:checked').forEach(function (c) { n++; t += parseFloat(c.dataset.amount) || 0; });
        f.querySelector('[data-count]').textContent = n; f.querySelector('[data-total]').textContent = t.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    f.addEventListener('change', sum); sum();
})();
</script>
