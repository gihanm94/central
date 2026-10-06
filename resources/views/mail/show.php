<?php /* $m, $doc, $hasImages */
preg_match('/<([^>]+)>/', $m['from'], $fm); $replyTo = $fm[1] ?? $m['from'];
$reply = ['to' => $replyTo, 'subject' => preg_match('/^re:/i', $m['subject']) ? $m['subject'] : 'Re: '.$m['subject'], 'thread' => $m['thread'], 'in_reply_to' => $m['message_id'], 'references' => $m['references']];
?>
<div class="flex flex-wrap items-center justify-between gap-3"><a href="<?= url('/mail') ?>" class="btn-ghost">‹ <?= e(__('Back to mail')) ?></a><button type="button" class="btn-primary" data-compose><?= icon('mail', 'size-4') ?> <?= e(__('Reply')) ?></button></div>
<section class="panel mt-3 overflow-hidden">
    <div class="border-b border-graphite-900/8 px-5 py-4"><h1 class="text-lg font-semibold"><?= e($m['subject'] ?: '('.__('no subject').')') ?></h1>
        <dl class="mt-2 grid gap-x-4 gap-y-0.5 text-sm sm:grid-cols-[auto_1fr]"><dt class="text-steel"><?= e(__('From')) ?></dt><dd><?= e($m['from']) ?></dd><dt class="text-steel"><?= e(__('To')) ?></dt><dd class="break-words"><?= e($m['to']) ?></dd>
            <?php if ($m['cc']): ?><dt class="text-steel">Cc</dt><dd class="break-words"><?= e($m['cc']) ?></dd><?php endif ?><dt class="text-steel"><?= e(__('Date')) ?></dt><dd><?= $m['date'] ? e(date('d M Y, H:i', strtotime($m['date']))) : '' ?></dd></dl>
        <?php if ($m['files']): ?><p class="mt-3 flex flex-wrap gap-2 text-xs"><?php foreach ($m['files'] as $f): ?><span class="rounded-md bg-mist px-2 py-1">📎 <?= e($f['name']) ?></span><?php endforeach ?><span class="self-center text-steel"><?= e(__('Open the message in Gmail to download attachments.')) ?></span></p><?php endif ?>
        <?php if ($hasImages): ?><p class="mt-3 text-xs text-steel"><?= e(__('Pictures from other websites are hidden to protect your privacy.')) ?> <a class="text-signal-700 hover:underline" href="<?= url('/mail/'.$m['id'], ['images' => 1]) ?>"><?= e(__('Show pictures')) ?></a></p><?php endif ?></div>
    <iframe sandbox title="<?= e(__('Message')) ?>" srcdoc="<?= e($doc) ?>" class="h-[65vh] w-full border-0 bg-white"></iframe>
</section>
<?= partial('mail/compose', ['reply' => $reply]) ?>
