<?php /* Project approval badge. $p */
$tone = ['draft' => 'bg-amber-50 text-amber-800', 'approved' => 'bg-emerald-50 text-emerald-800', 'rejected' => 'bg-signal-50 text-signal-800', 'closed' => 'bg-graphite-900/8 text-graphite-700'][$p['status']] ?? '';
?><span class="badge shrink-0 <?= $tone ?>"><?= e(__(App\Core\Support\Everbetter::PROJECT[$p['status']] ?? $p['status'])) ?></span>
<?php if (! empty($p['award_rank'])): ?><span class="badge shrink-0 bg-amber-50 text-amber-900 ring-1 ring-amber-400/50"><?= [1 => '🥇', 2 => '🥈', 3 => '🥉'][(int) $p['award_rank']] ?> <?= e([1 => __('1st'), 2 => __('2nd'), 3 => __('3rd')][(int) $p['award_rank']]) ?> <?= (int) $p['award_year'] ?></span><?php endif ?>
