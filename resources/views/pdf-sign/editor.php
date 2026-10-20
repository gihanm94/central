<?php /* $signature (own user_signatures row or null) */
$crumbs = [[__('Sign PDF'), '/sign-pdf']];
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0"><h1 class="page-title"><?= e(__('Sign a PDF')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(__('Open a PDF, add your signature where it belongs, move and resize it, then sign and save. The original and the signed copy are both kept.')) ?></p></div>
</div>

<?php if (! $signature): ?>
    <section class="panel mt-6 px-5 py-12 text-center">
        <span class="mx-auto flex size-12 items-center justify-center rounded-full bg-mist text-steel"><?= icon('pencil', 'size-6') ?></span>
        <p class="mt-3 font-medium"><?= e(__('You have no signature yet')) ?></p>
        <p class="mt-1 text-sm text-steel"><?= e(__('Management adds signatures in Management → Member signatures. Ask them to add yours, then come back.')) ?></p>
    </section>
<?php else: ?>
<div id="pdf-sign" class="mt-6" data-worker="<?= e(asset('assets/vendor/pdfjs/pdf.worker.min.js')) ?>" data-signature="<?= e(url('/sign-pdf/my-signature')) ?>"
     data-store="<?= e(url('/sign-pdf')) ?>" data-token="<?= e(csrf_token()) ?>" data-max="<?= App\Core\Support\Signatures::maxPdf() ?>"
     data-l-no-sig="<?= e(__('Your signature could not be loaded.')) ?>" data-l-not-pdf="<?= e(__('Choose a PDF file.')) ?>" data-l-too-big="<?= e(__('PDF files must be :size or smaller.', ['size' => App\Core\Support\Files::size(App\Core\Support\Signatures::maxPdf())])) ?>"
     data-l-locked="<?= e(__('This PDF is password-protected and cannot be signed.')) ?>" data-l-broken="<?= e(__('This PDF could not be opened.')) ?>"
     data-l-page="<?= e(__('Page :n')) ?>" data-l-signature="<?= e(__('Your signature')) ?>" data-l-remove="<?= e(__('Remove')) ?>"
     data-l-restart="<?= e(__('Start again with another PDF? The signatures you placed are dropped.')) ?>" data-l-failed="<?= e(__('The PDF could not be signed. Try again.')) ?>">

    <label data-drop class="flex cursor-pointer flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed border-graphite-900/15 bg-white px-6 py-16 text-center hover:bg-mist/40">
        <span class="flex size-12 items-center justify-center rounded-full bg-signal-50 text-signal-700"><?= icon('upload', 'size-6') ?></span>
        <span class="font-medium"><?= e(__('Choose a PDF or drop it here')) ?></span>
        <span class="text-sm text-steel"><?= e(__('Up to :size. Password-protected PDFs cannot be signed.', ['size' => App\Core\Support\Files::size(App\Core\Support\Signatures::maxPdf())])) ?></span>
        <input type="file" accept="application/pdf,.pdf" data-file class="sr-only">
    </label>

    <div data-bar hidden class="sticky top-0 z-20 -mx-1 mb-4 flex flex-wrap items-center gap-2 rounded-xl bg-white/95 p-3 shadow-sm ring-1 ring-graphite-900/8 backdrop-blur sm:gap-3">
        <div class="min-w-0 basis-full sm:basis-0 sm:flex-1"><p class="truncate font-medium" data-name></p>
            <p class="text-xs text-steel"><span data-count>0</span> <?= e(__('pages')) ?> · <span data-n>0</span> <?= e(__('signatures placed')) ?></p></div>
        <span class="hidden items-center gap-2 rounded-md bg-mist px-2 py-1 sm:flex"><img src="<?= e(url('/sign-pdf/my-signature')) ?>" alt="<?= e(__('Your signature')) ?>" class="h-7 max-w-28 object-contain"></span>
        <button type="button" data-restart class="btn-ghost px-2 sm:px-3"><?= e(__('Another PDF')) ?></button>
        <button type="button" data-add class="btn-secondary flex-1 sm:flex-none"><?= icon('plus', 'size-4') ?> <?= e(__('Add my signature')) ?></button>
        <button type="button" data-save class="btn-primary flex-1 sm:flex-none" disabled><?= icon('check', 'size-4') ?> <?= e(__('Sign & save')) ?></button>
        <p class="hidden w-full text-xs text-steel sm:block"><?= e(__('"Add my signature" puts it on the page you are looking at. Drag to move, pull the corner to resize, × to remove; arrow keys nudge it.')) ?></p>
    </div>
    <div data-pages class="space-y-10 pt-6"></div>
</div>
<script src="<?= asset('assets/vendor/pdfjs/pdf.min.js') ?>"></script>
<script src="<?= asset('assets/vendor/pdf-lib/pdf-lib.min.js') ?>"></script>
<script src="<?= asset('assets/pdfsign.js') ?>"></script>
<?php endif ?>
