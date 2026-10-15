<?php
declare(strict_types=1);

namespace App\Core\Api;

use App\Core\Auth\Auth;
use App\Core\Auth\CurrentUser;
use App\Core\Support\DB;
use App\Core\Support\Request;

/** Base of every API controller: the signed-in person, permission checks, paging, search and input. */
abstract class Controller
{
    protected function user(): CurrentUser
    {
        return Auth::user() ?? throw new ApiException(401, 'unauthenticated', __('Sign in first.'));
    }

    /** 403 unless the person holds $action on $resource (same permissions as the website). */
    protected function need(string $resource, string $action = 'view'): void
    {
        if (! $this->user()->can($resource, $action)) {
            throw new ApiException(403, 'forbidden', __("You don't have permission to :a :r.", ['a' => $action, 'r' => str_replace('_', ' ', $resource)]));
        }
    }

    protected function notFound(): never
    {
        throw new ApiException(404, 'not_found', __('Not found.'));
    }

    /** Search text from ?q= (trimmed, at most 100 characters). */
    protected function q(): string
    {
        return mb_substr(trim((string) Request::query('q', '')), 0, 100);
    }

    /** ILIKE pattern with %, _ and \ taken literally. */
    protected function like(string $q): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
    }

    /**
     * One page of "$sql" (which must not contain ORDER BY / LIMIT).
     * ?page= (from 1) and ?per_page= (1–100, default 25).
     * @param callable|null $map turns a row into what the app gets
     */
    protected function paged(string $sql, array $params, string $order, string $conn = 'core', ?callable $map = null): array
    {
        $per = max(1, min(100, (int) Request::query('per_page', 25)));
        $page = max(1, min(10000, (int) Request::query('page', 1)));
        $total = (int) DB::scalar("SELECT count(*) FROM ({$sql}) x", $params, $conn);
        $rows = DB::select("{$sql} ORDER BY {$order} LIMIT {$per} OFFSET ".(($page - 1) * $per), $params, $conn);

        return ['data' => $map ? array_map($map, $rows) : $rows, 'meta' => ['page' => $page, 'per_page' => $per, 'total' => $total, 'pages' => (int) ceil($total / $per)]];
    }

    /** Only these keys of the request body (JSON or form). */
    protected function input(array $allowed): array
    {
        return array_intersect_key(Request::all(), array_flip($allowed));
    }

    /** Keeps only $keys of a row, in that order (the API never sends whole table rows). */
    protected static function pick(?array $row, array $keys): ?array
    {
        if ($row === null) {
            return null;
        }
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $row[$k] ?? null;
        }

        return $out;
    }
}
