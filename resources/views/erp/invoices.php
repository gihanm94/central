<?php /* $f {q,from,to,department,page}, $result {rows,total,page,pages,all_departments}|null, $deps [id => name], $myDept, $isAdmin */
$docLabel = ['388' => __('Tax invoice'), 't01' => __('Receipt'), '81' => __('Credit note')];
$link = fn (array $extra = []) => url('/erp/invoices', array_filter(['q' => $f['q'], 'from' => $f['from'], 'to' => $f['to'], 'department' => $f['department'] ?: null] + $extra));
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0"><h1 class="page-title"><?= e(__('Invoices')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e($isAdmin
            ? __('Tax invoices, receipts and credit notes stamped by INET, for every department.')
            : __('Tax invoices, receipts and credit notes stamped by INET for your department: :d.', ['d' => $myDept ?? __('none')])) ?></p></div>
</div>

<?php if ($result === null): ?>
    <section class="panel mt-4 px-5 py-10 text-center text-sm text-steel"><?= e(__('Invoices are temporarily unavailable. Everything else keeps working.')) ?></section>
<?php elseif (! $isAdmin && ! $myDept): ?>
    <section class="panel mt-4 px-5 py-10 text-center text-sm text-steel"><?= e(__('Your account has no department yet, so there are no invoices to show. Ask an administrator.')) ?></section>
<?php else: ?>
<section class="panel mt-4 overflow-hidden">
    <form method="GET" action="<?= url('/erp/invoices') ?>" class="flex flex-wrap items-end gap-2 border-b border-graphite-900/8 p-3">
        <div class="min-w-[14rem] flex-1"><label class="label" for="f-q"><?= e(__('Search')) ?></label><input id="f-q" name="q" value="<?= e($f['q']) ?>" class="input" placeholder="<?= e(__('Invoice no., customer, order no.')) ?>"></div>
        <div><label class="label" for="f-from"><?= e(__('From')) ?></label><input id="f-from" type="date" name="from" value="<?= e($f['from']) ?>" class="input"></div>
        <div><label class="label" for="f-to"><?= e(__('To')) ?></label><input id="f-to" type="date" name="to" value="<?= e($f['to']) ?>" class="input"></div>
        <?php if ($isAdmin): ?>
            <div><label class="label" for="f-dep"><?= e(__('Department')) ?></label>
                <select id="f-dep" name="department" class="input"><option value=""><?= e(__('All departments')) ?></option>
                    <?php foreach ($deps as $id => $name): ?><option value="<?= (int) $id ?>" <?= (int) $f['department'] === (int) $id ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach ?>
                </select></div>
        <?php endif ?>
        <button class="btn-secondary"><?= e(__('Show')) ?></button>
        <span class="ml-auto self-center text-sm text-steel"><?= (int) $result['total'] ?> <?= e(__('invoices')) ?></span>
    </form>
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead class="bg-mist/60 text-left text-xs text-steel"><tr>
            <th class="px-4 py-2 font-medium"><?= e(__('Invoice')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Date')) ?></th>
            <th class="px-3 py-2 font-medium"><?= e(__('Customer')) ?></th>
            <?php if ($result['all_departments']): ?><th class="px-3 py-2 font-medium"><?= e(__('Department')) ?></th><?php endif ?>
            <th class="px-3 py-2 text-right font-medium"><?= e(__('Total')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Stamped PDF')) ?></th></tr></thead>
        <tbody class="divide-y divide-graphite-900/6">
        <?php foreach ($result['rows'] as $r): ?>
            <tr>
                <td class="px-4 py-2.5"><span class="font-medium tabular-nums"><?= e($r['no_invoice']) ?></span><?php if (filter_var($r['is_credit'], FILTER_VALIDATE_BOOL)): ?> <span class="badge bg-amber-50 text-amber-800">CN</span><?php endif ?>
                    <span class="block text-xs text-steel"><?= e($r['customer_order_number'] ?: '') ?></span></td>
                <td class="whitespace-nowrap px-3 py-2.5 text-steel"><?= e(format_date($r['invoice_date'])) ?></td>
                <td class="px-3 py-2.5"><?= e($r['customer_name'] ?: '—') ?><span class="block text-xs text-steel"><?= e($r['customer_code'] ?: '') ?></span></td>
                <?php if ($result['all_departments']): ?><td class="px-3 py-2.5 text-steel"><?= e($deps[(int) $r['app_department_id']] ?? '—') ?></td><?php endif ?>
                <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums"><?= e(number_format((float) $r['total_amount'], 2).' '.($r['currency_code'] ?: '')) ?></td>
                <td class="px-3 py-2.5"><div class="flex flex-wrap gap-1.5">
                    <?php foreach ($r['documents'] as $d): $u = url('/erp/invoices/'.(int) $r['id'].'/pdf/'.$d); ?>
                        <span class="inline-flex overflow-hidden rounded-md ring-1 ring-graphite-900/10">
                            <a href="<?= e($u) ?>" target="_blank" rel="noopener" class="px-2 py-1 text-xs font-medium hover:bg-mist"><?= e($docLabel[$d]) ?></a>
                            <a href="<?= e($u.'?download=1') ?>" class="border-l border-graphite-900/10 px-1.5 py-1 hover:bg-mist" title="<?= e(__('Download')) ?>" aria-label="<?= e(__('Download :d', ['d' => $docLabel[$d]])) ?>"><?= icon('download', 'size-3.5') ?></a>
                        </span>
                    <?php endforeach ?>
                </div></td>
            </tr>
        <?php endforeach ?>
        <?php if (! $result['rows']): ?><tr><td colspan="6" class="px-4 py-10 text-center text-steel"><?= e(__('No stamped invoices found.')) ?></td></tr><?php endif ?>
        </tbody>
    </table></div>
    <?php if ($result['pages'] > 1): ?>
        <div class="flex items-center justify-end gap-2 border-t border-graphite-900/8 px-4 py-2 text-sm">
            <a class="btn-secondary !h-8 !px-3 <?= $result['page'] <= 1 ? 'pointer-events-none opacity-40' : '' ?>" href="<?= e($link(['page' => $result['page'] - 1])) ?>">‹</a>
            <span><?= (int) $result['page'] ?> / <?= (int) $result['pages'] ?></span>
            <a class="btn-secondary !h-8 !px-3 <?= $result['page'] >= $result['pages'] ? 'pointer-events-none opacity-40' : '' ?>" href="<?= e($link(['page' => $result['page'] + 1])) ?>">›</a>
        </div>
    <?php endif ?>
</section>
<?php endif ?>
