<?php
declare(strict_types=1);

/*
 | Management module manifest — read by App\Core\Support\Modules at boot.
 | For administrators and Management (permission management.view): the revenue overview (top customers, sellers and
 | departments, from Accounting through the 'revenue.ranking' hook), the CRM overview (opportunities, from CRM through
 | the 'management.crm' hook) and the KPI calculator (yearly appraisal score).
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
        ['label' => 'CRM overview',   'url' => '/management/crm',            'icon' => 'target', 'permission' => 'management.view'],
        ['label' => 'Oil prices',     'url' => '/management/oil-prices',     'icon' => 'chart',  'permission' => 'management.view'],
        ['heading' => 'People'],
        ['label' => 'KPI calculator', 'url' => '/management/kpi-calculator', 'icon' => 'calc',  'permission' => 'kpi_calculator.view'],
        ['label' => 'Everbetter',     'url' => '/management/everbetter',     'icon' => 'bolt',  'permission' => 'management.view'],
        ['label' => 'Member signatures', 'url' => '/management/signatures',  'icon' => 'pencil', 'permission' => 'management.view'],
        ['heading' => 'Audit'],
        ['label' => 'PDF signing log', 'url' => '/management/pdf-signs',     'icon' => 'doc',   'permission' => 'management.view'],
    ],
    'hooks' => [],
];
