<?php
use App\Modules\Machines\Support\Files;
use App\Modules\Machines\Support\Mx;
/* $row, $c, $canEdit, $canDelete, $canMake */
$crumbs = [[__('Register requests'), $c['base']]];
$plan = fn (string $col) => json_decode((string) ($row[$col] ?? ''), true) ?: [];
$files = fn (string $col) => partial('machines/_files', ['list' => Files::decode($row[$col] ?? null)]);
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
        <h1 class="page-title break-words"><?= e($title) ?></h1>
        <p class="text-sm text-steel"><?= e(__('Request')) ?> #<?= (int) $row['id'] ?> · <?= e(__('by :name', ['name' => $row['created_by_name'] ?? '—'])) ?> · <?= e(format_date($row['created_at'])) ?></p>
    </div>
    <div class="flex w-full flex-wrap gap-2 sm:w-auto">
        <?php if ($canMake): ?><a href="<?= url('/machines/machines/create?register='.$row['id']) ?>" class="btn-dark flex-1 sm:flex-none"><?= icon('gear', 'size-4') ?> <?= e(__('Create the machine')) ?></a><?php endif ?>
        <?php if ($canDelete): ?><button type="button" class="btn-danger flex-1 sm:flex-none" data-delete-url="<?= e(url($c['base'].'/'.$row['id'].'/delete')) ?>" data-delete-name="<?= e($title) ?>" data-delete-kind="<?= e(__('register request')) ?>"><?= icon('trash', 'size-4') ?> <?= e(__('Delete')) ?></button><?php endif ?>
        <?php if ($canEdit): ?><a href="<?= url($c['base'].'/'.$row['id'].'/edit') ?>" class="btn-primary flex-1 sm:flex-none"><?= icon('pencil', 'size-4') ?> <?= e(__('Edit')) ?></a><?php endif ?>
    </div>
</div>

<?php if ((int) $row['machine_count'] > 0): ?>
<p class="mt-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-900"><?= e(__('Machine created:')) ?> <strong><?= e($row['machine_codes']) ?></strong></p>
<?php endif ?>

<section class="panel mt-5">
    <h2 class="border-b border-graphite-900/6 bg-mist/50 px-5 py-2 text-xs font-semibold text-steel"><?= e(__('Machine')) ?></h2>
    <?= partial('machines/_info', ['items' => [
        [__('Machine name'), $row['machine_name']], [__('Department'), $row['department_name']], [__('Bought new'), $row['is_new'] === null ? null : ($row['is_new'] ? __('Yes') : __('No'))],
        [__('Brand'), $row['brand']], [__('Model'), $row['model']], [__('Serial number'), $row['serial_number']],
        [__('Quantity'), $row['quantity']], [__('Price'), $row['price'] !== null ? number_format((float) $row['price'], 2) : null], [__('Power'), trim(($row['watt'] ? $row['watt'].' W ' : '').($row['horse_power'] ? $row['horse_power'].' HP' : ''))],
        [__('Note'), $row['note'], ['span' => 3]],
    ]]) ?>
    <h2 class="border-b border-graphite-900/6 bg-mist/50 px-5 py-2 text-xs font-semibold text-steel"><?= e(__('Who looks after it')) ?></h2>
    <?= partial('machines/_info', ['items' => [[__('Responsible person'), $row['responsible_id_name']], [__('Supervisor'), $row['supervisor_id_name']], [__('Manager'), $row['manager_id_name']]]]) ?>
    <h2 class="border-b border-graphite-900/6 bg-mist/50 px-5 py-2 text-xs font-semibold text-steel"><?= e(__('Maintenance and calibration plan')) ?></h2>
    <div class="grid gap-0 sm:grid-cols-2">
        <div class="border-b border-graphite-900/6 px-5 py-3.5"><p class="text-xs text-steel"><?= e(__('Maintenance rounds')) ?></p>
            <?php $m = $plan('maintenance'); if (! $m): ?><p class="mt-1 text-sm text-graphite-400">—</p><?php else: ?><ol class="mt-1 space-y-0.5 text-sm"><?php foreach ($m as $i => $p): ?><li><span class="text-steel">#<?= $i + 1 ?></span> <?= e(format_date($p['due_date'])) ?><?= ! empty($p['plan_date']) ? ' <span class="text-steel">· '.e(__('plan')).' '.e(format_date($p['plan_date'])).'</span>' : '' ?><?= ! empty($p['note']) ? ' · '.e($p['note']) : '' ?></li><?php endforeach ?></ol><?php endif ?></div>
        <div class="border-b border-graphite-900/6 px-5 py-3.5"><p class="text-xs text-steel"><?= e(__('Calibration due dates')) ?></p>
            <?php $m = $plan('calibration'); if (! $m): ?><p class="mt-1 text-sm text-graphite-400">—</p><?php else: ?><ol class="mt-1 space-y-0.5 text-sm"><?php foreach ($m as $p): ?><li><?= e(format_date($p['due_date'])) ?><?= ! empty($p['note']) ? ' · '.e($p['note']) : '' ?></li><?php endforeach ?></ol><?php endif ?></div>
    </div>
    <h2 class="border-b border-graphite-900/6 bg-mist/50 px-5 py-2 text-xs font-semibold text-steel"><?= e(__('Documents')) ?></h2>
    <?= partial('machines/_info', ['items' => [
        [__('Quotation, drawings, photos'), $files('attachment'), ['raw' => true]], [__('Work instruction'), $files('work_instruction'), ['raw' => true]],
        [__('Warranty'), $row['has_warranty'] === 'YES' ? __('Yes').($row['warranty_expire_date'] ? ' · '.__('ends :date', ['date' => format_date($row['warranty_expire_date'])]) : '') : ($row['has_warranty'] === 'NO' ? __('No') : null)],
        [__('Warranty note'), $row['warranty_note'], ['span' => 2]], [__('Warranty papers'), $files('warranty_files'), ['raw' => true]],
    ]]) ?>
</section>
