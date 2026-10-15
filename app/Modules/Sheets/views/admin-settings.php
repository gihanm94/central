<?php /* $office {start,end,days}, $maxRows, $configured, $redirect */
use App\Modules\Sheets\Support\Schedule;
$days = array_map('intval', (array) old('office_days', $office['days']));
?>
<div class="min-w-0"><h1 class="page-title"><?= e(__('Google Sheets settings')) ?></h1></div>
<div class="mt-4 grid gap-4 lg:grid-cols-3">
    <form method="POST" action="<?= url('/sheets/settings') ?>" class="panel lg:col-span-2"><?= csrf_field() ?>
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Office hours (no syncing)')) ?></h2></div>
        <div class="grid gap-4 p-5 sm:grid-cols-2">
            <div><label class="label" for="f-s"><?= e(__('From')) ?></label><input id="f-s" type="time" name="office_start" value="<?= e(old('office_start', $office['start'])) ?>" class="input"><?= field_error('office_start') ?></div>
            <div><label class="label" for="f-e"><?= e(__('Until')) ?></label><input id="f-e" type="time" name="office_end" value="<?= e(old('office_end', $office['end'])) ?>" class="input"><?= field_error('office_end') ?></div>
            <div class="sm:col-span-2"><span class="label"><?= e(__('On')) ?></span>
                <div class="flex flex-wrap gap-1.5"><?php foreach (Schedule::DAYS as $n => $d): ?>
                    <label class="flex items-center gap-1.5 rounded-md border border-graphite-900/10 px-2.5 py-1 text-sm has-[:checked]:border-signal-600 has-[:checked]:bg-signal-50"><input type="checkbox" name="office_days[]" value="<?= $n ?>" class="size-4 accent-signal-600" <?= in_array($n, $days, true) ? 'checked' : '' ?>> <?= e(__($d)) ?></label>
                <?php endforeach ?></div></div>
            <div><label class="label" for="f-max"><?= e(__('Most rows per sync')) ?></label><input id="f-max" type="number" name="max_rows" min="100" max="1000000" value="<?= e(old('max_rows', $maxRows)) ?>" class="input"><?= field_error('max_rows') ?>
                <p class="mt-1 text-xs text-steel"><?= e(__('A Google spreadsheet holds at most 10 million cells.')) ?></p></div>
        </div>
        <div class="flex justify-end border-t border-graphite-900/8 bg-mist/50 px-5 py-3"><button class="btn-primary"><?= e(__('Save')) ?></button></div>
    </form>
    <section class="panel">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Set up Google')) ?></h2><span class="badge <?= $configured ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800' ?>"><?= e($configured ? __('Ready') : __('Not set up')) ?></span></div>
        <ol class="list-decimal space-y-2 px-8 py-4 text-sm text-steel">
            <li><?= e(__('Uses the same OAuth client as Administration → Connectors, with the same redirect URI:')) ?> <code class="break-all text-xs"><?= e($redirect) ?></code></li>
            <li><?= e(__('In Google Cloud Console enable the Google Sheets API and the Google Drive API.')) ?></li>
            <li><?= e(__('On the consent screen add the scopes spreadsheets and drive.metadata.readonly.')) ?></li>
            <li><?= e(__('Run bin/sheets-sync.php from cron every 5 minutes, so evening and night syncs run when nobody is signed in.')) ?></li>
        </ol>
    </section>
</div>
