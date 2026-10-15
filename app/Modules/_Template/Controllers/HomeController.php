<?php
declare(strict_types=1);

namespace App\Modules\Example\Controllers;

use App\Core\Http\Controllers\Controller;

class HomeController extends Controller
{
    public function index(): string
    {
        // Query this module's own database: DB::select($sql, $params, 'example')
        return view('example::home', ['title' => __('Example')]);
    }
}
