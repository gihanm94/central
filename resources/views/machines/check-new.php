<?php
use App\Modules\Machines\Support\Mx;
/* $m machine, $plan [recheck, items, empty, weekend], $people */
$crumbs = [[__('Checklists'), '/machines/checklists']];
$old   = old('answers');
$old   = is_array($old) ? $old : [];
$mstat = old('machine_status', $m['machine_status']);
$err   = error_for('answers');
?>
<form method="POST" action="<?= url('/machines/checklists') ?>" enctype="multipart/form-data" data-no-busy data-check class="mx-auto max-w-2xl pb-28">
    <?= csrf_field() ?>
    <input type="hidden" name="machine_code" value="<?= e($m['machine_code']) ?>">

    <section class="panel overflow-hidden">
        <div class="flex items-center gap-4 p-4">
            <?php if ($m['image']): ?><img src="<?= e(upload_url($m['image'])) ?>" alt="" class="size-16 shrink-0 rounded-lg bg-white object-contain ring-1 ring-graphite-900/10"><?php else: ?><span class="flex size-16 shrink-0 items-center justify-center rounded-lg bg-mist text-graphite-400"><?= icon('gear', 'size-8') ?></span><?php endif ?>
            <div class="min-w-0">
                <p class="text-xs tabular-nums text-steel"><?= e($m['machine_code']) ?></p>
                <h1 class="text-lg font-semibold leading-tight"><?= e($m['machine_name']) ?></h1>
                <p class="mt-1 text-xs text-steel"><?= e(trim(($m['machine_group_name'] ?? '').' / '.($m['machine_type_name'] ?? ''), ' /')) ?></p>
            </div>
        </div>
        <div class="border-t border-graphite-900/6 px-4 py-2.5 text-sm <?= $plan['recheck'] ? 'bg-amber-50 text-amber-900' : 'bg-sky-50 text-sky-900' ?>">
            <?php if ($plan['recheck']): ?><strong><?= e(__('Your re-check')) ?></strong> · <?= e(__('after you save, it goes to :who for approval.', ['who' => $m['supervisor_id'] ? ($people[(int) $m['supervisor_id']] ?? __('your supervisor')) : ($people[(int) $m['manager_id']] ?? __('your manager'))])) ?>
            <?php else: ?><strong><?= e(__('General check')) ?></strong> · <?= e(__('the quick check before or after you use the machine.')) ?>
                <?php if ((int) $m['responsible_person_id'] === (int) auth()->id && $plan['weekend']): ?> <?= e(__('Re-checks are done on weekdays.')) ?><?php endif ?><?php endif ?>
        </div>
    </section>

    <?php if ($plan['empty']): ?>
        <p class="mt-4 rounded-lg bg-white px-4 py-6 text-center text-sm text-steel shadow-sm ring-1 ring-graphite-900/8"><?= e(__('Nothing to check on this machine right now.')) ?>
            <?php if (can('machines_machines', 'edit')): ?><br><a class="text-signal-700 underline" href="<?= url('/machines/machines/'.$m['id'].'/edit') ?>"><?= e(__('Add questions to the checklist')) ?></a><?php endif ?></p>
    <?php else: ?>

    <h2 class="mb-2 mt-5 text-sm font-semibold"><?= e(__('Machine status')) ?></h2>
    <div class="grid grid-cols-2 gap-2" role="radiogroup" aria-label="<?= e(__('Machine status')) ?>">
        <?php foreach (['OPERATIONAL' => ['Operational', 'peer-checked:bg-emerald-600 peer-checked:text-white peer-checked:ring-emerald-600'], 'UNDER MAINTENANCE' => ['Under maintenance', 'peer-checked:bg-amber-500 peer-checked:text-white peer-checked:ring-amber-500']] as $k => [$l, $cls]): ?>
        <label class="cursor-pointer"><input type="radio" name="machine_status" value="<?= e($k) ?>" class="peer sr-only" <?= $mstat === $k ? 'checked' : '' ?>>
            <span class="flex h-14 items-center justify-center rounded-xl bg-white px-2 text-center text-sm font-semibold ring-1 ring-graphite-900/15 transition-colors peer-focus-visible:outline-2 peer-focus-visible:outline-signal-600 <?= $cls ?>"><?= e(__($l)) ?></span></label>
        <?php endforeach ?>
    </div>

    <h2 class="mb-2 mt-6 flex items-center justify-between text-sm font-semibold"><span><?= e(__('Questions')) ?></span><span class="text-xs font-normal text-steel" data-progress><?= e(count($plan['items'])) ?></span></h2>
    <?php if ($err): ?><p class="mb-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800"><?= e($err) ?></p><?php endif ?>
    <?= partial('machines/_questions', ['items' => $plan['items'], 'old' => $old]) ?>

    <section class="panel mt-5 space-y-4 p-4">
        <div><label class="label" for="f-photo"><?= e(__('Photo (optional)')) ?></label>
            <input id="f-photo" name="image" type="file" accept="image/*" capture="environment" class="w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-graphite-900 file:px-3 file:py-2 file:text-sm file:text-white"></div>
        <div><label class="label" for="f-note"><?= e(__('Note (optional)')) ?></label><textarea id="f-note" name="machine_note" rows="3" maxlength="2000" class="input"><?= e(old('machine_note', '')) ?></textarea></div>
    </section>

    <div class="fixed inset-x-0 bottom-0 z-30 border-t border-graphite-900/10 bg-white/95 px-4 py-3 backdrop-blur lg:left-64">
        <div class="mx-auto flex max-w-2xl items-center gap-3">
            <a href="<?= url('/machines/scan') ?>" class="btn-secondary"><?= e(__('Cancel')) ?></a>
            <button class="btn-primary !h-11 flex-1 text-base" data-submit><?= icon('check', 'size-5') ?> <?= e($plan['recheck'] ? __('Save and send for approval') : __('Save checklist')) ?></button>
        </div>
    </div>
    <?php endif ?>
</form>
<script src="<?= asset('assets/machines.js') ?>" defer></script>
