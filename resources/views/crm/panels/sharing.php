<?php
/* Who can open this record. $c, $row, $shares, $canManage, $people [id => [name, department, department_id]], $departments [id => name] */
$base   = url($c['base'].'/'.$row['id']);
$owner  = (int) ($row['owner_id'] ?? 0);
$userOpts = [];
foreach ($people as $pid => $p) { if ($pid !== $owner) { $userOpts[$pid] = $p['name'].($p['department'] ? ' · '.$p['department'] : ''); } }
?>
<section id="sharing" class="panel">
    <div class="panel-head"><h2 class="panel-title"><?= e(__('Who can see this')) ?></h2></div>
    <ul class="divide-y divide-graphite-900/6 text-sm">
        <li class="flex items-start gap-3 px-5 py-3"><?= icon('building', 'mt-0.5 size-4 shrink-0 text-steel') ?>
            <span><?php if ($row['department_name']): ?><span class="font-medium"><?= e(__(':dept department', ['dept' => $row['department_name']])) ?></span><span class="block text-xs text-steel"><?= e(__('Everyone in it, by default.')) ?></span>
                <?php else: ?><span class="font-medium"><?= e(__('No department')) ?></span><span class="block text-xs text-steel"><?= e(__('Only the owner and the people below.')) ?></span><?php endif ?></span></li>
        <li class="flex items-start gap-3 px-5 py-3"><?= icon('shield', 'mt-0.5 size-4 shrink-0 text-steel') ?>
            <span><span class="font-medium"><?= e(__('Admin and management')) ?></span><span class="block text-xs text-steel"><?= e(__('Roles that see the whole company.')) ?></span></span></li>
        <?php foreach ($shares as $s): ?>
        <li class="flex items-center justify-between gap-3 px-5 py-3">
            <span class="flex min-w-0 items-center gap-3"><?= icon($s['target_type'] === 'user' ? 'user' : 'team', 'size-4 shrink-0 text-steel') ?>
                <span class="min-w-0"><span class="block truncate font-medium"><?= e($s['target_name']) ?></span><span class="block text-xs text-steel"><?= e($s['target_type'] === 'user' ? __('Person') : __('Department')) ?></span></span></span>
            <span class="flex shrink-0 items-center gap-1">
                <span class="badge <?= $s['access'] === 'edit' ? 'bg-amber-50 text-amber-800' : 'bg-graphite-900/6 text-graphite-800' ?>"><?= e($s['access'] === 'edit' ? __('Can edit') : __('View only')) ?></span>
                <?php if ($canManage): ?>
                <form method="POST" action="<?= $base ?>/shares/<?= (int) $s['id'] ?>/delete" data-confirm="<?= e(__('Remove access for :who?', ['who' => $s['target_name']])) ?>"><?= csrf_field() ?>
                    <button class="btn-ghost px-1.5 py-1 hover:text-signal-700" title="<?= e(__('Remove access')) ?>" aria-label="<?= e(__('Remove access for :who', ['who' => $s['target_name']])) ?>"><?= icon('x', 'size-4') ?></button></form>
                <?php endif ?>
            </span>
        </li>
        <?php endforeach ?>
    </ul>
    <?php if ($canManage): ?>
    <form method="POST" action="<?= $base ?>/shares" class="space-y-3 border-t border-graphite-900/8 p-5">
        <?= csrf_field() ?>
        <p class="text-sm font-medium"><?= e(__('Share with someone else')) ?></p>
        <div class="inline-flex rounded-md bg-mist p-0.5 text-sm" role="radiogroup" aria-label="<?= e(__('Share with')) ?>">
            <label class="cursor-pointer"><input type="radio" name="target_type" value="user" class="peer sr-only" checked><span class="block rounded px-3 py-1 peer-checked:bg-white peer-checked:font-medium peer-checked:shadow-sm"><?= e(__('A person')) ?></span></label>
            <label class="cursor-pointer"><input type="radio" name="target_type" value="department" class="peer sr-only"><span class="block rounded px-3 py-1 peer-checked:bg-white peer-checked:font-medium peer-checked:shadow-sm"><?= e(__('A department')) ?></span></label>
        </div>
        <div data-crm-show="target_type=user">
            <?= select_field('user_id', $userOpts, '', ['placeholder' => __('Choose a person…'), 'id' => 'share-user', 'aria' => __('Person')]) ?>
            <?= field_error('user_id') ?>
        </div>
        <div data-crm-show="target_type=department" hidden>
            <?= select_field('department_id', $departments, '', ['placeholder' => __('Choose a department…'), 'id' => 'share-dept', 'aria' => __('Department')]) ?>
            <?= field_error('department_id') ?>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <div class="inline-flex rounded-md bg-mist p-0.5 text-sm" role="radiogroup" aria-label="<?= e(__('Access level')) ?>">
                <label class="cursor-pointer"><input type="radio" name="access" value="view" class="peer sr-only" checked><span class="block rounded px-3 py-1 peer-checked:bg-white peer-checked:font-medium peer-checked:shadow-sm"><?= e(__('View only')) ?></span></label>
                <label class="cursor-pointer"><input type="radio" name="access" value="edit" class="peer sr-only"><span class="block rounded px-3 py-1 peer-checked:bg-white peer-checked:font-medium peer-checked:shadow-sm"><?= e(__('Can edit')) ?></span></label>
            </div>
            <button class="btn-dark ml-auto"><?= icon('plus', 'size-4') ?> <?= e(__('Share')) ?></button>
        </div>
    </form>
    <?php endif ?>
</section>
