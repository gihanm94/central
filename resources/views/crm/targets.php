<?php
use App\Modules\CRM\Support\Ui;
/* $deps, $dep, $year, $row, $live [1..4], $cur, $canEdit, $pickDept, $history, $tabs */
$crumbs = [[__('CRM settings'), '/crm/settings']];
$num   = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
$pct   = fn ($a, $t) => (float) $t > 0 ? round((float) $a / (float) $t * 100) : null;
$qsum  = array_sum([(float) $row['q1'], (float) $row['q2'], (float) $row['q3'], (float) $row['q4']]);
$liveT = array_sum($live);
$prev  = url('/crm/settings/targets', ['year' => $year - 1, 'department' => $dep]);
$next  = url('/crm/settings/targets', ['year' => $year + 1, 'department' => $dep]);
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div><h1 class="page-title"><?= e(__('Sales targets')) ?></h1>
        <p class="mt-1 max-w-3xl text-sm text-steel"><?= e(__('Set the target for each quarter and the year. Actuals come from opportunities won in that quarter (in :cur); save them at year end to keep the record.', ['cur' => $cur])) ?></p></div>
    <div class="flex flex-wrap items-center gap-2">
        <?php if ($pickDept): ?>
        <form method="GET" class="w-56"><input type="hidden" name="year" value="<?= (int) $year ?>"><?= select_field('department', $deps, (string) $dep, ['submit' => true, 'required' => true, 'aria' => __('Department')]) ?></form>
        <?php else: ?><span class="badge bg-graphite-900/6 text-graphite-800"><?= e($deps[$dep] ?? '') ?></span><?php endif ?>
        <div class="inline-flex items-center rounded-md bg-white ring-1 ring-graphite-900/15">
            <a href="<?= e($prev) ?>" class="btn-ghost px-2" aria-label="<?= e(__('Previous year')) ?>"><?= icon('arrowleft', 'size-4') ?></a>
            <span class="px-2 text-sm font-semibold tabular-nums"><?= (int) $year ?></span>
            <a href="<?= e($next) ?>" class="btn-ghost px-2" aria-label="<?= e(__('Next year')) ?>"><?= icon('right', 'size-4') ?></a>
        </div>
    </div>
</div>
<?= partial('partials/tabs', ['tabs' => $tabs]) ?>

<form method="POST" action="<?= url('/crm/settings/targets') ?>" class="mt-5">
    <?= csrf_field() ?>
    <input type="hidden" name="year" value="<?= (int) $year ?>"><input type="hidden" name="department" value="<?= (int) $dep ?>">
    <button name="action" value="targets" class="hidden" tabindex="-1" aria-hidden="true"></button>   <?php /* Enter in a field saves the targets, never the actuals */ ?>
    <section class="panel overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table w-max min-w-full">
                <thead><tr><th><?= e(__('Quarter')) ?></th><th class="text-right"><?= e(__('Target')) ?> (<?= e($cur) ?>)</th><th class="text-right"><?= e(__('Won so far')) ?></th><th class="text-right"><?= e(__('Saved actual')) ?></th><th class="min-w-48"><?= e(__('Achieved')) ?></th></tr></thead>
                <tbody>
                <?php foreach ([1, 2, 3, 4] as $q): $t = (float) $row['q'.$q]; $a = $row['a'.$q]; $p = $pct($live[$q], $t); ?>
                    <tr>
                        <td class="font-medium">Q<?= $q ?> <span class="text-xs font-normal text-steel"><?= e(['Jan–Mar', 'Apr–Jun', 'Jul–Sep', 'Oct–Dec'][$q - 1]) ?></span></td>
                        <td class="text-right"><input name="q<?= $q ?>" value="<?= e($num($t)) ?>" inputmode="decimal" class="input ml-auto w-40 text-right tabular-nums" <?= $canEdit ? '' : 'readonly' ?> aria-label="<?= e(__('Target for quarter :q', ['q' => $q])) ?>"><?= field_error('q'.$q) ?></td>
                        <td class="text-right tabular-nums"><?= e(number_format($live[$q], 2)) ?></td>
                        <td class="text-right"><input name="a<?= $q ?>" value="<?= e($num($a)) ?>" inputmode="decimal" placeholder="—" class="input ml-auto w-40 text-right tabular-nums" <?= $canEdit ? '' : 'readonly' ?> aria-label="<?= e(__('Saved actual for quarter :q', ['q' => $q])) ?>"></td>
                        <td><?= $p === null ? Ui::dash() : partial('crm/meter', ['value' => $p, 'label' => $p.'%']) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
                <tfoot>
                    <tr class="bg-mist/50">
                        <td class="font-semibold"><?= e(__('Year')) ?> <?= (int) $year ?></td>
                        <td class="text-right"><input name="total" value="<?= e($num($row['total'])) ?>" inputmode="decimal" class="input ml-auto w-40 text-right font-semibold tabular-nums" <?= $canEdit ? '' : 'readonly' ?> aria-label="<?= e(__('Target for the year')) ?>">
                            <span class="mt-1 block text-[11px] text-steel"><?= e(__('Quarters add up to :n; leave the year empty to use that.', ['n' => number_format($qsum, 2)])) ?></span></td>
                        <td class="text-right font-semibold tabular-nums"><?= e(number_format($liveT, 2)) ?></td>
                        <td class="text-right font-semibold tabular-nums"><?= $row['a1'] === null ? '—' : e(number_format((float) $row['a1'] + (float) $row['a2'] + (float) $row['a3'] + (float) $row['a4'], 2)) ?></td>
                        <td><?php $pt = $pct($liveT, (float) $row['total'] ?: $qsum); echo $pt === null ? Ui::dash() : partial('crm/meter', ['value' => $pt, 'label' => $pt.'%']); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
    <?php if ($canEdit): ?>
    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-xs text-steel"><?= $row['actuals_saved_at'] ? e(__('Actuals last saved :time.', ['time' => time_ago($row['actuals_saved_at'])])) : e(__('Actuals have not been saved for this year yet.')) ?></p>
        <div class="flex flex-wrap gap-2">
            <button name="action" value="actuals_live" class="btn-secondary"><?= icon('tick', 'size-4') ?> <?= e(__('Save actuals from won deals')) ?></button>
            <button name="action" value="actuals" class="btn-secondary"><?= e(__('Save typed actuals')) ?></button>
            <button name="action" value="targets" class="btn-primary"><?= e(__('Save targets')) ?></button>
        </div>
    </div>
    <?php endif ?>
</form>

<?php if ($history): ?>
<section class="panel mt-6 overflow-hidden">
    <div class="panel-head"><h2 class="panel-title"><?= e(__('Saved years')) ?></h2></div>
    <div class="overflow-x-auto"><table class="table w-max min-w-full">
        <thead><tr><th><?= e(__('Year')) ?></th><th class="text-right"><?= e(__('Target')) ?></th><th class="text-right">Q1</th><th class="text-right">Q2</th><th class="text-right">Q3</th><th class="text-right">Q4</th><th class="text-right"><?= e(__('Actual')) ?></th><th class="min-w-40"><?= e(__('Achieved')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): $act = (float) $h['a1'] + (float) $h['a2'] + (float) $h['a3'] + (float) $h['a4']; $p = $pct($act, $h['total']); ?>
            <tr><td class="font-medium"><a href="<?= e(url('/crm/settings/targets', ['year' => $h['year'], 'department' => $dep])) ?>" class="hover:text-signal-700"><?= (int) $h['year'] ?></a></td>
                <td class="text-right tabular-nums"><?= e(number_format((float) $h['total'], 2)) ?></td>
                <?php foreach (['a1', 'a2', 'a3', 'a4'] as $k): ?><td class="text-right tabular-nums"><?= $h[$k] === null ? '—' : e(number_format((float) $h[$k], 2)) ?></td><?php endforeach ?>
                <td class="text-right font-medium tabular-nums"><?= e(number_format($act, 2)) ?></td>
                <td><?= $p === null ? Ui::dash() : partial('crm/meter', ['value' => $p, 'label' => $p.'%']) ?></td></tr>
        <?php endforeach ?>
        </tbody></table></div>
</section>
<?php endif ?>
