<?php /* $dbs, $db, $q, $tables (name → columns, rows), $entries (name → gsheet_tables row + counts), $error */
use App\Modules\Sheets\Support\Catalog;
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0"><h1 class="page-title"><?= e(__('Tables & access')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(__('Every table of every module. Open a table and choose who may sync it to Google Sheets. Tables with passwords, tokens or sessions are never listed.')) ?></p></div>
</div>
<nav class="mt-4 flex flex-wrap gap-1 border-b border-graphite-900/10">
    <?php foreach ($dbs as $key => $name): ?>
        <a href="<?= url('/sheets/tables', ['db' => $key]) ?>" class="-mb-px border-b-2 px-4 py-2 text-sm font-medium <?= $key === $db ? 'border-signal-600 text-signal-700' : 'border-transparent text-steel hover:text-graphite-900' ?>"><?= e($name) ?></a>
    <?php endforeach ?>
</nav>
<section class="panel mt-4 overflow-hidden">
    <form method="GET" action="<?= url('/sheets/tables') ?>" class="flex flex-wrap items-center gap-2 border-b border-graphite-900/8 p-3">
        <input type="hidden" name="db" value="<?= e($db) ?>">
        <input name="q" value="<?= e($q) ?>" class="input max-w-xs" placeholder="<?= e(__('Search tables…')) ?>"><button class="btn-secondary"><?= e(__('Search')) ?></button>
        <span class="ml-auto text-sm text-steel"><?= count($tables) ?> <?= e(__('tables')) ?></span>
    </form>
    <?php if ($error): ?><p class="px-4 py-3 text-sm text-signal-800"><?= e($error) ?></p><?php endif ?>
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead class="bg-mist/60 text-left text-xs text-steel"><tr><th class="px-4 py-2 font-medium"><?= e(__('Table')) ?></th><th class="px-3 py-2 text-right font-medium"><?= e(__('Rows (approx.)')) ?></th><th class="px-3 py-2 text-right font-medium"><?= e(__('Columns')) ?></th>
            <th class="px-3 py-2 font-medium"><?= e(__('Google Sheets')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Who')) ?></th><th class="px-3 py-2"></th></tr></thead>
        <tbody class="divide-y divide-graphite-900/6">
        <?php foreach ($tables as $name => $t): $en = $entries[$name] ?? null; $open = $en && filter_var($en['enabled'], FILTER_VALIDATE_BOOL); ?>
            <tr>
                <td class="px-4 py-2.5"><span class="font-medium"><?= e($en ? Catalog::label($en) : ucfirst(str_replace('_', ' ', $name))) ?></span><span class="block font-mono text-xs text-steel"><?= e($name) ?></span></td>
                <td class="px-3 py-2.5 text-right tabular-nums text-steel"><?= number_format($t['rows']) ?></td>
                <td class="px-3 py-2.5 text-right tabular-nums text-steel"><?= count($t['columns']) ?></td>
                <td class="px-3 py-2.5"><?= $open ? '<span class="badge bg-emerald-50 text-emerald-800">'.e(__('Open')).'</span>' : '<span class="badge bg-graphite-900/6 text-graphite-700">'.e(__('Closed')).'</span>' ?></td>
                <td class="px-3 py-2.5 text-xs text-steel"><?= $en ? e(__(':r roles · :p people · :s syncs', ['r' => $en['roles'], 'p' => $en['people'], 's' => $en['syncs']])) : '—' ?></td>
                <td class="px-3 py-2.5 text-right"><a href="<?= url('/sheets/tables/edit', ['db' => $db, 'table' => $name]) ?>" class="btn-ghost px-3 py-1 text-xs"><?= e(__('Set access')) ?></a></td>
            </tr>
        <?php endforeach ?>
        <?php if (! $tables && ! $error): ?><tr><td colspan="6" class="px-4 py-8 text-center text-steel"><?= e(__('No tables.')) ?></td></tr><?php endif ?>
        </tbody>
    </table></div>
</section>
