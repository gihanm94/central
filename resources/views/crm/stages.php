<?php
use App\Modules\CRM\Support\Catalog;
use App\Modules\CRM\Support\Stages;
/* $stages (code => [label, …]), $colors, $deps [id => name], $hidden [code][dep] => true, $editableDeps [ids], $canColor, $tabs */
$crumbs = [[__('CRM settings'), '/crm/settings']];
$anyEdit = $editableDeps || $canColor;
?>
<div><h1 class="page-title"><?= e(__('Opportunity stages')) ?></h1>
    <p class="mt-1 max-w-3xl text-sm text-steel"><?= e(__('The stages and their names are fixed. Choose which stages each department can use: a department that does not use a stage cannot pick it on an opportunity and does not see it in the pipeline.')) ?></p></div>
<?= partial('partials/tabs', ['tabs' => $tabs]) ?>

<form method="POST" action="<?= url('/crm/settings/stages') ?>" class="mt-5">
    <?= csrf_field() ?>
    <?= field_error('use') ?>
    <section class="panel overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table w-max min-w-full">
                <thead><tr>
                    <th class="sticky left-0 z-[1] bg-white"><?= e(__('Stage')) ?></th>
                    <?php foreach ($deps as $id => $name): $edit = in_array($id, $editableDeps, true); ?>
                        <th class="text-center <?= $edit ? '' : 'text-graphite-400' ?>"><?= e($name) ?><?php if (! $edit && $anyEdit): ?><span class="block text-[10px] font-normal text-steel"><?= e(__('view only')) ?></span><?php endif ?></th>
                    <?php endforeach ?>
                </tr></thead>
                <tbody>
                <?php foreach ($stages as $code => $st): ?>
                    <tr>
                        <td class="sticky left-0 z-[1] bg-white">
                            <div class="flex items-center gap-3">
                                <input type="color" name="color[<?= e($code) ?>]" value="<?= e($colors[$code]) ?>" class="size-8 cursor-pointer rounded-md border-0 bg-transparent p-0 disabled:cursor-not-allowed disabled:opacity-60" <?= $canColor ? '' : 'disabled' ?> aria-label="<?= e(__('Colour of :stage', ['stage' => Catalog::stageLabel($code)])) ?>">
                                <?= \App\Modules\CRM\Support\Ui::stageBadge($code) ?>
                            </div>
                        </td>
                        <?php foreach ($deps as $id => $name): $edit = in_array($id, $editableDeps, true); $on = empty($hidden[$code][$id]); ?>
                            <td class="text-center">
                                <label class="inline-flex size-8 items-center justify-center <?= $edit ? 'cursor-pointer' : 'cursor-not-allowed opacity-60' ?>">
                                    <input type="checkbox" name="use[<?= (int) $id ?>][<?= e($code) ?>]" value="1" class="size-4 accent-signal-600" <?= $on ? 'checked' : '' ?> <?= $edit ? '' : 'disabled' ?> aria-label="<?= e($name.' · '.Catalog::stageLabel($code)) ?>">
                                </label>
                            </td>
                        <?php endforeach ?>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php if ($anyEdit): ?>
    <div class="mt-4 flex items-center justify-between gap-3">
        <p class="text-xs text-steel"><?= e($canColor ? __('You can change colours and any department.') : __('You can change your own department.')) ?> <?= e(__('Records already in a stage keep it.')) ?></p>
        <button class="btn-primary"><?= e(__('Save stage settings')) ?></button>
    </div>
    <?php endif ?>
</form>
