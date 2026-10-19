<?php /* $row (kpi_submissions), $approvers [id => name], $submitter */
$period = format_date(sprintf('%04d-%02d-01', $row['period_year'], $row['period_month']), 'F Y');
$v = fn (string $k) => old($k, $row[$k]);
$n = fn ($x) => $x === null || $x === '' ? '' : number_clean($x);
?>
<div class="mx-auto max-w-3xl">
    <h1 class="page-title"><?= e(__('Correct submission')) ?></h1>
    <p class="mt-1 text-sm text-steel"><?= e(__('Only the BU manager of the department can correct a submission. Every change is saved in its history and :name is notified.', ['name' => $submitter])) ?></p>

    <section class="panel mt-4 grid gap-3 p-5 text-sm sm:grid-cols-3">
        <div><p class="text-xs text-steel"><?= e(__('Submitted by')) ?></p><p class="font-medium"><?= e($submitter) ?></p></div>
        <div><p class="text-xs text-steel"><?= e(__('KPI / OKR')) ?></p><p class="font-medium"><span class="badge mr-1 <?= $row['task_kind'] === 'okr' ? 'bg-violet-50 text-violet-800' : 'bg-sky-50 text-sky-800' ?>"><?= e(strtoupper($row['task_kind'])) ?></span><?= e($row['task_title']) ?></p></div>
        <div><p class="text-xs text-steel"><?= e(__('Month')) ?></p><p class="font-medium"><?= e($period) ?> · <span class="<?= $row['percentage'] < 100 ? 'text-signal-700' : 'text-emerald-700' ?>"><?= (int) $row['percentage'] ?>%</span></p></div>
    </section>

    <form method="POST" action="<?= url('/kpi-submissions/'.(int) $row['id']) ?>" class="panel mt-4">
        <?= csrf_field() ?>
        <div class="grid gap-5 p-5 sm:grid-cols-2 sm:p-6">
            <div><label class="label" for="e-approver"><?= e(__('Approver')) ?> <span class="text-signal-600">*</span></label>
                <?= select_field('approver_id', $approvers, (string) $v('approver_id'), ['id' => 'e-approver', 'required' => true]) ?><?= field_error('approver_id') ?></div>
            <div><label class="label" for="e-crit"><?= e(__('Criteria')) ?></label>
                <input id="e-crit" name="criteria" type="number" step="any" min="1" max="100" value="<?= e($n($v('criteria'))) ?>" class="input"><?= field_error('criteria') ?></div>
            <div><label class="label" for="e-fin"><?= e(__('Finished')) ?> <span class="text-signal-600">*</span></label>
                <input id="e-fin" name="finished" type="number" step="any" min="0" required value="<?= e($n($v('finished'))) ?>" class="input"><?= field_error('finished') ?></div>
            <div><label class="label" for="e-total"><?= e(__('Total tasks')) ?> <span class="text-signal-600">*</span></label>
                <input id="e-total" name="total" type="number" step="any" min="0" required value="<?= e($n($v('total'))) ?>" class="input"><?= field_error('total') ?></div>
            <div class="sm:col-span-2"><label class="label" for="e-note"><?= e(__('Note')) ?></label>
                <textarea id="e-note" name="note" rows="3" maxlength="2000" class="input h-auto py-2"><?= e($v('note')) ?></textarea><?= field_error('note') ?></div>
            <div class="sm:col-span-2"><label class="label" for="e-link"><?= e(__('Related link')) ?></label>
                <input id="e-link" name="related_link" type="url" maxlength="500" value="<?= e($v('related_link')) ?>" class="input" placeholder="https://"><?= field_error('related_link') ?></div>
        </div>
        <div class="flex items-center justify-end gap-2 border-t border-graphite-900/8 px-5 py-4 sm:px-6">
            <a href="<?= url('/kpi-submissions', ['year' => (int) $row['period_year']]) ?>" class="btn-ghost mr-auto"><?= e(__('Cancel')) ?></a>
            <button class="btn-primary"><?= e(__('Save changes')) ?></button>
        </div>
    </form>
</div>
