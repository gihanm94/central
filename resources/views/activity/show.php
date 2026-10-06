<?php $crumbs = [[__('Activity log'), '/activity']]; $props = $log['properties'] ? json_decode($log['properties'], true) : []; unset($props['_t']); ?>
<h1 class="page-title break-words"><?= e(activity_text($log)) ?></h1>
<p class="mt-1 text-sm text-steel"><?= e(format_date($log['created_at'], 'l d M Y, H:i:s')) ?></p>

<div class="mt-6 grid items-start gap-6 xl:grid-cols-3">
    <section class="panel">
        <dl class="divide-y divide-graphite-900/6 text-sm">
            <?php foreach ([__('Person') => ($log['user_name'] ?? __('System')).($log['user_email'] ? ' ('.$log['user_email'].')' : ''), __('Action') => App\Core\Http\Controllers\ActivityController::actions()[$log['action']] ?? $log['action'], __('Module') => __(config('modules.'.$log['module'].'.name', $log['module'])), __('Record') => $log['subject_type'] ? __($log['subject_type']).($log['subject_id'] ? ' #'.$log['subject_id'] : '').($log['subject_label'] ? ' — '.$log['subject_label'] : '') : null, __('IP address') => $log['ip_address'], __('Device') => $log['user_agent']] as $k => $v): ?>
                <div class="px-5 py-3"><dt class="text-xs text-steel"><?= e($k) ?></dt><dd class="mt-0.5 break-words font-medium"><?= e($v ?: '—') ?></dd></div>
            <?php endforeach ?>
        </dl>
    </section>
    <section class="panel xl:col-span-2">
        <div class="panel-head"><h2 class="panel-title"><?= e(__('Details')) ?></h2></div>
        <?php if (! empty($props['changes'])): ?>
            <div class="overflow-x-auto"><table class="table"><thead><tr><th><?= e(__('Field')) ?></th><th><?= e(__('Before')) ?></th><th><?= e(__('After')) ?></th></tr></thead><tbody>
                <?php foreach ($props['changes'] as $field => $c): ?>
                    <tr><td class="font-medium"><?= e(__(ucfirst(str_replace('_', ' ', (string) $field)))) ?></td><td class="text-steel line-through decoration-signal-600/40"><?= e(is_scalar($c['from'] ?? null) ? $c['from'] : json_encode($c['from'] ?? null)) ?></td><td><?= e(is_scalar($c['to'] ?? null) ? $c['to'] : json_encode($c['to'] ?? null)) ?></td></tr>
                <?php endforeach ?>
            </tbody></table></div>
        <?php endif ?>
        <?php $rest = array_diff_key($props ?? [], ['changes' => 1]); if ($rest): ?>
            <pre class="overflow-x-auto p-5 text-xs leading-5 text-graphite-800"><?= e(json_encode($rest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        <?php endif ?>
        <?php if (! $props): ?><p class="px-5 py-6 text-sm text-steel"><?= e(__('No extra details were recorded.')) ?></p><?php endif ?>
    </section>
</div>
