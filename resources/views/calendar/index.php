<?php /* $crm, $canCreate, $google, $configured, $inCrm, $mine */
$crumbs = [];
$cfg = ['events' => url('/calendar/events'), 'create' => url('/crm/activities/create'), 'canCreate' => $canCreate, 'mine' => $mine, 'lang' => locale(),
    'i18n' => ['today' => __('Today'), 'month' => __('Month'), 'week' => __('Week'), 'day' => __('Day'), 'agenda' => __('Agenda'), 'new' => __('New activity'), 'activity' => __('Activity'), 'nothing' => __('Nothing scheduled.'), 'allday' => __('All day'),
        'open' => __('Open in Google Calendar'), 'google' => __('Google Calendar'), 'edit' => __('Edit activity'), 'more' => __('more')]];
$legend = ['CALL' => [__('Call'), '#0284c7'], 'MEETING' => [__('Meeting'), '#7c3aed'], 'EMAIL' => [__('E-mail'), '#64748b'], 'TASK' => [__('Task'), '#d97706'], 'GOOGLE' => [__('Google Calendar'), '#059669']];
?>
<link rel="stylesheet" href="<?= asset('assets/calendar.css') ?>">
<div class="cal-page flex flex-col" id="cal-page">
    <div class="flex flex-wrap items-center justify-between gap-3 pb-3">
        <div class="min-w-0"><h1 class="page-title"><?= e(__('Calendar')) ?></h1>
            <ul class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-xs text-steel"><?php foreach ($legend as $k => [$l, $c]): if ($k === 'GOOGLE' && ! $google) { continue; } ?><li class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full" style="background:<?= $c ?>"></span><?= e($l) ?></li><?php endforeach ?></ul></div>
        <div class="flex flex-wrap items-center gap-2">
            <?php if ($crm): ?><a class="btn-secondary !h-9" href="<?= url($inCrm ? '/crm/calendar' : '/calendar', $mine ? [] : ['mine' => 1]) ?>"><?= e($mine ? __('Show everyone') : __('Only mine')) ?></a><?php endif ?>
            <?php if ($canCreate): ?><a class="btn-primary !h-9" href="<?= url('/crm/activities/create') ?>" data-sheet data-sheet-title="<?= e(__('New activity')) ?>">+ <?= e(__('New activity')) ?></a><?php endif ?>
        </div>
    </div>
    <?php if (! $google): ?><p class="mb-3 rounded-lg bg-white px-4 py-2.5 text-sm text-steel shadow-sm ring-1 ring-graphite-900/10">
        <?= $configured ? '<a class="font-medium text-signal-700 underline" href="'.e(url('/connect/google', ['back' => 'calendar'])).'">'.e(__('Connect Google')).'</a> '.e(__('to see your Google Calendar here and put every activity on it.')) : e(__('The administrator has not set up the Google connector yet — only CRM activities are shown.')) ?></p><?php endif ?>
    <div class="panel min-h-0 flex-1 p-3 sm:p-4"><div id="cal" data-config="<?= e(json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>" style="height:100%"></div></div>
</div>
<div id="cal-pop" class="fixed z-50 hidden w-72 rounded-xl bg-white p-4 text-sm shadow-xl ring-1 ring-graphite-900/15"></div>
<script src="<?= asset('assets/vendor/fullcalendar/index.global.min.js') ?>"></script>
<script src="<?= asset('assets/calendar.js') ?>" defer></script>
