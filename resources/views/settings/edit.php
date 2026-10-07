<?php $canEdit = can('settings', 'edit'); ?>
<?php include __DIR__.'/_tabs.php'; $crumbs = []; ?>
<p class="mt-4 text-sm text-steel"><?= e(__('Your company name, logo, language and the picture people see when they sign in.')) ?></p>

<form method="POST" action="<?= url('/settings') ?>" enctype="multipart/form-data" class="mt-6 grid items-start gap-6 xl:grid-cols-5">
    <?= csrf_field() ?>
    <fieldset <?= $canEdit ? '' : 'disabled' ?> class="panel xl:col-span-3">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Brand')) ?></h2></div>
        <div class="space-y-5 p-5">
            <div><label for="company_name" class="label"><?= e(__('Company name')) ?></label><input id="company_name" name="company_name" value="<?= e(old('company_name', $brand['name'])) ?>" required class="input"><?= field_error('company_name') ?></div>
            <div><label for="login_banner" class="label"><?= e(__('Sign-in banner')) ?></label><input id="login_banner" name="login_banner" value="<?= e(old('login_banner', $brand['banner'])) ?>" maxlength="60" class="input"><p class="hint"><?= e(__('The name in the red band on the sign-in picture. Empty = the company name.')) ?></p></div>
            <div><label for="login_tagline" class="label"><?= e(__('Sign-in page message')) ?></label><input id="login_tagline" name="login_tagline" value="<?= e(old('login_tagline', $brand['tagline'])) ?>" maxlength="160" class="input"><p class="hint"><?= e(__('One short line shown under the company name.')) ?></p></div>
            <div><label for="support_email" class="label"><?= e(__('Support e-mail')) ?></label><input id="support_email" name="support_email" type="email" value="<?= e(old('support_email', $brand['support'])) ?>" placeholder="it-help@company.com" class="input"><p class="hint"><?= e(__('Shown on the sign-in page for people who need an account.')) ?></p><?= field_error('support_email') ?></div>
            <div><label for="default_locale" class="label"><?= e(__('Default language')) ?></label><?= select_field('default_locale', App\Core\Support\I18n::available(), old('default_locale', setting('default_locale', 'en')), ['id' => 'default_locale', 'required' => true]) ?><p class="hint"><?= e(__('For the sign-in page and anyone who has not picked a language.')) ?></p></div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <span class="label"><?= e(__('Logo')) ?></span>
                    <div class="flex h-24 items-center justify-center rounded-md bg-graphite-900 p-3">
                        <img id="logo-preview" src="<?= e($brand['logo'] ?? '') ?>" alt="" <?= $brand['logo'] ? '' : 'hidden' ?> class="max-h-full max-w-full object-contain">
                        <?php if (! $brand['logo']): ?><span class="text-xs text-graphite-400"><?= e(__('No logo')) ?></span><?php endif ?>
                    </div>
                    <input name="company_logo" type="file" accept="image/*" data-preview="#logo-preview" class="mt-2 w-full text-xs file:mr-2 file:rounded file:border-0 file:bg-graphite-900 file:px-2.5 file:py-1 file:text-white">
                    <p class="hint"><?= e(__('PNG or SVG with a transparent background suits the dark sidebar. Up to 2 MB.')) ?></p>
                    <label class="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" name="auth_logo" value="1" <?= $brand['auth_logo'] ? 'checked' : '' ?> class="accent-signal-600"> <?= e(__('Show the logo on the sign-in page')) ?></label>
                    <?php if ($brand['logo']): ?><label class="mt-1 flex items-center gap-2 text-xs text-steel"><input type="checkbox" name="remove_logo" value="1" class="accent-signal-600"> <?= e(__('Remove logo')) ?></label><?php endif ?>
                </div>
                <div>
                    <span class="label"><?= e(__('Sign-in picture')) ?></span>
                    <div class="relative h-24 overflow-hidden rounded-md bg-graphite-800">
                        <img id="login-preview" src="<?= e($brand['login_image'] ?? '') ?>" alt="" <?= $brand['login_image'] ? '' : 'hidden' ?> class="size-full object-contain">
                        <?php if (! $brand['login_image']): ?><span class="absolute inset-0 flex items-center justify-center gap-1.5 text-xs text-graphite-400"><?= icon('photo', 'size-4') ?> <?= e(__('Default texture')) ?></span><?php endif ?>
                    </div>
                    <input name="login_image" type="file" accept="image/jpeg,image/png,image/webp" data-preview="#login-preview" class="mt-2 w-full text-xs file:mr-2 file:rounded file:border-0 file:bg-graphite-900 file:px-2.5 file:py-1 file:text-white">
                    <p class="hint"><?= e(__('A wide photo of your site, plant or team, 1600 px or wider. Up to 5 MB.')) ?></p>
                    <?php if ($brand['login_image']): ?><label class="mt-1 flex items-center gap-2 text-xs text-steel"><input type="checkbox" name="remove_login_image" value="1" class="accent-signal-600"> <?= e(__('Use the default texture')) ?></label><?php endif ?>
                </div>
            </div>
            <?= field_error('branding') ?>
        </div>
        <?php if ($canEdit): ?>
            <div class="flex justify-end border-t border-graphite-900/8 px-5 py-3"><button class="btn-primary w-full sm:w-auto"><?= e(__('Save settings')) ?></button></div>
        <?php else: ?>
            <p class="border-t border-graphite-900/8 px-5 py-3 text-xs text-steel"><?= e(__('You can view these settings. Only administrators can change them.')) ?></p>
        <?php endif ?>
    </fieldset>

    <div class="space-y-6 xl:col-span-2">
        <div>
            <p class="label"><?= e(__('Sign-in page preview')) ?></p>
            <div class="grid aspect-[16/10] grid-cols-[1.15fr_0.85fr] overflow-hidden rounded-lg bg-graphite-950 ring-1 ring-graphite-900/10">
                <div class="relative overflow-hidden bg-graphite-800">
                    <?php if ($brand['login_image']): ?><img src="<?= e($brand['login_image']) ?>" alt="" class="absolute inset-0 size-full object-contain p-3"><?php endif ?>
                    <div class="absolute inset-0 bg-gradient-to-t from-graphite-950 to-transparent"></div>
                    <div class="absolute bottom-[22%] left-0 right-[8%] bg-signal-600 py-2 pl-4" style="clip-path: polygon(0 0,100% 0,94% 100%,0 100%)"><p class="truncate font-display text-lg font-bold text-white"><?= e($brand['banner']) ?></p></div>
                </div>
                <div class="flex flex-col justify-center gap-2 p-4"><div class="h-2.5 w-1/2 rounded bg-white/80"></div><div class="mt-2 h-5 rounded bg-graphite-800"></div><div class="h-5 rounded bg-graphite-800"></div><div class="mt-1 h-5 rounded bg-signal-600"></div></div>
            </div>
            <p class="mt-2 text-xs text-steel"><?= e(__('Save to see changes in the preview.')) ?></p>
        </div>

        <section class="panel">
            <div class="panel-head"><h2 class="panel-title"><?= e(__('E-mail delivery')) ?></h2></div>
            <div class="space-y-1 p-5 text-sm">
                <p><?= e(__('Driver')) ?>: <strong><?= e(config('mail.driver')) ?></strong><?= config('mail.driver') === 'smtp' ? ' via '.e(config('mail.host')).':'.(int) config('mail.port') : '' ?></p>
                <p class="text-steel"><?= config('mail.driver') === 'log' ? e(__('E-mails are saved as files in storage/mail instead of being sent. Switch to SMTP in config/config.php when ready.')) : e(__('Sent from :address.', ['address' => config('mail.from_address')])) ?></p>
            </div>
        </section>
    </div>
</form>
<?php if ($canEdit): ?>
<form method="POST" action="<?= url('/settings/test-mail') ?>" class="mt-4 xl:ml-[60%] xl:pl-6">
    <?= csrf_field() ?><button class="btn-secondary"><?= icon('mail', 'size-4') ?> <?= e(__('Send me a test e-mail')) ?></button>
</form>
<?php endif ?>
