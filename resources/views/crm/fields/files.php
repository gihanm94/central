<?php use App\Modules\CRM\Support\Attachments; /* $name, $f, $row, $err */ ?>
<label for="f-files" class="label"><?= e($f['label']) ?> <span class="font-normal text-steel">(<?= e(__('optional')) ?>)</span></label>
<input id="f-files" name="files[]" type="file" multiple class="w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-graphite-900 file:px-3 file:py-1.5 file:text-sm file:text-white">
<p class="hint"><?= e(__('Up to :mb MB each: :types.', ['mb' => Attachments::MAX_BYTES / 1048576, 'types' => implode(', ', Attachments::extensions())])) ?><?= $row ? ' '.e(__('Files already attached stay; manage them on the detail page.')) : '' ?></p>
<?php if ($err): ?><p class="error"><?= e($err) ?></p><?php endif ?>
