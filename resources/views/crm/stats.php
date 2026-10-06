<?php /* $cards: [label, value, sub, tone, icon, href] — shown to admin / BU manager only */
$tones = ['neutral' => 'bg-graphite-900/6 text-graphite-700', 'success' => 'bg-emerald-50 text-emerald-700', 'danger' => 'bg-signal-50 text-signal-700', 'info' => 'bg-sky-50 text-sky-700', 'warn' => 'bg-amber-50 text-amber-700', 'violet' => 'bg-violet-50 text-violet-700'];
?>
<section class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4 xl:grid-cols-<?= min(6, max(4, count($cards))) ?>" aria-label="<?= e(__('Statistics')) ?>" data-stats>
    <?php foreach ($cards as $k): $tag = ! empty($k['href']) ? 'a' : 'div'; ?>
    <<?= $tag ?> <?= $tag === 'a' ? 'href="'.e(url($k['href'])).'"' : '' ?> class="flex items-center gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-graphite-900/8 <?= $tag === 'a' ? 'transition-shadow hover:shadow-md' : '' ?>">
        <span class="flex size-10 shrink-0 items-center justify-center rounded-lg <?= $tones[$k['tone']] ?? $tones['neutral'] ?>"><?= icon($k['icon'], 'size-5') ?></span>
        <span class="min-w-0">
            <span class="block truncate text-xs text-steel"><?= e($k['label']) ?></span>
            <span class="block truncate text-xl font-semibold leading-tight tabular-nums <?= $k['tone'] === 'danger' ? 'text-signal-700' : '' ?>"><?= e($k['value']) ?></span>
            <?php if ($k['sub']): ?><span class="block truncate text-xs text-steel"><?= e($k['sub']) ?></span><?php endif ?>
        </span>
    </<?= $tag ?>>
    <?php endforeach ?>
</section>
