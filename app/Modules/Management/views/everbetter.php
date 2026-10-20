<?php
/* $session (this quarter's day), $y, $q, $thisQuarter, $requests, $status, $projects, $year, $sessions [quarter => row], $allSessions [id => label], $counts {waiting, accepted} */
use App\Core\Support\Everbetter;

$kinds = Everbetter::kinds();
$kindTone = ['initiation' => 'bg-sky-50 text-sky-800', 'closure' => 'bg-emerald-50 text-emerald-800', 'extension' => 'bg-violet-50 text-violet-800'];
$tabs = ['open' => __('Waiting'), 'submitted' => __('Not accepted yet'), 'decided' => __('Decided'), 'all' => __('All')];
$link = fn (array $x) => url('/management/everbetter', array_filter(array_merge(['status' => $status, 'year' => $year], $x), fn ($v) => $v !== null && $v !== ''));
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

<section class="panel mt-6 overflow-hidden" aria-labelledby="eb-quarter">
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
                <a href="<?= url('/everbetter/'.(int) $x['project_id']) ?>" class="block truncate font-medium hover:text-signal-700"><?= e($x['title']) ?></a>
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
            <form method="GET" action="<?= url('/management/everbetter') ?>" class="w-56"><input type="hidden" name="status" value="<?= e($status) ?>">
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
                <td class="max-w-[22rem] px-5 py-3"><a href="<?= url('/everbetter/'.(int) $x['project_id']) ?>" class="font-medium hover:text-signal-700"><?= e($x['title']) ?></a>
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

<div class="mt-4 grid items-start gap-4 xl:grid-cols-5">
    <section class="panel overflow-hidden xl:col-span-3">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('All projects')) ?> <span class="ml-1 text-sm font-normal text-steel"><?= count($projects) ?></span></h2></div>
        <?php if (! $projects): ?><p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('No projects yet.')) ?></p><?php endif ?>
        <ul class="divide-y divide-graphite-900/6">
            <?php foreach ($projects as $p): ?>
            <li class="flex flex-wrap items-center gap-3 px-5 py-3">
                <div class="min-w-0 flex-1"><a href="<?= url('/everbetter/'.(int) $p['id']) ?>" class="block truncate font-medium hover:text-signal-700"><?= e($p['title']) ?></a>
                    <p class="truncate text-xs text-steel"><?= e($p['owner_name'].' · '.($p['department_name'] ?? '—').' · '.format_date($p['start_date'], 'd M y').' – '.format_date($p['end_date'], 'd M y').' · '.__(':n members', ['n' => count($p['members'])])) ?></p></div>
                <span class="badge <?= (int) $p['presented'] ? 'bg-emerald-50 text-emerald-800' : 'bg-graphite-900/6 text-steel' ?>"><?= (int) $p['presented'] ? e(__('Presented')) : e(__('Not presented yet')) ?></span>
                <?= partial('everbetter/status', ['p' => $p]) ?>
            </li>
            <?php endforeach ?>
        </ul>
    </section>

    <section id="days" class="panel p-5 xl:col-span-2">
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

<dialog id="eb-decide" data-light-dismiss class="m-auto w-[min(30rem,calc(100vw-2rem))] rounded-xl bg-white p-0 text-graphite-900 shadow-2xl ring-1 ring-graphite-900/10 backdrop:bg-graphite-950/60" aria-labelledby="eb-decide-title">
    <form method="POST" action="" data-eb-decide-form>
        <?= csrf_field() ?>
        <div class="border-b border-graphite-900/8 px-5 py-3.5"><h2 id="eb-decide-title" class="text-base font-semibold"><?= e(__('Result of the presentation')) ?></h2>
            <p class="mt-0.5 text-sm text-steel"><span data-eb-d-title></span> · <span data-eb-d-kind></span></p></div>
        <div class="space-y-4 px-5 py-4">
            <div class="grid grid-cols-2 gap-2">
                <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-graphite-900/15 p-3 has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50"><input type="radio" name="result" value="approved" checked class="size-4 accent-emerald-600"> <?= e(__('Approved')) ?></label>
                <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-graphite-900/15 p-3 has-[:checked]:border-signal-600 has-[:checked]:bg-signal-50"><input type="radio" name="result" value="rejected" class="size-4 accent-signal-600"> <?= e(__('Not approved')) ?></label>
            </div>
            <div><label class="label" for="eb-d-note"><?= e(__('Comment for the team')) ?> <span class="font-normal text-steel">(<?= e(__('optional')) ?>)</span></label><textarea id="eb-d-note" name="note" rows="3" class="input"></textarea></div>
            <p class="text-xs text-steel"><?= e(__('Approved initiation approves the project; approved closure closes it; approved extension moves its end date. The team is told.')) ?></p>
        </div>
        <div class="flex justify-end gap-2 rounded-b-xl bg-mist/60 px-5 py-3"><button type="button" class="btn-secondary" data-close-dialog><?= e(__('Cancel')) ?></button><button class="btn-primary"><?= e(__('Save result')) ?></button></div>
    </form>
</dialog>
<script>
document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-eb-decide]'); if (!b) return;
    var f = document.querySelector('[data-eb-decide-form]');
    f.action = <?= json_encode(url('/management/everbetter/requests')) ?> + '/' + b.dataset.ebDecide + '/decide';
    f.querySelector('[data-eb-d-title]').textContent = b.dataset.ebTitle;
    f.querySelector('[data-eb-d-kind]').textContent = b.dataset.ebKind;
    f.reset();
});
</script>
