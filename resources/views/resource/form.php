<?php
use App\Core\Support\Html;
$isEdit = $row !== null;
$crumbs = [[__(ucfirst($c['plural'])), $c['base']]];
if ($isEdit) { $crumbs[] = [str_limit((string) ($row['name'] ?? $row['name_en'] ?? $row['title'] ?? $row['topic'] ?? '#'.$row['id']), 30), $c['base'].'/'.$row['id']]; }
$value = function (string $name, array $f) use ($row) {
    $v = old($name, $row[$name] ?? ($f['default'] ?? ''));
    if (($f['type'] ?? '') === 'date' && $v) { $v = substr((string) $v, 0, 10); }
    if (($f['type'] ?? '') === 'datetime' && $v) { $v = date('Y-m-d\TH:i', strtotime((string) $v)); }
    if (($f['type'] ?? '') === 'number' && $v !== '' && $v !== null) { $v = str_replace(',', '', number_clean($v)); }
    return $v;
};

// Sections become steps. Fields before the first section go into a first, untitled step.
$steps = []; $cur = ['title' => __('Details'), 'fields' => []];
foreach ($fields as $name => $f) {
    if (isset($f['section'])) { if ($cur['fields']) { $steps[] = $cur; } $cur = ['title' => $f['section'], 'fields' => []]; continue; }
    $cur['fields'][$name] = $f;
}
if ($cur['fields']) { $steps[] = $cur; }
$wizard = ! empty($c['wizard']) && count($steps) > 1;
$errStep = 0;
foreach ($steps as $i => $st) { foreach ($st['fields'] as $n => $_) { if (error_for($n)) { $errStep = $i; break 2; } } }

$renderField = function (string $name, array $f) use ($value, $row) {
    ob_start();
    $type = $f['type'] ?? 'text';
    $v    = $value($name, $f);
    $ro   = ! empty($f['readonly']);
    $err  = error_for($name);
    $req  = str_contains($f['rules'] ?? '', 'required') && ! $ro && $type !== 'checkbox';
    $lbl  = fn () => e($f['label']).($req ? ' <span class="text-signal-600" aria-hidden="true">*</span><span class="sr-only">('.e(__('required')).')</span>' : '');
    ?>
    <?php if ($type === 'custom'): ?>
        <?= partial($f['partial'], ['name' => $name, 'f' => $f, 'row' => $row, 'v' => $v, 'err' => $err]) ?>
    <?php elseif ($type === 'multi'):
        $picked = array_map('strval', (array) old($name, $row[$name.'_ids'] ?? [])); ?>
        <span class="label"><?= $lbl() ?></span>
        <div class="flex flex-wrap gap-2" role="group" aria-label="<?= e($f['label']) ?>">
            <?php foreach ($f['choices'] as $cv => $cl): $on = in_array((string) $cv, $picked, true); ?>
                <label class="cursor-pointer">
                    <input type="checkbox" name="<?= e($name) ?>[]" value="<?= e($cv) ?>" class="peer sr-only" <?= $on ? 'checked' : '' ?>>
                    <span class="inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-sm ring-1 ring-graphite-900/15 transition-colors hover:bg-mist peer-checked:bg-graphite-900 peer-checked:text-white peer-checked:ring-graphite-900 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-signal-600"><?= e($cl) ?></span>
                </label>
            <?php endforeach ?>
            <?php if (! $f['choices']): ?><span class="text-sm text-steel"><?= e($f['empty'] ?? __('Nothing to choose from yet.')) ?></span><?php endif ?>
        </div>
        <?php if (! empty($f['help'])): ?><p class="hint"><?= e($f['help']) ?></p><?php endif ?>
    <?php elseif ($type === 'file'): ?>
        <span class="label"><?= $lbl() ?></span>
        <div class="flex items-center gap-4">
            <img id="prev-<?= e($name) ?>" src="<?= e(upload_url($row[$name] ?? null)) ?>" alt="" class="size-16 rounded-lg bg-white object-contain ring-1 ring-graphite-900/10" <?= empty($row[$name]) ? 'hidden' : '' ?>>
            <span data-preview-ph class="flex size-16 items-center justify-center rounded-lg bg-mist text-graphite-400 ring-1 ring-graphite-900/10" <?= ! empty($row[$name]) ? 'hidden' : '' ?>><?= icon('photo', 'size-6') ?></span>
            <div>
                <label class="btn-secondary cursor-pointer"><?= icon('upload', 'size-4') ?> <?= e(__('Choose image')) ?>
                    <input id="f-<?= e($name) ?>" name="<?= e($name) ?>" type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" data-preview="#prev-<?= e($name) ?>"></label>
                <?php if (! empty($f['help'])): ?><p class="hint"><?= e($f['help']) ?></p><?php endif ?>
            </div>
        </div>
        <?php if ($err): ?><p class="error"><?= e($err) ?></p><?php endif ?>
    <?php elseif ($type === 'richtext'): ?>
        <span class="label" id="lbl-<?= e($name) ?>"><?= $lbl() ?></span>
        <div data-richtext data-upload="<?= e(url('/editor/image')) ?>" data-assets="<?= e(url('assets/vendor/quill')) ?>" data-labelledby="lbl-<?= e($name) ?>">
            <textarea hidden name="<?= e($name) ?>"><?= e(Html::render((string) $v)) ?></textarea>
            <div data-rt-editor class="rt-editor"></div>
        </div>
        <?php if (! empty($f['help'])): ?><p class="hint"><?= e($f['help']) ?></p><?php endif ?>
        <?php if ($err): ?><p class="error"><?= e($err) ?></p><?php endif ?>
    <?php elseif ($type === 'checkbox'): ?>
        <label class="flex items-start gap-3 text-sm sm:pt-6">
            <input type="checkbox" name="<?= e($name) ?>" value="1" class="mt-0.5 size-4 accent-signal-600" <?= filter_var(old($name, $row ? ($row[$name] ?? false) : ($f['default'] ?? false)), FILTER_VALIDATE_BOOL) ? 'checked' : '' ?> <?= $ro ? 'disabled' : '' ?>>
            <span><span class="font-medium"><?= e($f['label']) ?></span><?php if (! empty($f['help'])): ?><span class="block text-xs text-steel"><?= e($f['help']) ?></span><?php endif ?></span>
        </label>
    <?php else: ?>
        <label for="f-<?= e($name) ?>" class="label"><?= $lbl() ?></label>
        <?php if ($type === 'textarea'): ?>
            <textarea id="f-<?= e($name) ?>" name="<?= e($name) ?>" rows="4" class="input <?= $err ? 'input-error' : '' ?>" <?= $ro ? 'disabled' : '' ?>><?= e($v) ?></textarea>
        <?php elseif ($type === 'select' && ! empty($f['remote'])): ?>
            <?= partial('partials/remote-select', ['name' => $name, 'value' => (string) $v, 'current' => $f['current'] ?? null, 'kind' => $f['remote'], 'o' => ['id' => 'f-'.$name, 'placeholder' => $f['placeholder'] ?? __('Search and choose…'), 'required' => $req, 'depends' => $f['depends'] ?? null]]) ?>
        <?php elseif ($type === 'select'):
            $opts = $f['options'];
            if ($v !== '' && $v !== null && ! isset($opts[$v])) { $opts = ($f['all_options'] ?? []) + $opts; } ?>
            <?= select_field($name, $opts, $v, ['id' => 'f-'.$name, 'placeholder' => $f['placeholder'] ?? __('Choose…'), 'disabled' => $ro, 'class' => ($f['rich'] ?? false) ? null : 'input'.($err ? ' input-error' : ''), 'required' => $req, 'search' => $f['search'] ?? null]) ?>
        <?php else: ?>
            <input id="f-<?= e($name) ?>" name="<?= e($name) ?>" type="<?= e($type === 'datetime' ? 'datetime-local' : $type) ?>" <?= $type === 'number' ? 'step="any" inputmode="decimal"' : '' ?>
                   value="<?= $type === 'password' ? '' : e($v) ?>" <?= $type === 'password' ? 'autocomplete="new-password"' : '' ?>
                   class="input <?= $err ? 'input-error' : '' ?> <?= $type === 'color' ? '!w-20 p-1' : '' ?>" <?= $ro ? 'disabled' : '' ?>>
        <?php endif ?>
        <?php if (! empty($f['help'])): ?><p class="hint"><?= e($f['help']) ?></p><?php endif ?>
        <?php if ($ro): ?><p class="hint"><?= e(__("You can't change this.")) ?></p><?php endif ?>
        <?php if ($err): ?><p class="error"><?= e($err) ?></p><?php endif ?>
    <?php endif ?>
    <?php return ob_get_clean();
};
$saveLabel = $isEdit ? __('Save changes') : __('Create :item', ['item' => __($c['singular'])]);
?>
<div id="form-shell" class="[&:fullscreen]:overflow-auto [&:fullscreen]:bg-mist [&:fullscreen]:p-6">
<form method="POST" action="<?= url($isEdit ? $c['base'].'/'.$row['id'] : $c['base']) ?>" enctype="multipart/form-data" novalidate data-no-busy
      data-form data-msg-missing="<?= e(__('required missing')) ?>" data-msg-done="<?= e(__('Complete')) ?>" data-msg-optional="<?= e(__('Optional')) ?>" <?= $wizard ? 'data-wizard data-start="'.(int) $errStep.'"' : '' ?>>
    <?= csrf_field() ?>

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="page-title"><?= e($title) ?></h1>
            <!-- Required fields: how many are filled -->
            <div data-req-summary hidden class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                <div class="flex items-center gap-2.5">
                    <div class="h-1.5 w-36 overflow-hidden rounded-full bg-graphite-900/10" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-req-progress><div data-req-bar class="h-full rounded-full bg-signal-600 transition-all" style="width:0%"></div></div>
                    <span class="tabular-nums text-steel"><span class="font-semibold text-graphite-900" data-req-filled>0</span> <?= e(__('of')) ?> <span data-req-total>0</span> <?= e(__('required fields filled')) ?></span>
                </div>
                <button type="button" class="btn-secondary !h-7 text-xs" data-req-open><?= icon('list', 'size-3.5') ?> <?= e(__('View required fields')) ?></button>
            </div>
        </div>
        <button type="button" class="btn-secondary" data-fullscreen title="<?= e(__('Full screen')) ?>"><?= icon('expand', 'size-4') ?><span class="hidden sm:inline"> <?= e(__('Full screen')) ?></span></button>
    </div>

    <div class="mt-6 grid items-start gap-5 <?= $wizard ? 'lg:grid-cols-[15.5rem_minmax(0,1fr)]' : '' ?>">
        <?php if ($wizard): ?>
        <nav class="lg:sticky lg:top-20" aria-label="<?= e(__('Steps')) ?>">
            <ol class="flex gap-1 overflow-x-auto rounded-xl bg-white p-1.5 shadow-sm ring-1 ring-graphite-900/8 lg:flex-col lg:overflow-visible" data-stepper>
                <?php foreach ($steps as $i => $st): ?>
                <li class="min-w-fit lg:min-w-0">
                    <button type="button" data-go="<?= $i ?>" class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left transition-colors hover:bg-graphite-900/5">
                        <span data-dot class="flex size-7 shrink-0 items-center justify-center rounded-full bg-graphite-900/8 text-xs font-semibold"><?= $i + 1 ?></span>
                        <span class="min-w-0"><span class="block truncate text-sm font-medium"><?= e($st['title']) ?></span><span class="block text-xs text-steel" data-step-note>&nbsp;</span></span>
                    </button>
                </li>
                <?php endforeach ?>
            </ol>
        </nav>
        <?php endif ?>

        <div class="min-w-0 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-graphite-900/8">
            <?php foreach ($steps as $i => $st): ?>
            <section data-step="<?= $i ?>" <?= $wizard && $i !== $errStep ? 'hidden' : '' ?> aria-label="<?= e($st['title']) ?>" class="<?= ! $wizard && $i > 0 ? 'border-t border-graphite-900/8' : '' ?>">
                <div class="px-6 pt-6">
                    <?php if ($wizard): ?><p class="text-xs font-medium text-steel"><?= e(__('Step :n of :t', ['n' => $i + 1, 't' => count($steps)])) ?></p><?php endif ?>
                    <h2 class="text-lg font-semibold tracking-tight" data-step-title><?= e($st['title']) ?></h2>
                </div>
                <div class="grid gap-x-6 gap-y-5 px-6 py-6 md:grid-cols-2 2xl:grid-cols-3">
                <?php foreach ($st['fields'] as $name => $f):
                    $req  = str_contains($f['rules'] ?? '', 'required') && empty($f['readonly']) && ($f['type'] ?? '') !== 'checkbox';
                    $full = ($f['span'] ?? 1) === 2 ? 'md:col-span-2 2xl:col-span-3' : ''; ?>
                    <div class="min-w-0 <?= $full ?>" data-field="<?= e($name) ?>" data-label="<?= e($f['label']) ?>" <?= $req ? 'data-required' : '' ?> <?= ! empty($f['show_when']) ? 'data-crm-show="'.e($f['show_when']).'"' : '' ?>>
                        <?= $renderField($name, $f) ?>
                    </div>
                <?php endforeach ?>
                </div>
            </section>
            <?php endforeach ?>

            <div class="sticky bottom-0 z-10 flex flex-wrap items-center justify-between gap-2 border-t border-graphite-900/8 bg-white px-6 py-3">
                <div><?php if ($wizard): ?><button type="button" class="btn-secondary" data-prev><?= icon('arrowleft', 'size-4') ?> <?= e(__('Back')) ?></button><?php endif ?></div>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="<?= url($isEdit ? $c['base'].'/'.$row['id'] : $c['base']) ?>" class="btn-ghost"><?= e(__('Cancel')) ?></a>
                    <?php if ($wizard): ?><button type="button" class="btn-secondary" data-next><?= e(__('Next')) ?> <?= icon('right', 'size-4') ?></button><?php endif ?>
                    <button type="submit" class="btn-primary" data-save disabled title="<?= e(__('Fill in all required fields first')) ?>"><?= e($saveLabel) ?></button>
                </div>
            </div>
        </div>
    </div>

    <dialog id="required-dialog" class="m-auto w-[min(30rem,calc(100vw-2rem))] rounded-xl bg-white p-0 text-graphite-900 shadow-2xl ring-1 ring-graphite-900/10 backdrop:bg-graphite-950/60" aria-labelledby="required-title">
        <div class="p-5">
            <h2 id="required-title" class="text-base font-semibold"><?= e(__('Required fields')) ?></h2>
            <p class="mt-0.5 text-sm text-steel"><span data-req-filled>0</span> <?= e(__('of')) ?> <span data-req-total>0</span> <?= e(__('filled')) ?>. <?= e(__('Select a field to go to it.')) ?></p>
            <ul class="mt-4 max-h-[50vh] space-y-1 overflow-y-auto" data-req-list></ul>
        </div>
        <div class="flex justify-end rounded-b-xl bg-mist/60 px-5 py-3"><button type="button" class="btn-dark" data-req-close><?= e(__('Close')) ?></button></div>
    </dialog>
</form>
</div>
