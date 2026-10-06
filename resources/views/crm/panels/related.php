<?php /* $title, $items [[href,title,meta,badge]], $createUrl, $empty, $createLabel */ ?>
<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title"><?= e($title) ?> <span class="ml-1 text-sm font-normal text-steel"><?= count($items) ?></span></h2>
        <?php if ($createUrl): ?><a href="<?= url($createUrl) ?>" class="btn-secondary py-1"><?= icon('plus', 'size-4') ?> <?= e($createLabel ?: __('Add')) ?></a><?php endif ?>
    </div>
    <?php if (! $items): ?>
        <p class="px-5 py-6 text-center text-sm text-steel"><?= e($empty) ?></p>
    <?php else: ?>
    <ul class="divide-y divide-graphite-900/6">
        <?php foreach ($items as $it): ?>
        <li><a href="<?= url($it['href']) ?>" class="flex items-center justify-between gap-3 px-5 py-3 hover:bg-mist/50">
            <span class="min-w-0"><span class="block truncate text-sm font-medium"><?= e($it['title']) ?></span><?php if ($it['meta']): ?><span class="block truncate text-xs text-steel"><?= $it['meta'] === strip_tags($it['meta']) ? e($it['meta']) : $it['meta'] ?></span><?php endif ?></span>
            <span class="shrink-0"><?= $it['badge'] ?></span>
        </a></li>
        <?php endforeach ?>
    </ul>
    <?php endif ?>
</section>
