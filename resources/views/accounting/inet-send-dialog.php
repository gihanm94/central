<?php /* The Send window of the Inets page. One for the whole page; filled by public/assets/accounting.js from the button that opened it. */ ?>
<dialog id="inet-send" aria-labelledby="inet-send-title"
        data-l-sending="<?= e(__('Sending…')) ?>" data-l-sent="<?= e(__('Sent')) ?>" data-l-sub="<?= e(__('Please wait, this can take a few seconds.')) ?>" data-generated="<?= e(__('Generated')) ?>" data-l-no-gen="<?= e(__('No generated PDF: tick Auto PDF when generating, or upload a file.')) ?>" data-l-bad="<?= e(__('Choose a PDF file (up to 15 MB).')) ?>"
        class="m-auto h-[min(52rem,calc(100vh-2rem))] w-[min(60rem,calc(100vw-2rem))] overflow-hidden rounded-xl bg-white p-0 text-graphite-900 shadow-2xl ring-1 ring-graphite-900/10 backdrop:bg-graphite-950/60">
    <form data-send-form class="relative flex h-full flex-col" onsubmit="return false">
        <header class="flex items-center gap-3 border-b border-graphite-900/8 px-4 py-3">
            <span class="flex size-9 items-center justify-center rounded-lg bg-signal-50 text-signal-700"><?= icon('send', 'size-5') ?></span>
            <h2 id="inet-send-title" class="min-w-0 flex-1 truncate text-base font-semibold"><?= e(__('Send')) ?> · <span data-send-title></span></h2>
            <label data-send-toggle-wrap class="flex cursor-pointer items-center gap-2 text-sm text-steel" title="">
                <span><?= e(__('Show Generated')) ?></span>
                <span class="relative inline-flex h-6 w-11 items-center">
                    <input type="checkbox" data-send-toggle class="peer sr-only">
                    <span class="absolute inset-0 rounded-full bg-graphite-900/15 transition-colors peer-checked:bg-signal-600 peer-disabled:opacity-40"></span>
                    <span class="absolute left-0.5 size-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                </span>
            </label>
            <span class="h-6 w-px bg-graphite-900/10"></span>
            <button type="button" class="btn-primary" data-send-go disabled><?= icon('send', 'size-4') ?> <?= e(__('Send')) ?></button>
            <button type="button" class="btn-ghost !px-2" data-send-close aria-label="<?= e(__('Close')) ?>"><?= icon('x', 'size-5') ?></button>
        </header>
        <div data-send-bar hidden class="flex items-center gap-2 border-b border-graphite-900/8 bg-mist/60 px-4 py-1.5 text-sm text-steel"><span data-send-bar-text class="truncate"></span><button type="button" data-send-clear class="ml-auto text-xs hover:text-signal-700"><?= e(__('Choose another file')) ?></button></div>
        <div class="relative min-h-0 flex-1 bg-mist/40">
            <!-- upload -->
            <div data-send-drop class="absolute inset-0 flex flex-col items-center justify-center gap-2 px-6 text-center">
                <h3 class="text-base font-semibold"><?= e(__('Upload PDF to Send')) ?></h3>
                <p class="max-w-sm text-sm text-steel"><?= e(__('Upload the signed PDF that will be submitted to the Revenue Department.')) ?></p>
                <label data-send-zone class="mt-3 flex w-full max-w-md cursor-pointer flex-col items-center gap-2 rounded-xl border border-dashed border-graphite-900/20 bg-white px-6 py-8 transition-colors hover:border-signal-400">
                    <span class="flex size-10 items-center justify-center rounded-lg bg-mist text-steel"><?= icon('upload', 'size-5') ?></span>
                    <span class="text-sm font-semibold"><?= e(__('Upload a file')) ?></span>
                    <span class="text-xs text-steel"><?= e(__('Drag and drop or click to upload')) ?><br><?= e(__('Accepts application/pdf.')) ?></span>
                    <input type="file" data-send-file accept="application/pdf" class="sr-only">
                </label>
            </div>
            <!-- preview (uploaded or generated) -->
            <iframe data-send-frame hidden title="PDF" class="absolute inset-0 size-full border-0 bg-white"></iframe>
        </div>
        <?= partial('accounting/busy') ?>
    </form>
</dialog>
