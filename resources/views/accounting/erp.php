<?php
use App\Modules\Accounting\Erp\ErpSettings as S;
/* $totals, $running, $state, $count, $token, $runs, $hasPassword, $hasInet, $configured, $inWindow, $cron */
$entities  = config('erp.entities');
$byApi     = [];
foreach ($entities as $k => $d) { $byApi[$d['api']][] = $k; }
$groupTone = ['hot' => 'bg-signal-50 text-signal-800', 'cold' => 'bg-sky-50 text-sky-800', 'full' => 'bg-graphite-900/6 text-graphite-800'];
$stateTone = ['ok' => 'bg-emerald-50 text-emerald-800', 'failed' => 'bg-signal-50 text-signal-800', 'running' => 'bg-amber-50 text-amber-800', 'never' => 'bg-graphite-900/6 text-graphite-700'];
$days = ['1' => __('Mon'), '2' => __('Tue'), '3' => __('Wed'), '4' => __('Thu'), '5' => __('Fri'), '6' => __('Sat'), '7' => __('Sun')];
$onDays = array_filter(explode(',', S::schedule('days')));
$age = $token ? (int) round((time() - strtotime((string) $token['updated_at'])) / 60) : null;
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
        <p class="text-sm text-steel"><a href="<?= url('/accounting') ?>" class="hover:text-signal-700"><?= e(__('Accounting')) ?></a> › <?= e(__('Setup')) ?></p>
        <h1 class="page-title"><?= e(__('ERP connection')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(__('Administrators only. The ERP is read through its API and copied into this system on a schedule.')) ?></p>
    </div>
    <div class="flex flex-wrap gap-2">
        <form method="POST" action="<?= url('/accounting/erp/login') ?>"><?= csrf_field() ?><button class="btn-secondary"><?= icon('shield', 'size-4') ?> <?= e(__('Test login / get a new token')) ?></button></form>
        <form method="POST" action="<?= url('/accounting/erp/run') ?>"><?= csrf_field() ?><input type="hidden" name="group" value="all"><input type="hidden" name="mode" value="latest"><button class="btn-secondary" <?= $configured ? '' : 'disabled' ?> title="<?= e(__('The newest rows of everything, now, even outside the working hours')) ?>"><?= icon('upload', 'size-4') ?> <?= e(__('Force sync now')) ?></button></form>
        <form method="POST" action="<?= url('/accounting/erp/run') ?>" onsubmit="return confirm('<?= e(__('Copy EVERY row of every API? This can take a long time.')) ?>')"><?= csrf_field() ?><input type="hidden" name="group" value="all"><input type="hidden" name="mode" value="all"><button class="btn-primary" <?= $configured ? '' : 'disabled' ?>><?= icon('upload', 'size-4') ?> <?= e(__('Force full copy (all rows)')) ?></button></form>
    </div>
</div>

<nav class="mt-4 flex flex-wrap gap-1 border-b border-graphite-900/10" role="tablist" id="erp-tabs">
    <?php foreach (['status' => __('Status'), 'connection' => __('Connection'), 'schedule' => __('Schedule'), 'inet' => __('e-Tax portal'), 'apis' => __('APIs')] as $k => $l): ?>
    <button type="button" role="tab" data-tab="<?= $k ?>" class="-mb-px border-b-2 border-transparent px-4 py-2 text-sm font-medium text-steel hover:text-graphite-900 aria-selected:border-signal-600 aria-selected:text-signal-700"><?= e($l) ?></button>
    <?php endforeach ?>
</nav>

<div data-pane="status">
<?php $sumRows = array_sum($count); $beat = \App\Modules\Accounting\Erp\Schedule::heartbeat(); ?>
<div id="erp-live" data-url="<?= url('/accounting/erp/status') ?>" data-l-saved="<?= e(__('saved')) ?>" data-l-read="<?= e(__('read')) ?>" data-l-syncing="<?= e(__('Syncing now')) ?>" data-l-idle="<?= e(__('Idle')) ?>" data-l-on="<?= e(__('Schedule on')) ?>" data-l-off="<?= e(__('Schedule off')) ?>" data-l-looked="<?= e(__('looked')) ?>" data-l-ago="<?= e(__('ago')) ?>" data-l-never="<?= e(__('has not run yet')) ?>" data-l-stale="<?= e(__('The scheduler is not running: set up the cron line below, or keep an accounting page open.')) ?>" data-l-outside="<?= e(__('outside the working window')) ?>" class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl bg-white px-4 py-2.5 text-sm shadow-sm ring-1 ring-graphite-900/8">
    <span class="inline-flex items-center gap-1.5 font-medium"><span data-live-dot class="size-2 rounded-full bg-emerald-500"></span><span data-live-running><?= $running ? e(__('Syncing now')) : e(__('Idle')) ?></span></span>
    <span class="text-steel" data-live-sched><?= e(S::schedule('enabled') === '1' ? __('Schedule on') : __('Schedule off')) ?><?= $beat ? ' · '.e(__('looked :ago ago', ['ago' => max(0, time() - $beat['at']).'s'])).' ('.e($beat['by']).')' : ' · '.e(__('has not run yet')) ?></span>
    <span class="ml-auto text-xs text-steel" data-live-clock></span>
</div>
<section class="mt-3 grid grid-cols-2 gap-3 lg:grid-cols-5">
    <?php foreach ([['today', __('Rows saved today'), number_format((int) $totals['today']), 'text-graphite-900'], ['all', __('Rows saved in all runs'), number_format((int) $totals['all_time']), 'text-graphite-900'],
        ['rows', __('Rows in the tables now'), number_format($sumRows), 'text-graphite-900'], ['last_ok', __('Last good run'), $totals['last_ok'] ? time_ago($totals['last_ok']) : '—', 'text-graphite-900'],
        ['failed', __('Failed runs (24 h)'), (string) (int) $totals['failed_day'], (int) $totals['failed_day'] ? 'text-signal-700' : 'text-graphite-900']] as [$key, $l, $val, $c]): ?>
    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-graphite-900/8"><p class="text-xs text-steel"><?= e($l) ?></p><p data-stat="<?= $key ?>" class="truncate text-xl font-semibold tabular-nums <?= $c ?>"><?= e($val) ?></p></div>
    <?php endforeach ?>
</section>

<section class="panel mt-3">
    <div class="panel-head"><h2 class="panel-title"><?= e(__('Live sync log')) ?></h2><a href="<?= url('/accounting/logs') ?>" class="text-xs text-steel hover:text-signal-700"><?= e(__('Open all logs')) ?> →</a></div>
    <div id="erp-log" data-empty="<?= e(__('No sync lines yet today.')) ?>" class="h-56 overflow-auto rounded-b-xl bg-graphite-900 p-3 font-mono text-xs leading-5 text-white"></div>
</section>
<section class="panel mt-3" id="erp-runs" data-url="<?= url('/accounting/erp/runs') ?>">
    <div class="panel-head">
        <h2 class="panel-title"><?= e(__('Recent runs')) ?></h2>
        <div class="flex items-center gap-2">
            <label class="sr-only" for="runs-status"><?= e(__('Status')) ?></label>
            <select id="runs-status" data-runs-status class="input !h-8 !w-auto !py-0 text-sm"><option value=""><?= e(__('All')) ?></option><option value="ok"><?= e(__('Success')) ?></option><option value="warning"><?= e(__('Warning')) ?></option><option value="failed"><?= e(__('Failed')) ?></option><option value="running"><?= e(__('Running')) ?></option></select>
        </div>
    </div>
    <div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-mist/60 text-left text-xs text-steel"><tr>
        <th class="px-4 py-2 font-medium">#</th><th class="px-3 py-2 font-medium"><?= e(__('Run')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Status')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Started')) ?></th><th class="px-3 py-2 text-right font-medium"><?= e(__('Time')) ?></th>
        <th class="px-3 py-2 text-right font-medium"><?= e(__('Read')) ?></th><th class="px-3 py-2 text-right font-medium"><?= e(__('Saved')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Message')) ?></th></tr></thead>
        <tbody data-runs-body class="divide-y divide-graphite-900/6"><tr><td colspan="8" class="px-4 py-8 text-center text-steel"><?= e(__('Loading…')) ?></td></tr></tbody></table></div>
    <div class="flex items-center justify-between gap-3 border-t border-graphite-900/8 px-4 py-2.5 text-sm text-steel">
        <span data-runs-info></span>
        <div class="flex items-center gap-1"><button type="button" class="btn-secondary !h-8 !px-3" data-runs-prev aria-label="<?= e(__('Previous')) ?>">‹</button><span class="min-w-16 text-center tabular-nums" data-runs-page>1 / 1</span><button type="button" class="btn-secondary !h-8 !px-3" data-runs-next aria-label="<?= e(__('Next')) ?>">›</button></div>
    </div>
</section>
</div>

<div class="mt-4 grid gap-4 lg:grid-cols-3" data-pane="connection schedule inet" hidden>
    <!-- connection -->
    <form method="POST" action="<?= url('/accounting/erp/connection') ?>" class="panel lg:col-span-2">
        <?= csrf_field() ?>
        <div data-pane="connection">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Connection')) ?></h2><span class="text-xs text-steel"><?= e(S::baseUrl() ?: __('not set')) ?></span></div>
        <div class="grid gap-4 p-5 sm:grid-cols-2">
            <div><label class="label" for="erp-ip"><?= e(__('Server address')) ?></label><input id="erp-ip" name="ip" value="<?= e(S::conn('ip')) ?>" class="input" placeholder="5.223.76.93"><?= field_error('ip') ?></div>
            <div><label class="label" for="erp-port"><?= e(__('Port')) ?></label><input id="erp-port" name="port" value="<?= e(S::conn('port')) ?>" class="input" inputmode="numeric" placeholder="8001"></div>
            <div><label class="label" for="erp-user"><?= e(__('User name')) ?></label><input id="erp-user" name="username" value="<?= e(S::conn('username')) ?>" class="input" autocomplete="off"></div>
            <div><label class="label" for="erp-pass"><?= e(__('Password')) ?></label><input id="erp-pass" name="password" type="password" class="input" autocomplete="new-password" placeholder="<?= $hasPassword ? '•••••••• ('.e(__('saved — leave empty to keep')).')' : '' ?>"></div>
            <div><label class="label" for="erp-timeout"><?= e(__('Wait for an answer (seconds)')) ?></label><input id="erp-timeout" name="timeout" value="<?= e(S::conn('timeout')) ?>" class="input" inputmode="numeric"></div>
            <label class="flex items-start gap-2 text-sm sm:pt-6"><input type="checkbox" name="verify_tls" value="1" class="mt-0.5 size-4 accent-signal-600" <?= S::conn('verify_tls') === '1' ? 'checked' : '' ?>> <span><?= e(__('Check the server certificate')) ?><span class="block text-xs text-steel"><?= e(__('Leave off for an ERP with a self-signed certificate (as the old system did).')) ?></span></span></label>
        </div>

        </div>
        <div data-pane="schedule" hidden>
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Schedule')) ?></h2><span class="text-xs <?= $inWindow ? 'text-emerald-700' : 'text-steel' ?>"><?= e($inWindow ? __('inside the working window now') : __('outside the working window now')) ?></span></div>
        <div class="grid gap-4 p-5 sm:grid-cols-2">
            <label class="flex items-start gap-2 text-sm sm:col-span-2"><input type="checkbox" name="enabled" value="1" class="mt-0.5 size-4 accent-signal-600" <?= S::schedule('enabled') === '1' ? 'checked' : '' ?>> <span class="font-medium"><?= e(__('Run the schedule')) ?><span class="block text-xs font-normal text-steel"><?= e(__('Needs the cron line below. Hot = newest rows of orders, invoices, customers …; cold = reference data.')) ?></span></span></label>
            <div><label class="label" for="s-hot"><?= e(__('Hot sync every (minutes)')) ?></label><input id="s-hot" name="hot_minutes" value="<?= e(S::schedule('hot_minutes')) ?>" class="input" inputmode="numeric"></div>
            <div><label class="label" for="s-cold"><?= e(__('Cold sync every (minutes)')) ?></label><input id="s-cold" name="cold_minutes" value="<?= e(S::schedule('cold_minutes')) ?>" class="input" inputmode="numeric"></div>
            <div><label class="label" for="s-from"><?= e(__('From hour')) ?></label><input id="s-from" name="hour_start" value="<?= e(S::schedule('hour_start')) ?>" class="input" inputmode="numeric"></div>
            <div><label class="label" for="s-to"><?= e(__('Until hour (not included)')) ?></label><input id="s-to" name="hour_end" value="<?= e(S::schedule('hour_end')) ?>" class="input" inputmode="numeric"></div>
            <div><label class="label" for="s-tz"><?= e(__('Time zone')) ?></label><input id="s-tz" name="tz" value="<?= e(S::schedule('tz')) ?>" class="input"></div>
            <div><p class="label"><?= e(__('Days')) ?></p><div class="flex flex-wrap gap-1.5">
                <?php foreach ($days as $n => $l): ?><label class="cursor-pointer"><input type="checkbox" name="days[]" value="<?= $n ?>" class="peer sr-only" <?= in_array((string) $n, $onDays, true) ? 'checked' : '' ?>><span class="inline-flex h-8 items-center rounded-full px-3 text-sm ring-1 ring-graphite-900/15 peer-checked:bg-graphite-900 peer-checked:text-white peer-checked:ring-graphite-900"><?= e($l) ?></span></label><?php endforeach ?>
            </div></div>
            <div class="sm:col-span-2"><p class="label"><?= e(__('Cron line (run every minute)')) ?> <span class="font-normal text-steel">— <?= e(__('optional: without it the website starts the schedule while someone has an accounting page open')) ?></span></p><code class="block overflow-x-auto rounded-lg bg-graphite-900 px-3 py-2 text-xs text-white"><?= e($cron) ?></code></div>
        </div>

        </div>
        <div data-pane="inet" hidden>
        <div class="panel-head"><h2 class="panel-title"><?= e(__('e-Tax portal (INET)')) ?></h2><span class="text-xs text-steel"><?= e(__('used by the e-tax invoice step that follows')) ?></span></div>
        <div class="grid gap-4 p-5">
            <?php foreach (['sendApi' => __('Send document'), 'statusApi' => __('Document status'), 'paramsApi' => __('Document parameters')] as $k => $l): ?>
            <div><label class="label" for="inet-<?= $k ?>"><?= e($l) ?></label><input id="inet-<?= $k ?>" name="inet_<?= $k ?>" value="<?= e(S::inet($k)) ?>" class="input"><?= field_error('inet_'.$k) ?></div>
            <?php endforeach ?>
            <div><label class="label" for="inet-auth"><?= e(__('Authorization key')) ?></label><input id="inet-auth" name="inet_authorization" type="password" class="input" autocomplete="new-password" placeholder="<?= $hasInet ? '•••••••• ('.e(__('saved — leave empty to keep')).')' : '' ?>"></div>
        </div>
        </div>
        <div class="flex justify-end border-t border-graphite-900/8 bg-mist/50 px-5 py-3"><button class="btn-primary"><?= e(__('Save')) ?></button></div>
    </form>

    <!-- token -->
    <div class="space-y-4" data-pane="connection">
        <section class="panel">
            <div class="panel-head"><h2 class="panel-title"><?= e(__('Token (session)')) ?></h2></div>
            <dl class="divide-y divide-graphite-900/6 text-sm">
                <div class="flex justify-between gap-3 px-5 py-3"><dt class="text-steel"><?= e(__('Status')) ?></dt><dd class="font-medium">
                    <?php if (! $token): ?><?= e(__('No token yet')) ?>
                    <?php elseif ($age > 60 || in_array($token['session_suspended'], [true, 't', 'true'], true)): ?><span class="text-signal-700"><?= e(__('Expired — a new one is requested when needed')) ?></span>
                    <?php else: ?><span class="text-emerald-700"><?= e(__('Valid')) ?></span><?php endif ?></dd></div>
                <?php if ($token): ?>
                <div class="flex justify-between gap-3 px-5 py-3"><dt class="text-steel"><?= e(__('Session')) ?></dt><dd class="font-mono text-xs"><?= e(substr((string) $token['session_id'], 0, 6)) ?>…</dd></div>
                <div class="flex justify-between gap-3 px-5 py-3"><dt class="text-steel"><?= e(__('Got')) ?></dt><dd><?= e(time_ago($token['updated_at'])) ?></dd></div>
                <?php endif ?>
                <div class="px-5 py-3 text-xs text-steel"><?= e(__('The session is saved in the database and reused for an hour, like the old system. A 401 answer gets a new login automatically.')) ?></div>
            </dl>
        </section>
    </div>
</div>

<!-- all APIs -->
<form method="POST" action="<?= url('/accounting/erp/endpoints') ?>" class="panel mt-4 overflow-hidden" id="apis" data-pane="apis" hidden>
    <?= csrf_field() ?><input type="hidden" name="mode" value="latest" data-mode><input type="hidden" name="overwrite_form" value="1">
    <div class="panel-head">
        <h2 class="panel-title"><?= e(__('APIs')) ?> <span class="ml-1 text-sm font-normal text-steel"><?= count(config('erp.endpoints')) ?></span></h2>
        <div class="flex flex-wrap items-center gap-2">
            <button type="submit" formaction="<?= url('/accounting/erp/run') ?>" name="group" value="hot" class="btn-secondary py-1" <?= $configured ? '' : 'disabled' ?>><?= e(__('Run hot now')) ?></button>
            <button type="submit" formaction="<?= url('/accounting/erp/run') ?>" name="group" value="cold" class="btn-secondary py-1" <?= $configured ? '' : 'disabled' ?>><?= e(__('Run cold now')) ?></button>
            <button type="submit" formaction="<?= url('/accounting/erp/run') ?>" name="group" value="full" class="btn-secondary py-1" <?= $configured ? '' : 'disabled' ?>><?= e(__('Run the rest (full)')) ?></button>
            <button class="btn-primary py-1"><?= e(__('Save addresses')) ?></button>
        </div>
    </div>
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead class="bg-mist/60 text-left text-xs text-steel"><tr>
            <th class="px-4 py-2 font-medium"><?= e(__('API')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Address (added to the server address)')) ?></th>
            <th class="px-3 py-2 font-medium"><?= e(__('Copied into')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Last sync')) ?></th><th class="px-3 py-2 text-right font-medium"><?= e(__('Rows')) ?></th><th class="px-3 py-2"></th>
        </tr></thead>
        <tbody class="divide-y divide-graphite-900/6">
        <?php foreach (config('erp.endpoints') as $key => [$default, $label]): $ents = $byApi[$key] ?? []; ?>
            <tr class="align-top">
                <td class="px-4 py-2.5"><span class="font-medium"><?= e($label) ?></span><span class="block font-mono text-[11px] text-steel"><?= e($key) ?></span></td>
                <td class="px-3 py-2.5"><input name="api[<?= e($key) ?>]" value="<?= e(S::endpoint($key)) ?>" class="input font-mono text-xs" aria-label="<?= e($label) ?>"></td>
                <td class="px-3 py-2.5">
                    <?php foreach ($ents as $k): ?><span class="mb-1 flex items-center gap-1.5"><span class="badge <?= $groupTone[$entities[$k]['group']] ?>"><?= e($entities[$k]['group']) ?></span><span class="text-xs"><?= e($entities[$k]['label']) ?></span>
                        <label class="ml-1 inline-flex cursor-pointer items-center gap-1 text-[11px] text-steel" title="<?= e(__('Off: only new rows are added. On: rows that already exist are updated too.')) ?>"><input type="checkbox" name="overwrite[<?= e($k) ?>]" value="1" class="size-3.5 accent-signal-600" <?= S::overwrite($k) ? 'checked' : '' ?>> <?= e(__('Overwrite')) ?></label></span><?php endforeach ?>
                    <?php if (! $ents): ?><span class="text-xs text-steel"><?= e($key === 'tokenUrl' ? __('login') : __('not copied yet')) ?></span><?php endif ?>
                </td>
                <td class="px-3 py-2.5">
                    <?php foreach ($ents as $k): $st = $state[$k] ?? null; ?>
                    <span class="mb-1 block text-xs" data-state="<?= e($k) ?>"><span class="badge <?= $stateTone[$st['status'] ?? 'never'] ?>"><?= e($st['status'] ?? 'never') ?></span>
                        <?php if ($st && $st['last_run_at']): ?> <?= e(time_ago($st['last_run_at'])) ?> · <?= number_format((int) $st['saved']) ?> <?= e(__('saved')) ?><?= $st['duration_ms'] ? ' · '.round($st['duration_ms'] / 1000, 1).'s' : '' ?><?php endif ?>
                        <?php if ($st && $st['error']): ?><span class="block max-w-xs truncate text-signal-700" title="<?= e($st['error']) ?>"><?= e($st['error']) ?></span><?php endif ?></span>
                    <?php endforeach ?>
                </td>
                <td class="px-3 py-2.5 text-right tabular-nums"><?php foreach ($ents as $k): ?><span class="mb-1 block text-xs"><?= number_format($count[$k]) ?></span><?php endforeach ?></td>
                <td class="whitespace-nowrap px-3 py-2.5 text-right">
                    <?php if ($key !== 'tokenUrl'): ?>
                    <button type="submit" formaction="<?= url('/accounting/erp/test') ?>" name="key" value="<?= e($key) ?>" class="btn-ghost !h-7 px-2 text-xs" <?= $configured ? '' : 'disabled' ?> title="<?= e(__('Ask this API for one row')) ?>"><?= e(__('Test')) ?></button>
                    <?php foreach ($ents as $k): ?>
                    <button type="submit" formaction="<?= url('/accounting/erp/run') ?>" name="entity" value="<?= e($k) ?>" class="btn-secondary !h-7 px-2 text-xs" <?= $configured ? '' : 'disabled' ?> title="<?= e(__('Newest :n rows', ['n' => number_format($entities[$k]['size'])])) ?>"><?= e(__('Sync')) ?></button>
                    <button type="submit" formaction="<?= url('/accounting/erp/run') ?>" name="entity" value="<?= e($k) ?>" formnovalidate onclick="this.form.querySelector('[data-mode]').value='all'" class="btn-ghost !h-7 px-2 text-xs" <?= $configured ? '' : 'disabled' ?> title="<?= e(__('Copy every row of this API')) ?>"><?= e(__('Full')) ?></button>
                    <?php endforeach ?>
                    <?php endif ?>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <p class="border-t border-graphite-900/8 bg-mist/50 px-5 py-2 text-xs text-steel"><?= e(__('"Sync" copies the newest rows (the number is in config/erp.php); "Full" copies every row. The numbers show how many rows each run read and saved.')) ?></p>
</form>
<script src="<?= asset('assets/erp-live.js') ?>" defer></script>
