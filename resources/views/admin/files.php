<?php
use App\Core\Support\Files;
/* $roots, $root, $path, $rows, $total, $perPage, $page, $q, $folders, $sort, $dir */
$trail = [];
$acc = '';
foreach (array_filter(explode('/', $path)) as $seg) { $acc .= ($acc === '' ? '' : '/').$seg; $trail[$seg.'|'.$acc] = $acc; }
$u = fn (array $extra = []) => url('/files', array_filter(['root' => $root, 'path' => $path, 'q' => $q, 'sort' => $sort !== 'name' ? $sort : null, 'dir' => $dir === 'desc' ? 'desc' : null, 'per_page' => $perPage !== 20 ? $perPage : null] + $extra, fn ($v) => $v !== null && $v !== ''));
$sortLink = function (string $col, string $label) use ($u, $sort, $dir) {
    $next = $sort === $col && $dir === 'asc' ? 'desc' : 'asc';

    return '<a href="'.e($u(['sort' => $col === 'name' ? null : $col, 'dir' => $next === 'desc' ? 'desc' : null, 'page' => null])).'" class="inline-flex items-center gap-1 hover:text-signal-700">'.e($label).($sort === $col ? ($dir === 'asc' ? ' ▲' : ' ▼') : '').'</a>';
};
$icon = fn (array $r) => $r['dir'] ? 'folder' : (in_array($r['ext'], ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true) ? 'eye' : 'doc');
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0"><h1 class="page-title"><?= e(__('File manager')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(__('The files the system keeps on the server. Only administrators see this.')) ?></p></div>
    <div class="flex flex-wrap gap-2">
        <form method="POST" action="<?= url('/files/upload') ?>" enctype="multipart/form-data" id="fm-upload" class="inline"><?= csrf_field() ?><input type="hidden" name="root" value="<?= e($root) ?>"><input type="hidden" name="path" value="<?= e($path) ?>">
            <label class="btn-secondary cursor-pointer"><?= icon('upload', 'size-4') ?> <?= e(__('Upload')) ?><input type="file" name="files[]" multiple class="sr-only" onchange="this.form.submit()"></label></form>
        <button type="button" class="btn-primary" data-fm-open="mkdir"><?= icon('folder', 'size-4') ?> <?= e(__('New folder')) ?></button>
    </div>
</div>
<nav class="mt-4 flex flex-wrap gap-1.5" aria-label="<?= e(__('Folders')) ?>">
    <?php foreach ($roots as $k => $r): ?><a href="<?= url('/files', ['root' => $k]) ?>" class="rounded-full px-3.5 py-1.5 text-sm ring-1 <?= $k === $root ? 'bg-graphite-900 text-white ring-graphite-900' : 'bg-white text-graphite-800 ring-graphite-900/15 hover:bg-mist' ?>"><?= e($r['label']) ?></a><?php endforeach ?>
</nav>
<section class="panel mt-3 overflow-hidden">
    <div class="flex flex-wrap items-center gap-3 border-b border-graphite-900/8 p-3">
        <nav class="flex min-w-0 flex-1 flex-wrap items-center gap-1 text-sm" aria-label="<?= e(__('Path')) ?>"><a href="<?= url('/files', ['root' => $root]) ?>" class="font-medium hover:text-signal-700"><?= e($roots[$root]['label']) ?></a>
            <?php foreach ($trail as $k => $rel): [$seg] = explode('|', $k, 2); ?><span class="text-graphite-400">/</span><a href="<?= url('/files', ['root' => $root, 'path' => $rel]) ?>" class="hover:text-signal-700"><?= e($seg) ?></a><?php endforeach ?></nav>
        <form method="GET" action="<?= url('/files') ?>" class="flex items-center gap-2"><input type="hidden" name="root" value="<?= e($root) ?>"><?php if ($path !== ''): ?><input type="hidden" name="path" value="<?= e($path) ?>"><?php endif ?>
            <input name="q" value="<?= e($q) ?>" class="input !h-9 w-56" placeholder="<?= e(__('Search by name…')) ?>"><button class="btn-secondary !h-9"><?= e(__('Search')) ?></button><?php if ($q !== ''): ?><a href="<?= e($u(['q' => null])) ?>" class="btn-ghost !h-9"><?= e(__('Clear')) ?></a><?php endif ?></form>
    </div>
    <div data-bulk-bar hidden class="flex flex-wrap items-center gap-3 border-b border-graphite-900/8 bg-signal-50/60 px-4 py-2 text-sm">
        <span><span data-bulk-n class="font-semibold">0</span> <?= e(__('selected')) ?></span>
        <button type="button" class="btn-secondary py-1" data-fm-move-selected><?= e(__('Move to…')) ?></button>
        <button type="button" class="btn bg-signal-600 py-1 text-white hover:bg-signal-700" data-bulk-delete data-url="<?= e(url('/files/delete', ['root' => $root, 'path' => $path])) ?>" data-kind="<?= e(__('files or folders')) ?>"><?= icon('trash', 'size-4') ?> <?= e(__('Delete selected')) ?></button>
        <button type="button" class="btn-ghost py-1" data-bulk-clear><?= e(__('Clear')) ?></button>
    </div>
    <div class="overflow-x-auto"><table class="table w-full min-w-[40rem]">
        <thead class="bg-white"><tr>
            <th class="w-24 !px-3"><input type="checkbox" data-sel-all class="size-4 accent-signal-600" aria-label="<?= e(__('Select all')) ?>"></th>
            <th><?= $sortLink('name', __('Name')) ?></th><th><?= $sortLink('ext', __('Type')) ?></th><th class="text-right"><?= $sortLink('size', __('Size')) ?></th><th><?= $sortLink('mtime', __('Modified')) ?></th>
        </tr></thead>
        <tbody>
        <?php if ($path !== '' && $q === ''): ?><tr><td></td><td colspan="4"><a href="<?= url('/files', ['root' => $root, 'path' => str_contains($path, '/') ? dirname($path) : null]) ?>" class="inline-flex items-center gap-2 text-steel hover:text-signal-700">↰ <?= e(__('Up one level')) ?></a></td></tr><?php endif ?>
        <?php foreach ($rows as $r): ?>
            <tr class="group hover:bg-mist/40">
                <td class="!px-3"><div class="flex items-center gap-1.5"><input type="checkbox" data-sel="<?= e($r['rel']) ?>" class="size-4 accent-signal-600" aria-label="<?= e(__('Select')) ?>">
                    <button type="button" class="btn-ghost size-8 px-0" data-menu="#fm-m-<?= e(md5($r['rel'])) ?>" data-placement="bottom-start" aria-haspopup="menu" aria-expanded="false" aria-label="<?= e(__('Actions')) ?>"><?= icon('dots', 'size-5') ?></button>
                    <div id="fm-m-<?= e(md5($r['rel'])) ?>" data-menu-panel hidden role="menu" class="fixed z-[70] w-44 rounded-lg bg-white p-1.5 text-sm shadow-xl ring-1 ring-graphite-900/10">
                        <?php if (! $r['dir']): ?><a role="menuitem" href="<?= e(url('/files/download', ['root' => $root, 'path' => $r['rel']])) ?>" class="flex items-center gap-2.5 rounded-md px-2.5 py-1.5 hover:bg-mist"><?= icon('download', 'size-4 text-steel') ?> <?= e(__('Download')) ?></a><?php endif ?>
                        <button type="button" role="menuitem" data-fm-open="rename" data-item="<?= e($r['rel']) ?>" data-name="<?= e($r['name']) ?>" class="flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left hover:bg-mist"><?= icon('pencil', 'size-4 text-steel') ?> <?= e(__('Rename')) ?></button>
                        <button type="button" role="menuitem" data-fm-open="move" data-item="<?= e($r['rel']) ?>" class="flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left hover:bg-mist"><?= icon('right', 'size-4 text-steel') ?> <?= e(__('Move to…')) ?></button>
                        <hr class="my-1 border-graphite-900/8">
                        <button type="button" role="menuitem" data-delete-url="<?= e(url('/files/delete', ['root' => $root, 'path' => $path, 'item' => $r['rel']])) ?>" data-delete-name="<?= e($r['name']) ?>" data-delete-kind="<?= e($r['dir'] ? __('folder') : __('file')) ?>" class="flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left text-signal-700 hover:bg-signal-50"><?= icon('trash', 'size-4') ?> <?= e(__('Delete')) ?></button>
                    </div></div></td>
                <td><?php if ($r['dir']): ?><a href="<?= url('/files', ['root' => $root, 'path' => $r['rel']]) ?>" class="inline-flex items-center gap-2 font-medium hover:text-signal-700"><?= icon('folder', 'size-4 text-amber-500') ?> <?= e($r['name']) ?></a>
                    <?php else: ?><a href="<?= e(url('/files/download', ['root' => $root, 'path' => $r['rel']])) ?>" class="inline-flex items-center gap-2 hover:text-signal-700"><?= icon($icon($r), 'size-4 text-steel') ?> <?= e($r['name']) ?></a><?php endif ?>
                    <?php if ($q !== '' && $r['parent'] !== ''): ?><span class="block text-xs text-steel"><?= e($r['parent']) ?></span><?php endif ?></td>
                <td class="text-steel"><?= $r['dir'] ? e(__('Folder')) : e(strtoupper($r['ext']) ?: '—') ?></td>
                <td class="text-right tabular-nums text-steel"><?= $r['dir'] ? '—' : e(Files::size((int) $r['size'])) ?></td>
                <td class="whitespace-nowrap text-steel"><?= e(date('d M Y, H:i', (int) $r['mtime'])) ?></td>
            </tr>
        <?php endforeach ?>
        <?php if (! $rows): ?><tr><td colspan="5" class="px-4 py-10 text-center text-steel"><?= e($q !== '' ? __('Nothing matches your search.') : __('This folder is empty.')) ?></td></tr><?php endif ?>
        </tbody></table></div>
    <?= partial('partials/pagination', ['total' => $total, 'perPage' => $perPage, 'page' => $page]) ?>
</section>

<dialog id="fm-dlg" class="m-auto w-[min(26rem,calc(100vw-2rem))] rounded-xl bg-white p-0 shadow-2xl ring-1 ring-graphite-900/10 backdrop:bg-graphite-950/60">
    <form method="POST" data-fm-form class="p-5"><?= csrf_field() ?><input type="hidden" name="root" value="<?= e($root) ?>"><input type="hidden" name="path" value="<?= e($path) ?>"><div data-fm-items></div>
        <h2 class="text-base font-semibold" data-fm-title></h2>
        <div data-fm-name class="mt-4" hidden><label class="label" for="fm-name"><?= e(__('Name')) ?></label><input id="fm-name" name="name" class="input" maxlength="180" autocomplete="off"></div>
        <div data-fm-dest class="mt-4" hidden><label class="label" for="fm-dest"><?= e(__('Move to')) ?></label><select id="fm-dest" name="dest" class="input"><?php foreach ($folders as $fo): ?><option value="<?= e($fo) ?>"><?= e($fo === '' ? '/ ('.__('top folder').')' : '/'.$fo) ?></option><?php endforeach ?></select></div>
        <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn-secondary" data-fm-cancel><?= e(__('Cancel')) ?></button><button class="btn-primary" data-fm-ok><?= e(__('Save')) ?></button></div>
    </form>
</dialog>
<script>
(function () {
    var dlg = document.getElementById('fm-dlg'), form = dlg.querySelector('[data-fm-form]'), items = dlg.querySelector('[data-fm-items]'),
        L = { mkdir: <?= json_encode(__('New folder')) ?>, rename: <?= json_encode(__('Rename')) ?>, move: <?= json_encode(__('Move to…')) ?> };
    function open(kind, list, name) {
        form.action = <?= json_encode(url('/files/')) ?> + kind;
        dlg.querySelector('[data-fm-title]').textContent = L[kind];
        dlg.querySelector('[data-fm-name]').hidden = kind === 'move'; dlg.querySelector('[data-fm-dest]').hidden = kind !== 'move';
        dlg.querySelector('#fm-name').disabled = kind === 'move'; dlg.querySelector('#fm-name').value = name || '';
        items.innerHTML = list.map(function (i) { return '<input type="hidden" name="' + (kind === 'move' ? 'items[]' : 'item') + '" value="' + i.replace(/"/g, '&quot;') + '">'; }).join('');
        dlg.showModal(); if (kind !== 'move') { var n = dlg.querySelector('#fm-name'); n.focus(); n.select(); }
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-fm-open]');
        if (b) { var k = b.dataset.fmOpen; open(k, k === 'mkdir' ? [] : [b.dataset.item], b.dataset.name); return; }
        if (e.target.closest('[data-fm-move-selected]')) { var sel = Array.prototype.map.call(document.querySelectorAll('[data-sel]:checked'), function (c) { return c.dataset.sel; }); if (sel.length) open('move', sel); return; }
        if (e.target.closest('[data-fm-cancel]') || e.target === dlg) dlg.close();
    });
})();
</script>
