<?php
declare(strict_types=1);

/*
 | ERP sync (Accounting → ERP connection). Run `tick` every minute from cron; it only works inside the
 | working window and when the last run of a group is older than its interval:
 |   * * * * * php /path/to/app/bin/erp-sync.php tick
 | Other uses:
 |   php bin/erp-sync.php hot | cold | full | all          run a whole group now
 |   php bin/erp-sync.php entity <key> [latest|all|one] [id]  run one entity (keys: see config/erp.php)
 |   php bin/erp-sync.php login                              test the login and show the session
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
require dirname(__DIR__).'/app/bootstrap.php';

use App\Modules\Accounting\Erp\Client;
use App\Modules\Accounting\Erp\Schedule;

$cmd = $argv[1] ?? 'tick';
@set_time_limit(0);
try {
    switch ($cmd) {
        case 'tick':
            $ran = Schedule::tick();
            echo date('c').' '.($ran ? 'ran: '.implode(', ', $ran) : 'nothing due')."\n";
            break;
        case 'hot': case 'cold': case 'full': case 'all':
            $id = Schedule::runGroup($cmd, 'manual', isset($argv[2]) ? (int) $argv[2] : null);
            echo date('c').' '.($id ? "run #{$id} finished" : 'already running')."\n";
            break;
        case 'entity':
            $key = $argv[2] ?? '';
            $id  = Schedule::runEntity($key, $argv[3] ?? 'latest', $argv[4] ?? null, null);
            echo date('c')." run #{$id} finished\n";
            break;
        case 'login':
            $t = (new Client())->login();
            echo 'session '.substr((string) $t['session_id'], 0, 6)."… saved\n";
            break;
        default:
            fwrite(STDERR, "Unknown command {$cmd}\n");
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, date('c').' '.$e->getMessage()."\n");
    exit(1);
}
