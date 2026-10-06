<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Core\Http\Controllers\Controller;

/** Accounting overview. Left empty on purpose until the dashboards are designed. */
class AccountingController extends Controller
{
    public function index(): string
    {
        return view('accounting/index', ['title' => __('Accounting overview')]);
    }
}
