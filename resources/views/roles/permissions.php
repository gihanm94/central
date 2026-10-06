<?php $crumbs = [[__('Roles & access'), '/roles']]; $actions = config('core.actions'); $isAdmin = $role['slug'] === 'admin'; ?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
        <h1 class="page-title"><?= e($title) ?></h1>
        <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-steel"><?= e(__('Applies to everyone with this role. Records they see:')) ?> <?= partial('partials/scope-badge', ['scope' => $role['data_scope']]) ?></p>
    </div>
    <?php if ($canChange): ?>
        <div class="flex gap-2 text-sm">
            <button type="button" class="btn-ghost" data-check-all="#matrix" data-value="1"><?= e(__('Tick all')) ?></button>
            <button type="button" class="btn-ghost" data-check-all="#matrix" data-value="0"><?= e(__('Clear all')) ?></button>
        </div>
    <?php endif ?>
</div>
<?php if ($isAdmin): ?><p class="mt-6 rounded-md bg-graphite-900 px-4 py-3 text-sm text-white"><?= e(__('Admin always has every permission. This view is read-only.')) ?></p><?php endif ?>

<form method="POST" action="<?= url('/roles/'.$role['id'].'/permissions') ?>" class="mt-6">
    <?= csrf_field() ?>
    <fieldset id="matrix" <?= $canChange ? '' : 'disabled' ?> class="panel overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead class="bg-mist/60"><tr><th class="sticky left-0 z-10 bg-mist"><?= e(__('Area')) ?></th><?php foreach ($actions as $a): ?><th class="text-center"><?= e(__(ucfirst($a))) ?></th><?php endforeach ?></tr></thead>
                <tbody>
                <?php foreach ($perms as $p): $rp = $map[$p['id']] ?? []; ?>
                    <tr class="hover:bg-mist/40">
                        <td class="sticky left-0 z-10 bg-white font-medium"><?= e(__($p['label'])) ?></td>
                        <?php foreach ($actions as $a): $on = $isAdmin || filter_var($rp["can_{$a}"] ?? false, FILTER_VALIDATE_BOOL); ?>
                            <td class="text-center"><label class="inline-flex p-1.5"><input type="checkbox" name="perm[<?= e($p['key']) ?>][<?= $a ?>]" value="1" <?= $on ? 'checked' : '' ?> class="size-4 accent-signal-600" aria-label="<?= e(__($p['label']).' '.__($a)) ?>"></label></td>
                        <?php endforeach ?>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </fieldset>
    <?php if ($canChange): ?><div class="mt-4 flex justify-end gap-2"><a href="<?= url('/roles') ?>" class="btn-ghost"><?= e(__('Cancel')) ?></a><button class="btn-primary"><?= e(__('Save permissions')) ?></button></div><?php endif ?>
</form>
