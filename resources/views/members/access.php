<?php
$crumbs = [[__('Members'), '/members'], [$row['name'], '/members/'.$row['id']]];
$actions = config('core.actions');
$scopes  = array_map(fn ($l) => __($l), config('core.scopes'));
$isAdmin = $row['role_slug'] === 'admin';
$on  = 'bg-graphite-800 text-white ring-graphite-800';
$off = 'bg-white text-steel ring-graphite-900/15';
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
        <h1 class="page-title"><?= e($title) ?></h1>
        <p class="mt-1 max-w-2xl text-sm text-steel"><?= __('Starts from the <strong class="text-graphite-900">:role</strong> role. Pick <em>Allow</em> or <em>Deny</em> to make an exception for this person only.', ['role' => e(__($row['role_name']))]) ?></p>
    </div>
    <a href="<?= url('/roles') ?>" class="btn-secondary w-full sm:w-auto"><?= e(__('Edit the role instead')) ?></a>
</div>

<?php if ($isAdmin): ?><p class="mt-6 rounded-md bg-graphite-900 px-4 py-3 text-sm text-white"><?= e(__('Admins always have full access. Change their role first if you need to limit them.')) ?></p><?php endif ?>

<form method="POST" action="<?= url('/members/'.$row['id'].'/access') ?>" class="mt-6 space-y-6">
    <?= csrf_field() ?>
    <fieldset <?= $canChange ? '' : 'disabled' ?> class="panel">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Which records they can see')) ?></h2></div>
        <div class="grid gap-3 p-4 sm:flex sm:items-center sm:gap-4 sm:p-5">
            <?= select_field('data_scope', $scopes, $row['data_scope'] ?? '', ['placeholder' => __('Role default (:scope)', ['scope' => $scopes[$roleScope] ?? $roleScope]), 'wrap' => 'sm:w-72', 'disabled' => ! $canChange, 'aria' => __('Which records they can see')]) ?>
            <p class="text-sm text-steel"><?= e(__('Department')) ?>: <?= e($row['department_name'] ?? '—') ?> · <?= e(__('Team')) ?>: <?= e($row['team_name'] ?? '—') ?></p>
        </div>
    </fieldset>

    <fieldset <?= $canChange ? '' : 'disabled' ?> class="panel overflow-hidden">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('What they can do')) ?></h2>
            <span class="hidden gap-3 text-xs text-steel sm:flex"><span class="flex items-center gap-1"><span class="size-2.5 rounded-sm bg-graphite-800"></span><?= e(__('Role allows')) ?></span><span class="flex items-center gap-1"><span class="size-2.5 rounded-sm bg-signal-600"></span><?= e(__('Exception')) ?></span></span>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead class="bg-mist/60"><tr><th class="sticky left-0 z-10 bg-mist"><?= e(__('Area')) ?></th><?php foreach ($actions as $a): ?><th class="text-center"><?= e(__(ucfirst($a))) ?></th><?php endforeach ?></tr></thead>
                <tbody>
                <?php foreach ($perms as $p): $rp = $roleMap[$p['id']] ?? []; $up = $userMap[$p['id']] ?? []; ?>
                    <tr>
                        <td class="sticky left-0 z-10 bg-white font-medium"><?= e(__($p['label'])) ?></td>
                        <?php foreach ($actions as $a):
                            $roleOn = filter_var($rp["can_{$a}"] ?? false, FILTER_VALIDATE_BOOL);
                            $over   = $up["can_{$a}"] ?? null;
                            $val    = $over === null ? '' : (filter_var($over, FILTER_VALIDATE_BOOL) ? 'allow' : 'deny'); ?>
                            <td class="text-center">
                                <?= select_field("perm[{$p['key']}][{$a}]", ['allow' => __('Allow'), 'deny' => __('Deny')], $val, [
                                    'placeholder' => __('Role (:v)', ['v' => $roleOn ? __('yes') : __('no')]),
                                    'size' => 'sm', 'wrap' => 'mx-auto w-28', 'disabled' => ! $canChange, 'aria' => __($p['label']).' '.__($a),
                                    'class' => 'rounded-md px-2 py-1 text-xs ring-1 '.($roleOn ? $on : $off),
                                    'tones' => ['allow' => '!bg-signal-600 !text-white !ring-signal-600', 'deny' => '!bg-signal-600 !text-white !ring-signal-600'],
                                ]) ?>
                            </td>
                        <?php endforeach ?>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </fieldset>
    <?php if ($canChange): ?>
        <div class="flex justify-end gap-2"><a href="<?= url('/members/'.$row['id']) ?>" class="btn-ghost"><?= e(__('Cancel')) ?></a><button class="btn-primary"><?= e(__('Save access rules')) ?></button></div>
    <?php elseif (! $isAdmin): ?>
        <p class="text-sm text-steel"><?= e(__('You can view these rules. Changing them needs the “Roles & permissions: edit” permission.')) ?></p>
    <?php endif ?>
</form>
