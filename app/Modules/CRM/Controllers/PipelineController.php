<?php
declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\ValidationException;
use App\Modules\CRM\Support\Access;
use App\Modules\CRM\Support\Catalog;
use App\Modules\CRM\Support\Stages;
use App\Modules\CRM\Support\Targets;

/**
 * Kanban board of opportunities. One column per stage the person's department uses, cards load 10 at a time
 * as a column is scrolled. Every card is visible to those who may see the opportunity, but only the person who
 * created it can drag it to another stage. The person's own cards are highlighted.
 */
class PipelineController extends Controller
{
    private const PER_COLUMN = 10;
    private const FROM = 'opportunities t LEFT JOIN leads l ON l.id = t.lead_id LEFT JOIN contacts ct ON ct.id = t.contact_id LEFT JOIN currencies cu ON cu.code = t.currency';
    private const VALUE = 'COALESCE(t.amount, 0) * COALESCE(cu.rate_to_base, 1)';

    /** WHERE for everything on the board: what the person may see + search / my records / department. */
    private function filter(): array
    {
        $u      = $this->user();
        $vis    = Access::visible($u, 't', 'opportunity');
        $where  = ['t.deleted_at IS NULL', $vis['sql']];
        $params = $vis['params'];
        if (($q = trim((string) Request::input('q', ''))) !== '') {
            $like    = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $where[] = '(t.name ILIKE ? OR t.code ILIKE ? OR l.name_en ILIKE ? OR l.name_th ILIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        if (Request::input('mine') === '1') {
            $where[]  = 't.owner_id = ?';
            $params[] = $u->id;
        }
        $dep = (int) Request::input('department', 0);
        if ($dep && Access::seesAll($u)) {
            $where[]  = 't.department_id = ?';
            $params[] = $dep;
        }

        return [implode(' AND ', $where), $params];
    }

    /** [code => ['n' => count, 'v' => value in base currency]] for the stages shown */
    private function stats(array $stages): array
    {
        [$where, $params] = $this->filter();
        $rows = DB::select('SELECT t.opportunity_stage AS stage, count(*) AS n, COALESCE(sum('.self::VALUE.'), 0) AS v FROM '.self::FROM.' WHERE '.$where.' GROUP BY 1', $params, 'crm');
        $out  = array_fill_keys($stages, ['n' => 0, 'v' => 0.0]);
        foreach ($rows as $r) {
            if (isset($out[$r['stage']])) {
                $out[$r['stage']] = ['n' => (int) $r['n'], 'v' => (float) $r['v']];
            }
        }

        return $out;
    }

    private function rows(string $stage, int $page, ?int $onlyId = null): array
    {
        [$where, $params] = $this->filter();
        if ($onlyId) {
            $where   .= ' AND t.id = ?';
            $params[] = $onlyId;
        } else {
            $where   .= ' AND t.opportunity_stage = ?';
            $params[] = $stage;
        }
        $rows = DB::select('SELECT t.id, t.code, t.name, t.amount, t.currency, t.probability, t.close_at, t.priority, t.owner_id, t.created_by, t.opportunity_stage, t.updated_at,
                                   l.name_en AS lead_name, l.name_th AS lead_name_th, l.image AS lead_image, ct.name_en AS contact_name
                              FROM '.self::FROM.' WHERE '.$where.' ORDER BY t.updated_at DESC, t.id DESC LIMIT '.(self::PER_COLUMN + 1).' OFFSET '.(($page - 1) * self::PER_COLUMN), $params, 'crm');
        $names = Access::names('users', array_merge(array_column($rows, 'owner_id'), array_column($rows, 'created_by')));
        foreach ($rows as &$r) {
            $r['owner_name']   = $names[$r['owner_id'] ?? 0] ?? null;
            $r['creator_name'] = $names[$r['created_by'] ?? 0] ?? null;
        }

        return $rows;
    }

    private function html(array $rows, array $stages): string
    {
        $u = $this->user();
        $out = '';
        foreach ($rows as $r) {
            $out .= partial('crm/pipeline-card', ['o' => $r, 'me' => $u->id, 'canEdit' => can('crm_opportunities', 'edit'), 'stages' => $stages]);
        }

        return $out;
    }

    public function index(): string
    {
        $this->authorize('crm_opportunities', 'view');
        $u      = $this->user();
        $stages = Stages::availableFor($u);
        $stats  = $this->stats($stages);
        $cols   = [];
        foreach ($stages as $code) {
            $rows = $this->rows($code, 1);
            $more = count($rows) > self::PER_COLUMN;
            $cols[$code] = ['stats' => $stats[$code], 'html' => $this->html(array_slice($rows, 0, self::PER_COLUMN), $stages), 'more' => $more];
        }

        return view('crm/pipeline', [
            'title' => __('Pipeline'), 'cols' => $cols, 'stages' => $stages, 'cur' => Targets::baseCurrency(), 'all' => Access::seesAll($u), 'deps' => Access::departments(),
            'q' => trim((string) Request::input('q', '')), 'mine' => Request::input('mine') === '1', 'dep' => (int) Request::input('department', 0), 'canEdit' => can('crm_opportunities', 'edit'),
            'needsReason' => Catalog::NEEDS_REASON,
        ]);
    }

    /** Next page of one column (endless scroll). */
    public function cards(): never
    {
        $this->authorize('crm_opportunities', 'view');
        $stages = Stages::availableFor($this->user());
        $stage  = (string) Request::input('stage');
        if (! in_array($stage, $stages, true)) {
            json_response(['html' => '', 'more' => false]);
        }
        $rows = $this->rows($stage, max(1, (int) Request::input('page', 1)));

        json_response(['html' => $this->html(array_slice($rows, 0, self::PER_COLUMN), $stages), 'more' => count($rows) > self::PER_COLUMN]);
    }

    /** Drag and drop (or the card's "Move to" menu). */
    public function move(): never
    {
        $this->authorize('crm_opportunities', 'edit');
        $u     = $this->user();
        $ctl   = new OpportunityController();
        $id    = (int) Request::input('id');
        $stage = (string) Request::input('stage');
        $note  = trim((string) Request::input('note', ''));
        $row   = $ctl->openRecord($id);

        if ((int) $row['created_by'] !== $u->id) {
            json_response(['message' => __('Only :name, who created this opportunity, can move it.', ['name' => $row['creator_name'] ?? __('its creator')])], 403);
        }
        if ($stage === $row['opportunity_stage']) {
            json_response(['message' => __('It is already in this stage.')], 422);
        }
        try {
            $ctl->checkMove($stage, $note);
        } catch (ValidationException $e) {
            json_response(['message' => (string) reset($e->errors)], 422);
        }
        $ctl->changeStage($row, $stage, $note);

        $stages = Stages::availableFor($u);
        $fresh  = $this->rows($stage, 1, $id);
        json_response(['ok' => true, 'stats' => $this->stats($stages), 'html' => $fresh ? $this->html([$fresh[0]], $stages) : '', 'message' => __('Moved to :stage.', ['stage' => Catalog::stageLabel($stage)])]);
    }
}

