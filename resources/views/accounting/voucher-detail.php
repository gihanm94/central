<?php
use App\Core\Support\Ui;
/* $voucher (erp_vouchers row + comment), $rows (erp_voucher_rows), $payable ?array (erp_payables) — below the voucher's fields */
$raw = fn (?string $json) => $json ? (json_decode($json, true) ?: []) : [];
// a row's account and text are read from what the ERP sent (the first of these keys that has a value)
$pick = function (array $r, array $keys) { foreach ($keys as $k) { if (isset($r[$k]) && $r[$k] !== '' && $r[$k] !== null) { return (string) $r[$k]; } } return ''; };
$num = fn ($v) => $v === null || $v === '' ? '<span class="text-graphite-400">—</span>' : e(number_format((float) $v, 2));
$sum = fn (string $k) => array_sum(array_map(fn ($r) => (float) ($r[$k] ?? 0), $rows));
$balanced = abs($sum('debit_in_company_currency') - $sum('credit_in_company_currency')) < 0.005;
$p = $payable ? $raw($payable['raw'] ?? null) : [];
?>
<div class="grid items-start gap-4 xl:grid-cols-3">
    <section class="panel overflow-hidden xl:col-span-2" aria-labelledby="v-rows">
        <div class="panel-head flex-wrap gap-2">
            <h2 class="panel-title" id="v-rows"><?= e(__('Voucher rows')) ?> <span class="ml-1 text-sm font-normal text-steel"><?= count($rows) ?></span></h2>
            <?php if ($rows): ?><?= $balanced ? Ui::badge(__('Balanced'), 'success') : Ui::badge(__('Debit and credit differ'), 'warn') ?><?php endif ?>
        </div>
        <?php if (! $rows): ?>
            <p class="px-5 py-10 text-center text-sm text-steel"><?= e(__('No rows saved for this voucher yet. Sync it again from the ERP.')) ?></p>
        <?php else: ?>
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <thead class="bg-mist text-left text-xs text-steel"><tr>
                <th class="px-5 py-2 font-medium">#</th>
                <th class="px-3 py-2 font-medium"><?= e(__('Account')) ?></th>
                <th class="px-3 py-2 font-medium"><?= e(__('Text')) ?></th>
                <th class="px-3 py-2 text-right font-medium"><?= e(__('Debit')) ?></th>
                <th class="px-3 py-2 text-right font-medium"><?= e(__('Credit')) ?></th>
                <th class="px-3 py-2 text-right font-medium"><?= e(__('Debit (THB)')) ?></th>
                <th class="px-3 py-2 text-right font-medium"><?= e(__('Credit (THB)')) ?></th>
                <th class="px-5 py-2 text-right font-medium"><?= e(__('Quantity')) ?></th>
            </tr></thead>
            <tbody class="divide-y divide-graphite-900/6">
            <?php foreach ($rows as $i => $r): $x = $raw($r['raw'] ?? null); ?>
                <tr>
                    <td class="px-5 py-2 tabular-nums text-steel"><?= e($r['entry_identifier'] ?: (string) ($i + 1)) ?></td>
                    <td class="whitespace-nowrap px-3 py-2 tabular-nums"><?= e($pick($x, ['AccountNumber', 'Account', 'AccountCode', 'AccountId']) ?: '—') ?></td>
                    <td class="px-3 py-2"><span class="block max-w-xs truncate" title="<?= e($pick($x, ['Text', 'Description', 'RowText'])) ?>"><?= e($pick($x, ['Text', 'Description', 'RowText']) ?: '—') ?></span></td>
                    <td class="px-3 py-2 text-right tabular-nums"><?= $num($r['debit']) ?></td>
                    <td class="px-3 py-2 text-right tabular-nums"><?= $num($r['credit']) ?></td>
                    <td class="px-3 py-2 text-right tabular-nums"><?= $num($r['debit_in_company_currency']) ?></td>
                    <td class="px-3 py-2 text-right tabular-nums"><?= $num($r['credit_in_company_currency']) ?></td>
                    <td class="px-5 py-2 text-right tabular-nums text-steel"><?= $r['quantity'] !== null && (float) $r['quantity'] != 0.0 ? e(number_clean($r['quantity'])) : '' ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
            <tfoot class="border-t border-graphite-900/10 bg-mist/50 font-medium"><tr>
                <td class="px-5 py-2" colspan="5"><?= e(__('Total')) ?></td>
                <td class="px-3 py-2 text-right tabular-nums"><?= $num($sum('debit_in_company_currency')) ?></td>
                <td class="px-3 py-2 text-right tabular-nums"><?= $num($sum('credit_in_company_currency')) ?></td>
                <td></td>
            </tr></tfoot>
        </table></div>
        <?php endif ?>
    </section>

    <div class="space-y-4">
        <section class="panel p-5" aria-labelledby="v-payable">
            <h2 class="panel-title" id="v-payable"><?= e(__('Accounts payable')) ?></h2>
            <?php if (! $payable): ?>
                <p class="mt-2 text-sm text-steel"><?= e($voucher['accounts_payable_id'] ? __('Payable #:id is not saved yet. Sync the voucher again.', ['id' => $voucher['accounts_payable_id']]) : __('This voucher has no accounts payable invoice.')) ?></p>
            <?php else:
                $lines = [
                    __('Supplier invoice') => $payable['suppliers_invoice_number'],
                    __('Invoice number')   => $payable['invoice_number'],
                    __('Supplier')         => $pick($p, ['SupplierName', 'SupplierCode', 'SupplierId']),
                    __('Invoice date')     => $payable['invoice_date'] ? format_date($payable['invoice_date']) : null,
                    __('Due date')         => $payable['due_date'] ? format_date($payable['due_date']) : null,
                    __('Amount')           => $payable['invoice_amount'] !== null ? number_format((float) $payable['invoice_amount'], 2) : null,
                    __('Amount (THB)')     => $payable['invoice_amount_in_company_currency'] !== null ? number_format((float) $payable['invoice_amount_in_company_currency'], 2) : null,
                    __('VAT')              => $payable['vat_amount'] !== null ? number_format((float) $payable['vat_amount'], 2) : null,
                    __('Still to pay')     => $payable['rest_amount'] !== null ? number_format((float) $payable['rest_amount'], 2) : null,
                    __('Paid in full')     => $payable['paid_in_full_date'] ? format_date($payable['paid_in_full_date']) : null,
                    __('Reference')        => $payable['reference_number'],
                ]; ?>
                <dl class="mt-3 space-y-2 text-sm">
                    <?php foreach ($lines as $label => $v): if ($v === null || $v === '') { continue; } ?>
                        <div class="flex justify-between gap-4"><dt class="text-steel"><?= e($label) ?></dt><dd class="text-right font-medium tabular-nums"><?= e((string) $v) ?></dd></div>
                    <?php endforeach ?>
                </dl>
                <?php if (filter_var($payable['is_credit'], FILTER_VALIDATE_BOOL)): ?><p class="mt-3"><?= Ui::badge(__('Credit note'), 'warn') ?></p><?php endif ?>
            <?php endif ?>
        </section>

        <section class="panel p-5" aria-labelledby="v-comment">
            <h2 class="panel-title" id="v-comment"><?= e(__('Comment')) ?></h2>
            <?php if (trim((string) ($voucher['comment'] ?? '')) === ''): ?>
                <p class="mt-2 text-sm text-steel"><?= e(__('No comment.')) ?></p>
            <?php else: ?>
                <p class="mt-2 whitespace-pre-line break-words text-sm"><?= e((string) $voucher['comment']) ?></p>
            <?php endif ?>
        </section>

        <?php if ($all = $raw($voucher['raw'] ?? null)): ?>
        <details class="panel p-5">
            <summary class="cursor-pointer text-sm font-medium"><?= e(__('All fields from the ERP')) ?></summary>
            <dl class="mt-3 space-y-1.5 text-xs">
                <?php foreach ($all as $k => $v): ?>
                    <div class="flex justify-between gap-4"><dt class="text-steel"><?= e((string) $k) ?></dt><dd class="break-all text-right tabular-nums"><?= e($v === null ? '—' : (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v)) ?></dd></div>
                <?php endforeach ?>
            </dl>
        </details>
        <?php endif ?>
    </div>
</div>
