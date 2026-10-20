<?php /* $p (null = new), $people [id => {label, sub, img}], $memberIds */
$crumbs = [[__('Everbetter'), '/everbetter']];
$v = fn ($k) => old($k, $p[$k] ?? '');
$locked = $p && ! in_array($p['status'], ['draft', 'rejected'], true);   // approved: dates change through "Extend time"
?>
<h1 class="page-title"><?= e($p ? __('Edit project') : __('New Everbetter project')) ?></h1>
<form method="POST" action="<?= url($p ? '/everbetter/'.(int) $p['id'] : '/everbetter') ?>" class="panel mt-6 max-w-3xl space-y-5 p-6" data-eb-form>
    <?= csrf_field() ?>
    <div><label class="label" for="eb-title"><?= e(__('Project title')) ?></label>
        <input id="eb-title" name="title" value="<?= e((string) $v('title')) ?>" maxlength="200" required class="input <?= error_for('title') ? 'input-error' : '' ?>"><?= field_error('title') ?></div>
    <div><label class="label" for="eb-desc"><?= e(__('What will it improve?')) ?> <span class="font-normal text-steel">(<?= e(__('optional')) ?>)</span></label>
        <textarea id="eb-desc" name="description" rows="4" class="input"><?= e((string) $v('description')) ?></textarea></div>
    <div class="grid gap-4 sm:grid-cols-2">
        <div><label class="label" for="eb-start"><?= e(__('Start date')) ?></label><input id="eb-start" type="date" name="start_date" value="<?= e((string) $v('start_date')) ?>" required class="input" <?= $locked ? 'disabled' : '' ?>><?= field_error('start_date') ?></div>
        <div><label class="label" for="eb-end"><?= e(__('End date')) ?></label><input id="eb-end" type="date" name="end_date" value="<?= e((string) $v('end_date')) ?>" required class="input" <?= $locked ? 'disabled' : '' ?>><?= field_error('end_date') ?></div>
        <?php if ($locked): ?><p class="text-xs text-steel sm:col-span-2"><?= e(__('The project is approved: to change its end date, present "Extend time".')) ?></p><?php endif ?>
    </div>
    <div>
        <p class="label"><?= e(__('Project members')) ?></p>
        <ul class="mb-2 flex flex-wrap gap-2" data-eb-members>
            <?php foreach ($memberIds as $mid): if (! isset($people[$mid])) { continue; } $m = $people[$mid]; $owner = $p ? (int) $p['owner_id'] === $mid : $mid === auth()->id; ?>
            <li class="flex items-center gap-2 rounded-full bg-mist py-1 pl-1 pr-2 text-sm" data-id="<?= $mid ?>">
                <?= partial('partials/avatar', ['name' => $m['label'], 'avatar' => null, 'size' => 'size-6', 'extra' => 'text-[10px]']) ?><?= e($m['label']) ?>
                <input type="hidden" name="members[]" value="<?= $mid ?>">
                <?php if (! $owner): ?><button type="button" class="text-steel hover:text-signal-700" data-eb-remove aria-label="<?= e(__('Remove')) ?>">×</button><?php else: ?><span class="text-xs text-steel">(<?= e(__('owner')) ?>)</span><?php endif ?>
            </li>
            <?php endforeach ?>
        </ul>
        <div class="max-w-sm" data-eb-add><?= select_field('_add_member', $people, '', ['placeholder' => __('Add a member…'), 'search' => true, 'aria' => __('Add a member')]) ?></div>
    </div>
    <div class="flex justify-end gap-2 border-t border-graphite-900/8 pt-4">
        <a href="<?= url($p ? '/everbetter/'.(int) $p['id'] : '/everbetter') ?>" class="btn-ghost"><?= e(__('Cancel')) ?></a>
        <button class="btn-primary"><?= e($p ? __('Save') : __('Create project')) ?></button>
    </div>
</form>
<script>
(function () {
    var f = document.querySelector('[data-eb-form]'); if (!f) return;
    var list = f.querySelector('[data-eb-members]'), names = <?= json_encode(array_map(fn ($m) => $m['label'], $people), JSON_UNESCAPED_UNICODE) ?>;
    f.addEventListener('change', function (e) {
        if (e.target.name !== '_add_member' || !e.target.value) return;
        var id = e.target.value;
        if (!list.querySelector('[data-id="' + id + '"]')) {
            var li = document.createElement('li');
            li.className = 'flex items-center gap-2 rounded-full bg-mist py-1 pl-3 pr-2 text-sm'; li.dataset.id = id;
            li.textContent = names[id] || ('#' + id);
            li.insertAdjacentHTML('beforeend', '<input type="hidden" name="members[]" value="' + id + '"><button type="button" class="text-steel hover:text-signal-700" data-eb-remove aria-label="<?= e(__('Remove')) ?>">×</button>');
            list.appendChild(li);
        }
    });
    f.addEventListener('click', function (e) { var b = e.target.closest('[data-eb-remove]'); if (b) b.closest('li').remove(); });
})();
</script>
