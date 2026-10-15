<?php
declare(strict_types=1);

namespace App\Core\Api;

use App\Core\Support\DB;

/** Fixed one-minute windows in api_rate. Over the limit → 429 with Retry-After. */
final class RateLimit
{
    public static function hit(string $key, int $perMinute): void
    {
        $window = date('Y-m-d H:i:00');
        $hits = (int) DB::scalar('INSERT INTO api_rate (key, window_start, hits) VALUES (?, ?, 1)
            ON CONFLICT (key, window_start) DO UPDATE SET hits = api_rate.hits + 1 RETURNING hits', [mb_substr($key, 0, 120), $window]);
        if ($hits > $perMinute) {
            $retry = 60 - (int) date('s');
            throw new ApiException(429, 'rate_limited', __('Too many requests. Try again in :s seconds.', ['s' => $retry]), ['Retry-After' => (string) $retry]);
        }
        if (random_int(1, 500) === 1) {                 // now and then, forget old windows
            DB::exec("DELETE FROM api_rate WHERE window_start < now() - interval '10 minutes'");
        }
    }
}
