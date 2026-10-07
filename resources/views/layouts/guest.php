<?php $locales = App\Core\Support\I18n::available(); ?>
<!DOCTYPE html>
<html lang="<?= e(locale()) ?>" class="h-full bg-graphite-950">
<head><?php include __DIR__.'/head.php' ?></head>
<body class="auth h-full bg-graphite-950 text-white">
<div class="grid min-h-full lg:grid-cols-[minmax(0,1.15fr)_minmax(420px,0.85fr)]">
    <section class="relative hidden overflow-hidden lg:block" aria-hidden="true">
        <?php if ($branding['login_image']): ?>
            <?php /* the picture is shown whole (contain) on the textured dark background, never cropped */ ?>
            <div class="absolute inset-0 bg-graphite-800" style="background-image: repeating-linear-gradient(115deg, rgba(255,255,255,.035) 0 2px, transparent 2px 22px), radial-gradient(120% 90% at 20% 10%, #3d4149 0%, #17181b 70%);"></div>
            <div class="absolute inset-0 flex flex-col">
                <div class="flex min-h-0 flex-1 items-center justify-center px-[4%] pt-[4%]"><img src="<?= e($branding['login_image']) ?>" alt="" class="max-h-full max-w-full object-contain"></div>
                <?php if ($branding['banner'] !== ''): ?>
                <div class="mt-4 bg-signal-600 py-6 pl-14 pr-16" style="clip-path: polygon(0 0, 100% 0, 94% 100%, 0 100%); margin-right: 4rem">
                    <p class="font-display text-[clamp(2rem,3.8vw,3.75rem)] font-bold leading-[0.95] tracking-tight text-white"><?= e($branding['banner']) ?></p>
                </div>
                <?php endif ?>
                <?php if ($branding['tagline']): ?><p class="px-14 pb-10 pt-5 text-base text-graphite-300"><?= e($branding['tagline']) ?></p><?php else: ?><div class="pb-10"></div><?php endif ?>
            </div>
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
            <?php if ($branding['auth_logo']): ?>
                <?php if ($branding['logo']): ?>
                    <img src="<?= e($branding['logo']) ?>" alt="<?= e($branding['name']) ?>" class="h-10 w-auto max-w-[180px] object-contain">
                <?php else: ?>
                    <span class="flex size-10 items-center justify-center rounded-lg bg-signal-600 font-display text-xl font-bold"><?= e(mb_substr($branding['name'], 0, 1)) ?></span>
                <?php endif ?>
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
