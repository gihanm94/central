<?php
$here = App\Core\Support\Request::path();
$menu = array_values(array_filter($modules[$current]['menu'], function ($item) {
    if (isset($item['heading'])) { return true; }
    if (! empty($item['admin_only']) && ! auth()->isAdmin()) { return false; }
    if (empty($item['permission'])) { return true; }
    [$res, $act] = array_pad(explode('.', $item['permission'], 2), 2, 'view');
    return can($res, $act);
}));
$menu = array_values(array_filter($menu, fn ($item, $i) => ! isset($item['heading']) || (isset($menu[$i + 1]) && ! isset($menu[$i + 1]['heading'])), ARRAY_FILTER_USE_BOTH));
$mobile = $sid === 'mobile';
?>
<div class="flex h-full flex-col bg-graphite-900 text-graphite-300">
    <!-- Company + active module switcher -->
    <div class="relative shrink-0 border-b border-white/5 p-3">
        <button type="button" data-menu="#module-menu-<?= $sid ?>" data-placement="<?= $mobile ? 'bottom-start' : 'right-start' ?>" aria-haspopup="menu" aria-expanded="false"
                title="<?= e(__('Switch module')) ?>"
                class="flex w-full items-center gap-3 rounded-lg p-1.5 text-left transition-colors hover:bg-graphite-800 focus-visible:bg-graphite-800 lg:collapsed:justify-center">
            <?php if ($branding['logo']): ?>
                <span class="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-white/5 p-1"><img src="<?= e($branding['logo']) ?>" alt="" class="max-h-full max-w-full object-contain"></span>
            <?php else: ?>
                <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-signal-600 text-white"><?= icon($modules[$current]['icon'], 'size-5') ?></span>
            <?php endif ?>
            <span class="min-w-0 flex-1 <?= $mobile ? '' : 'lg:collapsed:hidden' ?>">
                <span class="block truncate font-display text-lg font-semibold leading-tight text-white"><?= e($branding['name']) ?></span>
                <span class="block truncate text-xs text-graphite-300"><?= e(__($modules[$current]['name'])) ?></span>
            </span>
            <?= icon('updown', 'size-4 shrink-0 text-graphite-400 '.($mobile ? '' : 'lg:collapsed:hidden')) ?>
        </button>
        <div id="module-menu-<?= $sid ?>" data-menu-panel hidden role="menu" class="fixed z-[70] w-64 rounded-lg bg-graphite-800 p-1.5 text-graphite-300 shadow-2xl ring-1 ring-white/10">
            <p class="px-2.5 pb-1.5 pt-1 text-xs text-graphite-400"><?= e(__('Modules')) ?></p>
            <?php foreach ($modules as $key => $m): ?>
                <?php if ($m['enabled']): ?>
                    <a role="menuitem" href="<?= url($m['home']) ?>" class="flex items-center gap-3 rounded-md px-2 py-1.5 text-sm hover:bg-graphite-700 hover:text-white <?= $key === $current ? 'text-white' : '' ?>">
                        <span class="flex size-7 items-center justify-center rounded-md <?= $key === $current ? 'bg-signal-600 text-white' : 'bg-white/5' ?>"><?= icon($m['icon'], 'size-4') ?></span>
                        <span class="flex-1"><?= e(__($m['name'])) ?></span>
                        <?php if ($key === $current): ?><?= icon('tick', 'size-4 text-signal-500') ?><?php endif ?>
                    </a>
                <?php else: ?>
                    <span class="flex cursor-default items-center gap-3 rounded-md px-2 py-1.5 text-sm text-graphite-400" aria-disabled="true">
                        <span class="flex size-7 items-center justify-center rounded-md bg-white/5"><?= icon($m['icon'], 'size-4') ?></span>
                        <span class="flex-1"><?= e(__($m['name'])) ?></span><span class="text-[11px]"><?= e(__('Coming soon')) ?></span>
                    </span>
                <?php endif ?>
            <?php endforeach ?>
        </div>
    </div>

    <!-- Module menu -->
    <nav class="flex-1 overflow-y-auto overflow-x-hidden px-3 py-3" aria-label="<?= e(__($modules[$current]['name'])) ?>">
        <?php foreach ($menu as $i => $item): ?>
            <?php if (isset($item['heading'])): ?>
                <p class="px-3 pb-1.5 <?= $i ? 'pt-5' : 'pt-1' ?> text-xs font-medium text-graphite-400 <?= $mobile ? '' : 'lg:collapsed:hidden' ?>"><?= e(__($item['heading'])) ?></p>
                <?php if (! $mobile && $i): ?><hr class="mx-2 my-3 hidden border-white/10 lg:collapsed:block"><?php endif ?>
            <?php else:
                $active = $here === $item['url'] || str_starts_with($here, $item['url'].'/'); ?>
                <a href="<?= url($item['url']) ?>" title="<?= e(__($item['label'])) ?>" class="nav-link <?= $active ? 'nav-link-active' : '' ?> <?= $mobile ? '' : 'lg:collapsed:justify-center lg:collapsed:px-0' ?>" <?= $active ? 'aria-current="page"' : '' ?>>
                    <?= icon($item['icon'] ?? 'grid', 'size-[18px] shrink-0 '.($active ? 'text-signal-500' : '')) ?>
                    <span class="truncate <?= $mobile ? '' : 'lg:collapsed:sr-only' ?>"><?= e(__($item['label'])) ?></span>
                </a>
            <?php endif ?>
        <?php endforeach ?>
    </nav>

    <!-- Signed-in person -->
    <a href="<?= url('/profile') ?>" title="<?= e(auth()->name) ?>" class="flex shrink-0 items-center gap-3 border-t border-white/5 px-4 py-3.5 hover:bg-graphite-800 <?= $mobile ? '' : 'lg:collapsed:justify-center lg:collapsed:px-0' ?>">
        <?= partial('partials/avatar', ['name' => auth()->name, 'avatar' => auth()->avatar, 'size' => 'size-9']) ?>
        <span class="min-w-0 <?= $mobile ? '' : 'lg:collapsed:hidden' ?>">
            <span class="block truncate text-sm font-medium text-white"><?= e(auth()->name) ?></span>
            <span class="block truncate text-xs text-graphite-400"><?= e(__(auth()->role_name)) ?></span>
        </span>
    </a>
</div>
