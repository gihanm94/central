<?php /* $reply: array|null [to, subject, thread, in_reply_to, references] — dialog opened by [data-compose] */ ?>
<dialog id="compose" class="m-auto w-[min(40rem,calc(100vw-2rem))] rounded-xl bg-white p-0 shadow-2xl ring-1 ring-graphite-900/10 backdrop:bg-graphite-950/60">
    <form method="POST" action="<?= url('/mail/send') ?>" class="flex flex-col"><?= csrf_field() ?>
        <input type="hidden" name="thread" value="<?= e($reply['thread'] ?? '') ?>"><input type="hidden" name="in_reply_to" value="<?= e($reply['in_reply_to'] ?? '') ?>"><input type="hidden" name="references" value="<?= e($reply['references'] ?? '') ?>">
        <header class="flex items-center justify-between border-b border-graphite-900/8 px-5 py-3"><h2 class="text-base font-semibold"><?= e($reply ? __('Reply') : __('New message')) ?></h2><button type="button" class="btn-ghost !px-2" data-compose-close aria-label="<?= e(__('Close')) ?>">&times;</button></header>
        <div class="space-y-3 p-5">
            <div><label class="label" for="c-to"><?= e(__('To')) ?></label><input id="c-to" name="to" required class="input" value="<?= e($reply['to'] ?? '') ?>" placeholder="name@example.com"></div>
            <div><label class="label" for="c-cc">Cc</label><input id="c-cc" name="cc" class="input"></div>
            <div><label class="label" for="c-sub"><?= e(__('Subject')) ?></label><input id="c-sub" name="subject" required class="input" value="<?= e($reply['subject'] ?? '') ?>"></div>
            <div><label class="label" for="c-body"><?= e(__('Message')) ?></label><textarea id="c-body" name="body" rows="9" required class="input"></textarea></div>
        </div>
        <footer class="flex justify-end gap-2 border-t border-graphite-900/8 bg-mist/50 px-5 py-3"><button type="button" class="btn-secondary" data-compose-close><?= e(__('Cancel')) ?></button><button class="btn-primary"><?= icon('send', 'size-4') ?> <?= e(__('Send')) ?></button></footer>
    </form>
</dialog>
<script>
(function () { var d = document.getElementById('compose'); if (!d) return;
    document.addEventListener('click', function (e) { if (e.target.closest('[data-compose]')) { d.showModal(); var t = d.querySelector('#c-to'); if (t && !t.value) t.focus(); else d.querySelector('#c-body').focus(); } if (e.target.closest('[data-compose-close]') || e.target === d) d.close(); }); })();
</script>
