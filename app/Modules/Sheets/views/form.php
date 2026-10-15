<?php /* $sync (null = new), $tables, $accounts, $tableId, $office, $officeLabel */
use App\Modules\Sheets\Support\Catalog;
use App\Modules\Sheets\Support\Schedule;
$s = $sync ?? [];
$v = fn (string $k, mixed $d = '') => old($k, $s[$k] ?? $d);
$tableId = (int) old('table_id', $tableId);
$label = '';
foreach ($tables as $t) { if ((int) $t['id'] === $tableId) { $label = Catalog::label($t); } }
$mode = (string) $v('mode', 'daily');
$days = array_map('intval', (array) old('days', $sync ? explode(',', (string) $s['days']) : [1, 2, 3, 4, 5]));
$target = (string) old('target', $sync ? 'existing' : 'new');
$accountId = (int) $v('account_id', $accounts[0]['id'] ?? 0);
?>
<div class="mx-auto max-w-3xl">
<a href="<?= url('/sheets') ?>" class="text-sm text-steel hover:text-graphite-900">← <?= e(__('Google Sheets')) ?></a>
<h1 class="page-title mt-1"><?= e($title) ?></h1>

<?php if (! $tables || ! $accounts): ?>
    <section class="panel mt-4 p-6 text-sm text-steel">
        <?= e(! $tables ? __('No table has been opened for you yet. Ask an administrator.') : __('Connect a Google account first.')) ?>
        <?php if ($tables): ?><a href="<?= url('/sheets/connect') ?>" class="btn-primary ml-2"><?= e(__('Connect a Google account')) ?></a><?php endif ?>
    </section>
<?php else: ?>
<form method="POST" action="<?= url($sync ? '/sheets/'.$s['id'] : '/sheets') ?>" class="panel mt-4" data-sheet-form data-files-url="<?= url('/sheets/accounts/__ID__/files') ?>" data-tabs-url="<?= url('/sheets/accounts/__ID__/tabs') ?>"><?= csrf_field() ?>
    <div class="grid gap-5 p-5">
        <!-- 1. table -->
        <div><label class="label" for="f-table">1. <?= e(__('Table')) ?></label>
            <select id="f-table" name="table_id" class="input" data-table>
                <?php foreach ($tables as $t): ?><option value="<?= (int) $t['id'] ?>" data-label="<?= e(Catalog::label($t)) ?>" <?= (int) $t['id'] === $tableId ? 'selected' : '' ?>><?= e(Catalog::label($t).' ('.$t['db'].'.'.$t['table_name'].')') ?></option><?php endforeach ?>
            </select><?= field_error('table_id') ?></div>

        <!-- 2. Google account -->
        <div><label class="label" for="f-account">2. <?= e(__('Google account')) ?></label>
            <div class="flex gap-2">
                <select id="f-account" name="account_id" class="input" data-account>
                    <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $a['id'] === $accountId ? 'selected' : '' ?>><?= e($a['google_email']) ?></option><?php endforeach ?>
                </select>
                <a href="<?= url('/sheets/connect') ?>" class="btn-secondary shrink-0" title="<?= e(__('Connect another Google account')) ?>"><?= icon('plus', 'size-4') ?></a>
            </div><?= field_error('account_id') ?></div>

        <!-- 3. spreadsheet -->
        <fieldset><legend class="label">3. <?= e(__('Spreadsheet')) ?></legend>
            <div class="flex flex-wrap gap-4 text-sm">
                <label class="flex items-center gap-2"><input type="radio" name="target" value="new" class="accent-signal-600" data-target <?= $target === 'new' ? 'checked' : '' ?>> <?= e(__('Create a new spreadsheet')) ?></label>
                <label class="flex items-center gap-2"><input type="radio" name="target" value="existing" class="accent-signal-600" data-target <?= $target === 'existing' ? 'checked' : '' ?>> <?= e(__('Use one of my spreadsheets')) ?></label>
            </div>
            <div data-pane-new class="mt-3" <?= $target === 'new' ? '' : 'hidden' ?>>
                <input name="new_name" value="<?= e(old('new_name', $label)) ?>" class="input" data-new-name placeholder="<?= e(__('Name of the new spreadsheet')) ?>"><?= field_error('new_name') ?>
            </div>
            <div data-pane-existing class="mt-3 grid gap-2" <?= $target === 'existing' ? '' : 'hidden' ?>>
                <div class="flex gap-2"><input type="search" class="input" data-file-search placeholder="<?= e(__('Search by name…')) ?>" aria-label="<?= e(__('Search spreadsheets')) ?>"></div>
                <select name="spreadsheet_id" class="input" data-files size="6" aria-label="<?= e(__('Spreadsheet')) ?>">
                    <?php if (! empty($s['spreadsheet_id'])): ?><option value="<?= e($s['spreadsheet_id']) ?>" selected><?= e($s['spreadsheet_name'] ?: $s['spreadsheet_id']) ?></option><?php endif ?>
                </select>
                <p class="text-xs text-steel" data-files-note></p>
                <input name="spreadsheet_link" value="<?= e(old('spreadsheet_link', '')) ?>" class="input text-xs" placeholder="<?= e(__('…or paste the link of a Google Sheet')) ?>">
                <?= field_error('spreadsheet_id') ?>
            </div>
        </fieldset>

        <!-- 4. tab -->
        <div><label class="label" for="f-tab">4. <?= e(__('Tab (sheet) name')) ?></label>
            <input id="f-tab" name="sheet_tab" value="<?= e($v('sheet_tab', $label)) ?>" list="f-tabs" class="input" maxlength="100" data-tab>
            <datalist id="f-tabs" data-tabs></datalist>
            <p class="mt-1 text-xs text-steel"><?= e(__('The tab is created if it does not exist. Every sync replaces everything on it, so do not type in it; use other tabs for your formulas and charts.')) ?></p>
            <?= field_error('sheet_tab') ?></div>

        <!-- 5. schedule -->
        <fieldset><legend class="label">5. <?= e(__('When')) ?></legend>
            <div class="flex flex-wrap gap-4 text-sm">
                <label class="flex items-center gap-2"><input type="radio" name="mode" value="daily" class="accent-signal-600" data-mode <?= $mode === 'daily' ? 'checked' : '' ?>> <?= e(__('Every day, once')) ?></label>
                <label class="flex items-center gap-2"><input type="radio" name="mode" value="once" class="accent-signal-600" data-mode <?= $mode === 'once' ? 'checked' : '' ?>> <?= e(__('One day only')) ?></label>
            </div>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <div data-pane-daily <?= $mode === 'daily' ? '' : 'hidden' ?> class="sm:col-span-2">
                    <span class="text-xs text-steel"><?= e(__('On these days')) ?></span>
                    <div class="mt-1 flex flex-wrap gap-1.5">
                        <?php foreach (Schedule::DAYS as $n => $d): ?>
                            <label class="flex cursor-pointer items-center gap-1.5 rounded-md border border-graphite-900/10 px-2.5 py-1 text-sm has-[:checked]:border-signal-600 has-[:checked]:bg-signal-50"><input type="checkbox" name="days[]" value="<?= $n ?>" class="size-4 accent-signal-600" <?= in_array($n, $days, true) ? 'checked' : '' ?>> <?= e(__($d)) ?></label>
                        <?php endforeach ?>
                    </div><?= field_error('days') ?>
                </div>
                <div data-pane-once <?= $mode === 'once' ? '' : 'hidden' ?>><label class="label" for="f-date"><?= e(__('Date')) ?></label>
                    <input id="f-date" type="date" name="run_date" value="<?= e($v('run_date', date('Y-m-d'))) ?>" min="<?= date('Y-m-d') ?>" class="input"><?= field_error('run_date') ?></div>
                <div><label class="label" for="f-time"><?= e(__('Time')) ?></label>
                    <input id="f-time" type="time" name="run_time" value="<?= e(substr((string) $v('run_time', $office['end']), 0, 5)) ?>" class="input" step="300"><?= field_error('run_time') ?></div>
            </div>
            <p class="mt-2 text-xs text-steel"><?= e(__('Office hours (:h) are kept free: syncs cannot be set inside them and never run in them.', ['h' => $officeLabel])) ?></p>
        </fieldset>
    </div>
    <div class="flex justify-end gap-2 border-t border-graphite-900/8 bg-mist/50 px-5 py-3">
        <a href="<?= url('/sheets') ?>" class="btn-ghost"><?= e(__('Cancel')) ?></a>
        <button class="btn-primary"><?= e(__('Save sync')) ?></button>
    </div>
</form>
<?php endif ?>
</div>
<script src="<?= asset('assets/sheets.js') ?>" defer></script>
