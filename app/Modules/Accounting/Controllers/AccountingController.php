<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Support\Request;
use App\Modules\Accounting\Support\Dashboard;

/** Accounting overview: revenue, orders, receivables, e-tax and billing notes. Each block needs the right to see its own screen. */
class AccountingController extends Controller
{
    public function index(): string
    {
        $show = ['finance' => can('accounting_invoices', 'view'), 'orders' => can('accounting_orders', 'view'), 'inet' => can('accounting_inet', 'view'), 'billing' => can('accounting_billing', 'view')];
        $data = array_filter($show) ? Dashboard::get(Request::query('refresh') === '1' && $this->user()->isAdmin()) : [];

        return view('accounting/index', ['title' => __('Accounting overview'), 'show' => $show, 'd' => $data]);
    }
}
