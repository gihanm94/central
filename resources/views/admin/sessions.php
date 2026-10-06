<?php /* $tab, $q, $rows, $page, $pages, $total, $activeCount, $maxHours */
use App\Core\Auth\SessionGuard;
$label = ['login' => __('Signed in'), 'logout' => __('Signed out'), 'failed' => __('Failed sign-in'), 'revoked' => __('Session ended'), 'expired' => __('Session expired')];
$tone  = ['login' => 'bg-emerald-50 text-emerald-800', 'logout' => 'bg-graphite-900/6 text-graphite-700', 'failed' => 'bg-signal-50 text-signal-800', 'revoked' => 'bg-amber-50 text-amber-800', 'expired' => 'bg-graphite-900/6 text-graphite-700'];
$link = fn (array $extra) => url('/sessions', array_filter(['tab' => $tab === 'log' ? 'log' : null, 'q' => $q] + $extra));
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0"><h1 class="page-title"><?= e(__('Sessions')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(__('Who is signed in, from which device. The browser id changes every :m minutes and every sign-in ends after :h hours.', ['m' => SessionGuard::rotateMinutes(), 'h' => $maxHours])) ?></p></div>
</div>
<nav class="mt-4 flex gap-1 border-b border-graphite-900/10">
    <a href="<?= url('/sessions') ?>" class="-mb-px border-b-2 px-4 py-2 text-sm font-medium <?= $tab === 'active' ? 'border-signal-600 text-signal-700' : 'border-transparent text-steel hover:text-graphite-900' ?>"><?= e(__('Active sessions')) ?> <span class="ml-1 rounded-full bg-mist px-2 text-xs"><?= (int) $activeCount ?></span></a>
    <a href="<?= url('/sessions', ['tab' => 'log']) ?>" class="-mb-px border-b-2 px-4 py-2 text-sm font-medium <?= $tab === 'log' ? 'border-signal-600 text-signal-700' : 'border-transparent text-steel hover:text-graphite-900' ?>"><?= e(__('Sign-in log')) ?></a>
</nav>
<section class="panel mt-4 overflow-hidden">
    <form method="GET" action="<?= url('/sessions') ?>" class="flex flex-wrap items-center gap-2 border-b border-graphite-900/8 p-3">
        <?php if ($tab === 'log'): ?><input type="hidden" name="tab" value="log"><?php endif ?>
        <input name="q" value="<?= e($q) ?>" class="input max-w-xs" placeholder="<?= e(__('Search person, device or IP…')) ?>"><button class="btn-secondary"><?= e(__('Search')) ?></button>
        <span class="ml-auto text-sm text-steel"><?= (int) $total ?> <?= e(__('rows')) ?></span>
    </form>
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <?php if ($tab === 'active'): ?>
        <thead class="bg-mist/60 text-left text-xs text-steel"><tr><th class="px-4 py-2 font-medium"><?= e(__('Person')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Device')) ?></th><th class="px-3 py-2 font-medium">IP</th><th class="px-3 py-2 font-medium"><?= e(__('Signed in')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Last active')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Ends')) ?></th><th class="px-3 py-2"></th></tr></thead>
        <tbody class="divide-y divide-graphite-900/6">
        <?php foreach ($rows as $r): $cur = SessionGuard::isCurrent($r); ?>
            <tr><td class="px-4 py-2.5"><span class="font-medium"><?= e($r['name']) ?></span><span class="block text-xs text-steel"><?= e($r['email']) ?></span></td>
                <td class="px-3 py-2.5"><?= e($r['device']) ?><?= $cur ? ' <span class="badge bg-emerald-50 text-emerald-800">'.e(__('You')).'</span>' : '' ?><span class="block text-xs text-steel"><?= e($r['method']) ?></span></td>
                <td class="px-3 py-2.5 tabular-nums"><?= e($r['ip']) ?></td><td class="px-3 py-2.5 text-steel"><?= e(time_ago($r['created_at'])) ?></td><td class="px-3 py-2.5 text-steel"><?= e(time_ago($r['last_seen_at'])) ?></td><td class="px-3 py-2.5 text-steel"><?= e(format_date($r['expires_at'], 'd M, H:i')) ?></td>
                <td class="whitespace-nowrap px-3 py-2.5 text-right"><form method="POST" action="<?= url('/sessions/'.$r['id'].'/revoke') ?>" class="inline" data-confirm="<?= e(__('End this session? That person is signed out.')) ?>"><?= csrf_field() ?><button class="btn-danger px-3 py-1 text-xs"><?= e(__('Revoke')) ?></button></form>
                    <form method="POST" action="<?= url('/sessions/user/'.$r['user_id'].'/revoke') ?>" class="inline" data-confirm="<?= e(__('End every session of :n?', ['n' => $r['name']])) ?>"><?= csrf_field() ?><button class="btn-ghost px-2 py-1 text-xs"><?= e(__('All of theirs')) ?></button></form></td></tr>
        <?php endforeach ?>
        <?php if (! $rows): ?><tr><td colspan="7" class="px-4 py-8 text-center text-steel"><?= e(__('No active sessions.')) ?></td></tr><?php endif ?>
        </tbody>
        <?php else: ?>
        <thead class="bg-mist/60 text-left text-xs text-steel"><tr><th class="px-4 py-2 font-medium"><?= e(__('When')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Event')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Person')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Device')) ?></th><th class="px-3 py-2 font-medium">IP</th><th class="px-3 py-2 font-medium"><?= e(__('Note')) ?></th></tr></thead>
        <tbody class="divide-y divide-graphite-900/6">
        <?php foreach ($rows as $r): ?>
            <tr><td class="whitespace-nowrap px-4 py-2.5 text-steel" title="<?= e(format_date($r['created_at'], 'd M Y, H:i:s')) ?>"><?= e(format_date($r['created_at'], 'd M, H:i')) ?></td><td class="px-3 py-2.5"><span class="badge <?= $tone[$r['event']] ?? $tone['logout'] ?>"><?= e($label[$r['event']] ?? $r['event']) ?></span></td>
                <td class="px-3 py-2.5"><span class="font-medium"><?= e($r['name'] ?: '—') ?></span><span class="block text-xs text-steel"><?= e($r['mail']) ?></span></td><td class="px-3 py-2.5"><?= e($r['device']) ?></td><td class="px-3 py-2.5 tabular-nums"><?= e($r['ip']) ?></td><td class="px-3 py-2.5 text-xs text-steel"><?= e(trim($r['method'].' '.$r['note'])) ?></td></tr>
        <?php endforeach ?>
        <?php if (! $rows): ?><tr><td colspan="6" class="px-4 py-8 text-center text-steel"><?= e(__('Nothing yet.')) ?></td></tr><?php endif ?>
        </tbody>
        <?php endif ?>
    </table></div>
    <?php if ($pages > 1): ?><div class="flex items-center justify-between border-t border-graphite-900/8 px-4 py-2.5 text-sm text-steel">
        <a class="btn-secondary !h-8 !px-3 <?= $page <= 1 ? 'pointer-events-none opacity-40' : '' ?>" href="<?= $link(['page' => $page - 1]) ?>">‹</a><span><?= $page ?> / <?= $pages ?></span>
        <a class="btn-secondary !h-8 !px-3 <?= $page >= $pages ? 'pointer-events-none opacity-40' : '' ?>" href="<?= $link(['page' => $page + 1]) ?>">›</a></div><?php endif ?>
</section>
