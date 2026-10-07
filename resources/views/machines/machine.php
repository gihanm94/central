<?php
use App\Modules\Machines\Support\Files;
use App\Modules\Machines\Support\Mx;
/* $m, $c, $canEdit, $canDelete, $canCheck, $general, $maint, $maintRecords, $calRecords, $checks, $history, $people, $canChange, $qrText, $link */
$crumbs = [[__('Machines'), $c['base']]];
$files  = fn (?string $json) => partial('machines/_files', ['list' => Files::decode($json)]);
$section = fn (string $t, ?string $right = null) => '<div class="flex items-center justify-between border-b border-graphite-900/6 bg-mist/50 px-5 py-2"><h2 class="text-xs font-semibold text-steel">'.e($t).'</h2>'.($right ?? '').'</div>';
$empty = fn (string $t) => '<p class="px-5 py-6 text-center text-sm text-steel">'.e($t).'</p>';
?>
<div class="flex flex-wrap items-start justify-between gap-4">
    <div class="flex min-w-0 items-center gap-4">
        <?php if ($m['image']): ?><img src="<?= e(upload_url($m['image'])) ?>" alt="" class="size-16 shrink-0 rounded-lg bg-white object-contain ring-1 ring-graphite-900/10"><?php endif ?>
        <div class="min-w-0">
            <p class="text-sm tabular-nums text-steel"><?= e($m['machine_code']) ?></p>
            <h1 class="page-title break-words"><?= e($m['machine_name']) ?></h1>
            <p class="mt-1 flex flex-wrap items-center gap-2"><?= Mx::pill($m['machine_status']) ?> <?= Mx::pill($m['check_status']) ?>
                <span class="text-sm text-steel"><?= e(trim(($m['machine_group_name'] ?? '').' / '.($m['machine_type_name'] ?? ''), ' /')) ?></span></p>
        </div>
    </div>
    <div class="flex w-full flex-wrap gap-2 sm:w-auto">
        <?php if ($canCheck): ?><a href="<?= url('/machines/checklists/new?machine='.rawurlencode($m['machine_code'])) ?>" class="btn-dark flex-1 sm:flex-none"><?= icon('check', 'size-4') ?> <?= e(__('Check now')) ?></a><?php endif ?>
        <a href="<?= url('/machines/label?ids='.$m['id']) ?>" target="_blank" class="btn-secondary flex-1 sm:flex-none"><?= icon('qr', 'size-4') ?> <?= e(__('QR label')) ?></a>
        <?php if ($canDelete): ?><button type="button" class="btn-danger flex-1 sm:flex-none" data-delete-url="<?= e(url($c['base'].'/'.$m['id'].'/delete')) ?>" data-delete-name="<?= e($m['machine_code']) ?>" data-delete-kind="<?= e(__('machine')) ?>"><?= icon('trash', 'size-4') ?> <?= e(__('Delete')) ?></button><?php endif ?>
        <?php if ($canEdit): ?><a href="<?= url($c['base'].'/'.$m['id'].'/edit') ?>" class="btn-primary flex-1 sm:flex-none"><?= icon('pencil', 'size-4') ?> <?= e(__('Edit')) ?></a><?php endif ?>
    </div>
</div>

<div class="mt-5 grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_18rem]">
<div class="min-w-0 space-y-5">
    <section class="panel overflow-hidden">
        <?= $section(__('Machine')) ?>
        <?= partial('machines/_info', ['items' => [
            [__('Department'), $m['department_name']], [__('Brand'), $m['brand']], [__('Model'), $m['model']],
            [__('Serial number'), $m['serial_number']], [__('Asset number'), $m['machine_number']], [__('Bought new'), $m['is_new'] === null ? null : ($m['is_new'] ? __('Yes') : __('No'))],
            [__('Responsible person'), $m['responsible_person_id_name'] ?? $m['responsible_person_name']], [__('Supervisor'), $m['supervisor_id_name']], [__('Manager'), $m['manager_id_name']],
            [__('Responsible re-checks'), __(Mx::PERIODS[$m['reset_period']] ?? $m['reset_period'])], [__('Maintenance every'), $m['maintenance_period']], [__('Calibration'), $m['is_calibration'] ? __('Yes').($m['certificate_period'] ? ' · '.$m['certificate_period'] : '') : __('No')],
            [__('Warranty'), $m['has_warranty'] === 'YES' ? __('Yes').($m['warranty_expire_date'] ? ' · '.__('ends :date', ['date' => format_date($m['warranty_expire_date'])]) : '') : ($m['has_warranty'] === 'NO' ? __('No') : null)],
            [__('Registered'), $m['register_id'] ? '#'.$m['register_id'].($m['register_date'] ? ' · '.format_date($m['register_date']) : '') : null], [__('Last reviewed'), $m['last_review'] ? format_date($m['last_review']).($m['review_by'] ? ' · '.$m['review_by'] : '') : null],
            [__('Note'), $m['note'], ['span' => 3]],
            [__('Work instruction'), $files($m['work_instruction']), ['raw' => true, 'span' => 2]], [__('Warranty papers'), $files($m['warranty_files']), ['raw' => true]],
        ]]) ?>
        <?php if ($m['reason_cancel']): ?><p class="px-5 py-3 text-sm text-red-800"><?= e($m['reason_cancel']) ?></p><?php endif ?>
    </section>

    <section class="panel overflow-hidden">
        <?= $section(__('Checklist')) ?>
        <?php if (! $general): echo $empty(__('No checklist items yet. Edit the machine to add questions.')); else: ?>
        <ul class="divide-y divide-graphite-900/6">
            <?php foreach ($general as $i): ?>
            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-2.5 text-sm">
                <span class="min-w-0 flex-1"><?= e($i['detail']) ?><?php if ($i['description']): ?><span class="block text-xs text-steel"><?= e($i['description']) ?></span><?php endif ?></span>
                <?= Mx::pill(Mx::resetLabel($i['reset_time']), $i['reset_time'] === Mx::GENERAL ? 'info' : 'neutral') ?>
                <?php if ($i['reset_time'] !== Mx::GENERAL): ?><?= Mx::pill($i['check_status'] ? __('Checked') : __('Not yet'), $i['check_status'] ? 'ok' : 'warn') ?><?php endif ?>
            </li>
            <?php endforeach ?>
        </ul>
        <?php endif ?>
        <?= $section(__('Maintenance checklist')) ?>
        <?php if (! $maint): echo $empty(__('No maintenance items.')); else: ?>
        <ul class="divide-y divide-graphite-900/6"><?php foreach ($maint as $i): ?><li class="px-5 py-2.5 text-sm"><?= e($i['detail']) ?></li><?php endforeach ?></ul>
        <?php endif ?>
    </section>

    <section class="panel overflow-hidden">
        <?= $section(__('Maintenance'), can('machines_maintenance', 'view') ? '<a class="text-xs text-signal-700 underline" href="'.e(url('/machines/maintenance?q='.rawurlencode($m['machine_code']))).'">'.e(__('Open list')).'</a>' : null) ?>
        <?php if (! $maintRecords): echo $empty(__('No maintenance planned.')); else: ?>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="text-xs text-steel"><tr><th class="px-5 py-2 font-medium"><?= e(__('Year')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Round')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Due')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Done')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Status')) ?></th></tr></thead><tbody class="divide-y divide-graphite-900/6">
            <?php foreach ($maintRecords as $r): $late = ! $r['actual_date'] && $r['due_date'] && $r['due_date'] < date('Y-m-d'); ?>
            <tr class="hover:bg-mist/40"><td class="px-5 py-2 tabular-nums"><?= e($r['years']) ?></td><td class="px-3 py-2 tabular-nums"><?= e($r['round']) ?></td><td class="px-3 py-2"><?= e($r['due_date'] ? format_date($r['due_date']) : '—') ?></td><td class="px-3 py-2"><?= e($r['actual_date'] ? format_date($r['actual_date']) : '—') ?></td>
                <td class="px-3 py-2"><?= $r['status'] ? Mx::pill($r['status']) : ($late ? Mx::pill(__('Overdue'), 'bad') : Mx::pill(__('Planned'), 'neutral')) ?></td></tr>
            <?php endforeach ?></tbody></table></div>
        <?php endif ?>
    </section>

    <?php if ($m['is_calibration'] || $calRecords): ?>
    <section class="panel overflow-hidden">
        <?= $section(__('Calibration'), can('machines_calibration', 'view') ? '<a class="text-xs text-signal-700 underline" href="'.e(url('/machines/calibration?q='.rawurlencode($m['machine_code']))).'">'.e(__('Open list')).'</a>' : null) ?>
        <?php if (! $calRecords): echo $empty(__('No calibration planned.')); else: ?>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="text-xs text-steel"><tr><th class="px-5 py-2 font-medium"><?= e(__('Due')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Certificate')) ?></th><th class="px-3 py-2 font-medium"><?= e(__('Result')) ?></th></tr></thead><tbody class="divide-y divide-graphite-900/6">
            <?php foreach ($calRecords as $r): $late = ! $r['certificate_date'] && $r['due_date'] && $r['due_date'] < date('Y-m-d'); ?>
            <tr class="hover:bg-mist/40"><td class="px-5 py-2"><?= e($r['due_date'] ? format_date($r['due_date']) : '—') ?></td><td class="px-3 py-2"><?= e($r['certificate_date'] ? format_date($r['certificate_date']) : '—') ?></td>
                <td class="px-3 py-2"><?= $r['results'] ? Mx::pill($r['results']) : ($late ? Mx::pill(__('Overdue'), 'bad') : Mx::pill(__('Planned'), 'neutral')) ?></td></tr>
            <?php endforeach ?></tbody></table></div>
        <?php endif ?>
    </section>
    <?php endif ?>

    <section class="panel overflow-hidden">
        <?= $section(__('Latest checks'), can('machines_checklists', 'view') ? '<a class="text-xs text-signal-700 underline" href="'.e(url('/machines/checklists?q='.rawurlencode($m['machine_code']))).'">'.e(__('Open list')).'</a>' : null) ?>
        <?php if (! $checks): echo $empty(__('Nobody has checked this machine yet.')); else: ?>
        <ul class="divide-y divide-graphite-900/6"><?php foreach ($checks as $k): ?>
            <li><a class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-2.5 text-sm hover:bg-mist/40" href="<?= e(url('/machines/checklists/'.$k['id'])) ?>">
                <span class="tabular-nums text-steel"><?= e(format_date($k['created_at'], 'd M Y H:i')) ?></span><span class="min-w-0 flex-1 truncate"><?= e($k['user_name'] ?? '—') ?><?= $k['check_type'] === 'MAINTENANCE' ? ' · '.e(__('Maintenance')) : ($k['recheck'] ? ' · '.e(__('Re-check')) : '') ?></span>
                <?= Mx::pill($k['checklist_status']) ?></a></li>
        <?php endforeach ?></ul>
        <?php endif ?>
    </section>
</div>

<aside class="space-y-5">
    <section class="panel p-5 text-center">
        <div id="qr" data-qr="<?= e($qrText) ?>" class="mx-auto w-44 max-w-full"></div>
        <p class="mt-2 text-sm font-semibold tabular-nums"><?= e($m['machine_code']) ?></p>
        <p class="text-xs text-steel"><?= e(__('Scan to do the checklist')) ?></p>
        <div class="mt-3 flex gap-2"><a class="btn-secondary flex-1" target="_blank" href="<?= e(url('/machines/label?ids='.$m['id'])) ?>"><?= icon('download', 'size-4') ?> <?= e(__('Print')) ?></a>
            <button type="button" class="btn-secondary flex-1" data-copy="<?= e($link) ?>"><?= icon('link', 'size-4') ?> <?= e(__('Copy link')) ?></button></div>
    </section>
    <?php if ($canChange && Mx::isActive($m['machine_status'])): ?>
    <section class="panel p-5">
        <h2 class="text-sm font-semibold"><?= e(__('Change the responsible person')) ?></h2>
        <form method="POST" action="<?= url($c['base'].'/'.$m['id'].'/responsible') ?>" class="mt-3 space-y-3"><?= csrf_field() ?>
            <?= select_field('responsible_person_id', $people, (string) $m['responsible_person_id'], ['placeholder' => __('Choose…'), 'aria' => __('Responsible person')]) ?>
            <button class="btn-dark w-full"><?= e(__('Change')) ?></button>
        </form>
    </section>
    <?php endif ?>
    <section class="panel overflow-hidden">
        <?= $section(__('Who was responsible')) ?>
        <?php if (! $history): echo $empty('—'); else: ?>
        <ul class="divide-y divide-graphite-900/6"><?php foreach ($history as $h): ?><li class="px-5 py-2.5 text-sm"><span class="font-medium"><?= e($h['name']) ?></span><span class="block text-xs text-steel"><?= e(format_date($h['effective_from'])) ?> → <?= $h['effective_to'] ? e(format_date($h['effective_to'])) : e(__('now')) ?></span></li><?php endforeach ?></ul>
        <?php endif ?>
    </section>
</aside>
</div>
<script src="<?= asset('assets/vendor/qr/qrcode.js') ?>"></script>
<script src="<?= asset('assets/machines.js') ?>" defer></script>
