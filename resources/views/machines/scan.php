<?php /* $mine, $canCheck */ $crumbs = []; ?>
<div class="mx-auto max-w-xl" id="scan" data-lookup="<?= e(url('/machines/lookup')) ?>" data-find="<?= e(url('/machines/find')) ?>"
     data-msg-nocam="<?= e(__('The camera is not available. Type the code below or take a photo of the QR code.')) ?>" data-msg-nomatch="<?= e(__('No machine with this code.')) ?>">
    <h1 class="page-title"><?= e(__('Scan QR code')) ?></h1>
    <p class="mt-1 text-sm text-steel"><?= e(__('Point the camera at the QR label on the machine. You land on the machine, ready to do its checklist.')) ?></p>

    <section class="panel mt-4 overflow-hidden">
        <div class="relative aspect-square w-full bg-graphite-950 sm:aspect-[4/3]" data-scan-box>
            <video data-scan-video class="absolute inset-0 size-full object-cover" playsinline muted hidden></video>
            <div data-scan-idle class="absolute inset-0 flex flex-col items-center justify-center gap-3 p-6 text-center text-white">
                <?= icon('qr', 'size-14 opacity-70') ?>
                <button type="button" class="btn-primary !h-12 px-6 text-base" data-scan-start><?= icon('qr', 'size-5') ?> <?= e(__('Open the camera')) ?></button>
                <p class="text-xs text-graphite-300" data-scan-msg></p>
            </div>
            <div data-scan-frame class="pointer-events-none absolute inset-[18%] hidden rounded-2xl border-2 border-white/90 shadow-[0_0_0_999px_rgba(0,0,0,.45)]"></div>
        </div>
        <div class="flex items-center justify-between gap-2 border-t border-graphite-900/8 p-3">
            <button type="button" class="btn-secondary" data-scan-stop hidden><?= e(__('Stop camera')) ?></button>
            <label class="btn-secondary cursor-pointer"><?= icon('photo', 'size-4') ?> <?= e(__('Photo of a QR code')) ?><input type="file" accept="image/*" capture="environment" class="sr-only" data-scan-file></label>
        </div>
    </section>

    <section class="panel mt-4 p-4">
        <label for="code" class="label"><?= e(__('Or type the machine code or name')) ?></label>
        <form data-code-form class="flex gap-2"><input id="code" class="input !h-11" autocomplete="off" placeholder="ADM-0101-0001" data-code><button class="btn-dark !h-11"><?= e(__('Open')) ?></button></form>
        <ul class="mt-2 divide-y divide-graphite-900/6" data-results></ul>
        <p class="mt-2 text-sm text-red-700" data-code-error hidden></p>
    </section>

    <?php if ($mine): ?>
    <section class="panel mt-4 overflow-hidden">
        <h2 class="border-b border-graphite-900/6 bg-mist/50 px-4 py-2 text-xs font-semibold text-steel"><?= e(__('Machines I look after')) ?></h2>
        <ul class="divide-y divide-graphite-900/6">
            <?php foreach ($mine as $m): ?>
            <li><a href="<?= url('/machines/m/'.rawurlencode($m['machine_code'])) ?>" class="flex items-center gap-3 px-4 py-3 hover:bg-mist/40"><span class="min-w-0 flex-1"><span class="block text-sm font-medium tabular-nums"><?= e($m['machine_code']) ?></span><span class="block truncate text-xs text-steel"><?= e($m['machine_name']) ?></span></span>
                <?= \App\Modules\Machines\Support\Mx::pill($m['check_status']) ?></a></li>
            <?php endforeach ?>
        </ul>
    </section>
    <?php endif ?>
</div>
<script src="<?= asset('assets/vendor/qr/jsQR.js') ?>"></script>
<script src="<?= asset('assets/scan.js') ?>" defer></script>
