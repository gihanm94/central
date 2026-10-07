<?php
use App\Modules\Machines\Support\Mx;
/* $r record, $m machine, $items, $people, $me */
$crumbs = [[__('Maintenance'), '/machines/maintenance']];
$old = old('answers'); $old = is_array($old) ? $old : [];
$err = error_for('answers');
$late = $r['due_date'] && $r['due_date'] < date('Y-m-d');
?>
<form method="POST" action="<?= url('/machines/maintenance/'.$r['id'].'/perform') ?>" enctype="multipart/form-data" data-no-busy data-check class="mx-auto max-w-2xl pb-28">
    <?= csrf_field() ?>
    <section class="panel overflow-hidden">
        <div class="p-4">
            <p class="text-xs tabular-nums text-steel"><?= e($m['machine_code']) ?> · <?= e(__('round :n of :y', ['n' => $r['round'] ?? '?', 'y' => $r['years']])) ?></p>
            <h1 class="text-lg font-semibold leading-tight"><?= e($m['machine_name']) ?></h1>
            <p class="mt-1.5 flex flex-wrap items-center gap-2 text-sm text-steel"><?= e(__('Due')) ?> <strong class="text-graphite-900"><?= e($r['due_date'] ? format_date($r['due_date']) : '—') ?></strong> <?= $late ? Mx::pill(__('Overdue'), 'bad') : '' ?></p>
        </div>
    </section>

    <section class="panel mt-4 space-y-4 p-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <div><label class="label" for="f-date"><?= e(__('Date done')) ?></label><input id="f-date" type="date" name="actual_date" value="<?= e(old('actual_date', date('Y-m-d'))) ?>" max="<?= date('Y-m-d') ?>" class="input !h-11"><?= field_error('actual_date') ?></div>
            <div><label class="label"><?= e(__('Done by')) ?></label><?= select_field('maintenance_by', array_map(fn ($l) => __($l), Mx::MAINT_BY), (string) old('maintenance_by', $r['maintenance_by'] ?: 'INTERNAL'), ['search' => false, 'class' => 'input !h-11']) ?><?= field_error('maintenance_by') ?></div>
            <div class="sm:col-span-2"><label class="label"><?= e(__('Person doing the maintenance')) ?></label><?= select_field('responsible_maintenance', $people, (string) old('responsible_maintenance', $r['responsible_maintenance'] ?: $me), ['class' => 'input !h-11']) ?></div>
        </div>
        <div>
            <span class="label"><?= e(__('Machine status after the maintenance')) ?></span>
            <div class="grid grid-cols-2 gap-2" role="radiogroup">
                <?php foreach (['OPERATIONAL' => ['Operational', 'peer-checked:bg-emerald-600 peer-checked:text-white peer-checked:ring-emerald-600'], 'UNDER MAINTENANCE' => ['Under maintenance', 'peer-checked:bg-amber-500 peer-checked:text-white peer-checked:ring-amber-500']] as $k => [$l, $cls]): ?>
                <label class="cursor-pointer"><input type="radio" name="machine_status" value="<?= e($k) ?>" class="peer sr-only" <?= old('machine_status', 'OPERATIONAL') === $k ? 'checked' : '' ?>>
                    <span class="flex h-12 items-center justify-center rounded-xl bg-white px-2 text-sm font-semibold ring-1 ring-graphite-900/15 transition-colors peer-focus-visible:outline-2 peer-focus-visible:outline-signal-600 <?= $cls ?>"><?= e(__($l)) ?></span></label>
                <?php endforeach ?>
            </div>
            <?= field_error('machine_status') ?>
        </div>
    </section>

    <?php if ($items): ?>
    <h2 class="mb-2 mt-6 flex items-center justify-between text-sm font-semibold"><span><?= e(__('Maintenance checklist')) ?></span><span class="text-xs font-normal text-steel" data-progress><?= count($items) ?></span></h2>
    <?php if ($err): ?><p class="mb-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800"><?= e($err) ?></p><?php endif ?>
    <?= partial('machines/_questions', ['items' => $items, 'old' => $old]) ?>
    <?php endif ?>

    <section class="panel mt-5 space-y-4 p-4">
        <div><label class="label" for="f-job"><?= e(__('What was done')) ?> <span class="text-signal-600">*</span></label><textarea id="f-job" name="job_detail" rows="4" maxlength="3000" class="input"><?= e(old('job_detail', '')) ?></textarea><?= field_error('job_detail') ?></div>
        <div><label class="label" for="f-note"><?= e(__('Note (optional)')) ?></label><textarea id="f-note" name="note" rows="2" maxlength="2000" class="input"><?= e(old('note', $r['note'] ?? '')) ?></textarea></div>
        <div><label class="label" for="f-photo"><?= e(__('Photo (optional)')) ?></label><input id="f-photo" name="image" type="file" accept="image/*" capture="environment" class="w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-graphite-900 file:px-3 file:py-2 file:text-sm file:text-white"></div>
        <div><label class="label" for="f-docs"><?= e(__('Reports or certificates (optional)')) ?></label><input id="f-docs" name="docs[]" type="file" multiple class="w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-graphite-900 file:px-3 file:py-2 file:text-sm file:text-white"></div>
    </section>

    <div class="fixed inset-x-0 bottom-0 z-30 border-t border-graphite-900/10 bg-white/95 px-4 py-3 backdrop-blur lg:left-64">
        <div class="mx-auto flex max-w-2xl items-center gap-3">
            <a href="<?= url('/machines/maintenance') ?>" class="btn-secondary"><?= e(__('Cancel')) ?></a>
            <button class="btn-primary !h-11 flex-1 text-base"><?= icon('check', 'size-5') ?> <?= e(__('Save maintenance')) ?></button>
        </div>
    </div>
</form>
<script src="<?= asset('assets/machines.js') ?>" defer></script>
