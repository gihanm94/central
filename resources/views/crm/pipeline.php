<?php
use App\Modules\CRM\Support\Catalog;
use App\Modules\CRM\Support\Stages;
use App\Modules\CRM\Support\Ui;
/* $cols [code => [stats, html, more]], $stages, $cur, $all, $deps, $q, $mine, $dep, $canEdit, $needsReason */
$crumbs = [];
$link = fn (array $o) => url('/crm/pipeline', array_filter(array_merge(['q' => $q ?: null, 'mine' => $mine ? 1 : null, 'department' => $dep ?: null], $o), fn ($v) => $v !== null));
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div><h1 class="page-title"><?= e(__('Pipeline')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e($canEdit ? __('Drag your own cards to another stage. Cards with a red edge are yours; the rest belong to colleagues and are locked.') : __('Cards with a red edge are yours.')) ?></p></div>
    <form method="GET" id="board-form" class="flex flex-wrap items-center gap-2">
        <?php if ($mine): ?><input type="hidden" name="mine" value="1"><?php endif ?>
        <label class="relative w-full sm:w-64"><span class="sr-only"><?= e(__('Search')) ?></span>
            <span class="pointer-events-none absolute inset-y-0 left-2.5 flex items-center text-graphite-400"><?= icon('search', 'size-4') ?></span>
            <input name="q" value="<?= e($q) ?>" type="search" placeholder="<?= e(__('Search (3+ letters)…')) ?>" class="input pl-8" autocomplete="off" data-board-search></label>
        <?php if ($all): ?><div class="w-48"><?= select_field('department', $deps, $dep ?: '', ['placeholder' => __('All departments'), 'submit' => true, 'aria' => __('Department')]) ?></div><?php endif ?>
        <div class="inline-flex rounded-md bg-white p-0.5 text-sm ring-1 ring-graphite-900/15" role="group" aria-label="<?= e(__('Whose records')) ?>">
            <a href="<?= e($link(['mine' => null])) ?>" class="rounded px-3 py-1 <?= ! $mine ? 'bg-graphite-900 font-medium text-white' : 'text-steel hover:text-graphite-900' ?>"><?= e(__('Everyone')) ?></a>
            <a href="<?= e($link(['mine' => 1])) ?>" class="rounded px-3 py-1 <?= $mine ? 'bg-graphite-900 font-medium text-white' : 'text-steel hover:text-graphite-900' ?>"><?= e(__('My records')) ?></a>
        </div>
    </form>
</div>

<div data-board class="mt-5" data-move-url="<?= e(url('/crm/pipeline/move')) ?>" data-cards-url="<?= e(url('/crm/pipeline/cards')) ?>" data-needs-reason="<?= e(implode(',', $needsReason)) ?>" data-can-move="<?= $canEdit ? '1' : '0' ?>">
    <div class="flex items-start gap-3 overflow-x-auto pb-3" data-board-scroll>
    <?php foreach ($cols as $code => $col): $c = Stages::color($code); ?>
        <section class="kb-col flex w-[18.5rem] shrink-0 flex-col overflow-hidden rounded-xl bg-white/70 ring-1 ring-graphite-900/8" data-stage="<?= e($code) ?>" data-color="<?= e($c) ?>">
            <header class="px-3 pb-2.5 pt-3" style="border-top: 4px solid <?= e($c) ?>; background: <?= e($c) ?>14">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="flex min-w-0 items-center gap-2 text-sm font-semibold"><span class="size-2.5 shrink-0 rounded-full" style="background:<?= e($c) ?>"></span><span class="truncate"><?= e(Catalog::stageLabel($code)) ?></span></h2>
                    <span class="rounded-full bg-white px-2 py-0.5 text-xs font-semibold tabular-nums ring-1 ring-graphite-900/10" data-count><?= (int) $col['stats']['n'] ?></span>
                </div>
                <p class="mt-0.5 text-xs tabular-nums text-steel" data-sum><?= e($cur.' '.Ui::compact((float) $col['stats']['v'])) ?></p>
            </header>
            <div class="kb-list min-h-28 space-y-2 overflow-y-auto p-2" style="max-height: calc(100vh - 19rem)" data-list data-page="1" data-more="<?= $col['more'] ? '1' : '0' ?>">
                <?= $col['html'] ?>
                <p class="py-6 text-center text-xs text-steel" data-empty <?= $col['html'] !== '' ? 'hidden' : '' ?>><?= e(__('Nothing in this stage.')) ?></p>
                <p class="py-2 text-center text-xs text-steel" data-loading hidden><?= e(__('Loading…')) ?></p>
            </div>
        </section>
    <?php endforeach ?>
    </div>
</div>

<dialog id="move-dialog" class="m-auto w-[min(28rem,calc(100vw-2rem))] rounded-xl bg-white p-0 text-graphite-900 shadow-2xl ring-1 ring-graphite-900/10 backdrop:bg-graphite-950/60" aria-labelledby="move-title">
    <form method="dialog" data-move-form>
        <div class="p-6">
            <h2 id="move-title" class="text-base font-semibold" data-move-title><?= e(__('Reason needed')) ?></h2>
            <p class="mt-1 text-sm text-steel"><?= e(__('Tell the team why. This goes into the opportunity\'s history.')) ?></p>
            <label for="move-reason" class="label mt-4"><?= e(__('Reason')) ?> <span class="text-signal-600" aria-hidden="true">*</span></label>
            <textarea id="move-reason" rows="3" maxlength="2000" class="input" data-move-reason></textarea>
        </div>
        <div class="flex justify-end gap-2 rounded-b-xl bg-mist/60 px-6 py-3">
            <button type="button" class="btn-secondary" data-move-cancel><?= e(__('Cancel')) ?></button>
            <button class="btn-primary" data-move-go disabled><?= e(__('Move')) ?></button>
        </div>
    </form>
</dialog>
<div id="kb-toast" class="pointer-events-none fixed bottom-5 left-1/2 z-[80] -translate-x-1/2 rounded-lg bg-graphite-900 px-4 py-2.5 text-sm text-white opacity-0 shadow-xl transition-opacity" role="status"></div>
<script src="<?= asset('assets/pipeline.js') ?>" defer></script>
