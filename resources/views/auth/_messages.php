<?php use App\Core\Support\Session; ?>
<?php if ($s = Session::get('status')): ?>
    <p class="mt-6 rounded-md bg-white/5 px-4 py-3 text-sm text-graphite-300 ring-1 ring-white/10"><?= e($s) ?></p>
<?php endif ?>
