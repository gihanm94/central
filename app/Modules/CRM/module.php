<?php
declare(strict_types=1);

use App\Modules\CRM\Support\CalendarFeed;
use App\Modules\CRM\Support\Reminders;

/*
 | CRM module manifest — read by App\Core\Support\Modules at boot.
 | 'enabled' here is the default; config/modules.php may override it.
 */
return [
    'key' => 'crm',
    'name' => 'CRM', 'icon' => 'handshake', 'home' => '/crm', 'enabled' => true, 'db' => 'crm',
    'dashboards' => [
        'overview' => ['label' => 'CRM overview', 'url' => '/crm/dashboard'],
    ],
    'menu' => [
        ['heading' => 'Overview'],
        ['label' => 'CRM overview',  'url' => '/crm/dashboard',     'icon' => 'chart',     'permission' => 'crm_dashboard.view'],
        ['heading' => 'Sales'],
        ['label' => 'Leads',         'url' => '/crm/leads',         'icon' => 'building',  'permission' => 'crm_leads.view'],
        ['label' => 'Contacts',      'url' => '/crm/contacts',      'icon' => 'user',      'permission' => 'crm_contacts.view'],
        ['label' => 'Opportunities', 'url' => '/crm/opportunities', 'icon' => 'target',    'permission' => 'crm_opportunities.view'],
        ['label' => 'Pipeline',      'url' => '/crm/pipeline',      'icon' => 'columns',   'permission' => 'crm_opportunities.view'],
        ['label' => 'Activities',    'url' => '/crm/activities',    'icon' => 'clock',     'permission' => 'crm_activities.view'],
        ['label' => 'Calendar',      'url' => '/crm/calendar',      'icon' => 'calendar',  'permission' => 'crm_activities.view'],
        ['heading' => 'Work'],
        ['label' => 'Projects',      'url' => '/crm/projects',      'icon' => 'folder',    'permission' => 'crm_projects.view'],
        ['label' => 'Tasks',         'url' => '/crm/tasks',         'icon' => 'tasks',     'permission' => 'crm_tasks.view'],
        ['label' => 'Team',          'url' => '/crm/team',          'icon' => 'users',     'permission' => 'crm_reassign.view'],
        ['heading' => 'Marketing'],
        ['label' => 'Campaigns',     'url' => '/crm/campaigns',     'icon' => 'chat',      'permission' => 'crm_campaigns.view'],
        ['heading' => 'Setup'],
        ['label' => 'Data tools',    'url' => '/crm/data',          'icon' => 'download',  'admin_only' => true],
        ['label' => 'Settings',      'url' => '/crm/settings',      'icon' => 'cog', 'permission_any' => ['crm_settings.view', 'crm_stages.view', 'crm_targets.view']],
    ],
    'hooks' => [
        // Polled about once a minute by every signed-in page (notification feed).
        'tick'            => fn () => Reminders::tick(),
        // Shared /calendar page: this person's visible CRM activities.
        'calendar.events' => fn ($user, string $from, string $to, bool $mine) => CalendarFeed::events($user, $from, $to, $mine),
    ],
];
