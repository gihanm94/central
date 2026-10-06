<?php
declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Auth\Auth;

/**
 * Routes: $router->get('/members/{id}', [MemberController::class, 'show'], ['auth', 'perm:members,view']);
 * Middleware: guest | auth | perm:<resource>,<action>. Every POST is CSRF-checked.
 */
final class Router
{
    private array $routes = [];

    public function get(string $path, array $handler, array $middleware = []): void  { $this->add('GET', $path, $handler, $middleware); }
    public function post(string $path, array $handler, array $middleware = []): void { $this->add('POST', $path, $handler, $middleware); }

    public function group(array $middleware, callable $routes): void
    {
        $before = $this->routes;
        $routes($this);
        foreach (array_slice($this->routes, count($before), null, true) as $i => $route) {
            $this->routes[$i]['middleware'] = [...$middleware, ...$route['middleware']];
        }
    }

    private function add(string $method, string $path, array $handler, array $middleware): void
    {
        $regex = '#^'.preg_replace_callback('#\{(\w+)\}#', fn ($m) => $m[1] === 'id' ? '(?P<id>\d+)' : '(?P<'.$m[1].'>[^/]+)', rtrim($path, '/') ?: '/').'$#';
        $this->routes[] = compact('method', 'path', 'regex', 'handler', 'middleware');
    }

    public function dispatch(string $method, string $path): void
    {
        $allowed = false;

        foreach ($this->routes as $route) {
            if (! preg_match($route['regex'], $path, $m)) {
                continue;
            }
            $allowed = true;
            if ($route['method'] !== $method) {
                continue;
            }

            if ($method === 'POST') {
                $token = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
                if (! Session::verifyCsrf($token)) {
                    throw new HttpException(419, 'Your session expired. Reload the page and try again.');
                }
            }

            foreach ($route['middleware'] as $mw) {
                $this->runMiddleware($mw);
            }

            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $params = array_map(fn ($v) => ctype_digit($v) ? (int) $v : urldecode($v), $params);
            [$class, $action] = $route['handler'];

            $result = (new $class())->$action(...array_values($params));
            if (is_string($result)) {
                echo $result;
            }

            return;
        }

        throw new HttpException($allowed ? 405 : 404);
    }

    private function runMiddleware(string $mw): void
    {
        [$name, $args] = array_pad(explode(':', $mw, 2), 2, '');

        switch ($name) {
            case 'guest':
                if (Auth::user()) {
                    redirect('/dashboard');
                }
                break;
            case 'auth':
                if (! Auth::user()) {
                    if (Request::isJson()) {
                        throw new HttpException(401, 'Please sign in again.');
                    }
                    $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? '/dashboard';
                    redirect('/login');
                }
                break;
            case 'perm':
                [$resource, $action] = array_pad(explode(',', $args), 2, 'view');
                if (! can($resource, $action)) {
                    throw new HttpException(403, "You don't have permission to {$action} ".str_replace('_', ' ', $resource).'.');
                }
                break;
        }
    }
}

