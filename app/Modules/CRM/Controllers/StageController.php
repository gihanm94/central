<?php
declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\ValidationException;
use App\Modules\CRM\Support\Access;
use App\Modules\CRM\Support\Catalog;
use App\Modules\CRM\Support\Stages;

/**
 * Which opportunity stages each department can use, and the colour of each stage.
 * The stages and their names are fixed; nobody edits those. Whole-company roles set colours and any department;
 * a BU Manager sets only their own department.
 */
class StageController extends Controller
{
    public function index(): string
    {
        $this->authorize('crm_stages', 'view');
        $u      = $this->user();
        $deps   = Access::departments();
        $canSet = can('crm_stages', 'edit');
        $mine   = Access::seesAll($u) ? array_keys($deps) : ($u->department_id ? [$u->department_id] : []);
        $hidden = [];
        foreach (DB::select('SELECT stage, department_id FROM stage_hidden', [], 'crm') as $r) {
            $hidden[$r['stage']][(int) $r['department_id']] = true;
        }

        return view('crm/stages', [
            'title' => __('Opportunity stages'), 'stages' => Catalog::STAGES, 'colors' => Stages::colors(), 'deps' => $deps, 'hidden' => $hidden,
            'editableDeps' => $canSet ? $mine : [], 'canColor' => $canSet && Access::seesAll($u), 'tabs' => LookupController::tabs(),
        ]);
    }

    public function save(): never
    {
        $this->authorize('crm_stages', 'edit');
        $u    = $this->user();
        $deps = Access::departments();
        $all  = Access::seesAll($u);
        $mine = $all ? array_keys($deps) : ($u->department_id ? [$u->department_id] : []);

        if ($all) {
            foreach ((array) Request::input('color', []) as $code => $hex) {
                if (isset(Catalog::STAGES[$code]) && preg_match('/^#[0-9a-f]{6}$/i', (string) $hex)) {
                    DB::exec('UPDATE crm_stages SET color = ? WHERE code = ?', [strtolower((string) $hex), $code], 'crm');
                }
            }
        }

        $posted = (array) Request::input('use', []);          // use[DEPT][CODE] = 1 when the department uses the stage
        foreach ($mine as $dep) {
            $use = array_keys(array_filter((array) ($posted[$dep] ?? []), fn ($v, $k) => isset(Catalog::STAGES[$k]) && $v, ARRAY_FILTER_USE_BOTH));
            if (! $use) {
                throw new ValidationException(['use' => __('Every department needs at least one stage (:dept).', ['dept' => $deps[$dep] ?? $dep])]);
            }
            DB::exec('DELETE FROM stage_hidden WHERE department_id = ?', [$dep], 'crm');
            foreach (array_diff(array_keys(Catalog::STAGES), $use) as $code) {
                DB::exec('INSERT INTO stage_hidden (stage, department_id, updated_by) VALUES (?, ?, ?)', [$code, $dep, $u->id], 'crm');
            }
        }
        Stages::forgetCache();
        Activity::log('updated', 'stage', null, 'Opportunity stages', ['Updated the opportunity stage settings', []], [], null, null, false, 'crm');
        Session::flash('success', __('Stage settings saved.'));
        redirect('/crm/settings/stages');
    }
}
