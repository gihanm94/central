<?php /* $crm, $canCreate, $google, $configured, $inCrm, $mine */
$crumbs = [];
$cfg = ['events' => url('/calendar/events'), 'create' => url('/crm/activities/create'), 'canCreate' => $canCreate, 'crm' => $crm, 'mine' => $mine, 'base' => url($inCrm ? '/crm/calendar' : '/calendar'),
    'i18n' => ['today' => __('Today'), 'month' => __('Month'), 'week' => __('Week'), 'agenda' => __('Agenda'), 'new' => __('New activity'), 'activity' => __('Activity'), 'nothing' => __('Nothing scheduled.'), 'allday' => __('All day'), 'open' => __('Open in Google Calendar'),
        'google' => __('Google Calendar'), 'view' => __('View activity'), 'edit' => __('Edit activity'), 'loading' => __('Loading…')],
    'days' => [__('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat'), __('Sun')]];
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0"><h1 class="page-title"><?= e(__('Calendar')) ?></h1>
        <p class="mt-1 text-sm text-steel"><?= e($crm ? __('CRM activities') : '') ?><?= $crm && $google ? ' + ' : '' ?><?= $google ? e(__('your Google Calendar')) : '' ?></p></div>
    <div class="flex flex-wrap items-center gap-2">
        <?php if ($crm): ?><a class="btn-secondary !h-9" href="<?= url($inCrm ? '/crm/calendar' : '/calendar', $mine ? [] : ['mine' => 1]) ?>"><?= e($mine ? __('Show everyone') : __('Only mine')) ?></a><?php endif ?>
        <?php if ($canCreate): ?><a class="btn-primary" href="<?= url('/crm/activities/create') ?>" data-sheet data-sheet-title="<?= e(__('New activity')) ?>"><?= e(__('New activity')) ?></a><?php endif ?>
    </div>
</div>
<?php if (! $google): ?><p class="mt-3 rounded-lg bg-white px-4 py-3 text-sm text-steel shadow-sm ring-1 ring-graphite-900/10">
    <?= $configured ? '<a class="font-medium text-signal-700 underline" href="'.e(url('/connect/google', ['back' => 'calendar'])).'">'.e(__('Connect Google')).'</a> '.e(__('to see your Google Calendar here and put every activity on it.')) : e(__('The administrator has not set up the Google connector yet — only CRM activities are shown.')) ?></p><?php endif ?>
<div class="mt-3 flex flex-wrap items-center gap-2" id="cal-bar">
    <button type="button" class="btn-secondary !h-9 !px-3" data-cal="prev">‹</button><button type="button" class="btn-secondary !h-9" data-cal="today"><?= e(__('Today')) ?></button><button type="button" class="btn-secondary !h-9 !px-3" data-cal="next">›</button>
    <h2 class="ml-2 text-lg font-semibold" id="cal-title"></h2>
    <nav class="ml-auto inline-flex gap-1 rounded-lg bg-white p-1 shadow-sm ring-1 ring-graphite-900/10"><?php foreach (['month' => 'Month', 'week' => 'Week', 'agenda' => 'Agenda'] as $k => $l): ?><button type="button" data-cal-view="<?= $k ?>" class="rounded-md px-3 py-1.5 text-sm"><?= e(__($l)) ?></button><?php endforeach ?></nav>
</div>
<div class="panel mt-3 overflow-hidden" id="cal" data-config="<?= e(json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>"></div>
<div id="cal-pop" class="fixed z-50 hidden w-72 rounded-xl bg-white p-4 text-sm shadow-xl ring-1 ring-graphite-900/15"></div>
<script src="<?= asset('assets/calendar.js') ?>" defer></script>
