<?php /* $syncs, $everyone, $tables, $accounts, $configured, $officeLabel, $paused */
use App\Modules\Sheets\Support\Catalog;
use App\Modules\Sheets\Support\Schedule;
$admin = auth()->isAdmin();
$sheetUrl = fn (array $s) => 'https://docs.google.com/spreadsheets/d/'.rawurlencode((string) $s['spreadsheet_id']);
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0"><h1 class="page-title"><?= e(__('Google Sheets')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(__('Send tables to your own Google spreadsheets on a schedule. Each sync replaces the whole tab with the latest data.')) ?></p></div>
    <?php if ($tables && $accounts): ?><a href="<?= url('/sheets/new') ?>" class="btn-primary"><?= icon('plus', 'size-4') ?> <?= e(__('New sync')) ?></a><?php endif ?>
</div>

<p class="mt-4 flex items-center gap-2 rounded-lg <?= $paused ? 'bg-amber-50 text-amber-900' : 'bg-mist text-steel' ?> px-4 py-2.5 text-sm">
    <?= icon('clock', 'size-4 shrink-0') ?>
    <?= e($paused ? __('Office hours now (:h): syncing is paused and starts again when they end.', ['h' => $officeLabel]) : __('Syncs never run during office hours (:h).', ['h' => $officeLabel])) ?>
</p>

<div class="mt-4 grid gap-4 lg:grid-cols-3">
    <!-- Syncs -->
    <section class="panel overflow-hidden lg:col-span-2">
        <div class="panel-head">
            <h2 class="panel-title"><?= e($everyone ? __('Everyone\'s syncs') : __('My syncs')) ?></h2>
            <?php if ($admin): ?>
                <a href="<?= url('/sheets', $everyone ? [] : ['all' => 1]) ?>" class="text-xs font-medium text-signal-700 hover:underline"><?= e($everyone ? __('Show mine') : __('Show everyone\'s')) ?></a>
            <?php endif ?>
        </div>
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <thead class="bg-mist/60 text-left text-xs text-steel"><tr>
                <th class="px-4 py-2 font-medium"><?= e(__('Table')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Google Sheet')) ?></th>
                <th class="px-3 py-2 font-medium"><?= e(__('When')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Last run')) ?></th><th class="px-3 py-2"></th></tr></thead>
            <tbody class="divide-y divide-graphite-900/6">
            <?php foreach ($syncs as $s): $active = filter_var($s['active'], FILTER_VALIDATE_BOOL); $mine = (int) $s['user_id'] === auth()->id; ?>
                <tr class="align-top">
                    <td class="px-4 py-2.5"><span class="font-medium"><?= e(Catalog::label($s)) ?></span>
                        <span class="block text-xs text-steel"><?= e(($s['db']).'.'.$s['table_name']) ?><?= $everyone ? ' · '.e($s['owner_name']) : '' ?></span></td>
                    <td class="px-3 py-2.5"><a href="<?= e($sheetUrl($s)) ?>" target="_blank" rel="noopener" class="font-medium hover:text-signal-700"><?= e($s['spreadsheet_name'] ?: $s['spreadsheet_id']) ?></a>
                        <span class="block text-xs text-steel"><?= e(__('Tab')) ?>: <?= e($s['sheet_tab']) ?> · <?= e($s['google_email']) ?></span></td>
                    <td class="px-3 py-2.5"><?= e(Schedule::describe($s)) ?>
                        <span class="block text-xs text-steel"><?= $active && $s['next_run_at'] ? e(__('Next: :t', ['t' => format_date($s['next_run_at'], 'D d M, H:i')])) : e($s['mode'] === 'once' && $s['last_run_at'] ? __('Done') : __('Paused')) ?></span></td>
                    <td class="px-3 py-2.5">
                        <?php if ($s['running_since']): ?><span class="badge bg-sky-50 text-sky-800"><?= e(__('Running')) ?></span>
                        <?php elseif ($s['last_status'] === 'ok'): ?><span class="badge bg-emerald-50 text-emerald-800"><?= e(__('OK')) ?></span>
                        <?php elseif ($s['last_status'] === 'error'): ?><span class="badge bg-signal-50 text-signal-800"><?= e(__('Failed')) ?></span>
                        <?php else: ?><span class="text-steel">—</span><?php endif ?>
                        <?php if ($s['last_run_at']): ?><span class="block text-xs text-steel" title="<?= e(format_date($s['last_run_at'], 'd M Y, H:i')) ?>"><?= e(time_ago($s['last_run_at'])) ?></span><?php endif ?>
                        <?php if ($s['last_message']): ?><span class="block max-w-xs text-xs <?= $s['last_status'] === 'error' ? 'text-signal-800' : 'text-steel' ?>"><?= e($s['last_message']) ?></span><?php endif ?>
                    </td>
                    <td class="whitespace-nowrap px-3 py-2.5 text-right">
                        <form method="POST" action="<?= url('/sheets/'.$s['id'].'/run') ?>" class="inline"><?= csrf_field() ?><button class="btn-ghost px-2 py-1 text-xs" <?= $paused ? 'disabled title="'.e(__('Not during office hours')).'"' : '' ?>><?= icon('refresh', 'size-4') ?> <?= e(__('Sync now')) ?></button></form>
                        <form method="POST" action="<?= url('/sheets/'.$s['id'].'/toggle') ?>" class="inline"><?= csrf_field() ?><button class="btn-ghost px-2 py-1 text-xs"><?= e($active ? __('Pause') : __('Resume')) ?></button></form>
                        <?php if ($mine): ?><a href="<?= url('/sheets/'.$s['id'].'/edit') ?>" class="btn-ghost px-2 py-1 text-xs"><?= icon('pencil', 'size-4') ?></a><?php endif ?>
                        <form method="POST" action="<?= url('/sheets/'.$s['id'].'/delete') ?>" class="inline" data-confirm="<?= e(__('Remove this sync? The Google Sheet itself is kept.')) ?>"><?= csrf_field() ?><button class="btn-ghost px-2 py-1 text-xs text-signal-700" aria-label="<?= e(__('Remove')) ?>"><?= icon('trash', 'size-4') ?></button></form>
                    </td>
                </tr>
            <?php endforeach ?>
            <?php if (! $syncs): ?><tr><td colspan="5" class="px-4 py-10 text-center text-steel">
                <?= e($tables ? ($accounts ? __('No syncs yet. Pick a table on the right to start.') : __('Connect a Google account first (on the right).')) : __('No table has been opened for you yet. Ask an administrator.')) ?></td></tr><?php endif ?>
            </tbody>
        </table></div>
    </section>

    <div class="grid content-start gap-4">
        <!-- Google accounts -->
        <section class="panel">
            <div class="panel-head"><h2 class="panel-title"><?= e(__('Google accounts')) ?></h2><span class="text-xs text-steel"><?= count($accounts) ?></span></div>
            <ul class="divide-y divide-graphite-900/6 text-sm">
                <?php foreach ($accounts as $a): ?>
                    <li class="flex items-center gap-2 px-5 py-2.5">
                        <span class="min-w-0 flex-1"><span class="block truncate font-medium"><?= e($a['google_email']) ?></span>
                            <span class="block text-xs <?= $a['last_error'] ? 'text-signal-700' : 'text-steel' ?>"><?= e($a['last_error'] ?: __('Connected :t', ['t' => time_ago($a['connected_at'])])) ?></span></span>
                        <form method="POST" action="<?= url('/sheets/accounts/'.$a['id'].'/disconnect') ?>" data-confirm="<?= e(__('Disconnect :e? Its syncs are removed too.', ['e' => $a['google_email']])) ?>"><?= csrf_field() ?><button class="btn-ghost px-2 py-1 text-xs"><?= e(__('Disconnect')) ?></button></form>
                    </li>
                <?php endforeach ?>
            </ul>
            <div class="border-t border-graphite-900/8 px-5 py-3">
                <?php if ($configured): ?>
                    <a href="<?= url('/sheets/connect') ?>" class="btn-secondary w-full justify-center"><?= icon('plus', 'size-4') ?> <?= e($accounts ? __('Connect another Google account') : __('Connect a Google account')) ?></a>
                <?php else: ?>
                    <p class="text-xs text-steel"><?= e(__('The administrator has not set up the Google connector yet.')) ?><?php if ($admin): ?> <a href="<?= url('/connectors') ?>" class="text-signal-700 hover:underline"><?= e(__('Set it up')) ?></a><?php endif ?></p>
                <?php endif ?>
            </div>
        </section>

        <!-- Tables I may sync -->
        <section class="panel">
            <div class="panel-head"><h2 class="panel-title"><?= e(__('Tables you can sync')) ?></h2><span class="text-xs text-steel"><?= count($tables) ?></span></div>
            <ul class="divide-y divide-graphite-900/6 text-sm">
                <?php foreach ($tables as $t): ?>
                    <li class="flex items-center gap-2 px-5 py-2.5">
                        <?= icon('table', 'size-4 shrink-0 text-steel') ?>
                        <span class="min-w-0 flex-1"><span class="block truncate font-medium"><?= e(Catalog::label($t)) ?></span><span class="block text-xs text-steel"><?= e($t['db'].'.'.$t['table_name']) ?></span></span>
                        <?php if ($accounts): ?><a href="<?= url('/sheets/new', ['table' => $t['id']]) ?>" class="btn-ghost px-2 py-1 text-xs"><?= e(__('Sync to a sheet')) ?></a><?php endif ?>
                    </li>
                <?php endforeach ?>
                <?php if (! $tables): ?><li class="px-5 py-6 text-center text-steel"><?= e(__('None yet.')) ?><?php if ($admin): ?> <a href="<?= url('/sheets/tables') ?>" class="text-signal-700 hover:underline"><?= e(__('Open tables')) ?></a><?php endif ?></li><?php endif ?>
            </ul>
        </section>
    </div>
</div>
