<?php $tone = ['urgent' => 'bg-signal-600 text-white', 'high' => 'bg-signal-50 text-signal-800', 'medium' => 'bg-graphite-900/6 text-graphite-800', 'low' => 'bg-graphite-900/4 text-steel'][$p] ?? ''; ?>
<span class="badge <?= $tone ?>"><?= e(__(ucfirst((string) $p))) ?></span>
