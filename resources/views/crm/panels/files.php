<?php
use App\Modules\CRM\Support\Attachments;
/* $c, $row, $items, $canUpload, $canManage */
$base = url($c['base'].'/'.$row['id']); $me = auth();
?>
<section id="files" class="panel">
    <div class="panel-head"><h2 class="panel-title"><?= e(__('Attachments')) ?> <span class="ml-1 text-sm font-normal text-steel"><?= count($items) ?></span></h2></div>
    <?php if ($items): ?>
    <ul class="divide-y divide-graphite-900/6">
        <?php foreach ($items as $f):
            $viewable = str_starts_with((string) $f['mime'], 'image/') || $f['mime'] === 'application/pdf'; ?>
        <li class="flex items-center gap-3 px-5 py-3">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-md bg-mist text-steel"><?= icon(str_starts_with((string) $f['mime'], 'image/') ? 'photo' : 'clock', 'size-4') ?></span>
            <div class="min-w-0 flex-1">
                <a href="<?= $base ?>/files/<?= (int) $f['id'] ?>" class="block truncate text-sm font-medium hover:text-signal-700" title="<?= e($f['original_name']) ?>"><?= e($f['original_name']) ?></a>
                <p class="truncate text-xs text-steel"><?= e(Attachments::humanSize((int) $f['size'])) ?> · <?= e($f['uploader']) ?> · <?= e(time_ago($f['created_at'])) ?></p>
            </div>
            <?php if ($viewable): ?><a href="<?= $base ?>/files/<?= (int) $f['id'] ?>?view=1" target="_blank" rel="noopener" class="btn-ghost px-1.5 py-1" title="<?= e(__('Open')) ?>" aria-label="<?= e(__('Open :name', ['name' => $f['original_name']])) ?>"><?= icon('eye', 'size-4') ?></a><?php endif ?>
            <a href="<?= $base ?>/files/<?= (int) $f['id'] ?>" class="btn-ghost px-1.5 py-1" title="<?= e(__('Download')) ?>" aria-label="<?= e(__('Download :name', ['name' => $f['original_name']])) ?>"><?= icon('download', 'size-4') ?></a>
            <?php if ($canManage || (int) $f['uploaded_by'] === $me->id): ?>
            <form method="POST" action="<?= $base ?>/files/<?= (int) $f['id'] ?>/delete" data-confirm="<?= e(__('Remove this file?')) ?>"><?= csrf_field() ?>
                <button class="btn-ghost px-1.5 py-1 hover:text-signal-700" title="<?= e(__('Remove')) ?>" aria-label="<?= e(__('Remove :name', ['name' => $f['original_name']])) ?>"><?= icon('trash', 'size-4') ?></button></form>
            <?php endif ?>
        </li>
        <?php endforeach ?>
    </ul>
    <?php elseif (! $canUpload): ?>
        <p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('No files attached.')) ?></p>
    <?php endif ?>
    <?php if ($canUpload): ?>
    <form method="POST" action="<?= $base ?>/files" enctype="multipart/form-data" class="space-y-2 <?= $items ? 'border-t border-graphite-900/8' : '' ?> p-5">
        <?= csrf_field() ?>
        <label for="files-input" class="text-sm font-medium"><?= e(__('Add files')) ?></label>
        <input id="files-input" name="files[]" type="file" multiple required class="w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-graphite-900 file:px-3 file:py-1.5 file:text-sm file:text-white">
        <?= field_error('files') ?>
        <p class="hint"><?= e(__('Up to :mb MB each: :types.', ['mb' => Attachments::MAX_BYTES / 1048576, 'types' => implode(', ', Attachments::extensions())])) ?></p>
        <div class="flex justify-end"><button class="btn-dark py-1.5"><?= icon('upload', 'size-4') ?> <?= e(__('Upload')) ?></button></div>
    </form>
    <?php endif ?>
</section>
