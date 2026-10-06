<?php
use App\Modules\CRM\Support\Catalog;
use App\Modules\CRM\Support\Ui;
/*
 | $all (sees every department), $deps, $pick, $mine, $scopeName, $cur, $stages, $hold, $pipeline, $forecast, $openCount,
 | $won, $winRate, $months, $counts, $industries, $sources, $actCount, $upcoming, $reminders, $top, $campaigns, $owners, $compare
 */
$crumbs = [];
$money  = fn ($n) => e($cur).' '.Ui::compact((float) $n);
$pl     = fn ($n, $one, $many) => __((int) $n === 1 ? $one : $many, ['n' => $n]);
$link   = fn (array $over) => url('/crm/dashboard', array_filter(array_merge(['department' => $pick ?: null, 'mine' => $mine ? 1 : null], $over), fn ($v) => $v !== null));
$maxStage = max(1.0, ...array_column($stages, 'v'));
$maxMonth = max(1.0, ...array_map(fn ($m) => (float) $m['v'], $months));
$maxInd   = max(1, ...array_map(fn ($i) => (int) $i['n'], $industries ?: [['n' => 1]]));
$maxSrc   = max(1, ...array_map(fn ($i) => (int) $i['n'], $sources ?: [['n' => 1]]));
$typeTone = ['CALL' => 'info', 'MEETING' => 'violet', 'EMAIL' => 'neutral', 'TASK' => 'warn'];
$tiles = [
    [__('Open pipeline'), $money($pipeline), $pl($openCount, ':n open opportunity', ':n open opportunities').($forecast ? ' · '.__('forecast :v', ['v' => $money($forecast)]) : ''), null],
    [__('Won this month'), $money($won['month_v']), $pl($won['month_n'], ':n deal', ':n deals').' · '.__('this year :v', ['v' => $money($won['year_v'])]), null],
    [__('Win rate, 12 months'), $winRate === null ? '—' : $winRate.'%', __(':w won · :l lost', ['w' => (int) $won['won12'], 'l' => (int) $won['lost12']]), null],
    [__('Activities to do'), (string) (int) $actCount['overdue'], __('overdue').' · '.__(':n in the next 7 days', ['n' => (int) $actCount['week']]), (int) $actCount['overdue'] > 0 ? 'text-signal-700' : null],
    [__('Leads'), number_format((int) $counts['leads']), __(':n new this month', ['n' => (int) $counts['leads_new']]).' · '.$pl($counts['contacts'], ':n contact', ':n contacts'), null],
];
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
        <h1 class="page-title"><?= e(__('CRM overview')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(__('Showing')) ?> <span class="font-medium text-graphite-900"><?= e($scopeName) ?></span><?= $mine ? ' · '.e(__('my records only')) : '' ?></p>
    </div>
    <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
        <?php if ($all): ?>
        <form method="GET" class="w-full sm:w-56">
            <?php if ($mine): ?><input type="hidden" name="mine" value="1"><?php endif ?>
            <?= select_field('department', $deps, $pick ?: '', ['placeholder' => __('All departments'), 'submit' => true, 'aria' => __('Department')]) ?>
        </form>
        <?php endif ?>
        <div class="inline-flex rounded-md bg-white p-0.5 text-sm ring-1 ring-graphite-900/15" role="group" aria-label="<?= e(__('Whose records')) ?>">
            <a href="<?= e($link(['mine' => null])) ?>" class="rounded px-3 py-1.5 <?= ! $mine ? 'bg-graphite-900 font-medium text-white' : 'text-steel hover:text-graphite-900' ?>" <?= ! $mine ? 'aria-current="true"' : '' ?>><?= e($all && ! $pick ? __('Everyone') : __('Department')) ?></a>
            <a href="<?= e($link(['mine' => 1])) ?>" class="rounded px-3 py-1.5 <?= $mine ? 'bg-graphite-900 font-medium text-white' : 'text-steel hover:text-graphite-900' ?>" <?= $mine ? 'aria-current="true"' : '' ?>><?= e(__('My records')) ?></a>
        </div>
    </div>
</div>

<?php if ($reminders): ?>
<section class="mt-5 rounded-lg bg-amber-50 p-4 ring-1 ring-amber-600/25" aria-label="<?= e(__('Reminders')) ?>">
    <h2 class="flex items-center gap-2 text-sm font-semibold text-amber-900"><?= icon('bell', 'size-4') ?> <?= e(__('Reminders')) ?></h2>
    <ul class="mt-2 space-y-1 text-sm text-amber-900">
        <?php foreach ($reminders as $r): ?>
        <li><a href="<?= url('/crm/activities/'.$r['id']) ?>" class="font-medium underline-offset-2 hover:underline"><?= e($r['topic']) ?></a> · <?= e(__(Catalog::ACTIVITY_TYPES[$r['activity_type']] ?? $r['activity_type'])) ?> · <?= e(format_date($r['start_at'], 'd M H:i')) ?> (<?= e(time_ago($r['start_at'])) ?>)</li>
        <?php endforeach ?>
    </ul>
</section>
<?php endif ?>

<div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
    <?php foreach ($tiles as [$label, $figure, $note, $tone]): ?>
    <section class="panel p-5">
        <h2 class="text-xs font-medium text-steel"><?= e($label) ?></h2>
        <p class="figure mt-2 text-4xl <?= $tone ?? '' ?>"><?= $figure ?></p>
        <p class="mt-2 text-xs text-steel"><?= $note ?></p>
    </section>
    <?php endforeach ?>
</div>

<?php if ($target):
    $tt = $target['total'] > 0 ? $target['total'] : array_sum($target['target']);
    $pt = $tt > 0 ? round($target['actual_total'] / $tt * 100) : 0;
    $curQ = (int) ceil(date('n') / 3);
?>
<section class="panel mt-6">
    <div class="panel-head">
        <h2 class="panel-title"><?= e(__('Sales target :year', ['year' => $targetYear])) ?></h2>
        <?php if (can('crm_targets', 'view')): ?><a href="<?= url('/crm/settings/targets', array_filter(['year' => $targetYear, 'department' => $pick ?: null])) ?>" class="text-sm text-steel hover:text-graphite-900"><?= e(__('Details')) ?></a><?php endif ?>
    </div>
    <div class="grid gap-px bg-graphite-900/6 sm:grid-cols-5">
        <?php foreach ([1, 2, 3, 4] as $q): $t = $target['target'][$q]; $a = $target['actual'][$q]; $p = $t > 0 ? round($a / $t * 100) : null; ?>
        <div class="bg-white px-5 py-4 <?= $q === $curQ ? 'bg-signal-50/40' : '' ?>">
            <p class="flex items-center justify-between text-xs font-medium text-steel"><span>Q<?= $q ?><?= $q === $curQ ? ' · '.e(__('now')) : '' ?></span><span class="tabular-nums"><?= $p === null ? '—' : $p.'%' ?></span></p>
            <p class="figure mt-2 text-2xl"><?= $money($a) ?></p>
            <p class="mt-1 text-xs text-steel"><?= e(__('of :t', ['t' => $money($t)])) ?></p>
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-graphite-900/8"><div class="h-full rounded-full" style="width: <?= min(100, (int) $p) ?>%; background: <?= $p !== null && $p >= 100 ? '#16a34a' : '#2a78d6' ?>"></div></div>
        </div>
        <?php endforeach ?>
        <div class="bg-white px-5 py-4">
            <p class="flex items-center justify-between text-xs font-medium text-steel"><span><?= e(__('Year')) ?></span><span class="tabular-nums"><?= $pt ?>%</span></p>
            <p class="figure mt-2 text-2xl"><?= $money($target['actual_total']) ?></p>
            <p class="mt-1 text-xs text-steel"><?= e(__('of :t', ['t' => $money($tt)])) ?></p>
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-graphite-900/8"><div class="h-full rounded-full" style="width: <?= min(100, $pt) ?>%; background: <?= $pt >= 100 ? '#16a34a' : '#2a78d6' ?>"></div></div>
        </div>
    </div>
</section>
<?php endif ?>

<div class="mt-6 grid gap-6 lg:grid-cols-5">
    <section class="panel lg:col-span-3">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Open pipeline by stage')) ?></h2><span class="text-xs text-steel"><?= e(__('Value in :cur', ['cur' => $cur])) ?></span></div>
        <ul class="space-y-4 p-5">
            <?php foreach (array_values($stages) as $i => $s): $w = $s['v'] > 0 ? max(2, $s['v'] / $maxStage * 100) : 0; ?>
            <li>
                <div class="mb-1.5 flex items-baseline justify-between gap-3 text-sm">
                    <a href="<?= e(url('/crm/opportunities', array_filter(['stage' => array_keys($stages)[$i], 'department' => $pick ?: null, 'scope' => $mine ? 'mine' : null]))) ?>" class="flex items-center gap-2 font-medium hover:text-signal-700"><span class="size-2.5 rounded-full" style="background: <?= e(App\Modules\CRM\Support\Stages::color(array_keys($stages)[$i])) ?>"></span><?= e($s['label']) ?></a>
                    <span class="tabular-nums text-steel"><?= e($pl($s['n'], ':n deal', ':n deals')) ?> · <span class="font-medium text-graphite-900"><?= $money($s['v']) ?></span></span>
                </div>
                <div class="h-5 rounded bg-graphite-900/5" title="<?= e($s['label'].': '.number_format($s['v'], 0).' '.$cur) ?>"><div class="h-full rounded-r" style="width: <?= round($w, 1) ?>%; background: <?= e(App\Modules\CRM\Support\Stages::color(array_keys($stages)[$i])) ?>"></div></div>
            </li>
            <?php endforeach ?>
        </ul>
        <?php if ((int) $hold['n'] > 0): ?><p class="border-t border-graphite-900/6 px-5 py-3 text-xs text-steel"><?= e(__('On hold: :n, worth :v', ['n' => (int) $hold['n'], 'v' => $money($hold['v'])])) ?></p><?php endif ?>
    </section>

    <section class="panel lg:col-span-2">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Won by month')) ?></h2><span class="text-xs text-steel"><?= e(__('Value in :cur', ['cur' => $cur])) ?></span></div>
        <?php if (! array_sum(array_map(fn ($m) => (float) $m['v'], $months))): ?>
            <p class="px-5 py-12 text-center text-sm text-steel"><?= e(__('No opportunities won in the last 6 months.')) ?></p>
        <?php else: ?>
        <div class="flex h-52 items-end gap-3 px-5 pb-4 pt-6" role="img" aria-label="<?= e(__('Won value by month')) ?>">
            <?php foreach ($months as $m): $h = (float) $m['v'] > 0 ? max(3, (float) $m['v'] / $maxMonth * 100) : 0; ?>
            <div class="flex h-full min-w-0 flex-1 flex-col justify-end text-center" title="<?= e($m['label'].': '.number_format((float) $m['v'], 0).' '.$cur.' ('.$m['n'].')') ?>">
                <span class="mb-1 truncate text-[11px] tabular-nums text-steel"><?= (float) $m['v'] > 0 ? e(Ui::compact((float) $m['v'])) : '' ?></span>
                <div class="rounded-t" style="height: <?= round($h, 1) ?>%; background: #2a78d6"></div>
                <span class="mt-2 text-xs text-steel"><?= e(__($m['label'])) ?></span>
            </div>
            <?php endforeach ?>
        </div>
        <?php endif ?>
    </section>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-2">
    <section class="panel">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Activities to do')) ?></h2><a href="<?= url('/crm/activities', ['status' => 'PLANNED']) ?>" class="text-sm text-steel hover:text-graphite-900"><?= e(__('See all')) ?></a></div>
        <?php if (! $upcoming): ?><p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('Nothing planned.')) ?></p><?php else: ?>
        <ul class="divide-y divide-graphite-900/6">
            <?php foreach ($upcoming as $a): $late = $a['start_at'] && strtotime($a['start_at']) < time(); ?>
            <li><a href="<?= url('/crm/activities/'.$a['id']) ?>" class="flex items-center justify-between gap-3 px-5 py-3 hover:bg-mist/50">
                <span class="min-w-0"><span class="block truncate text-sm font-medium"><?= e($a['topic']) ?></span>
                    <span class="block truncate text-xs text-steel"><?= e(__(Catalog::ACTIVITY_TYPES[$a['activity_type']] ?? $a['activity_type'])) ?><?= $a['lead_name'] ? ' · '.e($a['lead_name']) : '' ?><?= $owners[$a['owner_id'] ?? 0] ?? null ? ' · '.e($owners[$a['owner_id']]) : '' ?></span></span>
                <span class="shrink-0 text-right text-xs tabular-nums <?= $late ? 'font-medium text-signal-700' : 'text-steel' ?>"><?= $a['start_at'] ? e(format_date($a['start_at'], 'd M H:i')) : '—' ?><?= $late ? '<span class="block">'.e(__('Overdue')).'</span>' : '' ?></span>
            </a></li>
            <?php endforeach ?>
        </ul>
        <?php endif ?>
    </section>

    <section class="panel">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Biggest open opportunities')) ?></h2><a href="<?= url('/crm/opportunities') ?>" class="text-sm text-steel hover:text-graphite-900"><?= e(__('See all')) ?></a></div>
        <?php if (! $top): ?><p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('No open opportunities.')) ?></p><?php else: ?>
        <ul class="divide-y divide-graphite-900/6">
            <?php foreach ($top as $o): ?>
            <li><a href="<?= url('/crm/opportunities/'.$o['id']) ?>" class="flex items-center justify-between gap-3 px-5 py-3 hover:bg-mist/50">
                <span class="min-w-0"><span class="block truncate text-sm font-medium"><?= e($o['name']) ?></span>
                    <span class="block truncate text-xs text-steel"><?= e($o['lead_name'] ?? '—') ?><?= $owners[$o['owner_id'] ?? 0] ?? null ? ' · '.e($owners[$o['owner_id']]) : '' ?></span></span>
                <span class="shrink-0 text-right"><span class="block text-sm font-medium tabular-nums"><?= Ui::money($o['amount'], $o['currency']) ?></span><?= Ui::stageBadge($o['stage']) ?></span>
            </a></li>
            <?php endforeach ?>
        </ul>
        <?php endif ?>
    </section>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <?php foreach ([[__('Leads by industry'), $industries, $maxInd], [__('Leads by source'), $sources, $maxSrc]] as [$heading, $list, $max]): ?>
    <section class="panel">
        <div class="panel-head"><h2 class="panel-title"><?= e($heading) ?></h2></div>
        <?php if (! $list): ?><p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('No data yet.')) ?></p><?php else: ?>
        <ul class="space-y-3 p-5">
            <?php foreach ($list as $it): ?>
            <li><div class="mb-1 flex items-center justify-between gap-3 text-sm"><span class="flex min-w-0 items-center gap-2"><span class="size-2.5 shrink-0 rounded-full" style="background: <?= e($it['color']) ?>"></span><span class="truncate"><?= e($it['name']) ?></span></span><span class="tabular-nums font-medium"><?= (int) $it['n'] ?></span></div>
                <div class="h-1.5 rounded-full bg-graphite-900/6"><div class="h-full rounded-full" style="width: <?= round((int) $it['n'] / $max * 100) ?>%; background: <?= e($it['color']) ?>"></div></div></li>
            <?php endforeach ?>
        </ul>
        <?php endif ?>
    </section>
    <?php endforeach ?>

    <section class="panel">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Active campaigns')) ?></h2><a href="<?= url('/crm/campaigns') ?>" class="text-sm text-steel hover:text-graphite-900"><?= e(__('See all')) ?></a></div>
        <?php if (! $campaigns): ?><p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('No active campaigns.')) ?></p><?php else: ?>
        <ul class="divide-y divide-graphite-900/6">
            <?php foreach ($campaigns as $c): $used = (float) $c['budget'] > 0 ? (float) $c['actual_cost'] / (float) $c['budget'] * 100 : null; ?>
            <li class="px-5 py-3"><a href="<?= url('/crm/campaigns/'.$c['id']) ?>" class="text-sm font-medium hover:text-signal-700"><?= e($c['name']) ?></a>
                <p class="text-xs text-steel"><?= e(__('Ends :date', ['date' => format_date($c['end_date'], 'd M Y')])) ?></p>
                <?php if ($used !== null): ?><div class="mt-1.5"><?= partial('crm/meter', ['value' => $used, 'label' => round($used).'%']) ?></div><?php endif ?></li>
            <?php endforeach ?>
        </ul>
        <?php endif ?>
    </section>
</div>

<?php if ($compare): ?>
<section class="panel mt-6 overflow-hidden">
    <div class="panel-head"><h2 class="panel-title"><?= e(__('Departments')) ?></h2><span class="text-xs text-steel"><?= e(__('Value in :cur', ['cur' => $cur])) ?></span></div>
    <div class="overflow-x-auto">
        <table class="table">
            <thead class="bg-mist/60"><tr><th><?= e(__('Department')) ?></th><th class="text-right"><?= e(__('Leads')) ?></th><th class="text-right"><?= e(__('Open opportunities')) ?></th><th class="text-right"><?= e(__('Open pipeline')) ?></th><th class="text-right"><?= e(__('Won this year')) ?></th><th class="text-right"><?= e(__('Overdue activities')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($compare as $d): ?>
                <tr class="hover:bg-mist/40">
                    <td class="font-medium"><?php if ($d['id']): ?><a href="<?= e($link(['department' => $d['id']])) ?>" class="hover:text-signal-700"><?= e($d['name']) ?></a><?php else: ?><?= e($d['name']) ?><?php endif ?></td>
                    <td class="text-right tabular-nums"><?= (int) $d['leads'] ?></td>
                    <td class="text-right tabular-nums"><?= (int) $d['open'] ?></td>
                    <td class="text-right tabular-nums"><?= $money($d['pipeline']) ?></td>
                    <td class="text-right tabular-nums"><?= $money($d['won_year']) ?></td>
                    <td class="text-right tabular-nums <?= $d['overdue'] ? 'font-medium text-signal-700' : '' ?>"><?= (int) $d['overdue'] ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif ?>
<p class="mt-4 text-xs text-steel"><?= e(__('Totals are converted to :cur with the rates in CRM settings → Currencies.', ['cur' => $cur])) ?></p>
