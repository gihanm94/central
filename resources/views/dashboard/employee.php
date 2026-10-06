<?php
$h = (int) date('G');
$greeting = __($h < 12 ? 'Good morning' : ($h < 17 ? 'Good afternoon' : 'Good evening'));
$scopeNoun = ['all' => 'company', 'department' => 'department', 'team' => 'team', 'own' => 'personal'][$scope];
$bar = ['urgent' => 'bg-signal-600', 'high' => 'bg-signal-500/70', 'medium' => 'bg-graphite-600', 'low' => 'bg-graphite-300'];
$statuses = App\Core\Http\Controllers\TaskController::statuses();
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="page-title"><?= $greeting ?>, <?= e($u->firstName()) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e($u->job_title ?? __($u->role_name)) ?><?= $u->department_name ? ', '.e($u->department_name) : '' ?><?= $u->team_name ? ' / '.e($u->team_name) : '' ?></p>
    </div>
    <p class="flex items-center gap-2 text-sm text-steel"><?= e(__('Data you can see')) ?>: <?= partial('partials/scope-badge', ['scope' => $scope]) ?></p>
</div>

<?php if ($figures): $n = count($figures); $lg = [1 => 'lg:grid-cols-1', 2 => 'lg:grid-cols-2', 3 => 'lg:grid-cols-3', 4 => 'lg:grid-cols-4'][$n] ?? 'lg:grid-cols-4'; ?>
<section class="mt-6 grid grid-cols-2 gap-px overflow-hidden rounded-lg bg-graphite-900/8 ring-1 ring-graphite-900/8 <?= $lg ?> [&>div]:bg-white [&>div]:p-5 [&>div:last-child:nth-child(odd)]:col-span-2 lg:[&>div:last-child:nth-child(odd)]:col-span-1">
    <?php if (isset($figures['members'])): ?><div><p class="text-sm text-steel"><?= e(__('People you can see')) ?></p><p class="figure mt-2 text-4xl"><?= $figures['members'] ?></p></div><?php endif ?>
    <?php if (isset($figures['open_tasks'])): ?>
        <div><p class="text-sm text-steel"><?= e(__('Open tasks')) ?></p><p class="figure mt-2 text-4xl"><?= $figures['open_tasks'] ?></p></div>
        <div><p class="text-sm text-steel"><?= e(__('Overdue')) ?></p><p class="figure mt-2 text-4xl <?= $figures['overdue'] ? 'text-signal-600' : '' ?>"><?= $figures['overdue'] ?></p></div>
        <div><p class="text-sm text-steel"><?= e(__('Done this month')) ?></p><p class="figure mt-2 text-4xl"><?= $figures['done_month'] ?></p></div>
    <?php endif ?>
</section>
<?php endif ?>

<div class="mt-6 grid items-start gap-6 xl:grid-cols-5">
    <section class="panel xl:col-span-3">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('My open tasks')) ?></h2><?php if (can('tasks')): ?><a href="<?= url('/tasks') ?>" class="text-sm text-steel hover:text-graphite-900"><?= e(__('See all tasks')) ?></a><?php endif ?></div>
        <ul class="divide-y divide-graphite-900/6">
            <?php foreach ($myTasks as $t): $overdue = $t['due_date'] && strtotime($t['due_date']) < strtotime('today'); ?>
                <li class="flex items-center gap-4 px-5 py-3">
                    <span class="h-9 w-1 shrink-0 rounded-full <?= $bar[$t['priority']] ?? 'bg-graphite-300' ?>" title="<?= e(__(ucfirst($t['priority']))) ?>"></span>
                    <div class="min-w-0 flex-1">
                        <a href="<?= can('tasks') ? url('/tasks/'.$t['id']) : '#' ?>" class="block truncate text-sm font-medium hover:text-signal-700"><?= e($t['title']) ?></a>
                        <p class="text-xs text-steel"><?= e($statuses[$t['status']]) ?> · <?= e(__(ucfirst($t['priority']))) ?></p>
                    </div>
                    <?php if ($t['due_date']): ?><span class="shrink-0 text-xs tabular-nums <?= $overdue ? 'font-semibold text-signal-700' : 'text-steel' ?>"><?= e(__($overdue ? 'Overdue, :date' : 'Due :date', ['date' => format_date($t['due_date'], 'd M')])) ?></span><?php endif ?>
                </li>
            <?php endforeach ?>
            <?php if (! $myTasks): ?><li class="px-5 py-10 text-center text-sm text-steel"><?= e(__('No open tasks. New assignments will show up here.')) ?></li><?php endif ?>
        </ul>
    </section>

    <section class="panel xl:col-span-2">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('My KPIs')) ?></h2><?php if (can('kpis')): ?><a href="<?= url('/kpis') ?>" class="text-sm text-steel hover:text-graphite-900"><?= e(__('Details')) ?></a><?php endif ?></div>
        <ul class="space-y-5 p-5">
            <?php foreach ($myKpis as $k): $pct = (float) $k['target'] > 0 ? (int) round($k['actual'] / $k['target'] * 100) : 0; ?>
                <li>
                    <div class="flex items-baseline justify-between gap-3"><p class="text-sm font-medium"><?= e($k['title']) ?></p><p class="figure text-xl"><?= $pct ?>%</p></div>
                    <div class="mt-2"><?= partial('partials/progress', ['value' => $pct]) ?></div>
                    <p class="mt-1.5 text-xs text-steel"><?= e(__(':actual of :target :unit · ends :date', ['actual' => number_clean($k['actual']), 'target' => number_clean($k['target']), 'unit' => $k['unit'], 'date' => format_date($k['period_end'], 'd M')])) ?></p>
                </li>
            <?php endforeach ?>
            <?php if (! $myKpis): ?><li class="py-6 text-center text-sm text-steel"><?= e(__('No active KPIs. Your manager sets these.')) ?></li><?php endif ?>
        </ul>
    </section>
</div>

<div class="mt-6 grid items-start gap-6 xl:grid-cols-5">
    <?php if ($teammates): ?>
    <section class="panel xl:col-span-2">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('People you can see')) ?></h2></div>
        <ul class="divide-y divide-graphite-900/6">
            <?php foreach ($teammates as $m): ?>
                <li class="flex items-center gap-3 px-5 py-2.5">
                    <?= partial('partials/avatar', ['name' => $m['name'], 'avatar' => $m['avatar'], 'size' => 'size-8']) ?>
                    <div class="min-w-0"><a href="<?= url('/members/'.$m['id']) ?>" class="block truncate text-sm font-medium hover:text-signal-700"><?= e($m['name']) ?></a><p class="truncate text-xs text-steel"><?= e($m['job_title'] ?? '—') ?></p></div>
                </li>
            <?php endforeach ?>
        </ul>
    </section>
    <?php endif ?>
    <section class="panel <?= $teammates ? 'xl:col-span-3' : 'xl:col-span-5' ?>">
        <div class="panel-head"><h2 class="panel-title"><?= e(__($scope === 'own' ? 'Your recent activity' : 'Recent activity')) ?></h2></div>
        <ul class="divide-y divide-graphite-900/6">
            <?php foreach ($activity as $log): ?><?= partial('partials/activity-row', ['log' => $log, 'showUser' => $scope !== 'own']) ?><?php endforeach ?>
            <?php if (! $activity): ?><li class="px-5 py-10 text-center text-sm text-steel"><?= e(__('No activity yet.')) ?></li><?php endif ?>
        </ul>
    </section>
</div>
