<?php
use App\Core\Support\Request;
$res      = $c['resource'];
$q        = (string) Request::query('q', '');
$filterKeys = array_keys($filters);
$activeFilters = array_filter(array_intersect_key($_GET, array_flip($filterKeys)), fn ($v) => $v !== '' && $v !== null);
$searching = $q !== '' || $activeFilters;
$plural   = __(ucfirst($c['plural']));
$singular = __($c['singular']);
$primary  = array_key_first(array_filter($columns, fn ($col) => ! empty($col['primary']))) ?? array_key_first($columns);
$tones    = ['amber' => 'bg-amber-50/70', 'sky' => 'bg-sky-50/70', 'emerald' => 'bg-emerald-50/70'];
$sortUrl  = function (string $key) use ($sort, $dir) {
    $next = $sort === $key && $dir === 'asc' ? 'desc' : 'asc';
    return url(Request::path(), array_merge($_GET, ['sort' => $key, 'dir' => $next, 'page' => null]));
};
$canEditAny = can($res, 'edit');
$ro = ! empty($c['readonly']);          // read-only screens (ERP copies): no add / import
$rowMenu = function (array $row, array $a, string $id) use ($c, $singular) { ob_start(); ?>
    <div id="<?= e($id) ?>" data-menu-panel hidden role="menu" class="fixed z-[70] w-48 rounded-lg bg-white p-1.5 text-sm shadow-xl ring-1 ring-graphite-900/10">
        <a role="menuitem" href="<?= url($c['base'].'/'.$row['id']) ?>" class="flex items-center gap-2.5 rounded-md px-2.5 py-1.5 hover:bg-mist"><?= icon('eye', 'size-4 text-steel') ?> <?= e(__('View')) ?></a>
        <?php if ($a['edit']): ?><a role="menuitem" href="<?= url($c['base'].'/'.$row['id'].'/edit') ?>" class="flex items-center gap-2.5 rounded-md px-2.5 py-1.5 hover:bg-mist"><?= icon('pencil', 'size-4 text-steel') ?> <?= e(__('Edit')) ?></a><?php endif ?>
        <?php foreach ($a['links'] as $l): ?><a role="menuitem" href="<?= url($l['url']) ?>" class="flex items-center gap-2.5 rounded-md px-2.5 py-1.5 hover:bg-mist"><?= icon($l['icon'] ?? 'eye', 'size-4 text-steel') ?> <?= e($l['label']) ?></a><?php endforeach ?>
        <?php if ($a['download']): ?><a role="menuitem" href="<?= url($c['base'].'/'.$row['id'].'/download') ?>" class="flex items-center gap-2.5 rounded-md px-2.5 py-1.5 hover:bg-mist"><?= icon('download', 'size-4 text-steel') ?> <?= e(__('Download')) ?></a><?php endif ?>
        <?php if ($a['delete']): ?>
            <hr class="my-1 border-graphite-900/8">
            <button type="button" role="menuitem" data-delete-url="<?= e(url($c['base'].'/'.$row['id'].'/delete')) ?>" data-delete-name="<?= e($row['_label'] ?? '') ?>" data-delete-kind="<?= e($singular) ?>" class="flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left text-signal-700 hover:bg-signal-50"><?= icon('trash', 'size-4') ?> <?= e(__('Delete')) ?></button>
        <?php endif ?>
    </div>
<?php return ob_get_clean(); };
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
        <h1 class="page-title"><?= e($plural) ?></h1>
        <p class="mt-1 text-sm text-steel"><span data-total><?= e(__(':count total', ['count' => $total])) ?><?= $searching ? ' · '.e(__('filtered')) : '' ?></span><?= $intro ? '. '.e($intro) : '' ?></p>
    </div>
</div>

<?php if (! empty($stats)): ?>
<?= partial('crm/stats', ['cards' => $stats]) ?>
<?php endif ?>

<?= $topExtra ?? '' ?>
<section class="panel mt-4" data-table="<?= e($res) ?>" data-prefs-url="<?= e(url('/table-prefs')) ?>">
    <!-- Toolbar: search + filter on the left; add, import, export, columns on the right -->
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-graphite-900/8 p-3">
        <form method="GET" id="list-form" class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
            <?php foreach (['sort', 'dir', 'per_page'] as $keep): if (Request::query($keep)): ?><input type="hidden" name="<?= $keep ?>" value="<?= e(Request::query($keep)) ?>"><?php endif; endforeach ?>
            <?php if ($canSearch): ?>
            <label class="relative w-full sm:w-72">
                <span class="sr-only"><?= e(__('Search')) ?></span>
                <span class="pointer-events-none absolute inset-y-0 left-2.5 flex items-center text-graphite-400"><?= icon('search', 'size-4') ?></span>
                <input name="q" value="<?= e($q) ?>" type="search" placeholder="<?= e(__('Search (3+ letters)…')) ?>" class="input pl-8" autocomplete="off">
            </label>
            <?php endif ?>
            <?php if ($filters): ?>
            <div class="relative">
                <button type="button" class="btn-secondary" data-toggle="#filter-panel" aria-expanded="false"><?= icon('funnel', 'size-4') ?> <?= e(__('Filter')) ?><?php if ($activeFilters): ?><span class="ml-0.5 inline-flex size-5 items-center justify-center rounded-full bg-brand text-[11px] font-semibold text-white"><?= count($activeFilters) ?></span><?php endif ?></button>
                <div id="filter-panel" hidden class="absolute left-0 top-full z-30 mt-2 w-[min(22rem,calc(100vw-2rem))] rounded-lg bg-white p-4 shadow-xl ring-1 ring-graphite-900/10">
                    <div class="space-y-3">
                        <?php foreach ($filters as $name => $f): ?>
                            <div><label class="label !mb-1 text-xs text-steel"><?= e($f['label']) ?></label>
                                <?= select_field($name, $f['options'], Request::query($name), ['placeholder' => $f['label'], 'aria' => $f['label']]) ?></div>
                        <?php endforeach ?>
                    </div>
                    <div class="mt-4 flex justify-between gap-2">
                        <a href="<?= url($c['base']) ?>" class="btn-ghost"><?= e(__('Clear all')) ?></a>
                        <button class="btn-dark"><?= e(__('Apply')) ?></button>
                    </div>
                </div>
            </div>
            <?php endif ?>
            <?php if ($searching): ?><a href="<?= url($c['base']) ?>" class="btn-ghost"><?= e(__('Clear')) ?></a><?php endif ?>
        </form>

        <div class="flex flex-wrap items-center gap-2">
            <?= $headerActions ?? '' ?>
            <?php if (! $ro && can($res, 'create')): ?><a href="<?= url($c['base'].'/create') ?>" class="btn-primary"><?= icon('plus', 'size-4') ?> <?= e(__('Add')) ?></a><?php endif ?>
            <?php if (! $ro && can($res, 'import')): ?><button type="button" class="btn-secondary" data-toggle="#import-panel" aria-expanded="false" title="<?= e(__('Import')) ?>"><?= icon('upload', 'size-4') ?><span class="hidden lg:inline"> <?= e(__('Import')) ?></span></button><?php endif ?>
            <?php if (can($res, 'export')): ?><a href="<?= e(url($c['base'].'/export', $_GET)) ?>" class="btn-secondary" title="<?= e(__('Export')) ?>"><?= icon('download', 'size-4') ?><span class="hidden lg:inline"> <?= e(__('Export')) ?></span></a><?php endif ?>
            <div class="relative">
                <button type="button" class="btn-secondary" data-menu="#columns-menu" data-placement="bottom-end" aria-haspopup="menu" aria-expanded="false" title="<?= e(__('Choose columns')) ?>"><?= icon('columns', 'size-4') ?><span class="hidden lg:inline"> <?= e(__('View')) ?></span></button>
                <div id="columns-menu" data-menu-panel hidden role="menu" class="fixed z-[70] w-60 rounded-lg bg-white p-2 shadow-xl ring-1 ring-graphite-900/10">
                    <p class="px-2 pb-1.5 pt-1 text-xs font-medium text-steel"><?= e(__('Columns to show')) ?></p>
                    <?php foreach ($columns as $key => $col): $isPrimary = $key === $primary; ?>
                    <label class="flex items-center gap-2.5 rounded-md px-2 py-1.5 text-sm hover:bg-mist <?= $isPrimary ? 'opacity-60' : 'cursor-pointer' ?>">
                        <input type="checkbox" data-col-toggle="<?= e($key) ?>" class="size-4 accent-signal-600" <?= in_array($key, $hidden, true) && ! $isPrimary ? '' : 'checked' ?> <?= $isPrimary ? 'disabled' : '' ?>>
                        <?= e($col['label']) ?>
                    </label>
                    <?php endforeach ?>
                    <button type="button" data-col-reset class="mt-1 w-full rounded-md px-2 py-1.5 text-left text-xs text-steel hover:bg-mist"><?= e(__('Show all columns')) ?></button>
                </div>
            </div>
        </div>
    </div>

    <?php if (! $ro && can($res, 'import')): ?>
    <form id="import-panel" hidden method="POST" action="<?= url($c['base'].'/import') ?>" enctype="multipart/form-data" class="flex flex-wrap items-end gap-4 border-b border-graphite-900/8 bg-mist/40 p-4">
        <?= csrf_field() ?>
        <div class="min-w-0 flex-1 basis-64">
            <label for="file" class="label"><?= e(__('CSV file')) ?></label>
            <input id="file" name="file" type="file" accept=".csv,text/csv" required class="w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-graphite-900 file:px-3 file:py-1.5 file:text-sm file:text-white">
            <p class="hint"><?= e(__('First row must be the column names. Drop-down columns accept the name shown in the app.')) ?> <a href="<?= url($c['base'].'/template') ?>" class="font-medium text-graphite-900 underline underline-offset-2"><?= e(__('Download the template')) ?></a></p>
        </div>
        <button class="btn-dark"><?= e(__('Import rows')) ?></button>
    </form>
    <?php endif ?>

    <div data-list-region>
    <!-- Phones: cards -->
    <ul class="divide-y divide-graphite-900/6 md:hidden">
        <?php foreach ($rows as $row): $a = $rowActions[$row['id']]; ?>
            <li class="p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0"><?= ($columns[$primary]['render'])($row) ?></div>
                    <div class="relative shrink-0">
                        <button type="button" class="btn-ghost px-2" data-menu="#rowm-<?= (int) $row['id'] ?>" data-placement="bottom-end" aria-haspopup="menu" aria-expanded="false" aria-label="<?= e(__('Actions')) ?>"><?= icon('dots', 'size-5') ?></button>
                        <?= $rowMenu($row, $a, 'rowm-'.(int) $row['id']) ?>
                    </div>
                </div>
                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2.5 text-sm">
                    <?php foreach ($columns as $key => $col): if ($key === $primary) continue; ?>
                        <div class="min-w-0" data-col="<?= e($key) ?>" <?= in_array($key, $hidden, true) ? 'hidden' : '' ?>><dt class="text-xs text-steel"><?= e($col['label']) ?></dt><dd class="mt-0.5 min-w-0 break-words"><?= ($col['render'])($row) ?></dd></div>
                    <?php endforeach ?>
                </dl>
            </li>
        <?php endforeach ?>
    </ul>

    <!-- Tablets and up: table -->
    <div class="hidden overflow-x-auto md:block">
        <table class="table w-max min-w-full" data-table-el>
            <thead class="bg-white">
                <tr>
                    <th class="sticky left-0 z-[2] w-12 bg-white !px-2 shadow-[1px_0_0_rgba(23,24,27,.06)]"><span class="sr-only"><?= e(__('Actions')) ?></span></th>
                    <?php foreach ($columns as $key => $col): ?>
                    <th data-col="<?= e($key) ?>" <?= in_array($key, $hidden, true) && $key !== $primary ? 'hidden' : '' ?> aria-sort="<?= $sort === $key ? ($dir === 'desc' ? 'descending' : 'ascending') : 'none' ?>">
                        <?php if (! empty($col['sort'])): ?>
                            <a href="<?= e($sortUrl($key)) ?>" class="group flex items-center justify-between gap-2 whitespace-nowrap text-graphite-900 hover:text-signal-700"><?= e($col['label']) ?>
                                <?= icon($sort === $key ? ($dir === 'desc' ? 'sortdown' : 'sortup') : 'updown', 'size-3.5 shrink-0 '.($sort === $key ? 'text-signal-700' : 'text-graphite-400 group-hover:text-signal-700')) ?></a>
                        <?php else: ?><span class="whitespace-nowrap text-graphite-900"><?= e($col['label']) ?></span><?php endif ?>
                    </th>
                    <?php endforeach ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): $a = $rowActions[$row['id']]; ?>
                <tr class="group hover:bg-mist/40" data-row="<?= (int) $row['id'] ?>">
                    <td class="sticky left-0 z-[1] bg-white !px-2 shadow-[1px_0_0_rgba(23,24,27,.06)] group-hover:bg-[#f5f6f7]">
                        <div class="relative">
                            <button type="button" class="btn-ghost size-8 px-0" data-menu="#row-<?= (int) $row['id'] ?>" data-placement="bottom-start" aria-haspopup="menu" aria-expanded="false" aria-label="<?= e(__('Actions')) ?>"><?= icon('dots', 'size-5') ?></button>
                            <?= $rowMenu($row, $a, 'row-'.(int) $row['id']) ?>
                        </div>
                    </td>
                    <?php foreach ($columns as $key => $col): ?>
                        <td data-col="<?= e($key) ?>" class="whitespace-nowrap <?= e($tones[$col['tone'] ?? ''] ?? '') ?>" <?= in_array($key, $hidden, true) && $key !== $primary ? 'hidden' : '' ?>><?= ($col['render'])($row) ?></td>
                    <?php endforeach ?>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php if (! $rows): ?>
        <p class="px-5 py-14 text-center text-sm text-steel">
            <?= e($searching ? __('Nothing matches these filters.') : __('No :items yet.', ['items' => mb_strtolower($plural)])) ?>
            <?php if (! $ro && ! $searching && can($res, 'create')): ?><a href="<?= url($c['base'].'/create') ?>" class="ml-1 font-medium text-signal-700 hover:underline"><?= e(__('Add the first one')) ?></a><?php endif ?>
        </p>
    <?php endif ?>
    <?= partial('partials/pagination', compact('total', 'perPage', 'page')) ?>
    </div>
</section>
