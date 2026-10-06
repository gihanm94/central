<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="theme-color" content="#17181b">
<title><?= isset($title) ? e($title).' · ' : '' ?><?= e($branding['name']) ?></title>
<?php if ($branding['logo']): ?><link rel="icon" href="<?= e($branding['logo']) ?>"><?php else: ?><link rel="icon" href="data:,"><?php endif ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700&family=IBM+Plex+Sans+Thai:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
<script src="<?= asset('assets/app.js') ?>" defer></script>
<script src="<?= asset('assets/crm.js') ?>" defer></script>
<script src="<?= asset('assets/accounting.js') ?>" defer></script>
<script src="<?= asset('assets/ui.js') ?>" defer></script>
