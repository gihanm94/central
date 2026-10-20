<?php
declare(strict_types=1);

/*
 | Administrator module manifest — read by App\Core\Support\Modules at boot.
 | The administration pages (control room, members, departments, teams, roles, account requests, sessions, API access, settings, modules, connectors, files,
 | activity log, system logs, mail log). They are core pages and keep their addresses ('paths' shows them under this module); /admin opens the
 | first one the person may use. Visible to administrators and to roles allowed one of its pages.
 */
return [
    'key' => 'admin',
    'name' => 'Administrator', 'icon' => 'shield', 'home' => '/admin', 'enabled' => true, 'db' => 'core',
    'permission_any' => ['admin', 'members.view', 'departments.view', 'teams.view', 'roles.view', 'activity_logs.view', 'settings.view'],
    'paths' => ['/dashboard/admin', '/members', '/departments', '/teams', '/roles', '/sessions', '/api-access', '/settings', '/modules', '/connectors', '/files', '/activity', '/system-logs', '/mail-log', '/pdf-sign-log'],
    'dashboards' => [
        'admin' => ['label' => 'Control room', 'url' => '/dashboard/admin', 'admin_only' => true],
    ],
    'menu' => [
        ['heading' => 'Overview'],
        ['label' => 'Control room',     'url' => '/dashboard/admin',  'icon' => 'chart',    'admin_only' => true],
        ['heading' => 'Organization'],
        ['label' => 'Members',          'url' => '/members',          'icon' => 'users',    'permission' => 'members.view'],
        ['label' => 'Departments',      'url' => '/departments',      'icon' => 'building', 'permission' => 'departments.view'],
        ['label' => 'Teams',            'url' => '/teams',            'icon' => 'team',     'permission' => 'teams.view'],
        ['heading' => 'People & access'],
        ['label' => 'Roles & access',   'url' => '/roles',            'icon' => 'shield', 'permission' => 'roles.view'],
        ['label' => 'Account requests', 'url' => '/members/requests', 'icon' => 'user',   'permission' => 'members.create', 'admin_only' => true],
        ['label' => 'Sessions',         'url' => '/sessions',         'icon' => 'lock',   'admin_only' => true],
        ['heading' => 'Mobile'],
        ['label' => 'Mobile app & API', 'url' => '/api-access',       'icon' => 'key',    'admin_only' => true],
        ['heading' => 'System'],
        ['label' => 'Company settings', 'url' => '/settings',         'icon' => 'cog',    'permission' => 'settings.view'],
        ['label' => 'Modules',          'url' => '/modules',          'icon' => 'grid',   'admin_only' => true],
        ['label' => 'Connectors',       'url' => '/connectors',       'icon' => 'link',   'admin_only' => true],
        ['label' => 'File manager',     'url' => '/files',            'icon' => 'folder', 'admin_only' => true],
        ['heading' => 'Audit'],
        ['label' => 'Activity log',     'url' => '/activity',         'icon' => 'clock',  'permission' => 'activity_logs.view'],
        ['label' => 'System logs',      'url' => '/system-logs',      'icon' => 'alert',  'admin_only' => true],
        ['label' => 'Mail log',         'url' => '/mail-log',         'icon' => 'mail',   'permission' => 'settings.view'],
        ['label' => 'PDF signing log',  'url' => '/pdf-sign-log',     'icon' => 'doc',    'admin_only' => true],
    ],
    'hooks' => [],
];
