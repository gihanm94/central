<?php /* $from, $to, $period, $year, $data ?array (CRM management.crm), $departments [id => row], $people [id => user] */
use App\Modules\Management\Controllers\OverviewController;

$cur = $data['cur'] ?? 'THB';
$sym = $cur === 'THB' ? '฿' : $cur.' ';
$money = fn (float $v) => $sym.number_format($v, 0);
$short = function (float $v) use ($sym): string {
    $a = abs($v);
    return $sym.($a >= 1e9 ? number_format($v / 1e9, 2).'B' : ($a >= 1e6 ? number_format($v / 1e6, 2).'M' : ($a >= 1e3 ? number_format($v / 1e3, 1).'K' : number_format($v, 0))));
};
$medal = fn (int $i) => $i === 0 ? 'bg-amber-400 text-white' : ($i === 1 ? 'bg-graphite-300 text-graphite-900' : ($i === 2 ? 'bg-amber-700 text-white' : 'bg-graphite-900/6 text-graphite-700'));
$years = range((int) date('Y'), (int) date('Y') - 5);
$tab = fn (string $k) => url('/management/crm', array_filter(['period' => $k === 'ytd' ? null : $k, 'year' => $k === 'year' ? $year : null]));
$stageTone = ['QUALIFICATION' => 'bg-sky-400', 'SURVEY_PROPOSAL' => 'bg-sky-500', 'EVALUATION_TESTING' => 'bg-sky-600', 'NEGOTIATION' => 'bg-violet-600', 'ON_HOLD' => 'bg-amber-500'];
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0"><h1 class="page-title"><?= e(__('CRM overview')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(format_date($from, 'j M Y').' – '.format_date($to, 'j M Y')) ?> · <?= e(__('Opportunities of every department, amounts in :cur.', ['cur' => $cur])) ?></p></div>
</div>

<div class="mt-4 flex flex-wrap items-center gap-2">
    <nav class="flex flex-wrap rounded-md bg-graphite-900/6 p-0.5 text-sm" aria-label="<?= e(__('Period')) ?>">
        <?php foreach (OverviewController::PERIODS as $k => $label): if ($k === 'custom') { continue; } ?>
            <a href="<?= e($tab($k)) ?>" class="rounded px-3 py-1.5 <?= $period === $k ? 'bg-white font-medium shadow-sm' : 'text-steel hover:text-graphite-900' ?>"><?= e(__($label)) ?></a>
        <?php endforeach ?>
    </nav>
    <?php if ($period === 'year'): ?>
        <form method="GET" action="<?= url('/management/crm') ?>" class="w-28"><input type="hidden" name="period" value="year"><?= select_field('year', array_combine($years, $years), $year, ['id' => 'mg-year', 'submit' => true, 'search' => false, 'aria' => __('Year')]) ?></form>
    <?php endif ?>
    <form method="GET" action="<?= url('/management/crm') ?>" class="ml-auto flex flex-wrap items-center gap-2">
        <input type="hidden" name="period" value="custom">
        <input type="date" name="from" value="<?= e($from) ?>" class="input !w-40" aria-label="<?= e(__('From')) ?>"><span class="text-steel">→</span>
        <input type="date" name="to" value="<?= e($to) ?>" class="input !w-40" aria-label="<?= e(__('To')) ?>">
        <button class="btn-secondary"><?= e(__('Show')) ?></button>
    </form>
</div>

<?php if ($data === null): ?>
    <section class="panel mt-4 px-5 py-10 text-center text-sm text-steel"><?= e(__('CRM figures are not available right now (the CRM module is off or unavailable). The rest of Management still works.')) ?></section>
<?php else:
    $t = $data['totals'];
    $maxMonth = max([0.0, ...array_column($data['months'], 'won_v')]);
    $maxStage = max([0.0, ...array_column($data['stages'], 'v')]);
    $wonAll = max(1.0, array_sum(array_column($data['departments'], 'won_v')));
?>
<div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
    <div class="panel p-4"><p class="text-xs font-medium uppercase tracking-wide text-steel"><?= e(__('Open pipeline')) ?></p>
        <p class="mt-1 font-display text-4xl font-semibold tabular-nums" title="<?= e($money($t['open_v'])) ?>"><?= e($short($t['open_v'])) ?></p>
        <p class="mt-1 text-xs text-steel"><?= e(__(':n open · weighted :w', ['n' => (int) $t['open_n'], 'w' => $short($t['weighted'])])) ?></p></div>
    <div class="panel p-4"><p class="text-xs font-medium uppercase tracking-wide text-steel"><?= e(__('Won in period')) ?></p>
        <p class="mt-1 font-display text-4xl font-semibold tabular-nums text-emerald-700" title="<?= e($money($t['won_v'])) ?>"><?= e($short($t['won_v'])) ?></p>
        <p class="mt-1 text-xs text-steel"><?= e(__(':n deals · average :a', ['n' => (int) $t['won_n'], 'a' => $t['avg_won'] !== null ? $short($t['avg_won']) : '—'])) ?></p></div>
    <div class="panel p-4"><p class="text-xs font-medium uppercase tracking-wide text-steel"><?= e(__('Win rate')) ?></p>
        <p class="mt-1 font-display text-4xl font-semibold tabular-nums"><?= $t['win_rate'] !== null ? e(number_format($t['win_rate'], 1)).'%' : '—' ?></p>
        <p class="mt-1 text-xs text-steel"><?= e(__(':w won · :l lost (:v)', ['w' => (int) $t['won_n'], 'l' => (int) $t['lost_n'], 'v' => $short($t['lost_v'])])) ?></p></div>
    <div class="panel p-4"><p class="text-xs font-medium uppercase tracking-wide text-steel"><?= e(__('New opportunities')) ?></p>
        <p class="mt-1 font-display text-4xl font-semibold tabular-nums"><?= (int) $t['new_n'] ?></p>
        <p class="mt-1 text-xs <?= $t['stale_n'] ? 'text-signal-700' : 'text-steel' ?>"><?= e(__('worth :v · :s open more than 90 days in one stage', ['v' => $short($t['new_v']), 's' => (int) $t['stale_n']])) ?></p></div>
</div>

<?php $tg = $data['target']; if ($tg['total'] > 0): $pct = $tg['actual'] / $tg['total'] * 100; ?>
<section class="panel mt-4 p-5">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="panel-title"><?= e(__('Sales target :y', ['y' => $tg['year']])) ?></h2>
        <p class="text-sm tabular-nums"><strong><?= e($short($tg['actual'])) ?></strong> <span class="text-steel">/ <?= e($short($tg['total'])) ?> · <?= e(number_format($pct, 1)) ?>%</span></p>
    </div>
    <div class="mt-3 h-3 overflow-hidden rounded-full bg-graphite-900/6"><div class="h-full rounded-full <?= $pct >= 100 ? 'bg-emerald-600' : 'bg-signal-600' ?>" style="width: <?= round(min(100, $pct), 1) ?>%"></div></div>
    <p class="mt-1.5 text-xs text-steel"><?= e(__('All departments\' targets added up (CRM → Sales targets).')) ?></p>
</section>
<?php endif ?>

<div class="mt-4 grid gap-4 xl:grid-cols-5">
    <section class="panel p-5 xl:col-span-3">
        <div class="flex items-baseline justify-between gap-2"><h2 class="panel-title"><?= e(__('Won by month')) ?></h2><span class="text-xs text-steel"><?= e(__('last 12 months · new opportunities below')) ?></span></div>
        <div class="mt-4 flex h-48 items-end gap-1.5">
            <?php foreach ($data['months'] as $m): $h = $maxMonth > 0 ? max(2, $m['won_v'] / $maxMonth * 100) : 2; ?>
                <div class="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1" title="<?= e(format_date($m['ym'].'-01', 'F Y').': '.$money($m['won_v']).' · '.__(':n new', ['n' => $m['new_n']])) ?>">
                    <span class="hidden truncate text-[10px] tabular-nums text-steel sm:block"><?= $m['won_v'] > 0 ? e($short($m['won_v'])) : '' ?></span>
                    <div class="w-full max-w-10 rounded-t <?= $m['won_v'] > 0 ? 'bg-emerald-600' : 'bg-graphite-900/8' ?>" style="height: <?= round($h, 1) ?>%"></div>
                    <span class="text-[11px] text-steel"><?= e(format_date($m['ym'].'-01', 'M')) ?></span>
                    <span class="text-[10px] tabular-nums text-graphite-500"><?= (int) $m['new_n'] ?></span>
                </div>
            <?php endforeach ?>
        </div>
    </section>
    <section class="panel p-5 xl:col-span-2">
        <h2 class="panel-title"><?= e(__('Pipeline by stage')) ?></h2>
        <ul class="mt-4 space-y-3">
            <?php foreach ($data['stages'] as $k => $s): ?>
                <li>
                    <div class="flex items-baseline justify-between gap-2 text-sm"><span><?= e($s['label']) ?> <span class="text-xs text-steel">· <?= (int) $s['n'] ?></span></span>
                        <span class="tabular-nums" title="<?= e($money($s['v'])) ?>"><?= e($short($s['v'])) ?></span></div>
                    <div class="mt-1 h-2 overflow-hidden rounded-full bg-graphite-900/6"><div class="h-full rounded-full <?= $stageTone[$k] ?? 'bg-graphite-600' ?>" style="width: <?= $maxStage > 0 ? round($s['v'] / $maxStage * 100, 1) : 0 ?>%"></div></div>
                </li>
            <?php endforeach ?>
        </ul>
        <a href="<?= url('/crm/control', ['view' => 'stage']) ?>" class="mt-4 inline-block text-xs text-steel hover:text-graphite-900"><?= e(__('Find stuck opportunities in CRM → Sales control')) ?> →</a>
    </section>
</div>

<div class="mt-4 grid gap-4 xl:grid-cols-2">
    <section class="panel overflow-hidden">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Top sales people')) ?></h2><span class="text-xs text-steel"><?= e(__('by won amount')) ?></span></div>
        <?php if (! $data['sellers']): ?><p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('No opportunities in this period.')) ?></p><?php endif ?>
        <ol class="divide-y divide-graphite-900/6">
            <?php foreach ($data['sellers'] as $i => $s): $u = $s['id'] ? ($people[$s['id']] ?? null) : null; $dep = $departments[$u['department_id'] ?? 0] ?? null; ?>
                <li class="flex items-center gap-3 px-5 py-2.5">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-bold <?= $medal($i) ?>"><?= $i + 1 ?></span>
                    <?php $who = $u['name'] ?? ($s['id'] ? __('Deleted user').' #'.$s['id'] : __('No owner')); ?>
                    <?= partial('partials/avatar', ['name' => $who, 'avatar' => $u['avatar'] ?? null, 'size' => 'size-9']) ?>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium <?= $u ? '' : 'text-steel' ?>"><?= e($who) ?></p>
                        <p class="truncate text-xs text-steel"><?= e(($dep['name'] ?? '—').' · '.__(':w won · open :o (:n)', ['w' => $s['won_n'], 'o' => $short($s['open_v']), 'n' => $s['open_n']]).($s['win_rate'] !== null ? ' · '.__('win :p%', ['p' => $s['win_rate']]) : '')) ?></p>
                    </div>
                    <p class="font-display text-lg font-semibold tabular-nums <?= $s['won_v'] > 0 ? 'text-emerald-700' : 'text-steel' ?>" title="<?= e($money($s['won_v'])) ?>"><?= e($short($s['won_v'])) ?></p>
                </li>
            <?php endforeach ?>
        </ol>
    </section>

    <section class="panel overflow-hidden">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Department ranking')) ?></h2><span class="text-xs text-steel"><?= e(__('by won amount')) ?></span></div>
        <?php if (! $data['departments']): ?><p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('No opportunities in this period.')) ?></p><?php endif ?>
        <ol class="divide-y divide-graphite-900/6">
            <?php $rank = 0; foreach ($data['departments'] as $d): $dep = $d['id'] ? ($departments[$d['id']] ?? null) : null; ?>
                <li class="flex items-center gap-3 px-5 py-2.5">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-bold <?= $medal($rank++) ?>"><?= $rank ?></span>
                    <span class="size-3 shrink-0 rounded-full" style="background: <?= e($dep['color'] ?? '#9ca3af') ?>"></span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium"><?= e($dep['name'] ?? __('No department')) ?></p>
                        <p class="truncate text-xs text-steel"><?= e(__(':w won · open :o (:n)', ['w' => $d['won_n'], 'o' => $short($d['open_v']), 'n' => $d['open_n']]).($d['win_rate'] !== null ? ' · '.__('win :p%', ['p' => $d['win_rate']]) : '')) ?></p>
                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-graphite-900/6"><div class="h-full rounded-full" style="background: <?= e($dep['color'] ?? '#9ca3af') ?>; width: <?= round($d['won_v'] / $wonAll * 100, 1) ?>%"></div></div>
                    </div>
                    <p class="font-display text-lg font-semibold tabular-nums <?= $d['won_v'] > 0 ? 'text-emerald-700' : 'text-steel' ?>" title="<?= e($money($d['won_v'])) ?>"><?= e($short($d['won_v'])) ?></p>
                </li>
            <?php endforeach ?>
        </ol>
    </section>
</div>

<section class="panel mt-4 overflow-hidden">
    <div class="panel-head"><h2 class="panel-title"><?= e(__('Biggest open opportunities')) ?></h2><span class="text-xs text-steel"><?= e(__('top 8 by amount')) ?></span></div>
    <?php if (! $data['top']): ?><p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('No open opportunities.')) ?></p><?php else: ?>
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead class="bg-mist/60 text-left text-xs text-steel"><tr>
            <th class="px-5 py-2 font-medium"><?= e(__('Opportunity')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Stage')) ?></th>
            <th class="px-3 py-2 font-medium"><?= e(__('Owner')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Department')) ?></th>
            <th class="px-3 py-2 font-medium"><?= e(__('In stage since')) ?></th><th class="px-5 py-2 text-right font-medium"><?= e(__('Amount')) ?></th></tr></thead>
        <tbody class="divide-y divide-graphite-900/6">
        <?php foreach ($data['top'] as $o): $days = (int) floor((time() - strtotime((string) $o['since'])) / 86400); ?>
            <tr>
                <td class="px-5 py-2.5"><a href="<?= url('/crm/opportunities/'.(int) $o['id']) ?>" class="font-medium hover:text-signal-700"><?= e($o['name']) ?></a>
                    <span class="block text-xs text-steel"><?= e(trim(($o['code'] ?: '').($o['customer'] ? ' · '.$o['customer'] : ''), ' ·')) ?></span></td>
                <td class="whitespace-nowrap px-3 py-2.5"><span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full <?= $stageTone[$o['stage']] ?? 'bg-graphite-400' ?>"></span><?= e($data['stages'][$o['stage']]['label'] ?? $o['stage']) ?></span></td>
                <td class="px-3 py-2.5"><?= e($people[(int) $o['owner_id']]['name'] ?? ($o['owner_id'] ? __('Deleted user').' #'.(int) $o['owner_id'] : '—')) ?></td>
                <td class="px-3 py-2.5 text-steel"><?= e($departments[(int) $o['department_id']]['name'] ?? '—') ?></td>
                <td class="whitespace-nowrap px-3 py-2.5"><?= e(format_date($o['since'])) ?> <span class="ml-1 rounded px-1.5 py-0.5 text-xs tabular-nums <?= $days >= 90 ? 'bg-signal-50 text-signal-800' : ($days >= 30 ? 'bg-amber-50 text-amber-800' : 'bg-mist text-steel') ?>"><?= e(__(':n days', ['n' => $days])) ?></span></td>
                <td class="whitespace-nowrap px-5 py-2.5 text-right font-medium tabular-nums" title="<?= e($money((float) $o['v'])) ?>"><?= e($short((float) $o['v'])) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
</section>
<?php endif ?>
