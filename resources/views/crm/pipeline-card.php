<?php
use App\Modules\CRM\Support\Catalog;
use App\Modules\CRM\Support\Stages;
use App\Modules\CRM\Support\Ui;
/* One card of the board. $o (opportunity row + lead_*, owner_name, creator_name), $me (user id), $canEdit, $stages (codes the person may move to) */
$mine    = (int) $o['created_by'] === (int) $me || (int) $o['owner_id'] === (int) $me;
$movable = $canEdit && (int) $o['created_by'] === (int) $me;
$late    = $o['close_at'] && strtotime($o['close_at']) < strtotime('today') && in_array($o['opportunity_stage'], Catalog::OPEN, true);
$color   = Stages::color($o['opportunity_stage']);
?>
<article class="kb-card group relative rounded-lg bg-white p-3 text-sm shadow-sm <?= $mine ? 'ring-2 ring-signal-600/70' : 'ring-1 ring-graphite-900/10' ?> <?= $movable ? 'cursor-grab active:cursor-grabbing' : '' ?>"
         data-id="<?= (int) $o['id'] ?>" data-stage="<?= e($o['opportunity_stage']) ?>" data-mine="<?= $mine ? '1' : '0' ?>" <?= $movable ? 'draggable="true"' : '' ?>
         <?= ! $movable ? 'title="'.e(__('Only :name, who created this, can move it.', ['name' => $o['creator_name'] ?? '—'])).'"' : '' ?>
         style="<?= $mine ? 'box-shadow: inset 4px 0 0 var(--color-brand)' : '' ?>">
    <div class="flex items-start justify-between gap-2">
        <a href="<?= url('/crm/opportunities/'.$o['id']) ?>" class="min-w-0 font-medium leading-snug hover:text-signal-700" draggable="false"><?= e($o['name']) ?></a>
        <span class="flex shrink-0 items-center gap-1">
            <?php if ($mine): ?><span class="rounded bg-signal-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-signal-700"><?= e(__('Mine')) ?></span><?php endif ?>
            <?php if ($movable): ?>
            <span class="relative">
                <button type="button" class="btn-ghost size-6 px-0" data-menu="#cm-<?= (int) $o['id'] ?>" data-placement="bottom-end" aria-haspopup="menu" aria-expanded="false" aria-label="<?= e(__('Move :name to…', ['name' => $o['name']])) ?>"><?= icon('dots', 'size-4') ?></button>
                <span id="cm-<?= (int) $o['id'] ?>" data-menu-panel hidden role="menu" class="fixed z-[70] w-52 rounded-lg bg-white p-1.5 text-sm font-normal shadow-xl ring-1 ring-graphite-900/10">
                    <span class="block px-2 pb-1 pt-0.5 text-xs text-steel"><?= e(__('Move to')) ?></span>
                    <?php foreach ($stages as $code): if ($code === $o['opportunity_stage']) continue; ?>
                        <button type="button" role="menuitem" data-move-to="<?= e($code) ?>" class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left hover:bg-mist"><span class="size-2.5 rounded-full" style="background:<?= e(Stages::color($code)) ?>"></span><?= e(Catalog::stageLabel($code)) ?></button>
                    <?php endforeach ?>
                </span>
            </span>
            <?php else: ?><span class="text-graphite-400" aria-hidden="true"><?= icon('lock', 'size-3.5') ?></span><?php endif ?>
        </span>
    </div>
    <?php if ($o['lead_name']): ?>
    <div class="mt-2"><?= Ui::person((string) $o['lead_name'], $o['lead_name_th'] ?: $o['contact_name'], $o['lead_image'], null) ?></div>
    <?php endif ?>
    <div class="mt-2.5 flex items-center justify-between gap-2">
        <span class="font-semibold tabular-nums"><?= Ui::money($o['amount'], $o['currency']) ?></span>
        <?php if ($o['probability'] !== null): ?><span class="text-xs tabular-nums text-steel"><?= e(number_clean($o['probability'])) ?>%</span><?php endif ?>
    </div>
    <div class="mt-1.5 flex items-center justify-between gap-2 text-xs text-steel">
        <span class="flex min-w-0 items-center gap-1.5"><?= partial('partials/avatar', ['name' => $o['owner_name'] ?? '?', 'size' => 'size-5', 'extra' => 'text-[9px]']) ?><span class="truncate"><?= e($o['owner_name'] ?? '—') ?></span></span>
        <?php if ($o['close_at']): ?><span class="shrink-0 tabular-nums <?= $late ? 'font-medium text-signal-700' : '' ?>"><?= e(format_date($o['close_at'], 'd M')) ?></span><?php endif ?>
    </div>
</article>
