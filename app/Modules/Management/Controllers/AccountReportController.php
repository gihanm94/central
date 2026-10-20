<?php
declare(strict_types=1);

namespace App\Modules\Management\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Support\DB;
use App\Core\Support\Modules;
use App\Core\Support\Request;

/**
 * Management → Account report: the whole company in figures — income and expenses by year, by type, by department
 * and by month — from Accounting's account report (Accounting → Management → Account report) through the
 * 'account.report' hook. When Accounting is off or broken the page says so.
 */
class AccountReportController extends Controller
{
    public function index(): string
    {
        $this->authorize('management', 'view');
        $data = null;
        if (Modules::usable('accounting')) {
            $data = Modules::hook('account.report', [['year' => (int) Request::query('year', 0) ?: null, 'department' => (string) Request::query('department', '') ?: null]], 'accounting')['accounting'] ?? null;
        }
        $colors = array_column(DB::select('SELECT name, color, id FROM departments WHERE deleted_at IS NULL'), null, 'name');

        return view('management::account-report', ['title' => __('Account report'), 'data' => $data,
            'deptColor' => fn (string $name) => isset($colors[$name]) ? \App\Core\Support\DeptColor::of((int) $colors[$name]['id'], $colors[$name]['color']) : '#94a3b8',
            'canOpen' => can('accounting_reports', 'view')]);
    }
}
