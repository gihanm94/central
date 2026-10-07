<?php
declare(strict_types=1);

namespace App\Core\Support;

/** The period filter of the dashboards (?range=month|today|7d|30d|90d|last_month|year|custom&from=&to=). Default: the whole current month. */
final class Period
{
    public const PRESETS = ['today' => 'Today', '7d' => '7 days', '30d' => '30 days', 'month' => 'This month', 'last_month' => 'Last month', '90d' => '90 days', 'year' => 'This year'];

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: string} from, to (inclusive), preset key */
    public static function fromRequest(): array
    {
        $tz    = new \DateTimeZone((string) config('app.timezone', 'Asia/Bangkok'));
        $today = new \DateTimeImmutable('today', $tz);
        $key   = (string) Request::query('range', 'month');
        $from  = $today->modify('first day of this month');
        $to    = $today->modify('last day of this month');
        switch ($key) {
            case 'today':      $from = $today; $to = $today; break;
            case '7d':         $from = $today->modify('-6 days'); $to = $today; break;
            case '30d':        $from = $today->modify('-29 days'); $to = $today; break;
            case '90d':        $from = $today->modify('-89 days'); $to = $today; break;
            case 'last_month': $from = $today->modify('first day of last month'); $to = $today->modify('last day of last month'); break;
            case 'year':       $from = $today->setDate((int) $today->format('Y'), 1, 1); $to = $today->setDate((int) $today->format('Y'), 12, 31); break;
            case 'custom':
                $f = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) Request::query('from'), $tz);
                $t = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) Request::query('to'), $tz);
                if ($f && $t) { $from = min($f, $t); $to = max($f, $t); } else { $key = 'month'; }
                break;
            default: $key = 'month';
        }
        if ($to->diff($from)->days > 730) {
            $from = $to->modify('-730 days');
        }

        return [$from, $to, $key];
    }
}
