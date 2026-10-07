<?php
use App\Modules\Machines\Support\Mx;
/* $r record, $answers, $c, $machine, $canApprove, $canDelete, $chain, $by */
$crumbs = [[__('Checklists'), $c['base']]];
$ng = count(array_filter($answers, fn ($a) => ($a['answer'] ?? '') === 'NG'));
$tone = ['done' => 'bg-emerald-600 text-white', 'now' => 'bg-amber-500 text-white', 'late' => 'bg-red-600 text-white', 'waiting' => 'bg-graphite-900/10 text-steel'];
?>
<div class="mx-auto max-w-3xl">
<div class="flex flex-wrap items-start justify-between gap-3">
    <div class="min-w-0">
        <p class="text-sm text-steel"><?= e(__('Checklist')) ?> #<?= (int) $r['id'] ?> · <?= e(format_date($r['created_at'], 'd M Y H:i')) ?></p>
        <h1 class="page-title break-words"><a class="hover:underline" href="<?= $machine ? url('/machines/m/'.rawurlencode($r['machine_code'])) : '#' ?>"><?= e($r['machine_code']) ?></a> <span class="font-normal text-steel">· <?= e($r['machine_name']) ?></span></h1>
        <p class="mt-1 flex flex-wrap items-center gap-2">
            <?= $r['check_type'] === 'MAINTENANCE' ? Mx::pill(__('Maintenance'), 'info') : ($r['recheck'] ? Mx::pill(__('Re-check'), 'warn') : Mx::pill(__('General check'), 'neutral')) ?>
            <?= Mx::pill($r['checklist_status']) ?> <?= Mx::pill($r['machine_status']) ?>
            <?php if ($ng): ?><?= Mx::pill(__(':n NG', ['n' => $ng]), 'bad') ?><?php endif ?></p>
    </div>
    <?php if ($canDelete): ?><button type="button" class="btn-danger" data-delete-url="<?= e(url($c['base'].'/'.$r['id'].'/delete')) ?>" data-delete-name="<?= e($title) ?>" data-delete-kind="<?= e(__('checklist')) ?>"><?= icon('trash', 'size-4') ?> <?= e(__('Delete')) ?></button><?php endif ?>
</div>

<?php if (count($chain) > 1): ?>
<ol class="mt-5 flex flex-wrap items-stretch gap-2 sm:flex-nowrap">
    <?php foreach ($chain as $i => [$label, $who, $at, $state]): ?>
    <li class="flex min-w-[9rem] flex-1 items-center gap-3 rounded-xl bg-white p-3 shadow-sm ring-1 ring-graphite-900/8">
        <span class="flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold <?= $tone[$state] ?>"><?= $state === 'done' ? icon('check', 'size-4') : $i + 1 ?></span>
        <span class="min-w-0"><span class="block text-xs text-steel"><?= e($label) ?></span><span class="block truncate text-sm font-medium"><?= e($who) ?></span>
            <span class="block text-xs text-steel"><?= $at ? e(format_date($at, 'd M H:i')) : e(['now' => __('waiting'), 'late' => __('overdue'), 'waiting' => '—'][$state] ?? '') ?></span></span>
    </li>
    <?php endforeach ?>
</ol>
<?php endif ?>

<?php if ($canApprove): ?>
<form method="POST" action="<?= url($c['base'].'/'.$r['id'].'/approve') ?>" class="panel mt-5 space-y-3 border-l-4 border-amber-500 p-4"><?= csrf_field() ?>
    <p class="text-sm font-semibold"><?= e(__('This checklist waits for your approval.')) ?></p>
    <input name="note" maxlength="300" class="input" placeholder="<?= e(__('Note (optional)')) ?>" aria-label="<?= e(__('Note')) ?>">
    <button class="btn-dark w-full sm:w-auto"><?= icon('check', 'size-4') ?> <?= e(__('Approve')) ?></button>
</form>
<?php endif ?>

<section class="panel mt-5 overflow-hidden">
    <div class="border-b border-graphite-900/6 bg-mist/50 px-5 py-2 text-xs font-semibold text-steel"><?= e(__('Answers')) ?> · <?= e(__('by :name', ['name' => $by])) ?></div>
    <?php if (! $answers): ?><p class="px-5 py-6 text-center text-sm text-steel"><?= e($r['reason_not_checked'] ?: __('No answers were saved.')) ?></p>
    <?php else: ?>
    <ul class="divide-y divide-graphite-900/6">
        <?php foreach ($answers as $a): $v = (string) ($a['answer'] ?? ''); ?>
        <li class="flex flex-wrap items-start gap-x-4 gap-y-1 px-5 py-3 text-sm">
            <span class="min-w-0 flex-1"><?= e($a['detail'] ?? '') ?><?php if (! empty($a['remark'])): ?><span class="mt-0.5 block text-xs <?= $v === 'NG' ? 'text-red-700' : 'text-steel' ?>"><?= e($a['remark']) ?></span><?php endif ?></span>
            <?php if (! empty($a['is_choice'])): ?><?= Mx::pill($v === 'NA' ? 'N/A' : $v, $v === 'OK' ? 'ok' : ($v === 'NG' ? 'bad' : 'neutral')) ?><?php else: ?><span class="font-medium tabular-nums"><?= e($v) ?></span><?php endif ?>
        </li>
        <?php endforeach ?>
    </ul>
    <?php endif ?>
</section>

<?php if ($r['machine_note'] || $r['job_detail'] || $r['image'] || ($answers && $r['reason_not_checked'])): ?>
<section class="panel mt-5 p-5 text-sm">
    <?php if ($r['job_detail']): ?><p class="text-xs text-steel"><?= e(__('Work done')) ?></p><p class="mb-3 whitespace-pre-line"><?= e($r['job_detail']) ?></p><?php endif ?>
    <?php if ($r['machine_note']): ?><p class="text-xs text-steel"><?= e(__('Note')) ?></p><p class="mb-3 whitespace-pre-line"><?= e($r['machine_note']) ?></p><?php endif ?>
    <?php if ($answers && $r['reason_not_checked']): ?><p class="text-xs text-steel"><?= e(__('Approver note')) ?></p><p class="mb-3 whitespace-pre-line"><?= e($r['reason_not_checked']) ?></p><?php endif ?>
    <?php if ($r['image']): ?><a href="<?= e(upload_url($r['image'])) ?>" target="_blank" rel="noopener"><img src="<?= e(upload_url($r['image'])) ?>" alt="<?= e(__('Photo')) ?>" class="max-h-72 rounded-lg ring-1 ring-graphite-900/10"></a><?php endif ?>
</section>
<?php endif ?>
</div>
