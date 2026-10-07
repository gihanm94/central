<?php
use App\Core\Support\Request;
/* $rows, $total, $status, $q, $per, $page, $counts */
$crumbs = [[__('Members'), '/members']];
$tabs = ['pending' => __('Waiting'), 'approved' => __('Approved'), 'rejected' => __('Rejected')];
$pages = max(1, (int) ceil($total / $per));
$link = fn (array $o) => url('/members/requests', array_filter(array_merge(['status' => $status, 'q' => $q ?: null, 'per_page' => $per !== 10 ? $per : null], $o), fn ($v) => $v !== null && $v !== ''));
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div><h1 class="page-title"><?= e(__('Account requests')) ?></h1><p class="mt-1 text-sm text-steel"><?= e(__('People who asked for an account on the sign-in page. Approving creates the member with the password they chose; you never see it.')) ?></p></div>
    <a href="<?= url('/members') ?>" class="btn-secondary"><?= icon('users', 'size-4') ?> <?= e(__('Members')) ?></a>
</div>
<section class="panel mt-4">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-graphite-900/8 p-3">
        <nav class="inline-flex gap-1 rounded-lg bg-mist p-1"><?php foreach ($tabs as $k => $l): ?><a href="<?= e(url('/members/requests', ['status' => $k])) ?>" class="rounded-md px-3 py-1.5 text-sm <?= $k === $status ? 'bg-white font-medium shadow-sm' : 'text-steel hover:text-graphite-900' ?>"><?= e($l) ?> <span class="ml-1 text-xs text-steel"><?= (int) ($counts[$k] ?? 0) ?></span></a><?php endforeach ?></nav>
        <form method="GET" class="flex items-center gap-2"><input type="hidden" name="status" value="<?= e($status) ?>"><input name="q" value="<?= e($q) ?>" type="search" placeholder="<?= e(__('Search…')) ?>" class="input w-56"><button class="btn-secondary"><?= e(__('Search')) ?></button></form>
    </div>
    <div class="overflow-x-auto"><table class="w-full text-left text-sm">
        <thead class="text-xs text-steel"><tr><th class="px-4 py-2.5 font-medium"><?= e(__('Person')) ?></th><th class="px-3 py-2.5 font-medium"><?= e(__('Employee ID')) ?></th><th class="px-3 py-2.5 font-medium"><?= e(__('Department (typed)')) ?></th><th class="px-3 py-2.5 font-medium"><?= e(__('Phone')) ?></th><th class="px-3 py-2.5 font-medium"><?= e(__('Sent')) ?></th><th class="px-4 py-2.5"></th></tr></thead>
        <tbody class="divide-y divide-graphite-900/6">
        <?php foreach ($rows as $r): ?>
            <tr class="hover:bg-mist/40">
                <td class="px-4 py-3"><span class="block font-medium"><?= e($r['name']) ?></span><span class="block text-xs text-steel"><?= e($r['email']) ?></span></td>
                <td class="px-3 py-3 tabular-nums"><?= e($r['employee_code']) ?></td>
                <td class="px-3 py-3"><?= e($r['department_text'] ?? '—') ?></td>
                <td class="px-3 py-3"><?= e($r['phone'] ?? '—') ?></td>
                <td class="px-3 py-3 text-xs text-steel"><?= e(format_date($r['created_at'], 'd M Y H:i')) ?><?php if ($r['reviewed_at']): ?><span class="block"><?= e(__('by :n', ['n' => $r['reviewer'] ?? '—'])) ?> · <?= e(format_date($r['reviewed_at'], 'd M')) ?></span><?php endif ?><?php if ($r['reject_reason']): ?><span class="block"><?= e($r['reject_reason']) ?></span><?php endif ?></td>
                <td class="px-4 py-3 text-right">
                    <?php if ($r['status'] === 'pending'): ?><a class="btn-primary !h-8 px-3" href="<?= url('/members/requests/'.$r['id']) ?>"><?= e(__('Review')) ?></a>
                    <?php elseif ($r['user_id']): ?><a class="btn-secondary !h-8 px-3" href="<?= url('/members/'.$r['user_id']) ?>"><?= e(__('Open member')) ?></a>
                    <?php else: ?><form method="POST" action="<?= url('/members/requests/'.$r['id'].'/delete') ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="status" value="<?= e($status) ?>"><button class="btn-ghost !h-8 px-3 text-signal-700"><?= e(__('Remove')) ?></button></form><?php endif ?>
                </td>
            </tr>
        <?php endforeach ?>
        <?php if (! $rows): ?><tr><td colspan="6" class="px-4 py-10 text-center text-steel"><?= e(__('Nothing here.')) ?></td></tr><?php endif ?>
        </tbody></table></div>
    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-graphite-900/8 px-4 py-3 text-sm text-steel">
        <span><?= e(__(':count total', ['count' => $total])) ?></span>
        <span class="flex items-center gap-2">
            <a class="btn-secondary !h-8 !px-3 <?= $page <= 1 ? 'pointer-events-none opacity-40' : '' ?>" href="<?= e($link(['page' => $page - 1])) ?>">‹</a>
            <?= e(__('Page :p of :t', ['p' => $page, 't' => $pages])) ?>
            <a class="btn-secondary !h-8 !px-3 <?= $page >= $pages ? 'pointer-events-none opacity-40' : '' ?>" href="<?= e($link(['page' => $page + 1])) ?>">›</a>
        </span>
    </div>
</section>
