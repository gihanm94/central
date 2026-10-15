<?php /* $modules (without core), $status */
use App\Core\Support\Modules;
$where = ['manifest' => __('module.php'), 'routes' => __('routes.php'), 'api' => __('api.php (mobile API)'), 'migrations' => __('Database migrations')];
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0"><h1 class="page-title"><?= e(__('Modules')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e(__('Every module in app/Modules. A module that fails is switched off on its own pages only; everything else keeps working. Fix the file, then press Try again.')) ?></p></div>
    <?php if ($status): ?>
        <form method="POST" action="<?= url('/modules/retry') ?>"><?= csrf_field() ?><button class="btn-secondary"><?= e(__('Try all again')) ?></button></form>
    <?php endif ?>
</div>
<section class="panel mt-4 overflow-hidden">
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead class="bg-mist/60 text-left text-xs text-steel"><tr><th class="px-4 py-2 font-medium"><?= e(__('Module')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Folder')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Status')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Problems')) ?></th><th class="px-3 py-2"></th></tr></thead>
        <tbody class="divide-y divide-graphite-900/6">
        <?php foreach ($modules as $key => $m): $issues = $status[$key] ?? []; $blocked = Modules::problems($key); ?>
            <tr class="align-top">
                <td class="px-4 py-2.5"><span class="flex items-center gap-2 font-medium"><?= icon($m['icon'], 'size-4 text-steel') ?><?= e(__($m['name'])) ?></span><span class="block text-xs text-steel"><?= e($m['home']) ?></span></td>
                <td class="px-3 py-2.5 text-xs text-steel"><?= $m['dir'] ? e(str_replace(BASE_PATH.'/', '', $m['dir'])) : e(__('Not built yet')) ?></td>
                <td class="px-3 py-2.5">
                    <?php if (! $m['enabled']): ?><span class="badge bg-graphite-900/6 text-graphite-700"><?= e(__('Off')) ?></span>
                    <?php elseif ($blocked): ?><span class="badge bg-signal-50 text-signal-800"><?= e(__('Unavailable')) ?></span>
                    <?php elseif ($issues): ?><span class="badge bg-amber-50 text-amber-800"><?= e(__('Warnings')) ?></span>
                    <?php else: ?><span class="badge bg-emerald-50 text-emerald-800"><?= e(__('Working')) ?></span><?php endif ?>
                </td>
                <td class="px-3 py-2.5">
                    <?php foreach ($issues as $w => $i): ?>
                        <p class="mb-1.5 last:mb-0"><span class="font-medium"><?= e($where[$w] ?? (str_starts_with($w, 'hook:') ? __('Background task “:h”', ['h' => substr($w, 5)]) : $w)) ?></span>
                            <span class="text-xs text-steel"> · <?= e(time_ago($i['at'])) ?></span>
                            <span class="block break-all text-xs text-signal-800"><?= e($i['message']) ?></span>
                            <span class="block text-xs text-steel"><?= e($i['file'].':'.$i['line']) ?></span></p>
                    <?php endforeach ?>
                    <?php if (! $issues): ?><span class="text-steel">—</span><?php endif ?>
                </td>
                <td class="whitespace-nowrap px-3 py-2.5 text-right">
                    <?php if ($issues): ?><form method="POST" action="<?= url('/modules/retry') ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="module" value="<?= e($key) ?>"><button class="btn-ghost px-3 py-1 text-xs"><?= e(__('Try again')) ?></button></form><?php endif ?>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
</section>
<p class="mt-3 text-xs text-steel"><?= e(__('Switch a module on or off in config/modules.php. Full error traces are in storage/logs/php-error.log.')) ?></p>
