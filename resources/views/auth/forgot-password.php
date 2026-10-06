<?php $layout = 'layouts/guest'; ?>
<a href="<?= url('/login') ?>" class="text-sm text-graphite-400 hover:text-white">&larr; <?= e(__('Back to sign in')) ?></a>
<h1 class="mt-6 font-display text-4xl font-semibold tracking-tight"><?= e(__('Reset your password')) ?></h1>
<p class="mt-2 text-sm text-graphite-400"><?= e(__("Enter your work e-mail. We'll send a link to choose a new password.")) ?></p>
<?php include __DIR__.'/_messages.php' ?>
<form method="POST" action="<?= url('/forgot-password') ?>" class="mt-8 space-y-5">
    <?= csrf_field() ?>
    <div>
        <label for="email" class="mb-1.5 block text-sm font-medium text-graphite-300"><?= e(__('Work e-mail')) ?></label>
        <input id="email" name="email" type="email" value="<?= e(old('email')) ?>" required autofocus autocomplete="email" inputmode="email" class="input-dark">
        <?php if ($m = error_for('email')): ?><p class="mt-1.5 text-xs font-medium text-signal-500"><?= e($m) ?></p><?php endif ?>
    </div>
    <button type="submit" class="btn-primary w-full py-2.5"><?= e(__('Send reset link')) ?></button>
</form>
