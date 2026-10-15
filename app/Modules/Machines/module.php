<?php
declare(strict_types=1);

use App\Modules\Machines\Support\Jobs;

/*
 | Machines module manifest — read by App\Core\Support\Modules at boot.
 | 'enabled' here is the default; config/modules.php may override it.
 */
return [
    'key' => 'machines',
    'name' => 'Machine Checklist', 'icon' => 'gear', 'home' => '/machines', 'enabled' => true, 'db' => 'machines',
    'dashboards' => [],
    'menu' => [
        ['heading' => 'Daily work'],
        ['label' => 'Scan QR code',   'url' => '/machines/scan',        'icon' => 'qr'],
        ['label' => 'Checklists',     'url' => '/machines/checklists',  'icon' => 'check',   'permission' => 'machines_checklists.view'],
        ['heading' => 'Machines'],
        ['label' => 'Machines',       'url' => '/machines/machines',    'icon' => 'gear',    'permission' => 'machines_machines.view'],
        ['label' => 'Register',       'url' => '/machines/register',    'icon' => 'doc',     'permission' => 'machines_register.view'],
        ['label' => 'Maintenance',    'url' => '/machines/maintenance', 'icon' => 'tools',   'permission' => 'machines_maintenance.view'],
        ['label' => 'Calibration',    'url' => '/machines/calibration', 'icon' => 'target',  'permission' => 'machines_calibration.view'],
        ['heading' => 'Setup'],
        ['label' => 'Machine types',  'url' => '/machines/types',       'icon' => 'columns', 'permission' => 'machines_types.view'],
        ['label' => 'Questions',      'url' => '/machines/questions',   'icon' => 'list',    'permission' => 'machines_questions.view'],
    ],
    'hooks' => [
        // Polled about once a minute by every signed-in page (notification feed).
        'tick' => fn () => Jobs::tick(),
    ],
];
