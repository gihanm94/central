<?php
declare(strict_types=1);

use App\Modules\Example\Controllers\HomeController;

/** @var App\Core\Support\Router $r  (signed-in users only; loaded only for /example/... URLs) */

$r->get('/example', [HomeController::class, 'index']);
// $r->get('/example/items', [ItemController::class, 'index'], ['perm:example_items,view']);
