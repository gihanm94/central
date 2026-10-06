<?php /* Owner, department and history of a record. $row, $type */ ?>
<section class="panel">
    <div class="panel-head"><h2 class="panel-title"><?= e(__('Record')) ?></h2></div>
    <dl class="divide-y divide-graphite-900/6 text-sm">
        <div class="flex items-center justify-between gap-3 px-5 py-3">
            <dt class="text-steel"><?= e(__('Owner')) ?></dt>
            <dd class="flex min-w-0 items-center gap-2 font-medium"><?php if ($row['owner_name']): ?><?= partial('partials/avatar', ['name' => $row['owner_name'], 'size' => 'size-6', 'extra' => 'text-[10px]']) ?><span class="truncate"><?= e($row['owner_name']) ?></span><?php else: ?>—<?php endif ?></dd>
        </div>
        <div class="flex items-center justify-between gap-3 px-5 py-3"><dt class="text-steel"><?= e(__('Department')) ?></dt><dd class="truncate font-medium"><?= e($row['department_name'] ?? '—') ?></dd></div>
        <div class="flex items-center justify-between gap-3 px-5 py-3"><dt class="text-steel"><?= e(__('Created')) ?></dt><dd class="truncate text-right"><?= e(format_date($row['created_at'], 'd M Y')) ?><?php if ($row['creator_name']): ?><span class="block text-xs text-steel"><?= e($row['creator_name']) ?></span><?php endif ?></dd></div>
        <div class="flex items-center justify-between gap-3 px-5 py-3"><dt class="text-steel"><?= e(__('Updated')) ?></dt><dd class="text-right"><?= e(time_ago($row['updated_at'])) ?></dd></div>
    </dl>
</section>
