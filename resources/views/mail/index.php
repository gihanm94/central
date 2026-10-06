<?php /* $connected, $configured, $label, $labels, $q, $data, $error, $email, $page */
$link = fn (array $e = []) => url('/mail', array_filter(['label' => $label !== 'INBOX' ? $label : null, 'q' => $q] + $e, fn ($v) => $v !== null && $v !== ''));
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0"><h1 class="page-title"><?= e(__('Mail')) ?></h1><p class="mt-1 text-sm text-steel"><?= $connected ? e($email) : e(__('Your Gmail, inside the system.')) ?></p></div>
    <?php if ($connected): ?><button type="button" class="btn-primary" data-compose><?= icon('mail', 'size-4') ?> <?= e(__('Compose')) ?></button><?php endif ?>
</div>
<?php if (! $connected): ?>
<div class="panel mt-4 p-8 text-center"><p class="text-base font-medium"><?= e(__('Connect your Google account to see your e-mail here.')) ?></p>
    <?php if ($configured): ?><a href="<?= url('/connect/google', ['back' => 'mail']) ?>" class="btn-primary mt-4"><?= icon('link', 'size-4') ?> <?= e(__('Connect Google')) ?></a>
    <?php else: ?><p class="mt-2 text-sm text-steel"><?= e(__('The administrator has not set up the Google connector yet.')) ?></p><?php endif ?></div>
<?php else: ?>
<div class="mt-4 flex flex-wrap items-center gap-2">
    <nav class="inline-flex gap-1 rounded-lg bg-white p-1 shadow-sm ring-1 ring-graphite-900/10"><?php foreach ($labels as $k => $l): ?><a href="<?= url('/mail', array_filter(['label' => $k !== 'INBOX' ? $k : null])) ?>" class="rounded-md px-3 py-1.5 text-sm <?= $k === $label ? 'bg-signal-600 font-medium text-white' : 'text-steel hover:bg-mist' ?>"><?= e(__($l)) ?></a><?php endforeach ?></nav>
    <form method="GET" action="<?= url('/mail') ?>" class="ml-auto flex items-center gap-2"><?php if ($label !== 'INBOX'): ?><input type="hidden" name="label" value="<?= e($label) ?>"><?php endif ?><input name="q" value="<?= e($q) ?>" class="input !h-9 w-64" placeholder="<?= e(__('Search mail…')) ?>"><button class="btn-secondary !h-9"><?= e(__('Search')) ?></button></form>
</div>
<?php if ($error): ?><p class="mt-3 rounded-lg bg-signal-50 px-4 py-3 text-sm text-signal-800"><?= e($error) ?></p><?php endif ?>
<section class="panel mt-3 overflow-hidden"><ul class="divide-y divide-graphite-900/6">
    <?php foreach ($data['rows'] as $m): preg_match('/^(.*?)\s*<([^>]+)>$/', $m['from'], $f); $name = trim((string) ($f[1] ?? ''), "\" ") ?: ($f[2] ?? $m['from']); ?>
    <li><a href="<?= url('/mail/'.$m['id']) ?>" class="flex items-center gap-4 px-4 py-3 hover:bg-mist/50 <?= $m['unread'] ? 'bg-signal-50/30' : '' ?>">
        <span class="w-44 shrink-0 truncate text-sm <?= $m['unread'] ? 'font-semibold' : '' ?>"><?= e($name) ?></span>
        <span class="min-w-0 flex-1 truncate text-sm"><span class="<?= $m['unread'] ? 'font-semibold' : '' ?>"><?= e($m['subject'] ?: '('.__('no subject').')') ?></span> <span class="text-steel">— <?= e($m['snippet']) ?></span></span>
        <span class="shrink-0 text-xs text-steel"><?= $m['date'] ? e(date('Y-m-d', strtotime($m['date'])) === date('Y-m-d') ? date('H:i', strtotime($m['date'])) : date('d M', strtotime($m['date']))) : '' ?></span></a></li>
    <?php endforeach ?>
    <?php if (! $data['rows'] && ! $error): ?><li class="px-5 py-10 text-center text-sm text-steel"><?= e(__('Nothing here.')) ?></li><?php endif ?>
</ul>
<?php if ($data['next'] || $page > 1): ?><div class="flex items-center justify-between border-t border-graphite-900/8 px-4 py-2.5 text-sm text-steel"><a class="btn-secondary !h-8 !px-3" href="javascript:history.back()">‹ <?= e(__('Newer')) ?></a><span><?= (int) $data['estimate'] ?>+</span>
    <a class="btn-secondary !h-8 !px-3 <?= $data['next'] ? '' : 'pointer-events-none opacity-40' ?>" href="<?= e($link(['t' => $data['next'], 'p' => $page + 1])) ?>"><?= e(__('Older')) ?> ›</a></div><?php endif ?>
</section>
<?= partial('mail/compose', ['reply' => null]) ?>
<?php endif ?>
