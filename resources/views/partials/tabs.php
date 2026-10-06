<?php /* $tabs: [[url, label, icon]] — underline tabs that scroll sideways on phones */ $here = App\Core\Support\Request::path(); ?>
<nav class="-mx-4 mt-5 overflow-x-auto border-b border-graphite-900/10 px-4 sm:mx-0 sm:px-0" aria-label="<?= e(__('Sections')) ?>">
    <div class="flex min-w-max gap-1">
        <?php foreach ($tabs as [$href, $label, $ic]): $on = $here === $href; ?>
            <a href="<?= url($href) ?>" <?= $on ? 'aria-current="page"' : '' ?> class="-mb-px flex items-center gap-2 border-b-2 px-3 py-2.5 text-sm <?= $on ? 'border-signal-600 font-medium text-graphite-900' : 'border-transparent text-steel hover:text-graphite-900' ?>">
                <?= icon($ic, 'size-4') ?><?= e($label) ?>
            </a>
        <?php endforeach ?>
    </div>
</nav>
