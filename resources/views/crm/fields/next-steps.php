<?php
/* Next steps typed while creating / editing an opportunity (crm.js [data-steps]); saved as opportunity_steps.
   Steps already there are listed; ticking them off is done on the detail page. $name, $f (existing), $row, $err */
$existing = $f['existing'] ?? [];
$new = array_values(array_filter((array) old('next_steps', []), fn ($s) => trim((string) ($s['title'] ?? '')) !== ''));
$item = function ($i, array $s) { ob_start(); ?>
    <li data-step-line class="flex items-start gap-3 rounded-lg bg-white px-3 py-2.5 ring-1 ring-graphite-900/10">
        <input type="hidden" name="next_steps[<?= e($i) ?>][title]" value="<?= e($s['title'] ?? '') ?>"><input type="hidden" name="next_steps[<?= e($i) ?>][due_at]" value="<?= e($s['due_at'] ?? '') ?>"><input type="hidden" name="next_steps[<?= e($i) ?>][note]" value="<?= e($s['note'] ?? '') ?>">
        <span class="mt-0.5 size-4 shrink-0 rounded border border-graphite-900/25"></span>
        <span class="min-w-0 flex-1"><span class="block text-sm font-medium" data-s-title><?= e($s['title'] ?? '') ?></span>
            <span class="block text-xs text-steel" data-s-sub><?= e(implode(' · ', array_filter([($s['due_at'] ?? '') ? format_date($s['due_at']) : '', $s['note'] ?? '']))) ?></span></span>
        <button type="button" data-step-remove class="btn-ghost shrink-0 px-2 py-1 hover:text-signal-700" aria-label="<?= e(__('Remove')) ?>"><?= icon('trash', 'size-4') ?></button>
    </li>
<?php return ob_get_clean(); };
?>
<div data-steps data-counter="<?= count($new) ?>" data-msg-title="<?= e(__('Type what needs to be done.')) ?>">
    <div class="flex items-center justify-between gap-3">
        <div class="flex items-center gap-2"><span class="flex size-7 items-center justify-center rounded-md bg-signal-50 text-signal-700"><?= icon('tasks', 'size-4') ?></span><span class="text-sm font-semibold"><?= e(__('Next steps')) ?></span></div>
        <button type="button" data-step-open class="inline-flex h-8 items-center gap-1.5 rounded-md px-2.5 text-sm font-medium text-signal-700 ring-1 ring-signal-600/40 hover:bg-signal-50"><?= icon('plus', 'size-4') ?> <?= e(__('Add step')) ?></button>
    </div>
    <div data-step-form hidden class="mt-3 rounded-lg bg-signal-50/40 p-4 ring-1 ring-signal-600/30">
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="sm:col-span-2"><span class="label"><?= e(__('Next step')) ?> <span class="text-signal-600">*</span></span><input data-s="title" maxlength="200" class="input" placeholder="<?= e(__('e.g. Send the quotation')) ?>"></label>
            <label><span class="label"><?= e(__('Due date')) ?></span><input data-s="due_at" type="date" class="input"></label>
            <label><span class="label"><?= e(__('Note')) ?></span><input data-s="note" maxlength="2000" class="input"></label>
        </div>
        <p data-s-error hidden class="error"></p>
        <div class="mt-3 flex justify-end gap-2"><button type="button" class="btn-ghost" data-step-cancel><?= e(__('Cancel')) ?></button><button type="button" class="btn-primary" data-step-add><?= icon('plus', 'size-4') ?> <?= e(__('Add')) ?></button></div>
    </div>
    <ul class="mt-3 space-y-2">
        <?php foreach ($existing as $s): ?>
        <li class="flex items-start gap-3 rounded-lg bg-mist/50 px-3 py-2.5 text-sm">
            <span class="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded <?= $s['is_done'] ? 'bg-emerald-600 text-white' : 'border border-graphite-900/25' ?>"><?= $s['is_done'] ? icon('tick', 'size-3') : '' ?></span>
            <span class="min-w-0 flex-1"><span class="block <?= $s['is_done'] ? 'text-steel line-through' : 'font-medium' ?>"><?= e($s['title']) ?></span>
                <span class="block text-xs text-steel"><?= e(implode(' · ', array_filter([$s['due_at'] ? format_date($s['due_at']) : '', (string) $s['note']]))) ?></span></span>
        </li>
        <?php endforeach ?>
    </ul>
    <ul data-step-list class="mt-2 space-y-2"><?php foreach ($new as $i => $s) { echo $item($i, $s); } ?></ul>
    <div data-step-empty <?= $existing || $new ? 'hidden' : '' ?> class="mt-3 flex flex-col items-center gap-2 rounded-lg border border-dashed border-graphite-900/15 px-4 py-8 text-sm text-steel"><?= icon('tasks', 'size-7 text-graphite-300') ?><?= e(__('No next steps yet')) ?></div>
    <?php if ($existing): ?><p class="hint mt-2"><?= e(__('Tick steps off on the opportunity page.')) ?></p><?php endif ?>
    <template data-step-template><?= $item('__i__', []) ?></template>
</div>
<?php if ($err): ?><p class="error"><?= e($err) ?></p><?php endif ?>
