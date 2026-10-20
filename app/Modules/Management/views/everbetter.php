<?php
/* $tab (presentations | departments | ranking), $byDepartment, $ranking, $session (this quarter's day), $y, $q, $thisQuarter, $requests, $status, $projects, $year, $sessions [quarter => row], $allSessions [id => label], $counts {waiting, accepted} */
use App\Core\Support\Everbetter;

$kinds = Everbetter::kinds();
$kindTone = ['initiation' => 'bg-sky-50 text-sky-800', 'closure' => 'bg-emerald-50 text-emerald-800', 'extension' => 'bg-violet-50 text-violet-800'];
$tabs = ['open' => __('Waiting'), 'submitted' => __('Not accepted yet'), 'decided' => __('Decided'), 'all' => __('All')];
$link = fn (array $x) => url('/management/everbetter', array_filter(array_merge(['tab' => 'presentations', 'status' => $status, 'year' => $year], $x), fn ($v) => $v !== null && $v !== ''));
$actions = function (array $x) {
    ob_start(); ?>
    <div class="flex flex-wrap justify-end gap-1.5">
        <?php if ($x['status'] === 'submitted'): ?>
            <form method="POST" action="<?= url('/management/everbetter/requests/'.(int) $x['id'].'/acknowledge') ?>"><?= csrf_field() ?><button class="btn-secondary h-8 px-3 text-xs"><?= icon('check', 'size-4') ?> <?= e(__('Accept')) ?></button></form>
        <?php endif ?>
        <?php if ($x['status'] !== 'withdrawn' && $x['result'] === null): ?>
            <button type="button" class="btn-primary h-8 px-3 text-xs" data-open-dialog="eb-decide" data-eb-decide="<?= (int) $x['id'] ?>" data-eb-title="<?= e($x['title']) ?>" data-eb-kind="<?= e(Everbetter::kinds()[$x['kind']]) ?>"><?= e(__('Record result')) ?></button>
        <?php endif ?>
    </div>
    <?php return (string) ob_get_clean();
};
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0"><h1 class="page-title"><?= e(__('Everbetter')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(__('Improvement projects presented every quarter. Accept each request, then record the result after the presentation.')) ?></p></div>
    <div class="flex gap-2 text-sm">
        <span class="rounded-lg bg-amber-50 px-3 py-1.5 text-amber-900"><?= e(__(':n not accepted yet', ['n' => (int) $counts['waiting']])) ?></span>
        <span class="rounded-lg bg-sky-50 px-3 py-1.5 text-sky-900"><?= e(__(':n to present', ['n' => (int) $counts['accepted']])) ?></span>
    </div>
</div>

<nav class="mt-6 flex gap-7 overflow-x-auto rounded-xl bg-white px-6 shadow-sm ring-1 ring-graphite-900/8" aria-label="<?= e(__('Everbetter')) ?>">
    <?php foreach (['projects' => __('All projects'), 'presentations' => __('Presentations'), 'departments' => __('By department'), 'ranking' => __('Ranking')] as $k => $label): $on = $tab === $k; ?>
        <a href="<?= e(url('/management/everbetter', array_filter(['tab' => $k === 'projects' ? null : $k]))) ?>" <?= $on ? 'aria-current="page"' : '' ?>
           class="relative shrink-0 py-4 text-sm after:absolute after:inset-x-0 after:bottom-0 after:h-[3px] after:rounded-full <?= $on ? 'font-semibold text-signal-700 after:bg-signal-600' : 'text-graphite-700 hover:text-graphite-900 after:bg-transparent' ?>"><?= e($label) ?></a>
    <?php endforeach ?>
</nav>

<?php if ($tab === 'projects'): $pq = (string) App\Core\Support\Request::query('q', ''); $ps = (string) App\Core\Support\Request::query('pstatus', ''); ?>
<section class="panel mt-4 overflow-hidden" aria-labelledby="eb-all">
    <div class="panel-head flex-wrap gap-2">
        <h2 class="panel-title" id="eb-all"><?= e(__('All projects')) ?> <span class="ml-1 text-sm font-normal text-steel"><?= count($projects) ?></span></h2>
        <form method="GET" action="<?= url('/management/everbetter') ?>" class="flex flex-wrap items-center gap-2">
            <input name="q" value="<?= e($pq) ?>" class="input !w-56" placeholder="<?= e(__('Project or owner')) ?>" aria-label="<?= e(__('Search')) ?>">
            <div class="w-44"><?= select_field('pstatus', array_map(fn ($l) => __($l), Everbetter::PROJECT), $ps, ['placeholder' => __('Any status'), 'search' => false, 'aria' => __('Status')]) ?></div>
            <button class="btn-secondary"><?= e(__('Show')) ?></button>
        </form>
    </div>
    <?php if (! $projects): ?><p class="px-5 py-10 text-center text-sm text-steel"><?= e(__('No projects yet.')) ?></p><?php else: ?>
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead class="bg-mist/60 text-left text-xs text-steel"><tr>
            <th class="px-5 py-2 font-medium"><?= e(__('Project')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Department')) ?></th>
            <th class="px-3 py-2 font-medium"><?= e(__('Period')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Team')) ?></th>
            <th class="px-3 py-2 font-medium"><?= e(__('Presented')) ?></th><th class="px-5 py-2 font-medium"><?= e(__('Status')) ?></th></tr></thead>
        <tbody class="divide-y divide-graphite-900/6">
        <?php foreach ($projects as $p): $last = $p['last_request']; ?>
            <tr class="cursor-pointer hover:bg-mist/50" onclick="if (!event.target.closest('a')) location.href = this.querySelector('a').href">
                <td class="max-w-[22rem] px-5 py-3"><a href="<?= url('/management/everbetter/projects/'.(int) $p['id']) ?>" class="font-medium hover:text-signal-700"><?= e($p['title']) ?></a>
                    <span class="block text-xs text-steel"><?= e($p['owner_name']) ?></span></td>
                <td class="px-3 py-3 text-steel"><?= e($p['department_name'] ?? '—') ?></td>
                <td class="whitespace-nowrap px-3 py-3 text-steel"><?= e(format_date($p['start_date'], 'd M y').' – '.format_date($p['end_date'], 'd M y')) ?></td>
                <td class="px-3 py-3"><div class="flex -space-x-2"><?php foreach (array_slice($p['members'], 0, 5) as $m): ?><?= partial('partials/avatar', ['name' => $m['name'], 'avatar' => $m['avatar'], 'size' => 'size-7', 'extra' => 'ring-2 ring-white']) ?><?php endforeach ?>
                    <?php if (count($p['members']) > 5): ?><span class="flex size-7 items-center justify-center rounded-full bg-mist text-[11px] ring-2 ring-white">+<?= count($p['members']) - 5 ?></span><?php endif ?></div></td>
                <td class="px-3 py-3"><span class="badge <?= (int) $p['presented'] ? 'bg-emerald-50 text-emerald-800' : 'bg-graphite-900/6 text-steel' ?>"><?= (int) $p['presented'] ? '✓ '.e(__('Presented')) : e(__('Not presented yet')) ?></span>
                    <?php if ($last && $last['result'] === null): [$ll, $lt] = Everbetter::stateOf($last); ?><span class="mt-1 block text-xs text-steel"><?= e($kinds[$last['kind']].' · '.$ll) ?></span><?php endif ?></td>
                <td class="px-5 py-3"><div class="flex flex-wrap gap-1"><?= partial('everbetter/status', ['p' => $p]) ?></div></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
</section>

<?php elseif ($tab === 'presentations'): ?>
<section class="panel mt-4 overflow-hidden" aria-labelledby="eb-quarter">
    <div class="panel-head">
        <h2 class="panel-title" id="eb-quarter"><?= e(__('This quarter (Q:q :y)', ['q' => $q, 'y' => $y])) ?></h2>
        <span class="text-sm text-steel"><?= $session ? e(__('Presentation day :date', ['date' => format_date($session['present_on'], 'l d M Y')])).($session['place'] ? ' · '.e($session['place']) : '') : e(__('No presentation day set')) ?></span>
    </div>
    <?php if (! $session): ?>
        <p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('Set this quarter\'s presentation day below so people can ask to present.')) ?></p>
    <?php elseif (! $thisQuarter): ?>
        <p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('Nobody has asked to present this quarter yet.')) ?></p>
    <?php else: ?>
    <ul class="divide-y divide-graphite-900/6">
        <?php foreach ($thisQuarter as $x): [$l, $tone] = Everbetter::stateOf($x); ?>
        <li class="flex flex-wrap items-center gap-3 px-5 py-3">
            <?= partial('partials/avatar', ['name' => $x['requested_name'], 'avatar' => $x['avatar'], 'size' => 'size-9']) ?>
            <div class="min-w-0 flex-1">
                <a href="<?= url('/management/everbetter/projects/'.(int) $x['project_id']) ?>" class="block truncate font-medium hover:text-signal-700"><?= e($x['title']) ?></a>
                <p class="truncate text-xs text-steel"><?= e($x['requested_name']) ?><?= $x['note'] ? ' · '.e(mb_substr($x['note'], 0, 120)) : '' ?></p>
            </div>
            <span class="badge <?= $kindTone[$x['kind']] ?>"><?= e($kinds[$x['kind']]) ?></span>
            <span class="badge <?= $tone ?>"><?= e($l) ?></span>
            <?= $actions($x + ['title' => $x['title']]) ?>
        </li>
        <?php endforeach ?>
    </ul>
    <?php endif ?>
</section>

<section class="panel mt-4 overflow-hidden" aria-labelledby="eb-requests">
    <div class="panel-head flex-wrap gap-2">
        <h2 class="panel-title" id="eb-requests"><?= e(__('Presentation requests')) ?></h2>
        <div class="flex flex-wrap items-center gap-2">
            <nav class="flex rounded-md bg-graphite-900/6 p-0.5 text-sm"><?php foreach ($tabs as $k => $t): ?><a href="<?= e($link(['status' => $k])) ?>" class="rounded px-3 py-1 <?= $status === $k ? 'bg-white font-medium shadow-sm' : 'text-steel hover:text-graphite-900' ?>"><?= e($t) ?></a><?php endforeach ?></nav>
            <form method="GET" action="<?= url('/management/everbetter') ?>" class="w-56"><input type="hidden" name="tab" value="presentations"><input type="hidden" name="status" value="<?= e($status) ?>">
                <?= select_field('session', $allSessions, (string) (int) App\Core\Support\Request::query('session', 0) ?: '', ['placeholder' => __('Every presentation day'), 'submit' => true, 'search' => false, 'aria' => __('Presentation day')]) ?></form>
        </div>
    </div>
    <?php if (! $requests): ?><p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('Nothing here.')) ?></p><?php else: ?>
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead class="bg-mist/60 text-left text-xs text-steel"><tr>
            <th class="px-5 py-2 font-medium"><?= e(__('Project')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Presents')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Day')) ?></th>
            <th class="px-3 py-2 font-medium"><?= e(__('Status')) ?></th><th class="px-5 py-2"><span class="sr-only"><?= e(__('Actions')) ?></span></th></tr></thead>
        <tbody class="divide-y divide-graphite-900/6">
        <?php foreach ($requests as $x): [$l, $tone] = Everbetter::stateOf($x); ?>
            <tr class="align-top">
                <td class="max-w-[22rem] px-5 py-3"><a href="<?= url('/management/everbetter/projects/'.(int) $x['project_id']) ?>" class="font-medium hover:text-signal-700"><?= e($x['title']) ?></a>
                    <span class="block text-xs text-steel"><?= e($x['owner_name'].' · '.($x['department_name'] ?? '—').' · '.format_date($x['start_date'], 'd M y').' – '.format_date($x['end_date'], 'd M y')) ?></span>
                    <?php if ($x['note']): ?><span class="mt-1 block text-xs"><?= e(mb_substr($x['note'], 0, 200)) ?></span><?php endif ?></td>
                <td class="px-3 py-3"><span class="badge <?= $kindTone[$x['kind']] ?>"><?= e($kinds[$x['kind']]) ?></span>
                    <?php if ($x['kind'] === 'extension' && $x['new_end_date']): ?><span class="mt-1 block text-xs text-steel"><?= e(__('to :d', ['d' => format_date($x['new_end_date'])])) ?></span><?php endif ?>
                    <span class="mt-1 block text-xs text-steel"><?= e($x['requested_name']) ?></span></td>
                <td class="whitespace-nowrap px-3 py-3"><?= e(format_date($x['present_on'], 'd M Y')) ?><span class="block text-xs text-steel">Q<?= (int) $x['quarter'] ?> <?= (int) $x['year'] ?></span></td>
                <td class="px-3 py-3"><span class="badge <?= $tone ?>"><?= e($l) ?></span>
                    <?php if ($x['result_note']): ?><span class="mt-1 block max-w-[14rem] text-xs text-steel"><?= e($x['result_note']) ?></span><?php endif ?></td>
                <td class="px-5 py-3"><?= $actions($x) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
</section>

<div class="mt-4 max-w-3xl">
    <section id="days" class="panel p-5">
        <div class="flex items-center justify-between gap-2">
            <h2 class="panel-title"><?= e(__('Presentation days')) ?></h2>
            <div class="inline-flex items-center rounded-md bg-white ring-1 ring-graphite-900/15">
                <a href="<?= e($link(['year' => $year - 1])) ?>#days" class="btn-ghost px-2" aria-label="<?= e(__('Previous year')) ?>"><?= icon('arrowleft', 'size-4') ?></a>
                <span class="px-2 text-sm font-semibold tabular-nums"><?= (int) $year ?></span>
                <a href="<?= e($link(['year' => $year + 1])) ?>#days" class="btn-ghost px-2" aria-label="<?= e(__('Next year')) ?>"><?= icon('right', 'size-4') ?></a>
            </div>
        </div>
        <p class="mt-1 text-xs text-steel"><?= e(__('One day per quarter. People choose it when they ask to present.')) ?></p>
        <form method="POST" action="<?= url('/management/everbetter/days') ?>" class="mt-3 space-y-3">
            <?= csrf_field() ?><input type="hidden" name="year" value="<?= (int) $year ?>">
            <?php foreach ([1, 2, 3, 4] as $qq): $s = $sessions[$qq] ?? null; ?>
            <div class="grid grid-cols-[3rem_1fr] items-start gap-2">
                <span class="pt-2 text-sm font-semibold">Q<?= $qq ?></span>
                <div class="grid gap-2 sm:grid-cols-2">
                    <div><input type="date" name="day_<?= $qq ?>" value="<?= e((string) old('day_'.$qq, $s['present_on'] ?? '')) ?>" class="input" aria-label="<?= e(__('Q:q presentation day', ['q' => $qq])) ?>"
                        min="<?= sprintf('%04d-%02d-01', $year, ($qq - 1) * 3 + 1) ?>" max="<?= date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $qq * 3))) ?>"><?= field_error('day_'.$qq) ?></div>
                    <input name="place_<?= $qq ?>" value="<?= e((string) old('place_'.$qq, $s['place'] ?? '')) ?>" maxlength="200" class="input" placeholder="<?= e(__('Room or link (optional)')) ?>" aria-label="<?= e(__('Place')) ?>">
                </div>
            </div>
            <?php endforeach ?>
            <div class="flex justify-end"><button class="btn-primary"><?= e(__('Save days')) ?></button></div>
        </form>
    </section>
</div>

<?php elseif ($tab === 'departments'): $bd = $byDepartment; $maxCell = max([1, ...array_map(fn ($r) => max([0, ...array_column($r['years'], 'submitted')]), $bd['rows'])]); ?>
<section class="panel mt-4 overflow-hidden">
    <div class="panel-head"><h2 class="panel-title"><?= e(__('Projects submitted by department')) ?></h2>
        <span class="text-xs text-steel"><?= e(__('A project counts in the year it first asked to present.')) ?><?= $bd['drafts'] ? ' '.e(__(':n not submitted yet.', ['n' => $bd['drafts']])) : '' ?></span></div>
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead class="bg-mist/60 text-xs text-steel"><tr>
            <th class="px-5 py-2 text-left font-medium"><?= e(__('Department')) ?></th>
            <?php foreach ($bd['years'] as $yr): ?><th class="px-4 py-2 text-center font-medium <?= $yr === $y ? 'text-graphite-900' : '' ?>"><?= $yr ?><?= $yr === $y ? ' <span class="font-normal text-steel">('.e(__('this year')).')</span>' : '' ?></th><?php endforeach ?>
            <th class="px-5 py-2 text-right font-medium"><?= e(__('Total')) ?></th></tr></thead>
        <tbody class="divide-y divide-graphite-900/6">
        <?php foreach ($bd['rows'] as $r): $sum = array_sum(array_column($r['years'], 'submitted')); ?>
            <tr>
                <td class="px-5 py-2.5"><span class="inline-flex items-center gap-2 font-medium"><span class="size-2.5 rounded-full" style="background: <?= e($r['color']) ?>"></span><?= e($r['name']) ?></span></td>
                <?php foreach ($bd['years'] as $yr): $c = $r['years'][$yr] ?? null; $n = $c['submitted'] ?? 0; ?>
                    <td class="px-4 py-2.5 text-center">
                        <?php if ($n): ?>
                            <span class="inline-flex min-w-10 justify-center rounded-md px-2 py-1 font-semibold tabular-nums" style="background: rgba(209,26,42,<?= round(0.08 + 0.5 * $n / $maxCell, 2) ?>); color: <?= $n / $maxCell > 0.6 ? '#fff' : '#7f1d1d' ?>"><?= $n ?></span>
                            <span class="mt-0.5 block text-[11px] text-steel"><?= e(__(':a approved', ['a' => $c['approved']])) ?></span>
                        <?php else: ?><span class="text-graphite-300">0</span><?php endif ?>
                    </td>
                <?php endforeach ?>
                <td class="px-5 py-2.5 text-right font-semibold tabular-nums"><?= $sum ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
        <tfoot class="border-t-2 border-graphite-900/10 bg-mist/40 font-semibold"><tr>
            <td class="px-5 py-2.5"><?= e(__('All departments')) ?></td>
            <?php foreach ($bd['years'] as $yr): ?><td class="px-4 py-2.5 text-center tabular-nums"><?= (int) ($bd['totals'][$yr] ?? 0) ?></td><?php endforeach ?>
            <td class="px-5 py-2.5 text-right tabular-nums"><?= array_sum($bd['totals']) ?></td></tr></tfoot>
    </table></div>
</section>

<?php else: $rk = $ranking; $medal = [1 => ['🥇', 'bg-amber-50 ring-amber-400/60', __('1st')], 2 => ['🥈', 'bg-slate-50 ring-slate-300', __('2nd')], 3 => ['🥉', 'bg-orange-50 ring-orange-300/70', __('3rd')]]; ?>
<div class="mt-4 grid items-start gap-4 xl:grid-cols-5">
    <section class="panel overflow-hidden xl:col-span-3">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Top projects by year')) ?></h2></div>
        <div class="divide-y divide-graphite-900/6">
            <?php foreach ($rk['years'] as $yr): $w = $rk['winners'][$yr] ?? []; ?>
            <div class="px-5 py-4">
                <div class="flex items-center justify-between gap-2"><h3 class="font-display text-xl font-semibold"><?= $yr ?></h3>
                    <a href="<?= e(url('/management/everbetter', ['tab' => 'ranking', 'year' => $yr])) ?>#rank-form" class="text-xs text-steel hover:text-graphite-900"><?= e($w ? __('Change') : __('Set the ranking')) ?></a></div>
                <?php if (! $w): ?><p class="mt-2 text-sm text-steel"><?= e(__('No ranking yet.')) ?></p><?php else: ?>
                <ol class="mt-3 grid gap-2 sm:grid-cols-3">
                    <?php foreach ([1, 2, 3] as $r): $p = $w[$r] ?? null; [$icon, $tone, $label] = $medal[$r]; ?>
                    <li class="rounded-lg p-3 ring-1 <?= $p ? $tone : 'bg-mist/40 ring-graphite-900/8' ?>">
                        <p class="text-xs font-semibold text-steel"><span class="text-lg"><?= $icon ?></span> <?= e($label) ?></p>
                        <?php if ($p): ?><a href="<?= url('/management/everbetter/projects/'.(int) $p['id']) ?>" class="mt-1 block font-medium leading-snug hover:text-signal-700"><?= e($p['title']) ?></a>
                            <p class="mt-0.5 text-xs text-steel"><?= e($p['owner_name'].' · '.($p['department_name'] ?? '—')) ?></p>
                        <?php else: ?><p class="mt-1 text-sm text-steel">—</p><?php endif ?>
                    </li>
                    <?php endforeach ?>
                </ol>
                <?php endif ?>
            </div>
            <?php endforeach ?>
        </div>
    </section>
    <section id="rank-form" class="panel p-5 xl:col-span-2">
        <div class="flex items-center justify-between gap-2">
            <h2 class="panel-title"><?= e(__('Ranking of :y', ['y' => $rk['year']])) ?></h2>
            <div class="inline-flex items-center rounded-md bg-white ring-1 ring-graphite-900/15">
                <a href="<?= e(url('/management/everbetter', ['tab' => 'ranking', 'year' => $rk['year'] - 1])) ?>#rank-form" class="btn-ghost px-2" aria-label="<?= e(__('Previous year')) ?>"><?= icon('arrowleft', 'size-4') ?></a>
                <span class="px-2 text-sm font-semibold tabular-nums"><?= (int) $rk['year'] ?></span>
                <a href="<?= e(url('/management/everbetter', ['tab' => 'ranking', 'year' => $rk['year'] + 1])) ?>#rank-form" class="btn-ghost px-2" aria-label="<?= e(__('Next year')) ?>"><?= icon('right', 'size-4') ?></a>
            </div>
        </div>
        <p class="mt-1 text-xs text-steel"><?= e(__('Choose from the projects that presented on one of :y\'s days. The teams are told.', ['y' => $rk['year']])) ?></p>
        <?php if (! $rk['candidates']): ?><p class="mt-4 rounded-lg bg-mist px-3 py-3 text-sm text-steel"><?= e(__('No project presented in :y.', ['y' => $rk['year']])) ?></p><?php else: $opts = array_column(array_map(fn ($c) => ['id' => $c['id'], 'l' => $c['title'].' · '.($c['department_name'] ?? '—')], $rk['candidates']), 'l', 'id'); $cur = $rk['winners'][$rk['year']] ?? []; ?>
        <form method="POST" action="<?= url('/management/everbetter/ranking') ?>" class="mt-4 space-y-3">
            <?= csrf_field() ?><input type="hidden" name="year" value="<?= (int) $rk['year'] ?>">
            <?php foreach ([1, 2, 3] as $r): [$icon, , $label] = $medal[$r]; ?>
            <div><label class="label"><span class="text-base"><?= $icon ?></span> <?= e($label) ?></label>
                <?= select_field('rank_'.$r, $opts, isset($cur[$r]) ? (string) $cur[$r]['id'] : '', ['placeholder' => __('Nobody'), 'search' => true, 'aria' => $label]) ?><?= field_error('rank_'.$r) ?></div>
            <?php endforeach ?>
            <div class="flex justify-end"><button class="btn-primary"><?= e(__('Save ranking')) ?></button></div>
        </form>
        <?php endif ?>
    </section>
</div>
<?php endif ?>

<?= partial('management::everbetter-decide') ?>
