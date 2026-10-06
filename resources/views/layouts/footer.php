<footer class="hidden flex-wrap items-center justify-between gap-2 border-t border-graphite-900/8 px-4 py-4 text-xs text-steel sm:flex sm:px-6 lg:px-8">
    <span>&copy; <?= date('Y') ?> <?= e($branding['name']) ?></span>
    <?php if ($branding['support']): ?><a href="mailto:<?= e($branding['support']) ?>" class="hover:text-graphite-900"><?= e(__('Need help?')) ?> <?= e($branding['support']) ?></a><?php endif ?>
</footer>
