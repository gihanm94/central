<?php /* The "Request" dialog of the sign-in pages. */
$field = function (string $name, string $label, string $type = 'text', array $o = []) { ob_start(); ?>
    <div class="<?= $o['span'] ?? '' ?>">
        <label for="rq-<?= e($name) ?>" class="mb-1 block text-xs font-medium text-graphite-300"><?= e($label) ?> <span class="text-signal-500">*</span></label>
        <input id="rq-<?= e($name) ?>" name="<?= e($name) ?>" type="<?= e($type) ?>" required maxlength="<?= (int) ($o['max'] ?? 190) ?>" autocomplete="<?= e($o['auto'] ?? 'off') ?>" class="input-dark" <?= ! empty($o['inputmode']) ? 'inputmode="'.e($o['inputmode']).'"' : '' ?> <?= ! empty($o['placeholder']) ? 'placeholder="'.e($o['placeholder']).'"' : '' ?>>
        <p class="mt-1 hidden text-xs font-medium text-signal-500" data-err="<?= e($name) ?>"></p>
    </div>
<?php return ob_get_clean(); };
?>
<dialog id="request-dialog" class="m-auto w-[min(34rem,calc(100vw-1.5rem))] rounded-2xl bg-graphite-900 p-0 text-white shadow-2xl ring-1 ring-white/10 backdrop:bg-black/70">
    <form id="request-form" method="POST" action="<?= url('/request-access') ?>" novalidate class="max-h-[90vh] overflow-y-auto p-6">
        <?= csrf_field() ?>
        <div class="flex items-start justify-between gap-3">
            <div><h2 class="font-display text-2xl font-semibold"><?= e(__('Request an account')) ?></h2>
                <p class="mt-1 text-sm text-graphite-400"><?= e(__('An administrator checks your details, chooses your department and role, and e-mails you. Nobody else sees your password.')) ?></p></div>
            <button type="button" data-request-close class="rounded-md p-1 text-graphite-400 hover:bg-white/10 hover:text-white" aria-label="<?= e(__('Close')) ?>">&times;</button>
        </div>
        <input type="text" name="website" value="" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">

        <div data-request-body class="mt-5 grid gap-4 sm:grid-cols-2">
            <?= $field('employee_code', __('Employee ID'), 'text', ['max' => 30]) ?>
            <?= $field('name', __('Full name'), 'text', ['max' => 120, 'auto' => 'name']) ?>
            <?= $field('email', __('Work e-mail'), 'email', ['auto' => 'email', 'inputmode' => 'email']) ?>
            <?= $field('phone', __('Phone'), 'tel', ['max' => 30, 'auto' => 'tel', 'inputmode' => 'tel']) ?>
            <div class="sm:col-span-2">
                <span class="mb-1 block text-xs font-medium text-graphite-300"><?= e(__('Gender')) ?> <span class="text-signal-500">*</span></span>
                <div class="flex flex-wrap gap-2" role="radiogroup">
                    <?php foreach (enum_options('genders') as $k => $l): ?>
                    <label class="cursor-pointer"><input type="radio" name="gender" value="<?= e($k) ?>" class="peer sr-only"><span class="inline-flex h-9 items-center rounded-full px-4 text-sm ring-1 ring-white/15 hover:bg-white/5 peer-checked:bg-signal-600 peer-checked:ring-signal-600 peer-focus-visible:outline-2 peer-focus-visible:outline-white"><?= e($l) ?></span></label>
                    <?php endforeach ?>
                </div>
                <p class="mt-1 hidden text-xs font-medium text-signal-500" data-err="gender"></p>
            </div>
            <?= $field('department_text', __('Department'), 'text', ['max' => 120, 'span' => 'sm:col-span-2', 'placeholder' => __('Type your department')]) ?>
            <?= $field('password', __('Password'), 'password', ['max' => 100, 'auto' => 'new-password']) ?>
            <?= $field('password_confirmation', __('Confirm password'), 'password', ['max' => 100, 'auto' => 'new-password']) ?>
            <p class="text-xs text-graphite-400 sm:col-span-2"><?= e(__('Use at least 8 characters with upper and lower case letters and a number.')) ?></p>
            <p class="hidden rounded-md bg-signal-600/15 px-3 py-2 text-sm text-signal-500 sm:col-span-2" data-err="_form"></p>
        </div>
        <div data-request-done class="mt-6 hidden rounded-xl bg-emerald-500/10 p-5 text-sm text-emerald-300"></div>

        <div class="mt-6 flex items-center justify-end gap-3">
            <button type="button" data-request-close class="btn bg-white/5 px-4 py-2 text-white ring-1 ring-white/15 hover:bg-white/10"><?= e(__('Close')) ?></button>
            <button type="submit" data-request-send class="btn-primary px-5 py-2"><?= e(__('Send request')) ?></button>
        </div>
    </form>
</dialog>
<script src="<?= asset('assets/request.js') ?>" defer></script>
