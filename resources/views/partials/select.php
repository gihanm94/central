<?php
/*
 | Tailwind dropdown that replaces <select>. Posts like a select (hidden input).
 | $name, $options [value => label], $value, $o: placeholder, id, submit, disabled, class, search, tones, size, placement
 */
$id       = $o['id'] ?? 'sel-'.preg_replace('/\W+/', '-', $name).'-'.substr(md5(uniqid('', true)), 0, 5);
$rich     = $options && is_array(reset($options));          // options like ['label' => …, 'sub' => …, 'img' => url]
$hasValue = $value !== '' && array_key_exists($value, $options);
$plain    = fn ($opt) => is_array($opt) ? (string) $opt['label'] : (string) $opt;
$richHtml = function ($opt) {
    $img = ! empty($opt['img'])
        ? '<img src="'.e($opt['img']).'" alt="" class="size-8 shrink-0 rounded bg-white object-contain ring-1 ring-graphite-900/10">'
        : '<span class="inline-flex size-8 shrink-0 items-center justify-center rounded bg-graphite-900/6 text-[11px] font-semibold text-graphite-700">'.e(initials((string) $opt['label'])).'</span>';

    return '<span class="flex min-w-0 items-center gap-2.5">'.$img.'<span class="min-w-0 text-left"><span class="block truncate leading-tight">'.e($opt['label']).'</span>'
        .(! empty($opt['sub']) ? '<span class="block truncate text-xs leading-tight text-steel">'.e($opt['sub']).'</span>' : '').'</span></span>';
};
$label    = $hasValue ? $plain($options[$value]) : ($o['placeholder'] ?? __('Choose…'));
$tones    = $o['tones'] ?? [];
$search   = $o["search"] ?? ($rich || count($options) > 8);
$sm       = ($o['size'] ?? '') === 'sm';
$base     = $o['class'] ?? ($sm ? 'rounded-md px-2 py-1 text-xs ring-1 ring-graphite-900/15 bg-white' : ($rich ? 'input h-auto min-h-8 py-1' : 'input'));
?>
<div class="relative <?= e($o['wrap'] ?? '') ?>" data-select <?= ! empty($o['submit']) ? 'data-submit' : '' ?>>
    <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>" data-select-input>
    <button type="button" id="<?= e($id) ?>" data-select-button data-base="<?= e($base) ?>" aria-haspopup="listbox" aria-expanded="false"
            <?= ! empty($o['disabled']) ? 'disabled' : '' ?> <?= isset($o['aria']) ? 'aria-label="'.e($o['aria']).'"' : '' ?>
            class="<?= e($base.' '.($tones[$value] ?? '')) ?> flex w-full items-center justify-between gap-2 text-left disabled:cursor-not-allowed disabled:opacity-70">
        <span data-select-label class="min-w-0 flex-1 truncate <?= $hasValue ? '' : 'text-graphite-400' ?>"><?= $rich && $hasValue ? $richHtml($options[$value]) : e($label) ?></span>
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
                <button type="button" role="option" data-value="<?= e($ov) ?>" data-label="<?= e($plain($ol)) ?>" <?= $rich ? 'data-search="'.e($plain($ol).' '.($ol['sub'] ?? '')).'"' : '' ?> aria-selected="<?= $sel ? 'true' : 'false' ?>" <?= isset($tones[$ov]) ? 'data-tone="'.e($tones[$ov]).'"' : '' ?>
                        class="group flex w-full items-center justify-between gap-3 rounded-md px-2.5 py-1.5 text-left text-sm hover:bg-mist focus:bg-mist focus:outline-none aria-selected:font-medium">
                    <?php if ($rich): ?><span class="min-w-0" data-rich><?= $richHtml($ol) ?></span><?php else: ?><span class="truncate"><?= e($ol) ?></span><?php endif ?><?= icon('tick', 'size-4 shrink-0 text-signal-600 invisible group-aria-selected:visible') ?>
                </button>
            <?php endforeach ?>
            <p data-select-empty hidden class="px-2.5 py-2 text-sm text-steel"><?= e(__('No matches')) ?></p>
        </div>
    </div>
</div>
