<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Modules\Accounting\Erp\Client;
use App\Modules\Accounting\Erp\ErpSettings;
use App\Modules\Accounting\Erp\Schedule;
use App\Modules\Accounting\Erp\Syncer;
use App\Modules\Accounting\Support\Log;

/** Administrators: ERP address and login, every API address, the saved token, the schedule, and "run now". */
class ErpController extends Controller
{
    private function gate(): void
    {
        if (! $this->user()->isAdmin()) {
            abort(403, __('Only an administrator can open the ERP connection.'));
        }
    }

    public function index(): string
    {
        $this->gate();
        $state = array_column(DB::select('SELECT * FROM erp_sync_state', [], ErpSettings::CONN), null, 'entity');
        $count = [];
        foreach (Syncer::entities() as $k => $d) {
            $table = config('erp_schema.'.$d['entity'].'.table');
            $count[$k] = (int) DB::scalar("SELECT count(*) FROM {$table}", [], ErpSettings::CONN);
        }
        $token = DB::first('SELECT id, session_id, session_suspended, updated_at FROM erp_tokens WHERE is_primary ORDER BY id DESC LIMIT 1', [], ErpSettings::CONN);

        return view('accounting/erp', [
            'title' => __('ERP connection'), 'state' => $state, 'count' => $count, 'token' => $token,
            'runs' => DB::select('SELECT * FROM erp_sync_runs ORDER BY id DESC LIMIT 12', [], ErpSettings::CONN),
            'totals' => DB::first("SELECT coalesce(sum(saved) FILTER (WHERE started_at::date = CURRENT_DATE), 0) AS today, coalesce(sum(saved), 0) AS all_time, max(finished_at) FILTER (WHERE status = 'ok') AS last_ok, count(*) FILTER (WHERE status = 'failed' AND started_at > now() - interval '1 day') AS failed_day FROM erp_sync_runs", [], ErpSettings::CONN),
            'running' => (int) DB::scalar("SELECT count(*) FROM erp_sync_runs WHERE status = 'running' AND started_at > now() - interval '2 hours'", [], ErpSettings::CONN),
            'hasPassword' => ErpSettings::password() !== '', 'hasInet' => (string) ErpSettings::get('inet.authorization', '') !== '',
            'configured' => ErpSettings::configured(), 'inWindow' => Schedule::inWindow(),
            'cron' => '* * * * * '.Schedule::php().' '.BASE_PATH.'/bin/erp-sync.php tick',
        ]);
    }

    public function saveConnection(): never
    {
        $this->gate();
        $u = $this->user()->id;
        $ip = trim((string) Request::input('ip'));
        if ($ip !== '' && ! preg_match('#^(https?://)?[A-Za-z0-9.\-]+$#', $ip)) {
            throw new \App\Core\Support\ValidationException(['ip' => __('Enter the server address, e.g. 5.223.76.93')]);
        }
        foreach (['ip' => $ip, 'port' => preg_replace('/\D/', '', (string) Request::input('port')), 'username' => trim((string) Request::input('username')),
                  'verify_tls' => Request::input('verify_tls') ? '1' : '0', 'timeout' => (string) max(10, min(900, (int) Request::input('timeout', 120)))] as $k => $v) {
            ErpSettings::put('conn.'.$k, (string) $v, $u);
        }
        if (($pw = (string) Request::input('password')) !== '') {          // empty = keep the saved one
            ErpSettings::put('conn.password', $pw, $u);
        }
        if (($auth = trim((string) Request::input('inet_authorization'))) !== '') {
            ErpSettings::put('inet.authorization', $auth, $u);
        }
        foreach (['sendApi', 'statusApi', 'paramsApi'] as $k) {
            $v = trim((string) Request::input('inet_'.$k));
            if ($v !== '' && ! preg_match('#^https://#', $v)) {
                throw new \App\Core\Support\ValidationException(['inet_'.$k => __('Use an https:// address.')]);
            }
            ErpSettings::put('inet.'.$k, $v, $u);
        }
        $days = array_values(array_unique(array_filter(array_map('intval', (array) Request::input('days', [])), fn ($d) => $d >= 1 && $d <= 7)));
        $tz   = (string) Request::input('tz', 'Asia/Bangkok');
        ErpSettings::put('schedule.enabled', Request::input('enabled') ? '1' : '0', $u);
        ErpSettings::put('schedule.tz', in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : 'Asia/Bangkok', $u);
        ErpSettings::put('schedule.hour_start', (string) max(0, min(23, (int) Request::input('hour_start', 8))), $u);
        ErpSettings::put('schedule.hour_end', (string) max(1, min(24, (int) Request::input('hour_end', 19))), $u);
        ErpSettings::put('schedule.days', implode(',', $days ?: [1, 2, 3, 4, 5]), $u);
        ErpSettings::put('schedule.hot_minutes', (string) max(1, min(1440, (int) Request::input('hot_minutes', 3))), $u);
        ErpSettings::put('schedule.cold_minutes', (string) max(1, min(1440, (int) Request::input('cold_minutes', 30))), $u);
        Activity::log('updated', 'erp_connection', null, 'ERP', 'Changed the ERP connection settings', [], null, null, false, 'accounting');
        Session::flash('success', __('ERP connection saved.'));
        redirect('/accounting/erp');
    }

    public function saveEndpoints(): never
    {
        $this->gate();
        $changed = 0;
        if (Request::input('overwrite_form')) {                                   // one checkbox per ERP table: update rows that already exist
            $on = (array) Request::input('overwrite', []);
            foreach (array_keys(Syncer::entities()) as $k) {
                $v = isset($on[$k]) ? '1' : '0';
                if (ErpSettings::overwrite($k) !== ($v === '1')) { ErpSettings::put('overwrite.'.$k, $v, $this->user()->id); $changed++; }
            }
        }
        foreach ((array) Request::input('api', []) as $key => $path) {
            if (! isset(config('erp.endpoints')[$key])) { continue; }
            $path = trim((string) $path);
            if ($path === '' || $path[0] !== '/' || preg_match('/\s|\.\./', $path)) { continue; }
            if ($path !== ErpSettings::endpoint($key)) { ErpSettings::put('api.'.$key, $path, $this->user()->id); $changed++; }
        }
        Session::flash('success', $changed ? __(':n API addresses saved.', ['n' => $changed]) : __('Nothing changed.'));
        redirect('/accounting/erp#apis');
    }

    /** Get a new session now and show it. */
    public function login(): never
    {
        $this->gate();
        try {
            $t = (new Client())->login();
            Session::flash('success', __('Logged in to the ERP. Session :id… saved for one hour.', ['id' => substr((string) $t['session_id'], 0, 6)]));
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        redirect('/accounting/erp');
    }

    /** Ask one API for a single row, to see that its address and the login work. */
    public function test(): never
    {
        $this->gate();
        $key = (string) Request::input('key');
        if (! isset(config('erp.endpoints')[$key]) || $key === 'tokenUrl') { abort(404); }
        try {
            $r = (new Client())->page($key, ['top' => 1, 'skip' => 0]);
            Session::flash($r['error'] ? 'error' : 'success', $r['error'] ? $key.': '.$r['error'] : $key.': HTTP '.$r['status'].', '.count($r['rows']).' row(s) came back.');
        } catch (\Throwable $e) {
            Session::flash('error', $key.': '.$e->getMessage());
        }
        redirect('/accounting/erp#apis');
    }

    /** Everything the page shows that changes while it is open (polled every few seconds). */
    public function status(): never
    {
        $this->gate();
        session_write_close();
        $state = DB::select('SELECT entity, status, mode, last_run_at, last_ok_at, fetched, saved, duration_ms, error FROM erp_sync_state', [], ErpSettings::CONN);
        $beat = Schedule::heartbeat();
        $token = DB::first('SELECT session_suspended, updated_at FROM erp_tokens WHERE is_primary ORDER BY id DESC LIMIT 1', [], ErpSettings::CONN);
        json_response([
            'now' => time(),
            'state' => array_map(fn ($r) => $r + ['ago' => $r['last_run_at'] ? time_ago($r['last_run_at']) : null], $state),
            'runs' => array_map(fn ($r) => $r + ['ago' => time_ago($r['started_at'])], DB::select('SELECT id, kind, source, status, message, fetched, saved, started_at FROM erp_sync_runs ORDER BY id DESC LIMIT 12', [], ErpSettings::CONN)),
            'running' => (int) DB::scalar("SELECT count(*) FROM erp_sync_runs WHERE status = 'running' AND started_at > now() - interval '2 hours'", [], ErpSettings::CONN),
            'totals' => DB::first("SELECT coalesce(sum(saved) FILTER (WHERE started_at::date = CURRENT_DATE), 0) AS today, coalesce(sum(saved), 0) AS all_time, max(finished_at) FILTER (WHERE status = 'ok') AS last_ok, count(*) FILTER (WHERE status = 'failed' AND started_at > now() - interval '1 day') AS failed_day FROM erp_sync_runs", [], ErpSettings::CONN),
            'scheduler' => ['at' => $beat['at'] ?? null, 'by' => $beat['by'] ?? null, 'ago' => $beat ? time() - $beat['at'] : null, 'enabled' => ErpSettings::schedule('enabled') === '1', 'inWindow' => Schedule::inWindow()],
            'token' => $token ? ['ago' => time_ago($token['updated_at']), 'minutes' => (int) round((time() - strtotime((string) $token['updated_at'])) / 60)] : null,
        ]);
    }

    /** Run history, a page at a time (status: ok | warning | failed | running). */
    public function runs(): never
    {
        $this->gate();
        session_write_close();
        $st = (string) Request::query('status', '');
        $per = in_array((int) Request::query('per', 10), [10, 20, 50], true) ? (int) Request::query('per', 10) : 10;
        $where = in_array($st, ['ok', 'warning', 'failed', 'running'], true) ? 'WHERE status = ?' : '';
        $par = $where ? [$st] : [];
        $total = (int) DB::scalar("SELECT count(*) FROM erp_sync_runs {$where}", $par, ErpSettings::CONN);
        $pages = max(1, (int) ceil($total / $per));
        $page = min($pages, max(1, (int) Request::query('page', 1)));
        $rows = DB::select("SELECT id, kind, source, status, message, fetched, saved, started_at, finished_at,
                                   CASE WHEN finished_at IS NOT NULL THEN round(extract(epoch FROM finished_at - started_at)::numeric, 1) END AS seconds
                              FROM erp_sync_runs {$where} ORDER BY id DESC LIMIT {$per} OFFSET ".(($page - 1) * $per), $par, ErpSettings::CONN);
        foreach ($rows as &$r) { $r['ago'] = time_ago($r['started_at']); $r['at'] = date('d M H:i:s', strtotime((string) $r['started_at'])); }
        json_response(['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page]);
    }

    /** Start a sync in the background (cron-less servers fall back to running it here). */
    public function run(): never
    {
        $this->gate();
        $group  = (string) Request::input('group');
        $entity = (string) Request::input('entity');
        $mode   = in_array(Request::input('mode'), ['latest', 'all'], true) ? (string) Request::input('mode') : 'latest';
        if (! ErpSettings::configured()) {
            Session::flash('error', __('Fill in the ERP address, user name and password first.'));
            redirect('/accounting/erp');
        }
        if ($entity !== '') {
            isset(Syncer::entities()[$entity]) || abort(404);
            $args = ['entity', $entity, $mode];
            $label = $entity;
        } elseif (in_array($group, ['hot', 'cold', 'full', 'all'], true)) {
            $args = [$group, (string) $this->user()->id, $mode === 'all' ? 'all' : 'default'];
            $label = $group;
        } else {
            abort(404);
        }
        if (Schedule::spawn($args)) {
            Log::info('sync', 'Started by '.$this->user()->email.': '.$label.($mode === 'all' ? ' (all rows)' : ''));
            Session::flash('success', __('Started :what. Watch it below.', ['what' => $label]));
        } else {
            @set_time_limit(600);
            $entity !== '' ? Schedule::runEntity($entity, $mode, null, $this->user()->id) : Schedule::runGroup($group, 'manual', $this->user()->id, $mode === 'all' ? 'all' : null);
            Session::flash('success', __('Finished :what.', ['what' => $label]));
        }
        redirect('/accounting/erp');
    }
}
