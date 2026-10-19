<?php /* One person in an owner history line: $h (Responsible::history row), $k = from | to | by. Name as saved, user id, and "deleted" when the user is gone. */
$name = $h[$k.'_name'] ?? null;
$id   = $h[$k.'_id'] ?? null;
?><?php if ($name === null): ?>—<?php else: ?><span class="font-medium text-graphite-800"><?= e($name) ?></span><?php if ($id): ?> <span class="text-[11px] tabular-nums text-steel" title="<?= e(__('User ID')) ?>">#<?= (int) $id ?></span><?php endif ?><?php if (! empty($h[$k.'_gone'])): ?> <span class="rounded bg-graphite-900/6 px-1 text-[10px] text-steel"><?= e(__('deleted user')) ?></span><?php endif ?><?php endif ?>
