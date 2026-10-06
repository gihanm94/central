<?php include __DIR__.'/_tabs.php'; $crumbs = []; ?>
<section class="panel mt-6 overflow-hidden">
    <div class="panel-head">
        <div><h2 class="panel-title"><?= e(__('Mail log')) ?></h2>
            <p class="text-xs text-steel"><?= e(__('Driver')) ?>: <strong><?= e($driver) ?></strong><?= $driver === 'smtp' ? ' · '.e($host).' · '.e(__('from')).' '.e($from) : '' ?>. <?= e(__('The last 100 messages and what happened to them.')) ?></p></div>
        <?php if (can('settings', 'edit')): ?><form method="POST" action="<?= url('/settings/test-mail') ?>"><?= csrf_field() ?><button class="btn-secondary"><?= icon('mail', 'size-4') ?> <?= e(__('Send me a test e-mail')) ?></button></form><?php endif ?>
    </div>
    <?php if (! $rows): ?>
        <p class="px-5 py-12 text-center text-sm text-steel"><?= e(__('Nothing sent yet. Create a record or send a test e-mail.')) ?></p>
    <?php else: ?>
    <div class="overflow-x-auto"><table class="table w-full">
        <thead><tr><th><?= e(__('When')) ?></th><th><?= e(__('To')) ?></th><th><?= e(__('Subject')) ?></th><th><?= e(__('Result')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $bad = $r['status'] === 'failed'; ?>
            <tr class="<?= $bad ? 'bg-signal-50/40' : '' ?>">
                <td class="whitespace-nowrap tabular-nums text-steel"><?= e(format_date($r['created_at'], 'd M H:i:s')) ?></td>
                <td class="whitespace-nowrap"><?= e($r['to_email']) ?></td>
                <td class="max-w-md truncate" title="<?= e($r['subject']) ?>"><?= e($r['subject']) ?></td>
                <td><?php if ($bad): ?><span class="badge bg-signal-50 text-signal-800"><?= e(__('Failed')) ?></span><span class="mt-1 block max-w-md break-words text-xs text-signal-800"><?= e($r['error']) ?></span>
                    <?php else: ?><span class="badge bg-emerald-50 text-emerald-800"><?= e($r['driver'] === 'log' ? __('Saved to storage/mail') : __('Sent')) ?></span><?php endif ?></td>
            </tr>
        <?php endforeach ?>
        </tbody></table></div>
    <?php endif ?>
</section>
