<?php use App\Core\Support\Request; use App\Core\Http\Controllers\ActivityController; $f = fn ($k) => (string) Request::query($k, '');
/* $logs, $total, $page, $perPage, $users, $mine (own entries only), $base (/activity or /my-activity), $canExport */ ?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0"><h1 class="page-title"><?= e($mine ? __('My activity') : __('Activity log')) ?></h1><p class="mt-1 text-sm text-steel"><?= e($mine
        ? __(':count entries. Your own sign-ins, changes, imports, exports and downloads.', ['count' => $total])
        : __(':count entries. Every sign-in, change, import, export and download is recorded here.', ['count' => $total])) ?></p></div>
    <?php if ($canExport): ?><a href="<?= e(url($base.'/export', $_GET)) ?>" class="btn-secondary w-full sm:w-auto"><?= icon('download', 'size-4') ?> <?= e(__('Export')) ?></a><?php endif ?>
</div>

<form method="GET" class="mt-5 grid gap-2 sm:grid-cols-2 lg:grid-cols-6">
    <input name="q" type="search" value="<?= e($f('q')) ?>" placeholder="<?= e(__('Search description or IP')) ?>" class="input sm:col-span-2">
    <?= select_field('action', ActivityController::actions(), $f('action'), ['placeholder' => __('All actions'), 'aria' => __('Action')]) ?>
    <?php if (! $mine): ?><?= select_field('user', $users, $f('user'), ['placeholder' => __('Everyone'), 'aria' => __('Person')]) ?><?php endif ?>
    <input type="date" name="from" value="<?= e($f('from')) ?>" class="input" aria-label="<?= e(__('From date')) ?>">
    <input type="date" name="to" value="<?= e($f('to')) ?>" class="input <?= $mine ? 'lg:col-span-2' : '' ?>" aria-label="<?= e(__('To date')) ?>">
    <div class="flex gap-2 sm:col-span-2 lg:col-span-6"><button class="btn-dark"><?= e(__('Filter')) ?></button><?php if (array_filter($_GET)): ?><a href="<?= url($base) ?>" class="btn-ghost"><?= e(__('Clear')) ?></a><?php endif ?></div>
</form>

<section class="panel mt-4">
    <ul class="divide-y divide-graphite-900/6">
        <?php foreach ($logs as $log): ?><?= partial('partials/activity-row', ['log' => $log, 'showUser' => ! $mine, 'base' => $base]) ?><?php endforeach ?>
        <?php if (! $logs): ?><li class="px-5 py-14 text-center text-sm text-steel"><?= e(__('No activity matches these filters.')) ?></li><?php endif ?>
    </ul>
    <?= partial('partials/pagination', compact('total', 'perPage', 'page')) ?>
</section>
