<?php
use App\Core\Support\Session;
$tones = ['success' => 'border-emerald-600 bg-emerald-50 text-emerald-900', 'status' => 'border-graphite-600 bg-white text-graphite-900', 'error' => 'border-signal-600 bg-signal-50 text-signal-800'];
foreach ($tones as $key => $tone):
    if ($msg = Session::get($key)): ?>
    <div data-dismissible role="status" class="mb-5 flex items-start justify-between gap-4 rounded-md border-l-4 px-4 py-3 text-sm <?= $tone ?>">
        <span><?= e($msg) ?></span>
        <button type="button" data-dismiss class="opacity-60 hover:opacity-100" aria-label="<?= e(__('Dismiss')) ?>"><?= icon('x', 'size-4') ?></button>
    </div>
<?php endif; endforeach;
$errors = Session::errors();
if ($errors && ! ($hideErrorSummary ?? false)): ?>
    <div class="mb-5 rounded-md border-l-4 border-signal-600 bg-signal-50 px-4 py-3 text-sm text-signal-800">
        <?= count($errors) > 1 ? e(__('Please fix the highlighted fields.')) : e(reset($errors)) ?>
    </div>
<?php endif ?>
