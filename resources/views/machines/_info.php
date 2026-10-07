<?php /* A grid of label / value pairs. $items = [[label, html|string, ['raw' => true, 'span' => 2]]] */ ?>
<dl class="grid sm:grid-cols-2 lg:grid-cols-3">
    <?php foreach ($items as $it): [$label, $val] = $it; $o = $it[2] ?? []; $empty = $val === null || $val === ''; ?>
    <div class="border-b border-graphite-900/6 px-5 py-3.5 <?= ($o['span'] ?? 1) === 2 ? 'sm:col-span-2' : (($o['span'] ?? 1) === 3 ? 'sm:col-span-2 lg:col-span-3' : '') ?>">
        <dt class="text-xs text-steel"><?= e($label) ?></dt>
        <dd class="mt-1 break-words text-sm <?= $empty ? 'text-graphite-400' : 'font-medium' ?>"><?= $empty ? '—' : (! empty($o['raw']) ? $val : '<span class="whitespace-pre-line">'.e((string) $val).'</span>') ?></dd>
    </div>
    <?php endforeach ?>
</dl>
