<?php /* $devices, $roles, $log, $errors, $counts, $settings */ ?>
<div class="min-w-0"><h1 class="page-title"><?= e(__('Mobile app & API')) ?></h1>
    <p class="mt-1 text-sm text-steel"><?= e(__('Who is signed in on the mobile app, and every API request. Only people whose role holds “Mobile app & API” may sign in (Roles & access).')) ?></p></div>

<div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <?php foreach ([[__('Signed-in devices'), count($devices)], [__('Requests, last 24 h'), $counts['day']], [__('Refused (401), last 24 h'), $counts['day_401']], [__('Rate-limited (429), last 24 h'), $counts['day_429']]] as [$l, $v]): ?>
        <div class="panel p-4"><p class="text-xs text-steel"><?= e($l) ?></p><p class="mt-1 text-2xl font-semibold tabular-nums"><?= (int) $v ?></p></div>
    <?php endforeach ?>
</div>

<div class="mt-4 grid gap-4 lg:grid-cols-3">
    <section class="panel">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Security settings')) ?></h2></div>
        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 p-5 text-sm">
            <dt class="text-steel"><?= e(__('API')) ?></dt><dd><?= e($settings['enabled'] ? __('On') : __('Off')) ?></dd>
            <dt class="text-steel"><?= e(__('HTTPS only')) ?></dt><dd><?= $settings['https'] ? e(__('Yes')) : '<span class="text-amber-700">'.e(__('No (local mode)')).'</span>' ?></dd>
            <dt class="text-steel"><?= e(__('Access token')) ?></dt><dd><?= e(__(':n minutes', ['n' => $settings['access']])) ?></dd>
            <dt class="text-steel"><?= e(__('Stay signed in when idle')) ?></dt><dd><?= e(__(':n days', ['n' => $settings['refresh']])) ?></dd>
            <dt class="text-steel"><?= e(__('Sign in again after')) ?></dt><dd><?= e(__(':n days', ['n' => $settings['max']])) ?></dd>
            <dt class="text-steel"><?= e(__('Requests per device')) ?></dt><dd><?= e(__(':n per minute', ['n' => $settings['rate']])) ?></dd>
        </dl>
        <p class="border-t border-graphite-900/8 px-5 py-3 text-xs text-steel"><?= e(__('Change these in config/config.php under "api".')) ?></p>
        <div class="border-t border-graphite-900/8 px-5 py-3 text-sm"><span class="text-steel"><?= e(__('Roles allowed')) ?>:</span>
            <?= $roles ? e(implode(', ', array_column($roles, 'name'))) : '<span class="text-amber-700">'.e(__('none yet — only administrators')).'</span>' ?>
            <a href="<?= url('/roles') ?>" class="ml-1 text-signal-700 hover:underline"><?= e(__('Roles & access')) ?></a></div>
    </section>
    <section class="panel overflow-hidden lg:col-span-2">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Signed-in devices')) ?></h2><span class="text-xs text-steel"><?= count($devices) ?></span></div>
        <div class="max-h-96 overflow-auto"><table class="w-full text-sm">
            <thead class="sticky top-0 bg-mist text-left text-xs text-steel"><tr><th class="px-4 py-2 font-medium"><?= e(__('Person')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Device')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Last used')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Ends')) ?></th><th></th></tr></thead>
            <tbody class="divide-y divide-graphite-900/6"><?php foreach ($devices as $d): ?>
                <tr><td class="px-4 py-2"><span class="font-medium"><?= e($d['user_name']) ?></span><span class="block text-xs text-steel"><?= e($d['email']) ?></span></td>
                    <td class="px-3 py-2"><?= e($d['name']) ?><span class="block text-xs text-steel"><?= e($d['platform'].' · '.$d['last_ip']) ?></span></td>
                    <td class="px-3 py-2 text-steel"><?= e(time_ago($d['last_used_at'])) ?></td><td class="px-3 py-2 text-steel"><?= e(format_date($d['expires_at'], 'd M Y')) ?></td>
                    <td class="px-3 py-2 text-right"><form method="POST" action="<?= url('/api-access/devices/'.$d['id'].'/revoke') ?>" data-confirm="<?= e(__('Sign this device out?')) ?>"><?= csrf_field() ?><button class="btn-danger px-3 py-1 text-xs"><?= e(__('Sign out')) ?></button></form></td></tr>
            <?php endforeach ?><?php if (! $devices): ?><tr><td colspan="5" class="px-4 py-8 text-center text-steel"><?= e(__('Nobody is signed in on the mobile app.')) ?></td></tr><?php endif ?></tbody>
        </table></div>
    </section>
</div>

<section class="panel mt-4 overflow-hidden">
    <div class="panel-head"><h2 class="panel-title"><?= e(__('Request log')) ?></h2>
        <a href="<?= url('/api-access', $errors ? [] : ['log' => 'errors']) ?>" class="text-xs font-medium text-signal-700 hover:underline"><?= e($errors ? __('Show all') : __('Only refused / failed')) ?></a></div>
    <div class="max-h-[32rem] overflow-auto"><table class="w-full text-sm">
        <thead class="sticky top-0 bg-mist text-left text-xs text-steel"><tr><th class="px-4 py-2 font-medium"><?= e(__('When')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Person')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Request')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Result')) ?></th><th class="px-3 py-2 text-right font-medium">ms</th><th class="px-3 py-2 font-medium">IP</th></tr></thead>
        <tbody class="divide-y divide-graphite-900/6 font-mono text-xs"><?php foreach ($log as $l): ?>
            <tr><td class="whitespace-nowrap px-4 py-1.5 font-sans text-steel"><?= e(format_date($l['at'], 'd M H:i:s')) ?></td><td class="px-3 py-1.5 font-sans"><?= e($l['user_name'] ?? '—') ?></td>
                <td class="px-3 py-1.5"><?= e($l['method'].' '.$l['path']) ?></td>
                <td class="px-3 py-1.5"><span class="<?= $l['status'] >= 500 ? 'text-signal-800' : ($l['status'] >= 400 ? 'text-amber-700' : 'text-emerald-700') ?>"><?= (int) $l['status'] ?></span> <?= e($l['error'] ?? '') ?></td>
                <td class="px-3 py-1.5 text-right tabular-nums"><?= (int) $l['ms'] ?></td><td class="px-3 py-1.5"><?= e($l['ip']) ?></td></tr>
        <?php endforeach ?><?php if (! $log): ?><tr><td colspan="6" class="px-4 py-8 text-center font-sans text-steel"><?= e(__('No requests yet.')) ?></td></tr><?php endif ?></tbody>
    </table></div>
</section>
