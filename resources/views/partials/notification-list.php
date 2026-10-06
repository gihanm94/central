<?php
/* Rows of the bell dropdown and the notifications page. $items: notifications (+ actor_name) */
$icons = ['commented' => 'chat', 'reminder' => 'bell', 'shared' => 'users', 'updated' => 'pencil', 'created' => 'plus', 'deleted' => 'trash'];
?>
<?php if (! $items): ?>
    <p class="px-4 py-8 text-center text-sm text-steel"><?= e(__('You are all caught up.')) ?></p>
<?php else: ?>
<ul class="divide-y divide-graphite-900/6">
    <?php foreach ($items as $n): $unread = $n['read_at'] === null; ?>
    <li>
        <a href="<?= url('/notifications/'.$n['id'].'/open') ?>" class="flex items-start gap-3 px-4 py-3 hover:bg-mist/60 <?= $unread ? 'bg-signal-50/40' : '' ?>">
            <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full <?= $unread ? 'bg-brand text-white' : 'bg-graphite-900/6 text-steel' ?>"><?= icon($icons[$n['type']] ?? 'bell', 'size-4') ?></span>
            <span class="min-w-0 flex-1">
                <span class="block text-sm leading-snug <?= $unread ? 'font-semibold' : '' ?>"><?= e($n['title']) ?></span>
                <?php if ($n['body']): ?><span class="mt-0.5 block text-xs leading-snug text-steel"><?= e(str_limit((string) $n['body'], 110)) ?></span><?php endif ?>
                <span class="mt-1 block text-[11px] text-graphite-400"><?= e(($n['actor_name'] ? $n['actor_name'].' · ' : '').time_ago($n['created_at'])) ?></span>
            </span>
            <?php if ($unread): ?><span class="mt-2 size-2 shrink-0 rounded-full bg-signal-600" aria-label="<?= e(__('Unread')) ?>"></span><?php endif ?>
        </a>
    </li>
    <?php endforeach ?>
</ul>
<?php endif ?>
