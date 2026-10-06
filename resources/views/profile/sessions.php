<?php /* $me, $sessions, $events, $page, $pages, $total, $maxHours */
include __DIR__.'/_header.php';
use App\Core\Auth\SessionGuard;
$label = ['login' => __('Signed in'), 'logout' => __('Signed out'), 'failed' => __('Failed sign-in'), 'revoked' => __('Session ended'), 'expired' => __('Session expired')];
$tone  = ['login' => 'bg-emerald-50 text-emerald-800', 'logout' => 'bg-graphite-900/6 text-graphite-700', 'failed' => 'bg-signal-50 text-signal-800', 'revoked' => 'bg-amber-50 text-amber-800', 'expired' => 'bg-graphite-900/6 text-graphite-700'];
?>
<div class="mt-6 grid items-start gap-6 xl:grid-cols-5">
    <section class="panel xl:col-span-3">
        <div class="panel-head"><div><h2 class="panel-title"><?= e(__('Active sessions')) ?></h2><p class="text-xs text-steel"><?= e(__('Every browser that is signed in to your account. Each one ends by itself :h hours after signing in.', ['h' => $maxHours])) ?></p></div>
            <?php if (count($sessions) > 1): ?><form method="POST" action="<?= url('/profile/sessions/revoke-others') ?>" data-confirm="<?= e(__('Sign out every other device?')) ?>"><?= csrf_field() ?><button class="btn-secondary py-1 text-xs"><?= e(__('Sign out all others')) ?></button></form><?php endif ?></div>
        <ul class="divide-y divide-graphite-900/6">
            <?php foreach ($sessions as $s): $cur = SessionGuard::isCurrent($s); ?>
            <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                <div class="min-w-0">
                    <p class="flex items-center gap-2 text-sm font-medium"><?= e($s['device']) ?><?php if ($cur): ?><span class="badge bg-emerald-50 text-emerald-800"><?= e(__('This device')) ?></span><?php endif ?></p>
                    <p class="text-xs text-steel"><?= e($s['ip']) ?> · <?= e(__('signed in :t', ['t' => time_ago($s['created_at'])])) ?> · <?= e(__('active :t', ['t' => time_ago($s['last_seen_at'])])) ?> · <?= e(__('ends :d', ['d' => format_date($s['expires_at'], 'd M, H:i')])) ?></p>
                </div>
                <form method="POST" action="<?= url('/profile/sessions/'.$s['id'].'/revoke') ?>" data-confirm="<?= e($cur ? __('Sign out of this device?') : __('Sign this device out?')) ?>"><?= csrf_field() ?><button class="btn-danger px-3 py-1.5 text-xs"><?= e($cur ? __('Sign out') : __('Revoke')) ?></button></form>
            </li>
            <?php endforeach ?>
            <?php if (! $sessions): ?><li class="px-5 py-6 text-center text-sm text-steel"><?= e(__('No active sessions.')) ?></li><?php endif ?>
        </ul>
    </section>
    <section class="panel xl:col-span-2">
        <div class="panel-head"><div><h2 class="panel-title"><?= e(__('Device log')) ?></h2><p class="text-xs text-steel"><?= e(__('Sign-ins, sign-outs and failed attempts on your account.')) ?></p></div><span class="text-xs text-steel"><?= (int) $total ?></span></div>
        <ul class="divide-y divide-graphite-900/6">
            <?php foreach ($events as $ev): ?>
            <li class="px-5 py-3"><div class="flex items-center justify-between gap-2"><span class="badge <?= $tone[$ev['event']] ?? $tone['logout'] ?>"><?= e($label[$ev['event']] ?? $ev['event']) ?></span><span class="text-xs text-steel" title="<?= e(format_date($ev['created_at'], 'd M Y, H:i:s')) ?>"><?= e(time_ago($ev['created_at'])) ?></span></div>
                <p class="mt-1 truncate text-sm"><?= e($ev['device']) ?></p><p class="text-xs text-steel"><?= e($ev['ip']) ?><?= $ev['method'] ? ' · '.e($ev['method']) : '' ?><?= $ev['note'] ? ' · '.e($ev['note']) : '' ?></p></li>
            <?php endforeach ?>
            <?php if (! $events): ?><li class="px-5 py-6 text-center text-sm text-steel"><?= e(__('Nothing yet.')) ?></li><?php endif ?>
        </ul>
        <?php if ($pages > 1): ?><div class="flex items-center justify-between border-t border-graphite-900/8 px-5 py-2.5 text-sm text-steel">
            <a class="btn-secondary !h-8 !px-3 <?= $page <= 1 ? 'pointer-events-none opacity-40' : '' ?>" href="<?= url('/profile/sessions', ['page' => $page - 1]) ?>">‹</a><span><?= $page ?> / <?= $pages ?></span>
            <a class="btn-secondary !h-8 !px-3 <?= $page >= $pages ? 'pointer-events-none opacity-40' : '' ?>" href="<?= url('/profile/sessions', ['page' => $page + 1]) ?>">›</a></div><?php endif ?>
    </section>
</div>
