<?php
$labels = config('core.scopes');
$tones  = ['all' => 'bg-graphite-900 text-white', 'department' => 'bg-signal-600 text-white', 'team' => 'bg-signal-100 text-signal-800', 'own' => 'bg-graphite-900/8 text-graphite-800'];
?>
<span class="badge <?= $tones[$scope] ?? $tones['own'] ?>"><?= e(__($labels[$scope] ?? $scope)) ?></span>
