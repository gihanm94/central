<?php $crumbs = []; ?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div><h1 class="page-title"><?= e(__('Notifications')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(__(':n unread', ['n' => $unread])) ?> · <a href="<?= url('/profile/notifications') ?>" class="underline underline-offset-2 hover:text-graphite-900"><?= e(__('Choose what you receive')) ?></a></p></div>
    <div class="flex items-center gap-2">
        <div class="inline-flex rounded-md bg-white p-0.5 text-sm ring-1 ring-graphite-900/15" role="group">
            <a href="<?= url('/notifications') ?>" class="rounded px-3 py-1 <?= ! $only ? 'bg-graphite-900 font-medium text-white' : 'text-steel hover:text-graphite-900' ?>"><?= e(__('All')) ?></a>
            <a href="<?= url('/notifications', ['show' => 'unread']) ?>" class="rounded px-3 py-1 <?= $only ? 'bg-graphite-900 font-medium text-white' : 'text-steel hover:text-graphite-900' ?>"><?= e(__('Unread')) ?></a>
        </div>
        <?php if ($unread): ?><form method="POST" action="<?= url('/notifications/read-all') ?>"><?= csrf_field() ?><button class="btn-secondary"><?= icon('tick', 'size-4') ?> <?= e(__('Mark all as read')) ?></button></form><?php endif ?>
    </div>
</div>
<section class="panel mt-4 overflow-hidden">
    <?= partial('partials/notification-list', ['items' => $rows]) ?>
    <?= partial('partials/pagination', compact('total', 'perPage', 'page')) ?>
</section>
