<?php /* One confirmation dialog for every delete: the person must type DELETE. Opened by [data-delete-url]. */ ?>
<dialog id="delete-dialog" data-msg-one="<?= e(__('You are about to delete the :kind “:name”. It will no longer show up in lists.')) ?>" data-msg-many="<?= e(__('You are about to delete :n :kind. They will no longer show up in lists.')) ?>" class="m-auto w-[min(28rem,calc(100vw-2rem))] rounded-xl bg-white p-0 text-graphite-900 shadow-2xl ring-1 ring-graphite-900/10 backdrop:bg-graphite-950/60" aria-labelledby="delete-title">
    <form method="POST" action="" data-no-busy>
        <?= csrf_field() ?>
        <div data-delete-ids></div>
        <div class="p-6">
            <div class="flex items-start gap-4">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-signal-50 text-signal-700"><?= icon('trash', 'size-5') ?></span>
                <div class="min-w-0">
                    <h2 id="delete-title" class="text-base font-semibold" data-delete-title><?= e(__('Delete')) ?></h2>
                    <p class="mt-1 text-sm text-steel" data-delete-text></p>
                </div>
            </div>
            <label for="delete-confirm" class="mt-5 block text-sm font-medium"><?= e(__('Type DELETE to confirm')) ?></label>
            <input id="delete-confirm" name="confirm" type="text" autocomplete="off" spellcheck="false" placeholder="DELETE" class="input mt-1.5 font-mono tracking-wide" data-delete-input>
        </div>
        <div class="flex justify-end gap-2 rounded-b-xl bg-mist/60 px-6 py-3">
            <button type="button" class="btn-secondary" data-delete-cancel><?= e(__('Cancel')) ?></button>
            <button class="btn bg-signal-600 text-white hover:bg-signal-700" disabled data-delete-go><?= icon('trash', 'size-4') ?> <?= e(__('Delete')) ?></button>
        </div>
    </form>
</dialog>
