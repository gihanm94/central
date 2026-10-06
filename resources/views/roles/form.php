<?php $crumbs = [[__('Roles & access'), '/roles']]; $isAdmin = $role && $role['slug'] === 'admin'; ?>
<h1 class="page-title"><?= e($title) ?></h1>
<form method="POST" action="<?= url($role ? '/roles/'.$role['id'] : '/roles') ?>" class="panel mt-6 max-w-2xl">
    <?= csrf_field() ?>
    <div class="grid gap-5 p-4 sm:grid-cols-2 sm:p-6">
        <div class="sm:col-span-2"><label for="name" class="label"><?= e(__('Role name')) ?></label><input id="name" name="name" value="<?= e(old('name', $role['name'] ?? '')) ?>" required class="input"><?= field_error('name') ?></div>
        <div class="sm:col-span-2"><label for="description" class="label"><?= e(__('What this role is for')) ?> <span class="font-normal text-steel">(<?= e(__('optional')) ?>)</span></label><input id="description" name="description" value="<?= e(old('description', $role['description'] ?? '')) ?>" class="input"></div>
        <div>
            <label for="data_scope" class="label"><?= e(__('Records they can see')) ?></label>
            <?php if ($isAdmin): ?>
                <input type="hidden" name="data_scope" value="all"><p class="input bg-mist"><?= e(__('Whole company')) ?></p>
            <?php else: ?>
                <?= select_field('data_scope', array_map(fn ($l) => __($l), config('core.scopes')), old('data_scope', $role['data_scope'] ?? 'own'), ['id' => 'data_scope', 'required' => true]) ?>
            <?php endif ?>
        </div>
        <div>
            <label for="level" class="label"><?= e(__('Rank (1–99)')) ?></label>
            <input id="level" name="level" type="number" min="1" max="99" inputmode="numeric" value="<?= e(old('level', $role['level'] ?? 20)) ?>" class="input" <?= $isAdmin ? 'readonly' : '' ?>>
            <p class="hint"><?= e(__('People can only manage members with a lower rank. Management 80, BU Manager 60, Manager 40, Member 10.')) ?></p>
        </div>
    </div>
    <div class="flex justify-end gap-2 border-t border-graphite-900/8 px-4 py-3 sm:px-6"><a href="<?= url('/roles') ?>" class="btn-ghost"><?= e(__('Cancel')) ?></a><button class="btn-primary"><?= e($role ? __('Save role') : __('Create role')) ?></button></div>
</form>
