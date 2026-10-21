<?php
/* Chips + a dialog to pick up to $f['max'] items (ui.js [data-picker]).
   $name, $picked [ids], $err, $f: choices [id => ['label', 'color']], max, search (placeholder), group (list heading),
   add (dialog button), empty (big empty box text, or null for a plain "Add" chip), none (nothing to choose from) */
$choices = $f['choices'];
$picked  = array_values(array_filter($picked, fn ($id) => isset($choices[$id])));
$max     = (int) ($f['max'] ?? 0);
$opts    = [];
foreach ($choices as $id => $c) { $opts[(string) $id] = ['label' => (string) $c['label'], 'color' => preg_match('/^#[0-9a-f]{6}$/i', (string) ($c['color'] ?? '')) ? $c['color'] : '#8a909a']; }
$chip = fn (string $id) => '<span data-chip="'.e($id).'" class="inline-flex h-7 items-center gap-1.5 rounded-md pl-2 pr-1 text-sm font-medium" style="background:'.$opts[$id]['color'].'1f;color:color-mix(in srgb,'.$opts[$id]['color'].' 78%,#000);box-shadow:inset 0 0 0 1px '.$opts[$id]['color'].'59">'
    .'<span class="size-1.5 rounded-full" style="background:'.$opts[$id]['color'].'"></span><span>'.e($opts[$id]['label']).'</span>'
    .'<button type="button" class="ml-0.5 inline-flex size-5 items-center justify-center rounded opacity-70 hover:opacity-100" data-chip-x aria-label="'.e(__('Remove').' '.$opts[$id]['label']).'">&times;</button></span>';
?>
<div data-picker data-name="<?= e($name) ?>" data-max="<?= $max ?>" data-msg-remove="<?= e(__('Remove')) ?>" aria-labelledby="lbl-<?= e($name) ?>">
    <script type="application/json" data-picker-options><?= json_encode($opts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <div data-picker-values><?php foreach ($picked as $id): ?><input type="hidden" name="<?= e($name) ?>[]" value="<?= e($id) ?>"><?php endforeach ?></div>

    <div data-picker-chips <?= $picked ? '' : 'hidden' ?> class="flex min-h-[4.5rem] flex-wrap content-start items-start gap-2 rounded-lg bg-mist/60 p-3 ring-1 <?= $err ? 'ring-signal-600' : 'ring-graphite-900/10' ?>">
        <?php foreach ($picked as $id): ?><?= $chip($id) ?><?php endforeach ?>
        <button type="button" data-picker-open data-picker-add class="inline-flex h-7 items-center gap-1 rounded-md border border-dashed border-graphite-900/25 bg-white px-2 text-sm text-steel hover:border-graphite-900/50 hover:text-graphite-900"><?= icon('plus', 'size-3.5') ?> <?= e(__('Add')) ?></button>
    </div>
    <button type="button" data-picker-open data-picker-empty <?= $picked ? 'hidden' : '' ?> class="flex w-full flex-col items-center justify-center gap-2 rounded-lg bg-mist/60 px-4 py-6 text-steel ring-1 <?= $err ? 'ring-signal-600' : 'ring-graphite-900/10' ?> hover:bg-mist hover:text-graphite-900">
        <span class="flex size-10 items-center justify-center rounded-full border border-dashed border-graphite-900/25"><?= icon('plus', 'size-5') ?></span>
        <span class="text-sm font-medium"><?= e($f['empty'] ?? __('Click to add')) ?></span>
    </button>
    <?php if (! $choices): ?><p class="hint"><?= e($f['none'] ?? __('Nothing to choose from yet.')) ?></p><?php endif ?>
    <?php if (! empty($f['help'])): ?><p class="hint"><?= e($f['help']) ?></p><?php endif ?>
    <?php if ($err): ?><p class="error"><?= e($err) ?></p><?php endif ?>

    <dialog data-picker-dialog class="m-auto w-[min(24rem,calc(100vw-2rem))] overflow-hidden rounded-xl bg-white p-0 text-graphite-900 shadow-2xl ring-1 ring-graphite-900/10 backdrop:bg-graphite-950/60" aria-label="<?= e($f['label']) ?>">
        <div class="p-2">
            <label class="relative block">
                <span class="pointer-events-none absolute inset-y-0 left-2.5 flex items-center text-graphite-400"><?= icon('search', 'size-4') ?></span>
                <input type="search" data-picker-search class="input pl-8" placeholder="<?= e($f['search'] ?? __('Search…')) ?>" aria-label="<?= e($f['search'] ?? __('Search…')) ?>" autocomplete="off">
            </label>
        </div>
        <div class="max-h-[22rem] overflow-y-auto px-2 pb-2">
            <p data-picker-sel-head class="px-2.5 pb-1 pt-2 text-xs font-medium text-steel"><?= e(__('Selected')) ?></p>
            <div data-picker-group="selected" class="space-y-0.5"></div>
            <p data-picker-rest-head class="px-2.5 pb-1 pt-3 text-xs font-medium text-steel"><?= e($f['group'] ?? $f['label']) ?></p>
            <div data-picker-group="rest" class="space-y-0.5"></div>
            <p data-picker-none hidden class="px-2.5 py-6 text-center text-sm text-steel"><?= e(__('Nothing found.')) ?></p>
        </div>
        <div class="flex items-center justify-between gap-3 border-t border-graphite-900/8 px-4 py-3">
            <span class="text-sm text-steel"><span class="tabular-nums" data-picker-count>0</span> <?= e(__('selected')) ?></span>
            <div class="flex items-center gap-2">
                <button type="button" class="btn-ghost" data-picker-cancel><?= e(__('Cancel')) ?></button>
                <button type="button" class="btn-primary" data-picker-done><?= icon('plus', 'size-4') ?> <?= e($f['add'] ?? __('Add')) ?></button>
            </div>
        </div>
    </dialog>
</div>
