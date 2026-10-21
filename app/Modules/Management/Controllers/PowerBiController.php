<?php
declare(strict_types=1);

namespace App\Modules\Management\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Support\PowerBi;

/**
 * Management → Power BI → Overview: a Power BI report shown full page in an iframe.
 * The report is chosen by an administrator under Admin → Company settings → Power BI.
 */
class PowerBiController extends Controller
{
    public function index(): string
    {
        $this->authorize('management', 'view');

        return view('management::powerbi', ['title' => __('Power BI overview'), 'url' => PowerBi::url(), 'isAdmin' => $this->user()->isAdmin()]);
    }
}
