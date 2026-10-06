<?php
/* $show [finance, orders, inet, billing => bool], $d dashboard numbers */
$f = fn ($v) => number_format((float) $v, 0);
$delta = function (float $now, float $then): array {
    if ($then == 0.0) { return [null, 'neutral']; }
    $p = ($now - $then) / abs($then) * 100;

    return [($p >= 0 ? '▲ ' : '▼ ').number_format(abs($p), 1).'%', $p >= 0 ? 'up' : 'down'];
};
$tone = ['up' => 'text-emerald-700', 'down' => 'text-signal-700', 'neutral' => 'text-steel'];
$k = $d['kpi'] ?? [];
$cards = [];
if ($show['finance'] && $d) {
    [$t1, $c1] = $delta((float) $k['month'], (float) $k['month_prev_year']);
    [$t2, $c2] = $delta((float) $k['ytd'], (float) $k['ytd_prev']);
    $cards[] = [__('Revenue this month'), '฿'.$f($k['month']), $t1 ? $t1.' '.__('vs same month last year') : __('no data last year'), $c1];
    $cards[] = [__('Revenue this year'), '฿'.$f($k['ytd']), $t2 ? $t2.' '.__('vs last year to date') : __('no data last year'), $c2];
    $cards[] = [__('Receivable outstanding'), '฿'.$f($d['receivable']), __('open invoices not paid yet'), 'neutral'];
}
if ($show['orders'] && $d) { $cards[] = [__('Orders this month'), (string) $d['orders_this_month'], ($k['invoices_month'] ?? 0).' '.__('invoices this month'), 'neutral']; }
if ($show['inet'] && $d) { $in = $d['inet']; $cards[] = [__('E-tax waiting to send'), (string) ((int) ($in['waiting'] ?? 0)), (int) $d['not_in_inet'].' '.__('invoices (60 days) not generated yet'), ((int) ($in['waiting'] ?? 0)) ? 'down' : 'neutral']; }
if ($show['billing'] && $d) { $cards[] = [__('Billing notes today'), (string) $d['billing_today'], '฿'.$f($d['billing_open']).' '.__('billed, not paid'), 'neutral']; }
$box = 'rounded-xl bg-white p-5 shadow-sm ring-1 ring-graphite-900/8';
$hd = fn (string $t, string $sub = '') => '<div class="mb-3"><h2 class="text-sm font-semibold">'.e($t).'</h2>'.($sub !== '' ? '<p class="text-xs text-steel">'.e($sub).'</p>' : '').'</div>';
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
        <h1 class="page-title"><?= e(__('Accounting overview')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= $d ? e(__('Figures in THB, from the ERP copy. Updated :t.', ['t' => date('H:i', strtotime((string) $d['built']))])) : e(__('You do not have access to any accounting screen yet.')) ?></p>
    </div>
    <?php if ($d && auth()->isAdmin()): ?><a href="<?= url('/accounting', ['refresh' => 1]) ?>" class="btn-secondary"><?= icon('bolt', 'size-4') ?> <?= e(__('Refresh')) ?></a><?php endif ?>
</div>

<?php if ($d): ?>
<section class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6">
    <?php foreach ($cards as [$label, $value, $sub, $c]): ?>
    <div class="<?= $box ?> !p-4"><p class="text-xs text-steel"><?= e($label) ?></p><p class="mt-1 truncate text-xl font-semibold tabular-nums"><?= e($value) ?></p><p class="mt-0.5 truncate text-xs <?= $tone[$c] ?>"><?= e($sub) ?></p></div>
    <?php endforeach ?>
</section>

<div class="mt-4 grid gap-4 lg:grid-cols-12">
    <?php if ($show['finance']): ?>
    <section class="<?= $box ?> lg:col-span-7"><?= $hd(__('Revenue by year'), __('Net of credit notes')) ?><div class="h-72"><canvas id="ch-year" aria-label="<?= e(__('Revenue by year')) ?>" role="img"></canvas></div></section>
    <section class="<?= $box ?> lg:col-span-5"><?= $hd(__('Revenue by month'), __('This year against last year')) ?><div class="h-72"><canvas id="ch-month" aria-label="<?= e(__('Revenue by month')) ?>" role="img"></canvas></div></section>
    <section class="<?= $box ?> lg:col-span-4"><?= $hd(__('Top customers'), __('Last 12 months')) ?><div class="h-80"><canvas id="ch-cust" role="img" aria-label="<?= e(__('Top customers')) ?>"></canvas></div></section>
    <section class="<?= $box ?> lg:col-span-4"><?= $hd(__('Revenue by seller'), __('Last 12 months')) ?><div class="h-80"><canvas id="ch-seller" role="img" aria-label="<?= e(__('Revenue by seller')) ?>"></canvas></div></section>
    <section class="<?= $box ?> lg:col-span-4"><?= $hd(__('Top products'), __('Last 12 months')) ?><div class="h-80"><canvas id="ch-prod" role="img" aria-label="<?= e(__('Top products')) ?>"></canvas></div></section>
    <section class="<?= $box ?> lg:col-span-6"><?= $hd(__('Receivable ageing'), __('Open amount by days past due')) ?><div class="h-64"><canvas id="ch-aging" role="img" aria-label="<?= e(__('Receivable ageing')) ?>"></canvas></div></section>
    <?php endif ?>
    <?php if ($show['orders']): ?>
    <section class="<?= $box ?> <?= $show['finance'] ? 'lg:col-span-6' : 'lg:col-span-12' ?>"><?= $hd(__('Orders per month'), __('Number of customer orders, last 12 months')) ?><div class="h-64"><canvas id="ch-orders" role="img" aria-label="<?= e(__('Orders per month')) ?>"></canvas></div></section>
    <?php endif ?>
    <?php if ($show['inet']): ?>
    <section class="<?= $box ?> lg:col-span-4"><?= $hd(__('E-tax documents'), __('Where each invoice stands')) ?><div class="h-64"><canvas id="ch-inet" role="img" aria-label="<?= e(__('E-tax documents')) ?>"></canvas></div></section>
    <?php endif ?>
    <?php if ($show['billing']): ?>
    <section class="<?= $box ?> lg:col-span-4"><?= $hd(__('Billing notes'), __('By status')) ?><div class="h-64"><canvas id="ch-bstatus" role="img" aria-label="<?= e(__('Billing notes')) ?>"></canvas></div></section>
    <section class="<?= $box ?> lg:col-span-4"><?= $hd(__('Billed per month'), __('Billing note totals, last 12 months')) ?><div class="h-64"><canvas id="ch-bmonth" role="img" aria-label="<?= e(__('Billed per month')) ?>"></canvas></div></section>
    <?php endif ?>
</div>
<script type="application/json" id="dash-data"><?= json_encode($d + ['labels' => [
    'month' => __('This year'), 'prev' => __('Last year'), 'orders' => __('Orders'), 'amount' => __('Amount'), 'notdue' => __('Not due'), 'days' => __('days'),
    'inet' => [__('Signed by INET'), __('Waiting at INET'), __('Generated, not sent'), __('Not generated')],
    'bill' => ['pending' => __('Pending'), 'overdue' => __('Overdue'), 'paid' => __('Paid'), 'complete' => __('Complete')],
    'months' => [__('Jan'), __('Feb'), __('Mar'), __('Apr'), __('May'), __('Jun'), __('Jul'), __('Aug'), __('Sep'), __('Oct'), __('Nov'), __('Dec')],
]], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= asset('assets/vendor/chartjs/chart.umd.min.js') ?>"></script>
<script src="<?= asset('assets/dashboard.js') ?>"></script>
<?php endif ?>
