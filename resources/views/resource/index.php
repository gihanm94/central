<?php
use App\Core\Support\Request;
$res = $c['resource'];
$q   = (string) Request::query('q', '');
$activeFilters = array_filter(array_intersect_key($_GET, $filters + ['q' => 1]));
$plural = __(ucfirst($c['plural']));
$singular = __($c['singular']);
$primary = array_key_first(array_filter($columns, fn ($col) => ! empty($col['primary']))) ?? array_key_first($columns);
$actionsFor = function (array $row, array $a) use ($c, $singular) { ob_start(); ?>
    <?php foreach ($a['links'] as $l): ?><a href="<?= url($l['url']) ?>" class="btn-ghost px-2 py-1.5" title="<?= e($l['label']) ?>" aria-label="<?= e($l['label']) ?>"><?= icon($l['icon'] ?? 'eye', 'size-4') ?></a><?php endforeach ?>
    <?php if ($a['download']): ?><a href="<?= url($c['base'].'/'.$row['id'].'/download') ?>" class="btn-ghost px-2 py-1.5" title="<?= e(__('Download')) ?>" aria-label="<?= e(__('Download')) ?>"><?= icon('download', 'size-4') ?></a><?php endif ?>
    <?php if ($a['edit']): ?><a href="<?= url($c['base'].'/'.$row['id'].'/edit') ?>" class="btn-ghost px-2 py-1.5" title="<?= e(__('Edit')) ?>" aria-label="<?= e(__('Edit')) ?>"><?= icon('pencil', 'size-4') ?></a><?php endif ?>
    <?php if ($a['delete']): ?>
        <form method="POST" action="<?= url($c['base'].'/'.$row['id'].'/delete') ?>" data-confirm="<?= e(__("Delete this :item? This can't be undone from the app.", ['item' => $singular])) ?>">
            <?= csrf_field() ?><button class="btn-ghost px-2 py-1.5 hover:text-signal-700" title="<?= e(__('Delete')) ?>" aria-label="<?= e(__('Delete')) ?>"><?= icon('trash', 'size-4') ?></button>
        </form>
    <?php endif ?>
<?php return ob_get_clean(); };
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
        <h1 class="page-title"><?= e($plural) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(__(':count total', ['count' => $total])) ?><?= $activeFilters ? ' · '.e(__('filtered')) : '' ?><?= $intro ? '. '.e($intro) : '' ?></p>
    </div>
    <div class="flex w-full flex-wrap gap-2 sm:w-auto">
        <?php if (can($res, 'import')): ?>
            <button type="button" class="btn-secondary flex-1 sm:flex-none" data-toggle="#import-panel" aria-expanded="false"><?= icon('upload', 'size-4') ?> <?= e(__('Import')) ?></button>
        <?php endif ?>
        <?php if (can($res, 'export')): ?>
            <a href="<?= e(url($c['base'].'/export', $_GET)) ?>" class="btn-secondary flex-1 sm:flex-none"><?= icon('download', 'size-4') ?> <?= e(__('Export')) ?></a>
        <?php endif ?>
        <?php if (can($res, 'create')): ?>
            <a href="<?= url($c['base'].'/create') ?>" class="btn-primary w-full sm:w-auto"><?= icon('plus', 'size-4') ?> <?= e(__('New :item', ['item' => $singular])) ?></a>
        <?php endif ?>
    </div>
</div>

<?php if (! empty($c['tabs'])): ?><?= partial('partials/tabs', ['tabs' => $c['tabs']]) ?><?php endif ?>

<?php if (can($res, 'import')): ?>
<form id="import-panel" hidden method="POST" action="<?= url($c['base'].'/import') ?>" enctype="multipart/form-data" class="panel mt-5 flex flex-wrap items-end gap-4 p-5">
    <?= csrf_field() ?>
    <div class="min-w-0 flex-1 basis-64">
        <label for="file" class="label"><?= e(__('CSV file')) ?></label>
        <input id="file" name="file" type="file" accept=".csv,text/csv" required class="w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-graphite-900 file:px-3 file:py-1.5 file:text-sm file:text-white">
        <p class="hint"><?= e(__('First row must be the column names. Drop-down columns accept the name shown in the app.')) ?> <a href="<?= url($c['base'].'/template') ?>" class="font-medium text-graphite-900 underline underline-offset-2"><?= e(__('Download the template')) ?></a></p>
    </div>
    <button class="btn-dark w-full sm:w-auto"><?= e(__('Import rows')) ?></button>
</form>
<?php endif ?>

<form method="GET" class="mt-5 grid gap-2 sm:flex sm:flex-wrap sm:items-center">
    <?php if ($canSearch): ?>
        <label class="relative sm:w-72">
            <span class="sr-only"><?= e(__('Search')) ?></span>
            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-graphite-400"><?= icon('search', 'size-4') ?></span>
            <input name="q" value="<?= e($q) ?>" type="search" placeholder="<?= e(__('Search :items', ['items' => mb_strtolower($plural)])) ?>" class="input pl-9">
        </label>
    <?php endif ?>
    <?php if ($filters): ?>
    <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
        <?php foreach ($filters as $name => $f): ?>
            <?= select_field($name, $f['options'], Request::query($name), ['placeholder' => $f['label'], 'submit' => true, 'wrap' => 'sm:w-48', 'aria' => $f['label']]) ?>
        <?php endforeach ?>
    </div>
    <?php endif ?>
    <div class="flex gap-2">
        <?php if ($canSearch): ?><button class="btn-secondary"><?= e(__('Search')) ?></button><?php endif ?>
        <?php if ($activeFilters): ?><a href="<?= url($c['base']) ?>" class="btn-ghost"><?= e(__('Clear')) ?></a><?php endif ?>
    </div>
</form>

<section class="panel mt-4 overflow-hidden">
    <!-- Phones: cards -->
    <ul class="divide-y divide-graphite-900/6 md:hidden">
        <?php foreach ($rows as $row): $a = $rowActions[$row['id']]; ?>
            <li class="p-4">
                <div class="min-w-0"><?= ($columns[$primary]['render'])($row) ?></div>
                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2.5 text-sm">
                    <?php foreach ($columns as $key => $col): if ($key === $primary) continue; ?>
                        <div class="min-w-0"><dt class="text-xs text-steel"><?= e($col['label']) ?></dt><dd class="mt-0.5 min-w-0 break-words"><?= ($col['render'])($row) ?></dd></div>
                    <?php endforeach ?>
                </dl>
                <div class="-mb-1 mt-2 flex justify-end gap-0.5"><?= $actionsFor($row, $a) ?></div>
            </li>
        <?php endforeach ?>
    </ul>
    <!-- Tablets and up: table -->
    <div class="hidden overflow-x-auto md:block">
        <table class="table">
            <thead class="bg-mist/60">
                <tr>
                    <?php foreach ($columns as $col): ?><th><?= e($col['label']) ?></th><?php endforeach ?>
                    <th class="text-right"><span class="sr-only"><?= e(__('Actions')) ?></span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr class="hover:bg-mist/40">
                    <?php foreach ($columns as $col): ?><td><?= ($col['render'])($row) ?></td><?php endforeach ?>
                    <td class="whitespace-nowrap text-right"><div class="inline-flex items-center gap-0.5"><?= $actionsFor($row, $rowActions[$row['id']]) ?></div></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php if (! $rows): ?>
        <p class="px-5 py-14 text-center text-sm text-steel">
            <?= e($activeFilters ? __('Nothing matches these filters.') : __('No :items yet.', ['items' => mb_strtolower($plural)])) ?>
            <?php if (! $activeFilters && can($res, 'create')): ?><a href="<?= url($c['base'].'/create') ?>" class="ml-1 font-medium text-signal-700 hover:underline"><?= e(__('Add the first one')) ?></a><?php endif ?>
        </p>
    <?php endif ?>
    <?= partial('partials/pagination', compact('total', 'perPage', 'page')) ?>
</section>
