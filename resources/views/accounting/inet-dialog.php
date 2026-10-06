<?php /* "Select Invoices to Generate" — filled by public/assets/accounting.js. $credit = which tab the page is on */ ?>
<dialog id="inet-dialog" data-credit="<?= $credit ? '1' : '0' ?>" aria-labelledby="inet-dialog-title"
        class="m-auto w-[min(56rem,calc(100vw-2rem))] rounded-xl bg-white p-0 text-graphite-900 shadow-2xl ring-1 ring-graphite-900/10 backdrop:bg-graphite-950/60">
    <div class="flex items-center justify-between px-5 pb-3 pt-4">
        <h2 id="inet-dialog-title" class="text-base font-semibold"><?= e(__('Select Invoices to Generate')) ?></h2>
        <button type="button" class="btn-ghost !px-2" data-inet-close aria-label="<?= e(__('Close')) ?>">&times;</button>
    </div>
    <div class="flex flex-wrap items-center gap-2 border-t border-graphite-900/8 px-5 py-3">
        <label class="relative min-w-0 flex-1 basis-56"><span class="sr-only"><?= e(__('Search')) ?></span>
            <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-steel"><?= icon('search', 'size-4') ?></span>
            <input type="search" data-inet-q class="input !pl-9" placeholder="<?= e(__('Search…')) ?>" autocomplete="off"></label>
        <div class="inline-flex gap-1 rounded-lg bg-mist p-1" role="tablist">
            <button type="button" role="tab" data-inet-tab="0" class="rounded-md px-3 py-1 text-sm"><?= e(__('Invoice')) ?></button>
            <button type="button" role="tab" data-inet-tab="1" class="rounded-md px-3 py-1 text-sm"><?= e(__('Credit note')) ?></button>
        </div>
    </div>
    <div class="px-5">
        <div class="max-h-[22rem] overflow-auto rounded-lg ring-1 ring-graphite-900/10">
            <table class="w-full text-center text-sm">
                <thead class="sticky top-0 bg-mist text-xs uppercase tracking-wide text-steel"><tr>
                    <th class="w-12 px-3 py-2.5"><input type="checkbox" data-inet-all class="size-4 accent-signal-600" aria-label="<?= e(__('Tick all on this page')) ?>"></th>
                    <th class="px-3 py-2.5 font-semibold"><?= e(__('Invoice no')) ?></th><th class="px-3 py-2.5 font-semibold"><?= e(__('Auto PDF')) ?></th>
                    <th class="px-3 py-2.5 font-semibold"><?= e(__('Order no')) ?></th><th class="px-3 py-2.5 font-semibold"><?= e(__('Delivery no')) ?></th>
                    <th class="px-3 py-2.5 font-semibold"><?= e(__('VAT no')) ?></th><th class="px-3 py-2.5 font-semibold"><?= e(__('Remark')) ?></th>
                </tr></thead>
                <tbody data-inet-rows class="divide-y divide-graphite-900/8"></tbody>
            </table>
            <p data-inet-empty hidden class="px-5 py-10 text-sm text-steel"><?= e(__('Nothing to generate. Everything from the ERP copy is already here.')) ?></p>
        </div>
        <p data-inet-error hidden class="error mt-2"></p>
        <div data-inet-live hidden class="mt-3">
            <div class="mb-1 flex items-center justify-between text-xs text-steel"><span class="font-medium text-graphite-800"><?= e(__('Live log')) ?></span><span data-inet-livestate></span></div>
            <div data-inet-log class="h-44 overflow-auto rounded-lg bg-graphite-900 p-3 font-mono text-xs leading-5 text-white"></div>
        </div>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
        <div class="flex items-center gap-2">
            <button type="button" data-inet-prev class="btn-secondary !size-9 !rounded-full !p-0" aria-label="<?= e(__('Previous')) ?>"><?= icon('arrowleft', 'size-4') ?></button>
            <span class="min-w-14 text-center text-sm tabular-nums text-steel" data-inet-pager>1 / 1</span>
            <button type="button" data-inet-next class="btn-secondary !size-9 !rounded-full !p-0" aria-label="<?= e(__('Next')) ?>"><?= icon('right', 'size-4') ?></button>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-sm text-steel"><span data-inet-count>0</span> <?= e(__('selected')) ?></span>
            <button type="button" class="btn-secondary" data-inet-close><?= e(__('Cancel')) ?></button>
            <button type="button" class="btn-primary" data-inet-go disabled><?= icon('bolt', 'size-4') ?> <?= e(__('Generate')) ?></button>
        </div>
    </div>
</dialog>
