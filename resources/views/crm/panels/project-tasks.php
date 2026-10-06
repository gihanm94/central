<?php
use App\Modules\CRM\Support\Catalog;
use App\Modules\CRM\Support\Ui;
/* $project, $tasks (with assignee_names), $canAdd, $canEdit */
$statuses = Catalog::tr(Catalog::TASK_STATUSES);
?>
<section class="panel" id="tasks">
    <div class="panel-head">
        <h2 class="panel-title"><?= e(__('Tasks')) ?> <span class="ml-1 text-sm font-normal text-steel"><?= count($tasks) ?></span></h2>
        <?php if ($canAdd): ?><a href="<?= url('/crm/tasks/create?project_id='.$project['id']) ?>" data-sheet data-sheet-title="<?= e(__('Add task')) ?>" class="btn-secondary py-1"><?= icon('plus', 'size-4') ?> <?= e(__('Add task')) ?></a><?php endif ?>
    </div>
    <?php if (! $tasks): ?>
        <p class="px-5 py-6 text-center text-sm text-steel"><?= e(__('No tasks yet.')) ?></p>
    <?php else: ?>
    <ul class="divide-y divide-graphite-900/6">
        <?php foreach ($tasks as $t):
            $closed = in_array($t['status'], ['DONE', 'CANCELLED'], true);
            $late   = ! $closed && $t['end_date'] && strtotime((string) $t['end_date'].' 23:59:59') < time(); ?>
        <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3">
            <div class="min-w-0 flex-1 basis-56">
                <a href="<?= url('/crm/tasks/'.$t['id']) ?>" class="block truncate text-sm font-medium hover:text-signal-700 <?= $closed ? 'text-steel line-through' : '' ?>"><?= e($t['name']) ?></a>
                <span class="block text-xs tabular-nums <?= $late ? 'font-medium text-signal-700' : 'text-steel' ?>"><?= $t['start_date'] || $t['end_date'] ? e(format_date($t['start_date'], 'd M').' → '.format_date($t['end_date'], 'd M Y')) : '' ?><?= $late ? ' · '.e(__('Overdue')) : '' ?></span>
            </div>
            <?= Ui::avatars($t['assignee_names']) ?>
            <?php if ($canEdit): ?>
            <form method="POST" action="<?= url('/crm/tasks/'.$t['id'].'/status') ?>" class="shrink-0">
                <?= csrf_field() ?>
                <select name="status" data-autosubmit class="input !h-7 !w-36 !py-0 text-xs" aria-label="<?= e(__('Status')) ?>">
                    <?php foreach ($statuses as $k => $l): ?><option value="<?= e($k) ?>" <?= $t['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach ?>
                </select>
            </form>
            <?php else: ?><?= Ui::badge($statuses[$t['status']] ?? $t['status'], Catalog::TASK_TONES[$t['status']] ?? 'neutral') ?><?php endif ?>
        </li>
        <?php endforeach ?>
    </ul>
    <?php endif ?>
</section>
