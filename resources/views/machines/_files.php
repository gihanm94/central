<?php use App\Modules\Machines\Support\Files; /* $list: decoded file list */ ?>
<?php if (! $list): ?>—<?php else: ?>
<ul class="space-y-1"><?php foreach ($list as $x): ?>
    <li><a class="inline-flex items-center gap-1.5 text-signal-700 underline-offset-2 hover:underline" href="<?= e(url('/machines/file', ['f' => $x['f']])) ?>" target="_blank" rel="noopener"><?= icon('doc', 'size-4') ?><?= e($x['n']) ?></a> <span class="text-xs text-steel"><?= e(number_format($x['s'] / 1024, 0)) ?> KB</span></li>
<?php endforeach ?></ul>
<?php endif ?>
