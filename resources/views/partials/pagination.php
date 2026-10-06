<?php
$pages = (int) ceil($total / max(1, $perPage));
if ($pages <= 1) { return; }
$link = fn ($p) => url(App\Core\Support\Request::path(), array_merge($_GET, ['page' => $p]));
$from = ($page - 1) * $perPage + 1;
$to   = min($total, $page * $perPage);
?>
<nav class="flex items-center justify-between gap-4 border-t border-graphite-900/8 px-5 py-3 text-sm" aria-label="<?= e(__('Pages')) ?>">
    <p class="text-steel"><?= e(__(':from–:to of :total', ['from' => $from, 'to' => $to, 'total' => $total])) ?></p>
    <div class="flex gap-1">
        <?php if ($page > 1): ?><a href="<?= e($link($page - 1)) ?>" class="btn-secondary px-3 py-1.5"><?= e(__('Previous')) ?></a><?php endif ?>
        <?php if ($page < $pages): ?><a href="<?= e($link($page + 1)) ?>" class="btn-secondary px-3 py-1.5"><?= e(__('Next')) ?></a><?php endif ?>
    </div>
</nav>
