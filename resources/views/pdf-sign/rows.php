<?php /* Table of signed PDFs. $result {rows,total,page,pages}, $base (detail pages), $showPerson, $link(fn page) */ ?>
<div class="overflow-x-auto"><table class="w-full text-sm">
    <thead class="bg-mist/60 text-left text-xs text-steel"><tr>
        <th class="px-4 py-2 font-medium"><?= e(__('Document')) ?></th>
        <?php if ($showPerson): ?><th class="px-3 py-2 font-medium"><?= e(__('Signed by')) ?></th><?php endif ?>
        <th class="px-3 py-2 font-medium"><?= e(__('Signed')) ?></th>
        <th class="px-3 py-2 text-right font-medium"><?= e(__('Pages')) ?></th>
        <th class="px-3 py-2 text-right font-medium"><?= e(__('Signatures')) ?></th>
        <th class="px-4 py-2 font-medium"><span class="sr-only"><?= e(__('Actions')) ?></span></th></tr></thead>
    <tbody class="divide-y divide-graphite-900/6">
    <?php foreach ($result['rows'] as $r): $file = url('/sign-pdf/'.(int) $r['id'].'/file/signed'); ?>
        <tr class="hover:bg-mist/40">
            <td class="max-w-[20rem] px-4 py-2.5"><a href="<?= e(url($base.'/'.(int) $r['id'])) ?>" class="flex items-center gap-2 font-medium hover:text-signal-700">
                <?= icon('doc', 'size-4 shrink-0 text-signal-700') ?><span class="truncate"><?= e($r['title']) ?></span></a>
                <span class="block pl-6 text-xs text-steel"><?= e(App\Core\Support\Files::size((int) $r['signed_size'])) ?></span></td>
            <?php if ($showPerson): ?>
                <td class="px-3 py-2.5"><span class="flex items-center gap-2"><?= partial('partials/avatar', ['name' => $r['user_name'], 'avatar' => $r['avatar'] ?? null, 'size' => 'size-7']) ?>
                    <span class="min-w-0"><span class="block truncate font-medium"><?= e($r['user_name']) ?></span><span class="block truncate text-xs text-steel"><?= e($r['department_name'] ?? '—') ?></span></span></span></td>
            <?php endif ?>
            <td class="whitespace-nowrap px-3 py-2.5 text-steel"><?= e(format_date($r['created_at'], 'd M Y H:i')) ?></td>
            <td class="px-3 py-2.5 text-right tabular-nums"><?= (int) $r['pages'] ?></td>
            <td class="px-3 py-2.5 text-right tabular-nums"><?= (int) $r['signs'] ?></td>
            <td class="whitespace-nowrap px-4 py-2.5 text-right">
                <a href="<?= e(url($base.'/'.(int) $r['id'])) ?>" class="btn-ghost h-8 px-2 text-xs"><?= icon('eye', 'size-4') ?> <?= e(__('Preview')) ?></a>
                <a href="<?= e($file.'?download=1') ?>" class="btn-ghost h-8 px-2 text-xs" aria-label="<?= e(__('Download')) ?>"><?= icon('download', 'size-4') ?></a></td>
        </tr>
    <?php endforeach ?>
    <?php if (! $result['rows']): ?><tr><td colspan="6" class="px-4 py-12 text-center text-steel"><?= e(__('No signed PDFs yet.')) ?></td></tr><?php endif ?>
    </tbody>
</table></div>
<?php if ($result['pages'] > 1): ?>
    <div class="flex items-center justify-end gap-2 border-t border-graphite-900/8 px-4 py-2 text-sm">
        <a class="btn-secondary !h-8 !px-3 <?= $result['page'] <= 1 ? 'pointer-events-none opacity-40' : '' ?>" href="<?= e($link(['page' => $result['page'] - 1])) ?>">‹</a>
        <span><?= (int) $result['page'] ?> / <?= (int) $result['pages'] ?></span>
        <a class="btn-secondary !h-8 !px-3 <?= $result['page'] >= $result['pages'] ? 'pointer-events-none opacity-40' : '' ?>" href="<?= e($link(['page' => $result['page'] + 1])) ?>">›</a>
    </div>
<?php endif ?>
