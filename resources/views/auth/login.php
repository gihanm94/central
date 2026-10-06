<?php $layout = 'layouts/guest'; ?>
<h1 class="font-display text-4xl font-semibold tracking-tight"><?= e(__('Sign in')) ?></h1>
<p class="mt-2 text-sm text-graphite-400"><?= e(__('Use your work e-mail, or a passkey saved on this device.')) ?></p>
<?php include __DIR__.'/_messages.php' ?>

<form method="POST" action="<?= url('/login') ?>" class="mt-8 space-y-5" novalidate>
    <?= csrf_field() ?>
    <div>
        <label for="email" class="mb-1.5 block text-sm font-medium text-graphite-300"><?= e(__('Work e-mail')) ?></label>
        <input id="email" name="email" type="email" value="<?= e(old('email')) ?>" required autofocus autocomplete="username webauthn" inputmode="email"
               class="input-dark <?= error_for('email') ? 'ring-signal-500' : '' ?>">
        <?php if ($m = error_for('email')): ?><p class="mt-1.5 text-xs font-medium text-signal-500"><?= e($m) ?></p><?php endif ?>
    </div>
    <div>
        <div class="mb-1.5 flex items-center justify-between">
            <label for="password" class="text-sm font-medium text-graphite-300"><?= e(__('Password')) ?></label>
            <a href="<?= url('/forgot-password') ?>" class="text-xs text-graphite-400 underline-offset-2 hover:text-white hover:underline"><?= e(__('Forgot password?')) ?></a>
        </div>
        <input id="password" name="password" type="password" required autocomplete="current-password" class="input-dark">
        <?php if ($m = error_for('password')): ?><p class="mt-1.5 text-xs font-medium text-signal-500"><?= e($m) ?></p><?php endif ?>
    </div>
    <label class="flex items-center gap-2.5 text-sm text-graphite-300">
        <input type="checkbox" name="remember" value="1" class="size-4 accent-signal-600" <?= old('remember') ? 'checked' : '' ?>>
        <?= e(__('Keep me signed in on this device')) ?>
    </label>
    <button type="submit" class="btn-primary w-full py-2.5"><?= e(__('Sign in')) ?></button>
</form>

<div class="my-6 flex items-center gap-3 text-xs text-graphite-400"><span class="h-px flex-1 bg-white/10"></span> <?= e(__('or')) ?> <span class="h-px flex-1 bg-white/10"></span></div>

<button type="button" data-passkey-login data-options-url="<?= url('/passkeys/login/options') ?>" data-login-url="<?= url('/passkeys/login') ?>"
        data-msg-unsupported="<?= e(__('This browser does not support passkeys.')) ?>" data-msg-cancel="<?= e(__('Passkey sign-in was cancelled.')) ?>"
        class="btn w-full bg-white/5 py-2.5 text-white ring-1 ring-white/15 hover:bg-white/10">
    <?= icon('finger', 'size-5 text-signal-500') ?> <?= e(__('Sign in with a passkey')) ?>
</button>
<p id="passkey-status" role="alert" hidden class="mt-3 text-xs font-medium text-signal-500"></p>
