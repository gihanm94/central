<?php /* $title, $people [[name, department]], $history (ownership_history rows with names) */ ?>
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
    <div class="border-t border-graphite-900/8 bg-mist/50 px-5 py-3">
        <p class="text-xs font-semibold uppercase tracking-wide text-steel"><?= e(__('Before')) ?></p>
        <ul class="mt-2 space-y-1.5 text-xs text-steel">
            <?php foreach ($history as $h): ?>
            <li><span class="font-medium text-graphite-800"><?= e($h['from_name'] ?? '—') ?></span>
                <?= $h['to_name'] ? '→ <span class="font-medium text-graphite-800">'.e($h['to_name']).'</span>' : '· '.e(__('removed')) ?>
                <span class="block text-[11px]"><?= e(format_date($h['created_at'], 'd M Y')) ?><?= $h['by_name'] ? ' · '.e($h['by_name']) : '' ?><?= $h['note'] ? ' · '.e($h['note']) : '' ?></span></li>
            <?php endforeach ?>
        </ul>
    </div>
    <?php endif ?>
</section>
