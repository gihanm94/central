<?php
use App\Modules\CRM\Support\Catalog;
/* Opportunity stage tracker. $row, $canEdit, $steps (newest first) */
$stage    = $row['opportunity_stage'];
$pipeline = Catalog::PIPELINE;
$cur      = array_search($stage, $pipeline, true);
$moves    = array_values(array_filter($steps, fn ($s) => $s['kind'] === 'stage'));   // newest first
$reached  = -1;
foreach ($moves as $m) { $i = array_search($m['stage'], $pipeline, true); if ($i !== false) { $reached = max($reached, $i); } }
$won      = $stage === 'CLOSED_WON';
$closed   = in_array($stage, ['CLOSED_LOST', 'CANCEL', 'ON_HOLD'], true);
$since    = $moves ? $moves[0]['created_at'] : $row['created_at'];
$days     = max(0, (int) floor((time() - strtotime($since)) / 86400));
$nodes    = [...array_map(fn ($s) => [$s, Catalog::stageLabel($s)], $pipeline), ['CLOSED_WON', Catalog::stageLabel('CLOSED_WON')]];
$banner   = ['CLOSED_WON' => ['bg-emerald-50 text-emerald-900 ring-emerald-600/20', 'tick'], 'CLOSED_LOST' => ['bg-signal-50 text-signal-800 ring-signal-600/20', 'alert'],
             'CANCEL' => ['bg-graphite-900/5 text-graphite-800 ring-graphite-900/10', 'x'], 'ON_HOLD' => ['bg-amber-50 text-amber-900 ring-amber-600/20', 'clock']][$stage] ?? null;
$options  = array_diff_key(Catalog::stages(), [$stage => 1]);
?>
<section id="stage" class="panel mt-6 p-5">
    <ol class="grid grid-cols-5 gap-1.5 sm:gap-2" aria-label="<?= e(__('Opportunity stages')) ?>">
        <?php foreach ($nodes as $i => [$key, $label]):
            $state = $won ? 'done' : ($closed ? ($i <= $reached ? 'past' : 'todo') : ($cur !== false ? ($i < $cur ? 'done' : ($i === $cur ? 'current' : 'todo')) : 'todo'));
            $bar = ['done' => $key === 'CLOSED_WON' ? 'bg-emerald-600' : 'bg-graphite-800', 'past' => 'bg-graphite-400', 'current' => 'bg-signal-600', 'todo' => 'bg-graphite-900/10'][$state]; ?>
            <li <?= $state === 'current' ? 'aria-current="step"' : '' ?>>
                <span class="block h-2 rounded-full <?= $bar ?>"></span>
                <span class="mt-2 flex items-start gap-1 text-[11px] leading-tight sm:text-xs <?= $state === 'current' ? 'font-semibold text-graphite-900' : ($state === 'todo' ? 'text-graphite-400' : 'text-steel') ?>">
                    <?php if ($state === 'done'): ?><?= icon('tick', 'mt-px size-3.5 shrink-0 '.($key === 'CLOSED_WON' ? 'text-emerald-600' : 'text-graphite-800')) ?><?php endif ?>
                    <span class="min-w-0 break-words"><?= e($label) ?></span>
                </span>
            </li>
        <?php endforeach ?>
    </ol>

    <?php if ($banner): ?>
    <div class="mt-4 flex items-start gap-3 rounded-md px-4 py-3 text-sm ring-1 <?= $banner[0] ?>">
        <?= icon($banner[1], 'mt-0.5 size-4 shrink-0') ?>
        <div class="min-w-0"><p class="font-medium"><?= e(Catalog::stageLabel($stage)) ?><?= $row['close_at'] && $stage !== 'ON_HOLD' ? ' · '.e(format_date($row['close_at'], 'd M Y')) : '' ?></p>
            <?php if (! empty($row['cancel_reason'])): ?><p class="mt-0.5 whitespace-pre-line break-words"><?= e($row['cancel_reason']) ?></p><?php endif ?></div>
    </div>
    <?php endif ?>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-x-6 gap-y-2 text-sm text-steel">
        <p><?= e(__('In this stage for :n', ['n' => $days === 0 ? __('less than a day') : __($days === 1 ? ':n day' : ':n days', ['n' => $days])])) ?>
            <?php if ($row['follow_at']): ?> · <?= e(__('Follow-up :date', ['date' => format_date($row['follow_at'], 'd M Y')])) ?><?php endif ?>
            <?php if ($row['close_at'] && ! $won && ! $closed): ?> · <?= e(__('Expected close :date', ['date' => format_date($row['close_at'], 'd M Y')])) ?><?php endif ?></p>
        <?php if ($canEdit): ?><button type="button" class="btn-secondary py-1.5" data-toggle="#move-stage" aria-expanded="false"><?= icon('right', 'size-4') ?> <?= e(__('Move to another stage')) ?></button><?php endif ?>
    </div>

    <?php if ($canEdit): ?>
    <form id="move-stage" hidden method="POST" action="<?= url('/crm/opportunities/'.$row['id'].'/stage') ?>" class="mt-4 grid gap-3 border-t border-graphite-900/8 pt-4 sm:grid-cols-3">
        <?= csrf_field() ?>
        <div><label for="move-stage-pick" class="label"><?= e(__('New stage')) ?></label>
            <?= select_field('stage', $options, '', ['placeholder' => __('Choose a stage…'), 'id' => 'move-stage-pick', 'required' => true]) ?><?= field_error('stage') ?></div>
        <div class="sm:col-span-2"><label for="move-note" class="label"><?= e(__('Note')) ?> <span class="font-normal text-steel">(<?= e(__('required for lost, cancelled and on hold')) ?>)</span></label>
            <textarea id="move-note" name="note" rows="2" maxlength="2000" class="input <?= error_for('note') ? 'input-error' : '' ?>" placeholder="<?= e(__('What happened? This goes into the history.')) ?>"></textarea><?= field_error('note') ?></div>
        <div class="flex justify-end gap-2 sm:col-span-3"><button type="button" class="btn-ghost" data-toggle="#move-stage"><?= e(__('Cancel')) ?></button><button class="btn-primary"><?= e(__('Move stage')) ?></button></div>
    </form>
    <?php endif ?>
</section>
