<?php
declare(strict_types=1);

use App\Modules\Sheets\Support\Runner;

/*
 | Google Sheets module manifest — read by App\Core\Support\Modules at boot.
 | People sync the tables the administrator allows into their own Google spreadsheets on a schedule.
 | Its tables live in the core database (gsheet_*); its upgrades are in its own migrations/ folder, so a failing one fences off only this module.
 */
return [
    'key' => 'sheets',
    'name' => 'Google Sheets', 'icon' => 'table', 'home' => '/sheets', 'enabled' => true, 'db' => 'core',
    'dashboards' => [],
    'menu' => [
        ['heading' => 'My syncs'],
        ['label' => 'Syncs',           'url' => '/sheets',          'icon' => 'refresh'],
        ['label' => 'New sync',        'url' => '/sheets/new',      'icon' => 'plus'],
        ['heading' => 'Administration'],
        ['label' => 'Tables & access', 'url' => '/sheets/tables',   'icon' => 'table',   'admin_only' => true],
        ['label' => 'Settings',        'url' => '/sheets/settings', 'icon' => 'cog',     'admin_only' => true],
    ],
    'hooks' => [
        // Fallback for installs without cron: runs at most one due sync per poll (never in office hours).
        'tick' => fn () => Runner::tick(1, 20),
        // One Google sign-in for everything: the main 'Connect Google' also asks for Sheets, and the account it connects
        // is added here (so Sheets, Mail and Calendar all stay connected together).
        'google.scopes' => fn () => App\Modules\Sheets\Support\Accounts::SCOPES,
        'google.connected' => fn (int $userId, string $email, string $access, string $refresh, int $expiresIn, array $scopes)
            => App\Modules\Sheets\Support\Accounts::adopt($userId, $email, $access, $refresh, $expiresIn, $scopes),
    ],
];
