<?php
declare(strict_types=1);

namespace App\Modules\Sheets\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Google\Google;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\Settings;
use App\Core\Support\ValidationException;
use App\Modules\Sheets\Support\Catalog;
use App\Modules\Sheets\Support\Schedule;

/** Administrators: every table of every module, which ones may go to Google Sheets and for whom; office hours and limits. */
class AdminController extends Controller
{
    private function gate(): void
    {
        $this->user()->isAdmin() || abort(403, __('Only an administrator can open this.'));
    }

    public function tables(): string
    {
        $this->gate();
        $dbs = Catalog::databases();
        $db = isset($dbs[(string) Request::query('db')]) ? (string) Request::query('db') : (string) array_key_first($dbs);
        $q = mb_strtolower(trim((string) Request::query('q', '')));
        $error = null;
        try {
            $tables = Catalog::tables($db);
        } catch (\Throwable $e) {
            $tables = [];
            $error = $e->getMessage();
        }
        if ($q !== '') {
            $tables = array_filter($tables, fn ($t, $name) => str_contains($name, $q), ARRAY_FILTER_USE_BOTH);
        }
        $entries = [];
        foreach (DB::select('SELECT t.*, (SELECT count(*) FROM gsheet_table_access a WHERE a.table_id = t.id AND a.role_id IS NOT NULL) AS roles,
                (SELECT count(*) FROM gsheet_table_access a WHERE a.table_id = t.id AND a.user_id IS NOT NULL) AS people,
                (SELECT count(*) FROM gsheet_syncs s WHERE s.table_id = t.id) AS syncs FROM gsheet_tables t WHERE t.db = ?', [$db]) as $e) {
            $entries[$e['table_name']] = $e;
        }

        return view('sheets::admin-tables', ['title' => __('Tables for Google Sheets'), 'dbs' => $dbs, 'db' => $db, 'q' => $q, 'tables' => $tables, 'entries' => $entries, 'error' => $error]);
    }

    public function editTable(): string
    {
        $this->gate();
        [$db, $name, $info] = $this->table();
        $t = Catalog::entry($db, $name);

        return view('sheets::admin-table', ['title' => Catalog::label($t), 't' => $t, 'db' => $db, 'dbName' => Catalog::databases()[$db], 'info' => $info,
            'hidden' => Catalog::hidden($t), 'access' => Catalog::access((int) $t['id']),
            'roles' => DB::select('SELECT id, name FROM roles ORDER BY level DESC, name'),
            'users' => DB::select('SELECT u.id, u.name, u.email, r.name AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active ORDER BY u.name'),
            'syncs' => DB::select('SELECT s.id, s.sheet_tab, s.spreadsheet_name, s.active, u.name FROM gsheet_syncs s JOIN users u ON u.id = s.user_id WHERE s.table_id = ? ORDER BY u.name', [$t['id']])]);
    }

    public function saveTable(): never
    {
        $this->gate();
        [$db, $name, $info] = $this->table();
        $t = Catalog::entry($db, $name);
        $cols = array_keys($info['columns']);
        $shown = array_intersect((array) Request::input('columns', []), $cols);
        $hidden = array_values(array_diff(array_filter($cols, fn ($c) => ! Catalog::secretColumn($c)), $shown));
        $enabled = (bool) Request::input('enabled');
        if ($enabled && count($hidden) + count(array_filter($cols, [Catalog::class, 'secretColumn'])) >= count($cols)) {
            throw new ValidationException(['columns' => __('Leave at least one column to send.')]);
        }
        $roles = array_map('intval', (array) Request::input('roles', []));
        $users = array_map('intval', (array) Request::input('users', []));
        DB::transaction(function () use ($t, $enabled, $hidden, $roles, $users) {
            DB::update('gsheet_tables', ['label' => mb_substr(trim((string) Request::input('label', '')), 0, 120) ?: null, 'enabled' => $enabled,
                'hidden_columns' => json_encode($hidden), 'updated_by' => $this->user()->id, 'updated_at' => now()], ['id' => $t['id']]);
            DB::exec('DELETE FROM gsheet_table_access WHERE table_id = ?', [$t['id']]);
            foreach (array_unique($roles) as $r) {
                DB::exec('INSERT INTO gsheet_table_access (table_id, role_id) SELECT ?, id FROM roles WHERE id = ?', [$t['id'], $r]);
            }
            foreach (array_unique($users) as $uid) {
                DB::exec('INSERT INTO gsheet_table_access (table_id, user_id) SELECT ?, id FROM users WHERE id = ?', [$t['id'], $uid]);
            }
        });
        Activity::log('updated', 'sheet table', (int) $t['id'], $db.'.'.$name, ($enabled ? 'Opened' : 'Closed').' for Google Sheets', [], null, notify: false, module: 'sheets');
        Session::flash('success', __('Saved. People who lost access stop syncing on their next run.'));
        redirect(url('/sheets/tables', ['db' => $db]));
    }

    /** [$db, $table, info] from the request, checked against the real tables. */
    private function table(): array
    {
        $db = (string) Request::input('db', Request::query('db', ''));
        $name = (string) Request::input('table', Request::query('table', ''));
        isset(Catalog::databases()[$db]) || abort(404);
        $info = Catalog::tables($db)[$name] ?? abort(404, __('That table is not available.'));

        return [$db, $name, $info];
    }

    public function settings(): string
    {
        $this->gate();

        return view('sheets::admin-settings', ['title' => __('Google Sheets settings'), 'office' => Schedule::office(), 'maxRows' => Schedule::maxRows(),
            'configured' => Google::configured(), 'redirect' => Google::redirectUri()]);
    }

    public function saveSettings(): never
    {
        $this->gate();
        $in = Request::all();
        $errors = [];
        foreach (['office_start', 'office_end'] as $k) {
            if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($in[$k] ?? ''))) { $errors[$k] = __('Enter a time like 08:30.'); }
        }
        if (! $errors && $in['office_start'] >= $in['office_end']) { $errors['office_end'] = __('The end must be after the start.'); }
        $days = array_values(array_filter(array_map('intval', (array) ($in['office_days'] ?? [])), fn ($d) => $d >= 1 && $d <= 7));
        $max = (int) ($in['max_rows'] ?? 0);
        if ($max < 100 || $max > 1000000) { $errors['max_rows'] = __('Between 100 and 1,000,000.'); }
        if ($errors) {
            throw new ValidationException($errors);
        }
        Settings::put('gsheet_office_start', $in['office_start']);
        Settings::put('gsheet_office_end', $in['office_end']);
        Settings::put('gsheet_office_days', implode(',', $days));
        Settings::put('gsheet_max_rows', (string) $max);
        Session::flash('success', __('Settings saved. Existing schedules keep their times; syncs never run during the new office hours.'));
        redirect('/sheets/settings');
    }
}
