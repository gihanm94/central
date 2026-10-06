<?php /* Owner, department and history of a record. $row, $type */
use App\Modules\CRM\Support\Access;
use App\Modules\CRM\Support\Reassign;
use App\Modules\CRM\Support\Responsible;
$canHand = auth() && Reassign::inScope(auth(), $row);
$before  = array_values(array_filter(Responsible::history($type, (int) $row['id']), fn ($h) => $h['role'] === 'owner'));
?>
<section class="panel">
    <div class="panel-head"><h2 class="panel-title"><?= e(__('Record')) ?></h2></div>
    <dl class="divide-y divide-graphite-900/6 text-sm">
        <div class="flex items-center justify-between gap-3 px-5 py-3">
            <dt class="text-steel"><?= e(__('Owner')) ?></dt>
            <dd class="flex min-w-0 items-center gap-2 font-medium"><?php if ($row['owner_name']): ?><?= partial('partials/avatar', ['name' => $row['owner_name'], 'size' => 'size-6', 'extra' => 'text-[10px]']) ?><span class="truncate"><?= e($row['owner_name']) ?></span><?php else: ?>—<?php endif ?></dd>
        </div>
        <div class="flex items-center justify-between gap-3 px-5 py-3"><dt class="text-steel"><?= e(__('Department')) ?></dt><dd class="truncate font-medium"><?= e($row['department_name'] ?? '—') ?></dd></div>
        <div class="flex items-center justify-between gap-3 px-5 py-3"><dt class="text-steel"><?= e(__('Created')) ?></dt><dd class="truncate text-right"><?= e(format_date($row['created_at'], 'd M Y')) ?><?php if ($row['creator_name']): ?><span class="block text-xs text-steel"><?= e($row['creator_name']) ?></span><?php endif ?></dd></div>
        <div class="flex items-center justify-between gap-3 px-5 py-3"><dt class="text-steel"><?= e(__('Updated')) ?></dt><dd class="text-right"><?= e(time_ago($row['updated_at'])) ?></dd></div>
    </dl>
    <?php if ($before): ?>
    <div class="border-t border-graphite-900/8 bg-mist/50 px-5 py-3">
        <p class="text-xs font-semibold uppercase tracking-wide text-steel"><?= e(__('Previous owners')) ?></p>
        <ul class="mt-2 space-y-1.5 text-xs text-steel">
            <?php foreach ($before as $h): ?>
            <li><span class="font-medium text-graphite-800"><?= e($h['from_name'] ?? '—') ?></span> → <span class="font-medium text-graphite-800"><?= e($h['to_name'] ?? '—') ?></span>
                <span class="block text-[11px]"><?= e(format_date($h['created_at'], 'd M Y')) ?><?= $h['by_name'] ? ' · '.e($h['by_name']) : '' ?><?= $h['note'] ? ' · '.e($h['note']) : '' ?></span></li>
            <?php endforeach ?>
        </ul>
    </div>
    <?php endif ?>
    <?php if ($canHand): $targets = Access::assignable(auth()); unset($targets[(int) ($row['owner_id'] ?? 0)]); ?>
    <details class="border-t border-graphite-900/8 px-5 py-3 text-sm">
        <summary class="cursor-pointer font-medium text-signal-700"><?= e(__('Change owner')) ?></summary>
        <form method="POST" action="<?= url('/crm/'.Access::ENTITIES[$type]['path'].'/'.$row['id'].'/owner') ?>" class="mt-3 space-y-2">
            <?= csrf_field() ?>
            <?= select_field('to', $targets, '', ['placeholder' => __('New owner…'), 'aria' => __('New owner'), 'search' => true, 'required' => true]) ?>
            <input name="note" class="input" maxlength="250" placeholder="<?= e(__('Note (optional)')) ?>" aria-label="<?= e(__('Note')) ?>">
            <?php if (Access::seesAll(auth())): ?><label class="flex items-center gap-2 text-xs text-steel"><input type="checkbox" name="move_department" value="1" class="size-4 accent-signal-600"> <?= e(__('Also move it to the new owner\'s department')) ?></label><?php endif ?>
            <button type="submit" class="btn-secondary w-full"><?= e(__('Change owner')) ?></button>
        </form>
    </details>
    <?php endif ?>
</section>
