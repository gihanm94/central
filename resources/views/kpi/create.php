<?php /* $year, $month, $periods [[y,m]…], $tasks [id => {title,kind,criteria}], $approvers [id => name], $approver, $due DateTimeImmutable, $now {days_late,percentage} */
use App\Core\Support\KpiSubmissions as K;

$years  = array_values(array_unique(array_map(fn ($p) => $p[0], $periods)));
$months = [];
foreach ($periods as [$py, $pm]) {
    if ($py === $year) { $months[$pm] = format_date(sprintf('%04d-%02d-01', $py, $pm), 'F'); }
}
ksort($months);
$period = format_date(sprintf('%04d-%02d-01', $year, $month), 'F Y');
$kind   = ['kpi' => __('KPI'), 'okr' => __('OKR')];
$taskOptions = [];
foreach ($tasks as $id => $t) { $taskOptions[$id] = $kind[$t['kind']].' · '.$t['title']; }
$criteria = array_map(fn ($t) => $t['criteria'] !== null ? number_clean($t['criteria']) : '—', $tasks);
?>
<div class="mx-auto max-w-3xl">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div><h1 class="page-title"><?= e(__('Submit KPIs')) ?></h1>
            <p class="mt-1 text-sm text-steel"><?= e(__('Send your monthly result for each KPI and OKR. Once sent it cannot be changed.')) ?></p></div>
        <a href="<?= url('/kpi-submissions', ['year' => $year, 'view' => 'mine']) ?>" class="btn-secondary"><?= e(__('My submissions')) ?></a>
    </div>

    <details class="panel mt-4 group">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-3 text-sm font-medium">
            <span class="flex items-center gap-2"><?= icon('alert', 'size-4 text-signal-600') ?><?= e(__('Read the rules before you submit')) ?></span>
            <?= icon('right', 'size-4 text-steel transition group-open:rotate-90') ?></summary>
        <ol class="list-decimal space-y-1.5 border-t border-graphite-900/8 px-5 py-4 pl-10 text-sm text-graphite-700">
            <li><?= e(__('Submit only your own results, never for someone else: the system counts by the person who submits.')) ?></li>
            <li><?= e(__('Submit once for every KPI / OKR you have: if you have 5, submit 5 times (use "Submit and continue").')) ?></li>
            <li><?= e(__('A sent result cannot be changed. If it is wrong, ask your approver to delete it, then submit again.')) ?></li>
            <li><?= e(__('A result counts only after your approver has approved it. Approvers approve by day :d of the next month.', ['d' => K::APPROVE_DAY])) ?></li>
            <li><?= e(__('Approved results are final; changes later affect the approver\'s year-end review.')) ?></li>
            <li><?= e(__('Each month is due on day :d of the next month. On time = 100%, 1 day late = 80%, 2 = 60%, 3 = 40%, 4 = 20%, 5 days or more = 0%.', ['d' => K::DUE_DAY])) ?></li>
        </ol>
    </details>

    <form method="POST" action="<?= url('/kpi-submissions') ?>" enctype="multipart/form-data" class="panel mt-4" data-kpi-form
          data-kpi-criteria="<?= e(json_encode($criteria, JSON_FORCE_OBJECT)) ?>" data-kpi-url="<?= e(url('/kpi-submissions/create')) ?>">
        <?= csrf_field() ?>
        <div class="grid gap-5 p-5 sm:grid-cols-2 sm:p-6">
            <div><label class="label" for="k-year"><?= e(__('Year')) ?> <span class="text-signal-600">*</span></label>
                <?= select_field('year', array_combine($years, $years), $year, ['id' => 'k-year', 'required' => true, 'search' => false]) ?><?= field_error('year') ?></div>
            <div><label class="label" for="k-month"><?= e(__('Month')) ?> <span class="text-signal-600">*</span></label>
                <?= select_field('month', $months, $month, ['id' => 'k-month', 'required' => true, 'search' => false]) ?><?= field_error('month') ?></div>

            <div class="sm:col-span-2 rounded-md px-4 py-3 text-sm <?= $now['days_late'] ? ($now['percentage'] ? 'bg-amber-50 text-amber-900' : 'bg-signal-50 text-signal-800') : 'bg-emerald-50 text-emerald-900' ?>">
                <?= e(__(':period is due on :date.', ['period' => $period, 'date' => format_date($due->format('Y-m-d'), 'j M Y')])) ?>
                <strong><?= e($now['days_late'] ? __('Sending today is :n days late and counts :p%.', ['n' => $now['days_late'], 'p' => $now['percentage']]) : __('Sending today is on time (100%).')) ?></strong>
            </div>

            <div class="sm:col-span-2"><label class="label" for="k-task"><?= e(__('KPI / OKR')) ?> <span class="text-signal-600">*</span></label>
                <?php if ($tasks): ?>
                    <?= select_field('task_id', $taskOptions, old('task_id'), ['id' => 'k-task', 'required' => true, 'placeholder' => __('Choose a KPI or OKR…')]) ?>
                    <p class="hint"><?= e(__(':n left to submit for :period. Ones already sent are not listed.', ['n' => count($tasks), 'period' => $period])) ?></p>
                <?php else: ?>
                    <div class="rounded-md bg-mist px-4 py-3 text-sm text-steel"><?= e(__('Nothing left to submit for :period: every KPI and OKR assigned to you is sent. If one is missing, ask your manager to add it in Tasks with the type KPI or OKR.', ['period' => $period])) ?></div>
                <?php endif ?>
                <?= field_error('task_id') ?></div>

            <div><label class="label" for="k-approver"><?= e(__('Approver')) ?> <span class="text-signal-600">*</span></label>
                <?= select_field('approver_id', $approvers, $approver, ['id' => 'k-approver', 'required' => true, 'placeholder' => __('Choose a person…')]) ?>
                <p class="hint"><?= e(__('Gets an e-mail for every submission.')) ?></p><?= field_error('approver_id') ?></div>
            <div><span class="label"><?= e(__('Criteria')) ?></span>
                <p class="flex h-9 items-center rounded-md bg-mist px-3 text-sm tabular-nums" data-kpi-criteria-out>—</p>
                <p class="hint"><?= e(__('Set on the KPI task; copied into the submission.')) ?></p></div>

            <div><label class="label" for="k-fin"><?= e(__('Finished')) ?> <span class="text-signal-600">*</span></label>
                <input id="k-fin" name="finished" type="number" step="any" min="0" inputmode="decimal" required value="<?= e(old('finished')) ?>" class="input" placeholder="0">
                <p class="hint"><?= e(__('Total work done for this KPI.')) ?></p><?= field_error('finished') ?></div>
            <div><label class="label" for="k-total"><?= e(__('Total tasks')) ?> <span class="text-signal-600">*</span></label>
                <input id="k-total" name="total" type="number" step="any" min="0" inputmode="decimal" required value="<?= e(old('total')) ?>" class="input" placeholder="0">
                <p class="hint"><?= e(__('All work assigned for this KPI. For sales, enter the full amount.')) ?></p><?= field_error('total') ?></div>

            <div class="sm:col-span-2"><label class="label" for="k-note"><?= e(__('Note')) ?></label>
                <textarea id="k-note" name="note" rows="3" maxlength="2000" class="input h-auto py-2"><?= e(old('note')) ?></textarea><?= field_error('note') ?></div>

            <div class="sm:col-span-2"><span class="label"><?= e(__('Attachments')) ?></span>
                <label class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-graphite-900/20 bg-mist/50 px-4 py-6 text-center text-sm text-steel hover:border-signal-600/60 hover:bg-mist" data-kpi-drop>
                    <?= icon('upload', 'size-5 text-graphite-400') ?>
                    <span><strong class="text-graphite-900"><?= e(__('Choose files')) ?></strong> <?= e(__('or drag them here')) ?></span>
                    <span class="text-xs"><?= e(__('Up to :n files, :mb MB each: PDF, images, Office, CSV, TXT, ZIP.', ['n' => K::MAX_FILES, 'mb' => K::MAX_FILE_MB])) ?></span>
                    <input type="file" name="files[]" multiple class="sr-only" data-kpi-files accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.csv,.txt,.zip">
                </label>
                <ul class="mt-2 space-y-1 text-sm" data-kpi-file-list></ul><?= field_error('files') ?></div>

            <div class="sm:col-span-2"><label class="label" for="k-link"><?= e(__('Related link')) ?></label>
                <div class="relative"><input id="k-link" name="related_link" type="url" maxlength="500" value="<?= e(old('related_link')) ?>" class="input pr-9" placeholder="https://">
                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-graphite-400"><?= icon('link', 'size-4') ?></span></div><?= field_error('related_link') ?></div>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-graphite-900/8 px-5 py-4 sm:px-6">
            <a href="<?= url('/kpi-submissions') ?>" class="btn-ghost mr-auto"><?= e(__('Cancel')) ?></a>
            <button name="next" value="continue" class="btn-secondary" <?= $tasks ? '' : 'disabled' ?>><?= e(__('Submit and continue')) ?></button>
            <button name="next" value="finish" class="btn-primary" <?= $tasks ? '' : 'disabled' ?>><?= e(__('Submit and finish')) ?></button>
        </div>
    </form>
</div>
<script src="<?= asset('assets/kpi.js') ?>" defer></script>
