<?php /* $enabled, $time, $picked, $mode, $users, $today, $last */ ?>
<div class="min-w-0">
    <p class="text-sm text-steel"><a href="<?= url('/accounting') ?>" class="hover:text-signal-700"><?= e(__('Accounting')) ?></a> › <?= e(__('Setup')) ?></p>
    <h1 class="page-title"><?= e(__('Notifications')) ?></h1>
    <p class="mt-1 text-sm text-steel"><?= e(__('Every day at the chosen time, the billing notes whose remind date is today are sent on Lark, with their details.')) ?></p>
</div>
<div class="mt-4 grid gap-4 lg:grid-cols-3">
    <form method="POST" action="<?= url('/accounting/notify') ?>" class="panel lg:col-span-2">
        <?= csrf_field() ?>
        <div class="space-y-5 p-5">
            <label class="flex cursor-pointer items-center justify-between gap-4"><span><span class="block text-sm font-medium"><?= e(__('Send the daily billing list')) ?></span><span class="block text-xs text-steel"><?= e(__('Needs the scheduler (cron line on the ERP connection page, or an open accounting page).')) ?></span></span>
                <span class="relative inline-flex h-6 w-11 items-center"><input type="checkbox" name="enabled" value="1" class="peer sr-only" <?= $enabled ? 'checked' : '' ?>><span class="absolute inset-0 rounded-full bg-graphite-900/15 transition-colors peer-checked:bg-signal-600"></span><span class="absolute left-0.5 size-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span></span></label>
            <div><label class="label" for="n-time"><?= e(__('Time of day (Bangkok)')) ?></label><input id="n-time" type="time" name="time" value="<?= e($time) ?>" class="input max-w-[10rem]"></div>
            <div>
                <p class="label"><?= e(__('Send to')) ?></p>
                <?php if ($mode === 'webhook'): ?><p class="mb-2 rounded-lg bg-sky-50 px-3 py-2 text-xs text-sky-900"><?= e(__('Lark is in group (webhook) mode: the list is posted to the group once; the people below are not used.')) ?></p><?php endif ?>
                <div class="max-h-72 divide-y divide-graphite-900/8 overflow-auto rounded-lg ring-1 ring-graphite-900/10">
                    <?php foreach ($users as $u): ?>
                    <label class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-mist/60"><input type="checkbox" name="users[]" value="<?= (int) $u['id'] ?>" class="size-4 accent-signal-600" <?= in_array((int) $u['id'], $picked, true) ? 'checked' : '' ?>>
                        <span class="min-w-0"><span class="block truncate text-sm font-medium"><?= e($u['name']) ?></span><span class="block truncate text-xs text-steel"><?= e($u['email']) ?></span></span></label>
                    <?php endforeach ?>
                </div>
                <p class="mt-1 text-xs text-steel"><?= e(__('Lark finds each person by this work e-mail.')) ?></p>
            </div>
        </div>
        <div class="flex items-center justify-between gap-3 border-t border-graphite-900/8 bg-mist/50 px-5 py-3">
            <span class="text-xs text-steel"><?= $last ? e(__('Last sent on :d', ['d' => $last])) : e(__('Not sent yet.')) ?> · <?= e(__('Lark mode')) ?>: <strong><?= e($mode) ?></strong> <a href="<?= url('/settings') ?>" class="ml-1 hover:text-signal-700">(<?= e(__('Lark settings')) ?>)</a></span>
            <div class="flex gap-2"><button type="submit" formaction="<?= url('/accounting/notify/send') ?>" class="btn-secondary" data-confirm="<?= e(__('Send today\'s list to Lark now?')) ?>"><?= icon('send', 'size-4') ?> <?= e(__('Send now')) ?></button><button class="btn-primary"><?= e(__('Save')) ?></button></div>
        </div>
    </form>
    <section class="panel">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Today\'s list')) ?></h2><span class="text-xs text-steel"><?= count($today) ?></span></div>
        <?php if (! $today): ?><p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('No billing notes are due today.')) ?></p><?php else: ?>
        <ul class="divide-y divide-graphite-900/8 text-sm"><?php foreach ($today as $n): ?><li class="px-5 py-3"><p class="font-medium"><?= e($n['billing_number']) ?> <span class="text-steel">· ฿<?= e(number_format((float) $n['total_amount'], 2)) ?></span></p><p class="truncate text-xs text-steel"><?= e($n['customer_name']) ?></p></li><?php endforeach ?></ul><?php endif ?>
    </section>
</div>
