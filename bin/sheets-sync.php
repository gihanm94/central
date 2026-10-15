<?php
declare(strict_types=1);

/*
 | Google Sheets syncs. Run it every 5 minutes from cron so syncs scheduled in the evening or at night run
 | even when nobody is signed in:
 |   * /5 * * * * php /path/to/app/bin/sheets-sync.php
 | Nothing runs during office hours (Google Sheets → Settings); due syncs wait until they end.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
require dirname(__DIR__).'/app/bootstrap.php';

if (! App\Core\Support\Modules::usable('sheets')) {
    exit(date('c')." the Google Sheets module is off or unavailable\n");
}
$ran = App\Modules\Sheets\Support\Runner::tick(20, 240);
echo date('c').' '.($ran ? implode("\n", $ran) : 'nothing due')."\n";
