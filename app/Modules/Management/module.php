<?php
declare(strict_types=1);

/*
 | Management module manifest — read by App\Core\Support\Modules at boot.
 | For administrators and Management (permission management.view): the revenue overview (top customers, sellers and
 | departments, from Accounting through the 'revenue.ranking' hook) and the KPI calculator (yearly appraisal score).
 | Its data lives in the core database (kpi_scores); it never reads another module's database itself.
 */
return [
    'key' => 'management',
    'name' => 'Management', 'icon' => 'chart', 'home' => '/management', 'enabled' => true, 'db' => 'core',
    'permission' => 'management.view',
    'dashboards' => [],
    'menu' => [
        ['heading' => 'Overview'],
        ['label' => 'Overview',       'url' => '/management',                'icon' => 'chart', 'permission' => 'management.view'],
        ['heading' => 'People'],
        ['label' => 'KPI calculator', 'url' => '/management/kpi-calculator', 'icon' => 'calc',  'permission' => 'kpi_calculator.view'],
    ],
    'hooks' => [],
];
