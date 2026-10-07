<?php
/* $r, $departments, $guess, $roles, $teams, $people */
$crumbs = [[__('Members'), '/members'], [__('Account requests'), '/members/requests']];
$busy = $r['status'] !== 'pending';
$v = fn (string $k, $d = '') => old($k, $d);
?>
<div class="mx-auto max-w-3xl">
    <h1 class="page-title"><?= e(__('Account request')) ?></h1>
    <p class="mt-1 text-sm text-steel"><?= e(__('Sent :when from :ip', ['when' => format_date($r['created_at'], 'd M Y H:i'), 'ip' => $r['ip_address'] ?? '—'])) ?></p>

    <?php if ($busy): ?><p class="mt-4 rounded-lg bg-white px-4 py-3 text-sm shadow-sm ring-1 ring-graphite-900/8"><?= e($r['status'] === 'approved' ? __('This request was approved.') : __('This request was rejected.')) ?></p><?php else: ?>

    <form method="POST" action="<?= url('/members/requests/'.$r['id'].'/approve') ?>" class="panel mt-5 overflow-hidden" novalidate><?= csrf_field() ?>
        <h2 class="border-b border-graphite-900/6 bg-mist/50 px-5 py-2 text-xs font-semibold text-steel"><?= e(__('What the person typed (you can correct it)')) ?></h2>
        <div class="grid gap-x-6 gap-y-5 p-5 sm:grid-cols-2">
            <div><label class="label" for="f-employee_code"><?= e(__('Employee ID')) ?> *</label><input id="f-employee_code" name="employee_code" value="<?= e($v('employee_code', $r['employee_code'])) ?>" class="input" maxlength="30"><?= field_error('employee_code') ?></div>
            <div><label class="label" for="f-name"><?= e(__('Full name')) ?> *</label><input id="f-name" name="name" value="<?= e($v('name', $r['name'])) ?>" class="input" maxlength="120"><?= field_error('name') ?></div>
            <div><label class="label" for="f-email"><?= e(__('Work e-mail')) ?> *</label><input id="f-email" type="email" name="email" value="<?= e($v('email', $r['email'])) ?>" class="input"><?= field_error('email') ?></div>
            <div><label class="label" for="f-phone"><?= e(__('Phone')) ?></label><input id="f-phone" name="phone" value="<?= e($v('phone', $r['phone'])) ?>" class="input" maxlength="30"><?= field_error('phone') ?></div>
            <div><label class="label"><?= e(__('Gender')) ?></label><?= select_field('gender', enum_options('genders'), (string) $v('gender', $r['gender']), ['placeholder' => __('Choose…'), 'class' => 'input']) ?></div>
            <div><span class="label"><?= e(__('Department they typed')) ?></span><p class="rounded-md bg-amber-50 px-3 py-2 text-sm font-medium text-amber-900"><?= e($r['department_text'] ?: '—') ?></p></div>
        </div>
        <h2 class="border-y border-graphite-900/6 bg-mist/50 px-5 py-2 text-xs font-semibold text-steel"><?= e(__('What you decide')) ?></h2>
        <div class="grid gap-x-6 gap-y-5 p-5 sm:grid-cols-2">
            <div><label class="label"><?= e(__('Department')) ?> *</label><?= select_field('department_id', $departments, (string) $v('department_id', $guess), ['placeholder' => __('Choose the department…'), 'class' => 'input']) ?><?= field_error('department_id') ?></div>
            <div><label class="label"><?= e(__('Role')) ?> *</label><?= select_field('role_id', $roles, (string) $v('role_id', ''), ['placeholder' => __('Choose the role…'), 'class' => 'input']) ?><?= field_error('role_id') ?></div>
            <div><label class="label"><?= e(__('Team')) ?></label><?= select_field('team_id', $teams, (string) $v('team_id', ''), ['placeholder' => __('No team'), 'class' => 'input']) ?><?= field_error('team_id') ?></div>
            <div><label class="label"><?= e(__('Reports to')) ?></label><?= select_field('manager_id', $people, (string) $v('manager_id', ''), ['placeholder' => __('Nobody'), 'class' => 'input']) ?></div>
            <div class="sm:col-span-2"><label class="label" for="f-job"><?= e(__('Job title')) ?></label><input id="f-job" name="job_title" value="<?= e($v('job_title', '')) ?>" class="input" maxlength="120"></div>
        </div>
        <p class="mx-5 mb-5 flex items-center gap-2 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-900"><?= icon('shield', 'size-4') ?> <?= e(__('The password they chose is kept as a hash and becomes their password. Nobody, including you, can read it.')) ?></p>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-graphite-900/8 bg-mist/40 px-5 py-3">
            <a href="<?= url('/members/requests') ?>" class="btn-ghost"><?= e(__('Back')) ?></a>
            <div class="flex gap-2"><button type="button" class="btn-danger" data-toggle="#reject-box" aria-expanded="false"><?= e(__('Reject')) ?></button><button class="btn-primary"><?= icon('check', 'size-4') ?> <?= e(__('Approve and create the member')) ?></button></div>
        </div>
    </form>

    <form id="reject-box" hidden method="POST" action="<?= url('/members/requests/'.$r['id'].'/reject') ?>" class="panel mt-4 space-y-3 p-5"><?= csrf_field() ?>
        <label class="label" for="f-reason"><?= e(__('Reason (sent to the person, optional)')) ?></label><input id="f-reason" name="reason" maxlength="300" class="input">
        <button class="btn-danger"><?= e(__('Reject the request')) ?></button>
    </form>
    <?php endif ?>
</div>
