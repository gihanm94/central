<?php
use App\Core\Support\Session;
// success / status / error messages are shown as toasts (public/assets/toast.js), not as a banner at the top of the page
foreach (['success' => 'success', 'status' => 'info', 'error' => 'error'] as $key => $type):
    if ($msg = Session::get($key)): ?>
    <div data-toast data-type="<?= $type ?>" hidden><?= e($msg) ?></div>
<?php endif; endforeach;
$errors = Session::errors();
if ($errors && ! ($hideErrorSummary ?? false)): ?>
    <div class="mb-5 rounded-md border-l-4 border-signal-600 bg-signal-50 px-4 py-3 text-sm text-signal-800">
        <?= count($errors) > 1 ? e(__('Please fix the highlighted fields.')) : e(reset($errors)) ?>
    </div>
<?php endif ?>
