<?php /* The questions of a checklist as big tap targets. $items [id, detail, description, is_choice], $old = answers posted before */ $old = is_array($old ?? null) ? $old : []; ?>
<ol class="space-y-3">
        <?php foreach ($items as $n => $i): $o = (array) ($old[$i['id']] ?? []); $ans = (string) ($o['answer'] ?? ''); ?>
        <li class="panel p-4" data-q>
            <p class="text-sm font-medium"><span class="mr-1 text-steel"><?= $n + 1 ?>.</span><?= e($i['detail']) ?></p>
            <?php if ($i['description']): ?><p class="mt-0.5 text-xs text-steel"><?= e($i['description']) ?></p><?php endif ?>
            <?php if ($i['is_choice']): ?>
            <div class="mt-3 grid grid-cols-3 gap-2" role="radiogroup" aria-label="<?= e($i['detail']) ?>">
                <?php foreach (['OK' => ['OK', 'peer-checked:bg-emerald-600 peer-checked:ring-emerald-600'], 'NG' => ['NG', 'peer-checked:bg-red-600 peer-checked:ring-red-600'], 'NA' => ['N/A', 'peer-checked:bg-graphite-600 peer-checked:ring-graphite-600']] as $k => [$l, $cls]): ?>
                <label class="cursor-pointer"><input type="radio" name="answers[<?= (int) $i['id'] ?>][answer]" value="<?= $k ?>" class="peer sr-only" data-ans <?= $ans === $k ? 'checked' : '' ?>>
                    <span class="flex h-12 items-center justify-center rounded-xl bg-white text-base font-semibold ring-1 ring-graphite-900/15 transition-colors peer-checked:text-white peer-focus-visible:outline-2 peer-focus-visible:outline-signal-600 <?= $cls ?>"><?= e($l) ?></span></label>
                <?php endforeach ?>
            </div>
            <div data-remark class="mt-2" <?= $ans === 'NG' ? '' : 'hidden' ?>><input name="answers[<?= (int) $i['id'] ?>][remark]" value="<?= e($o['remark'] ?? '') ?>" maxlength="500" placeholder="<?= e(__('What is wrong?')) ?>" class="input !h-11" aria-label="<?= e(__('What is wrong?')) ?>"></div>
            <?php else: ?>
            <input name="answers[<?= (int) $i['id'] ?>][answer]" value="<?= e($ans) ?>" maxlength="200" inputmode="decimal" data-ans-text placeholder="<?= e(__('Type the reading')) ?>" class="input mt-3 !h-11" aria-label="<?= e($i['detail']) ?>">
            <?php endif ?>
        </li>
        <?php endforeach ?>
    </ol>
