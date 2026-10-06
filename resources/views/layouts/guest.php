<?php $locales = App\Core\Support\I18n::available(); ?>
<!DOCTYPE html>
<html lang="<?= e(locale()) ?>" class="h-full bg-graphite-950">
<head><?php include __DIR__.'/head.php' ?></head>
<body class="auth h-full bg-graphite-950 text-white">
<div class="grid min-h-full lg:grid-cols-[minmax(0,1.15fr)_minmax(420px,0.85fr)]">
    <section class="relative hidden overflow-hidden lg:block" aria-hidden="true">
        <?php if ($branding['login_image']): ?>
            <img src="<?= e($branding['login_image']) ?>" alt="" class="absolute inset-0 size-full object-cover">
            <div class="absolute inset-0 bg-gradient-to-t from-graphite-950 via-graphite-950/40 to-graphite-950/10"></div>
            <div class="absolute bottom-24 left-0 right-16 bg-signal-600 py-7 pl-14 pr-16" style="clip-path: polygon(0 0, 100% 0, 94% 100%, 0 100%)">
                <p class="font-display text-[clamp(2.6rem,4.6vw,4.5rem)] font-bold leading-[0.95] tracking-tight text-white"><?= e($branding['name']) ?></p>
            </div>
            <p class="absolute bottom-10 left-14 max-w-md text-base text-graphite-300"><?= e($branding['tagline']) ?></p>
        <?php else: ?>
            <?php /* default picture: scales with the window (contain), never cropped or stretched */ ?>
            <div class="absolute inset-0 flex flex-col items-center justify-center bg-white px-[4%] py-[4%]">
                <img src="<?= asset('assets/auth-hero.webp') ?>" alt="" class="min-h-0 w-full max-w-[56rem] flex-1 object-contain">
                <?php if ($branding['tagline']): ?><p class="mt-2 max-w-md text-center text-base text-graphite-600"><?= e($branding['tagline']) ?></p><?php endif ?>
            </div>
        <?php endif ?>
    </section>

    <section class="mx-auto flex w-full max-w-md flex-col px-6 py-8 sm:px-10 sm:py-10">
        <div class="flex items-center gap-3">
            <?php if ($branding['logo']): ?>
                <img src="<?= e($branding['logo']) ?>" alt="<?= e($branding['name']) ?>" class="h-10 w-auto max-w-[180px] object-contain">
            <?php else: ?>
                <span class="flex size-10 items-center justify-center rounded-lg bg-signal-600 font-display text-xl font-bold"><?= e(mb_substr($branding['name'], 0, 1)) ?></span>
            <?php endif ?>
            <span class="font-display text-2xl font-semibold lg:hidden"><?= e($branding['name']) ?></span>
            <nav class="ml-auto flex gap-1 text-xs" aria-label="<?= e(__('Language')) ?>">
                <?php foreach ($locales as $code => $name): ?>
                    <a href="<?= url('/lang/'.$code) ?>" class="rounded px-2 py-1 <?= $code === locale() ? 'bg-white/10 text-white' : 'text-graphite-400 hover:text-white' ?>" <?= $code === locale() ? 'aria-current="true"' : '' ?>><?= e($name) ?></a>
                <?php endforeach ?>
            </nav>
        </div>
        <div class="my-auto w-full py-10"><?= $content ?></div>
        <p class="text-xs text-graphite-400">
            <?= e(__('Accounts are created by your administrator.')) ?>
            <?php if ($branding['support']): ?><?= e(__('Need access?')) ?> <a href="mailto:<?= e($branding['support']) ?>" class="text-graphite-300 underline underline-offset-2 hover:text-white"><?= e($branding['support']) ?></a><?php endif ?>
        </p>
    </section>
</div>
</body>
</html>
