<?php /* $clientId, $hasSecret, $redirect, $people */ ?>
<div class="min-w-0"><h1 class="page-title"><?= e(__('Connectors')) ?></h1>
    <p class="mt-1 text-sm text-steel"><?= e(__('Let each person connect their own Google account: Gmail and Calendar inside this system.')) ?></p></div>
<div class="mt-4 grid gap-4 lg:grid-cols-3">
    <form method="POST" action="<?= url('/connectors') ?>" class="panel lg:col-span-2"><?= csrf_field() ?>
        <div class="panel-head"><h2 class="panel-title">Google</h2><span class="badge <?= $clientId && $hasSecret ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800' ?>"><?= e($clientId && $hasSecret ? __('Ready') : __('Not set up')) ?></span></div>
        <div class="grid gap-4 p-5">
            <div><label class="label" for="g-id"><?= e(__('OAuth client ID')) ?></label><input id="g-id" name="client_id" value="<?= e($clientId) ?>" class="input font-mono text-xs" autocomplete="off" placeholder="1234567890-abc.apps.googleusercontent.com"></div>
            <div><label class="label" for="g-secret"><?= e(__('OAuth client secret')) ?></label><input id="g-secret" name="client_secret" type="password" class="input font-mono text-xs" autocomplete="new-password" placeholder="<?= $hasSecret ? '•••••••• ('.e(__('saved — leave empty to keep')).')' : '' ?>"></div>
            <div><label class="label"><?= e(__('Authorised redirect URI (copy this into Google Cloud)')) ?></label><input readonly value="<?= e($redirect) ?>" class="input font-mono text-xs" onclick="this.select()"></div>
        </div>
        <div class="flex justify-end border-t border-graphite-900/8 bg-mist/50 px-5 py-3"><button class="btn-primary"><?= e(__('Save')) ?></button></div>
    </form>
    <section class="panel">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('How to set it up')) ?></h2></div>
        <ol class="list-decimal space-y-2 px-8 py-4 text-sm text-steel">
            <li><?= e(__('In Google Cloud Console create a project and enable the Gmail API and the Google Calendar API.')) ?></li>
            <li><?= e(__('Create an OAuth client (type: Web application) and paste the redirect URI shown here.')) ?></li>
            <li><?= e(__('On the consent screen add the scopes gmail.readonly, gmail.send and calendar.')) ?></li>
            <li><?= e(__('Paste the client ID and secret here. Each person then connects under Profile → Connectors.')) ?></li>
        </ol>
        <p class="border-t border-graphite-900/8 px-5 py-3 text-xs text-steel"><?= e(__('Tokens are stored encrypted. Mail is read live from Google and is not copied into this system.')) ?></p>
    </section>
</div>
<section class="panel mt-4 overflow-hidden">
    <div class="panel-head"><h2 class="panel-title"><?= e(__('Connected people')) ?></h2><span class="text-xs text-steel"><?= count($people) ?></span></div>
    <table class="w-full text-sm"><thead class="bg-mist/60 text-left text-xs text-steel"><tr><th class="px-4 py-2 font-medium"><?= e(__('Person')) ?></th><th class="px-3 py-2 font-medium">Google</th><th class="px-3 py-2 font-medium"><?= e(__('Since')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Status')) ?></th><th></th></tr></thead>
        <tbody class="divide-y divide-graphite-900/6"><?php foreach ($people as $p): ?>
            <tr><td class="px-4 py-2.5"><span class="font-medium"><?= e($p['name']) ?></span><span class="block text-xs text-steel"><?= e($p['email']) ?></span></td><td class="px-3 py-2.5"><?= e($p['google_email']) ?></td><td class="px-3 py-2.5 text-steel"><?= e(time_ago($p['connected_at'])) ?></td>
                <td class="px-3 py-2.5"><?= $p['last_error'] ? '<span class="text-signal-700">'.e($p['last_error']).'</span>' : '<span class="text-emerald-700">'.e(__('Working')).'</span>' ?></td>
                <td class="px-3 py-2.5 text-right"><form method="POST" action="<?= url('/connectors/user/'.$p['user_id'].'/disconnect') ?>" data-confirm="<?= e(__('Disconnect this person?')) ?>"><?= csrf_field() ?><button class="btn-danger px-3 py-1 text-xs"><?= e(__('Disconnect')) ?></button></form></td></tr>
        <?php endforeach ?><?php if (! $people): ?><tr><td colspan="5" class="px-4 py-8 text-center text-steel"><?= e(__('Nobody has connected yet.')) ?></td></tr><?php endif ?></tbody></table>
</section>
