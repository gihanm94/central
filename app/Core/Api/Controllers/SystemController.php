<?php
declare(strict_types=1);

namespace App\Core\Api\Controllers;

use App\Core\Api\ApiException;
use App\Core\Api\Controller;

class SystemController extends Controller
{
    /** API URLs of a module that is fenced off (see Modules::api). */
    public function unavailable(): never
    {
        throw new ApiException(503, 'module_unavailable', __('This part of the system is temporarily unavailable. Everything else keeps working.'));
    }
}
