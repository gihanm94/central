<?php /* $p, $presentations, $open (waiting request or null), $sessions (upcoming days), $canEdit, $allowed (kinds it may present now) */
use App\Core\Support\Everbetter;
$crumbs = [[__('Everbetter'), '/everbetter']];
$kinds = Everbetter::kinds();
$help = ['initiation' => __('Start: what you will improve, the plan and the team. Approval starts the project.'),
    'closure' => __('Finish: the results. Approval closes the project.'),
    'extension' => __('More time: why, and the new end date. Approval moves the end date.')];
$presented = count(array_filter($presentations, fn ($x) => $x['result'] !== null));
?>
<div class="flex flex-wrap items-start justify-between gap-4">
    <div class="min-w-0">
        <h1 class="page-title break-words"><?= e($p['title']) ?></h1>
        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
            <?= partial('everbetter/status', ['p' => $p]) ?>
            <span class="badge <?= $presented ? 'bg-emerald-50 text-emerald-800' : 'bg-graphite-900/6 text-steel' ?>"><?= $presented ? '✓ '.e(__('Presented')) : e(__('Not presented yet')) ?></span>
            <span class="text-steel"><?= e(format_date($p['start_date']).' – '.format_date($p['end_date'])) ?></span>
        </div>
    </div>
    <div class="flex w-full flex-wrap gap-2 sm:w-auto">
        <?php if ($canEdit): ?><a href="<?= url('/everbetter/'.(int) $p['id'].'/edit') ?>" class="btn-secondary flex-1 sm:flex-none"><?= icon('pencil', 'size-4') ?> <?= e(__('Edit')) ?></a><?php endif ?>
        <?php if ($canEdit && $allowed && ! $open): ?>
            <button type="button" class="btn-primary flex-1 sm:flex-none" data-open-dialog="eb-present"><?= icon('chat', 'size-4') ?> <?= e(__('Present')) ?></button>
        <?php endif ?>
    </div>
</div>

<?php if ($open): [$l, $tone] = Everbetter::stateOf($open); ?>
<div class="mt-5 flex flex-wrap items-center gap-3 rounded-xl px-4 py-3 text-sm ring-1 <?= $open['status'] === 'acknowledged' ? 'bg-sky-50 text-sky-900 ring-sky-600/20' : 'bg-amber-50 text-amber-900 ring-amber-600/20' ?>">
    <?= icon($open['status'] === 'acknowledged' ? 'check' : 'clock', 'size-5 shrink-0') ?>
    <p class="min-w-0 flex-1"><strong><?= e($kinds[$open['kind']]) ?></strong> · <?= e(format_date($open['present_on'], 'l d M Y')) ?> — <?= e($l) ?>
        <?= $open['status'] === 'acknowledged' ? e(__('(by :name)', ['name' => $open['acknowledged_name']])) : '' ?></p>
    <?php if ($canEdit && $open['status'] === 'submitted'): ?>
        <form method="POST" action="<?= url('/everbetter/'.(int) $p['id'].'/requests/'.(int) $open['id'].'/withdraw') ?>" data-confirm="<?= e(__('Withdraw this request?')) ?>"><?= csrf_field() ?><button class="btn-ghost h-8 px-2 text-xs"><?= e(__('Withdraw')) ?></button></form>
    <?php endif ?>
</div>
<?php elseif ($canEdit && ! $allowed && $p['status'] === 'closed'): ?>
<p class="mt-5 rounded-xl bg-mist px-4 py-3 text-sm text-steel"><?= e(__('This project is closed.')) ?></p>
<?php endif ?>

<div class="mt-6 grid items-start gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <section class="panel">
            <div class="panel-head"><h2 class="panel-title"><?= e(__('About the project')) ?></h2></div>
            <div class="whitespace-pre-line px-5 py-4 text-sm"><?= $p['description'] ? e($p['description']) : '<span class="text-steel">'.e(__('No description yet.')).'</span>' ?></div>
        </section>
        <section class="panel overflow-hidden">
            <div class="panel-head"><h2 class="panel-title"><?= e(__('Presentations')) ?></h2></div>
            <?php if (! $presentations): ?><p class="px-5 py-8 text-center text-sm text-steel"><?= e(__('Not presented yet. Use "Present" to ask for the next presentation day.')) ?></p><?php endif ?>
            <ol class="divide-y divide-graphite-900/6">
                <?php foreach ($presentations as $x): [$l, $tone] = Everbetter::stateOf($x); ?>
                <li class="px-5 py-3.5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="font-medium"><?= e($kinds[$x['kind']]) ?> <span class="font-normal text-steel">· <?= e(__('Q:q :y presentation, :date', ['q' => $x['quarter'], 'y' => $x['year'], 'date' => format_date($x['present_on'], 'd M Y')])) ?></span></p>
                        <span class="badge <?= $tone ?>"><?= e($l) ?></span>
                    </div>
                    <p class="mt-1 text-xs text-steel"><?= e(__('Asked by :name on :date', ['name' => $x['requested_name'], 'date' => format_date($x['requested_at'], 'd M Y H:i')])) ?>
                        <?= $x['kind'] === 'extension' && $x['new_end_date'] ? ' · '.e(__('new end date :d', ['d' => format_date($x['new_end_date'])])) : '' ?>
                        <?= $x['acknowledged_at'] ? ' · '.e(__('accepted by :name :date', ['name' => $x['acknowledged_name'], 'date' => format_date($x['acknowledged_at'], 'd M')])) : '' ?>
                        <?= $x['decided_at'] ? ' · '.e(__('decided by :name :date', ['name' => $x['decided_name'], 'date' => format_date($x['decided_at'], 'd M')])) : '' ?></p>
                    <?php if ($x['note']): ?><p class="mt-1.5 whitespace-pre-line text-sm"><?= e($x['note']) ?></p><?php endif ?>
                    <?php if ($x['result_note']): ?><p class="mt-1.5 rounded-md bg-mist px-3 py-2 text-sm"><span class="text-xs font-semibold text-steel"><?= e(__('Management')) ?>:</span> <?= e($x['result_note']) ?></p><?php endif ?>
                </li>
                <?php endforeach ?>
            </ol>
        </section>
    </div>
    <section class="panel overflow-hidden">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Team')) ?> <span class="ml-1 text-sm font-normal text-steel"><?= count($p['members']) ?></span></h2></div>
        <ul class="divide-y divide-graphite-900/6">
            <?php foreach ($p['members'] as $m): ?>
            <li class="flex items-center gap-3 px-5 py-2.5"><?= partial('partials/avatar', ['name' => $m['name'], 'avatar' => $m['avatar'], 'size' => 'size-8']) ?>
                <span class="min-w-0 flex-1 truncate text-sm font-medium"><?= e($m['name']) ?></span><?php if ((int) $m['id'] === (int) $p['owner_id']): ?><span class="text-xs text-steel"><?= e(__('owner')) ?></span><?php endif ?></li>
            <?php endforeach ?>
        </ul>
        <dl class="divide-y divide-graphite-900/6 border-t border-graphite-900/8 text-sm">
            <div class="flex justify-between gap-3 px-5 py-2.5"><dt class="text-steel"><?= e(__('Department')) ?></dt><dd class="font-medium"><?= e($p['department_name'] ?? '—') ?></dd></div>
            <div class="flex justify-between gap-3 px-5 py-2.5"><dt class="text-steel"><?= e(__('Created')) ?></dt><dd><?= e(format_date($p['created_at'], 'd M Y')) ?></dd></div>
        </dl>
    </section>
</div>

<?php if ($canEdit && $allowed && ! $open): ?>
<dialog id="eb-present" data-light-dismiss class="m-auto w-[min(36rem,calc(100vw-2rem))] rounded-xl bg-white p-0 text-graphite-900 shadow-2xl ring-1 ring-graphite-900/10 backdrop:bg-graphite-950/60" aria-labelledby="eb-present-title">
    <form method="POST" action="<?= url('/everbetter/'.(int) $p['id'].'/present') ?>" data-eb-present>
        <?= csrf_field() ?>
        <div class="flex items-center justify-between gap-3 border-b border-graphite-900/8 px-5 py-3.5">
            <h2 id="eb-present-title" class="text-base font-semibold"><?= e(__('Present to Management')) ?></h2>
            <button type="button" class="btn-ghost -mr-2 px-2" data-close-dialog aria-label="<?= e(__('Close')) ?>"><?= icon('x', 'size-5') ?></button>
        </div>
        <div class="space-y-5 px-5 py-4">
            <?php if (! $sessions): ?>
                <p class="rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-900"><?= e(__('Management has not set an upcoming presentation day yet. Try again later.')) ?></p>
            <?php else: ?>
            <fieldset><legend class="label"><?= e(__('What are you presenting?')) ?></legend>
                <div class="grid gap-2">
                    <?php foreach ($allowed as $i => $k): ?>
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-graphite-900/15 p-3 has-[:checked]:border-signal-600 has-[:checked]:bg-signal-50/40">
                        <input type="radio" name="kind" value="<?= $k ?>" <?= $i === 0 ? 'checked' : '' ?> class="mt-1 size-4 accent-signal-600" data-eb-kind>
                        <span><span class="block font-medium"><?= e($kinds[$k]) ?></span><span class="block text-xs text-steel"><?= e($help[$k]) ?></span></span>
                    </label>
                    <?php endforeach ?>
                </div></fieldset>
            <div><label class="label" for="eb-session"><?= e(__('Presentation day')) ?></label>
                <?= select_field('session_id', array_column(array_map(fn ($s) => ['id' => $s['id'], 'l' => __('Q:q :y · :date', ['q' => $s['quarter'], 'y' => $s['year'], 'date' => format_date($s['present_on'], 'D d M Y')]).($s['place'] ? ' · '.$s['place'] : '')], $sessions), 'l', 'id'),
                    (string) $sessions[0]['id'], ['id' => 'eb-session', 'search' => false]) ?></div>
            <div data-eb-newend <?= in_array('extension', $allowed, true) && $allowed[0] === 'extension' ? '' : 'hidden' ?>><label class="label" for="eb-newend"><?= e(__('New end date')) ?></label>
                <input id="eb-newend" type="date" name="new_end_date" class="input" min="<?= e(date('Y-m-d', strtotime($p['end_date'].' +1 day'))) ?>">
                <p class="mt-1 text-xs text-steel"><?= e(__('Now ends :d.', ['d' => format_date($p['end_date'])])) ?></p></div>
            <div><label class="label" for="eb-note"><?= e(__('Note for Management')) ?> <span class="font-normal text-steel">(<?= e(__('optional')) ?>)</span></label>
                <textarea id="eb-note" name="note" rows="3" class="input" placeholder="<?= e(__('What will you show? Anything they should know?')) ?>"></textarea></div>
            <?php endif ?>
        </div>
        <div class="flex justify-end gap-2 rounded-b-xl bg-mist/60 px-5 py-3">
            <button type="button" class="btn-secondary" data-close-dialog><?= e(__('Cancel')) ?></button>
            <?php if ($sessions): ?><button class="btn-primary"><?= icon('chat', 'size-4') ?> <?= e(__('Send request')) ?></button><?php endif ?>
        </div>
    </form>
</dialog>
<script>
(function () {
    var f = document.querySelector('[data-eb-present]'); if (!f) return;
    f.addEventListener('change', function (e) {
        if (!e.target.matches('[data-eb-kind]')) return;
        var box = f.querySelector('[data-eb-newend]'), on = e.target.value === 'extension';
        box.hidden = !on; box.querySelector('input').required = on;
    });
})();
</script>
<?php endif ?>
