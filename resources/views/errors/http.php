<?php
$layout = auth() ? 'layouts/app' : 'layouts/guest';
$headline = [403 => __("You don't have access to this page"), 404 => __("We couldn't find that page"), 419 => __('Your session expired'), 405 => __('That action is not allowed here'), 401 => __('Please sign in')][$status] ?? __('Something went wrong');
$dark = ! auth();
?>
<div class="mx-auto max-w-lg py-16 text-center">
    <span class="inline-flex size-12 items-center justify-center rounded-full <?= $dark ? 'bg-white/5 text-signal-500' : 'bg-signal-50 text-signal-600' ?>"><?= icon($status === 403 ? 'lock' : 'alert', 'size-6') ?></span>
    <h1 class="mt-5 font-display text-3xl font-semibold tracking-tight"><?= e($headline) ?></h1>
    <p class="mt-2 text-sm <?= $dark ? 'text-graphite-400' : 'text-steel' ?>"><?= e($message ?: __('Error :code.', ['code' => $status])) ?><?= $status === 403 ? ' '.e(__('Ask an administrator if you need it.')) : '' ?></p>
    <a href="<?= url(auth() ? '/dashboard' : '/login') ?>" class="btn-primary mt-6"><?= e(auth() ? __('Go to dashboard') : __('Go to sign in')) ?></a>
</div>
