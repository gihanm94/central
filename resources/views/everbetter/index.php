<?php /* $projects (mine: owner or member), $session (this quarter's day or null), $next (next upcoming day or null) */
use App\Core\Support\Everbetter;
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0"><h1 class="page-title"><?= e(__('Everbetter')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(__('Your improvement projects. Present them to Management on the quarter\'s presentation day: initiation, closure or more time.')) ?></p></div>
    <?php if (can('everbetter', 'create')): ?><a href="<?= url('/everbetter/new') ?>" class="btn-primary w-full sm:w-auto"><?= icon('plus', 'size-4') ?> <?= e(__('New project')) ?></a><?php endif ?>
</div>

<?php $day = $next ?? $session; ?>
<div class="mt-5 flex items-center gap-3 rounded-xl px-4 py-3 text-sm ring-1 <?= $day ? 'bg-sky-50 text-sky-900 ring-sky-600/20' : 'bg-mist text-steel ring-graphite-900/8' ?>">
    <?= icon('calendar', 'size-5 shrink-0') ?>
    <p><?= $day ? e(__('Next presentation day: :date (Q:q :y)', ['date' => format_date($day['present_on'], 'l d M Y'), 'q' => $day['quarter'], 'y' => $day['year']])).($day['place'] ? ' · '.e($day['place']) : '') : e(__('Management has not set the next presentation day yet.')) ?></p>
</div>

<div class="mt-4 grid gap-3 lg:grid-cols-2">
    <?php foreach ($projects as $p): $last = $p['last_request']; ?>
    <a href="<?= url('/everbetter/'.(int) $p['id']) ?>" class="panel block p-5 hover:ring-graphite-900/20">
        <div class="flex items-start justify-between gap-3">
            <h2 class="min-w-0 font-semibold leading-snug"><?= e($p['title']) ?></h2>
            <?= partial('everbetter/status', ['p' => $p]) ?>
        </div>
        <p class="mt-1 text-sm text-steel"><?= e(format_date($p['start_date']).' – '.format_date($p['end_date'])) ?> · <?= e($p['owner_name']) ?></p>
        <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
            <span class="rounded-full px-2 py-0.5 font-medium <?= (int) $p['presented'] ? 'bg-emerald-50 text-emerald-800' : 'bg-graphite-900/6 text-steel' ?>"><?= (int) $p['presented'] ? '✓ '.e(__('Presented')) : e(__('Not presented yet')) ?></span>
            <?php if ($last): [$l, $tone] = Everbetter::stateOf($last); ?>
                <span class="rounded-full px-2 py-0.5 font-medium <?= $tone ?>"><?= e(Everbetter::kinds()[$last['kind']]) ?> · <?= e($l) ?><?= $last['result'] === null ? ' · '.e(format_date($last['present_on'], 'd M')) : '' ?></span>
            <?php endif ?>
        </div>
        <div class="mt-3 flex -space-x-2">
            <?php foreach (array_slice($p['members'], 0, 8) as $m): ?><?= partial('partials/avatar', ['name' => $m['name'], 'avatar' => $m['avatar'], 'size' => 'size-7', 'extra' => 'ring-2 ring-white']) ?><?php endforeach ?>
            <?php if (count($p['members']) > 8): ?><span class="flex size-7 items-center justify-center rounded-full bg-mist text-[11px] ring-2 ring-white">+<?= count($p['members']) - 8 ?></span><?php endif ?>
        </div>
    </a>
    <?php endforeach ?>
    <?php if (! $projects): ?>
        <div class="panel px-5 py-14 text-center lg:col-span-2">
            <span class="mx-auto flex size-12 items-center justify-center rounded-full bg-mist text-steel"><?= icon('bolt', 'size-6') ?></span>
            <p class="mt-3 font-medium"><?= e(__('No projects yet')) ?></p>
            <p class="mt-1 text-sm text-steel"><?= e(__('Start one with "New project", add your team, then ask to present its initiation.')) ?></p>
        </div>
    <?php endif ?>
</div>
