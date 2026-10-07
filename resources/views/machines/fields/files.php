<?php
use App\Modules\Machines\Support\Files;
/* Several documents: the ones already saved (untick to remove) and new ones to add. $name, $f ['column' => json column], $row, $err */
$col  = $f['column'];
$cur  = Files::decode($row[$col] ?? null);
$post = old('keep_'.$col);
?>
<span class="label"><?= e($f['label']) ?></span>
<?php if ($cur): ?>
<ul class="mb-2 space-y-1">
    <?php foreach ($cur as $x): $on = is_array($post) ? in_array($x['f'], $post, true) : true; ?>
    <li class="flex items-center gap-2 rounded-md bg-mist/60 px-3 py-1.5 text-sm">
        <label class="flex min-w-0 flex-1 cursor-pointer items-center gap-2"><input type="checkbox" name="keep_<?= e($col) ?>[]" value="<?= e($x['f']) ?>" class="size-4 accent-signal-600" <?= $on ? 'checked' : '' ?>>
            <span class="truncate"><?= e($x['n']) ?></span></label>
        <a class="shrink-0 text-xs text-signal-700 underline" href="<?= e(url('/machines/file', ['f' => $x['f']])) ?>" target="_blank" rel="noopener"><?= e(__('Open')) ?></a>
        <span class="shrink-0 text-xs text-steel"><?= e(number_format($x['s'] / 1024, 0)) ?> KB</span>
    </li>
    <?php endforeach ?>
</ul>
<p class="hint mb-1"><?= e(__('Untick a document to remove it when you save.')) ?></p>
<?php endif ?>
<input name="<?= e($name) ?>[]" type="file" multiple class="w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-graphite-900 file:px-3 file:py-1.5 file:text-sm file:text-white">
<p class="hint"><?= e(__('Up to :mb MB each: :types.', ['mb' => Files::MAX_BYTES / 1048576, 'types' => implode(', ', Files::extensions())])) ?></p>
<?php if ($err): ?><p class="error"><?= e($err) ?></p><?php endif ?>
