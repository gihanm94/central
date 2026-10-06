<?php include __DIR__.'/_header.php'; /* $me, $configured, $conn */
$on = $conn && ! empty($conn['refresh_token']); ?>
<div class="mt-6 grid items-start gap-6 xl:grid-cols-3">
    <section class="panel xl:col-span-2">
        <div class="panel-head"><div class="flex items-center gap-3"><span class="flex size-10 items-center justify-center rounded-lg bg-white ring-1 ring-graphite-900/10"><svg viewBox="0 0 24 24" class="size-6" aria-hidden="true"><path fill="#4285F4" d="M23 12.2c0-.8-.1-1.5-.2-2.2H12v4.2h6.2a5.3 5.3 0 0 1-2.3 3.5v2.9h3.7c2.2-2 3.4-5 3.4-8.4Z"/><path fill="#34A853" d="M12 23.5c3.1 0 5.7-1 7.6-2.8l-3.7-2.9c-1 .7-2.3 1.1-3.9 1.1-3 0-5.5-2-6.4-4.7H1.8v3A11.5 11.5 0 0 0 12 23.5Z"/><path fill="#FBBC05" d="M5.6 14.2a6.9 6.9 0 0 1 0-4.4v-3H1.8a11.5 11.5 0 0 0 0 10.4l3.8-3Z"/><path fill="#EA4335" d="M12 5.4c1.7 0 3.2.6 4.4 1.7l3.3-3.3A11.5 11.5 0 0 0 1.8 6.8l3.8 3C6.5 7.4 9 5.4 12 5.4Z"/></svg></span>
            <div><h2 class="panel-title">Google</h2><p class="text-xs text-steel"><?= e(__('Gmail and Calendar, inside this system.')) ?></p></div></div>
            <?php if ($on): ?><span class="badge bg-emerald-50 text-emerald-800"><?= e(__('Connected')) ?></span><?php endif ?></div>
        <div class="space-y-4 p-5 text-sm">
            <?php if ($on): ?>
                <p><?= e(__('Connected as')) ?> <strong><?= e($conn['google_email']) ?></strong> · <?= e(time_ago($conn['connected_at'])) ?></p>
                <?php if ($conn['last_error']): ?><p class="rounded-lg bg-signal-50 px-3 py-2 text-signal-800"><?= e($conn['last_error']) ?></p><?php endif ?>
                <div class="flex flex-wrap gap-2"><a href="<?= url('/mail') ?>" class="btn-secondary"><?= icon('chat', 'size-4') ?> <?= e(__('Open mail')) ?></a><a href="<?= url('/calendar') ?>" class="btn-secondary"><?= icon('clock', 'size-4') ?> <?= e(__('Open calendar')) ?></a>
                    <form method="POST" action="<?= url('/profile/connectors/google/disconnect') ?>" data-confirm="<?= e(__('Disconnect Google? Your mail and calendar will no longer show here.')) ?>"><?= csrf_field() ?><button class="btn-danger"><?= e(__('Disconnect')) ?></button></form></div>
            <?php elseif (! $configured): ?>
                <p class="rounded-lg bg-amber-50 px-3 py-2 text-amber-900"><?= e(__('The administrator has not set up the Google connector yet.')) ?></p>
            <?php else: ?>
                <p class="text-steel"><?= e(__('Connect your Google account to read and send e-mail and to see your calendar here. Activities you create in the CRM are also added to your Google Calendar.')) ?></p>
                <a href="<?= url('/connect/google') ?>" class="btn-primary"><?= icon('link', 'size-4') ?> <?= e(__('Connect Google')) ?></a>
            <?php endif ?>
        </div>
    </section>
    <section class="panel">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('What this allows')) ?></h2></div>
        <ul class="space-y-2 px-5 py-4 text-sm text-steel"><li>✉️ <?= e(__('Read your inbox and send mail from this system.')) ?></li><li>📅 <?= e(__('Show your Google Calendar next to CRM activities.')) ?></li><li>➕ <?= e(__('Add new CRM activities to your Google Calendar.')) ?></li><li>🔒 <?= e(__('You can disconnect at any time; nothing is deleted from Google.')) ?></li></ul>
    </section>
</div>
