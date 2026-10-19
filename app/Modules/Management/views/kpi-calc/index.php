<?php /* $year, $q, $rows (people with saved score), $years */
use App\Modules\Management\Support\KpiScore as S;

$tone = fn (float $s) => $s >= 80 ? 'bg-emerald-50 text-emerald-800' : ($s >= 60 ? 'bg-amber-50 text-amber-800' : ($s > 0 ? 'bg-signal-50 text-signal-800' : 'bg-graphite-900/6 text-graphite-500'));
$done = count(array_filter($rows, fn ($r) => $r['score'] !== null));
$avg = $done ? array_sum(array_map(fn ($r) => (float) $r['score'], array_filter($rows, fn ($r) => $r['score'] !== null))) / $done : 0;
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0"><h1 class="page-title"><?= e(__('KPI calculator')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(__('Yearly appraisal score out of 100 for each person. Open a person to calculate it from their KPIs, submissions and the other sections.')) ?></p></div>
    <?php if (can('kpi_calculator', 'export')): ?><a href="<?= e(url('/management/kpi-calculator/export', ['year' => $year])) ?>" class="btn-secondary"><?= icon('download', 'size-4') ?><?= e(__('Export')) ?></a><?php endif ?>
</div>

<div class="mt-4 grid gap-3 sm:grid-cols-3">
    <div class="panel p-4"><p class="text-xs font-medium uppercase tracking-wide text-steel"><?= e(__('People')) ?></p><p class="mt-1 font-display text-3xl font-semibold tabular-nums"><?= count($rows) ?></p></div>
    <div class="panel p-4"><p class="text-xs font-medium uppercase tracking-wide text-steel"><?= e(__('Calculated')) ?></p><p class="mt-1 font-display text-3xl font-semibold tabular-nums"><?= $done ?> <span class="text-base text-steel">/ <?= count($rows) ?></span></p></div>
    <div class="panel p-4"><p class="text-xs font-medium uppercase tracking-wide text-steel"><?= e(__('Average score')) ?></p><p class="mt-1 font-display text-3xl font-semibold tabular-nums"><?= e(number_format($avg, 2)) ?></p></div>
</div>

<section class="panel mt-4 overflow-hidden">
    <form method="GET" action="<?= url('/management/kpi-calculator') ?>" class="flex flex-wrap items-center gap-2 border-b border-graphite-900/8 p-3">
        <div class="relative"><span class="pointer-events-none absolute inset-y-0 left-2.5 flex items-center text-graphite-400"><?= icon('search', 'size-4') ?></span>
            <input name="q" value="<?= e($q) ?>" class="input w-64 pl-8" placeholder="<?= e(__('Name or department…')) ?>" aria-label="<?= e(__('Search')) ?>"></div>
        <div class="w-28"><?= select_field('year', array_combine($years, $years), $year, ['id' => 'kc-year', 'submit' => true, 'search' => false, 'aria' => __('Year')]) ?></div>
        <span class="ml-auto text-sm text-steel"><?= e(__(':n people', ['n' => count($rows)])) ?></span>
    </form>
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead class="whitespace-nowrap bg-mist/60 text-left text-xs text-steel"><tr>
            <th class="px-4 py-2 font-medium"><?= e(__('Name')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Department')) ?></th>
            <th class="px-3 py-2 font-medium"><?= e(__('Year')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Month')) ?></th>
            <th class="px-3 py-2 font-medium"><?= e(__('Group')) ?></th><th class="px-3 py-2 text-right font-medium"><?= e(__('KPI score')) ?></th>
            <th class="px-3 py-2 font-medium"><?= e(__('Calculated')) ?></th><th class="px-3 py-2"><span class="sr-only"><?= e(__('Actions')) ?></span></th></tr></thead>
        <tbody class="divide-y divide-graphite-900/6">
        <?php foreach ($rows as $r): $score = (float) ($r['score'] ?? 0); $link = url('/management/kpi-calculator/'.(int) $r['id'], ['year' => $year]); ?>
            <tr class="hover:bg-mist/40">
                <td class="px-4 py-2.5"><a href="<?= e($link) ?>" class="flex items-center gap-2.5 font-medium hover:text-signal-700">
                    <?= partial('partials/avatar', ['name' => $r['name'], 'avatar' => $r['avatar'], 'size' => 'size-8']) ?>
                    <span><?= e($r['name']) ?><span class="block text-xs font-normal text-steel"><?= e($r['role_name']) ?></span></span></a></td>
                <td class="px-3 py-2.5 text-steel"><?= e($r['department_name'] ?? '—') ?></td>
                <td class="px-3 py-2.5 tabular-nums"><?= (int) $year ?></td>
                <td class="px-3 py-2.5"><?= $r['month'] ? e(format_date(sprintf('%04d-%02d-01', $year, $r['month']), 'F')) : '<span class="text-steel">—</span>' ?></td>
                <td class="px-3 py-2.5 text-steel"><?= e($r['emp_group'] ? __(S::GROUPS[$r['emp_group']] ?? $r['emp_group']) : '—') ?></td>
                <td class="px-3 py-2.5 text-right"><span class="inline-flex min-w-16 justify-center rounded-md px-2 py-0.5 font-display text-lg font-semibold tabular-nums <?= $tone($score) ?>"><?= e(number_format($score, 2)) ?></span></td>
                <td class="whitespace-nowrap px-3 py-2.5 text-xs text-steel"><?= $r['calculated_at'] ? e(format_date($r['calculated_at'], 'j M Y').' · '.($r['calculated_by_name'] ?? '')) : e(__('Not calculated')) ?></td>
                <td class="px-3 py-2.5 text-right"><a href="<?= e($link) ?>" class="btn-secondary px-3 py-1 text-xs"><?= icon('calc', 'size-3.5') ?><?= e(__('Calculate')) ?></a></td>
            </tr>
        <?php endforeach ?>
        <?php if (! $rows): ?><tr><td colspan="8" class="px-4 py-10 text-center text-steel"><?= e(__('No people to show.')) ?></td></tr><?php endif ?>
        </tbody>
    </table></div>
</section>
