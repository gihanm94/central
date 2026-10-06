<?php include __DIR__.'/_header.php'; ?>
<div class="mt-6 grid items-start gap-6 xl:grid-cols-3">
    <div class="space-y-6 xl:col-span-2">
        <form method="POST" action="<?= url('/profile/password') ?>" class="panel">
            <?= csrf_field() ?>
            <div class="panel-head"><h2 class="panel-title"><?= e(__('Password')) ?></h2></div>
            <div class="grid gap-5 p-4 sm:grid-cols-3 sm:p-5">
                <?php foreach (['current_password' => [__('Current password'), 'current-password'], 'password' => [__('New password'), 'new-password'], 'password_confirmation' => [__('Confirm new password'), 'new-password']] as $name => [$label, $ac]): ?>
                    <div>
                        <label for="<?= $name ?>" class="label"><?= e($label) ?></label>
                        <input id="<?= $name ?>" name="<?= $name ?>" type="password" required autocomplete="<?= $ac ?>" class="input <?= error_for($name) ? 'input-error' : '' ?>">
                        <?= field_error($name) ?>
                    </div>
                <?php endforeach ?>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-graphite-900/8 px-4 py-3 sm:px-5">
                <p class="text-xs text-steel"><?= e(__('Changing it signs you out on other devices and sends you a confirmation.')) ?></p>
                <button class="btn-dark w-full sm:w-auto"><?= e(__('Change password')) ?></button>
            </div>
        </form>

        <section class="panel">
            <div class="panel-head"><div><h2 class="panel-title"><?= e(__('Passkeys')) ?></h2><p class="text-xs text-steel"><?= e(__('Sign in with your fingerprint, face or device PIN instead of a password.')) ?></p></div></div>
            <ul class="divide-y divide-graphite-900/6">
                <?php foreach ($passkeys as $k): ?>
                    <li class="flex items-center justify-between gap-4 px-5 py-3">
                        <div class="flex min-w-0 items-center gap-3"><?= icon('key', 'size-5 shrink-0 text-steel') ?>
                            <div class="min-w-0"><p class="truncate text-sm font-medium"><?= e($k['name']) ?></p><p class="text-xs text-steel"><?= e(__('Added :date', ['date' => format_date($k['created_at'])])) ?> · <?= e($k['last_used_at'] ? __('last used :time', ['time' => time_ago($k['last_used_at'])]) : __('not used yet')) ?></p></div>
                        </div>
                        <form method="POST" action="<?= url('/passkeys/'.$k['id'].'/delete') ?>" data-confirm="<?= e(__('Remove this passkey? You will no longer be able to sign in with it.')) ?>">
                            <?= csrf_field() ?><button class="btn-danger px-3 py-1.5 text-xs"><?= e(__('Remove')) ?></button>
                        </form>
                    </li>
                <?php endforeach ?>
                <?php if (! $passkeys): ?><li class="px-5 py-4 text-sm text-steel"><?= e(__('No passkeys yet.')) ?></li><?php endif ?>
            </ul>
            <form data-passkey-register action="<?= url('/passkeys') ?>" data-options-url="<?= url('/passkeys/options') ?>"
                  data-msg-unsupported="<?= e(__('This browser does not support passkeys.')) ?>" data-msg-cancel="<?= e(__('Passkey setup was cancelled.')) ?>" data-msg-ok="<?= e(__('Passkey added.')) ?>" data-msg-exists="<?= e(__('This device already has a passkey for your account.')) ?>"
                  class="flex flex-wrap items-end gap-3 border-t border-graphite-900/8 px-5 py-4">
                <div class="min-w-0 flex-1 basis-48"><label for="passkey-name" class="label"><?= e(__('Name this passkey')) ?></label><input id="passkey-name" name="name" maxlength="80" placeholder="<?= e(__('e.g. Work laptop')) ?>" class="input"></div>
                <button type="submit" class="btn-primary w-full sm:w-auto"><?= icon('finger', 'size-4') ?> <?= e(__('Add passkey')) ?></button>
                <p data-passkey-status role="alert" hidden class="w-full text-xs font-medium text-signal-700"></p>
            </form>
        </section>
    </div>

    <aside class="space-y-6">
        <section class="panel">
            <div class="panel-head"><h2 class="panel-title"><?= e(__('Remembered devices')) ?></h2></div>
            <ul class="divide-y divide-graphite-900/6 text-sm">
                <?php foreach ($devices as $d): ?>
                    <li class="flex items-center justify-between gap-3 px-5 py-2.5">
                        <div class="min-w-0"><p class="truncate"><?= e(str_limit($d['user_agent'], 44)) ?></p><p class="text-xs text-steel"><?= e(__('Since :date', ['date' => format_date($d['created_at'])])) ?></p></div>
                        <form method="POST" action="<?= url('/profile/devices/'.$d['id'].'/forget') ?>"><?= csrf_field() ?><button class="btn-ghost px-2 py-1 text-xs"><?= e(__('Forget')) ?></button></form>
                    </li>
                <?php endforeach ?>
                <?php if (! $devices): ?><li class="px-5 py-3 text-steel"><?= e(__('No devices use “keep me signed in”.')) ?></li><?php endif ?>
            </ul>
        </section>
        <section class="panel">
            <div class="panel-head"><h2 class="panel-title"><?= e(__('Recent sign-ins')) ?></h2></div>
            <ul class="divide-y divide-graphite-900/6 text-sm">
                <?php foreach ($logins as $l): ?>
                    <li class="px-5 py-2.5">
                        <p class="<?= str_starts_with($l['description'], 'Failed') ? 'text-signal-700' : '' ?>"><?= e(activity_text($l)) ?></p>
                        <p class="text-xs text-steel"><?= e($l['ip_address']) ?> · <?= e(time_ago($l['created_at'])) ?></p>
                    </li>
                <?php endforeach ?>
            </ul>
        </section>
    </aside>
</div>
