<?php /* $row — a planned activity */ $late = $row['start_at'] && strtotime($row['start_at']) < time(); ?>
<section class="panel mt-6 flex flex-wrap items-center justify-between gap-3 p-4 <?= $late ? 'ring-signal-600/40' : '' ?>">
    <p class="flex items-center gap-2 text-sm"><?= icon('clock', 'size-4 text-steel') ?>
        <?= e($late ? __('This activity was due :time.', ['time' => time_ago($row['start_at'])]) : __('This activity is planned.')) ?></p>
    <form method="POST" action="<?= url('/crm/activities/'.$row['id'].'/status') ?>" class="flex gap-2"><?= csrf_field() ?>
        <button name="status" value="CANCELLED" class="btn-secondary py-1.5"><?= e(__('Cancel activity')) ?></button>
        <button name="status" value="DONE" class="btn-dark py-1.5"><?= icon('tick', 'size-4') ?> <?= e(__('Mark as done')) ?></button>
    </form>
</section>
