<?php
declare(strict_types=1);

namespace App\Modules\Sheets\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Support\DB;
use App\Core\Support\Google\Google;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\ValidationException;
use App\Modules\Sheets\Support\Accounts;
use App\Modules\Sheets\Support\Catalog;
use App\Modules\Sheets\Support\GSheets;
use App\Modules\Sheets\Support\Runner;
use App\Modules\Sheets\Support\Schedule;

/** A person's syncs (table → Google Sheet tab on a schedule) and their connected Google accounts. */
class SyncController extends Controller
{
    private const SELECT = 'SELECT s.*, t.db, t.table_name, t.label, a.google_email, u.name AS owner_name
        FROM gsheet_syncs s JOIN gsheet_tables t ON t.id = s.table_id JOIN gsheet_accounts a ON a.id = s.account_id JOIN users u ON u.id = s.user_id';

    public function index(): string
    {
        $u = $this->user();
        $everyone = $u->isAdmin() && Request::query('all') === '1';
        $syncs = DB::select(self::SELECT.($everyone ? '' : ' WHERE s.user_id = ?').' ORDER BY coalesce(t.label, t.table_name), s.id', $everyone ? [] : [$u->id]);

        return view('sheets::index', ['title' => __('Google Sheets'), 'syncs' => $syncs, 'everyone' => $everyone, 'tables' => Catalog::allowed($u),
            'accounts' => Accounts::of($u->id), 'configured' => Google::configured(), 'officeLabel' => Schedule::officeLabel(), 'paused' => Schedule::inOffice()]);
    }

    /* ------------------------------------------------------------------ Google accounts */

    public function connect(): never
    {
        Google::configured() || abort(404, __('The administrator has not set up the Google connector yet.'));
        $state = bin2hex(random_bytes(16));
        $_SESSION['gsheet_state'] = $state;
        Google::forwardTo('/sheets/connect/callback');
        header('Location: '.Google::loginUrl($state, null, null, 'consent select_account'));     // Sheets + Gmail + Calendar in one consent
        exit;
    }

    public function callback(): never
    {
        $u = $this->user();
        $state = (string) Request::query('state', '');
        $ok = $state !== '' && hash_equals((string) ($_SESSION['gsheet_state'] ?? ''), $state);
        unset($_SESSION['gsheet_state']);
        if (! $ok) {
            self::go('/sheets', 'error', __('The Google sign-in could not be checked. Try again.'));
        }
        if ($err = Request::query('error')) {
            self::go('/sheets', 'error', __('Google said: :e', ['e' => (string) $err]));
        }
        try {
            $row = Accounts::connect($u->id, (string) Request::query('code', ''));
            self::go($_SESSION['gsheet_back'] ?? '/sheets', 'success', __('Google account :e connected for Sheets.', ['e' => $row['google_email']]));
        } catch (\Throwable $e) {
            self::go('/sheets', 'error', $e->getMessage());
        }
    }

    public function disconnect(int $id): never
    {
        Accounts::disconnect($id, $this->user()->id);
        self::go('/sheets', 'success', __('Google account disconnected. Its syncs were removed; nothing was deleted from Google.'));
    }

    /** JSON: spreadsheets of one of my accounts (?q= search). */
    public function files(int $id): never
    {
        Accounts::find($id, $this->user()->id) ?? abort(404);
        try {
            json_response(['files' => GSheets::spreadsheets($id, trim((string) Request::query('q', '')))]);
        } catch (\Throwable $e) {
            json_response(['message' => $e->getMessage()], 502);
        }
    }

    /** JSON: tabs of a spreadsheet (?file=). */
    public function tabs(int $id): never
    {
        Accounts::find($id, $this->user()->id) ?? abort(404);
        try {
            json_response(GSheets::spreadsheet($id, self::fileId((string) Request::query('file', ''))));
        } catch (\Throwable $e) {
            json_response(['message' => $e->getMessage()], 502);
        }
    }

    /* ---------------------------------------------------------------------------- syncs */

    public function create(): string
    {
        $u = $this->user();
        $tables = Catalog::allowed($u);
        $_SESSION['gsheet_back'] = '/sheets/new'.(Request::query('table') ? '?table='.(int) Request::query('table') : '');

        return view('sheets::form', ['title' => __('New sync'), 'sync' => null, 'tables' => $tables, 'accounts' => Accounts::of($u->id),
            'tableId' => (int) Request::query('table', $tables[0]['id'] ?? 0), 'office' => Schedule::office(), 'officeLabel' => Schedule::officeLabel()]);
    }

    public function store(): never
    {
        $u = $this->user();
        $data = $this->checked(null);
        $data['user_id'] = $u->id;
        $id = DB::insert('gsheet_syncs', $data);
        self::go('/sheets', 'success', __('Sync saved. Next run: :t.', ['t' => format_date($data['next_run_at'], 'D d M, H:i')]).' #'.$id);
    }

    public function edit(int $id): string
    {
        $u = $this->user();
        $s = $this->mine($id);

        return view('sheets::form', ['title' => __('Edit sync'), 'sync' => $s, 'tables' => Catalog::allowed($u), 'accounts' => Accounts::of($u->id),
            'tableId' => (int) $s['table_id'], 'office' => Schedule::office(), 'officeLabel' => Schedule::officeLabel()]);
    }

    public function update(int $id): never
    {
        $s = $this->mine($id);
        $data = $this->checked($s) + ['active' => true, 'updated_at' => now()];
        DB::update('gsheet_syncs', $data, ['id' => $id]);
        self::go('/sheets', 'success', __('Sync saved. Next run: :t.', ['t' => format_date($data['next_run_at'], 'D d M, H:i')]));
    }

    public function destroy(int $id): never
    {
        $this->mine($id, true);
        DB::exec('DELETE FROM gsheet_syncs WHERE id = ?', [$id]);
        self::go('/sheets', 'success', __('Sync removed. The Google Sheet itself is kept.'));
    }

    public function run(int $id): never
    {
        $this->mine($id, true);
        try {
            $line = Runner::now($id);
            $s = DB::first('SELECT last_status, last_message FROM gsheet_syncs WHERE id = ?', [$id]);
            Session::flash($s['last_status'] === 'ok' ? 'success' : 'error', (string) $s['last_message']);
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }
        back();
    }

    /** Pause / resume. */
    public function toggle(int $id): never
    {
        $s = $this->mine($id, true);
        if (filter_var($s['active'], FILTER_VALIDATE_BOOL)) {
            DB::exec('UPDATE gsheet_syncs SET active = FALSE, next_run_at = NULL, updated_at = now() WHERE id = ?', [$id]);
            back('success', __('Sync paused.'));
        }
        $next = Schedule::next($s) ?? self::go('/sheets/'.$id.'/edit', 'error', __('That date has passed. Choose a new one.'));
        DB::exec('UPDATE gsheet_syncs SET active = TRUE, next_run_at = ?, updated_at = now() WHERE id = ?', [$next->format('c'), $id]);
        back('success', __('Sync resumed. Next run: :t.', ['t' => $next->format('D d M, H:i')]));
    }

    /* -------------------------------------------------------------------------- helpers */

    private static function go(string $to, string $type, string $message): never
    {
        Session::flash($type, $message);
        redirect($to);
    }

    /** A sync of mine (administrators: anybody's when $adminToo). */
    private function mine(int $id, bool $adminToo = false): array
    {
        $u = $this->user();
        $s = DB::first('SELECT * FROM gsheet_syncs WHERE id = ?', [$id]) ?? abort(404);
        if ((int) $s['user_id'] !== $u->id && ! ($adminToo && $u->isAdmin())) {
            abort(403);
        }

        return $s;
    }

    /** Spreadsheet id from an id or a docs.google.com link. */
    private static function fileId(string $v): string
    {
        $v = trim($v);
        if (preg_match('#/spreadsheets/d/([A-Za-z0-9_-]+)#', $v, $m)) {
            return $m[1];
        }

        return preg_match('/^[A-Za-z0-9_-]{1,128}$/', $v) ? $v : '';
    }

    /** Validates the form and resolves the spreadsheet (creates a new one when asked). @return array columns for gsheet_syncs */
    private function checked(?array $old): array
    {
        $u = $this->user();
        $in = Request::all();
        $errors = [];
        $tableId = (int) ($in['table_id'] ?? 0);
        $table = null;
        foreach (Catalog::allowed($u) as $t) {
            if ((int) $t['id'] === $tableId) { $table = $t; }
        }
        if (! $table) { $errors['table_id'] = __('Choose a table you may sync.'); }
        $accountId = (int) ($in['account_id'] ?? 0);
        if (! Accounts::find($accountId, $u->id)) { $errors['account_id'] = __('Choose one of your connected Google accounts.'); }
        $tab = trim(preg_replace('/[\[\]\*\?\/\\\\:]/', ' ', (string) ($in['sheet_tab'] ?? '')) ?? '');
        if ($tab === '' || mb_strlen($tab) > 100) { $errors['sheet_tab'] = __('Give the tab a name (up to 100 characters).'); }
        $new = ($in['target'] ?? '') === 'new';
        $fileId = self::fileId(trim((string) ($in['spreadsheet_link'] ?? '')) ?: (string) ($in['spreadsheet_id'] ?? ''));
        $newName = trim((string) ($in['new_name'] ?? ''));
        if ($new && $newName === '') { $errors['new_name'] = __('Give the new spreadsheet a name.'); }
        if (! $new && $fileId === '') { $errors['spreadsheet_id'] = __('Choose a spreadsheet or paste its link.'); }
        try {
            $when = Schedule::validate($in);
        } catch (ValidationException $e) {
            $errors += $e->errors;
        }
        if ($errors) {
            throw new ValidationException($errors);
        }

        try {
            if ($new) {
                $file = GSheets::create($accountId, mb_substr($newName, 0, 200), $tab);
            } else {
                $file = ['id' => $fileId, 'name' => GSheets::spreadsheet($accountId, $fileId)['name']];
            }
        } catch (\Throwable $e) {
            throw new ValidationException([$new ? 'new_name' : 'spreadsheet_id' => $e->getMessage()]);
        }
        $next = Schedule::next($when);

        return ['table_id' => $tableId, 'account_id' => $accountId, 'spreadsheet_id' => $file['id'], 'spreadsheet_name' => mb_substr($file['name'], 0, 255),
            'sheet_tab' => $tab, 'next_run_at' => $next?->format('c')] + $when;
    }
}
