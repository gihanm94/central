<?php /* $days (day => bytes), $today */ ?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
        <p class="text-sm text-steel"><a href="<?= url('/accounting') ?>" class="hover:text-signal-700"><?= e(__('Accounting')) ?></a> › <?= e(__('Setup')) ?></p>
        <h1 class="page-title"><?= e(__('Logs')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(__('Live view of ERP calls, syncs, INET generate / send and errors. Kept in text files (storage/logs/accounting), not in the database, for :n days.', ['n' => \App\Modules\Accounting\Support\Log::KEEP_DAYS])) ?></p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a id="lg-dl" class="btn-secondary" href="<?= url('/accounting/logs/download') ?>?day=<?= e($today) ?>"><?= icon('download', 'size-4') ?> <?= e(__('Download')) ?></a>
        <form method="POST" action="<?= url('/accounting/logs/clear') ?>" data-confirm="<?= e(__('Clear this day?')) ?>"><?= csrf_field() ?><input type="hidden" name="day" id="lg-clear" value="<?= e($today) ?>"><button class="btn-secondary"><?= icon('trash', 'size-4') ?> <?= e(__('Clear day')) ?></button></form>
    </div>
</div>

<div class="mt-4 rounded-xl bg-white p-3 shadow-sm ring-1 ring-graphite-900/8">
    <div class="flex flex-wrap items-center gap-2">
        <select id="lg-day" class="input !w-auto"><?php foreach ($days + [$today => 0] as $d => $b): ?><option value="<?= e($d) ?>" <?= $d === $today ? 'selected' : '' ?>><?= e($d) ?><?= $d === $today ? ' · '.e(__('live')) : '' ?></option><?php endforeach ?></select>
        <select id="lg-level" class="input !w-auto"><option value="debug"><?= e(__('All levels')) ?></option><option value="info"><?= e(__('Info and above')) ?></option><option value="warn"><?= e(__('Warnings and errors')) ?></option><option value="error"><?= e(__('Errors only')) ?></option></select>
        <select id="lg-ch" class="input !w-auto"><option value=""><?= e(__('All channels')) ?></option><option value="erp"><?= e(__('ERP API calls')) ?></option><option value="sync"><?= e(__('Sync')) ?></option><option value="inet"><?= e(__('INET (generate / send)')) ?></option><option value="pdf">PDF</option><option value="app"><?= e(__('Other errors')) ?></option></select>
        <input id="lg-q" class="input !w-56" placeholder="<?= e(__('Search…')) ?>">
        <label class="ml-auto flex items-center gap-2 text-sm"><input type="checkbox" id="lg-follow" checked class="size-4 accent-signal-600"> <?= e(__('Follow new lines')) ?></label>
        <span id="lg-state" class="inline-flex items-center gap-1.5 text-xs text-emerald-700"><span class="size-2 animate-pulse rounded-full bg-emerald-500"></span><?= e(__('live')) ?></span>
        <button type="button" id="lg-clearview" class="btn-secondary !h-8"><?= e(__('Clear screen')) ?></button>
    </div>
    <div id="lg-box" data-empty="<?= e(__('No log lines yet.')) ?>" class="mt-3 h-[65vh] overflow-auto rounded-lg bg-graphite-900 p-3 font-mono text-xs leading-5 text-white"></div>
    <p class="mt-2 text-xs text-steel"><?= e(__('Each line can be opened for details (URL, HTTP status, time, error and where it happened).')) ?></p>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var box = document.getElementById('lg-box'), today = <?= json_encode($today) ?>, $ = function (i) { return document.getElementById(i); };
    var s = AcctLog.stream(box, { url: <?= json_encode(url('/accounting/logs/tail')) ?>, day: today, tail: 400, every: 1500, onTick: function (d) { $('lg-state').hidden = $('lg-day').value !== today; } });
    function apply() { s.set({ day: $('lg-day').value, level: $('lg-level').value, ch: $('lg-ch').value, q: $('lg-q').value }); $('lg-dl').href = $('lg-dl').href.replace(/day=[^&]*/, 'day=' + $('lg-day').value); $('lg-clear').value = $('lg-day').value; }
    ['lg-day', 'lg-level', 'lg-ch'].forEach(function (i) { $(i).addEventListener('change', apply); });
    var t; $('lg-q').addEventListener('input', function () { clearTimeout(t); t = setTimeout(apply, 350); });
    $('lg-follow').addEventListener('change', function () { s.pause(!this.checked); if (this.checked) box.scrollTop = box.scrollHeight; });
    $('lg-clearview').addEventListener('click', function () { box.innerHTML = ''; });
});
</script>
