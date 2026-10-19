<?php
use App\Modules\CRM\Support\Catalog;
use App\Modules\CRM\Support\StageReview;
/* Sales control → By stage. From crm/team: $f, $stage, $stageCounts, $stageRows, $people, $deps, $members, $wholeCompany, $canEdit, $sq */
$dates  = StageReview::dates();
$ago    = fn (int $d) => date('Y-m-d', strtotime("-{$d} days"));
$days   = fn ($since) => $since ? max(0, (int) floor((time() - strtotime((string) $since)) / 86400)) : null;
$return = '/crm/control?'.(string) parse_url($sq(), PHP_URL_QUERY);
$open   = array_flip(Catalog::OPEN);
?>
<form method="GET" action="<?= url('/crm/control') ?>" class="mt-4 rounded-xl bg-white p-3 shadow-sm ring-1 ring-graphite-900/8">
    <input type="hidden" name="view" value="stage"><input type="hidden" name="stage" value="<?= e($stage) ?>">
    <div class="flex flex-wrap items-end gap-2">
        <?php if ($wholeCompany): ?>
            <div class="w-full sm:w-48"><label class="label" for="st-dep"><?= e(__('Department')) ?></label>
                <?= select_field('department', $deps, $f['department'] ?: '', ['id' => 'st-dep', 'placeholder' => __('All departments')]) ?></div>
        <?php endif ?>
        <div class="w-full sm:w-56"><label class="label" for="st-owner"><?= e(__('Owner')) ?></label>
            <?= select_field('owner', $members, $f['owner'] ?: '', ['id' => 'st-owner', 'placeholder' => __('Everyone'), 'search' => true]) ?></div>
        <div class="w-full sm:w-44"><label class="label" for="st-date"><?= e(__('Date')) ?></label>
            <?= select_field('date', array_map(fn ($d) => $d[0], $dates), $f['date'], ['id' => 'st-date', 'required' => true]) ?></div>
        <div><label class="label" for="st-from"><?= e(__('From')) ?></label><input id="st-from" type="date" name="from" value="<?= e($f['from']) ?>" class="input"></div>
        <div><label class="label" for="st-to"><?= e(__('To')) ?></label><input id="st-to" type="date" name="to" value="<?= e($f['to']) ?>" class="input"></div>
        <button class="btn-secondary"><?= e(__('Show')) ?></button>
        <?php if ($f['from'] !== '' || $f['to'] !== '' || $f['owner'] || $f['department'] || $f['date'] !== 'since'): ?>
            <a href="<?= e(url('/crm/control', ['view' => 'stage', 'stage' => $stage])) ?>" class="btn-ghost"><?= e(__('Clear filters')) ?></a>
        <?php endif ?>
    </div>
    <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs">
        <span class="text-steel"><?= e(__('In this stage for more than')) ?></span>
        <?php foreach ([30, 60, 90, 180] as $d): $on = $f['date'] === 'since' && $f['from'] === '' && $f['to'] === $ago($d); ?>
            <a href="<?= e($sq(['date' => 'since', 'from' => null, 'to' => $ago($d)])) ?>" class="rounded-full px-2.5 py-1 ring-1 <?= $on ? 'bg-graphite-900 text-white ring-graphite-900' : 'bg-white ring-graphite-900/10 hover:bg-mist' ?>"><?= e(__(':n days', ['n' => $d])) ?></a>
        <?php endforeach ?>
    </div>
</form>

<nav class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-8" aria-label="<?= e(__('Stages')) ?>">
    <?php foreach (Catalog::STAGES as $k => [$label]): $c = $stageCounts[$k] ?? ['n' => 0, 'amount' => 0]; $on = $k === $stage; ?>
    <a href="<?= e($sq(['stage' => $k])) ?>" class="rounded-lg px-3 py-2 ring-1 transition-colors <?= $on ? 'bg-graphite-900 text-white ring-graphite-900' : 'bg-white ring-graphite-900/10 hover:bg-mist' ?>" <?= $on ? 'aria-current="page"' : '' ?>>
        <span class="flex items-center gap-1.5 text-xs <?= $on ? 'text-white/70' : 'text-steel' ?>"><?= e(__($label)) ?><?php if (isset($open[$k])): ?><span class="size-1.5 rounded-full bg-amber-500" title="<?= e(__('Open')) ?>"></span><?php endif ?></span>
        <span class="block text-xl font-semibold tabular-nums"><?= (int) $c['n'] ?></span>
        <span class="block truncate text-[11px] tabular-nums <?= $on ? 'text-white/60' : 'text-steel' ?>"><?= e(number_format((float) $c['amount'], 0)) ?></span>
    </a>
    <?php endforeach ?>
</nav>
<p class="mt-1 text-xs text-steel"><?= e(__('Count and amount per stage for the filters above. The dot marks stages that are still open.')) ?></p>

<form method="POST" action="<?= url('/crm/control/status') ?>" class="mt-4" data-team>
    <?= csrf_field() ?>
    <input type="hidden" name="type" value="opportunity"><input type="hidden" name="return" value="<?= e($return) ?>">
    <?php if ($canEdit): ?>
    <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-graphite-900/8">
        <h2 class="text-sm font-semibold"><?= e(__('Change stage of the ticked opportunities')) ?></h2>
        <div class="mt-2 grid gap-2 sm:grid-cols-[14rem_1fr_auto]">
            <?= select_field('status', array_diff_key(Catalog::stages(), [$stage => 1]), (string) old('status', ''), ['placeholder' => __('New stage…'), 'aria' => __('New stage')]) ?>
            <input name="status_note" class="input" maxlength="500" value="<?= e((string) old('status_note')) ?>" placeholder="<?= e(__('Reason (needed to close, cancel or hold), e.g. no reply for 6 months')) ?>" aria-label="<?= e(__('Reason')) ?>">
            <button type="submit" class="btn-primary" data-needs-ticked data-confirm="<?= e(__('Change the stage of the ticked opportunities?')) ?>"><?= icon('check', 'size-4') ?> <?= e(__('Apply to ticked')) ?> (<span data-ticked>0</span>)</button>
        </div>
        <?= field_error('status') ?><?= field_error('note') ?>
    </section>
    <?php endif ?>

    <section class="panel mt-4 overflow-hidden">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Opportunities')) ?> · <?= e(Catalog::stageLabel($stage)) ?></h2>
            <span class="text-sm text-steel"><?= e(__('Longest in this stage first')) ?></span></div>
        <?php if (! $stageRows): ?>
            <p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('No opportunities in this stage for these filters.')) ?></p>
        <?php else: ?>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-mist/60 text-left text-xs text-steel"><tr>
                <th class="w-10 px-4 py-2"><input type="checkbox" class="size-4 accent-signal-600" data-tick-all aria-label="<?= e(__('Tick all')) ?>"></th>
                <th class="px-3 py-2 font-medium"><?= e(__('Opportunity')) ?></th>
                <th class="px-3 py-2 font-medium"><?= e(__('Owner')) ?></th>
                <?php if ($wholeCompany): ?><th class="px-3 py-2 font-medium"><?= e(__('Department')) ?></th><?php endif ?>
                <th class="px-3 py-2 text-right font-medium"><?= e(__('Amount')) ?></th>
                <th class="px-3 py-2 font-medium"><?= e(__('In stage since')) ?></th>
                <th class="px-3 py-2 font-medium"><?= e(__('Created')) ?></th>
                <th class="px-3 py-2 font-medium"><?= e(__('Updated')) ?></th>
            </tr></thead>
            <tbody class="divide-y divide-graphite-900/6">
            <?php foreach ($stageRows as $r): $d = $days($r['since']); ?>
                <tr class="hover:bg-mist/40">
                    <td class="px-4 py-2"><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" class="size-4 accent-signal-600" data-tick aria-label="<?= e(__('Tick')) ?>"></td>
                    <td class="px-3 py-2"><a href="<?= url('/crm/opportunities/'.(int) $r['id']) ?>" class="font-medium hover:text-signal-700"><?= e($r['name']) ?></a>
                        <span class="block text-xs text-steel"><?= e(trim(($r['code'] ?: '').($r['customer'] ? ' · '.$r['customer'] : ''), ' ·')) ?></span></td>
                    <td class="px-3 py-2"><?= e($people[(int) $r['owner_id']] ?? '—') ?></td>
                    <?php if ($wholeCompany): ?><td class="px-3 py-2 text-steel"><?= e($deps[(int) $r['department_id']] ?? '—') ?></td><?php endif ?>
                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums"><?= $r['amount'] !== null ? e(number_format((float) $r['amount'], 2).' '.$r['currency']) : '—' ?></td>
                    <td class="whitespace-nowrap px-3 py-2"><?= e(format_date($r['since'])) ?>
                        <?php if ($d !== null): ?><span class="ml-1 rounded px-1.5 py-0.5 text-xs font-medium tabular-nums <?= $d >= 90 ? 'bg-signal-50 text-signal-800' : ($d >= 30 ? 'bg-amber-50 text-amber-800' : 'bg-mist text-steel') ?>"><?= e(__(':n days', ['n' => $d])) ?></span><?php endif ?></td>
                    <td class="whitespace-nowrap px-3 py-2 text-steel"><?= e(format_date($r['created_at'])) ?></td>
                    <td class="whitespace-nowrap px-3 py-2 text-steel"><?= e(time_ago($r['updated_at'])) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        </div>
        <?php if (count($stageRows) >= 300): ?><p class="border-t border-graphite-900/8 px-5 py-2 text-xs text-steel"><?= e(__('Showing the first 300. Narrow the dates to see the rest.')) ?></p><?php endif ?>
        <?php endif ?>
    </section>
</form>
