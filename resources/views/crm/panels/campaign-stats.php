<?php
/* $row */
$budget = $row['budget'] !== null ? (float) $row['budget'] : null; $cost = $row['actual_cost'] !== null ? (float) $row['actual_cost'] : null;
$used = $budget && $cost !== null ? $cost / $budget * 100 : null;
$roi  = $cost && $row['expected_revenue'] !== null ? ((float) $row['expected_revenue'] - $cost) / $cost * 100 : null;
$tiles = [
    [__('Budget'), $budget !== null ? number_format($budget, 2) : '—', null],
    [__('Actual cost'), $cost !== null ? number_format($cost, 2) : '—', $used !== null ? __(':n% of budget', ['n' => round($used)]) : null],
    [__('Remaining'), $budget !== null && $cost !== null ? number_format($budget - $cost, 2) : '—', $budget !== null && $cost !== null && $cost > $budget ? __('Over budget') : null],
    [__('Expected revenue'), $row['expected_revenue'] !== null ? number_format((float) $row['expected_revenue'], 2) : '—', $roi !== null ? __('Expected return :n%', ['n' => round($roi)]) : null],
    [__('Response rate'), $row['actual_response_rate'] !== null ? number_clean($row['actual_response_rate']).'%' : '—', $row['expected_response_rate'] !== null ? __('Expected :n%', ['n' => number_clean($row['expected_response_rate'])]) : null],
];
?>
<section class="panel">
    <div class="panel-head"><h2 class="panel-title"><?= e(__('Budget and results')) ?></h2></div>
    <dl class="grid grid-cols-2 gap-px bg-graphite-900/6 sm:grid-cols-3">
        <?php foreach ($tiles as [$label, $val, $note]): ?>
        <div class="bg-white px-5 py-4"><dt class="text-xs text-steel"><?= e($label) ?></dt><dd class="figure mt-1.5 text-2xl"><?= e($val) ?></dd>
            <?php if ($note): ?><p class="mt-1 text-xs <?= $note === __('Over budget') ? 'font-medium text-signal-700' : 'text-steel' ?>"><?= e($note) ?></p><?php endif ?></div>
        <?php endforeach ?>
    </dl>
    <?php if ($used !== null): ?>
    <div class="border-t border-graphite-900/6 px-5 py-4"><?= partial('crm/meter', ['value' => $used, 'label' => round($used).'%']) ?></div>
    <?php endif ?>
</section>
