<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\I18n;
use App\Core\Support\Mailer;
use App\Core\Support\Notifier;
use App\Core\Support\Options;
use App\Core\Support\Permission;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\Settings;
use App\Core\Support\Validator;
use App\Core\Support\ValidationException;

/**
 * Account requests. The sign-in page has a "Request" form (employee id, name, e-mail, phone, gender, department as typed text, password).
 * An administrator opens the request, chooses the real department and role, and approves it: the member is created with the
 * password hash the person chose, so the administrator never learns it. Or rejects it with a reason.
 */
class AccessRequestController extends Controller
{
    public static function enabled(): bool { return Settings::get('access_requests') !== '0'; }

    /* ------------------------------------------------------------ the public form */

    public function submit(): never
    {
        self::enabled() || abort(404);
        if (trim((string) Request::input('website')) !== '') {          // honeypot: bots fill every field
            json_response(['ok' => true, 'message' => __('Thank you. Your request was sent.')]);
        }
        $in = Request::all();
        $d = Validator::validate($in, [
            'employee_code'   => 'required|max:30',
            'name'            => 'required|max:120',
            'email'           => 'required|email|max:190',
            'phone'           => 'required|max:30',
            'gender'          => 'required|in:'.implode(',', array_keys(config('core.employee.genders'))),
            'department_text' => 'required|max:120',
            'password'        => 'required|password',
            'password_confirmation' => 'required|same:password',
        ], ['employee_code' => __('Employee ID'), 'name' => __('Full name'), 'email' => __('Work e-mail'), 'phone' => __('Phone'), 'gender' => __('Gender'), 'department_text' => __('Department'), 'password' => __('Password'), 'password_confirmation' => __('Confirm password')]);

        $email = mb_strtolower($d['email']);
        if (DB::scalar('SELECT 1 FROM users WHERE lower(email) = ? AND deleted_at IS NULL', [$email])) {
            throw new ValidationException(['email' => __('This e-mail already has an account. Use "Forgot password" on the sign-in page.')]);
        }
        if (DB::scalar("SELECT 1 FROM access_requests WHERE lower(email) = ? AND status = 'pending'", [$email])) {
            throw new ValidationException(['email' => __('A request for this e-mail is already waiting for the administrator.')]);
        }
        if (DB::scalar('SELECT 1 FROM employee_profiles WHERE lower(employee_code) = lower(?)', [$d['employee_code']])) {
            throw new ValidationException(['employee_code' => __('This employee ID already belongs to a member.')]);
        }
        if ((int) DB::scalar("SELECT count(*) FROM access_requests WHERE ip_address = ? AND created_at > now() - interval '1 hour'", [Request::ip()]) >= 5) {
            throw new \App\Core\Support\HttpException(429, __('Too many requests from this network. Try again later.'));
        }

        $id = (int) DB::insert('access_requests', [
            'employee_code' => $d['employee_code'], 'name' => $d['name'], 'email' => $email, 'phone' => $d['phone'], 'gender' => $d['gender'], 'department_text' => $d['department_text'],
            'password_hash' => password_hash($d['password'], PASSWORD_DEFAULT), 'ip_address' => Request::ip(), 'locale' => I18n::locale(),
        ]);
        Activity::log('created', 'access_request', $id, $d['name'], ['New account request from :name', ['name' => $d['name']]], ['_url' => url('/members/requests')], null, null, false);

        // tell the people who can decide
        $to = DB::select("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active AND u.deleted_at IS NULL AND r.slug = 'admin'");
        foreach ($to as $a) {
            try {
                Notifier::deliver('core', 'created', (int) $a['id'], ['key' => 'New account request: :name', 'params' => ['name' => $d['name']]],
                    ['key' => ':name (:code) asked for an account. Open the request to choose department and role.', 'params' => ['name' => $d['name'], 'code' => $d['employee_code']]], url('/members/requests'));
            } catch (\Throwable $e) {
                error_log('[access-request] notify: '.$e->getMessage());
            }
        }
        json_response(['ok' => true, 'message' => __('Thank you. Your request was sent. An administrator will review it and e-mail you.')]);
    }

    /* ------------------------------------------------------------------ admin */

    private function guard(): void
    {
        $u = $this->user();
        ($u->isAdmin() || ($u->scope() === 'all' && can('members', 'create'))) || abort(403, __('Only an administrator can review account requests.'));
    }

    public static function pending(): int { return (int) DB::scalar("SELECT count(*) FROM access_requests WHERE status = 'pending'"); }

    public function index(): string
    {
        $this->guard();
        $status = in_array(Request::query('status'), ['pending', 'approved', 'rejected'], true) ? (string) Request::query('status') : 'pending';
        $q = trim((string) Request::query('q', ''));
        $where = 'r.status = ?'; $par = [$status];
        if ($q !== '') {
            $where .= ' AND (r.name ILIKE ? OR r.email ILIKE ? OR r.employee_code ILIKE ? OR r.department_text ILIKE ?)';
            array_push($par, ...array_fill(0, 4, '%'.$q.'%'));
        }
        $per  = in_array((int) Request::query('per_page'), [10, 20, 50], true) ? (int) Request::query('per_page') : 10;
        $page = max(1, (int) Request::query('page', 1));
        $total = (int) DB::scalar("SELECT count(*) FROM access_requests r WHERE {$where}", $par);
        $rows = DB::select("SELECT r.*, u.name AS reviewer FROM access_requests r LEFT JOIN users u ON u.id = r.reviewed_by WHERE {$where} ORDER BY r.created_at DESC LIMIT {$per} OFFSET ".(($page - 1) * $per), $par);
        $counts = array_column(DB::select('SELECT status, count(*) n FROM access_requests GROUP BY status'), 'n', 'status');

        return view('members/requests', ['title' => __('Account requests'), 'rows' => $rows, 'total' => $total, 'status' => $status, 'q' => $q, 'per' => $per, 'page' => $page, 'counts' => $counts]);
    }

    public function review(int $id): string
    {
        $this->guard();
        $r = DB::first('SELECT * FROM access_requests WHERE id = ?', [$id]) ?? abort(404);
        $u = $this->user();
        $deps = Options::departments($u);
        // pre-select the department whose name or code matches what the person typed
        $guess = '';
        $t = mb_strtolower(trim((string) $r['department_text']));
        foreach (DB::select('SELECT id, name, code FROM departments WHERE deleted_at IS NULL') as $dep) {
            if ($t !== '' && (mb_strtolower($dep['name']) === $t || mb_strtolower($dep['code']) === $t || str_contains(mb_strtolower($dep['name']), $t) || str_contains($t, mb_strtolower($dep['name'])))) { $guess = (string) $dep['id']; break; }
        }

        return view('members/request-review', ['title' => __('Account request'), 'r' => $r, 'departments' => $deps, 'guess' => $guess, 'roles' => array_map(fn ($n) => __($n), Options::roles($u)),
            'teams' => Options::teams($u), 'people' => Options::users($u)]);
    }

    public function approve(int $id): never
    {
        $this->guard();
        $r = DB::first("SELECT * FROM access_requests WHERE id = ? AND status = 'pending'", [$id]) ?? abort(404, __('This request was already reviewed.'));
        $u = $this->user();
        $d = Validator::validate(Request::all(), [
            'name' => 'required|max:120', 'email' => 'required|email|max:190', 'employee_code' => 'required|max:30', 'phone' => 'nullable|max:30',
            'gender' => 'nullable|in:'.implode(',', array_keys(config('core.employee.genders'))),
            'department_id' => 'required', 'role_id' => 'required', 'team_id' => 'nullable', 'manager_id' => 'nullable', 'job_title' => 'nullable|max:120',
        ], ['name' => __('Full name'), 'email' => __('Work e-mail'), 'employee_code' => __('Employee ID'), 'department_id' => __('Department'), 'role_id' => __('Role')]);

        if (! isset(Options::departments($u)[(int) $d['department_id']])) { throw new ValidationException(['department_id' => __('Choose a department from the list.')]); }
        if (! isset(Options::roles($u)[(int) $d['role_id']])) { throw new ValidationException(['role_id' => __('Choose a role from the list.')]); }
        $email = mb_strtolower($d['email']);
        if (DB::scalar('SELECT 1 FROM users WHERE lower(email) = ? AND deleted_at IS NULL', [$email])) { throw new ValidationException(['email' => __('This e-mail already has an account.')]); }
        if (DB::scalar('SELECT 1 FROM employee_profiles WHERE lower(employee_code) = lower(?)', [$d['employee_code']])) { throw new ValidationException(['employee_code' => __('This employee ID already belongs to a member.')]); }
        if (! empty($d['team_id'])) {
            $td = DB::scalar('SELECT department_id FROM teams WHERE id = ? AND deleted_at IS NULL', [$d['team_id']]);
            if ((int) $td !== (int) $d['department_id']) { throw new ValidationException(['team_id' => __('This team belongs to another department.')]); }
        }

        $uid = (int) DB::transaction(function () use ($r, $d, $email) {
            $uid = (int) DB::insert('users', [
                'name' => $d['name'], 'email' => $email, 'password' => $r['password_hash'], 'role_id' => (int) $d['role_id'], 'department_id' => (int) $d['department_id'],
                'team_id' => $d['team_id'] ?: null, 'manager_id' => $d['manager_id'] ?: null, 'is_active' => true, 'locale' => $r['locale'] ?: null,
            ]);
            DB::exec('INSERT INTO employee_profiles (user_id, employee_code, job_title, phone, gender) VALUES (?, ?, ?, ?, ?)', [$uid, $d['employee_code'], $d['job_title'] ?: null, $d['phone'] ?: null, $d['gender'] ?: null]);
            DB::exec("UPDATE access_requests SET status = 'approved', user_id = ?, reviewed_by = ?, reviewed_at = now(), password_hash = NULL WHERE id = ?", [$uid, $this->user()->id, $r['id']]);

            return $uid;
        });
        Permission::forget($uid);
        Activity::log('created', 'member', $uid, $d['name'], ['Created member ":label" from an account request', ['label' => $d['name']]], ['_url' => url('/members/'.$uid)], null);
        $this->mail($r, $d['name'], $email, __('Your account is ready'), [__('Your request was approved. You can sign in now with the e-mail and password you chose.')], ['label' => __('Sign in'), 'url' => url('/login')]);

        Session::flash('success', __(':name is now a member.', ['name' => $d['name']]));
        redirect('/members/requests');
    }

    public function reject(int $id): never
    {
        $this->guard();
        $r = DB::first("SELECT * FROM access_requests WHERE id = ? AND status = 'pending'", [$id]) ?? abort(404, __('This request was already reviewed.'));
        $why = trim((string) Request::input('reason'));
        DB::exec("UPDATE access_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = now(), reject_reason = ?, password_hash = NULL WHERE id = ?", [$this->user()->id, $why !== '' ? mb_substr($why, 0, 300) : null, $r['id']]);
        Activity::log('updated', 'access_request', (int) $r['id'], $r['name'], ['Rejected the account request of :name', ['name' => $r['name']]], [], null, null, false);
        $this->mail($r, $r['name'], $r['email'], __('About your account request'), array_filter([__('Your account request was not approved.'), $why !== '' ? e($why) : null, __('Please contact your administrator if you think this is a mistake.')]), null);
        Session::flash('success', __('Request rejected.'));
        redirect('/members/requests');
    }

    public function destroy(int $id): never
    {
        $this->guard();
        DB::exec("DELETE FROM access_requests WHERE id = ? AND status <> 'pending'", [$id]);
        Session::flash('success', __('Removed.'));
        redirect('/members/requests?status='.(Request::input('status') ?: 'approved'));
    }

    private function mail(array $r, string $name, string $email, string $subject, array $lines, ?array $button): void
    {
        try {
            I18n::with($r['locale'] ?: null, fn () => Mailer::notify($email, $name, $subject, $lines, $button));
        } catch (\Throwable $e) {
            error_log('[access-request] mail: '.$e->getMessage());
        }
    }
}
