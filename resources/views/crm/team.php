<?php
use App\Modules\CRM\Support\Ui;
/* $members [id => label], $from, $person, $type, $types, $openOnly, $counts, $rows, $targets, $canEdit, $wholeCompany */
$t       = $types[$type];
$qs      = fn (array $x) => url('/crm/team', array_filter(array_merge(['user' => $from, 'type' => $type, 'all' => $openOnly ? null : 1], $x), fn ($v) => $v !== null && $v !== 0));
$stageTone = fn ($s) => $type === 'opportunity' ? Ui::stageBadge($s) : Ui::badge($t['statuses'][$s] ?? (string) $s, 'neutral');
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
        <h1 class="page-title"><?= e(__('Team')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e($wholeCompany ? __('Pick a person to see everything they have, then hand it to somebody else or change many at once.') : __('Pick someone from your department to see everything they have, then hand it to somebody else or change many at once.')) ?></p>
    </div>
</div>

<form method="GET" action="<?= url('/crm/team') ?>" class="mt-4 flex flex-wrap items-center gap-3 rounded-xl bg-white p-3 shadow-sm ring-1 ring-graphite-900/8">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <?php if (! $openOnly): ?><input type="hidden" name="all" value="1"><?php endif ?>
    <label class="text-sm font-medium" for="team-user"><?= e(__('Person')) ?></label>
    <div class="w-full max-w-sm"><?= select_field('user', $members, $from ?: '', ['id' => 'team-user', 'placeholder' => __('Choose a person…'), 'submit' => true, 'search' => true]) ?></div>
    <?php if ($from): ?>
    <div class="ml-auto inline-flex rounded-lg bg-mist p-0.5 text-sm" role="group" aria-label="<?= e(__('Show')) ?>">
        <a href="<?= $qs(['all' => null]) ?>" class="rounded-md px-3 py-1 <?= $openOnly ? 'bg-white font-medium shadow-sm' : 'text-steel' ?>"><?= e(__('Open only')) ?></a>
        <a href="<?= $qs(['all' => 1]) ?>" class="rounded-md px-3 py-1 <?= ! $openOnly ? 'bg-white font-medium shadow-sm' : 'text-steel' ?>"><?= e(__('Everything')) ?></a>
    </div>
    <?php endif ?>
</form>

<?php if (! $from): ?>
    <div class="mt-6 rounded-xl bg-white p-10 text-center shadow-sm ring-1 ring-graphite-900/8">
        <span class="mx-auto flex size-12 items-center justify-center rounded-full bg-mist text-steel"><?= icon('users', 'size-6') ?></span>
        <p class="mt-3 font-medium"><?= e(__('Choose a person above')) ?></p>
        <p class="mt-1 text-sm text-steel"><?= e(__('People who left or are switched off are listed too, so their records can be handed over.')) ?></p>
    </div>
<?php else: ?>

<nav class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-7" aria-label="<?= e(__('Record types')) ?>">
    <?php foreach ($types as $k => $tt): $n = $counts[$k] ?? ['open' => 0, 'total' => 0]; $on = $k === $type; ?>
    <a href="<?= $qs(['type' => $k]) ?>" class="rounded-lg px-3 py-2 ring-1 transition-colors <?= $on ? 'bg-graphite-900 text-white ring-graphite-900' : 'bg-white ring-graphite-900/10 hover:bg-mist' ?>" <?= $on ? 'aria-current="page"' : '' ?>>
        <span class="block text-xs <?= $on ? 'text-white/70' : 'text-steel' ?>"><?= e($tt['name']) ?></span>
        <span class="block text-lg font-semibold tabular-nums"><?= (int) $n['open'] ?><span class="text-xs font-normal <?= $on ? 'text-white/70' : 'text-steel' ?>"> / <?= (int) $n['total'] ?></span></span>
    </a>
    <?php endforeach ?>
</nav>
<p class="mt-1 text-xs text-steel"><?= e(__('Open / all records')) ?></p>

<form method="POST" action="<?= url('/crm/team/reassign') ?>" class="mt-4" data-team>
    <?= csrf_field() ?>
    <input type="hidden" name="type" value="<?= e($type) ?>"><input type="hidden" name="from" value="<?= (int) $from ?>">

    <?php if ($canEdit): ?>
    <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-graphite-900/8">
        <div class="grid gap-4 lg:grid-cols-2">
            <div>
                <h2 class="text-sm font-semibold"><?= e(__('Hand to somebody else')) ?></h2>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    <?= select_field('to', $targets, '', ['placeholder' => __('New owner…'), 'aria' => __('New owner'), 'search' => true]) ?>
                    <input name="note" class="input" maxlength="250" placeholder="<?= e(__('Note (optional), e.g. resigned')) ?>" aria-label="<?= e(__('Note')) ?>">
                </div>
                <?php if ($wholeCompany): ?><label class="mt-2 flex items-center gap-2 text-xs text-steel"><input type="checkbox" name="move_department" value="1" class="size-4 accent-signal-600"> <?= e(__('Also move the records to the new owner\'s department')) ?></label><?php endif ?>
                <div class="mt-2 flex flex-wrap gap-2">
                    <button type="submit" class="btn-primary" data-needs-ticked><?= icon('users', 'size-4') ?> <?= e(__('Hand over ticked')) ?> (<span data-ticked>0</span>)</button>
                    <button type="submit" class="btn-secondary" formaction="<?= url('/crm/team/handover') ?>" data-confirm="<?= e(__('Hand over everything this person has to the chosen owner?')) ?>"><?= e(__('Hand over everything')) ?></button>
                </div>
                <label class="mt-2 flex items-center gap-2 text-xs text-steel"><input type="checkbox" name="include_closed" value="1" class="size-4 accent-signal-600"> <?= e(__('"Everything" also includes closed records')) ?></label>
            </div>
            <?php if ($t['status']): ?>
            <div>
                <h2 class="text-sm font-semibold"><?= e($type === 'opportunity' ? __('Change stage') : __('Change status')) ?></h2>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    <?= select_field('status', $t['statuses'], '', ['placeholder' => $type === 'opportunity' ? __('New stage…') : __('New status…'), 'aria' => __('New status'), 'search' => false]) ?>
                    <input name="status_note" class="input" maxlength="500" placeholder="<?= e(__('Reason (needed to close, cancel or hold)')) ?>" aria-label="<?= e(__('Reason')) ?>">
                </div>
                <div class="mt-2"><button type="submit" class="btn-secondary" formaction="<?= url('/crm/team/status') ?>" data-needs-ticked><?= icon('check', 'size-4') ?> <?= e(__('Apply to ticked')) ?> (<span data-ticked>0</span>)</button></div>
            </div>
            <?php endif ?>
        </div>
    </section>
    <?php endif ?>

    <section class="panel mt-4 overflow-hidden">
        <div class="panel-head"><h2 class="panel-title"><?= e($t['name']) ?> · <?= e($person['name'] ?? '') ?><?= $person && ! $person['active'] ? ' <span class="text-signal-700">('.e(__('inactive')).')</span>' : '' ?></h2></div>
        <?php if (! $rows): ?>
            <p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('Nothing here.')) ?></p>
        <?php else: ?>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-mist/60 text-left text-xs text-steel"><tr>
                <th class="w-10 px-4 py-2"><input type="checkbox" class="size-4 accent-signal-600" data-tick-all aria-label="<?= e(__('Tick all')) ?>"></th>
                <th class="px-3 py-2 font-medium"><?= e(__('Name')) ?></th>
                <?php if ($t['status']): ?><th class="px-3 py-2 font-medium"><?= e($type === 'opportunity' ? __('Stage') : __('Status')) ?></th><?php endif ?>
                <th class="px-3 py-2 font-medium"><?= e(__('Updated')) ?></th>
            </tr></thead>
            <tbody class="divide-y divide-graphite-900/6">
            <?php foreach ($rows as $r): ?>
                <tr class="hover:bg-mist/40">
                    <td class="px-4 py-2"><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" class="size-4 accent-signal-600" data-tick aria-label="<?= e(__('Tick')) ?>"></td>
                    <td class="px-3 py-2"><a href="<?= url('/crm/'.\App\Modules\CRM\Support\Access::ENTITIES[$type]['path'].'/'.$r['id']) ?>" class="font-medium hover:text-signal-700"><?= e($r['label']) ?></a></td>
                    <?php if ($t['status']): ?><td class="px-3 py-2"><?= $stageTone($r['status']) ?></td><?php endif ?>
                    <td class="px-3 py-2 text-steel"><?= e(time_ago($r['updated_at'])) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        </div>
        <?php if (count($rows) >= 300): ?><p class="border-t border-graphite-900/8 px-5 py-2 text-xs text-steel"><?= e(__('Showing the 300 most recently changed. Use "Hand over everything" for the rest.')) ?></p><?php endif ?>
        <?php endif ?>
    </section>
</form>
<?php endif ?>
