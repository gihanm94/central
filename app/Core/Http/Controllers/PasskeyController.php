<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Auth\Auth;
use App\Core\Auth\WebAuthn;
use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\ValidationException;

class PasskeyController extends Controller
{
    public function loginOptions(): never
    {
        json_response(WebAuthn::loginOptions());
    }

    public function login(): never
    {
        $userId = WebAuthn::verifyLogin(Request::all());
        $user   = Auth::load($userId) ?? throw new ValidationException(['passkey' => __('This account is deactivated.')]);
        Auth::login($user, Request::boolean('remember'), 'passkey');

        $to = $_SESSION['_intended'] ?? $user->homeUrl();
        unset($_SESSION['_intended']);
        json_response(['redirect' => url(str_starts_with($to, '/') ? $to : '/dashboard')]);
    }

    public function registerOptions(): never
    {
        json_response(WebAuthn::registrationOptions($this->user()));
    }

    public function register(): never
    {
        $id   = WebAuthn::register($this->user(), Request::all());
        $name = DB::scalar('SELECT name FROM passkeys WHERE id = ?', [$id]);
        Activity::log('passkey_added', 'user', $this->user()->id, $this->user()->name, ['Added passkey ":label"', ['label' => $name]], [], null, null, false);
        json_response(['ok' => true]);
    }

    public function destroy(int $id): never
    {
        $key = DB::first('SELECT * FROM passkeys WHERE id = ? AND user_id = ?', [$id, $this->user()->id]) ?? abort(404);
        DB::exec('DELETE FROM passkeys WHERE id = ?', [$id]);
        Activity::log('passkey_removed', 'user', $this->user()->id, $this->user()->name, ['Removed passkey ":label"', ['label' => $key['name']]], [], null, null, false);
        back('success', __('Passkey removed.'));
    }
}
