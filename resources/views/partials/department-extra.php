<div class="grid gap-6 <?= $teams ? 'lg:grid-cols-2' : '' ?>">
    <?php if ($teams): ?>
    <section class="panel">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Teams')) ?></h2></div>
        <ul class="divide-y divide-graphite-900/6">
            <?php foreach ($teams as $t): ?>
                <li class="flex items-center justify-between px-5 py-2.5 text-sm">
                    <a href="<?= url('/teams/'.$t['id']) ?>" class="font-medium hover:text-signal-700"><?= e($t['name']) ?></a>
                    <span class="text-steel"><?= e($t['lead_name'] ?? __('No lead')) ?></span>
                </li>
            <?php endforeach ?>
        </ul>
    </section>
    <?php endif ?>
    <section class="panel">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('People')) ?></h2><span class="text-xs text-steel"><?= count($members) ?></span></div>
        <ul class="divide-y divide-graphite-900/6">
            <?php foreach ($members as $m): ?>
                <li class="flex items-center gap-3 px-5 py-2.5">
                    <?= partial('partials/avatar', ['name' => $m['name'], 'avatar' => $m['avatar'], 'size' => 'size-8']) ?>
                    <div class="text-sm">
                        <?php if (can('members')): ?><a href="<?= url('/members/'.$m['id']) ?>" class="font-medium hover:text-signal-700"><?= e($m['name']) ?></a><?php else: ?><span class="font-medium"><?= e($m['name']) ?></span><?php endif ?>
                        <p class="text-xs text-steel"><?= e(__($m['role_name'])) ?></p>
                    </div>
                </li>
            <?php endforeach ?>
            <?php if (! $members): ?><li class="px-5 py-6 text-center text-sm text-steel"><?= e(__('Nobody here yet.')) ?></li><?php endif ?>
        </ul>
    </section>
</div>
