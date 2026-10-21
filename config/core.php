<?php
// Rule book for the Core. Keys are stored in the database; labels are translated in lang/*.php.
return [
    // Languages people can pick. Add one: copy lang/th.php to lang/xx.php, translate, add it here.
    'locales' => ['en' => 'English', 'th' => 'ไทย'],

    'actions' => ['view', 'create', 'edit', 'delete', 'import', 'export', 'download'],

    'scopes' => [
        'all'        => 'Whole company',
        'department' => 'Own department',
        'team'       => 'Own team',
        'own'        => 'Own records only',
    ],

    'resources' => [
        'dashboard'     => 'Dashboard',
        'members'       => 'Members',
        'departments'   => 'Departments',
        'teams'         => 'Teams',
        'roles'         => 'Roles & permissions',
        'kpis'          => 'KPIs',
        'tasks'         => 'Tasks',
        'activity_logs' => 'Activity log',
        'settings'      => 'Company settings',
    ],

    // Employment data (values match the Java enums used elsewhere).
    'employee' => [
        'levels'   => ['C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'C8', 'C9', 'C10', 'C11', 'C12', 'OTHER'],
        'statuses' => ['ACTIVE' => 'Active', 'PROBATION' => 'Probation', 'SUSPENDED' => 'Suspended', 'RESIGNED' => 'Resigned', 'TERMINATED' => 'Terminated', 'RETIRED' => 'Retired'],
        'types'    => ['FULL_TIME' => 'Full time', 'PART_TIME' => 'Part time', 'REMOTE' => 'Remote', 'OTHER' => 'Other'],
        'genders'  => ['MALE' => 'Male', 'FEMALE' => 'Female', 'OTHER' => 'Other', 'PREFER_NOT_TO_SAY' => 'Prefer not to say'],
    ],

    // Record events that can notify people, per module, by e-mail and/or Lark.
    'notify_actions' => ['created' => 'Create', 'updated' => 'Edit', 'deleted' => 'Delete', 'import' => 'Import', 'export' => 'Export', 'download' => 'Download', 'commented' => 'Comment', 'shared' => 'Share', 'reminder' => 'Reminder', 'assigned' => 'Assign'],
    'notify_channels' => ['app' => 'In-app (bell)', 'email' => 'E-mail', 'lark' => 'Lark'],
    'security_events' => ['login' => 'New sign-in', 'password' => 'Password changed or reset'],
];
