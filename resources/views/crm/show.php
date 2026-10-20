<?php
use App\Modules\CRM\Support\Access;
$crumbs = [[__(ucfirst($c['plural'])), $c['base']]];
$singular = __($c['singular']);

// What to show in the details card: skip empty values, file inputs, ownership (it has its own card) and widgets that have their own card.
$value = function (string $name, array $f) use ($row) {
    $type = $f['type'] ?? 'text';
    if (isset($f['display'])) { $d = ($f['display'])($row); return $d === null || $d === '' ? null : (string) $d; }
    $v = $row[$name] ?? null;
    if ($type === 'checkbox') { return filter_var($v, FILTER_VALIDATE_BOOL) ? __('Yes') : null; }
    if ($v === null || $v === '' || $v === []) { return null; }
    if (isset($f['options'])) { $o = $f['options'][$v] ?? $f['all_options'][$v] ?? $v; return (string) (is_array($o) ? $o['label'] : $o); }
    if (($f['type'] ?? '') === 'richtext') { return App\Core\Support\Html::clean((string) $v); }
    return match ($type) {
        'date' => format_date((string) $v),
        'datetime' => format_date((string) $v, 'd M Y H:i'),
        'number' => number_clean($v),
        default => (string) $v,
    };
};
$rows = []; $heading = null;
foreach ($fields as $name => $f) {
    if (isset($f['section'])) { $heading = $name === '_ownership' ? false : $f['section']; continue; }
    if ($heading === false || in_array($name, ['owner_id', 'department_id'], true) || ! empty($f['hide_show']) || ($f['type'] ?? '') === 'file') { continue; }
    $v = $value($name, $f);
    if ($v === null) { continue; }
    $rows[] = ['heading' => $heading, 'name' => $name, 'f' => $f, 'v' => $v];
    $heading = null;
}
$sectionsShown = [];
?>
<div class="flex flex-wrap items-start justify-between gap-4">
    <div class="flex min-w-0 items-center gap-4">
        <?= $media ?>
        <div class="min-w-0">
            <h1 class="page-title break-words"><?= e($title) ?></h1>
            <?php if ($subtitle): ?><p class="mt-0.5 text-sm text-steel"><?= e($subtitle) ?></p><?php endif ?>
            <?php if ($badges): ?><div class="mt-2 flex flex-wrap items-center gap-1.5"><?= implode('', $badges) ?></div><?php endif ?>
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

<?= $top ?>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="min-w-0 space-y-6 lg:col-span-2" <?= ! empty($tabs) ? 'data-tabs' : '' ?>>
        <?php if (! empty($tabs)): ?>
        <nav class="-mb-2 flex gap-1 overflow-x-auto border-b border-graphite-900/10" role="tablist" aria-label="<?= e(__('Sections')) ?>">
            <?php foreach (['details' => ['label' => __('Details'), 'count' => null]] + $tabs as $k => $t): ?>
                <button type="button" role="tab" id="tab-<?= e($k) ?>" aria-controls="panel-<?= e($k) ?>" aria-selected="<?= $k === 'details' ? 'true' : 'false' ?>" data-tab="<?= e($k) ?>"
                    class="-mb-px flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors aria-selected:border-signal-600 aria-selected:text-graphite-900 <?= $k === 'details' ? 'border-signal-600' : 'border-transparent text-steel hover:text-graphite-900' ?>">
                    <?= e($t['label']) ?><?php if ($t['count'] !== null): ?><span class="rounded-full bg-graphite-900/6 px-1.5 text-xs tabular-nums text-steel"><?= (int) $t['count'] ?></span><?php endif ?>
                </button>
            <?php endforeach ?>
        </nav>
        <?php endif ?>
        <section class="panel overflow-hidden" <?= ! empty($tabs) ? 'role="tabpanel" id="panel-details" aria-labelledby="tab-details" data-tab-panel="details"' : '' ?>>
            <div class="panel-head"><h2 class="panel-title"><?= e(__('Details')) ?></h2></div>
            <?php if (! $rows): ?>
                <p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('Nothing more has been filled in yet.')) ?></p>
            <?php else: ?>
            <dl class="grid sm:grid-cols-2">
                <?php foreach ($rows as $r): $f = $r['f']; ?>
                    <?php if ($r['heading']): ?><h3 class="border-b border-graphite-900/6 bg-mist/50 px-5 py-2 text-xs font-semibold text-steel sm:col-span-2"><?= e($r['heading']) ?></h3><?php endif ?>
                    <?php $href = isset($f['href']) ? ($f['href'])($row) : null; ?>
                    <div class="min-w-0 border-b border-graphite-900/6 px-5 py-3.5 <?= ($f['span'] ?? 1) === 2 ? 'sm:col-span-2' : '' ?>">
                        <dt class="text-xs text-steel"><?= e($f['label']) ?></dt>
                        <dd class="mt-1 break-words text-sm <?= ($f['type'] ?? '') === 'richtext' ? '' : 'whitespace-pre-line font-medium' ?>">
                            <?php if (($f['type'] ?? '') === 'richtext'): ?><div class="rt-content"><?= App\Core\Support\Html::render($r['v']) ?></div>
                            <?php elseif ($href): ?><a href="<?= e(url($href)) ?>" class="hover:text-signal-700"><?= e($r['v']) ?></a>
                            <?php elseif (($f['type'] ?? '') === 'email'): ?><a href="mailto:<?= e($r['v']) ?>" class="hover:text-signal-700"><?= e($r['v']) ?></a>
                            <?php else: ?><?= e($r['v']) ?><?php endif ?>
                        </dd>
                    </div>
                <?php endforeach ?>
            </dl>
            <?php endif ?>
        </section>
        <?php foreach ($tabs ?? [] as $k => $t): ?>
            <div role="tabpanel" id="panel-<?= e($k) ?>" aria-labelledby="tab-<?= e($k) ?>" data-tab-panel="<?= e($k) ?>" hidden><?= $t['html'] ?></div>
        <?php endforeach ?>
        <?= $main ?>
    </div>
    <aside class="min-w-0 space-y-6"><?= $side ?></aside>
</div>
