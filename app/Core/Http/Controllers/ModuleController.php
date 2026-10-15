<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\HttpException;
use App\Core\Support\Modules;
use App\Core\Support\Request;
use App\Core\Support\Session;

/** The answer of a module that is fenced off, and the administrators' Modules page (health of every module). */
class ModuleController extends Controller
{
    /** Every URL of a module that failed to load. The rest of the system keeps working. */
    public function unavailable(): never
    {
        $key  = Modules::forPath(Request::path()) ?? '';
        $name = __((string) (Modules::all()[$key]['name'] ?? $key));
        $msg  = __(':module is temporarily unavailable. Everything else keeps working.', ['module' => $name]);
        if ($this->user()->isAdmin()) {
            $msg .= ' '.__('Details are on the Modules page (Administration → Modules).');
        }
        throw new HttpException(503, $msg);
    }

    private function gate(): void
    {
        $this->user()->isAdmin() || abort(403, __('Only an administrator can open this.'));
    }

    public function index(): string
    {
        $this->gate();

        return view('admin/modules', ['title' => __('Modules'), 'modules' => array_diff_key(Modules::all(), ['core' => 1]), 'status' => Modules::status()]);
    }

    /** Forget a module's recorded failures; its next page view or hook call tries again. */
    public function retry(): never
    {
        $this->gate();
        $key = (string) Request::input('module', '');
        Modules::clear($key === '' ? null : $key);
        @unlink(BASE_PATH.'/storage/cache/schema.version');     // migrations are re-checked on the next request too
        Session::flash('success', __('Status cleared. The module is tried again on its next use.'));
        redirect('/modules');
    }
}
