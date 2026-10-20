<?php
declare(strict_types=1);

/*
 | Daily oil prices (PTT OR). Run it every 5 minutes from cron; it syncs only at the times set in
 | Management → Oil prices (default 05:10, 06:10 and 07:00 Bangkok time) and does nothing in between:
 |   0-59/5 * * * * php /path/to/app/bin/oil-prices.php
 | Without cron the website runs the same check once a minute while somebody is signed in.
 |   php bin/oil-prices.php --now            sync right away
 |   php bin/oil-prices.php --from=2026-01-01 [--to=2026-03-31]   load past prices, one request per day
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
require dirname(__DIR__).'/app/bootstrap.php';

use App\Core\Support\OilPrices;

$opt = getopt('', ['now', 'from:', 'to:']);
$tz = new DateTimeZone(OilPrices::TZ);
try {
    if (isset($opt['from'])) {
        $d = new DateTimeImmutable((string) $opt['from'], $tz);
        $to = new DateTimeImmutable((string) ($opt['to'] ?? 'today'), $tz);
        for (; $d <= $to; $d = $d->modify('+1 day')) {
            $r = OilPrices::sync('history', $d);
            echo $d->format('Y-m-d')." {$r['changed']} change(s)\n";
        }
        exit(0);
    }
    $r = isset($opt['now']) ? OilPrices::sync('manual', null, null, 'cron') : OilPrices::tick();
    echo date('c').' '.($r === null ? 'nothing due' : "synced {$r['products']} fuel(s), {$r['changed']} change(s)")."\n";
} catch (Throwable $e) {
    fwrite(STDERR, date('c').' '.$e->getMessage()."\n");
    exit(1);
}
