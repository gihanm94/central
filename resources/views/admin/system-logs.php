<?php /* $files, $name, $file, $level, $q, $ref, $rows, $total, $all, $page, $pages, $counts, $php */
use App\Core\Support\Files;
use App\Core\Support\SystemLogs;

$tone = ['error' => 'bg-signal-50 text-signal-800 ring-signal-600/20', 'warning' => 'bg-amber-50 text-amber-800 ring-amber-600/20', 'notice' => 'bg-sky-50 text-sky-800 ring-sky-600/20', 'info' => 'bg-graphite-900/6 text-graphite-700 ring-graphite-900/10'];
$dot = ['error' => 'bg-signal-600', 'warning' => 'bg-amber-500', 'notice' => 'bg-sky-500', 'info' => 'bg-graphite-400'];
$link = fn (array $over = []) => url('/system-logs', array_filter(array_replace(['file' => $name, 'level' => $level, 'q' => $q, 'ref' => $ref], $over), fn ($v) => $v !== '' && $v !== null));
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0"><h1 class="page-title"><?= e(__('System logs')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(__('Errors and messages written by PHP and the system, newest first — no need to log in to the server.')) ?></p></div>
    <?php if ($file['path'] !== ''): ?>
    <div class="flex flex-wrap gap-2">
        <a href="<?= e(url('/system-logs/download', ['file' => $name])) ?>" class="btn-secondary"><?= icon('download', 'size-4') ?><?= e(__('Download')) ?></a>
        <?php if (! $file['extra']): ?>
            <form method="POST" action="<?= url('/system-logs/clear') ?>" data-confirm="<?= e(__('Empty :name? Its entries are gone for good (download it first if you need them).', ['name' => $name])) ?>">
                <?= csrf_field() ?><input type="hidden" name="file" value="<?= e($name) ?>"><button class="btn-danger"><?= icon('trash', 'size-4') ?><?= e(__('Clear log')) ?></button></form>
        <?php endif ?>
    </div>
    <?php endif ?>
</div>

<div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
    <?php foreach (SystemLogs::LEVELS as $k => $label): ?>
        <a href="<?= e($link(['level' => $level === $k ? '' : $k, 'page' => null])) ?>" class="panel flex items-center gap-3 p-4 hover:ring-graphite-900/20 <?= $level === $k ? 'ring-2 !ring-signal-600' : '' ?>">
            <span class="size-3 rounded-full <?= $dot[$k] ?>"></span>
            <span class="flex-1 text-sm text-steel"><?= e(__($label)) ?> <span class="text-xs">(<?= e(__('24 h')) ?>)</span></span>
            <span class="font-display text-3xl font-semibold tabular-nums <?= $k === 'error' && $counts[$k] ? 'text-signal-700' : '' ?>"><?= (int) $counts[$k] ?></span>
        </a>
    <?php endforeach ?>
</div>

<div class="mt-4 grid items-start gap-4 xl:grid-cols-4">
    <aside class="space-y-4 xl:col-span-1">
        <section class="panel overflow-hidden">
            <div class="panel-head"><h2 class="panel-title"><?= e(__('Log files')) ?></h2></div>
            <?php if (! $files): ?><p class="px-5 py-6 text-sm text-steel"><?= e(__('No log files yet.')) ?></p><?php endif ?>
            <ul class="divide-y divide-graphite-900/6 text-sm">
                <?php foreach ($files as $n => $f): ?>
                    <li><a href="<?= e(url('/system-logs', ['file' => $n])) ?>" class="flex items-center gap-2 px-4 py-2.5 hover:bg-mist <?= $n === $name ? 'bg-mist font-medium' : '' ?>">
                        <?= icon('doc', 'size-4 shrink-0 text-graphite-400') ?><span class="min-w-0 flex-1 truncate"><?= e($n) ?></span>
                        <span class="shrink-0 text-xs text-steel"><?= e(Files::size($f['size'])) ?></span></a></li>
                <?php endforeach ?>
            </ul>
            <p class="border-t border-graphite-900/8 px-4 py-2.5 text-xs text-steel"><?= e(__('To show the web server\'s log too, add its path to config/config.php: \'logs\' => [\'extra\' => [\'/var/log/apache2/error.log\']] (PHP must be allowed to read it).')) ?></p>
        </section>
        <section class="panel overflow-hidden">
            <div class="panel-head"><h2 class="panel-title"><?= e(__('This server')) ?></h2></div>
            <dl class="divide-y divide-graphite-900/6 text-xs">
                <?php foreach ($php as $k => $v): ?><div class="flex gap-3 px-4 py-2"><dt class="w-32 shrink-0 text-steel"><?= e($k) ?></dt><dd class="min-w-0 break-all font-medium"><?= e($v) ?></dd></div><?php endforeach ?>
            </dl>
        </section>
    </aside>

    <section class="panel overflow-hidden xl:col-span-3">
        <form method="GET" action="<?= url('/system-logs') ?>" class="flex flex-wrap items-center gap-2 border-b border-graphite-900/8 p-3">
            <input type="hidden" name="file" value="<?= e($name) ?>"><?php if ($level !== ''): ?><input type="hidden" name="level" value="<?= e($level) ?>"><?php endif ?>
            <div class="relative min-w-[14rem] flex-1"><span class="pointer-events-none absolute inset-y-0 left-2.5 flex items-center text-graphite-400"><?= icon('search', 'size-4') ?></span>
                <input name="q" value="<?= e($q) ?>" class="input pl-8" placeholder="<?= e(__('Search the log (message, file, user…)')) ?>" aria-label="<?= e(__('Search')) ?>"></div>
            <input name="ref" value="<?= e($ref) ?>" class="input !w-36 uppercase" placeholder="<?= e(__('Reference')) ?>" aria-label="<?= e(__('Reference code')) ?>" maxlength="12">
            <button class="btn-secondary"><?= e(__('Search')) ?></button>
            <?php if ($q !== '' || $ref !== '' || $level !== ''): ?><a href="<?= e(url('/system-logs', ['file' => $name])) ?>" class="btn-ghost"><?= e(__('Clear filters')) ?></a><?php endif ?>
            <span class="ml-auto text-sm text-steel"><?= e(__(':n of :all entries', ['n' => $total, 'all' => $all])) ?></span>
        </form>
        <?php if (! $rows): ?>
            <p class="px-5 py-12 text-center text-sm text-steel"><?= e($all ? __('No entries match.') : __('This log is empty. Good news.')) ?></p>
        <?php endif ?>
        <ol class="divide-y divide-graphite-900/6">
            <?php foreach ($rows as $i => $r): $multi = str_contains($r['body'], "\n"); ?>
                <li>
                    <details class="group" <?= $ref !== '' && $i === 0 ? 'open' : '' ?>>
                        <summary class="flex cursor-pointer list-none items-start gap-3 px-4 py-2.5 hover:bg-mist/50">
                            <span class="mt-0.5 inline-flex shrink-0 rounded px-1.5 py-0.5 text-[11px] font-semibold uppercase ring-1 ring-inset <?= $tone[$r['level']] ?>"><?= e(__($r['level'])) ?></span>
                            <span class="min-w-0 flex-1 break-words font-mono text-xs leading-5 text-graphite-900"><?= e($r['head']) ?></span>
                            <span class="shrink-0 whitespace-nowrap text-xs tabular-nums text-steel" title="<?= e((string) $r['time']) ?>"><?= $r['time'] ? e(format_date($r['time'], 'j M H:i:s')) : '—' ?></span>
                            <?= icon('right', 'mt-0.5 size-4 shrink-0 text-steel transition group-open:rotate-90') ?>
                        </summary>
                        <div class="border-t border-graphite-900/6 bg-graphite-950 px-4 py-3">
                            <div class="mb-2 flex items-center justify-between gap-2 text-xs text-graphite-400"><span><?= e($r['time'] ? format_date($r['time'], 'j M Y H:i:s') : '') ?></span>
                                <button type="button" class="rounded px-2 py-0.5 text-graphite-300 ring-1 ring-white/15 hover:bg-white/10" data-copy-log><?= e(__('Copy')) ?></button></div>
                            <pre class="max-h-[32rem] overflow-auto whitespace-pre-wrap break-words font-mono text-xs leading-5 text-white/90" data-log-text><?= e($r['body']) ?></pre>
                            <?php if (! $multi): ?><p class="mt-2 text-xs text-graphite-400"><?= e(__('Single-line entry: this is the full message.')) ?></p><?php endif ?>
                        </div>
                    </details>
                </li>
            <?php endforeach ?>
        </ol>
        <?php if ($pages > 1): ?>
            <div class="flex items-center justify-end gap-2 border-t border-graphite-900/8 px-4 py-2 text-sm">
                <?php if ($page > 1): ?><a class="btn-ghost" href="<?= e($link(['page' => $page - 1])) ?>">‹ <?= e(__('Newer')) ?></a><?php endif ?>
                <span class="text-steel"><?= e(__('Page :p of :n', ['p' => $page, 'n' => $pages])) ?></span>
                <?php if ($page < $pages): ?><a class="btn-ghost" href="<?= e($link(['page' => $page + 1])) ?>"><?= e(__('Older')) ?> ›</a><?php endif ?>
            </div>
        <?php endif ?>
    </section>
</div>
<script>
document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy-log]'); if (!b) return;
    var t = b.closest('div').parentNode.querySelector('[data-log-text]').textContent;
    (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () { window.toast && toast(<?= json_encode(__('Copied.')) ?>, 'success'); }, function () {});
});
</script>
