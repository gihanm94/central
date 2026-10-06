<?php include __DIR__.'/_header.php'; $p = $profile; $crumbs = []; ?>
<div class="mt-6 grid items-start gap-6 xl:grid-cols-3">
    <form method="POST" action="<?= url('/profile') ?>" enctype="multipart/form-data" class="panel xl:col-span-2">
        <?= csrf_field() ?>
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Personal details')) ?></h2></div>
        <div class="grid gap-5 p-4 sm:grid-cols-2 sm:p-5">
            <div class="flex items-center gap-4 sm:col-span-2">
                <img id="avatar-preview" src="<?= e($me->avatarUrl() ?? '') ?>" alt="" <?= $me->avatar ? '' : 'hidden' ?> class="size-14 rounded-full object-cover">
                <div class="min-w-0">
                    <label for="avatar" class="label"><?= e(__('Photo')) ?></label>
                    <input id="avatar" name="avatar" type="file" accept="image/png,image/jpeg,image/webp" data-preview="#avatar-preview" class="w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-graphite-900 file:px-3 file:py-1.5 file:text-sm file:text-white">
                    <p class="hint"><?= e(__('JPG, PNG or WebP, up to 2 MB.')) ?></p>
                    <?= field_error('avatars') ?>
                    <?php if ($me->avatar): ?><label class="mt-1 flex items-center gap-2 text-xs text-steel"><input type="checkbox" name="remove_avatar" value="1" class="accent-signal-600"> <?= e(__('Remove current photo')) ?></label><?php endif ?>
                </div>
            </div>
            <?php foreach ([
                'name' => [__('Full name'), 'text', $me->name, 2], 'phone' => [__('Phone'), 'tel', $p['phone'] ?? '', 1],
                'date_of_birth' => [__('Date of birth'), 'date', $p['date_of_birth'] ?? '', 1],
            ] as $name => [$label, $type, $val, $span]): ?>
                <div class="<?= $span === 2 ? 'sm:col-span-2' : '' ?>">
                    <label for="p-<?= $name ?>" class="label"><?= e($label) ?></label>
                    <input id="p-<?= $name ?>" name="<?= $name ?>" type="<?= $type ?>" value="<?= e(old($name, $val)) ?>" class="input <?= error_for($name) ? 'input-error' : '' ?>">
                    <?= field_error($name) ?>
                </div>
            <?php endforeach ?>
            <div>
                <label for="p-gender" class="label"><?= e(__('Gender')) ?></label>
                <?= select_field('gender', enum_options('genders'), old('gender', $p['gender'] ?? ''), ['id' => 'p-gender', 'placeholder' => __('Choose…')]) ?>
                <?= field_error('gender') ?>
            </div>
            <?php foreach ([
                'address' => [__('Address'), 'text', $p['address'] ?? '', 2],
                'emergency_contact_name' => [__('Emergency contact'), 'text', $p['emergency_contact_name'] ?? '', 1],
                'emergency_contact_phone' => [__('Emergency contact phone'), 'tel', $p['emergency_contact_phone'] ?? '', 1],
            ] as $name => [$label, $type, $val, $span]): ?>
                <div class="<?= $span === 2 ? 'sm:col-span-2' : '' ?>">
                    <label for="p-<?= $name ?>" class="label"><?= e($label) ?></label>
                    <input id="p-<?= $name ?>" name="<?= $name ?>" type="<?= $type ?>" value="<?= e(old($name, $val)) ?>" class="input">
                    <?= field_error($name) ?>
                </div>
            <?php endforeach ?>
        </div>
        <div class="flex justify-end border-t border-graphite-900/8 px-4 py-3 sm:px-5"><button class="btn-primary w-full sm:w-auto"><?= e(__('Save details')) ?></button></div>
    </form>

    <aside class="panel">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Employment')) ?></h2></div>
        <dl class="divide-y divide-graphite-900/6 text-sm">
            <?php foreach ([
                __('Employee code') => $p['employee_code'] ?? null, __('Job title') => $p['job_title'] ?? null,
                __('Employee level') => isset($p['employee_level']) ? enum_label('levels', $p['employee_level']) : null,
                __('Employment status') => enum_label('statuses', $p['employment_status'] ?? null),
                __('Employment type') => enum_label('types', $p['employment_type'] ?? null),
                __('Role') => __($me->role_name), __('Department') => $me->department_name, __('Team') => $me->team_name,
                __('Reports to') => $manager, __('Joined') => isset($p['joined_at']) ? format_date($p['joined_at']) : null,
            ] as $label => $value): ?>
                <div class="flex justify-between gap-4 px-5 py-2.5"><dt class="text-steel"><?= e($label) ?></dt><dd class="text-right font-medium"><?= e($value ?: '—') ?></dd></div>
            <?php endforeach ?>
            <div class="flex items-center justify-between gap-4 px-5 py-2.5"><dt class="text-steel"><?= e(__('Data you can see')) ?></dt><dd><?= partial('partials/scope-badge', ['scope' => $me->scope()]) ?></dd></div>
        </dl>
        <p class="border-t border-graphite-900/8 px-5 py-3 text-xs text-steel"><?= e(__('Something wrong here? Ask your administrator to update it.')) ?></p>
    </aside>
</div>
