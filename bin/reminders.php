<?php
declare(strict_types=1);

/*
 | Sends due activity reminders. Run it every minute from cron so reminders go out even when nobody is signed in:
 |   * * * * * php /path/to/app/bin/reminders.php
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
require dirname(__DIR__).'/app/bootstrap.php';

$n = App\Modules\CRM\Support\Reminders::tick(true);
echo date('c')." sent {$n} reminder(s)\n";
