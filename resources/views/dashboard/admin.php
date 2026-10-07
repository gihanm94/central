<?php
use App\Core\Support\Request;
/* $from $to (DateTimeImmutable) $range $days $unit $dept $depts $now $prev $members $series $buckets $hours $people $rank $heads $topBy $recent */
$crumbs = [];
$presets = ['today' => __('Today'), '7d' => __('7 days'), '30d' => __('30 days'), 'month' => __('This month'), 'last_month' => __('Last month'), '90d' => __('90 days'), 'year' => __('This year')];
$keep = array_filter(['department' => $dept ?: null]);
$delta = function (int $a, int $b) {
    if ($b === 0) { return $a > 0 ? ['+new', 'up'] : ['—', 'flat']; }
    $p = round(($a - $b) / $b * 100);
    return [($p > 0 ? '+' : '').$p.'%', $p > 0 ? 'up' : ($p < 0 ? 'down' : 'flat')];
};
$tone = ['up' => 'bg-emerald-50 text-emerald-700', 'down' => 'bg-amber-50 text-amber-700', 'flat' => 'bg-graphite-900/6 text-steel'];
$name = fn ($d) => $depts[$d]['name'] ?? '—';
$dot  = fn ($d) => '<span class="inline-block size-2.5 shrink-0 rounded-full" style="background:'.e($depts[$d]['color'] ?? '#9ca3af').'"></span>';
$tiles = [
    [__('Sign-ins'), (int) $now['logins'], (int) $prev['logins'], __(':n different people', ['n' => (int) $now['signers']]), url('/activity', ['action' => 'login'])],
    [__('Active people'), (int) $now['signers'], (int) $prev['signers'], __('of :n members', ['n' => $members]).' · '.($members ? round($now['signers'] / $members * 100) : 0).'%', null],
    [__('Changes made'), (int) $now['changes'], (int) $prev['changes'], __('created, edited, deleted, imported …'), url('/activity')],
    [__('Failed sign-ins'), (int) $now['failed'], (int) $prev['failed'], __('wrong password or locked'), url('/activity', ['action' => 'login_failed'])],
];
$best = $rank[0] ?? null;
$maxRank = max(1, ...array_map(fn ($r) => (int) $r['total'], $rank ?: [['total' => 1]]));
$maxPeople = max(1, ...array_map(fn ($r) => (int) $r['total'], $people ?: [['total' => 1]]));
?>
<div class="flex flex-wrap items-end justify-between gap-3">
    <div class="min-w-0">
        <h1 class="page-title"><?= e(__('Control room')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e($from->format('Y-m-d') === $to->format('Y-m-d') ? format_date($from->format('Y-m-d')) : format_date($from->format('Y-m-d')).' – '.format_date($to->format('Y-m-d'))) ?> · <?= e(__(':n days', ['n' => $days])) ?> · <?= e($dept ? $name($dept) : __('All departments')) ?></p>
    </div>
</div>

<!-- filters -->
<form method="GET" id="ctl-filter" class="panel mt-4 flex flex-wrap items-center gap-x-4 gap-y-3 p-3">
    <input type="hidden" name="range" value="<?= e($range) ?>" data-range>
    <nav class="flex flex-wrap gap-1 rounded-lg bg-mist p-1" aria-label="<?= e(__('Period')) ?>">
        <?php foreach ($presets as $k => $l): ?><a href="<?= e(url('/dashboard/admin', $keep + ['range' => $k])) ?>" class="rounded-md px-3 py-1.5 text-sm <?= $range === $k ? 'bg-white font-medium shadow-sm' : 'text-steel hover:text-graphite-900' ?>"><?= e($l) ?></a><?php endforeach ?>
    </nav>
    <div class="flex flex-wrap items-center gap-2 text-sm">
        <label class="sr-only" for="ctl-from"><?= e(__('From')) ?></label><input id="ctl-from" type="date" name="from" value="<?= e($from->format('Y-m-d')) ?>" class="input !w-40" data-custom>
        <span class="text-steel">→</span>
        <label class="sr-only" for="ctl-to"><?= e(__('To')) ?></label><input id="ctl-to" type="date" name="to" value="<?= e($to->format('Y-m-d')) ?>" class="input !w-40" data-custom>
    </div>
    <div class="ml-auto flex items-center gap-2">
        <span class="hidden text-sm text-steel sm:inline"><?= e(__('Department')) ?></span>
        <div class="min-w-[12rem]"><?= select_field('department', [0 => __('All departments')] + array_map(fn ($d) => $d['name'], array_filter($depts, fn ($d) => $d['id'] !== 0)), (string) $dept, ['aria' => __('Department'), 'submit' => true, 'class' => 'input']) ?></div>
    </div>
</form>

<!-- key figures -->
<section class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="<?= e(__('Key figures')) ?>">
    <?php foreach ($tiles as [$label, $v, $p, $sub, $href]): [$txt, $dir] = $delta($v, $p); $inverse = $label === __('Failed sign-ins'); if ($inverse && $dir !== 'flat') { $dir = $dir === 'up' ? 'down' : 'up'; } ?>
    <<?= $href ? 'a href="'.e($href).'"' : 'div' ?> class="panel block p-4 transition-shadow <?= $href ? 'hover:shadow-md' : '' ?>">
        <div class="flex items-start justify-between gap-2"><p class="text-sm text-steel"><?= e($label) ?></p><span class="rounded-full px-2 py-0.5 text-[11px] font-medium <?= $tone[$dir] ?>" title="<?= e(__('compared with the :n days before', ['n' => $days])) ?>"><?= e($txt) ?></span></div>
        <p class="figure mt-2 text-4xl sm:text-5xl"><?= number_format($v) ?></p>
        <p class="mt-1.5 truncate text-xs text-steel"><?= e($sub) ?></p>
    </<?= $href ? 'a' : 'div' ?>>
    <?php endforeach ?>
</section>

<!-- sign-ins by department over time + department ranking -->
<div class="mt-4 grid items-start gap-4 xl:grid-cols-3">
    <section class="panel xl:col-span-2">
        <div class="panel-head"><div><h2 class="panel-title"><?= e(__('Sign-ins by department')) ?></h2><p class="text-xs text-steel"><?= e(['day' => __('per day'), 'week' => __('per week'), 'month' => __('per month')][$unit]) ?></p></div><span class="text-sm tabular-nums text-steel"><?= e(__(':count total', ['count' => number_format((int) $now['logins'])])) ?></span></div>
        <?php if (! $series): ?><p class="px-5 py-16 text-center text-sm text-steel"><?= e(__('No sign-ins in this period.')) ?></p><?php else: ?>
        <div class="px-3 pt-4 sm:px-5"><div class="relative h-64 sm:h-72"><canvas id="ctl-stack" role="img" aria-label="<?= e(__('Sign-ins by department')) ?>"></canvas></div></div>
        <ul class="flex flex-wrap gap-2 px-5 py-4" id="ctl-legend">
            <?php foreach ($series as $i => $s): ?><li><button type="button" data-ds="<?= $i ?>" class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs ring-1 ring-graphite-900/12 hover:bg-mist aria-pressed:opacity-100" aria-pressed="true"><span class="size-2.5 rounded-full" style="background:<?= e($s['color']) ?>"></span><?= e($s['name']) ?> <span class="tabular-nums text-steel"><?= (int) $s['total'] ?></span></button></li><?php endforeach ?>
        </ul>
        <?php endif ?>
    </section>

    <section class="panel">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Most active department')) ?></h2></div>
        <?php if ($best): $bd = (int) $best['d']; ?>
        <div class="m-4 rounded-xl p-4 text-white" style="background: <?= e($depts[$bd]['color'] ?? '#475569') ?>">
            <p class="text-xs uppercase tracking-wide text-white/80"><?= e(__('Top of the period')) ?></p>
            <p class="mt-1 font-display text-3xl font-semibold leading-tight"><?= e($name($bd)) ?></p>
            <p class="mt-2 text-sm text-white/90"><?= e(__(':a actions · :b people', ['a' => number_format((int) $best['total']), 'b' => (int) $best['people']])) ?></p>
        </div>
        <ol class="space-y-3 px-5 pb-5">
            <?php foreach ($rank as $i => $r): $d = (int) $r['d']; $hc = (int) ($heads[$d] ?? 0); ?>
            <li>
                <div class="flex items-center justify-between gap-3 text-sm"><span class="flex min-w-0 items-center gap-2"><span class="w-4 text-xs tabular-nums text-steel"><?= $i + 1 ?></span><?= $dot($d) ?><span class="truncate font-medium"><?= e($name($d)) ?></span></span><span class="shrink-0 tabular-nums text-steel"><?= number_format((int) $r['total']) ?></span></div>
                <div class="mt-1.5 h-2 rounded-full bg-graphite-900/6"><div class="h-2 rounded-full" style="width: <?= max(2, (int) $r['total'] / $maxRank * 100) ?>%; background: <?= e($depts[$d]['color'] ?? '#9ca3af') ?>"></div></div>
                <p class="mt-1 pl-6 text-[11px] text-steel"><?= e(__(':l sign-ins · :c changes · :p of :h people active', ['l' => (int) $r['logins'], 'c' => (int) $r['changes'], 'p' => (int) $r['people'], 'h' => $hc])) ?></p>
            </li>
            <?php endforeach ?>
        </ol>
        <?php else: ?><p class="px-5 py-12 text-center text-sm text-steel"><?= e(__('No activity in this period.')) ?></p><?php endif ?>
    </section>
</div>

<!-- people + hours -->
<div class="mt-4 grid items-start gap-4 xl:grid-cols-3">
    <section class="panel xl:col-span-2">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Most active people')) ?></h2><span class="text-xs text-steel"><?= e(__('by sign-ins and changes')) ?></span></div>
        <ol class="divide-y divide-graphite-900/6">
            <?php foreach ($people as $i => $p): $d = (int) $p['d']; ?>
            <li class="flex items-center gap-3 px-5 py-3">
                <span class="w-5 text-center font-display text-lg font-semibold tabular-nums <?= $i < 3 ? 'text-signal-600' : 'text-steel' ?>"><?= $i + 1 ?></span>
                <?= partial('partials/avatar', ['name' => $p['name'], 'avatar' => $p['avatar'], 'size' => 'size-9']) ?>
                <div class="min-w-0 flex-1">
                    <a href="<?= url('/members/'.$p['id']) ?>" class="block truncate text-sm font-medium hover:text-signal-700"><?= e($p['name']) ?></a>
                    <span class="mt-0.5 inline-flex items-center gap-1.5 text-xs text-steel"><?= $dot($d) ?><?= e($name($d)) ?> · <?= e(time_ago($p['last_at'])) ?></span>
                    <div class="mt-1.5 flex h-1.5 overflow-hidden rounded-full bg-graphite-900/6"><div style="width: <?= (int) $p['logins'] / $maxPeople * 100 ?>%; background: <?= e($depts[$d]['color'] ?? '#9ca3af') ?>"></div><div style="width: <?= (int) $p['changes'] / $maxPeople * 100 ?>%; background: <?= e($depts[$d]['color'] ?? '#9ca3af') ?>; opacity:.45"></div></div>
                </div>
                <div class="text-right"><p class="figure text-2xl"><?= (int) $p['total'] ?></p><p class="text-[11px] text-steel"><?= (int) $p['logins'] ?> <?= e(__('in')) ?> · <?= (int) $p['changes'] ?> <?= e(__('changes')) ?></p></div>
            </li>
            <?php endforeach ?>
            <?php if (! $people): ?><li class="px-5 py-10 text-center text-sm text-steel"><?= e(__('No activity in this period.')) ?></li><?php endif ?>
        </ol>
    </section>

    <section class="panel">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Busiest hours')) ?></h2><span class="text-xs text-steel"><?= e(__('sign-ins')) ?></span></div>
        <div class="px-3 pb-4 pt-4 sm:px-5"><div class="relative h-56"><canvas id="ctl-hours" role="img" aria-label="<?= e(__('Busiest hours')) ?>"></canvas></div></div>
    </section>
</div>

<!-- each department's most frequent sign-ins -->
<section class="mt-6" aria-label="<?= e(__('Most sign-ins in each department')) ?>">
    <div class="mb-3 flex items-end justify-between"><h2 class="text-base font-semibold"><?= e(__('Most sign-ins in each department')) ?></h2></div>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <?php foreach ($rank as $r): $d = (int) $r['d']; $list = $topBy[$d] ?? []; if (! $list) continue; ?>
        <article class="panel overflow-hidden">
            <div class="flex items-center justify-between gap-3 px-4 py-3" style="border-top: 3px solid <?= e($depts[$d]['color'] ?? '#9ca3af') ?>">
                <h3 class="flex min-w-0 items-center gap-2 text-sm font-semibold"><?= $dot($d) ?><span class="truncate"><?= e($name($d)) ?></span></h3><span class="text-xs tabular-nums text-steel"><?= e(__(':n sign-ins', ['n' => (int) $r['logins']])) ?></span>
            </div>
            <ol class="divide-y divide-graphite-900/6 border-t border-graphite-900/6">
                <?php foreach ($list as $t): ?>
                <li class="flex items-center gap-3 px-4 py-2.5"><span class="w-4 text-center text-xs font-semibold tabular-nums text-steel"><?= (int) $t['rk'] ?></span><?= partial('partials/avatar', ['name' => $t['name'], 'avatar' => $t['avatar'], 'size' => 'size-8']) ?>
                    <span class="min-w-0 flex-1"><a href="<?= url('/members/'.$t['id']) ?>" class="block truncate text-sm font-medium hover:text-signal-700"><?= e($t['name']) ?></a><span class="block text-xs text-steel"><?= e(time_ago($t['last_at'])) ?></span></span>
                    <span class="figure text-xl"><?= (int) $t['logins'] ?></span></li>
                <?php endforeach ?>
            </ol>
        </article>
        <?php endforeach ?>
        <?php if (! array_filter($topBy)): ?><p class="rounded-lg bg-white px-5 py-10 text-center text-sm text-steel shadow-sm ring-1 ring-graphite-900/8 sm:col-span-2 xl:col-span-3"><?= e(__('No sign-ins in this period.')) ?></p><?php endif ?>
    </div>
</section>

<section class="panel mt-6">
    <div class="panel-head"><h2 class="panel-title"><?= e(__('Latest activity')) ?></h2><a href="<?= url('/activity') ?>" class="text-sm text-steel hover:text-graphite-900"><?= e(__('Open the activity log')) ?> ›</a></div>
    <ul class="divide-y divide-graphite-900/6">
        <?php foreach ($recent as $log): ?><?= partial('partials/activity-row', ['log' => $log]) ?><?php endforeach ?>
        <?php if (! $recent): ?><li class="px-5 py-10 text-center text-sm text-steel"><?= e(__('Nothing logged in this period.')) ?></li><?php endif ?>
    </ul>
</section>

<script type="application/json" id="ctl-data"><?= json_encode([
    'unit' => $unit, 'buckets' => $buckets, 'series' => $series, 'hours' => $hours,
    'label' => ['signins' => __('sign-ins'), 'hour' => __('Hour')],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= asset('assets/vendor/chartjs/chart.umd.min.js') ?>"></script>
<script src="<?= asset('assets/control.js') ?>" defer></script>
