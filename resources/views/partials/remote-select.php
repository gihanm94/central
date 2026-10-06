<?php
/*
 | Dropdown whose options are fetched from the server while you search and scroll (see ui.js).
 | $name, $value, $current ['label','sub','img'] of the chosen row, $kind (leads|contacts|opportunities),
 | $o: placeholder, id, required, depends (name of another field that narrows the list), aria
 */
$id    = $o['id'] ?? 'rs-'.preg_replace('/\W+/', '-', $name);
$ph    = $o['placeholder'] ?? __('Choose…');
$has   = $value !== '' && $current;
$img   = $has && ! empty($current['img'])
    ? '<img src="'.e($current['img']).'" alt="" class="size-8 shrink-0 rounded bg-white object-contain ring-1 ring-graphite-900/10">'
    : ($has ? '<span class="inline-flex size-8 shrink-0 items-center justify-center rounded bg-graphite-900/6 text-[11px] font-semibold text-graphite-700">'.e(initials((string) $current['label'])).'</span>' : '');
?>
<div class="relative" data-select data-remote-select data-url="<?= e(url('/crm/search/'.$kind)) ?>" data-placeholder="<?= e($ph) ?>" data-msg-loading="<?= e(__('Loading…')) ?>" data-msg-none="<?= e(__('No matches')) ?>" data-msg-error="<?= e(__('Could not load. Try again.')) ?>" <?= ! empty($o['depends']) ? 'data-depends="'.e($o['depends']).'"' : '' ?>>
    <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>" data-select-input>
    <button type="button" id="<?= e($id) ?>" data-select-button data-base="input h-auto min-h-8 py-1" aria-haspopup="listbox" aria-expanded="false" <?= isset($o['aria']) ? 'aria-label="'.e($o['aria']).'"' : '' ?>
            class="input flex h-auto min-h-8 w-full items-center justify-between gap-2 py-1 text-left">
        <span data-select-label class="min-w-0 flex-1 truncate <?= $has ? '' : 'text-graphite-400' ?>"><?php if ($has): ?>
            <span class="flex min-w-0 items-center gap-2.5"><?= $img ?><span class="min-w-0 text-left"><span class="block truncate leading-tight"><?= e($current['label']) ?></span><?php if (! empty($current['sub'])): ?><span class="block truncate text-xs leading-tight text-steel"><?= e($current['sub']) ?></span><?php endif ?></span></span>
        <?php else: ?><?= e($ph) ?><?php endif ?></span>
        <?= icon('updown', 'size-4 shrink-0 opacity-60') ?>
    </button>
    <div data-select-panel data-remote hidden role="listbox" aria-labelledby="<?= e($id) ?>" class="fixed z-[70] min-w-[min(30rem,92vw)] rounded-lg bg-white p-1 text-graphite-900 shadow-xl ring-1 ring-graphite-900/10">
        <div class="p-1"><input type="text" data-select-search placeholder="<?= e(__('Search…')) ?>" class="w-full rounded-md border-0 bg-mist px-2.5 py-1.5 text-sm ring-0 focus:outline-none focus:ring-2 focus:ring-signal-600" autocomplete="off"></div>
        <div class="max-h-72 overflow-y-auto" data-select-list>
            <?php if (empty($o['required'])): ?>
            <button type="button" role="option" data-value="" data-label="<?= e($ph) ?>" aria-selected="<?= $has ? 'false' : 'true' ?>" class="flex w-full items-center rounded-md px-2.5 py-1.5 text-left text-sm text-steel hover:bg-mist focus:bg-mist focus:outline-none"><?= e($ph) ?></button>
            <?php endif ?>
            <div data-remote-items></div>
            <p data-remote-status class="px-2.5 py-2 text-sm text-steel"></p>
        </div>
    </div>
</div>
