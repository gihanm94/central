<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Auth\Auth;
use App\Core\Auth\CurrentUser;
use App\Core\Support\Request;
use App\Core\Support\Validator;

abstract class Controller
{
    protected function user(): CurrentUser
    {
        return Auth::user() ?? abort(401);
    }

    protected function authorize(string $resource, string $action = 'view'): void
    {
        if (! can($resource, $action)) {
            abort(403, "You don't have permission to {$action} ".str_replace('_', ' ', $resource).'.');
        }
    }

    protected function validate(array $rules, array $labels = [], ?array $data = null): array
    {
        return Validator::validate($data ?? Request::all(), $rules, $labels);
    }

    protected function input(string $key, mixed $default = null): mixed
    {
        return Request::input($key, $default);
    }
}
