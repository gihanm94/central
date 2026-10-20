<?php /* Project approval badge. $p */
$tone = ['draft' => 'bg-amber-50 text-amber-800', 'approved' => 'bg-emerald-50 text-emerald-800', 'rejected' => 'bg-signal-50 text-signal-800', 'closed' => 'bg-graphite-900/8 text-graphite-700'][$p['status']] ?? '';
?><span class="badge shrink-0 <?= $tone ?>"><?= e(__(App\Core\Support\Everbetter::PROJECT[$p['status']] ?? $p['status'])) ?></span>
