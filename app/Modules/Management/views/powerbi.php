<?php /* $url (Power BI report link, '' when not set), $isAdmin */ ?>
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1 class="page-title"><?= e(__('Power BI overview')) ?></h1>
    <div class="flex items-center gap-2">
        <?php if ($url): ?>
            <button type="button" class="btn-secondary" data-pbi-full><?= icon('expand', 'size-4') ?> <?= e(__('Full screen')) ?></button>
            <a href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer" class="btn-secondary" title="<?= e(__('Open in Power BI')) ?>"><?= icon('link', 'size-4') ?><span class="hidden sm:inline"> <?= e(__('Open in Power BI')) ?></span></a>
        <?php endif ?>
        <?php if ($isAdmin): ?><a href="<?= url('/settings/powerbi') ?>" class="btn-secondary" title="<?= e(__('Settings')) ?>"><?= icon('cog', 'size-4') ?><span class="hidden sm:inline"> <?= e(__('Settings')) ?></span></a><?php endif ?>
    </div>
</div>

<?php if (! $url): ?>
    <section class="panel mt-4 px-5 py-16 text-center">
        <p class="text-sm text-steel"><?= e(__('No Power BI report has been set yet.')) ?></p>
        <?php if ($isAdmin): ?><a href="<?= url('/settings/powerbi') ?>" class="btn-primary mt-4"><?= icon('cog', 'size-4') ?> <?= e(__('Add the report')) ?></a>
        <?php else: ?><p class="mt-1 text-sm text-steel"><?= e(__('Ask an administrator to add it.')) ?></p><?php endif ?>
    </section>
<?php else: ?>
    <!-- fills the page below the header: viewport minus header, title row and page padding -->
    <div class="mt-3 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-graphite-900/8" style="height: calc(100dvh - 9.5rem); min-height: 28rem" data-pbi-box>
        <iframe src="<?= e($url) ?>" title="<?= e(__('Power BI overview')) ?>" class="size-full border-0" allowfullscreen loading="eager" referrerpolicy="strict-origin-when-cross-origin"></iframe>
    </div>
    <script>
    (function () {
        var b = document.querySelector('[data-pbi-full]'), box = document.querySelector('[data-pbi-box]');
        if (b && box && box.requestFullscreen) b.addEventListener('click', function () { box.requestFullscreen(); }); else if (b) b.hidden = true;
    })();
    </script>
<?php endif ?>
