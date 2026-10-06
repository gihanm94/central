<?php $size ??= 'size-9'; $extra ??= ''; ?>
<?php if (! empty($avatar)): ?>
<img src="<?= e(upload_url($avatar)) ?>" alt="" class="<?= e($size.' '.$extra) ?> shrink-0 rounded-full object-cover">
<?php else: ?>
<span class="<?= e($size.' '.$extra) ?> inline-flex shrink-0 items-center justify-center rounded-full bg-graphite-700 text-xs font-semibold text-white"><?= e(initials((string) ($name ?? '?'))) ?></span>
<?php endif ?>
