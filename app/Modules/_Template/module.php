<?php
declare(strict_types=1);

/*
 | Starter for a new module. Copy this folder to app/Modules/<Name>/ (no leading "_"),
 | then replace "example" / "Example" everywhere. Folders starting with "_" are never loaded.
 | Read app/Modules/README.md for the full checklist.
 */
return [
    'key'     => 'example',                     // first URL segment (/example/...), permission prefix, activity-log module
    'name'    => 'Example',
    'icon'    => 'grid',                        // any name from resources/views/partials/icons.php
    'home'    => '/example',
    'enabled' => true,                          // config/modules.php can still override this
    'db'      => 'example',                     // connection in config/config.php → 'db'; must match database/sql/migrations/<db>/
    'dashboards' => [
        // 'overview' => ['label' => 'Example overview', 'url' => '/example'],
    ],
    'menu' => [
        ['heading' => 'Overview'],
        ['label' => 'Example home', 'url' => '/example', 'icon' => 'home'],
        // ['label' => 'Items', 'url' => '/example/items', 'icon' => 'box', 'permission' => 'example_items.view'],
    ],
    // Optional. Each runs inside try/catch: if it throws, it is logged and shown on Administration → Modules,
    // and nothing else is affected.
    'hooks' => [
        // 'tick'            => fn () => \App\Modules\Example\Support\Jobs::tick(),            // about once a minute while someone is signed in
        // 'request'         => fn () => null,                                                  // every request to /example/...
        // 'error'           => fn (\Throwable $e) => null,                                     // uncaught error on an /example page
        // 'calendar.events' => fn ($user, string $from, string $to, bool $mine) => ['events' => [], 'linked' => []],
    ],
];
