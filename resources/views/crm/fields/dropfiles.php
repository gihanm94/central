<?php
use App\Modules\CRM\Support\Attachments;
/* Files dropped or chosen for the record (crm.js [data-dropfiles]): up to $f['max'] files in all, counting those already attached. $name, $f (max, existing), $row, $err */
$max = (int) ($f['max'] ?? 5); $have = $f['existing'] ?? [];
?>
<div data-dropfiles data-max="<?= $max - count($have) ?>" data-msg-full="<?= e(__('You can attach up to :n files.', ['n' => $max])) ?>">
    <label data-drop class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-graphite-900/15 bg-mist/40 px-4 py-10 text-center transition-colors hover:border-signal-600/40 <?= $err ? '!border-signal-600' : '' ?>">
        <span class="flex size-12 items-center justify-center rounded-full bg-graphite-900/6 text-graphite-700"><?= icon('upload', 'size-6') ?></span>
        <span class="text-sm font-semibold"><?= e(__('Drop files here or click to browse')) ?></span>
        <span class="text-xs text-steel"><span data-count><?= count($have) ?></span> / <?= $max ?> <?= e(__('files used')) ?></span>
        <span class="btn-secondary mt-1 !h-8 text-sm"><?= icon('clip', 'size-4') ?> <?= e(__('Choose file')) ?></span>
        <input type="file" name="files[]" multiple class="sr-only" data-files-input data-have="<?= count($have) ?>">
    </label>
    <p class="hint"><?= e(__('Up to :mb MB each: :types.', ['mb' => Attachments::MAX_BYTES / 1048576, 'types' => implode(', ', Attachments::extensions())])) ?></p>
    <ul class="mt-3 space-y-1.5" data-files-list>
        <?php foreach ($have as $a): ?>
        <li class="flex items-center gap-2 rounded-md bg-mist/50 px-3 py-2 text-sm"><?= icon('doc', 'size-4 text-steel') ?><span class="min-w-0 flex-1 truncate"><?= e($a['original_name'] ?? '') ?></span><span class="text-xs text-steel"><?= e(__('Attached')) ?></span></li>
        <?php endforeach ?>
    </ul>
</div>
<?php if ($err): ?><p class="error"><?= e($err) ?></p><?php endif ?>
