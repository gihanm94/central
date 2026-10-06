<?php $crumbs = [[__('Profile'), '/profile']]; ?>
<div class="flex flex-wrap items-center gap-4">
    <?= partial('partials/avatar', ['name' => $me->name, 'avatar' => $me->avatar, 'size' => 'size-14 sm:size-16', 'extra' => 'text-lg']) ?>
    <div class="min-w-0">
        <h1 class="page-title truncate"><?= e($me->name) ?></h1>
        <p class="truncate text-sm text-steel"><?= e($me->job_title ?? __($me->role_name)) ?> · <?= e($me->email) ?></p>
    </div>
</div>
<?= partial('partials/tabs', ['tabs' => [
    ['/profile', __('Profile'), 'user'], ['/profile/security', __('Security'), 'lock'], ['/profile/sessions', __('Sessions'), 'clock'], ['/profile/connectors', __('Connectors'), 'link'],
    ['/profile/preferences', __('Preferences'), 'sliders'], ['/profile/notifications', __('Notifications'), 'bell'],
]]) ?>
