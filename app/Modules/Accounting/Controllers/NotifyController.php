<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Support\DB;
use App\Core\Support\Lark;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Modules\Accounting\Billing\BillingNotify;
use App\Modules\Accounting\Erp\ErpSettings;

/** Administrators: who gets the daily Lark list of billing notes, and when. */
class NotifyController extends Controller
{
    private function gate(): void
    {
        $this->user()->isAdmin() || abort(403, __('Only an administrator can open this.'));
    }

    public function edit(): string
    {
        $this->gate();

        return view('accounting/notify', [
            'title' => __('Notifications'), 'enabled' => BillingNotify::enabled(), 'time' => BillingNotify::time(), 'picked' => BillingNotify::userIds(), 'mode' => Lark::mode(),
            'users' => DB::select('SELECT id, name, email FROM users WHERE is_active AND deleted_at IS NULL ORDER BY name'), 'today' => BillingNotify::today(), 'last' => ErpSettings::get('notify.last'),
        ]);
    }

    public function save(): never
    {
        $this->gate();
        $u = $this->user()->id;
        $time = (string) Request::input('time', '08:30');
        ErpSettings::put('notify.enabled', Request::input('enabled') ? '1' : '0', $u);
        ErpSettings::put('notify.time', preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : '08:30', $u);
        ErpSettings::put('notify.users', implode(',', array_map('intval', (array) Request::input('users', []))), $u);
        Session::flash('success', __('Notification settings saved.'));
        redirect('/accounting/notify');
    }

    /** Send today's list now (also when it is empty, so the connection can be tested). */
    public function send(): never
    {
        $this->gate();
        $r = BillingNotify::send(true);
        if ($r['sent'] > 0) { Session::flash('success', __('Sent to :n recipient(s) on Lark.', ['n' => $r['sent']]).($r['failed'] ? ' '.__('Failed: :x', ['x' => implode(', ', $r['failed'])]) : '')); }
        else { Session::flash('error', __('Nothing was sent: :x', ['x' => implode(', ', $r['failed']) ?: __('unknown')])); }
        redirect('/accounting/notify');
    }
}
