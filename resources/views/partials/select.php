<?php
/*
 | Tailwind dropdown that replaces <select>. Posts like a select (hidden input).
 | $name, $options [value => label], $value, $o: placeholder, id, submit, disabled, class, search, tones, size, placement
 */
$id       = $o['id'] ?? 'sel-'.preg_replace('/\W+/', '-', $name).'-'.substr(md5(uniqid('', true)), 0, 5);
$hasValue = $value !== '' && array_key_exists($value, $options);
$label    = $hasValue ? $options[$value] : ($o['placeholder'] ?? __('Choose…'));
$tones    = $o['tones'] ?? [];
$search   = $o['search'] ?? count($options) > 8;
$sm       = ($o['size'] ?? '') === 'sm';
$base     = $o['class'] ?? ($sm ? 'rounded-md px-2 py-1 text-xs ring-1 ring-graphite-900/15 bg-white' : 'input');
?>
<div class="relative <?= e($o['wrap'] ?? '') ?>" data-select <?= ! empty($o['submit']) ? 'data-submit' : '' ?>>
    <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>" data-select-input>
    <button type="button" id="<?= e($id) ?>" data-select-button data-base="<?= e($base) ?>" aria-haspopup="listbox" aria-expanded="false"
            <?= ! empty($o['disabled']) ? 'disabled' : '' ?> <?= isset($o['aria']) ? 'aria-label="'.e($o['aria']).'"' : '' ?>
            class="<?= e($base.' '.($tones[$value] ?? '')) ?> flex w-full items-center justify-between gap-2 text-left disabled:cursor-not-allowed disabled:opacity-70">
        <span data-select-label class="truncate <?= $hasValue ? '' : 'text-graphite-400' ?>"><?= e($label) ?></span>
        <?= icon('updown', ($sm ? 'size-3.5' : 'size-4').' shrink-0 opacity-60') ?>
    </button>
    <div data-select-panel hidden role="listbox" aria-labelledby="<?= e($id) ?>" class="fixed z-[70] min-w-44 rounded-lg bg-white p-1 text-graphite-900 shadow-xl ring-1 ring-graphite-900/10">
        <?php if ($search): ?>
            <div class="p-1"><input type="text" data-select-search placeholder="<?= e(__('Search…')) ?>" class="w-full rounded-md border-0 bg-mist px-2.5 py-1.5 text-sm ring-0 focus:outline-none focus:ring-2 focus:ring-signal-600" autocomplete="off"></div>
        <?php endif ?>
        <div class="max-h-64 overflow-y-auto" data-select-list>
            <?php if (isset($o['placeholder']) && empty($o['required'])): ?>
                <button type="button" role="option" data-value="" data-label="<?= e($o['placeholder']) ?>" aria-selected="<?= $hasValue ? 'false' : 'true' ?>"
                        class="flex w-full items-center justify-between gap-3 rounded-md px-2.5 py-1.5 text-left text-sm text-steel hover:bg-mist focus:bg-mist focus:outline-none"><?= e($o['placeholder']) ?></button>
            <?php endif ?>
            <?php foreach ($options as $ov => $ol): $sel = $hasValue && (string) $ov === $value; ?>
                <button type="button" role="option" data-value="<?= e($ov) ?>" data-label="<?= e($ol) ?>" aria-selected="<?= $sel ? 'true' : 'false' ?>" <?= isset($tones[$ov]) ? 'data-tone="'.e($tones[$ov]).'"' : '' ?>
                        class="group flex w-full items-center justify-between gap-3 rounded-md px-2.5 py-1.5 text-left text-sm hover:bg-mist focus:bg-mist focus:outline-none aria-selected:font-medium">
                    <span class="truncate"><?= e($ol) ?></span><?= icon('tick', 'size-4 shrink-0 text-signal-600 invisible group-aria-selected:visible') ?>
                </button>
            <?php endforeach ?>
            <p data-select-empty hidden class="px-2.5 py-2 text-sm text-steel"><?= e(__('No matches')) ?></p>
        </div>
    </div>
</div>
