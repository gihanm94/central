<?php
/*
 | Owner history as a timeline in a pop-up card, opened by a "View history" button.
 | $id (dialog id), $title, $history (Responsible::history rows, newest first), $created (optional: [at, by] — the start of the line)
 */
$created ??= null;
?>
<dialog id="<?= e($id) ?>" data-light-dismiss class="m-auto w-[min(32rem,calc(100vw-2rem))] rounded-xl bg-white p-0 text-graphite-900 shadow-2xl ring-1 ring-graphite-900/10 backdrop:bg-graphite-950/60" aria-labelledby="<?= e($id) ?>-title">
    <div class="flex items-center justify-between gap-3 border-b border-graphite-900/8 px-5 py-3.5">
        <h2 id="<?= e($id) ?>-title" class="flex items-center gap-2 text-base font-semibold"><?= icon('clock', 'size-5 text-steel') ?><?= e($title) ?></h2>
        <button type="button" class="btn-ghost -mr-2 px-2" data-close-dialog aria-label="<?= e(__('Close')) ?>"><?= icon('x', 'size-5') ?></button>
    </div>
    <div class="max-h-[70vh] overflow-y-auto px-5 py-4">
        <?php if (! $history && ! $created): ?>
            <p class="py-6 text-center text-sm text-steel"><?= e(__('No changes yet.')) ?></p>
        <?php endif ?>
        <ol class="relative">
            <?php foreach ($history as $i => $h): $last = $i === count($history) - 1 && ! $created; ?>
            <li class="relative flex gap-4 pb-5 <?= $last ? 'pb-0' : '' ?>">
                <?php if (! $last): ?><span class="absolute left-[0.6875rem] top-6 bottom-0 w-px bg-graphite-900/10" aria-hidden="true"></span><?php endif ?>
                <span class="relative mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full <?= $i === 0 ? 'bg-signal-600 text-white' : 'bg-white text-steel ring-1 ring-graphite-900/15' ?>"><?= icon('users', 'size-3.5') ?></span>
                <div class="min-w-0 flex-1">
                    <p class="text-xs tabular-nums text-steel"><time datetime="<?= e(date('c', strtotime($h['created_at']))) ?>"><?= e(format_date($h['created_at'], 'd M Y, H:i')) ?></time><?= $i === 0 ? ' · <span class="font-medium text-signal-700">'.e(__('latest')).'</span>' : '' ?></p>
                    <p class="mt-1 text-sm leading-6">
                        <?= partial('crm/panels/history-person', ['h' => $h, 'k' => 'from']) ?>
                        <?php if ($h['to_name']): ?><span class="mx-1 text-steel">→</span><?= partial('crm/panels/history-person', ['h' => $h, 'k' => 'to']) ?>
                        <?php else: ?><span class="text-steel">· <?= e(__('removed')) ?></span><?php endif ?>
                    </p>
                    <?php if ($h['by_name']): ?><p class="mt-0.5 text-xs text-steel"><?= e(__('by')) ?> <?= partial('crm/panels/history-person', ['h' => $h, 'k' => 'by']) ?></p><?php endif ?>
                    <?php if ($h['note']): ?><p class="mt-1.5 rounded-md bg-mist px-2.5 py-1.5 text-xs text-graphite-800"><?= e($h['note']) ?></p><?php endif ?>
                </div>
            </li>
            <?php endforeach ?>
            <?php if ($created): ?>
            <li class="relative flex gap-4">
                <span class="relative mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-700 ring-1 ring-emerald-600/20"><?= icon('plus', 'size-3.5') ?></span>
                <div class="min-w-0 flex-1">
                    <p class="text-xs tabular-nums text-steel"><?= e(format_date($created['at'], 'd M Y, H:i')) ?></p>
                    <p class="mt-1 text-sm"><span class="font-medium"><?= e(__('Created')) ?></span><?php if (! empty($created['by'])): ?> <span class="text-steel"><?= e(__('by')) ?></span> <span class="font-medium"><?= e($created['by']) ?></span><?php endif ?></p>
                </div>
            </li>
            <?php endif ?>
        </ol>
    </div>
    <div class="flex justify-end rounded-b-xl bg-mist/60 px-5 py-3"><button type="button" class="btn-secondary" data-close-dialog><?= e(__('Close')) ?></button></div>
</dialog>
