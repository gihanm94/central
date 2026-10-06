<?php $peak = max(1, max(array_map(fn ($d) => (int) $d['count'], $trend))); $largest = max(1, ...array_map(fn ($d) => (int) $d['members_count'], $departments ?: [['members_count' => 1]])); ?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="page-title"><?= e(__('Control room')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(__('Everything happening across :company today.', ['company' => $branding['name']])) ?></p>
    </div>
    <div class="flex w-full flex-wrap gap-2 sm:w-auto">
        <a href="<?= url('/activity') ?>" class="btn-secondary flex-1 sm:flex-none"><?= e(__('Full activity log')) ?></a>
        <a href="<?= url('/members/create') ?>" class="btn-primary flex-1 sm:flex-none"><?= icon('plus', 'size-4') ?> <?= e(__('Add member')) ?></a>
    </div>
</div>

<section class="mt-6 grid grid-cols-2 gap-px overflow-hidden rounded-lg bg-graphite-900/8 ring-1 ring-graphite-900/8 lg:grid-cols-4 [&>a]:bg-white [&>a]:p-5 [&>a:hover]:bg-mist/50" aria-label="<?= e(__('Key figures')) ?>">
    <a href="<?= url('/members') ?>">
        <p class="text-sm text-steel"><?= e(__('Members')) ?></p>
        <p class="figure mt-2 text-5xl"><?= (int) $stats['active'] ?></p>
        <p class="mt-1.5 text-xs text-steel"><?= e(__(':blocked blocked · :depts departments · :teams teams', ['blocked' => $stats['members'] - $stats['active'], 'depts' => (int) $stats['departments'], 'teams' => (int) $stats['teams']])) ?></p>
    </a>
    <a href="<?= url('/tasks') ?>">
        <p class="text-sm text-steel"><?= e(__('Open tasks')) ?></p>
        <p class="figure mt-2 text-5xl"><?= (int) $stats['open_tasks'] ?></p>
        <p class="mt-1.5 text-xs <?= $stats['overdue'] ? 'font-medium text-signal-700' : 'text-steel' ?>"><?= e(__(':n overdue', ['n' => (int) $stats['overdue']])) ?></p>
    </a>
    <a href="<?= url('/activity', ['action' => 'login']) ?>">
        <p class="text-sm text-steel"><?= e(__('Sign-ins today')) ?></p>
        <p class="figure mt-2 text-5xl"><?= (int) $stats['logins_today'] ?></p>
        <p class="mt-1.5 text-xs <?= $stats['failed_today'] ? 'font-medium text-signal-700' : 'text-steel' ?>"><?= e(__(':n failed attempts', ['n' => (int) $stats['failed_today']])) ?></p>
    </a>
    <a href="<?= url('/roles') ?>">
        <p class="text-sm text-steel"><?= e(__('Roles')) ?></p>
        <p class="figure mt-2 text-5xl"><?= (int) $stats['roles'] ?></p>
        <p class="mt-1.5 text-xs text-steel"><?= e(__('Manage access rules')) ?></p>
    </a>
</section>

<div class="mt-6 grid items-start gap-6 xl:grid-cols-3">
    <section class="panel xl:col-span-2">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Sign-ins, last 14 days')) ?></h2><span class="text-xs text-steel"><?= e(__(':count total', ['count' => array_sum(array_column($trend, 'count'))])) ?></span></div>
        <div class="flex h-48 items-end gap-1.5 px-5 pb-3 pt-6">
            <?php foreach ($trend as $i => $d): $last = $i === count($trend) - 1; ?>
                <div class="group flex h-full flex-1 flex-col items-center justify-end gap-2">
                    <span class="text-[11px] tabular-nums text-steel opacity-0 group-hover:opacity-100"><?= (int) $d['count'] ?></span>
                    <div class="w-full rounded-t-sm <?= $last ? 'bg-signal-600' : 'bg-graphite-800 group-hover:bg-graphite-600' ?>" style="height: <?= max(2, (int) $d['count'] / $peak * 100) ?>%" title="<?= e(format_date($d['day'], 'd M')) ?>: <?= (int) $d['count'] ?>"></div>
                </div>
            <?php endforeach ?>
        </div>
        <div class="flex justify-between border-t border-graphite-900/8 px-5 py-2 text-[11px] text-steel"><span><?= e(format_date($trend[0]['day'], 'd M')) ?></span><span><?= e(__('Today')) ?></span></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('People by role')) ?></h2></div>
        <ul class="divide-y divide-graphite-900/6">
            <?php foreach ($roles as $r): ?>
                <li class="flex items-center justify-between gap-3 px-5 py-3">
                    <div><p class="text-sm font-medium"><?= e(__($r['name'])) ?></p><div class="mt-1"><?= partial('partials/scope-badge', ['scope' => $r['data_scope']]) ?></div></div>
                    <span class="figure text-2xl"><?= (int) $r['users_count'] ?></span>
                </li>
            <?php endforeach ?>
        </ul>
    </section>
</div>

<div class="mt-6 grid items-start gap-6 xl:grid-cols-3">
    <section class="panel">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Head-count by department')) ?></h2></div>
        <ul class="space-y-4 p-5">
            <?php foreach ($departments as $d): ?>
                <li>
                    <div class="flex justify-between text-sm"><span><?= e($d['name']) ?></span><span class="tabular-nums text-steel"><?= (int) $d['members_count'] ?></span></div>
                    <div class="mt-1.5 h-2 rounded-full bg-graphite-900/6"><div class="h-2 rounded-full bg-graphite-800" style="width: <?= (int) $d['members_count'] / $largest * 100 ?>%"></div></div>
                </li>
            <?php endforeach ?>
            <?php if (! $departments): ?><li class="text-sm text-steel"><?= e(__('No departments yet.')) ?></li><?php endif ?>
        </ul>
    </section>

    <section class="panel xl:col-span-2">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Latest activity')) ?></h2><span class="hidden text-xs text-steel sm:inline"><?= e(__('Sign-ins, changes, imports, exports and downloads')) ?></span></div>
        <ul class="divide-y divide-graphite-900/6">
            <?php foreach ($recent as $log): ?><?= partial('partials/activity-row', ['log' => $log]) ?><?php endforeach ?>
            <?php if (! $recent): ?><li class="px-5 py-10 text-center text-sm text-steel"><?= e(__('Nothing logged yet.')) ?></li><?php endif ?>
        </ul>
    </section>
</div>

<section class="panel mt-6 overflow-hidden">
    <div class="panel-head"><h2 class="panel-title"><?= e(__('Recently added members')) ?></h2><a href="<?= url('/members') ?>" class="text-sm text-steel hover:text-graphite-900"><?= e(__('All members')) ?></a></div>
    <div class="overflow-x-auto">
        <table class="table">
            <thead><tr><th><?= e(__('Name')) ?></th><th><?= e(__('Department')) ?></th><th><?= e(__('Role')) ?></th><th><?= e(__('Status')) ?></th><th><?= e(__('Last sign-in')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($newMembers as $m): ?>
                <tr>
                    <td><a href="<?= url('/members/'.$m['id']) ?>" class="flex items-center gap-3"><?= partial('partials/avatar', ['name' => $m['name'], 'avatar' => $m['avatar'], 'size' => 'size-8']) ?><span><span class="block font-medium"><?= e($m['name']) ?></span><span class="block text-xs text-steel"><?= e($m['email']) ?></span></span></a></td>
                    <td><?= e($m['department_name'] ?? '—') ?></td>
                    <td><?= e(__($m['role_name'])) ?></td>
                    <td><?= filter_var($m['is_active'], FILTER_VALIDATE_BOOL) ? '<span class="badge bg-emerald-50 text-emerald-800">'.e(__('Active')).'</span>' : '<span class="badge bg-graphite-900/6 text-steel">'.e(__('Blocked')).'</span>' ?></td>
                    <td class="text-steel"><?= e(time_ago($m['last_login_at'])) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</section>
