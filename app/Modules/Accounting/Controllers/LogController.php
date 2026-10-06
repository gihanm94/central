<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Modules\Accounting\Support\Log;

/** Administrators: the accounting log files, live. People who may generate can follow just their own Generate run. */
class LogController extends Controller
{
    private function gate(): void
    {
        if (! $this->user()->isAdmin()) {
            abort(403, __('Only an administrator can open the logs.'));
        }
    }

    public function index(): string
    {
        $this->gate();

        return view('accounting/logs', ['title' => __('Logs'), 'days' => Log::days(), 'today' => date('Y-m-d')]);
    }

    /** New lines after a byte offset (JSON). */
    public function tail(): never
    {
        $run = (string) Request::input('run', '');
        if (! $this->user()->isAdmin()) {
            can('accounting_inet', 'create') && $run !== '' || abort(403);
        }
        session_write_close();                                   // polled every second: never hold the session lock
        $r = Log::read((string) Request::input('day', date('Y-m-d')), (int) Request::input('after', -1), min(1000, max(10, (int) Request::input('tail', 300))), [
            'level' => (string) Request::input('level', 'debug'), 'ch' => (string) Request::input('ch', ''), 'q' => (string) Request::input('q', ''),
            'run' => $run,
        ]);
        json_response($r);
    }

    public function download(): never
    {
        $this->gate();
        $file = Log::file((string) Request::input('day', date('Y-m-d')));
        is_file($file) || abort(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="accounting-'.basename($file));
        header('Content-Length: '.filesize($file));
        readfile($file);
        exit;
    }

    public function clear(): never
    {
        $this->gate();
        $day = (string) Request::input('day', '');
        if ($day !== '' && $day !== date('Y-m-d') && is_file(Log::file($day))) { @unlink(Log::file($day)); }
        elseif ($day === date('Y-m-d')) { @file_put_contents(Log::file($day), ''); }
        Session::flash('success', __('Log of :day cleared.', ['day' => $day]));
        back();
    }
}
