<?php $layout = 'layouts/guest'; ?>
<h1 class="font-display text-4xl font-semibold tracking-tight"><?= e(__('Choose a new password')) ?></h1>
<p class="mt-2 text-sm text-graphite-400"><?= e(__('At least 8 characters, with upper and lower case letters and a number.')) ?></p>
<form method="POST" action="<?= url('/reset-password') ?>" class="mt-8 space-y-5">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e(old('token', $token)) ?>">
    <?php foreach (['email' => [__('Work e-mail'), 'email', 'username', old('email', $email)], 'password' => [__('New password'), 'password', 'new-password', ''], 'password_confirmation' => [__('Confirm new password'), 'password', 'new-password', '']] as $name => [$label, $type, $ac, $val]): ?>
        <div>
            <label for="<?= $name ?>" class="mb-1.5 block text-sm font-medium text-graphite-300"><?= e($label) ?></label>
            <input id="<?= $name ?>" name="<?= $name ?>" type="<?= $type ?>" value="<?= e($val) ?>" required autocomplete="<?= $ac ?>" class="input-dark">
            <?php if ($m = error_for($name)): ?><p class="mt-1.5 text-xs font-medium text-signal-500"><?= e($m) ?></p><?php endif ?>
        </div>
    <?php endforeach ?>
    <button type="submit" class="btn-primary w-full py-2.5"><?= e(__('Save password')) ?></button>
</form>
