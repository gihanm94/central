<?php
/*
 | Each module has its own sidebar, dashboards and notification switches.
 | The module comes from the first URL segment (/crm, /accounting …); anything else is "core".
 | Menu items show only when the user holds the permission ("resource.action").
 | Dashboards: people can pick one as their default in Preferences.
 */
return [
    'core' => [
        'name' => 'User Management', 'icon' => 'users', 'home' => '/dashboard', 'enabled' => true, 'db' => 'core',
        'dashboards' => [
            'personal' => ['label' => 'My workspace', 'url' => '/dashboard/personal'],
            'admin'    => ['label' => 'Control room', 'url' => '/dashboard/admin', 'admin_only' => true],
        ],
        'menu' => [
            ['heading' => 'Overview'],
            ['label' => 'Control room',     'url' => '/dashboard/admin',    'icon' => 'chart', 'admin_only' => true],
            ['label' => 'My workspace',     'url' => '/dashboard/personal', 'icon' => 'home'],
            ['heading' => 'Work'],
            ['label' => 'Calendar',         'url' => '/calendar',    'icon' => 'calendar'],
            ['label' => 'Mail',             'url' => '/mail',        'icon' => 'mail'],
            ['label' => 'Tasks',            'url' => '/tasks',       'icon' => 'check',    'permission' => 'tasks.view'],
            ['label' => 'KPIs & OKRs',      'url' => '/kpis',        'icon' => 'target',   'permission' => 'kpis.view'],
            ['label' => 'KPI submissions',  'url' => '/kpi-submissions', 'icon' => 'tick', 'permission' => 'kpi_submissions.view'],
            ['heading' => 'ERP'],
            ['label' => 'Invoices',         'url' => '/erp/invoices', 'icon' => 'doc',    'permission' => 'erp_invoices.view'],
        ],
    ],
    /*
     | Modules. Each one describes itself in app/Modules/<Name>/module.php (name, menu, hooks …);
     | anything set here overrides the manifest, e.g. 'crm' => ['enabled' => false] switches CRM off.
     | A new module needs no line here: dropping its folder in is enough.
     */
    'crm'        => ['enabled' => true],
    'accounting' => ['enabled' => true],
    // Placeholders until their module folders are built; the databases already exist.
    'inventory'  => ['name' => 'Inventory', 'icon' => 'box', 'home' => '/inventory', 'enabled' => false, 'db' => 'inventory'],
    'machines'   => ['enabled' => true],
    'hr'         => ['name' => 'HR', 'icon' => 'id', 'home' => '/hr', 'enabled' => false, 'db' => 'hr'],
];
