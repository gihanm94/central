<?php $dot = ['todo' => 'bg-graphite-400', 'in_progress' => 'bg-sky-500', 'review' => 'bg-amber-500', 'done' => 'bg-emerald-600'][$s] ?? 'bg-graphite-400'; ?>
<span class="inline-flex items-center gap-1.5 text-sm"><span class="size-2 rounded-full <?= $dot ?>"></span><?= e($labels[$s] ?? $s) ?></span>
