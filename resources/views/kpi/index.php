<?php /* $f {year,view,q}, $groups [user_id => {name, department, months: [month => rows]}], $years, $pending, $me CurrentUser */
use App\Core\Support\KpiSubmissions as K;

$link = fn (array $over = []) => url('/kpi-submissions', array_filter(array_replace(['year' => $f['year'], 'view' => $f['view'] === 'all' ? null : $f['view'], 'q' => $f['q'] ?: null], $over), fn ($v) => $v !== null && $v !== ''));
$tabs = ['all' => __('All'), 'mine' => __('Mine'), 'approve' => __('To approve')];
$num  = fn ($n) => $n === null ? '—' : number_format((float) $n, 2);
$today = date('Y-m-d');
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0"><h1 class="page-title"><?= e(__('KPI submissions')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(__('Monthly KPI and OKR results. Each month is due on day :d of the next month; approvers approve by day :a.', ['d' => K::DUE_DAY, 'a' => K::APPROVE_DAY])) ?></p></div>
    <div class="flex flex-wrap gap-2">
        <?php if (can('kpi_submissions', 'export')): ?><a href="<?= e(url('/kpi-submissions/export', ['year' => $f['year'], 'view' => $f['view'], 'q' => $f['q']])) ?>" class="btn-secondary"><?= icon('download', 'size-4') ?><?= e(__('Export')) ?></a><?php endif ?>
        <?php if (can('kpi_submissions', 'create')): ?><a href="<?= url('/kpi-submissions/create') ?>" class="btn-primary"><?= icon('plus', 'size-4') ?><?= e(__('Submit KPIs')) ?></a><?php endif ?>
    </div>
</div>

<div class="mt-4 flex flex-wrap items-center gap-2">
    <nav class="flex rounded-md bg-graphite-900/6 p-0.5 text-sm" aria-label="<?= e(__('Show')) ?>">
        <?php foreach ($tabs as $k => $label): ?>
            <a href="<?= e($link(['view' => $k === 'all' ? null : $k])) ?>" class="flex items-center gap-1.5 rounded px-3 py-1.5 <?= $f['view'] === $k ? 'bg-white font-medium shadow-sm' : 'text-steel hover:text-graphite-900' ?>" <?= $f['view'] === $k ? 'aria-current="page"' : '' ?>>
                <?= e($label) ?><?php if ($k === 'approve' && $pending): ?><span class="rounded-full bg-signal-600 px-1.5 text-[11px] font-semibold leading-4 text-white"><?= (int) $pending ?></span><?php endif ?></a>
        <?php endforeach ?>
    </nav>
    <form method="GET" action="<?= url('/kpi-submissions') ?>" class="ml-auto flex flex-wrap items-center gap-2">
        <?php if ($f['view'] !== 'all'): ?><input type="hidden" name="view" value="<?= e($f['view']) ?>"><?php endif ?>
        <div class="w-28"><?= select_field('year', array_combine($years, $years), $f['year'], ['id' => 'k-y', 'submit' => true, 'search' => false, 'aria' => __('Year')]) ?></div>
        <div class="relative"><span class="pointer-events-none absolute inset-y-0 left-2.5 flex items-center text-graphite-400"><?= icon('search', 'size-4') ?></span>
            <input name="q" value="<?= e($f['q']) ?>" class="input w-56 pl-8" placeholder="<?= e(__('KPI, person, note…')) ?>" aria-label="<?= e(__('Search')) ?>"></div>
    </form>
</div>

<?php if (! $groups): ?>
    <section class="panel mt-4 px-5 py-12 text-center text-sm text-steel">
        <?= e($f['view'] === 'approve' ? __('Nothing waiting for your approval in :y.', ['y' => $f['year']]) : __('No submissions in :y yet.', ['y' => $f['year']])) ?>
        <?php if (can('kpi_submissions', 'create') && $f['view'] !== 'approve'): ?><div class="mt-3"><a href="<?= url('/kpi-submissions/create') ?>" class="btn-primary"><?= e(__('Submit KPIs')) ?></a></div><?php endif ?>
    </section>
<?php endif ?>

<?php foreach ($groups as $uid => $g):
    $all = array_merge(...array_values($g['months']));
    $gApproved = count(array_filter($all, fn ($r) => $r['approved_at']));
?>
<details class="panel group/p mt-4 overflow-hidden" id="u<?= (int) $uid ?>" open data-kpi-group>
    <summary class="flex cursor-pointer list-none flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 hover:bg-mist/50">
        <?= icon('right', 'size-4 text-steel transition group-open/p:rotate-90') ?>
        <span class="inline-flex size-8 items-center justify-center rounded-full bg-graphite-900 text-xs font-semibold text-white"><?= e(initials($g['name'])) ?></span>
        <span><span class="block font-semibold leading-tight"><?= e($g['name']) ?></span><span class="block text-xs text-steel"><?= e($g['department'] ?? '—') ?></span></span>
        <span class="text-sm text-steel"><?= e(count($all) === 1 ? __('1 submission') : __(':n submissions', ['n' => count($all)])) ?></span>
        <span class="badge <?= $gApproved === count($all) ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800' ?>"><?= e(__(':a of :n approved', ['a' => $gApproved, 'n' => count($all)])) ?></span>
        <span class="text-sm text-steel"><?= e(__('Average')) ?> <strong class="tabular-nums text-graphite-900"><?= e(number_format(array_sum(array_column($all, 'percentage')) / count($all), 0)) ?>%</strong></span>
    </summary>
<?php foreach ($g['months'] as $m => $rows):
    $approved = count(array_filter($rows, fn ($r) => $r['approved_at']));
    $avg      = array_sum(array_column($rows, 'percentage')) / count($rows);
    $mineToApprove = count(array_filter($rows, fn ($r) => ! $r['approved_at'] && (int) $r['approver_id'] === $me->id));
    $approveBy = K::approveBy($f['year'], $m)->format('Y-m-d');
?>
<details class="group/m border-t border-graphite-900/8" id="u<?= (int) $uid ?>-m<?= (int) $m ?>" open data-kpi-group>
    <summary class="flex cursor-pointer list-none flex-wrap items-center gap-x-4 gap-y-2 bg-mist/40 py-2.5 pl-10 pr-4 hover:bg-mist/70">
        <?= icon('right', 'size-4 text-steel transition group-open/m:rotate-90') ?>
        <span class="font-display text-lg font-semibold"><?= e(format_date(sprintf('%04d-%02d-01', $f['year'], $m), 'F Y')) ?></span>
        <span class="text-sm text-steel"><?= e(count($rows) === 1 ? __('1 submission') : __(':n submissions', ['n' => count($rows)])) ?></span>
        <span class="badge <?= $approved === count($rows) ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800' ?>"><?= e(__(':a of :n approved', ['a' => $approved, 'n' => count($rows)])) ?></span>
        <span class="text-sm text-steel"><?= e(__('Average')) ?> <strong class="tabular-nums text-graphite-900"><?= e(number_format($avg, 0)) ?>%</strong></span>
        <?php if ($mineToApprove): ?>
            <form method="POST" action="<?= url('/kpi-submissions/approve-month') ?>" class="ml-auto" data-confirm="<?= e(__('Approve all :n submissions of :name waiting for you in this month?', ['n' => $mineToApprove, 'name' => $g['name']])) ?>">
                <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $uid ?>"><input type="hidden" name="year" value="<?= (int) $f['year'] ?>"><input type="hidden" name="month" value="<?= (int) $m ?>">
                <button class="btn-dark px-3 py-1 text-xs"><?= icon('check', 'size-3.5') ?><?= e(__('Approve all (:n)', ['n' => $mineToApprove])) ?></button></form>
        <?php endif ?>
    </summary>
    <div class="overflow-x-auto border-t border-graphite-900/8"><table class="w-full min-w-[84rem] text-sm">
        <thead class="whitespace-nowrap bg-mist/60 text-left text-xs text-steel"><tr>
            <th class="w-10 px-3 py-2 font-medium">#</th>
            <th class="px-3 py-2 font-medium"><?= e(__('Status')) ?></th>
            <th class="px-3 py-2 font-medium"><?= e(__('Submitted by')) ?></th>
            <th class="px-3 py-2 font-medium"><?= e(__('KPIs & OKRs')) ?></th>
            <th class="px-3 py-2 font-medium"><?= e(__('Approver')) ?></th>
            <th class="px-3 py-2 font-medium"><?= e(__('Approved')) ?></th>
            <th class="px-3 py-2 text-right font-medium"><?= e(__('Percentage')) ?></th>
            <th class="px-3 py-2 text-right font-medium"><?= e(__('Finished')) ?></th>
            <th class="px-3 py-2 text-right font-medium"><?= e(__('Total tasks')) ?></th>
            <th class="px-3 py-2 text-right font-medium"><?= e(__('Criteria')) ?></th>
            <th class="px-3 py-2 font-medium"><?= e(__('Note')) ?></th>
            <th class="px-3 py-2 font-medium"><?= e(__('Files & link')) ?></th>
            <th class="px-3 py-2"><span class="sr-only"><?= e(__('Actions')) ?></span></th>
        </tr></thead>
        <tbody class="divide-y divide-graphite-900/6">
        <?php foreach ($rows as $i => $r):
            $lateApproval = $r['approved_at'] ? substr((string) $r['approved_at'], 0, 10) > $approveBy : $today > $approveBy; ?>
            <tr class="align-top hover:bg-mist/40">
                <td class="px-3 py-2.5 tabular-nums text-steel"><?= $i + 1 ?></td>
                <td class="whitespace-nowrap px-3 py-2.5">
                    <?php if (! $r['days_late']): ?><span class="badge bg-emerald-50 text-emerald-800"><?= icon('check', 'size-3.5') ?><?= e(__('On time')) ?></span>
                    <?php else: ?><span class="badge <?= $r['percentage'] ? 'bg-amber-50 text-amber-800' : 'bg-signal-50 text-signal-800' ?>"><?= e(__('Late :n days', ['n' => $r['days_late']])) ?></span><?php endif ?>
                    <span class="mt-0.5 block text-[11px] text-steel" title="<?= e(__('Submitted')) ?>"><?= e(format_date($r['submitted_at'], 'j M Y H:i')) ?></span></td>
                <td class="px-3 py-2.5"><div class="flex items-center gap-2">
                    <span class="inline-flex size-7 shrink-0 items-center justify-center rounded-full bg-graphite-900/8 text-[11px] font-semibold text-graphite-700"><?= e(initials($r['submitter_name'])) ?></span>
                    <span class="min-w-0"><span class="block truncate font-medium"><?= e($r['submitter_name']) ?></span><span class="block truncate text-xs text-steel"><?= e($r['department_name'] ?? '—') ?></span></span></div></td>
                <td class="min-w-[18rem] max-w-[26rem] px-3 py-2.5"><span class="badge mr-1 <?= $r['task_kind'] === 'okr' ? 'bg-violet-50 text-violet-800' : 'bg-sky-50 text-sky-800' ?>"><?= e(strtoupper($r['task_kind'])) ?></span><?php if ($r['kpi_id'] && can('kpis')): ?><a href="<?= url('/kpis/'.(int) $r['kpi_id']) ?>" class="font-medium hover:text-signal-700"><?= e($r['task_title']) ?></a><?php else: ?><span class="font-medium"><?= e($r['task_title']) ?></span><?php endif ?></td>
                <td class="whitespace-nowrap px-3 py-2.5"><?= e($r['approver_name'] ?? '—') ?></td>
                <td class="whitespace-nowrap px-3 py-2.5">
                    <?php if ($r['approved_at']): ?>
                        <span class="inline-flex items-center gap-1.5 text-emerald-700"><span class="flex size-5 items-center justify-center rounded bg-emerald-600 text-white"><?= icon('check', 'size-3.5') ?></span><span class="tabular-nums text-xs text-steel"><?= e(format_date($r['approved_at'], 'j M H:i')) ?></span></span>
                    <?php elseif (K::canApprove($me, $r)): ?>
                        <form method="POST" action="<?= url('/kpi-submissions/'.(int) $r['id'].'/approve') ?>"><?= csrf_field() ?><button class="btn-secondary px-2.5 py-1 text-xs"><?= icon('check', 'size-3.5') ?><?= e(__('Approve')) ?></button></form>
                    <?php else: ?><span class="text-xs text-steel"><?= e(__('Waiting')) ?></span><?php endif ?>
                    <?php if ($lateApproval): ?><span class="mt-0.5 block text-[11px] text-amber-700"><?= e(__('After day :d', ['d' => K::APPROVE_DAY])) ?></span><?php endif ?></td>
                <td class="px-3 py-2.5 text-right font-medium tabular-nums <?= $r['percentage'] < 100 ? 'text-signal-700' : '' ?>"><?= (int) $r['percentage'] ?>%</td>
                <td class="px-3 py-2.5 text-right tabular-nums"><?= e($num($r['finished'])) ?></td>
                <td class="px-3 py-2.5 text-right tabular-nums"><?= e($num($r['total'])) ?></td>
                <td class="px-3 py-2.5 text-right tabular-nums"><?= e($num($r['criteria'])) ?></td>
                <td class="min-w-[10rem] max-w-[16rem] px-3 py-2.5 text-steel"><?php if ($r['note']): ?><span class="line-clamp-2" title="<?= e($r['note']) ?>"><?= e($r['note']) ?></span><?php else: ?>—<?php endif ?></td>
                <td class="px-3 py-2.5"><div class="flex max-w-[14rem] flex-col gap-1">
                    <?php foreach ($r['files'] as $file): ?>
                        <a href="<?= url('/kpi-submissions/'.(int) $r['id'].'/files/'.(int) $file['id']) ?>" class="inline-flex min-w-0 items-center gap-1 text-xs hover:text-signal-700"><?= icon('doc', 'size-3.5 shrink-0 text-graphite-400') ?><span class="truncate"><?= e($file['name']) ?></span></a>
                    <?php endforeach ?>
                    <?php if ($r['related_link']): ?><a href="<?= e($r['related_link']) ?>" target="_blank" rel="noopener noreferrer" class="inline-flex min-w-0 items-center gap-1 text-xs text-sky-700 hover:underline"><?= icon('link', 'size-3.5 shrink-0') ?><span class="truncate"><?= e(parse_url($r['related_link'], PHP_URL_HOST) ?: $r['related_link']) ?></span></a><?php endif ?>
                    <?php if (! $r['files'] && ! $r['related_link']): ?><span class="text-steel">—</span><?php endif ?></div></td>
                <td class="whitespace-nowrap px-3 py-2.5 text-right">
                    <?php if (K::canEdit($me, $r)): ?><a href="<?= url('/kpi-submissions/'.(int) $r['id'].'/edit') ?>" class="btn-ghost !px-2" title="<?= e(__('Correct')) ?>" aria-label="<?= e(__('Correct')) ?>"><?= icon('pencil', 'size-4') ?></a><?php endif ?>
                    <?php if (K::canDelete($me, $r)): ?>
                        <form class="inline" method="POST" action="<?= url('/kpi-submissions/'.(int) $r['id'].'/delete') ?>" data-confirm="<?= e(__('Delete this submission? :name can then submit this KPI again for the month.', ['name' => $r['submitter_name']])) ?>">
                            <?= csrf_field() ?><button class="btn-ghost !px-2 text-signal-700" title="<?= e(__('Delete')) ?>" aria-label="<?= e(__('Delete')) ?>"><?= icon('trash', 'size-4') ?></button></form>
                    <?php endif ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
</details>
<?php endforeach ?>
</details>
<?php endforeach ?>
<script src="<?= asset('assets/kpi.js') ?>" defer></script>
