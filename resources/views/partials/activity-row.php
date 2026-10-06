<?php
$tones = [
    'created' => 'bg-emerald-50 text-emerald-800', 'updated' => 'bg-sky-50 text-sky-800', 'deleted' => 'bg-signal-50 text-signal-800',
    'login_failed' => 'bg-signal-50 text-signal-800', 'password_reset' => 'bg-amber-50 text-amber-800', 'password_changed' => 'bg-amber-50 text-amber-800',
    'import' => 'bg-violet-50 text-violet-800', 'export' => 'bg-violet-50 text-violet-800', 'download' => 'bg-violet-50 text-violet-800',
];
$showUser ??= true;
$link = can('activity_logs') ? url('/activity/'.$log['id']) : null;
?>
<li class="flex items-start gap-3 px-5 py-3">
    <?php if ($showUser): ?><?= partial('partials/avatar', ['name' => $log['user_name'] ?? __('System'), 'avatar' => $log['avatar'] ?? null, 'size' => 'size-8', 'extra' => 'mt-0.5']) ?><?php endif ?>
    <div class="min-w-0 flex-1">
        <p class="text-sm">
            <?php if ($showUser): ?><span class="font-medium"><?= e($log['user_name'] ?? __('System')) ?></span><?php endif ?>
            <?php if ($link): ?><a href="<?= $link ?>" class="text-graphite-800 hover:text-signal-700"><?php endif ?>
            <?php $txt = activity_text($log); ?><?= e($showUser && locale() === 'en' ? lcfirst($txt) : $txt) ?>
            <?php if ($link): ?></a><?php endif ?>
        </p>
        <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-steel">
            <span class="badge <?= $tones[$log['action']] ?? 'bg-graphite-900/6 text-graphite-800' ?>"><?= e(App\Core\Http\Controllers\ActivityController::actions()[$log['action']] ?? __(str_replace('_', ' ', $log['action']))) ?></span>
            <time datetime="<?= e(date('c', strtotime($log['created_at']))) ?>" title="<?= e(format_date($log['created_at'], 'd M Y H:i')) ?>"><?= e(time_ago($log['created_at'])) ?></time>
            <?php if ($log['ip_address']): ?><span class="hidden sm:inline"><?= e($log['ip_address']) ?></span><?php endif ?>
        </p>
    </div>
</li>
