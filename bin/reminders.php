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

if (! App\Core\Support\Modules::usable('crm')) {
    exit(date('c')." the crm module is off or unavailable\n");
}
try {
    $n = App\Modules\CRM\Support\Reminders::tick(true);
    echo date('c')." sent {$n} reminder(s)\n";
} catch (Throwable $e) {
    App\Core\Support\Modules::fail('crm', 'hook:cron', $e);
    fwrite(STDERR, date('c').' '.$e->getMessage()."\n");
    exit(1);
}
