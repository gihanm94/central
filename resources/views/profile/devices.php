<?php /* $me, $allowed, $devices */
include __DIR__.'/_header.php';
?>
<section class="panel mt-6">
    <div class="panel-head"><div><h2 class="panel-title"><?= e(__('Mobile devices')) ?></h2>
        <p class="text-xs text-steel"><?= e($allowed ? __('Phones and tablets signed in to the mobile app with your account. Lost a phone? Sign it out here.') : __('Your account may not use the mobile app. Ask an administrator if you need it.')) ?></p></div></div>
    <ul class="divide-y divide-graphite-900/6">
        <?php foreach ($devices as $d): ?>
            <li class="flex items-center gap-3 px-5 py-3 text-sm">
                <?= icon('id', 'size-5 shrink-0 text-steel') ?>
                <span class="min-w-0 flex-1"><span class="block font-medium"><?= e($d['name']) ?></span>
                    <span class="block text-xs text-steel"><?= e(__('Signed in :a · last used :b · from :ip', ['a' => format_date($d['created_at'], 'd M Y'), 'b' => time_ago($d['last_used_at']), 'ip' => $d['last_ip']])) ?></span></span>
                <form method="POST" action="<?= url('/profile/devices/'.$d['id'].'/revoke') ?>" data-confirm="<?= e(__('Sign this device out?')) ?>"><?= csrf_field() ?><button class="btn-secondary py-1 text-xs"><?= e(__('Sign out')) ?></button></form>
            </li>
        <?php endforeach ?>
        <?php if (! $devices): ?><li class="px-5 py-8 text-center text-sm text-steel"><?= e(__('No device is signed in to the mobile app.')) ?></li><?php endif ?>
    </ul>
    <p class="border-t border-graphite-900/8 px-5 py-3 text-xs text-steel"><?= e(__('Changing your password or “Sign out all others” also signs out every mobile device.')) ?></p>
</section>
