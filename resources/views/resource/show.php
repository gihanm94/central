<?php
$crumbs = [[__(ucfirst($c['plural'])), $c['base']]];
$display = function (string $name, array $f) use ($row) {
    $v = $row[$name] ?? null;
    $type = $f['type'] ?? 'text';
    if ($type === 'password') { return null; }
    if ($type === 'checkbox') { return filter_var($v, FILTER_VALIDATE_BOOL) ? __('Yes') : __('No'); }
    if ($v === null || $v === '') { return '—'; }
    if (isset($f['options'])) { return $f['options'][$v] ?? $f['all_options'][$v] ?? $v; }
    if ($type === 'date') { return format_date($v); }
    if ($type === 'datetime') { return format_date($v, 'd M Y H:i'); }
    if ($type === 'number') { return number_clean($v); }
    return $v;
};
$singular = __($c['singular']);
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="flex min-w-0 items-center gap-4">
        <?php if ($c['resource'] === 'members'): ?><?= partial('partials/avatar', ['name' => $row['name'], 'avatar' => $row['avatar'], 'size' => 'size-14', 'extra' => 'text-base']) ?><?php endif ?>
        <div class="min-w-0">
            <h1 class="page-title break-words"><?= e($title) ?></h1>
            <p class="text-sm text-steel">
                <?= e(ucfirst($singular)) ?> #<?= (int) $row['id'] ?>
                <?php if (! empty($row['created_at'])): ?> · <?= e(__('added :date', ['date' => format_date($row['created_at'])])) ?><?php endif ?>
                <?php if (! empty($row['updated_at'])): ?> · <?= e(__('updated :time', ['time' => time_ago($row['updated_at'])])) ?><?php endif ?>
            </p>
        </div>
    </div>
    <div class="flex w-full flex-wrap gap-2 sm:w-auto">
        <?php foreach ($links as $l): ?><a href="<?= url($l['url']) ?>" <?= str_contains($l['url'], '/create') ? 'data-sheet' : '' ?> data-sheet-title="<?= e($l['label']) ?>" class="btn-secondary flex-1 sm:flex-none"><?= icon($l['icon'] ?? 'eye', 'size-4') ?> <?= e($l['label']) ?></a><?php endforeach ?>
        <?php if ($canDownload): ?><a href="<?= url($c['base'].'/'.$row['id'].'/download') ?>" class="btn-secondary flex-1 sm:flex-none"><?= icon('download', 'size-4') ?> <?= e(__('Download')) ?></a><?php endif ?>
        <?php if ($canDelete): ?>
            <button type="button" class="btn-danger flex-1 sm:flex-none" data-delete-url="<?= e(url($c['base'].'/'.$row['id'].'/delete')) ?>" data-delete-name="<?= e($title) ?>" data-delete-kind="<?= e($singular) ?>"><?= icon('trash', 'size-4') ?> <?= e(__('Delete')) ?></button>
        <?php endif ?>
        <?php if ($canEdit): ?><a href="<?= url($c['base'].'/'.$row['id'].'/edit') ?>" class="btn-primary w-full sm:w-auto"><?= icon('pencil', 'size-4') ?> <?= e(__('Edit')) ?></a><?php endif ?>
    </div>
</div>

<?php if ($c['resource'] === 'tasks' && $canEdit && $row['status'] !== 'done'): ?>
    <form method="POST" action="<?= url('/tasks/'.$row['id'].'/status') ?>" class="mt-5 flex flex-wrap items-center gap-2">
        <?= csrf_field() ?>
        <span class="text-sm text-steel"><?= e(__('Move to')) ?></span>
        <?php foreach (App\Core\Http\Controllers\TaskController::statuses() as $k => $l): if ($k === $row['status']) continue; ?>
            <button name="status" value="<?= $k ?>" class="<?= $k === 'done' ? 'btn-dark' : 'btn-secondary' ?> py-1.5"><?= e($l) ?></button>
        <?php endforeach ?>
    </form>
<?php endif ?>

<section class="panel mt-6">
    <dl class="grid sm:grid-cols-2">
        <?php foreach ($fields as $name => $f):
            if (isset($f['section'])): ?>
                <h2 class="border-b border-graphite-900/6 bg-mist/50 px-5 py-2 text-xs font-semibold text-steel sm:col-span-2"><?= e($f['section']) ?></h2>
            <?php continue; endif;
            $d = $display($name, $f); if ($d === null) continue; ?>
            <div class="border-b border-graphite-900/6 px-5 py-3.5 <?= ($f['span'] ?? 1) === 2 ? 'sm:col-span-2' : '' ?>">
                <dt class="text-xs text-steel"><?= e($f['label']) ?></dt>
                <dd class="mt-1 whitespace-pre-line break-words text-sm <?= $d === '—' ? 'text-graphite-400' : 'font-medium' ?>"><?= e($d) ?></dd>
            </div>
        <?php endforeach ?>
    </dl>
</section>

<?php if ($extra): ?><div class="mt-6"><?= $extra ?></div><?php endif ?>
