<?php
use App\Modules\CRM\Support\Catalog;
/* Next steps + history of an opportunity. $row, $steps (newest first), $canEdit */
$base  = url('/crm/opportunities/'.$row['id']);
$open  = array_values(array_filter($steps, fn ($s) => $s['kind'] === 'step' && ! $s['is_done']));
usort($open, fn ($a, $b) => strcmp((string) ($a['due_at'] ?? '9999'), (string) ($b['due_at'] ?? '9999')));
$past  = array_values(array_filter($steps, fn ($s) => $s['kind'] === 'stage' || $s['is_done']));
?>
<section id="progress" class="panel">
    <div class="panel-head"><h2 class="panel-title"><?= e(__('Progress and next steps')) ?></h2></div>

    <div class="border-b border-graphite-900/8 p-5">
        <h3 class="text-xs font-semibold text-steel"><?= e(__('Next steps')) ?></h3>
        <?php if (! $open): ?><p class="mt-2 text-sm text-steel"><?= e(__('Nothing planned. Add the next step so the team knows what comes next.')) ?></p>
        <?php else: ?>
        <ul class="mt-2 space-y-2">
            <?php foreach ($open as $i => $s): $late = $s['due_at'] && strtotime($s['due_at']) < strtotime('today'); ?>
            <li class="rounded-md ring-1 <?= $i === 0 ? 'bg-signal-50/40 ring-signal-600/25' : 'ring-graphite-900/10' ?>">
                <div class="flex flex-wrap items-start justify-between gap-3 px-4 py-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium break-words"><?= e($s['title']) ?><?php if ($i === 0): ?> <span class="badge ml-1 bg-signal-600 text-white"><?= e(__('Next')) ?></span><?php endif ?></p>
                        <p class="mt-0.5 text-xs text-steel"><?= e(Catalog::stageLabel($s['stage'])) ?> · <?= e($s['creator']) ?>
                            <?php if ($s['due_at']): ?> · <span class="<?= $late ? 'font-medium text-signal-700' : '' ?>"><?= e(__('Due :date', ['date' => format_date($s['due_at'], 'd M Y')])) ?><?= $late ? ' · '.e(__('Overdue')) : '' ?></span><?php endif ?></p>
                        <?php if ($s['note']): ?><p class="mt-1 whitespace-pre-line break-words text-sm text-graphite-800"><?= e($s['note']) ?></p><?php endif ?>
                    </div>
                    <?php if ($canEdit): ?>
                    <div class="flex shrink-0 items-center gap-1">
                        <details class="relative">
                            <summary class="btn-secondary cursor-pointer list-none py-1"><?= icon('tick', 'size-4') ?> <?= e(__('Done')) ?></summary>
                            <form method="POST" action="<?= $base ?>/steps/<?= (int) $s['id'] ?>/done" class="absolute right-0 z-10 mt-1 w-72 max-w-[80vw] space-y-2 rounded-lg bg-white p-3 shadow-xl ring-1 ring-graphite-900/10"><?= csrf_field() ?>
                                <label class="text-xs font-medium" for="done-note-<?= (int) $s['id'] ?>"><?= e(__('What was the result?')) ?> <span class="font-normal text-steel">(<?= e(__('optional')) ?>)</span></label>
                                <textarea id="done-note-<?= (int) $s['id'] ?>" name="note" rows="2" class="input"></textarea>
                                <div class="flex justify-end"><button class="btn-dark py-1"><?= e(__('Mark as done')) ?></button></div>
                            </form>
                        </details>
                        <form method="POST" action="<?= $base ?>/steps/<?= (int) $s['id'] ?>/delete" data-confirm="<?= e(__('Remove this step?')) ?>"><?= csrf_field() ?>
                            <button class="btn-ghost px-1.5 py-1 hover:text-signal-700" title="<?= e(__('Remove step')) ?>" aria-label="<?= e(__('Remove step :title', ['title' => $s['title']])) ?>"><?= icon('trash', 'size-4') ?></button></form>
                    </div>
                    <?php endif ?>
                </div>
            </li>
            <?php endforeach ?>
        </ul>
        <?php endif ?>

        <?php if ($canEdit): ?>
        <form method="POST" action="<?= $base ?>/steps" class="mt-4 grid gap-3 sm:grid-cols-6">
            <?= csrf_field() ?>
            <div class="sm:col-span-3"><label for="step-title" class="label"><?= e(__('Add a next step')) ?></label>
                <input id="step-title" name="title" required maxlength="200" class="input <?= error_for('title') ? 'input-error' : '' ?>" placeholder="<?= e(__('e.g. Send revised quotation')) ?>"><?= field_error('title') ?></div>
            <div class="sm:col-span-2"><label for="step-due" class="label"><?= e(__('Due date')) ?> <span class="font-normal text-steel">(<?= e(__('optional')) ?>)</span></label>
                <input id="step-due" name="due_at" type="date" class="input"><?= field_error('due_at') ?></div>
            <div class="flex items-end sm:col-span-1"><button class="btn-dark w-full"><?= icon('plus', 'size-4') ?> <?= e(__('Add')) ?></button></div>
        </form>
        <?php endif ?>
    </div>

    <div class="p-5">
        <h3 class="text-xs font-semibold text-steel"><?= e(__('History')) ?></h3>
        <?php if (! $past): ?><p class="mt-2 text-sm text-steel"><?= e(__('No history yet.')) ?></p>
        <?php else: ?>
        <ol class="mt-3 space-y-0">
            <?php foreach ($past as $i => $s): $isStage = $s['kind'] === 'stage'; $at = $isStage ? $s['created_at'] : ($s['done_at'] ?? $s['created_at']); $who = $isStage ? $s['creator'] : ($s['doer'] ?? $s['creator']); ?>
            <li class="relative flex gap-3 pb-5 last:pb-0">
                <?php if ($i < count($past) - 1): ?><span class="absolute left-[13px] top-7 h-[calc(100%-1.75rem)] w-px bg-graphite-900/12" aria-hidden="true"></span><?php endif ?>
                <span class="relative z-[1] flex size-7 shrink-0 items-center justify-center rounded-full ring-4 ring-white <?= $isStage ? 'bg-graphite-800 text-white' : 'bg-emerald-600 text-white' ?>"><?= icon($isStage ? 'right' : 'tick', 'size-3.5') ?></span>
                <div class="min-w-0 flex-1 pt-0.5">
                    <p class="text-sm break-words">
                        <?php if ($isStage): ?>
                            <?php if ($s['from_stage']): ?><?= e(__('Moved from :from to :to', ['from' => Catalog::stageLabel($s['from_stage']), 'to' => Catalog::stageLabel($s['stage'])])) ?>
                            <?php else: ?><?= e(__('Created in :to', ['to' => Catalog::stageLabel($s['stage'])])) ?><?php endif ?>
                        <?php else: ?><span class="font-medium"><?= e($s['title']) ?></span> <span class="text-steel">— <?= e(__('done')) ?></span><?php endif ?>
                    </p>
                    <?php if ($s['note']): ?><p class="mt-0.5 whitespace-pre-line break-words text-sm text-graphite-800"><?= e($s['note']) ?></p><?php endif ?>
                    <p class="mt-0.5 text-xs text-steel"><?= e($who) ?> · <time datetime="<?= e(date('c', strtotime($at))) ?>" title="<?= e(format_date($at, 'd M Y H:i')) ?>"><?= e(time_ago($at)) ?></time><?php if (! $isStage): ?> · <?= e(Catalog::stageLabel($s['stage'])) ?><?php endif ?></p>
                </div>
            </li>
            <?php endforeach ?>
        </ol>
        <?php endif ?>
    </div>
</section>
