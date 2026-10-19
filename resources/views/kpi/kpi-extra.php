<?php /* $row (kpis row), $subs (its submissions, newest first), $history (activity_logs of the KPI and its submissions), $fields */
use App\Core\Http\Controllers\KpiController;

$pct  = (float) $row['target'] > 0 ? (int) round((float) $row['actual'] / (float) $row['target'] * 100) : 0;
$tone = fn (float $p) => $p >= 100 ? 'text-emerald-700' : ($p >= 70 ? 'text-amber-700' : 'text-signal-700');
$bar  = fn (float $p) => $p >= 100 ? 'bg-emerald-600' : ($p >= 70 ? 'bg-amber-500' : 'bg-signal-600');
$statusTone = KpiController::STATUS_TONES;
$approved = array_filter($subs, fn ($s) => $s['approved_at']);
$avg  = $subs ? array_sum(array_column($subs, 'percentage')) / count($subs) : null;
// Labels for the fields named in the history
$labels = array_map(fn ($f) => $f['label'] ?? '', $fields) + [
    'approver' => __('Approver'), 'finished' => __('Finished'), 'total' => __('Total tasks'), 'criteria' => __('Criteria'),
    'note' => __('Note'), 'related_link' => __('Related link'), 'percentage' => __('Percentage'), 'days_late' => __('Days late'),
];
$actionTone = ['created' => 'bg-emerald-50 text-emerald-800', 'updated' => 'bg-sky-50 text-sky-800', 'deleted' => 'bg-signal-50 text-signal-800', 'approved' => 'bg-violet-50 text-violet-800'];
?>
<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="panel p-4"><p class="text-xs font-medium uppercase tracking-wide text-steel"><?= e(__('Status')) ?></p>
        <p class="mt-2"><span class="inline-flex rounded-md px-3 py-1 font-display text-xl font-semibold <?= $statusTone[$row['status']] ?? 'bg-graphite-900 text-white' ?>"><?= e(KpiController::statuses()[$row['status']] ?? $row['status']) ?></span></p>
        <p class="mt-2 text-xs text-steel"><?= e(format_date($row['period_start'], 'd M').' – '.format_date($row['period_end'], 'd M Y')) ?></p></div>
    <div class="panel p-4"><p class="text-xs font-medium uppercase tracking-wide text-steel"><?= e(__('Target reached')) ?></p>
        <p class="mt-1 font-display text-4xl font-semibold tabular-nums <?= $tone($pct) ?>"><?= $pct ?>%</p>
        <div class="mt-2 h-2 overflow-hidden rounded-full bg-graphite-900/8"><div class="h-full rounded-full <?= $bar($pct) ?>" style="width: <?= min(100, max(0, $pct)) ?>%"></div></div>
        <p class="mt-1.5 text-xs text-steel"><?= e(number_clean($row['actual']).' / '.number_clean($row['target']).' '.$row['unit']) ?></p></div>
    <div class="panel p-4"><p class="text-xs font-medium uppercase tracking-wide text-steel"><?= e(__('Criteria')) ?></p>
        <p class="mt-1 font-display text-4xl font-semibold tabular-nums"><?= $row['criteria'] !== null ? e(number_clean($row['criteria'])) : '—' ?></p>
        <p class="mt-1.5 text-xs text-steel"><span class="badge <?= $row['kind'] === 'okr' ? 'bg-violet-50 text-violet-800' : 'bg-sky-50 text-sky-800' ?>"><?= e(KpiController::KINDS[$row['kind']] ?? 'KPI') ?></span></p></div>
    <div class="panel p-4"><p class="text-xs font-medium uppercase tracking-wide text-steel"><?= e(__('Monthly submissions')) ?></p>
        <p class="mt-1 font-display text-4xl font-semibold tabular-nums <?= $avg === null ? 'text-steel' : $tone($avg) ?>"><?= $avg === null ? '—' : e(number_format($avg, 0)).'%' ?></p>
        <p class="mt-1.5 text-xs text-steel"><?= e(__(':a of :n approved', ['a' => count($approved), 'n' => count($subs)])) ?> · <?= e(__('average on-time score')) ?></p></div>
</div>

<section class="panel mt-4 overflow-hidden">
    <div class="panel-head"><h2 class="panel-title"><?= e(__('Monthly submissions')) ?></h2>
        <a href="<?= url('/kpi-submissions') ?>" class="text-sm text-steel hover:text-graphite-900"><?= e(__('All submissions')) ?></a></div>
    <?php if (! $subs): ?>
        <p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('Nothing submitted for this KPI yet.')) ?></p>
    <?php else: ?>
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead class="whitespace-nowrap bg-mist/60 text-left text-xs text-steel"><tr>
            <th class="px-4 py-2 font-medium"><?= e(__('Month')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Status')) ?></th>
            <th class="px-3 py-2 text-right font-medium"><?= e(__('Finished')) ?></th><th class="px-3 py-2 text-right font-medium"><?= e(__('Total tasks')) ?></th>
            <th class="px-3 py-2 text-right font-medium"><?= e(__('Percentage')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Approver')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Approved')) ?></th></tr></thead>
        <tbody class="divide-y divide-graphite-900/6">
        <?php foreach ($subs as $s): ?>
            <tr>
                <td class="whitespace-nowrap px-4 py-2.5 font-medium"><?= e(format_date(sprintf('%04d-%02d-01', $s['period_year'], $s['period_month']), 'F Y')) ?></td>
                <td class="px-3 py-2.5"><?= $s['days_late'] ? '<span class="badge '.($s['percentage'] ? 'bg-amber-50 text-amber-800' : 'bg-signal-50 text-signal-800').'">'.e(__('Late :n days', ['n' => $s['days_late']])).'</span>' : '<span class="badge bg-emerald-50 text-emerald-800">'.e(__('On time')).'</span>' ?></td>
                <td class="px-3 py-2.5 text-right tabular-nums"><?= e(number_format((float) $s['finished'], 2)) ?></td>
                <td class="px-3 py-2.5 text-right tabular-nums"><?= e(number_format((float) $s['total'], 2)) ?></td>
                <td class="px-3 py-2.5 text-right"><span class="inline-flex min-w-14 justify-center rounded-md px-2 py-0.5 font-semibold tabular-nums <?= (int) $s['percentage'] >= 100 ? 'bg-emerald-50 text-emerald-800' : ((int) $s['percentage'] >= 60 ? 'bg-amber-50 text-amber-800' : 'bg-signal-50 text-signal-800') ?>"><?= (int) $s['percentage'] ?>%</span></td>
                <td class="whitespace-nowrap px-3 py-2.5"><?= e($s['approver_name'] ?? '—') ?></td>
                <td class="whitespace-nowrap px-3 py-2.5"><?= $s['approved_at'] ? '<span class="text-emerald-700">'.icon('check', 'mr-1 inline size-4').e(format_date($s['approved_at'], 'j M Y')).'</span>' : '<span class="text-steel">'.e(__('Waiting')).'</span>' ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
</section>

<section class="panel mt-4">
    <div class="panel-head"><h2 class="panel-title"><?= e(__('Change history')) ?></h2><span class="text-xs text-steel"><?= e(__('Who changed what, and when')) ?></span></div>
    <?php if (! $history): ?>
        <p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('No changes yet.')) ?></p>
    <?php else: ?>
    <ol class="divide-y divide-graphite-900/6">
        <?php foreach ($history as $log):
            $props = json_decode((string) $log['properties'], true) ?: [];
            $changes = $props['changes'] ?? []; ?>
            <li class="flex gap-3 px-5 py-3">
                <?= partial('partials/avatar', ['name' => $log['user_name'] ?? __('System'), 'avatar' => $log['avatar'] ?? null, 'size' => 'size-8', 'extra' => 'mt-0.5']) ?>
                <div class="min-w-0 flex-1">
                    <p class="text-sm"><span class="font-medium"><?= e($log['user_name'] ?? __('System')) ?></span>
                        <span class="badge ml-1 <?= $actionTone[$log['action']] ?? 'bg-graphite-900/6 text-graphite-800' ?>"><?= e(__(ucfirst($log['action']))) ?></span>
                        <span class="text-graphite-700"><?= e(activity_text($log)) ?></span></p>
                    <?php if ($changes): ?>
                        <ul class="mt-1.5 space-y-1 text-xs">
                            <?php foreach ($changes as $field => $c): ?>
                                <li class="flex flex-wrap items-baseline gap-1.5"><span class="font-medium text-graphite-700"><?= e($labels[$field] ?? ucfirst(str_replace('_', ' ', (string) $field))) ?>:</span>
                                    <span class="rounded bg-signal-50 px-1.5 text-signal-800 line-through decoration-signal-400"><?= e(str_limit((string) ($c['from'] ?? '') ?: '—', 80)) ?></span>
                                    <span class="text-steel">→</span>
                                    <span class="rounded bg-emerald-50 px-1.5 text-emerald-800"><?= e(str_limit((string) ($c['to'] ?? '') ?: '—', 80)) ?></span></li>
                            <?php endforeach ?>
                        </ul>
                    <?php endif ?>
                    <p class="mt-1 text-xs text-steel"><time datetime="<?= e(date('c', strtotime($log['created_at']))) ?>"><?= e(format_date($log['created_at'], 'j M Y H:i')) ?></time> · <?= e(time_ago($log['created_at'])) ?></p>
                </div>
            </li>
        <?php endforeach ?>
    </ol>
    <?php endif ?>
</section>
