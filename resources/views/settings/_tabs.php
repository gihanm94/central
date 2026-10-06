<?php $crumbs = [[__('Company settings'), '/settings']]; ?>
<h1 class="page-title"><?= e(__('Company settings')) ?></h1>
<?= partial('partials/tabs', ['tabs' => [['/settings', __('Branding & language'), 'photo'], ['/settings/notifications', __('Notifications'), 'bell'], ['/settings/mail-log', __('Mail log'), 'mail']]]) ?>
