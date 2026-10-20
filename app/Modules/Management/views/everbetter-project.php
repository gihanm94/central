<?php /* Management → Everbetter → one project. $p (with members), $presentations */
use App\Core\Support\Everbetter;
$crumbs = [[__('Everbetter'), '/management/everbetter']];
$kinds = Everbetter::kinds();
$kindTone = ['initiation' => 'bg-sky-50 text-sky-800', 'closure' => 'bg-emerald-50 text-emerald-800', 'extension' => 'bg-violet-50 text-violet-800'];
$presented = count(array_filter($presentations, fn ($x) => $x['result'] !== null));
$days = max(1, (strtotime($p['end_date']) - strtotime($p['start_date'])) / 86400);
$done = min(100, max(0, (int) round((time() - strtotime($p['start_date'])) / 86400 / $days * 100)));
?>
<div class="flex flex-wrap items-start justify-between gap-4">
    <div class="min-w-0">
        <h1 class="page-title break-words"><?= e($p['title']) ?></h1>
        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
            <?= partial('everbetter/status', ['p' => $p]) ?>
            <span class="badge <?= $presented ? 'bg-emerald-50 text-emerald-800' : 'bg-graphite-900/6 text-steel' ?>"><?= $presented ? '✓ '.e(__('Presented')) : e(__('Not presented yet')) ?></span>
            <span class="text-steel"><?= e($p['owner_name']) ?> · <?= e($p['department_name'] ?? '—') ?></span>
        </div>
    </div>
    <a href="<?= url('/management/everbetter') ?>" class="btn-secondary"><?= icon('arrowleft', 'size-4') ?> <?= e(__('All projects')) ?></a>
</div>

<div class="mt-6 grid items-start gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <section class="panel">
            <div class="panel-head"><h2 class="panel-title"><?= e(__('Details')) ?></h2></div>
            <dl class="grid text-sm sm:grid-cols-3">
                <div class="border-b border-graphite-900/6 px-5 py-3"><dt class="text-xs text-steel"><?= e(__('Start date')) ?></dt><dd class="mt-0.5 font-medium"><?= e(format_date($p['start_date'], 'd M Y')) ?></dd></div>
                <div class="border-b border-graphite-900/6 px-5 py-3"><dt class="text-xs text-steel"><?= e(__('End date')) ?></dt><dd class="mt-0.5 font-medium"><?= e(format_date($p['end_date'], 'd M Y')) ?></dd></div>
                <div class="border-b border-graphite-900/6 px-5 py-3"><dt class="text-xs text-steel"><?= e(__('Time used')) ?></dt>
                    <dd class="mt-1.5"><div class="h-1.5 overflow-hidden rounded-full bg-graphite-900/6"><div class="h-full rounded-full bg-signal-600" style="width: <?= $done ?>%"></div></div><span class="text-xs text-steel"><?= $done ?>%</span></dd></div>
                <div class="border-b border-graphite-900/6 px-5 py-3"><dt class="text-xs text-steel"><?= e(__('Created')) ?></dt><dd class="mt-0.5"><?= e(format_date($p['created_at'], 'd M Y')) ?></dd></div>
                <div class="border-b border-graphite-900/6 px-5 py-3"><dt class="text-xs text-steel"><?= e(__('Approved')) ?></dt><dd class="mt-0.5"><?= $p['approved_at'] ? e(format_date($p['approved_at'], 'd M Y')) : '—' ?></dd></div>
                <div class="border-b border-graphite-900/6 px-5 py-3"><dt class="text-xs text-steel"><?= e(__('Closed')) ?></dt><dd class="mt-0.5"><?= $p['closed_at'] ? e(format_date($p['closed_at'], 'd M Y')) : '—' ?></dd></div>
            </dl>
            <div class="whitespace-pre-line px-5 py-4 text-sm"><?= $p['description'] ? e($p['description']) : '<span class="text-steel">'.e(__('No description yet.')).'</span>' ?></div>
        </section>

        <section class="panel overflow-hidden">
            <div class="panel-head"><h2 class="panel-title"><?= e(__('Presentations')) ?> <span class="ml-1 text-sm font-normal text-steel"><?= count($presentations) ?></span></h2></div>
            <?php if (! $presentations): ?><p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('The team has not asked to present yet.')) ?></p><?php endif ?>
            <ol class="divide-y divide-graphite-900/6">
                <?php foreach ($presentations as $x): [$l, $tone] = Everbetter::stateOf($x); $waiting = $x['status'] !== 'withdrawn' && $x['result'] === null; ?>
                <li class="px-5 py-4 <?= $waiting ? 'bg-amber-50/40' : '' ?>">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="badge <?= $kindTone[$x['kind']] ?>"><?= e($kinds[$x['kind']]) ?></span>
                        <span class="text-sm"><?= e(__('Q:q :y presentation, :date', ['q' => $x['quarter'], 'y' => $x['year'], 'date' => format_date($x['present_on'], 'd M Y')])) ?></span>
                        <span class="badge <?= $tone ?>"><?= e($l) ?></span>
                        <?php if ($waiting): ?>
                        <span class="ml-auto flex gap-1.5">
                            <?php if ($x['status'] === 'submitted'): ?><form method="POST" action="<?= url('/management/everbetter/requests/'.(int) $x['id'].'/acknowledge') ?>"><?= csrf_field() ?><button class="btn-secondary h-8 px-3 text-xs"><?= icon('check', 'size-4') ?> <?= e(__('Accept')) ?></button></form><?php endif ?>
                            <button type="button" class="btn-primary h-8 px-3 text-xs" data-open-dialog="eb-decide" data-eb-decide="<?= (int) $x['id'] ?>" data-eb-title="<?= e($p['title']) ?>" data-eb-kind="<?= e($kinds[$x['kind']]) ?>"><?= e(__('Record result')) ?></button>
                        </span>
                        <?php endif ?>
                    </div>
                    <p class="mt-1.5 text-xs text-steel"><?= e(__('Asked by :name on :date', ['name' => $x['requested_name'], 'date' => format_date($x['requested_at'], 'd M Y H:i')])) ?>
                        <?= $x['kind'] === 'extension' && $x['new_end_date'] ? ' · '.e(__('new end date :d', ['d' => format_date($x['new_end_date'])])) : '' ?>
                        <?= $x['acknowledged_at'] ? ' · '.e(__('accepted by :name :date', ['name' => $x['acknowledged_name'], 'date' => format_date($x['acknowledged_at'], 'd M')])) : '' ?>
                        <?= $x['decided_at'] ? ' · '.e(__('decided by :name :date', ['name' => $x['decided_name'], 'date' => format_date($x['decided_at'], 'd M')])) : '' ?></p>
                    <?php if ($x['note']): ?><p class="mt-1.5 whitespace-pre-line text-sm"><?= e($x['note']) ?></p><?php endif ?>
                    <?php if ($x['result_note']): ?><p class="mt-1.5 rounded-md bg-mist px-3 py-2 text-sm"><span class="text-xs font-semibold text-steel"><?= e(__('Management')) ?>:</span> <?= e($x['result_note']) ?></p><?php endif ?>
                </li>
                <?php endforeach ?>
            </ol>
        </section>
    </div>

    <section class="panel overflow-hidden">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Team')) ?> <span class="ml-1 text-sm font-normal text-steel"><?= count($p['members']) ?></span></h2></div>
        <ul class="divide-y divide-graphite-900/6">
            <?php foreach ($p['members'] as $m): ?>
            <li class="flex items-center gap-3 px-5 py-2.5"><?= partial('partials/avatar', ['name' => $m['name'], 'avatar' => $m['avatar'], 'size' => 'size-9']) ?>
                <span class="min-w-0 flex-1 truncate text-sm font-medium"><?= e($m['name']) ?></span>
                <?php if ((int) $m['id'] === (int) $p['owner_id']): ?><span class="badge bg-graphite-900/6 text-graphite-700"><?= e(__('owner')) ?></span><?php endif ?></li>
            <?php endforeach ?>
            <?php if (! $p['members']): ?><li class="px-5 py-6 text-center text-sm text-steel"><?= e(__('Nobody yet.')) ?></li><?php endif ?>
        </ul>
    </section>
</div>
<?= partial('management::everbetter-decide') ?>
