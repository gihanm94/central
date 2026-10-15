<?php /* $t, $db, $dbName, $info (columns, rows), $hidden, $access {roles, users}, $roles, $users, $syncs */
use App\Modules\Sheets\Support\Catalog;
$open = filter_var($t['enabled'], FILTER_VALIDATE_BOOL);
?>
<div class="mx-auto max-w-4xl">
<a href="<?= url('/sheets/tables', ['db' => $db]) ?>" class="text-sm text-steel hover:text-graphite-900">← <?= e(__('Tables & access')) ?> · <?= e($dbName) ?></a>
<h1 class="page-title mt-1"><?= e(Catalog::label($t)) ?> <span class="font-mono text-base font-normal text-steel"><?= e($db.'.'.$t['table_name']) ?></span></h1>
<p class="mt-1 text-sm text-steel"><?= e(__('About :n rows.', ['n' => number_format($info['rows'])])) ?> <?= e(__('People given this table get every row of it in their Google Sheet, whatever they may see on screen.')) ?></p>

<form method="POST" action="<?= url('/sheets/tables/save') ?>" class="mt-4 grid gap-4"><?= csrf_field() ?>
    <input type="hidden" name="db" value="<?= e($db) ?>"><input type="hidden" name="table" value="<?= e($t['table_name']) ?>">
    <section class="panel">
        <div class="grid gap-4 p-5 sm:grid-cols-2">
            <label class="flex items-center gap-3 text-sm font-medium sm:col-span-2"><input type="checkbox" name="enabled" value="1" class="size-5 accent-signal-600" <?= $open ? 'checked' : '' ?>> <?= e(__('Open this table for Google Sheets')) ?></label>
            <div class="sm:col-span-2"><label class="label" for="f-label"><?= e(__('Name people see')) ?></label><input id="f-label" name="label" value="<?= e(old('label', $t['label'] ?? '')) ?>" class="input" placeholder="<?= e(ucfirst(str_replace('_', ' ', $t['table_name']))) ?>"></div>
        </div>
    </section>

    <div class="grid gap-4 md:grid-cols-2">
        <section class="panel">
            <div class="panel-head"><h2 class="panel-title"><?= e(__('Roles that may sync it')) ?></h2></div>
            <div class="grid gap-1.5 p-5 text-sm">
                <?php foreach ($roles as $r): ?>
                    <label class="flex items-center gap-2"><input type="checkbox" name="roles[]" value="<?= (int) $r['id'] ?>" class="size-4 accent-signal-600" <?= in_array((int) $r['id'], $access['roles'], true) ? 'checked' : '' ?>> <?= e($r['name']) ?></label>
                <?php endforeach ?>
                <p class="mt-2 text-xs text-steel"><?= e(__('Administrators can always sync open tables.')) ?></p>
            </div>
        </section>
        <section class="panel">
            <div class="panel-head"><h2 class="panel-title"><?= e(__('…and these people')) ?></h2><span class="text-xs text-steel" data-count></span></div>
            <div class="p-3"><input type="search" class="input" placeholder="<?= e(__('Filter people…')) ?>" oninput="var q=this.value.toLowerCase();this.parentNode.nextElementSibling.querySelectorAll('label').forEach(function(l){l.hidden=q&&l.textContent.toLowerCase().indexOf(q)<0})"></div>
            <div class="grid max-h-72 gap-1.5 overflow-y-auto px-5 pb-4 text-sm">
                <?php foreach ($users as $p): ?>
                    <label class="flex items-center gap-2"><input type="checkbox" name="users[]" value="<?= (int) $p['id'] ?>" class="size-4 accent-signal-600" <?= in_array((int) $p['id'], $access['users'], true) ? 'checked' : '' ?>>
                        <span class="min-w-0 truncate"><?= e($p['name']) ?> <span class="text-xs text-steel"><?= e($p['email'].' · '.$p['role']) ?></span></span></label>
                <?php endforeach ?>
            </div>
        </section>
    </div>

    <section class="panel">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Columns sent')) ?></h2><span class="text-xs text-steel"><?= e(__('Untick to keep a column out of Google Sheets')) ?></span></div>
        <div class="grid gap-1.5 p-5 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($info['columns'] as $col => $type): $secret = Catalog::secretColumn($col); ?>
                <label class="flex items-center gap-2 <?= $secret ? 'text-graphite-400' : '' ?>" <?= $secret ? 'title="'.e(__('Never sent: looks like a password, token or key')).'"' : '' ?>>
                    <input type="checkbox" name="columns[]" value="<?= e($col) ?>" class="size-4 accent-signal-600" <?= $secret ? 'disabled' : (in_array($col, $hidden, true) ? '' : 'checked') ?>>
                    <span class="font-mono text-xs"><?= e($col) ?></span> <span class="text-xs text-steel"><?= e($type) ?></span><?= $secret ? icon('lock', 'size-3.5') : '' ?></label>
            <?php endforeach ?>
        </div><div class="px-5 pb-3"><?= field_error('columns') ?></div>
    </section>

    <?php if ($syncs): ?>
    <section class="panel">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Current syncs of this table')) ?></h2><span class="text-xs text-steel"><?= count($syncs) ?></span></div>
        <ul class="divide-y divide-graphite-900/6 text-sm"><?php foreach ($syncs as $s): ?>
            <li class="px-5 py-2"><span class="font-medium"><?= e($s['name']) ?></span> → <?= e(($s['spreadsheet_name'] ?: '?').' / '.$s['sheet_tab']) ?><?= filter_var($s['active'], FILTER_VALIDATE_BOOL) ? '' : ' <span class="text-xs text-steel">('.e(__('paused')).')</span>' ?></li>
        <?php endforeach ?></ul>
    </section>
    <?php endif ?>

    <div class="flex justify-end gap-2"><a href="<?= url('/sheets/tables', ['db' => $db]) ?>" class="btn-ghost"><?= e(__('Cancel')) ?></a><button class="btn-primary"><?= e(__('Save')) ?></button></div>
</form>
</div>
