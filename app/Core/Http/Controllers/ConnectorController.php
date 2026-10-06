<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\Crypt;
use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\Settings;
use App\Core\Support\Google\Google;

/** Connectors: the administrator enters the Google OAuth client once; each person connects their own Gmail + Calendar. */
class ConnectorController extends Controller
{
    /* ----------------------------------------------------------------- administrator */

    public function admin(): string
    {
        $this->user()->isAdmin() || abort(403, __('Only an administrator can open this.'));

        return view('admin/connectors', [
            'title' => __('Connectors'), 'clientId' => Google::clientId(), 'hasSecret' => Google::clientSecret() !== '', 'redirect' => Google::redirectUri(),
            'people' => DB::select('SELECT g.*, u.name, u.email FROM google_connections g JOIN users u ON u.id = g.user_id ORDER BY u.name'),
        ]);
    }

    public function saveAdmin(): never
    {
        $this->user()->isAdmin() || abort(403);
        Settings::put('google_client_id', trim((string) Request::input('client_id')));
        if (($secret = trim((string) Request::input('client_secret'))) !== '') { Settings::put('google_client_secret', Crypt::encrypt($secret)); }
        Session::flash('success', __('Google connector saved.'));
        redirect('/connectors');
    }

    /* ----------------------------------------------------------------------- a person */

    public function profile(): string
    {
        $u = $this->user();

        return view('profile/connectors', ['title' => __('Connectors'), 'me' => $u, 'configured' => Google::configured(), 'conn' => Google::connection($u->id)]);
    }

    public function start(): never
    {
        Google::configured() || abort(404, __('The administrator has not set up the Google connector yet.'));
        $state = bin2hex(random_bytes(16));
        $_SESSION['google_state'] = $state;
        $_SESSION['google_back'] = in_array(Request::query('back'), ['mail', 'calendar'], true) ? Request::query('back') : 'profile';
        header('Location: '.Google::loginUrl($state, $this->user()->email));
        exit;
    }

    public function callback(): never
    {
        $u = $this->user();
        $back = ['mail' => '/mail', 'calendar' => '/calendar'][$_SESSION['google_back'] ?? ''] ?? '/profile/connectors';
        $state = (string) Request::query('state', '');
        if ($state === '' || ! hash_equals((string) ($_SESSION['google_state'] ?? ''), $state)) {
            Session::flash('error', __('The Google sign-in could not be checked. Try again.'));
            redirect('/profile/connectors');
        }
        unset($_SESSION['google_state'], $_SESSION['google_back']);
        if ($err = Request::query('error')) {
            Session::flash('error', __('Google said: :e', ['e' => (string) $err]));
            redirect('/profile/connectors');
        }
        try {
            $row = Google::connect($u->id, (string) Request::query('code', ''));
            Session::flash('success', __('Connected to Google as :e.', ['e' => $row['google_email']]));
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
            $back = '/profile/connectors';
        }
        redirect($back);
    }

    public function disconnect(): never
    {
        Google::disconnect($this->user()->id);
        Session::flash('success', __('Google was disconnected. Nothing is deleted from your Google account.'));
        redirect('/profile/connectors');
    }

    /** An administrator can cut a person's connection. */
    public function disconnectUser(int $id): never
    {
        $this->user()->isAdmin() || abort(403);
        Google::disconnect($id);
        Session::flash('success', __('Disconnected.'));
        redirect('/connectors');
    }
}
