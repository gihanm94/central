<?php /* $c, $row, $items [id, body, author, created_by, created_at], $canPost */
$base = url($c['base'].'/'.$row['id']); $me = auth(); ?>
<section id="comments" class="panel">
    <div class="panel-head"><h2 class="panel-title"><?= e(__('Comments')) ?> <span class="ml-1 text-sm font-normal text-steel"><?= count($items) ?></span></h2></div>
    <?php if ($canPost): ?>
    <form method="POST" action="<?= $base ?>/comments" class="flex gap-3 border-b border-graphite-900/8 p-5">
        <?= csrf_field() ?>
        <?= partial('partials/avatar', ['name' => $me->name, 'avatar' => $me->avatar, 'size' => 'size-8', 'extra' => 'mt-0.5']) ?>
        <div class="min-w-0 flex-1">
            <label for="comment-body" class="sr-only"><?= e(__('Write a comment')) ?></label>
            <textarea id="comment-body" name="body" rows="2" maxlength="5000" required placeholder="<?= e(__('Write a comment…')) ?>" class="input <?= error_for('body') ? 'input-error' : '' ?>"></textarea>
            <?= field_error('body') ?>
            <div class="mt-2 flex justify-end"><button class="btn-dark py-1.5"><?= icon('chat', 'size-4') ?> <?= e(__('Post comment')) ?></button></div>
        </div>
    </form>
    <?php endif ?>
    <?php if (! $items): ?>
        <p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('No comments yet. Start the conversation.')) ?></p>
    <?php else: ?>
    <ul class="divide-y divide-graphite-900/6">
        <?php foreach ($items as $it): ?>
        <li class="flex gap-3 px-5 py-4">
            <?= partial('partials/avatar', ['name' => $it['author'], 'size' => 'size-8', 'extra' => 'mt-0.5']) ?>
            <div class="min-w-0 flex-1">
                <p class="flex flex-wrap items-baseline gap-x-2 text-sm"><span class="font-medium"><?= e($it['author']) ?></span>
                    <time class="text-xs text-steel" datetime="<?= e(date('c', strtotime($it['created_at']))) ?>" title="<?= e(format_date($it['created_at'], 'd M Y H:i')) ?>"><?= e(time_ago($it['created_at'])) ?></time></p>
                <p class="mt-1 whitespace-pre-line break-words text-sm text-graphite-800"><?= e($it['body']) ?></p>
            </div>
            <?php if ((int) $it['created_by'] === $me->id || $me->isAdmin()): ?>
            <form method="POST" action="<?= $base ?>/comments/<?= (int) $it['id'] ?>/delete" data-confirm="<?= e(__('Delete this comment?')) ?>" class="shrink-0"><?= csrf_field() ?>
                <button class="btn-ghost px-1.5 py-1 hover:text-signal-700" title="<?= e(__('Delete comment')) ?>" aria-label="<?= e(__('Delete comment')) ?>"><?= icon('trash', 'size-4') ?></button></form>
            <?php endif ?>
        </li>
        <?php endforeach ?>
    </ul>
    <?php endif ?>
</section>
