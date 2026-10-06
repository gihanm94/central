<?php
include __DIR__.'/_tabs.php'; $crumbs = [];
$canEdit  = can('settings', 'edit');
$actions  = config('core.notify_actions');
$channels = config('core.notify_channels');
$modes    = ['off' => __('Off'), 'webhook' => __('Group chat (bot webhook)'), 'app' => __('Direct message to each person (Lark app)')];
?>
<form method="POST" action="<?= url('/settings/notifications') ?>" class="mt-6 space-y-6">
    <?= csrf_field() ?>
    <fieldset <?= $canEdit ? '' : 'disabled' ?> class="space-y-6">
        <section class="panel">
            <div class="panel-head"><div><h2 class="panel-title flex items-center gap-2"><?= icon('chat', 'size-4 text-signal-600') ?>Lark</h2><p class="text-xs text-steel"><?= e(__('Send notifications to Lark as well as e-mail.')) ?></p></div></div>
            <div class="grid gap-5 p-4 sm:grid-cols-2 sm:p-5">
                <div>
                    <label for="lark_mode" class="label"><?= e(__('How to send')) ?></label>
                    <?= select_field('lark_mode', $modes, old('lark_mode', $lark['mode']), ['id' => 'lark_mode', 'required' => true]) ?>
                    <?= field_error('lark_mode') ?>
                </div>
                <div>
                    <label for="lark_domain" class="label"><?= e(__('Lark region')) ?></label>
                    <?= select_field('lark_domain', ['https://open.larksuite.com' => 'Lark (larksuite.com)', 'https://open.feishu.cn' => 'Feishu (feishu.cn)'], old('lark_domain', $lark['domain']), ['id' => 'lark_domain', 'required' => true]) ?>
                </div>
                <div class="grid gap-5 sm:col-span-2 sm:grid-cols-2" data-show-when="lark_mode=webhook" <?= $lark['mode'] === 'webhook' ? '' : 'hidden' ?>>
                    <div class="sm:col-span-2">
                        <label for="lark_webhook_url" class="label"><?= e(__('Bot webhook address')) ?></label>
                        <input id="lark_webhook_url" name="lark_webhook_url" value="<?= e(old('lark_webhook_url', $lark['webhook'])) ?>" placeholder="https://open.larksuite.com/open-apis/bot/v2/hook/…" class="input">
                        <p class="hint"><?= e(__('In the Lark group: Settings → Bots → Add bot → Custom bot, then copy the webhook address.')) ?></p>
                        <?= field_error('lark_webhook_url') ?>
                    </div>
                    <div>
                        <label for="lark_webhook_secret" class="label"><?= e(__('Signature secret')) ?> <span class="font-normal text-steel">(<?= e(__('optional')) ?>)</span></label>
                        <input id="lark_webhook_secret" name="lark_webhook_secret" type="password" value="<?= e($lark['secret']) ?>" autocomplete="off" class="input">
                        <p class="hint"><?= e(__('Only if “Set signature verification” is on for the bot.')) ?></p>
                    </div>
                </div>
                <div class="grid gap-5 sm:col-span-2 sm:grid-cols-2" data-show-when="lark_mode=app" <?= $lark['mode'] === 'app' ? '' : 'hidden' ?>>
                    <div>
                        <label for="lark_app_id" class="label"><?= e(__('App ID')) ?></label>
                        <input id="lark_app_id" name="lark_app_id" value="<?= e(old('lark_app_id', $lark['app_id'])) ?>" placeholder="cli_…" autocomplete="off" class="input">
                        <?= field_error('lark_app_id') ?>
                    </div>
                    <div>
                        <label for="lark_app_secret" class="label"><?= e(__('App secret')) ?></label>
                        <input id="lark_app_secret" name="lark_app_secret" type="password" value="<?= e($lark['app_secret']) ?>" autocomplete="off" class="input">
                    </div>
                    <p class="hint sm:col-span-2"><?= e(__('Create an app in the Lark developer console, enable the bot, add the permissions im:message:send_as_bot and contact:user.email:readonly, and publish it. People are matched by their work e-mail.')) ?></p>
                </div>
            </div>
        </section>

        <section class="panel overflow-hidden">
            <div class="panel-head"><div><h2 class="panel-title"><?= e(__('What sends a notification')) ?></h2><p class="text-xs text-steel"><?= e(__('Per module. The person who acted and the record owner are told. Each person can still turn things off for themselves.')) ?></p></div>
                <?php if ($canEdit): ?><div class="hidden gap-1 sm:flex"><button type="button" class="btn-ghost text-xs" data-check-all="#notify-matrix" data-value="1"><?= e(__('Tick all')) ?></button><button type="button" class="btn-ghost text-xs" data-check-all="#notify-matrix" data-value="0"><?= e(__('Clear all')) ?></button></div><?php endif ?>
            </div>
            <div class="overflow-x-auto" id="notify-matrix">
                <table class="table">
                    <thead class="bg-mist/60"><tr><th><?= e(__('Module')) ?></th><th><?= e(__('Channel')) ?></th><?php foreach ($actions as $al): ?><th class="text-center"><?= e(__($al)) ?></th><?php endforeach ?></tr></thead>
                    <tbody>
                    <?php foreach (config('modules') as $m => $mod): foreach (array_keys($channels) as $i => $c): ?>
                        <tr class="<?= $i === 0 ? 'border-t-2 border-graphite-900/10' : '' ?>">
                            <?php if ($i === 0): ?><td rowspan="<?= count($channels) ?>" class="align-top font-medium"><span class="flex items-center gap-2"><?= icon($mod['icon'], 'size-4 text-steel') ?><?= e(__($mod['name'])) ?></span><?php if (! $mod['enabled']): ?><span class="mt-1 block text-xs font-normal text-steel"><?= e(__('Coming soon')) ?></span><?php endif ?></td><?php endif ?>
                            <td class="whitespace-nowrap text-steel"><?= e(__($channels[$c])) ?></td>
                            <?php foreach (array_keys($actions) as $a): ?>
                                <td class="text-center"><input type="checkbox" name="notify[<?= $m ?>][<?= $a ?>][<?= $c ?>]" value="1" class="size-4 accent-signal-600" <?= $matrix[$m][$a][$c] ? 'checked' : '' ?> aria-label="<?= e($m.' '.$a.' '.$c) ?>"></td>
                            <?php endforeach ?>
                        </tr>
                    <?php endforeach; endforeach ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel overflow-hidden">
            <div class="panel-head"><h2 class="panel-title"><?= e(__('Security alerts')) ?></h2></div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead class="bg-mist/60"><tr><th><?= e(__('Event')) ?></th><?php foreach ($channels as $cl): ?><th class="text-center"><?= e(__($cl)) ?></th><?php endforeach ?></tr></thead>
                    <tbody>
                    <?php foreach (config('core.security_events') as $ev => $el): ?>
                        <tr><td class="font-medium"><?= e(__($el)) ?></td>
                            <?php foreach (array_keys($channels) as $c): ?><td class="text-center"><input type="checkbox" name="security[<?= $ev ?>][<?= $c ?>]" value="1" class="size-4 accent-signal-600" <?= $security[$ev][$c] ? 'checked' : '' ?> aria-label="<?= e($el.' '.$c) ?>"></td><?php endforeach ?>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </section>
    </fieldset>
    <?php if ($canEdit): ?><div class="flex justify-end"><button class="btn-primary w-full sm:w-auto"><?= e(__('Save notification settings')) ?></button></div><?php endif ?>
</form>
<?php if ($canEdit): ?>
<div class="mt-4 flex flex-wrap gap-2">
    <form method="POST" action="<?= url('/settings/test-mail') ?>"><?= csrf_field() ?><button class="btn-secondary"><?= icon('mail', 'size-4') ?> <?= e(__('Send me a test e-mail')) ?></button></form>
    <?php if ($lark['mode'] !== 'off'): ?><form method="POST" action="<?= url('/settings/test-lark') ?>"><?= csrf_field() ?><button class="btn-secondary"><?= icon('chat', 'size-4') ?> <?= e(__('Send a test Lark message')) ?></button></form><?php endif ?>
</div>
<?php endif ?>
