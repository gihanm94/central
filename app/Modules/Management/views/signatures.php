<?php /* $rows (members with signature info), $q, $dep, $has, $deps [id => name], $counts {members, signed} */ ?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0"><h1 class="page-title"><?= e(__('Member signatures')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(__('Add each person\'s signature: upload a picture of it or draw it here. People can then place their own signature on PDFs in User Management → Sign PDF.')) ?></p></div>
    <p class="text-sm text-steel"><?= e(__(':s of :m members have a signature', ['s' => (int) $counts['signed'], 'm' => (int) $counts['members']])) ?></p>
</div>

<form method="GET" action="<?= url('/management/signatures') ?>" class="mt-4 flex flex-wrap items-end gap-2 rounded-xl bg-white p-3 shadow-sm ring-1 ring-graphite-900/8">
    <div class="min-w-[12rem] flex-1"><label class="label" for="sg-q"><?= e(__('Search')) ?></label><input id="sg-q" name="q" value="<?= e($q) ?>" class="input" placeholder="<?= e(__('Name, e-mail or username')) ?>"></div>
    <div class="w-full sm:w-52"><label class="label" for="sg-dep"><?= e(__('Department')) ?></label><?= select_field('department', $deps, $dep ?: '', ['id' => 'sg-dep', 'placeholder' => __('All departments')]) ?></div>
    <div class="w-full sm:w-44"><label class="label" for="sg-has"><?= e(__('Signature')) ?></label><?= select_field('has', ['yes' => __('Has a signature'), 'no' => __('No signature yet')], $has, ['id' => 'sg-has', 'placeholder' => __('Everyone'), 'search' => false]) ?></div>
    <button class="btn-secondary"><?= e(__('Show')) ?></button>
</form>

<div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
    <?php foreach ($rows as $r): $has = $r['signed_at'] !== null; ?>
    <article class="panel flex flex-col overflow-hidden">
        <div class="flex items-center gap-3 px-4 py-3">
            <?= partial('partials/avatar', ['name' => $r['name'], 'avatar' => $r['avatar'], 'size' => 'size-10']) ?>
            <div class="min-w-0 flex-1"><p class="truncate font-medium"><?= e($r['name']) ?></p>
                <p class="truncate text-xs text-steel"><?= e(implode(' · ', array_filter([$r['job_title'], $r['department']])) ?: '—') ?></p></div>
            <?php if ($has): ?><span class="badge bg-emerald-50 text-emerald-800"><?= e(__('Signed')) ?></span><?php else: ?><span class="badge bg-amber-50 text-amber-800"><?= e(__('Missing')) ?></span><?php endif ?>
        </div>
        <div class="flex h-28 items-center justify-center border-y border-graphite-900/6 bg-[repeating-linear-gradient(45deg,#fafafa,#fafafa_8px,#f3f4f6_8px,#f3f4f6_16px)] p-3">
            <?php if ($has): ?><img src="<?= e(url('/management/signatures/'.(int) $r['id'].'/image').'?v='.strtotime((string) $r['signed_at'])) ?>" alt="<?= e(__('Signature of :name', ['name' => $r['name']])) ?>" loading="lazy" class="max-h-full max-w-full object-contain">
            <?php else: ?><span class="text-sm text-steel"><?= e(__('No signature yet')) ?></span><?php endif ?>
        </div>
        <div class="flex flex-wrap items-center gap-2 px-4 py-2.5">
            <p class="min-w-0 flex-1 truncate text-xs text-steel"><?= $has ? e(__(':how :date by :by', ['how' => $r['source'] === 'drawn' ? __('Drawn') : __('Uploaded'), 'date' => format_date($r['signed_at'], 'd M Y'), 'by' => $r['signed_by'] ?? '—'])).((int) $r['pdfs'] ? ' · '.e(__(':n PDFs', ['n' => (int) $r['pdfs']])) : '') : '' ?></p>
            <button type="button" class="btn-secondary h-8 px-3 text-xs" data-sig-open="<?= (int) $r['id'] ?>" data-sig-name="<?= e($r['name']) ?>"><?= icon($has ? 'pencil' : 'plus', 'size-4') ?> <?= e($has ? __('Change') : __('Add signature')) ?></button>
            <?php if ($has): ?>
            <form method="POST" action="<?= url('/management/signatures/'.(int) $r['id'].'/delete') ?>" data-confirm="<?= e(__('Remove the signature of :name? They can no longer sign PDFs until a new one is added.', ['name' => $r['name']])) ?>">
                <?= csrf_field() ?><button class="btn-ghost h-8 px-2 text-signal-700" aria-label="<?= e(__('Remove')) ?>"><?= icon('trash', 'size-4') ?></button></form>
            <?php endif ?>
        </div>
    </article>
    <?php endforeach ?>
    <?php if (! $rows): ?><p class="panel px-5 py-12 text-center text-sm text-steel sm:col-span-2 xl:col-span-3"><?= e(__('Nobody matches these filters.')) ?></p><?php endif ?>
</div>

<dialog id="sig-dialog" data-light-dismiss class="m-auto w-[min(40rem,calc(100vw-2rem))] rounded-xl bg-white p-0 text-graphite-900 shadow-2xl ring-1 ring-graphite-900/10 backdrop:bg-graphite-950/60" aria-labelledby="sig-title"
        data-base="<?= e(url('/management/signatures')) ?>" data-l-empty="<?= e(__('Draw the signature first.')) ?>" data-l-pick="<?= e(__('Choose an image first.')) ?>">
    <form method="POST" action="" enctype="multipart/form-data" data-sig-form>
        <?= csrf_field() ?><input type="hidden" name="drawn" value="">
        <div class="flex items-center justify-between gap-3 border-b border-graphite-900/8 px-5 py-3.5">
            <h2 id="sig-title" class="text-base font-semibold"><?= e(__('Signature of')) ?> <span data-sig-who></span></h2>
            <button type="button" class="btn-ghost -mr-2 px-2" data-close-dialog aria-label="<?= e(__('Close')) ?>"><?= icon('x', 'size-5') ?></button>
        </div>
        <div class="px-5 pt-4">
            <div class="inline-flex rounded-lg bg-mist p-0.5 text-sm" role="tablist">
                <button type="button" role="tab" data-sig-mode="draw" aria-selected="true" class="rounded-md px-3 py-1.5 aria-selected:bg-white aria-selected:font-medium aria-selected:shadow-sm"><?= icon('pencil', 'mr-1 inline size-4') ?><?= e(__('Draw')) ?></button>
                <button type="button" role="tab" data-sig-mode="upload" aria-selected="false" class="rounded-md px-3 py-1.5 aria-selected:bg-white aria-selected:font-medium aria-selected:shadow-sm"><?= icon('upload', 'mr-1 inline size-4') ?><?= e(__('Upload image')) ?></button>
            </div>
        </div>
        <div class="p-5" data-sig-panel="draw">
            <div class="relative rounded-lg border border-graphite-900/15 bg-white">
                <canvas data-sig-pad class="block h-56 w-full cursor-crosshair touch-none rounded-lg"></canvas>
                <span class="pointer-events-none absolute inset-x-8 bottom-12 border-b border-dashed border-graphite-900/20"></span>
                <span class="pointer-events-none absolute bottom-3 left-4 text-xs text-steel" data-sig-hint><?= e(__('Sign here with the mouse, a pen or your finger')) ?></span>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                <span class="text-steel"><?= e(__('Ink')) ?></span>
                <?php foreach (['#111827' => __('Black'), '#1d4ed8' => __('Blue')] as $c => $n): ?>
                    <button type="button" data-sig-color="<?= $c ?>" class="size-7 rounded-full ring-2 ring-offset-2 <?= $c === '#111827' ? 'ring-signal-600' : 'ring-transparent' ?>" style="background: <?= $c ?>" aria-label="<?= e($n) ?>"></button>
                <?php endforeach ?>
                <button type="button" data-sig-clear class="btn-ghost ml-auto"><?= icon('refresh', 'size-4') ?> <?= e(__('Clear')) ?></button>
            </div>
        </div>
        <div class="p-5" data-sig-panel="upload" hidden>
            <label class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed border-graphite-900/15 px-6 py-8 text-center hover:bg-mist/40">
                <img data-sig-preview alt="" class="hidden max-h-32 max-w-full object-contain">
                <span data-sig-pick class="flex flex-col items-center gap-2"><?= icon('photo', 'size-8 text-steel') ?><span class="font-medium"><?= e(__('Choose a picture of the signature')) ?></span></span>
                <span class="text-xs text-steel"><?= e(__('PNG, JPG or WebP. A dark signature on white paper works best; the white background is removed automatically.')) ?></span>
                <input type="file" name="file" accept="image/png,image/jpeg,image/webp" class="sr-only" data-sig-file>
            </label>
        </div>
        <div class="flex justify-end gap-2 rounded-b-xl bg-mist/60 px-5 py-3">
            <button type="button" class="btn-secondary" data-close-dialog><?= e(__('Cancel')) ?></button>
            <button class="btn-primary"><?= icon('check', 'size-4') ?> <?= e(__('Save signature')) ?></button>
        </div>
    </form>
</dialog>
<script src="<?= asset('assets/sigpad.js') ?>"></script>
