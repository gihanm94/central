<?php /* $f {q,from,to,page}, $result {rows,total,page,pages}, $signature (own row or null) */
$link = fn (array $x = []) => url('/sign-pdf', array_filter(array_merge(['q' => $f['q'], 'from' => $f['from'], 'to' => $f['to']], $x)));
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0"><h1 class="page-title"><?= e(__('Sign PDF')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(__('Put your signature on a PDF. Every PDF you sign is kept here with the original.')) ?></p></div>
    <?php if ($signature && can('pdf_sign', 'create')): ?><a href="<?= url('/sign-pdf/new') ?>" class="btn-primary w-full sm:w-auto"><?= icon('pencil', 'size-4') ?> <?= e(__('Sign a PDF')) ?></a><?php endif ?>
</div>

<div class="mt-6 grid items-start gap-6 lg:grid-cols-3">
    <section class="panel overflow-hidden lg:order-2">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('My signature')) ?></h2></div>
        <?php if ($signature): ?>
            <div class="flex h-32 items-center justify-center bg-[repeating-linear-gradient(45deg,#fafafa,#fafafa_8px,#f3f4f6_8px,#f3f4f6_16px)] p-4">
                <img src="<?= e(url('/sign-pdf/my-signature').'?v='.strtotime((string) $signature['updated_at'])) ?>" alt="<?= e(__('My signature')) ?>" class="max-h-full max-w-full object-contain"></div>
            <p class="border-t border-graphite-900/8 px-5 py-3 text-xs text-steel"><?= e(__('Set by Management on :date. Only you can place it on PDFs.', ['date' => format_date($signature['updated_at'], 'd M Y')])) ?></p>
        <?php else: ?>
            <p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('You have no signature yet. Management adds it in Management → Member signatures.')) ?></p>
        <?php endif ?>
    </section>

    <section class="panel overflow-hidden lg:col-span-2 lg:order-1">
        <form method="GET" action="<?= url('/sign-pdf') ?>" class="flex flex-wrap items-end gap-2 border-b border-graphite-900/8 p-3">
            <div class="min-w-[12rem] flex-1"><label class="label" for="sp-q"><?= e(__('Search')) ?></label><input id="sp-q" name="q" value="<?= e($f['q']) ?>" class="input" placeholder="<?= e(__('File name')) ?>"></div>
            <div><label class="label" for="sp-from"><?= e(__('From')) ?></label><input id="sp-from" type="date" name="from" value="<?= e($f['from']) ?>" class="input"></div>
            <div><label class="label" for="sp-to"><?= e(__('To')) ?></label><input id="sp-to" type="date" name="to" value="<?= e($f['to']) ?>" class="input"></div>
            <button class="btn-secondary"><?= e(__('Show')) ?></button>
        </form>
        <?= partial('pdf-sign/rows', ['result' => $result, 'base' => '/sign-pdf', 'showPerson' => false, 'link' => $link]) ?>
    </section>
</div>
