<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\DB;
use App\Core\Support\Request;

/** Remembers, per person and per list, which columns are hidden and how many rows per page. */
class TablePrefController extends Controller
{
    public static function load(int $userId, string $resource): array
    {
        $row = DB::first('SELECT hidden, per_page FROM user_table_prefs WHERE user_id = ? AND resource = ?', [$userId, $resource]);

        return ['hidden' => $row ? (json_decode((string) $row['hidden'], true) ?: []) : [], 'per_page' => $row['per_page'] ?? null];
    }

    public static function rememberPageSize(int $userId, string $resource, int $size): void
    {
        DB::exec('INSERT INTO user_table_prefs (user_id, resource, per_page) VALUES (?, ?, ?)
                  ON CONFLICT (user_id, resource) DO UPDATE SET per_page = EXCLUDED.per_page, updated_at = now()', [$userId, $resource, $size]);
    }

    public function save(): never
    {
        $resource = (string) Request::input('resource');
        if (! preg_match('/^[a-z_]{2,60}$/', $resource)) {
            json_response(['message' => 'Unknown list.'], 422);
        }
        $hidden = array_values(array_filter(array_map('strval', (array) Request::input('hidden', [])), fn ($k) => preg_match('/^[a-z0-9_]{1,40}$/i', $k)));
        $per    = Request::input('per_page');
        $per    = in_array((int) $per, [10, 20, 50, 100], true) ? (int) $per : null;

        DB::exec('INSERT INTO user_table_prefs (user_id, resource, hidden, per_page) VALUES (?, ?, ?, ?)
                  ON CONFLICT (user_id, resource) DO UPDATE SET hidden = EXCLUDED.hidden, per_page = COALESCE(EXCLUDED.per_page, user_table_prefs.per_page), updated_at = now()',
            [$this->user()->id, $resource, json_encode($hidden), $per]);
        json_response(['ok' => true]);
    }
}
