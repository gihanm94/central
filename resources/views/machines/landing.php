<?php
use App\Modules\Machines\Support\Mx;
/* $m, $plan, $names, $checks, $due, $canCheck, $canOpen */
$crumbs = [];
$active = Mx::isActive($m['machine_status']);
?>
<div class="mx-auto max-w-xl">
    <section class="panel overflow-hidden">
        <div class="flex items-center gap-4 p-4">
            <?php if ($m['image']): ?><img src="<?= e(upload_url($m['image'])) ?>" alt="" class="size-20 shrink-0 rounded-xl bg-white object-contain ring-1 ring-graphite-900/10"><?php else: ?><span class="flex size-20 shrink-0 items-center justify-center rounded-xl bg-mist text-graphite-400"><?= icon('gear', 'size-10') ?></span><?php endif ?>
            <div class="min-w-0">
                <p class="text-sm tabular-nums text-steel"><?= e($m['machine_code']) ?></p>
                <h1 class="text-xl font-semibold leading-tight"><?= e($m['machine_name']) ?></h1>
                <p class="mt-1.5 flex flex-wrap gap-1.5"><?= Mx::pill($m['machine_status']) ?> <?= Mx::pill($m['check_status']) ?></p>
            </div>
        </div>
        <dl class="grid grid-cols-2 gap-px border-t border-graphite-900/6 bg-graphite-900/6 text-sm">
            <?php foreach ([[__('Type'), trim(($m['machine_group_name'] ?? '').' / '.($m['machine_type_name'] ?? ''), ' /')], [__('Model'), trim(($m['brand'] ?? '').' '.($m['model'] ?? ''))], [__('Responsible'), $names[(int) $m['responsible_person_id']] ?? null], [__('Supervisor'), $names[(int) $m['supervisor_id']] ?? ($names[(int) $m['manager_id']] ?? null)]] as [$l, $v]): ?>
            <div class="bg-white px-4 py-2.5"><dt class="text-xs text-steel"><?= e($l) ?></dt><dd class="truncate font-medium"><?= e($v ?: '—') ?></dd></div>
            <?php endforeach ?>
        </dl>
    </section>

    <?php if ($canCheck): ?>
        <a href="<?= url('/machines/checklists/new?machine='.rawurlencode($m['machine_code'])) ?>" class="mt-4 flex items-center gap-4 rounded-2xl p-5 text-white shadow-sm <?= $plan['recheck'] ? 'bg-amber-600 hover:bg-amber-700' : 'bg-signal-600 hover:bg-signal-700' ?>">
            <?= icon('check', 'size-9 shrink-0') ?>
            <span class="min-w-0"><span class="block text-lg font-semibold"><?= e($plan['recheck'] ? __('Do your re-check') : __('Do the checklist')) ?></span>
                <span class="block text-sm text-white/85"><?= e($plan['empty'] ? __('No questions yet') : __(':n questions', ['n' => count($plan['items'])])) ?><?= $plan['recheck'] ? ' · '.e(__('goes to approval')) : '' ?></span></span>
            <span class="ml-auto text-2xl">›</span>
        </a>
    <?php elseif (! $active): ?>
        <p class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800"><?= e(__('This machine is not in use any more (:status).', ['status' => strtolower($m['machine_status'])])) ?></p>
    <?php endif ?>

    <?php if ($due['maintenance'] || $due['calibration']): ?>
    <section class="panel mt-4 divide-y divide-graphite-900/6 text-sm">
        <?php foreach ([['maintenance', __('Next maintenance'), 'tools'], ['calibration', __('Next calibration'), 'target']] as [$k, $l, $ic]): if (! $due[$k]) continue; $late = $due[$k]['due_date'] < date('Y-m-d'); ?>
        <div class="flex items-center gap-3 px-4 py-3"><?= icon($ic, 'size-5 text-steel') ?><span class="flex-1"><?= e($l) ?></span><span class="font-medium tabular-nums"><?= e(format_date($due[$k]['due_date'])) ?></span><?= $late ? Mx::pill(__('Overdue'), 'bad') : '' ?></div>
        <?php endforeach ?>
    </section>
    <?php endif ?>

    <section class="panel mt-4 overflow-hidden">
        <h2 class="border-b border-graphite-900/6 bg-mist/50 px-4 py-2 text-xs font-semibold text-steel"><?= e(__('Latest checks')) ?></h2>
        <?php if (! $checks): ?><p class="px-4 py-5 text-center text-sm text-steel"><?= e(__('Nobody has checked this machine yet.')) ?></p><?php else: ?>
        <ul class="divide-y divide-graphite-900/6"><?php foreach ($checks as $k): ?>
            <li class="flex flex-wrap items-center gap-x-3 px-4 py-2.5 text-sm"><span class="tabular-nums text-steel"><?= e(format_date($k['created_at'], 'd M H:i')) ?></span><span class="min-w-0 flex-1 truncate"><?= e($k['user_name'] ?? '—') ?></span><?= Mx::pill($k['checklist_status']) ?></li>
        <?php endforeach ?></ul><?php endif ?>
    </section>

    <div class="mt-4 flex flex-wrap gap-2">
        <?php if ($canOpen): ?><a class="btn-secondary flex-1" href="<?= url('/machines/machines/'.$m['id']) ?>"><?= icon('gear', 'size-4') ?> <?= e(__('Machine details')) ?></a><?php endif ?>
        <a class="btn-secondary flex-1" href="<?= url('/machines/scan') ?>"><?= icon('qr', 'size-4') ?> <?= e(__('Scan another')) ?></a>
    </div>
</div>
