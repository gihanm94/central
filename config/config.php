<?php
// Written by public/install.php — you can edit it by hand afterwards.
return [
    'app' => [
        'name'     => 'Acme International',
        'url'      => 'http://localhost:8000',
        'env'      => 'local',            // local shows errors, production hides them
        'timezone' => 'Asia/Bangkok',
        'key'      => 'change-me-run-installer',
    ],
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 5432,
        'username' => 'acme',
        'password' => 'acmeinter123',
        // One PostgreSQL database per area. Core (people, roles, logs) lives in user_db.
        'databases' => [
            'core'       => 'user_db',
            'crm'        => 'crm_db',
            'accounting' => 'account_db',
            'inventory'  => 'inventory_db',
            'machines'   => 'machine_db',
            'hr'         => 'hr_db',
        ],
    ],
    'mail' => [
        'driver'       => 'log',          // log = save e-mails to storage/mail, smtp = really send
        'host'         => 'smtp.gmail.com',
        'port'         => 587,
        'encryption'   => 'tls',          // tls (587) | ssl (465) | none
        'username'     => 'web-support@acme-inter.com',
        'password'     => 'xzpqcgetujdzfqyb',
        'from_address' => 'web-support@acme-inter.com',
        'from_name'    => 'Acme International',
    ],
    'notifications' => [
        'enabled'          => true,
        'actions'          => ['created', 'updated', 'deleted', 'import', 'export', 'download'],
        'login_alert'      => true,
        'password_changed' => true,
    ],
    'security' => [
        'remember_days'      => 30,
        'reset_minutes'      => 60,
        'max_login_attempts' => 5,
        'lockout_minutes'    => 1,
        'passkey_rp_id'      => null,     // null = current host name
    ],
];
