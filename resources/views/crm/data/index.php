<?php /* $tables [table => [label, count]], $departments, $excel, $tab */
$tabs = ['import' => __('Import'), 'export' => __('Export')];
?>
<div class="min-w-0">
    <h1 class="page-title"><?= e(__('Data tools')) ?></h1>
    <p class="mt-1 text-sm text-steel"><?= e(__('Administrators only. Move CRM data in from Excel, CSV or an SQL file, or take it out again.')) ?></p>
</div>

<div class="mt-4 inline-flex rounded-lg bg-white p-0.5 text-sm shadow-sm ring-1 ring-graphite-900/10" role="tablist">
    <?php foreach ($tabs as $k => $l): ?><a href="<?= url('/crm/data', ['tab' => $k]) ?>" role="tab" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>" class="rounded-md px-4 py-1.5 <?= $tab === $k ? 'bg-graphite-900 font-medium text-white' : 'text-steel hover:text-graphite-900' ?>"><?= e($l) ?></a><?php endforeach ?>
</div>

<?php if ($tab === 'import'): ?>
<section class="panel mt-4">
    <div class="panel-head"><h2 class="panel-title"><?= e(__('1. Choose a file')) ?></h2></div>
    <form method="POST" action="<?= url('/crm/data/upload') ?>" enctype="multipart/form-data" class="grid gap-4 p-5 lg:grid-cols-[1fr_auto] lg:items-end">
        <?= csrf_field() ?>
        <div>
            <label class="label" for="data-file"><?= e(__('Excel (.xlsx), CSV or SQL file')) ?></label>
            <input id="data-file" type="file" name="file" accept=".xlsx,.csv,.txt,.sql" required class="input !h-auto py-1.5 file:mr-3 file:rounded file:border-0 file:bg-graphite-900/6 file:px-3 file:py-1 file:text-sm">
            <?php if ($err = error_for('file')): ?><p class="error"><?= e($err) ?></p><?php endif ?>
            <p class="hint"><?= e(__('Next you choose the table and match every column of your file to a column of the table. Nothing is saved until you press Import.')) ?></p>
        </div>
        <button class="btn-primary"><?= icon('upload', 'size-4') ?> <?= e(__('Upload and continue')) ?></button>
    </form>
    <ul class="grid gap-3 border-t border-graphite-900/8 bg-mist/50 p-5 text-sm text-steel sm:grid-cols-3">
        <li><span class="font-medium text-graphite-900">Excel</span><br><?= e($excel ? __('Every sheet can be imported into its own table. Dates and numbers are understood.') : __('Not available on this server (PHP zip extension missing). Save the sheet as CSV.')) ?></li>
        <li><span class="font-medium text-graphite-900">CSV</span><br><?= e(__('Comma, semicolon or tab separated. UTF-8 is best; Thai Windows files are converted.')) ?></li>
        <li><span class="font-medium text-graphite-900">SQL</span><br><?= e(__('Only the data (INSERT or COPY) is read — the file is never executed, so it cannot change the database.')) ?></li>
    </ul>
</section>
<?php else: ?>
<form method="POST" action="<?= url('/crm/data/export') ?>" class="panel mt-4">
    <?= csrf_field() ?>
    <div class="panel-head"><h2 class="panel-title"><?= e(__('Export')) ?></h2></div>
    <div class="grid gap-6 p-5 lg:grid-cols-[1.4fr_1fr]">
        <div>
            <p class="label"><?= e(__('Tables')) ?></p>
            <?php if ($err = error_for('tables')): ?><p class="error"><?= e($err) ?></p><?php endif ?>
            <div class="grid gap-1.5 sm:grid-cols-2" data-export-tables>
                <?php foreach ($tables as $t => $info): ?>
                <label class="flex cursor-pointer items-center gap-2.5 rounded-lg px-3 py-2 text-sm ring-1 ring-graphite-900/10 hover:bg-mist/60">
                    <input type="checkbox" name="tables[]" value="<?= e($t) ?>" class="size-4 accent-signal-600">
                    <span class="min-w-0 flex-1 truncate"><?= e($info['label']) ?></span><span class="text-xs tabular-nums text-steel"><?= number_format($info['count']) ?></span>
                </label>
                <?php endforeach ?>
            </div>
        </div>
        <div class="space-y-4">
            <div>
                <p class="label"><?= e(__('Format')) ?></p>
                <div class="space-y-1.5 text-sm">
                    <label class="flex items-center gap-2"><input type="radio" name="format" value="xlsx" class="accent-signal-600" checked> <?= e(__('Excel (.xlsx) — one sheet per table')) ?></label>
                    <label class="flex items-center gap-2"><input type="radio" name="format" value="csv" class="accent-signal-600"> <?= e(__('CSV — a zip when more than one table')) ?></label>
                    <label class="flex items-center gap-2"><input type="radio" name="format" value="sql" class="accent-signal-600"> <?= e(__('SQL — INSERT statements')) ?></label>
                </div>
            </div>
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="names" value="1" class="mt-0.5 size-4 accent-signal-600"> <span><?= e(__('Write names instead of ids')) ?><span class="block text-xs text-steel"><?= e(__('Lead, contact, owner (e-mail), department … are written as text people can read. The import understands both.')) ?></span></span></label>
            <div>
                <p class="label"><?= e(__('Department')) ?></p>
                <?= select_field('department', $departments, '', ['placeholder' => __('All departments')]) ?>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="label" for="ex-from"><?= e(__('Created from')) ?></label><input id="ex-from" type="date" name="from" class="input"></div>
                <div><label class="label" for="ex-to"><?= e(__('Created until')) ?></label><input id="ex-to" type="date" name="to" class="input"></div>
            </div>
            <button class="btn-primary w-full"><?= icon('download', 'size-4') ?> <?= e(__('Download')) ?></button>
        </div>
    </div>
</form>
<?php endif ?>
