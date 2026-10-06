<?php
$crumbs ??= [];
$locales = App\Core\Support\I18n::available();
?>
<header class="sticky top-0 z-20 flex h-14 items-center gap-2 border-b border-graphite-900/8 bg-white/90 px-3 backdrop-blur sm:h-16 sm:gap-3 sm:px-6 lg:px-8">
    <button type="button" data-sidebar-toggle class="btn-ghost -ml-1 shrink-0 px-2" aria-label="<?= e(__('Toggle sidebar')) ?>" title="<?= e(__('Toggle sidebar')) ?>">
        <?= icon('panel') ?>
    </button>
    <span class="h-5 w-px shrink-0 bg-graphite-900/15" aria-hidden="true"></span>

    <nav class="flex min-w-0 flex-1 items-center gap-1.5 text-sm" aria-label="<?= e(__('Breadcrumb')) ?>">
        <a href="<?= url($modules[$current]['home']) ?>" class="hidden shrink-0 text-steel hover:text-graphite-900 sm:inline"><?= e(__($modules[$current]['name'])) ?></a>
        <?php foreach ($crumbs as [$label, $href]): ?>
            <?= icon('right', 'hidden size-3.5 shrink-0 text-graphite-400 sm:block') ?>
            <a href="<?= url($href) ?>" class="hidden shrink-0 text-steel hover:text-graphite-900 md:inline"><?= e($label) ?></a>
        <?php endforeach ?>
        <?php if (! empty($title)): ?>
            <?= icon('right', 'hidden size-3.5 shrink-0 text-graphite-400 sm:block') ?>
            <span class="truncate font-medium text-graphite-900" aria-current="page"><?= e($title) ?></span>
        <?php endif ?>
    </nav>

    <!-- Display size -->
    <div class="relative hidden shrink-0 sm:block">
        <button type="button" data-menu="#scale-menu" data-placement="bottom-end" aria-haspopup="menu" aria-expanded="false" class="btn-ghost gap-1.5 px-2 text-xs font-semibold" title="<?= e(__('Display size')) ?>">
            <?= icon('zoom', 'size-[18px]') ?><span class="hidden md:inline tabular-nums" data-scale-label><?= (int) auth()->ui_scale ?>%</span>
        </button>
        <div id="scale-menu" data-url="<?= e(url('/profile/scale')) ?>" data-menu-panel hidden role="menu" class="fixed z-[70] w-60 rounded-lg bg-white p-3 shadow-xl ring-1 ring-graphite-900/10">
            <p class="text-sm font-medium"><?= e(__('Display size')) ?></p>
            <p class="mt-0.5 text-xs text-steel"><?= e(__('Make everything smaller or larger. Saved for you.')) ?></p>
            <div class="mt-3 grid grid-cols-5 gap-1" role="group" aria-label="<?= e(__('Display size')) ?>">
                <?php foreach ([80, 90, 100, 110, 125] as $pct): ?>
                    <button type="button" data-scale="<?= $pct ?>" class="h-8 rounded-md text-xs font-medium ring-1 ring-graphite-900/15 hover:bg-mist <?= (int) auth()->ui_scale === $pct ? 'bg-graphite-900 text-white ring-graphite-900 hover:bg-graphite-900' : '' ?>"><?= $pct ?>%</button>
                <?php endforeach ?>
            </div>
            <p class="mt-2 text-xs text-steel"><kbd class="rounded bg-mist px-1">Ctrl</kbd> + <kbd class="rounded bg-mist px-1">+</kbd> / <kbd class="rounded bg-mist px-1">−</kbd> <?= e(__('also works in your browser.')) ?></p>
        </div>
    </div>

    <!-- Language -->
    <div class="relative shrink-0">
        <button type="button" data-menu="#lang-menu" data-placement="bottom-end" aria-haspopup="menu" aria-expanded="false" class="btn-ghost gap-1.5 px-2 text-xs font-semibold uppercase" title="<?= e(__('Language')) ?>">
            <?= icon('globe', 'size-[18px]') ?><span class="hidden sm:inline"><?= e(locale()) ?></span>
        </button>
        <div id="lang-menu" data-menu-panel hidden role="menu" class="fixed z-[70] w-44 rounded-lg bg-white p-1.5 shadow-xl ring-1 ring-graphite-900/10">
            <?php foreach ($locales as $code => $name): ?>
                <a role="menuitem" href="<?= url('/lang/'.$code) ?>" class="flex items-center justify-between rounded-md px-2.5 py-1.5 text-sm hover:bg-mist <?= $code === locale() ? 'font-medium' : '' ?>">
                    <?= e($name) ?><?php if ($code === locale()): ?><?= icon('tick', 'size-4 text-signal-600') ?><?php endif ?>
                </a>
            <?php endforeach ?>
        </div>
    </div>

    <!-- Account -->
    <div class="relative shrink-0">
        <button type="button" data-menu="#user-menu" data-placement="bottom-end" aria-expanded="false" aria-haspopup="menu" class="flex items-center gap-2 rounded-full p-0.5 pr-1 hover:bg-graphite-900/5 sm:pr-2">
            <?= partial('partials/avatar', ['name' => auth()->name, 'avatar' => auth()->avatar, 'size' => 'size-8']) ?>
            <span class="hidden max-w-32 truncate text-sm font-medium md:block"><?= e(auth()->firstName()) ?></span>
        </button>
        <div id="user-menu" data-menu-panel hidden role="menu" class="fixed z-[70] w-64 rounded-lg bg-white p-1.5 shadow-xl ring-1 ring-graphite-900/10">
            <div class="border-b border-graphite-900/8 px-3 pb-2.5 pt-1.5">
                <p class="truncate text-sm font-medium"><?= e(auth()->name) ?></p>
                <p class="truncate text-xs text-steel"><?= e(auth()->email) ?></p>
            </div>
            <?php foreach ([['/profile', 'user', 'Profile'], ['/profile/security', 'lock', 'Security'], ['/profile/preferences', 'sliders', 'Preferences'], ['/profile/notifications', 'bell', 'My notifications']] as [$href, $ic, $lbl]): ?>
                <a role="menuitem" href="<?= url($href) ?>" class="mt-0.5 flex items-center gap-2.5 rounded-md px-3 py-2 text-sm hover:bg-mist"><?= icon($ic, 'size-4 text-steel') ?> <?= e(__($lbl)) ?></a>
            <?php endforeach ?>
            <form method="POST" action="<?= url('/logout') ?>" class="mt-1 border-t border-graphite-900/8 pt-1">
                <?= csrf_field() ?>
                <button class="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-sm text-signal-700 hover:bg-signal-50"><?= icon('logout', 'size-4') ?> <?= e(__('Sign out')) ?></button>
            </form>
        </div>
    </div>
</header>
