<?php
include __DIR__.'/_header.php';
$actions  = config('core.notify_actions');
$channels = config('core.notify_channels');
$enabled  = array_filter(config('modules'), fn ($m) => $m['enabled']);
$cell = function (string $m, string $a, string $c) use ($company, $prefs, $larkOn) {
    $allowed = $company[$m][$a][$c] && ($c === 'email' || $larkOn);
    $on      = $prefs[$m][$a][$c] ?? true;
    return '<label class="inline-flex items-center justify-center p-1 '.($allowed ? 'cursor-pointer' : 'opacity-40').'" title="'.e($allowed ? '' : __('Turned off by your company')).'">'
        .'<input type="checkbox" name="notify['.e($m).']['.e($a).']['.e($c).']" value="1" class="size-4 accent-signal-600" '.($on ? 'checked' : '').' '.($allowed ? '' : 'disabled').' aria-label="'.e($m.' '.$a.' '.$c).'"></label>';
};
?>
<form method="POST" action="<?= url('/profile/notifications') ?>" class="mt-6 space-y-6">
    <?= csrf_field() ?>
    <p class="max-w-2xl text-sm text-steel"><?= e(__('Choose what you hear about. You get a message when you do something, and when someone changes a record that belongs to you. Greyed-out boxes are switched off for the whole company.')) ?></p>

    <?php foreach ($enabled as $m => $mod): ?>
    <section class="panel overflow-hidden">
        <div class="panel-head"><h2 class="panel-title flex items-center gap-2"><?= icon($mod['icon'], 'size-4 text-signal-600') ?><?= e(__($mod['name'])) ?></h2></div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead class="bg-mist/60"><tr><th><?= e(__('Channel')) ?></th><?php foreach ($actions as $a => $al): ?><th class="text-center"><?= e(__($al)) ?></th><?php endforeach ?></tr></thead>
                <tbody>
                    <?php foreach ($channels as $c => $cl): ?>
                        <tr><td class="font-medium"><span class="flex items-center gap-2"><?= icon($c === 'email' ? 'mail' : 'chat', 'size-4 text-steel') ?><?= e(__($cl)) ?></span></td>
                            <?php foreach ($actions as $a => $_): ?><td class="text-center"><?= $cell($m, $a, $c) ?></td><?php endforeach ?></tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endforeach ?>

    <section class="panel overflow-hidden">
        <div class="panel-head"><div><h2 class="panel-title flex items-center gap-2"><?= icon('lock', 'size-4 text-signal-600') ?><?= e(__('Security alerts')) ?></h2><p class="text-xs text-steel"><?= e(__('Password e-mails are always sent, to protect your account.')) ?></p></div></div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead class="bg-mist/60"><tr><th><?= e(__('Event')) ?></th><?php foreach ($channels as $c => $cl): ?><th class="text-center"><?= e(__($cl)) ?></th><?php endforeach ?></tr></thead>
                <tbody>
                <?php foreach (config('core.security_events') as $ev => $el): ?>
                    <tr><td class="font-medium"><?= e(__($el)) ?></td>
                        <?php foreach ($channels as $c => $_):
                            $allowed = $security[$ev][$c] && ($c === 'email' || $larkOn) && ! ($ev === 'password' && $c === 'email');
                            $on = ($ev === 'password' && $c === 'email') ? true : ($prefs['_security'][$ev][$c] ?? true); ?>
                            <td class="text-center"><input type="checkbox" name="notify[_security][<?= $ev ?>][<?= $c ?>]" value="1" class="size-4 accent-signal-600" <?= $on ? 'checked' : '' ?> <?= $allowed ? '' : 'disabled' ?> aria-label="<?= e($el.' '.$c) ?>"></td>
                        <?php endforeach ?>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php if (! $larkOn): ?><p class="text-xs text-steel"><?= e(__('Lark messages to you personally are available when your company connects a Lark app.')) ?></p><?php endif ?>
    <div class="flex justify-end"><button class="btn-primary w-full sm:w-auto"><?= e(__('Save my notifications')) ?></button></div>
</form>
