<div class="min-w-0">
    <h1 class="page-title"><?= e(__('Accounting overview')) ?></h1>
    <p class="mt-1 text-sm text-steel"><?= e(__('The dashboard is empty for now.')) ?></p>
</div>
<?php if (auth()->isAdmin()): ?>
<div class="mt-6 rounded-xl bg-white p-6 text-sm shadow-sm ring-1 ring-graphite-900/8">
    <p class="font-medium"><?= e(__('Start with the ERP connection')) ?></p>
    <p class="mt-1 text-steel"><?= e(__('Enter the ERP address and login, check every API, and let the schedule copy the data into this system.')) ?></p>
    <a href="<?= url('/accounting/erp') ?>" class="btn-primary mt-3"><?= icon('cog', 'size-4') ?> <?= e(__('Open ERP connection')) ?></a>
</div>
<?php endif ?>
