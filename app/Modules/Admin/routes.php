<?php
declare(strict_types=1);

use App\Modules\Admin\Controllers\HomeController;

/** @var App\Core\Support\Router $r  (signed-in users only; loaded only for /admin URLs). The pages themselves are core routes. */

$r->get('/admin', [HomeController::class, 'index']);
