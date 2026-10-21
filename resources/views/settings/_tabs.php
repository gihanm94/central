<?php $crumbs = [[__('Company settings'), '/settings']]; ?>
<h1 class="page-title"><?= e(__('Company settings')) ?></h1>
<?= partial('partials/tabs', ['tabs' => array_merge([['/settings', __('Branding & language'), 'photo'], ['/settings/notifications', __('Notifications'), 'bell']],
    auth()->isAdmin() ? [['/settings/powerbi', __('Power BI'), 'chart']] : [])]) ?>
