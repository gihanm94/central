<?php
/* Pick several people: $f['people'] = [id => ['name', 'department']], $f['selected'] = ids ticked at the start */
$picked = array_map('strval', (array) old($name, $f['selected'] ?? []));
?>
<span class="label"><?= e($f['label']) ?></span>
<div data-people class="overflow-hidden rounded-lg bg-white ring-1 ring-graphite-900/15">
    <div class="border-b border-graphite-900/10 p-2"><input type="search" data-people-filter class="input" placeholder="<?= e(__('Search people…')) ?>" aria-label="<?= e(__('Search people…')) ?>"></div>
    <div class="max-h-56 overflow-y-auto">
        <?php foreach ($f['people'] as $id => $p): $on = in_array((string) $id, $picked, true); ?>
        <label data-people-row data-q="<?= e(mb_strtolower($p['name'].' '.($p['department'] ?? ''))) ?>" class="flex cursor-pointer items-center gap-3 px-3 py-1.5 text-sm hover:bg-mist/70">
            <input type="checkbox" name="<?= e($name) ?>[]" value="<?= (int) $id ?>" class="size-4 accent-signal-600" <?= $on ? 'checked' : '' ?>>
            <span class="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-graphite-900/6 text-[10px] font-semibold text-graphite-700"><?= e(initials($p['name'])) ?></span>
            <span class="min-w-0 truncate"><?= e($p['name']) ?><?php if (! empty($p['department'])): ?> <span class="text-xs text-steel">· <?= e($p['department']) ?></span><?php endif ?></span>
        </label>
        <?php endforeach ?>
    </div>
    <p class="border-t border-graphite-900/10 bg-mist/50 px-3 py-1.5 text-xs text-steel"><span class="font-semibold text-graphite-900" data-people-count>0</span> <?= e(__('selected')) ?></p>
</div>
<?php if (! empty($f['help'])): ?><p class="hint"><?= e($f['help']) ?></p><?php endif ?>
<?php if ($err): ?><p class="error"><?= e($err) ?></p><?php endif ?>
