<?php /* $dry, $result [inserted, updated, skipped, errors, total], $table, $tableLabel, $token, $sheet, $fileName, $post */
$flat = function (array $a, string $prefix = '') use (&$flat) { $o = []; foreach ($a as $k => $v) { $key = $prefix === '' ? (string) $k : $prefix.'['.$k.']'; if (is_array($v)) { $o += $flat($v, $key); } else { $o[$key] = $v; } } return $o; };
$ok = $result['inserted'] + $result['updated'];
?>
<div class="min-w-0">
    <p class="text-sm text-steel"><a href="<?= url('/crm/data') ?>" class="hover:text-signal-700"><?= e(__('Data tools')) ?></a> › <?= e(__('Import')) ?></p>
    <h1 class="page-title"><?= e($dry ? __('Check result') : __('Import finished')) ?></h1>
    <p class="mt-1 text-sm text-steel"><?= e($fileName) ?> → <?= e($tableLabel) ?><?= $dry ? ' · '.e(__('nothing has been saved yet')) : '' ?></p>
</div>

<section class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
    <?php foreach ([[__('Rows read'), $result['total'], 'neutral'], [$dry ? __('Would be added') : __('Added'), $result['inserted'], 'success'], [$dry ? __('Would be updated') : __('Updated'), $result['updated'], 'info'], [__('Skipped (problems)'), $result['skipped'], $result['skipped'] ? 'danger' : 'neutral']] as [$l, $v, $tone]): ?>
    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-graphite-900/8"><p class="text-xs text-steel"><?= e($l) ?></p><p class="text-2xl font-semibold tabular-nums <?= $tone === 'danger' ? 'text-signal-700' : '' ?>"><?= number_format($v) ?></p></div>
    <?php endforeach ?>
</section>

<?php if ($result['errors']): ?>
<section class="panel mt-4 overflow-hidden">
    <div class="panel-head"><h2 class="panel-title"><?= e(__('Rows with problems')) ?></h2><span class="text-xs text-steel"><?= e(__('Row numbers are the rows of the sheet (the header is row 1). Showing :n.', ['n' => count($result['errors'])])) ?></span></div>
    <div class="max-h-96 overflow-y-auto"><table class="w-full text-sm"><tbody class="divide-y divide-graphite-900/6">
        <?php foreach ($result['errors'] as [$line, $msg]): ?><tr><td class="w-24 px-4 py-2 tabular-nums text-steel"><?= e(__('Row')) ?> <?= (int) $line ?></td><td class="px-3 py-2"><?= e($msg) ?></td></tr><?php endforeach ?>
    </tbody></table></div>
</section>
<?php endif ?>

<div class="mt-4 flex flex-wrap items-center gap-2">
    <?php if ($dry): ?>
    <form method="POST" action="<?= url('/crm/data/run') ?>" class="contents">
        <?= csrf_field() ?>
        <?php foreach ($flat($post) as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>"><?php endforeach ?>
        <input type="hidden" name="action" value="import">
        <button class="btn-primary" <?= $ok === 0 ? 'disabled' : '' ?>><?= icon('upload', 'size-4') ?> <?= e(__('Import :n rows', ['n' => number_format($ok)])) ?></button>
    </form>
    <a href="<?= url('/crm/data/map', ['token' => $token, 'sheet' => $sheet, 'table' => $table, 'header' => $post['header'] === '0' ? '0' : null]) ?>" class="btn-secondary"><?= e(__('Back to the matching')) ?></a>
    <?php if ($result['skipped']): ?><span class="text-sm text-steel"><?= e(__('Rows with problems are skipped; the others are imported.')) ?></span><?php endif ?>
    <?php else: ?>
    <a href="<?= url('/crm/data/map', ['token' => $token]) ?>" class="btn-secondary"><?= e(__('Import another sheet from this file')) ?></a>
    <form method="POST" action="<?= url('/crm/data/discard') ?>" class="contents"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>"><button class="btn-primary"><?= e(__('Done — remove the file')) ?></button></form>
    <?php endif ?>
</div>
