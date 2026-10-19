<?php
declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Support\Modules;

/** /admin: opens the first administration page this person may use. */
class HomeController extends Controller
{
    public function index(): never
    {
        $u = $this->user();
        foreach (Modules::all()['admin']['menu'] ?? [] as $item) {
            if (isset($item['heading']) || (! empty($item['admin_only']) && ! $u->isAdmin())) {
                continue;
            }
            [$res, $act] = array_pad(explode('.', (string) ($item['permission'] ?? ''), 2), 2, 'view');
            if ($res === '' || can($res, $act)) {
                redirect($item['url']);
            }
        }
        abort(403, __('You have no administration pages.'));
    }
}
