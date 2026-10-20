<?php
use App\Core\Support\Session;
// success / status / warning / error messages are shown as toasts (public/assets/toast.js), not as a banner at the top of the page;
// a line break in the message splits it into a title and a description
foreach (['success' => 'success', 'status' => 'info', 'warning' => 'warning', 'error' => 'error'] as $key => $type):
    if ($msg = Session::get($key)): ?>
    <div data-toast data-type="<?= $type ?>" hidden><?= e($msg) ?></div>
<?php endif; endforeach;
$errors = Session::errors();
// form errors: the fields are highlighted where they are, and a toast says what is wrong
if ($errors && ! ($hideErrorSummary ?? false)): ?>
    <div data-toast data-type="error" hidden><?= count($errors) > 1 ? e(__('Not saved: please fix the :n highlighted fields.', ['n' => count($errors)])) : e(__('Not saved: :reason', ['reason' => reset($errors)])) ?></div>
<?php endif ?>
