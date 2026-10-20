<?php /* The "Result of the presentation" dialog, opened by [data-eb-decide="request id"] buttons (with data-eb-title, data-eb-kind). */ ?>
<dialog id="eb-decide" data-light-dismiss class="m-auto w-[min(30rem,calc(100vw-2rem))] rounded-xl bg-white p-0 text-graphite-900 shadow-2xl ring-1 ring-graphite-900/10 backdrop:bg-graphite-950/60" aria-labelledby="eb-decide-title">
    <form method="POST" action="" data-eb-decide-form>
        <?= csrf_field() ?>
        <div class="border-b border-graphite-900/8 px-5 py-3.5"><h2 id="eb-decide-title" class="text-base font-semibold"><?= e(__('Result of the presentation')) ?></h2>
            <p class="mt-0.5 text-sm text-steel"><span data-eb-d-title></span> · <span data-eb-d-kind></span></p></div>
        <div class="space-y-4 px-5 py-4">
            <div class="grid grid-cols-2 gap-2">
                <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-graphite-900/15 p-3 has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50"><input type="radio" name="result" value="approved" checked class="size-4 accent-emerald-600"> <?= e(__('Approved')) ?></label>
                <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-graphite-900/15 p-3 has-[:checked]:border-signal-600 has-[:checked]:bg-signal-50"><input type="radio" name="result" value="rejected" class="size-4 accent-signal-600"> <?= e(__('Not approved')) ?></label>
            </div>
            <div><label class="label" for="eb-d-note"><?= e(__('Comment for the team')) ?> <span class="font-normal text-steel">(<?= e(__('optional')) ?>)</span></label><textarea id="eb-d-note" name="note" rows="3" class="input"></textarea></div>
            <p class="text-xs text-steel"><?= e(__('Approved initiation approves the project; approved closure closes it; approved extension moves its end date. The team is told.')) ?></p>
        </div>
        <div class="flex justify-end gap-2 rounded-b-xl bg-mist/60 px-5 py-3"><button type="button" class="btn-secondary" data-close-dialog><?= e(__('Cancel')) ?></button><button class="btn-primary"><?= e(__('Save result')) ?></button></div>
    </form>
</dialog>
<script>
document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-eb-decide]'); if (!b) return;
    var f = document.querySelector('[data-eb-decide-form]');
    f.action = <?= json_encode(url('/management/everbetter/requests')) ?> + '/' + b.dataset.ebDecide + '/decide';
    f.querySelector('[data-eb-d-title]').textContent = b.dataset.ebTitle;
    f.querySelector('[data-eb-d-kind]').textContent = b.dataset.ebKind;
    f.reset();
});
</script>
