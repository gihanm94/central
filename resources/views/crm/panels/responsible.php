<?php /* $title, $people [[name, department]], $history (Responsible::history rows) */ $dlg = 'resp-history-'.substr(md5($title.json_encode(array_column($history ?? [], 'id'))), 0, 8); ?>
<section class="panel">
    <div class="panel-head"><h2 class="panel-title"><?= e($title) ?> <span class="ml-1 text-sm font-normal text-steel"><?= count($people) ?></span></h2></div>
    <?php if (! $people): ?>
        <p class="px-5 py-5 text-center text-sm text-steel"><?= e(__('Nobody yet.')) ?></p>
    <?php else: ?>
    <ul class="divide-y divide-graphite-900/6">
        <?php foreach ($people as $p): ?>
        <li class="flex items-center gap-3 px-5 py-2.5"><span class="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-graphite-900/6 text-xs font-semibold text-graphite-700"><?= e(initials($p['name'])) ?></span>
            <span class="min-w-0"><span class="block truncate text-sm font-medium"><?= e($p['name']) ?></span><?php if (! empty($p['department'])): ?><span class="block truncate text-xs text-steel"><?= e($p['department']) ?></span><?php endif ?></span></li>
        <?php endforeach ?>
    </ul>
    <?php endif ?>
    <?php if (! empty($history)): ?>
    <div class="flex items-center justify-between gap-3 border-t border-graphite-900/8 bg-mist/50 px-5 py-3">
        <div class="min-w-0 text-xs text-steel">
            <p class="font-semibold uppercase tracking-wide"><?= e(__('History')) ?> <span class="font-normal normal-case">(<?= count($history) ?>)</span></p>
            <p class="mt-0.5 truncate"><?= e(__('Last change :date', ['date' => format_date($history[0]['created_at'], 'd M Y')])) ?></p>
        </div>
        <button type="button" class="btn-secondary h-8 shrink-0 px-3 text-xs" data-open-dialog="<?= $dlg ?>"><?= icon('clock', 'size-4') ?> <?= e(__('View history')) ?></button>
    </div>
    <?= partial('crm/panels/owner-history', ['id' => $dlg, 'title' => __(':title · history', ['title' => $title]), 'history' => $history]) ?>
    <?php endif ?>
</section>
