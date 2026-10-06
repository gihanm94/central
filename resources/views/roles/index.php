<?php $resources = config('core.resources'); ?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
        <h1 class="page-title"><?= e(__('Roles & access')) ?></h1>
        <p class="mt-1 max-w-2xl text-sm text-steel"><?= e(__('A role decides what people can do and which records they see. You can still make exceptions for one person from their member page.')) ?></p>
    </div>
    <?php if (can('roles', 'create')): ?><a href="<?= url('/roles/create') ?>" class="btn-primary w-full sm:w-auto"><?= icon('plus', 'size-4') ?> <?= e(__('New role')) ?></a><?php endif ?>
</div>

<div class="mt-6 grid gap-4 lg:grid-cols-2">
    <?php foreach ($roles as $r): $s = $summary[$r['id']] ?? []; ?>
        <section class="panel flex flex-col">
            <div class="flex items-start justify-between gap-4 p-5">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="font-display text-2xl font-semibold"><?= e(__($r['name'])) ?></h2>
                        <?php if (filter_var($r['is_system'], FILTER_VALIDATE_BOOL)): ?><span class="badge bg-graphite-900/6 text-steel"><?= e(__('Built-in')) ?></span><?php endif ?>
                    </div>
                    <p class="mt-1 text-sm text-steel"><?= e($r['description'] ? __($r['description']) : '—') ?></p>
                </div>
                <div class="text-right"><p class="figure text-3xl"><?= (int) $r['users_count'] ?></p><p class="text-xs text-steel"><?= e(__('people')) ?></p></div>
            </div>
            <div class="flex flex-wrap items-center gap-2 px-5 text-xs text-steel"><?= e(__('Sees')) ?> <?= partial('partials/scope-badge', ['scope' => $r['data_scope']]) ?> · <?= e(__('rank :n', ['n' => (int) $r['level']])) ?></div>
            <div class="mt-4 flex-1 border-t border-graphite-900/6 px-5 py-3 text-xs leading-6 text-steel">
                <?php if ($r['slug'] === 'admin'): ?><?= e(__('Everything, always.')) ?>
                <?php else: $parts = []; foreach ($resources as $k => $l) { if (! empty($s[$k])) { $parts[] = '<span class="text-graphite-900">'.e(__($l)).'</span> '.e(implode(', ', array_map(fn ($a) => mb_strtolower(__(ucfirst($a))), $s[$k]))); } } ?>
                    <?= $parts ? implode(' &nbsp;·&nbsp; ', $parts) : e(__('No permissions yet.')) ?>
                <?php endif ?>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-graphite-900/8 px-5 py-3">
                <?php if (can('roles', 'delete') && ! filter_var($r['is_system'], FILTER_VALIDATE_BOOL)): ?>
                    <form method="POST" action="<?= url('/roles/'.$r['id'].'/delete') ?>" data-confirm="<?= e(__('Delete the role :name?', ['name' => $r['name']])) ?>" class="mr-auto"><?= csrf_field() ?><button class="btn-ghost px-2 text-signal-700" aria-label="<?= e(__('Delete')) ?>"><?= icon('trash', 'size-4') ?></button></form>
                <?php endif ?>
                <?php if (can('roles', 'edit')): ?><a href="<?= url('/roles/'.$r['id'].'/edit') ?>" class="btn-secondary py-1.5"><?= e(__('Edit role')) ?></a><?php endif ?>
                <a href="<?= url('/roles/'.$r['id'].'/permissions') ?>" class="btn-dark py-1.5"><?= icon('shield', 'size-4') ?> <?= e(__('Permissions')) ?></a>
            </div>
        </section>
    <?php endforeach ?>
</div>
