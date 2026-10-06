<?php
use App\Modules\CRM\Support\Data\Schema;
/* $token, $fileName, $sheets [name => rows], $sheet, $table, $tables, $columns, $header, $sample, $rowCount, $suggest, $fixed, $withHeader, $saved, $savedId, $isSql */
$base = ['token' => $token, 'sheet' => $sheet, 'table' => $table ?: null, 'header' => $withHeader ? null : '0'];
$link = fn (array $x) => url('/crm/data/map', array_filter(array_merge($base, $x), fn ($v) => $v !== null && $v !== ''));
$main = array_filter($columns, fn ($c) => ! Schema::audit($c['name']));
$auditCols = array_filter($columns, fn ($c) => Schema::audit($c['name']));
$samples = [];
foreach ($header as $i => $_) { $samples[$i] = array_values(array_filter(array_map(fn ($r) => (string) ($r[$i] ?? ''), $sample), fn ($v) => $v !== '')); }
$mapRow = function (array $c) use ($header, $suggest, $fixed) { ob_start();
    $name = $c['name']; $sel = $suggest[$name] ?? null; ?>
    <tr class="align-top">
        <td class="px-4 py-2.5">
            <span class="font-medium"><?= e($name) ?></span><?php if ($c['required']): ?> <span class="text-signal-600" title="<?= e(__('required')) ?>">*</span><?php endif ?>
            <span class="block text-xs text-steel"><?= e($c['type'].($c['length'] ? '('.$c['length'].')' : '')) ?><?= isset(Schema::LOOKUPS[$name]) ? ' · '.e(__('id or name')) : '' ?></span>
        </td>
        <td class="px-3 py-2.5">
            <select name="map[<?= e($name) ?>]" class="input" data-map aria-label="<?= e($name) ?>">
                <option value=""><?= e(__('— not imported —')) ?></option>
                <?php foreach ($header as $i => $h): ?><option value="<?= $i ?>" <?= $sel === $i ? 'selected' : '' ?>><?= e($h) ?></option><?php endforeach ?>
            </select>
        </td>
        <td class="px-3 py-2.5 text-xs text-steel"><span data-preview class="block max-w-[16rem] truncate"></span></td>
        <td class="px-3 py-2.5"><input name="fixed[<?= e($name) ?>]" value="<?= e($fixed[$name] ?? '') ?>" class="input" placeholder="<?= e(__('empty cells get…')) ?>" aria-label="<?= e(__('Fixed value for :c', ['c' => $name])) ?>"></td>
    </tr>
<?php return ob_get_clean(); };
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
        <p class="text-sm text-steel"><a href="<?= url('/crm/data') ?>" class="hover:text-signal-700"><?= e(__('Data tools')) ?></a> › <?= e(__('Import')) ?></p>
        <h1 class="page-title"><?= e(__('Match your file to a table')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e($fileName) ?> · <?= e(__(':n rows in this sheet', ['n' => number_format($rowCount)])) ?></p>
    </div>
    <form method="POST" action="<?= url('/crm/data/discard') ?>"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>"><button class="btn-ghost text-signal-700"><?= icon('trash', 'size-4') ?> <?= e(__('Remove the uploaded file')) ?></button></form>
</div>

<!-- 1. which sheet / SQL table, 2. which CRM table -->
<section class="panel mt-4">
    <div class="grid gap-4 p-5 lg:grid-cols-2">
        <div>
            <p class="label"><?= e($isSql ? __('Table in the SQL file') : __('Sheet')) ?></p>
            <div class="flex flex-wrap gap-1.5">
                <?php foreach ($sheets as $n => $cnt): ?>
                <a href="<?= $link(['sheet' => $n, 'table' => null, 'saved' => null]) ?>" class="rounded-lg px-3 py-1.5 text-sm ring-1 <?= (string) $n === $sheet ? 'bg-graphite-900 text-white ring-graphite-900' : 'bg-white ring-graphite-900/15 hover:bg-mist' ?>"><?= e($n) ?> <span class="text-xs opacity-70"><?= number_format($cnt) ?></span></a>
                <?php endforeach ?>
            </div>
            <?php if (! $isSql): ?>
            <label class="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" class="size-4 accent-signal-600" <?= $withHeader ? 'checked' : '' ?> data-href="<?= e($link(['header' => $withHeader ? '0' : null])) ?>" onchange="location.href = this.dataset.href"> <?= e(__('The first row holds the column names')) ?></label>
            <?php endif ?>
        </div>
        <form method="GET" action="<?= url('/crm/data/map') ?>">
            <input type="hidden" name="token" value="<?= e($token) ?>"><input type="hidden" name="sheet" value="<?= e($sheet) ?>"><?php if (! $withHeader): ?><input type="hidden" name="header" value="0"><?php endif ?>
            <p class="label"><?= e(__('Import into')) ?></p>
            <?= select_field('table', $tables, $table, ['placeholder' => __('Choose a CRM table…'), 'submit' => true, 'search' => true]) ?>
            <p class="hint"><?= e(__('Each sheet goes to one table. Import the next sheet afterwards.')) ?></p>
        </form>
    </div>
</section>

<?php if ($table): ?>
<form method="POST" action="<?= url('/crm/data/run') ?>" class="mt-4" data-map-form data-samples="<?= e(json_encode($samples, JSON_UNESCAPED_UNICODE)) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>"><input type="hidden" name="sheet" value="<?= e($sheet) ?>"><input type="hidden" name="table" value="<?= e($table) ?>"><input type="hidden" name="header" value="<?= $withHeader ? '1' : '0' ?>">
    <?php if ($err = error_for('map') ?? error_for('mode')): ?><p class="error mb-2"><?= e($err) ?></p><?php endif ?>

    <?php if ($saved): ?>
    <div class="mb-3 flex flex-wrap items-center gap-2 text-sm"><span class="text-steel"><?= e(__('Saved mappings')) ?>:</span>
        <?php foreach ($saved as $s): ?>
        <span class="inline-flex items-center overflow-hidden rounded-full ring-1 <?= $savedId === (int) $s['id'] ? 'bg-graphite-900 text-white ring-graphite-900' : 'bg-white ring-graphite-900/15' ?>"><a href="<?= $link(['saved' => (int) $s['id']]) ?>" class="px-3 py-1"><?= e($s['name']) ?></a><button type="submit" formaction="<?= url('/crm/data/mappings/'.$s['id'].'/delete') ?>" class="px-2 py-1 opacity-60 hover:opacity-100" aria-label="<?= e(__('Delete')) ?>" data-confirm="<?= e(__('Delete this saved mapping?')) ?>">&times;</button></span>
        <?php endforeach ?>
    </div>
    <?php endif ?>

    <section class="panel overflow-hidden">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Match columns')) ?> · <?= e($tables[$table]) ?></h2><span class="text-xs text-steel"><?= e(__('* needed. Cells that stay empty use the fixed value, then the default.')) ?></span></div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-mist/60 text-left text-xs text-steel"><tr><th class="px-4 py-2 font-medium"><?= e(__('Table column')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Column in your file')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('First values')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Fixed value')) ?></th></tr></thead>
            <tbody class="divide-y divide-graphite-900/6">
                <?php foreach ($main as $c) { echo $mapRow($c); } ?>
                <tr><td colspan="4" class="bg-mist/40 px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-steel"><?= e(__('Owner, department, ids and dates (optional)')) ?></td></tr>
                <?php foreach ($auditCols as $c) { echo $mapRow($c); } ?>
            </tbody>
        </table>
        </div>
        <p class="border-t border-graphite-900/8 bg-mist/50 px-5 py-2 text-xs text-steel"><?= e(__('Without a column, the owner is you, the department is the owner\'s, and the code is numbered automatically.')) ?></p>
    </section>

    <section class="panel mt-4">
        <div class="grid gap-4 p-5 lg:grid-cols-3">
            <div>
                <label class="label" for="imp-mode"><?= e(__('If the row already exists')) ?></label>
                <select id="imp-mode" name="mode" class="input">
                    <option value="insert"><?= e(__('Always add a new row')) ?></option>
                    <option value="update_code"><?= e(__('Update the row with the same code')) ?></option>
                    <option value="update_id"><?= e(__('Update the row with the same id')) ?></option>
                </select>
            </div>
            <div>
                <label class="label" for="imp-save"><?= e(__('Save this mapping as')) ?></label>
                <input id="imp-save" name="save_as" class="input" maxlength="120" placeholder="<?= e(__('e.g. Old CRM leads (optional)')) ?>">
            </div>
            <div class="flex flex-wrap items-end gap-2 lg:justify-end">
                <button type="submit" name="action" value="check" class="btn-secondary"><?= icon('check', 'size-4') ?> <?= e(__('Check only')) ?></button>
                <button type="submit" name="action" value="import" class="btn-primary"><?= icon('upload', 'size-4') ?> <?= e(__('Import')) ?></button>
            </div>
        </div>
    </section>
</form>
<?php endif ?>
