<?php
$collapsed = ($_COOKIE['sidebar'] ?? '') === 'collapsed';
$modules   = config('modules');
$segment   = explode('/', trim(App\Core\Support\Request::path(), '/'))[0];
$current   = isset($modules[$segment]) && $modules[$segment]['enabled'] ? $segment : 'core';
?>
<!DOCTYPE html>
<html lang="<?= e(locale()) ?>" class="h-full" data-sidebar="<?= $collapsed ? 'collapsed' : 'open' ?>" style="font-size: <?= (int) auth()->ui_scale ?>%">
<head><?php include __DIR__.'/head.php' ?></head>
<body class="h-full">
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2"><?= e(__('Skip to content')) ?></a>

<!-- Mobile drawer -->
<div id="sidebar-drawer" data-drawer hidden class="fixed inset-0 z-50 lg:hidden">
    <div class="absolute inset-0 bg-graphite-950/60" data-close-drawer></div>
    <aside class="drawer-panel relative h-full w-[18rem] max-w-[85vw] shadow-2xl"><?php $sid = 'mobile'; include __DIR__.'/sidebar.php' ?></aside>
</div>
<!-- Desktop sidebar (collapsible) -->
<aside class="fixed inset-y-0 left-0 z-30 hidden w-64 transition-[width] duration-200 lg:block lg:collapsed:w-[4.5rem]"><?php $sid = 'desktop'; include __DIR__.'/sidebar.php' ?></aside>

<div class="flex min-h-full flex-col transition-[padding] duration-200 lg:pl-64 lg:collapsed:pl-[4.5rem]">
    <?php include __DIR__.'/navbar.php' ?>
    <main id="main" class="flex-1 px-4 pb-10 pt-5 sm:px-6 sm:pb-8 lg:px-8 lg:pt-6">
        <?php include __DIR__.'/../partials/flash.php' ?>
        <?= $content ?>
    </main>
    <?php include __DIR__.'/footer.php' ?>
</div>
<?= partial('partials/delete-dialog') ?>
</body>
</html>
