<?php /* Drop zone for a logo / picture (ui.js [data-logo-drop]): round, or rounded square with $f['shape'] = 'square' (a person's photo).
   $name, $f (label, help, recommend, shape), $current (stored path or null), $err */
$round = ($f['shape'] ?? 'round') !== 'square'; ?>
<div class="flex flex-col items-center gap-1.5 text-center">
    <label data-logo-drop class="relative flex size-32 cursor-pointer flex-col items-center justify-center gap-1 overflow-hidden <?= $round ? 'rounded-full' : 'rounded-2xl' ?> border-2 border-dashed border-graphite-900/15 bg-mist/50 text-steel transition-colors hover:border-signal-600/50 hover:text-graphite-900 focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-signal-600 <?= $err ? '!border-signal-600' : '' ?>">
        <img data-logo-img src="<?= e(upload_url($current)) ?>" alt="" class="absolute inset-0 size-full bg-white <?= $round ? 'object-contain p-2' : 'object-cover' ?>" <?= $current ? '' : 'hidden' ?>>
        <span data-logo-ph class="flex flex-col items-center gap-1" <?= $current ? 'hidden' : '' ?>><?= icon('upload', 'size-6') ?><span class="text-xs font-medium"><?= e(__('Click or drop')) ?></span></span>
        <input type="file" name="<?= e($name) ?>" accept="image/png,image/jpeg,image/webp" class="sr-only" data-logo-input aria-label="<?= e($f['label']) ?>">
    </label>
    <p class="mt-1 text-xs text-steel"><?= e($f['help'] ?? __('PNG, JPG or WEBP')) ?></p>
    <?php if (! empty($f['recommend'])): ?><p class="text-xs text-steel"><?= e($f['recommend']) ?></p><?php endif ?>
    <?php if ($current): ?><p class="text-xs text-steel"><?= e(__('Click the picture to change it.')) ?></p><?php endif ?>
    <?php if ($err): ?><p class="error"><?= e($err) ?></p><?php endif ?>
</div>
