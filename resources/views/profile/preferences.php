<?php
include __DIR__.'/_header.php';
$enabled  = array_filter(config('modules'), fn ($m) => $m['enabled']);
$modOpts  = array_map(fn ($m) => __($m['name']), $enabled);
$curMod   = old('default_module', $me->default_module && isset($enabled[$me->default_module]) ? $me->default_module : 'core');
?>
<form method="POST" action="<?= url('/profile/preferences') ?>" class="panel mt-6 max-w-3xl">
    <?= csrf_field() ?>
    <div class="panel-head"><div><h2 class="panel-title"><?= e(__('Preferences')) ?></h2><p class="text-xs text-steel"><?= e(__('How the app looks and where it opens for you.')) ?></p></div></div>
    <div class="divide-y divide-graphite-900/6">
        <div class="grid gap-3 p-4 sm:grid-cols-[1fr_16rem] sm:items-center sm:p-5">
            <div><label for="pref-locale" class="text-sm font-medium"><?= e(__('Language')) ?></label><p class="text-xs text-steel"><?= e(__('Used for menus, messages and the e-mails you receive.')) ?></p></div>
            <?= select_field('locale', App\Core\Support\I18n::available(), old('locale', $me->locale ?? locale()), ['id' => 'pref-locale', 'required' => true]) ?>
        </div>
        <div class="grid gap-3 p-4 sm:grid-cols-[1fr_16rem] sm:items-center sm:p-5">
            <div><label for="pref-module" class="text-sm font-medium"><?= e(__('Module to open after sign-in')) ?></label><p class="text-xs text-steel"><?= e(__('Modules that are not live yet appear here when they launch.')) ?></p></div>
            <?= select_field('default_module', $modOpts, $curMod, ['id' => 'pref-module', 'required' => true]) ?>
        </div>
        <?php foreach ($dashboards as $mod => $boards): ?>
            <div class="grid gap-3 p-4 sm:grid-cols-[1fr_16rem] sm:items-center sm:p-5" data-show-when="default_module=<?= e($mod) ?>" <?= $mod === $curMod ? '' : 'hidden' ?>>
                <div><label class="text-sm font-medium"><?= e(__('Dashboard to open')) ?></label><p class="text-xs text-steel"><?= e(__('In :module', ['module' => __(config('modules.'.$mod.'.name'))])) ?></p></div>
                <?= select_field($mod === $curMod ? 'default_dashboard' : '_dash_'.$mod, $boards, old('default_dashboard', $me->default_dashboard), ['placeholder' => __('Automatic'), 'data-dash' => $mod]) ?>
            </div>
        <?php endforeach ?>
    </div>
    <div class="flex justify-end border-t border-graphite-900/8 px-4 py-3 sm:px-5"><button class="btn-primary w-full sm:w-auto"><?= e(__('Save preferences')) ?></button></div>
</form>
