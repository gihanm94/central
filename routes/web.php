<?php
declare(strict_types=1);

use App\Core\Http\Controllers\ActivityController;
use App\Core\Http\Controllers\AuthController;
use App\Core\Http\Controllers\DashboardController;
use App\Core\Http\Controllers\DepartmentController;
use App\Core\Http\Controllers\EditorController;
use App\Core\Http\Controllers\KpiController;
use App\Core\Http\Controllers\MemberController;
use App\Core\Http\Controllers\PasskeyController;
use App\Core\Http\Controllers\ProfileController;
use App\Core\Http\Controllers\RoleController;
use App\Core\Http\Controllers\SettingsController;
use App\Core\Http\Controllers\TablePrefController;
use App\Core\Http\Controllers\TaskController;
use App\Core\Http\Controllers\TeamController;
use App\Core\Support\Router;

/** @var Router $router */

// ---- Anyone: language switcher ----
$router->get('/lang/{code}', [AuthController::class, 'language']);

// ---- Guests: sign in only (no sign-up) ----
$router->group(['guest'], function (Router $r) {
    $r->get('/login', [AuthController::class, 'showLogin']);
    $r->post('/login', [AuthController::class, 'login']);
    $r->get('/forgot-password', [AuthController::class, 'showForgot']);
    $r->post('/forgot-password', [AuthController::class, 'sendReset']);
    $r->get('/reset-password', [AuthController::class, 'showReset']);
    $r->post('/reset-password', [AuthController::class, 'reset']);
    $r->post('/passkeys/login/options', [PasskeyController::class, 'loginOptions']);
    $r->post('/passkeys/login', [PasskeyController::class, 'login']);
});

// ---- Signed in ----
$router->group(['auth'], function (Router $r) {
    $r->get('/', [DashboardController::class, 'index']);
    $r->get('/dashboard', [DashboardController::class, 'index']);
    $r->get('/dashboard/personal', [DashboardController::class, 'personal']);
    $r->get('/dashboard/admin', [DashboardController::class, 'admin']);
    $r->post('/logout', [AuthController::class, 'logout']);

    $r->get('/profile', [ProfileController::class, 'edit']);
    $r->post('/profile', [ProfileController::class, 'update']);
    $r->get('/profile/security', [ProfileController::class, 'security']);
    $r->post('/profile/password', [ProfileController::class, 'password']);
    $r->get('/profile/preferences', [ProfileController::class, 'preferences']);
    $r->post('/profile/preferences', [ProfileController::class, 'savePreferences']);
    $r->post('/profile/scale', [ProfileController::class, 'scale']);
    $r->post('/table-prefs', [TablePrefController::class, 'save']);
    $r->post('/editor/image', [EditorController::class, 'image']);
    $r->get('/profile/notifications', [ProfileController::class, 'notifications']);
    $r->post('/profile/notifications', [ProfileController::class, 'saveNotifications']);
    $r->post('/profile/devices/{id}/forget', [ProfileController::class, 'forgetDevice']);
    $r->post('/passkeys/options', [PasskeyController::class, 'registerOptions']);
    $r->post('/passkeys', [PasskeyController::class, 'register']);
    $r->post('/passkeys/{id}/delete', [PasskeyController::class, 'destroy']);

    // Standard screens: list, new, detail, edit, delete, export, import, template, download
    $resources = [
        'members'     => MemberController::class,
        'departments' => DepartmentController::class,
        'teams'       => TeamController::class,
        'tasks'       => TaskController::class,
        'kpis'        => KpiController::class,
    ];
    foreach ($resources as $path => $controller) {
        $r->get("/{$path}", [$controller, 'index']);
        $r->get("/{$path}/create", [$controller, 'create']);
        $r->post("/{$path}", [$controller, 'store']);
        $r->get("/{$path}/export", [$controller, 'export']);
        $r->get("/{$path}/template", [$controller, 'template']);
        $r->post("/{$path}/import", [$controller, 'import']);
        $r->post("/{$path}/bulk-delete", [$controller, 'bulkDestroy']);
        $r->get("/{$path}/{id}", [$controller, 'show']);
        $r->get("/{$path}/{id}/edit", [$controller, 'edit']);
        $r->post("/{$path}/{id}", [$controller, 'update']);
        $r->post("/{$path}/{id}/delete", [$controller, 'destroy']);
        $r->get("/{$path}/{id}/download", [$controller, 'download']);
    }
    $r->post('/tasks/{id}/status', [TaskController::class, 'status']);
    $r->get('/members/{id}/access', [MemberController::class, 'access']);
    $r->post('/members/{id}/access', [MemberController::class, 'saveAccess']);

    $r->get('/roles', [RoleController::class, 'index']);
    $r->get('/roles/create', [RoleController::class, 'create']);
    $r->post('/roles', [RoleController::class, 'store']);
    $r->get('/roles/{id}/edit', [RoleController::class, 'edit']);
    $r->post('/roles/{id}', [RoleController::class, 'update']);
    $r->post('/roles/{id}/delete', [RoleController::class, 'destroy']);
    $r->get('/roles/{id}/permissions', [RoleController::class, 'permissions']);
    $r->post('/roles/{id}/permissions', [RoleController::class, 'savePermissions']);

    $r->get('/activity', [ActivityController::class, 'index']);
    $r->get('/activity/export', [ActivityController::class, 'export']);
    $r->get('/activity/{id}', [ActivityController::class, 'show']);

    $r->get('/settings', [SettingsController::class, 'edit']);
    $r->post('/settings', [SettingsController::class, 'update']);
    $r->post('/settings/test-mail', [SettingsController::class, 'testMail']);
    $r->get('/settings/notifications', [SettingsController::class, 'notifications']);
    $r->post('/settings/notifications', [SettingsController::class, 'saveNotifications']);
    $r->post('/settings/test-lark', [SettingsController::class, 'testLark']);

    // Modules register their routes here when built
    $crmRoutes = BASE_PATH.'/app/Modules/CRM/routes.php';
    if (config('modules.crm.enabled')) {
        require $crmRoutes;
    }
});
