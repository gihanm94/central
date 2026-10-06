<?php
$isEdit = $row !== null;
$crumbs = [[__(ucfirst($c['plural'])), $c['base']]];
if ($isEdit) { $crumbs[] = [str_limit((string) ($row['name'] ?? $row['title'] ?? '#'.$row['id']), 30), $c['base'].'/'.$row['id']]; }
$value = function (string $name, array $f) use ($row) {
    $v = old($name, $row[$name] ?? ($f['default'] ?? ''));
    if (($f['type'] ?? '') === 'date' && $v) { $v = substr((string) $v, 0, 10); }
    if (($f['type'] ?? '') === 'datetime' && $v) { $v = date('Y-m-d\TH:i', strtotime((string) $v)); }
    if (($f['type'] ?? '') === 'number' && $v !== '' && $v !== null) { $v = str_replace(',', '', number_clean($v)); }
    return $v;
};
?>
<h1 class="page-title"><?= e($title) ?></h1>

<form method="POST" action="<?= url($isEdit ? $c['base'].'/'.$row['id'] : $c['base']) ?>" class="panel mt-6 max-w-4xl" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <div class="grid gap-5 p-4 sm:grid-cols-2 sm:p-6">
        <?php foreach ($fields as $name => $f):
            if (isset($f['section'])): ?>
                <h2 class="-mb-1 border-t border-graphite-900/8 pt-5 text-sm font-semibold text-graphite-900 sm:col-span-2"><?= e($f['section']) ?></h2>
            <?php continue; endif;
            $type = $f['type'] ?? 'text';
            $v    = $value($name, $f);
            $ro   = ! empty($f['readonly']);
            $err  = error_for($name);
            $span = ($f['span'] ?? 1) === 2 ? 'sm:col-span-2' : '';
            $req  = str_contains($f['rules'] ?? '', 'required');
        ?>
        <div class="<?= $span ?>" <?= ! empty($f['show_when']) ? 'data-crm-show="'.e($f['show_when']).'"' : '' ?>>
            <?php if ($type === 'custom'): ?>
                <?= partial($f['partial'], ['name' => $name, 'f' => $f, 'row' => $row, 'v' => $v, 'err' => $err]) ?>
            <?php elseif ($type === 'multi'):
                $picked = array_map('strval', (array) old($name, $row[$name.'_ids'] ?? [])); ?>
                <span class="label"><?= e($f['label']) ?> <span class="font-normal text-steel">(<?= e(__('optional')) ?>)</span></span>
                <div class="flex flex-wrap gap-2" role="group" aria-label="<?= e($f['label']) ?>">
                    <?php foreach ($f['choices'] as $cv => $cl): $on = in_array((string) $cv, $picked, true); ?>
                        <label class="cursor-pointer">
                            <input type="checkbox" name="<?= e($name) ?>[]" value="<?= e($cv) ?>" class="peer sr-only" <?= $on ? 'checked' : '' ?>>
                            <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm ring-1 ring-graphite-900/15 transition-colors hover:bg-mist peer-checked:bg-graphite-900 peer-checked:text-white peer-checked:ring-graphite-900 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-signal-600"><?= e($cl) ?></span>
                        </label>
                    <?php endforeach ?>
                    <?php if (! $f['choices']): ?><span class="text-sm text-steel"><?= e($f['empty'] ?? __('Nothing to choose from yet.')) ?></span><?php endif ?>
                </div>
                <?php if (! empty($f['help'])): ?><p class="hint"><?= e($f['help']) ?></p><?php endif ?>
            <?php elseif ($type === 'file'): ?>
                <label for="f-<?= e($name) ?>" class="label"><?= e($f['label']) ?> <span class="font-normal text-steel">(<?= e(__('optional')) ?>)</span></label>
                <div class="flex items-center gap-3">
                    <?php if (! empty($row[$name])): ?><img src="<?= e(upload_url($row[$name])) ?>" alt="" class="size-12 rounded-md object-cover ring-1 ring-graphite-900/10"><?php endif ?>
                    <input id="f-<?= e($name) ?>" name="<?= e($name) ?>" type="file" accept="image/png,image/jpeg,image/webp" class="w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-graphite-900 file:px-3 file:py-1.5 file:text-sm file:text-white">
                </div>
                <?php if (! empty($f['help'])): ?><p class="hint"><?= e($f['help']) ?></p><?php endif ?>
                <?php if ($err): ?><p class="error"><?= e($err) ?></p><?php endif ?>
            <?php elseif ($type === 'checkbox'): ?>
                <label class="flex items-start gap-3 text-sm sm:pt-6">
                    <input type="checkbox" name="<?= e($name) ?>" value="1" class="mt-0.5 size-4 accent-signal-600" <?= filter_var(old($name, $row ? ($row[$name] ?? false) : ($f['default'] ?? false)), FILTER_VALIDATE_BOOL) ? 'checked' : '' ?> <?= $ro ? 'disabled' : '' ?>>
                    <span><span class="font-medium"><?= e($f['label']) ?></span><?php if (! empty($f['help'])): ?><span class="block text-xs text-steel"><?= e($f['help']) ?></span><?php endif ?></span>
                </label>
            <?php else: ?>
                <label for="f-<?= e($name) ?>" class="label"><?= e($f['label']) ?><?php if (! $req && ! $ro): ?> <span class="font-normal text-steel">(<?= e(__('optional')) ?>)</span><?php endif ?></label>
                <?php if ($type === 'textarea'): ?>
                    <textarea id="f-<?= e($name) ?>" name="<?= e($name) ?>" rows="4" class="input <?= $err ? 'input-error' : '' ?>" <?= $ro ? 'disabled' : '' ?>><?= e($v) ?></textarea>
                <?php elseif ($type === 'select'):
                    $opts = $f['options'];
                    if ($v !== '' && $v !== null && ! isset($opts[$v])) { $opts = ($f['all_options'] ?? []) + $opts; } ?>
                    <?= select_field($name, $opts, $v, ['id' => 'f-'.$name, 'placeholder' => __('Choose…'), 'disabled' => $ro, 'class' => 'input'.($err ? ' input-error' : ''), 'required' => $req]) ?>
                <?php else: ?>
                    <input id="f-<?= e($name) ?>" name="<?= e($name) ?>" type="<?= e($type) ?>" <?= $type === 'number' ? 'step="any" inputmode="decimal"' : '' ?>
                           value="<?= $type === 'password' ? '' : e($v) ?>" <?= $type === 'password' ? 'autocomplete="new-password"' : '' ?>
                           class="input <?= $err ? 'input-error' : '' ?>" <?= $ro ? 'disabled' : '' ?>>
                <?php endif ?>
                <?php if (! empty($f['help'])): ?><p class="hint"><?= e($f['help']) ?></p><?php endif ?>
                <?php if ($ro): ?><p class="hint"><?= e(__("You can't change this.")) ?></p><?php endif ?>
                <?php if ($err): ?><p class="error"><?= e($err) ?></p><?php endif ?>
            <?php endif ?>
        </div>
        <?php endforeach ?>
    </div>
    <div class="sticky bottom-0 flex items-center justify-end gap-2 rounded-b-lg border-t border-graphite-900/8 bg-white/95 px-4 py-3 backdrop-blur sm:static sm:px-6">
        <a href="<?= url($isEdit ? $c['base'].'/'.$row['id'] : $c['base']) ?>" class="btn-ghost"><?= e(__('Cancel')) ?></a>
        <button class="btn-primary"><?= e($isEdit ? __('Save changes') : __('Create :item', ['item' => __($c['singular'])])) ?></button>
    </div>
</form>
