<?php /* PDF window of the billing list (toolbar: zoom, reload, print, download, close). Filled by public/assets/billing.js */ ?>
<dialog id="bill-viewer" class="m-auto h-[min(60rem,calc(100vh-2rem))] w-[min(56rem,calc(100vw-2rem))] overflow-hidden rounded-xl bg-white p-0 shadow-2xl ring-1 ring-graphite-900/10 backdrop:bg-graphite-950/70">
    <div class="flex h-full flex-col">
        <header class="flex items-center gap-2 border-b border-graphite-900/8 px-4 py-2.5">
            <h2 class="min-w-0 flex-1 truncate text-base font-semibold" data-bv-title></h2>
            <button type="button" class="btn-ghost !px-2" data-bv-zoom="-25" aria-label="<?= e(__('Zoom out')) ?>">−</button>
            <span class="w-12 text-center text-sm tabular-nums text-steel" data-bv-pct>100%</span>
            <button type="button" class="btn-ghost !px-2" data-bv-zoom="25" aria-label="<?= e(__('Zoom in')) ?>">+</button>
            <button type="button" class="btn-ghost !px-2" data-bv-reload aria-label="<?= e(__('Reload')) ?>">↻</button>
            <span class="mx-1 h-6 w-px bg-graphite-900/10"></span>
            <button type="button" class="btn-ghost" data-bv-print><?= icon('doc', 'size-4') ?> <?= e(__('Print')) ?></button>
            <a class="btn-ghost" data-bv-download href="#"><?= icon('download', 'size-4') ?> <?= e(__('Download')) ?></a>
            <span class="mx-1 h-6 w-px bg-graphite-900/10"></span>
            <button type="button" class="btn-ghost !px-2" data-bv-close aria-label="<?= e(__('Close')) ?>"><?= icon('x', 'size-5') ?></button>
        </header>
        <div class="relative min-h-0 flex-1 bg-graphite-900/90"><iframe data-bv-frame title="PDF" class="absolute inset-0 size-full border-0 bg-white"></iframe></div>
    </div>
</dialog>
