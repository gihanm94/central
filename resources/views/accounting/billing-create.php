<?php /* $isAdmin, $notifyTime. All behaviour in public/assets/billing.js */ ?>
<div id="bill-page" data-customers="<?= e(url('/accounting/billing/customers')) ?>" data-invoices="<?= e(url('/accounting/billing/invoices')) ?>" data-store="<?= e(url('/accounting/billing')) ?>"
     data-l-inv="<?= e(__('inv')) ?>" data-l-items="<?= e(__('items')) ?>" data-l-working="<?= e(__('Generating…')) ?>" data-l-done="<?= e(__('Billing note created')) ?>" data-l-pick="<?= e(__('Select a customer')) ?>" data-l-none="<?= e(__('No invoices to bill for this customer.')) ?>" data-l-same="<?= e(__('same as billing')) ?>">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <p class="text-sm text-steel"><a href="<?= url('/accounting/billing') ?>" class="hover:text-signal-700"><?= e(__('Billing notes')) ?></a> › <?= e(__('Generate')) ?></p>
            <h1 class="page-title"><?= e(__('Generate billing note')) ?></h1>
        </div>
    </div>

    <div class="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="space-y-4">
            <?php if ($isAdmin): ?>
            <section class="rounded-xl border border-amber-200 bg-amber-50/60 p-5">
                <div class="flex items-center gap-3"><span class="flex size-9 items-center justify-center rounded-full bg-amber-500 text-white"><?= icon('shield', 'size-5') ?></span>
                    <div class="flex-1"><p class="text-[11px] font-semibold uppercase tracking-wider text-amber-700"><?= e(__('Admin')) ?></p><p class="font-semibold"><?= e(__('Override options')) ?></p></div></div>
                <label class="mt-4 flex cursor-pointer items-center justify-between gap-4 border-t border-amber-200 pt-4"><span><span class="block text-sm font-medium"><?= e(__('Enable override')) ?></span><span class="block text-xs text-steel"><?= e(__('Set the billing note number by hand')) ?></span></span>
                    <span class="relative inline-flex h-6 w-11 items-center"><input type="checkbox" data-override-on class="peer sr-only"><span class="absolute inset-0 rounded-full bg-graphite-900/15 transition-colors peer-checked:bg-signal-600"></span><span class="absolute left-0.5 size-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span></span></label>
                <input data-override hidden class="input mt-3 max-w-xs" placeholder="BI2610001" maxlength="40">
            </section>
            <?php endif ?>

            <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-graphite-900/8" data-step="1">
                <div class="flex items-center gap-3"><span data-dot class="flex size-9 items-center justify-center rounded-full bg-graphite-900/8 text-sm font-semibold text-steel">1</span>
                    <div class="flex-1"><p class="text-[11px] font-semibold uppercase tracking-wider text-steel"><?= e(__('Step 1')) ?></p><p class="font-semibold"><?= e(__('Select customer')) ?></p></div>
                    <div class="inline-flex rounded-lg bg-mist p-1 text-xs font-semibold" role="group" aria-label="<?= e(__('Language of the document')) ?>">
                        <button type="button" data-lang="th" class="rounded-md bg-white px-3 py-1 shadow-sm">TH</button><button type="button" data-lang="en" class="rounded-md px-3 py-1 text-steel">EN</button></div></div>
                <div class="relative mt-4 border-t border-graphite-900/8 pt-4">
                    <label class="label"><?= e(__('Customer')) ?> <span class="text-signal-600">*</span></label>
                    <button type="button" data-cust-btn class="flex min-h-12 w-full items-center justify-between gap-3 rounded-lg border border-graphite-900/15 bg-mist/50 px-3 py-2 text-left" aria-haspopup="listbox">
                        <span class="min-w-0"><span data-cust-name class="block truncate text-sm text-steel"><?= e(__('Select a customer')) ?></span><span data-cust-code class="block text-xs text-steel"></span></span><span class="text-steel">⌄</span></button>
                    <div data-cust-pop hidden class="absolute inset-x-0 top-full z-30 mt-1 rounded-lg bg-white p-2 shadow-xl ring-1 ring-graphite-900/10">
                        <input data-cust-q class="input" placeholder="<?= e(__('Search customer')) ?>" autocomplete="off">
                        <ul data-cust-list role="listbox" class="mt-2 max-h-72 overflow-auto"></ul>
                    </div>
                </div>
            </section>

            <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-graphite-900/8" data-step="2">
                <div class="flex items-center gap-3"><span data-dot class="flex size-9 items-center justify-center rounded-full bg-graphite-900/8 text-sm font-semibold text-steel">2</span>
                    <div class="flex-1"><p class="text-[11px] font-semibold uppercase tracking-wider text-steel"><?= e(__('Step 2')) ?></p><p class="font-semibold"><?= e(__('Select invoices')) ?></p></div>
                    <label class="flex items-center gap-2 text-xs text-steel"><input type="checkbox" data-inv-all class="size-4 accent-signal-600"> <?= e(__('Select all')) ?></label></div>
                <div class="mt-4 border-t border-graphite-900/8 pt-4">
                    <p data-inv-empty class="rounded-lg bg-mist/60 px-4 py-6 text-center text-sm text-steel"><?= e(__('Select a customer first.')) ?></p>
                    <div data-inv-box hidden class="max-h-80 overflow-auto rounded-lg ring-1 ring-graphite-900/10"><table class="w-full text-sm"><thead class="sticky top-0 bg-mist text-xs text-steel"><tr>
                        <th class="w-10 px-3 py-2"></th><th class="px-3 py-2 text-left font-medium"><?= e(__('Invoice')) ?></th><th class="px-3 py-2 text-left font-medium"><?= e(__('Order')) ?></th><th class="px-3 py-2 text-left font-medium"><?= e(__('Date')) ?></th><th class="px-3 py-2 text-left font-medium"><?= e(__('Due')) ?></th><th class="px-3 py-2 text-right font-medium"><?= e(__('Amount')) ?></th></tr></thead><tbody data-inv-rows class="divide-y divide-graphite-900/8"></tbody></table></div>
                </div>
            </section>

            <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-graphite-900/8" data-step="3">
                <div class="flex items-center gap-3"><span data-dot class="flex size-9 items-center justify-center rounded-full bg-graphite-900/8 text-sm font-semibold text-steel">3</span>
                    <div class="flex-1"><p class="text-[11px] font-semibold uppercase tracking-wider text-steel"><?= e(__('Step 3')) ?></p><p class="font-semibold"><?= e(__('Billing address')) ?></p></div></div>
                <div class="mt-4 space-y-2 border-t border-graphite-900/8 pt-4">
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-graphite-900/15 p-3 has-[:checked]:border-signal-600 has-[:checked]:bg-signal-50/40"><input type="radio" name="addr" value="customer" checked class="mt-1 size-4 accent-signal-600"><span data-addr-text class="text-sm text-steel"><?= e(__('The customer address appears here.')) ?></span></label>
                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-graphite-900/15 p-3 has-[:checked]:border-signal-600 has-[:checked]:bg-signal-50/40"><input type="radio" name="addr" value="custom" class="size-4 accent-signal-600"><span class="text-sm"><?= e(__('Enter a custom address')) ?></span></label>
                    <textarea data-addr-custom hidden rows="3" class="input" placeholder="<?= e(__('Company, street, district, province, postal code')) ?>"></textarea>
                </div>
            </section>

            <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-graphite-900/8" data-step="4">
                <div class="flex items-center gap-3"><span data-dot class="flex size-9 items-center justify-center rounded-full bg-graphite-900/8 text-sm font-semibold text-steel">4</span>
                    <div class="flex-1"><p class="text-[11px] font-semibold uppercase tracking-wider text-steel"><?= e(__('Step 4')) ?></p><p class="font-semibold"><?= e(__('Dates')) ?></p></div></div>
                <div class="mt-4 grid gap-4 border-t border-graphite-900/8 pt-4 sm:grid-cols-2">
                    <div><label class="label" for="bill-date"><?= e(__('Billing date')) ?> <span class="text-signal-600">*</span></label><input id="bill-date" type="date" data-bill-date class="input" value="<?= date('Y-m-d') ?>"></div>
                    <div><div class="flex items-center justify-between"><label class="label" for="remind-date"><?= e(__('Remind date')) ?></label>
                        <label class="flex cursor-pointer items-center gap-2 text-xs text-steel"><?= e(__('Same as billing date')) ?> <span class="relative inline-flex h-5 w-9 items-center"><input type="checkbox" data-remind-same checked class="peer sr-only"><span class="absolute inset-0 rounded-full bg-graphite-900/15 transition-colors peer-checked:bg-signal-600"></span><span class="absolute left-0.5 size-4 rounded-full bg-white shadow transition-transform peer-checked:translate-x-4"></span></span></label></div>
                        <input id="remind-date" type="date" data-remind-date class="input" value="<?= date('Y-m-d') ?>" disabled>
                        <p class="mt-1 text-xs text-steel"><?= e(__('A Lark message listing the notes of the day goes out at :t (Accounting → Notifications).', ['t' => $notifyTime])) ?></p></div>
                </div>
            </section>
        </div>

        <aside class="lg:sticky lg:top-20 lg:self-start">
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-graphite-900/8">
                <div class="flex items-center justify-between"><h2 class="font-semibold"><?= e(__('Progress')) ?></h2><span class="text-xs text-steel"><span data-prog-n>0</span>/4 <?= e(__('steps')) ?></span></div>
                <div class="mt-3 grid grid-cols-4 gap-2"><?php for ($i = 1; $i <= 4; $i++): ?><span data-prog class="h-1 rounded-full bg-graphite-900/10"></span><?php endfor ?></div>
                <h3 class="mt-6 font-semibold"><?= e(__('Summary')) ?></h3><p class="text-xs text-steel"><?= e(__('Review before generating')) ?></p>
                <dl class="mt-4 divide-y divide-graphite-900/8 text-sm">
                    <div class="py-3"><dt class="text-xs text-steel"><?= e(__('Customer')) ?></dt><dd data-sum-cust class="mt-0.5 font-medium">—</dd></div>
                    <div class="py-3"><dt class="text-xs text-steel"><?= e(__('Invoices')) ?></dt><dd data-sum-inv class="mt-0.5 font-medium">—</dd><dd data-sum-chips class="mt-1 flex flex-wrap gap-1"></dd></div>
                    <div class="py-3"><dt class="text-xs text-steel"><?= e(__('Address')) ?></dt><dd data-sum-addr class="mt-0.5 line-clamp-3 font-medium">—</dd></div>
                    <div class="py-3"><dt class="text-xs text-steel"><?= e(__('Billing date')) ?></dt><dd data-sum-date class="mt-0.5 font-medium">—</dd></div>
                    <div class="py-3"><dt class="text-xs text-steel"><?= e(__('Remind date')) ?></dt><dd class="mt-0.5 font-medium"><span data-sum-remind>—</span> <span data-sum-same class="ml-1 rounded bg-mist px-1.5 py-0.5 text-[11px] font-normal text-steel"></span></dd></div>
                    <div class="py-3"><dt class="text-xs text-steel"><?= e(__('Total')) ?></dt><dd data-sum-total class="mt-0.5 text-lg font-semibold tabular-nums">—</dd></div>
                </dl>
                <button type="button" data-generate class="btn-primary mt-4 w-full justify-center" disabled><?= icon('bolt', 'size-4') ?> <?= e(__('Generate')) ?></button>
            </div>
        </aside>
    </div>
    <div class="pointer-events-none fixed inset-0 z-[90] [&>*]:pointer-events-auto"><?= partial('accounting/busy') ?></div>
</div>
