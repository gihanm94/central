<?php
declare(strict_types=1);

/*
 | Machine Checklist background jobs (re-check periods, automatic records, maintenance and calibration reminders).
 | Run it every few minutes from cron; a job that is not due simply does nothing:
 |   * /5 * * * * php /path/to/app/bin/machines-cron.php
 | Without cron the website runs the same jobs about once a minute while somebody is signed in.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
require dirname(__DIR__).'/app/bootstrap.php';

$ran = App\Modules\Machines\Support\Jobs::tick(true);
echo date('c').' ran: '.($ran ? implode(', ', $ran) : 'nothing due')."\n";
