<?php
declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Notifier;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\ValidationException;
use App\Modules\CRM\Support\Access;
use App\Modules\CRM\Support\Catalog;
use App\Modules\CRM\Support\Reassign;
use App\Modules\CRM\Support\StageReview;

/**
 * CRM → Manager → Sales control, for a BU manager (own department) or an admin (everybody). Two views:
 *  - By person: pick someone, see every record they have, hand them to somebody else (with a reason) or change many at once.
 *  - By stage: how many opportunities sit in each stage within a date range; pick a stage, tick the stale ones and move them on.
 * Nothing is lost: the record stays as it is and the previous owner / stage is kept in its history.
 */
class TeamController extends Controller
{
    private function gate(string $action = 'view'): void
    {
        $this->authorize('crm_reassign', $action);
    }

    /** People the signed-in person can look at: everybody for whole-company roles, the own department otherwise. */
    private function members(): array
    {
        $u = $this->user();

        return array_filter(Access::everyone(), fn ($p) => Access::seesAll($u) || ($u->department_id && (int) $p['department_id'] === $u->department_id));
    }

    /** The old address of this screen. */
    public function moved(): never
    {
        $q = http_build_query(array_filter(['user' => Request::query('user'), 'type' => Request::query('type')]));
        redirect('/crm/control'.($q !== '' ? '?'.$q : ''));
    }

    public function index(): string
    {
        $this->gate();
        $u       = $this->user();
        $view    = Request::query('view') === 'stage' ? 'stage' : 'person';
        $members = $this->members();
        $types   = Reassign::types();
        $from    = (int) Request::query('user', 0);
        $type    = (string) Request::query('type', 'opportunity');
        $type    = isset($types[$type]) ? $type : 'opportunity';
        $open    = Request::query('all') === null;
        $from    = isset($members[$from]) ? $from : 0;

        $options = [];
        foreach ($members as $id => $p) {
            $options[$id] = $p['name'].($p['department'] && Access::seesAll($u) ? ' · '.$p['department'] : '').($p['active'] ? '' : ' ('.__('inactive').')');
        }
        $targets = Access::assignable($u);
        unset($targets[$from]);

        // By stage
        $f = ['department' => Access::seesAll($u) ? (int) Request::query('department', 0) : 0, 'owner' => (int) Request::query('owner', 0),
            'from' => (string) Request::query('from', ''), 'to' => (string) Request::query('to', ''), 'date' => (string) Request::query('date', 'since')];
        $f['owner'] = isset($members[$f['owner']]) ? $f['owner'] : 0;
        $f['date']  = isset(StageReview::dates()[$f['date']]) ? $f['date'] : 'since';
        $stage      = (string) Request::query('stage', 'QUALIFICATION');
        $stage      = isset(Catalog::STAGES[$stage]) ? $stage : 'QUALIFICATION';
        $people     = Access::everyone();
        $openCount  = (int) DB::scalar('SELECT count(*) FROM opportunities t WHERE t.deleted_at IS NULL AND t.opportunity_stage IN (\''.implode("','", Catalog::OPEN).'\')'
            .(Access::seesAll($u) ? '' : ' AND t.department_id = '.(int) $u->department_id), [], 'crm');

        return view('crm/team', [
            'title' => __('Sales control'), 'view' => $view, 'f' => $f, 'stage' => $stage, 'openCount' => $openCount,
            'stageCounts' => $view === 'stage' ? StageReview::counts($u, $f) : [], 'stageRows' => $view === 'stage' ? StageReview::rows($u, $stage, $f) : [],
            'people' => array_map(fn ($p) => $p['name'], $people), 'deps' => Access::departments(), 'members' => $options, 'from' => $from, 'person' => $members[$from] ?? null, 'type' => $type, 'types' => $types, 'openOnly' => $open,
            'counts' => $from ? Reassign::counts($u, $from) : [], 'rows' => $from ? Reassign::recordsOf($u, $type, $from, $open) : [],
            'targets' => $targets, 'canEdit' => can('crm_reassign', 'edit'), 'wholeCompany' => Access::seesAll($u),
        ]);
    }

    private function back(int $from, string $type): never
    {
        $ret = (string) Request::input('return', '');             // the By stage view sends its own address (filters kept)
        redirect(str_starts_with($ret, '/crm/control?') ? $ret : '/crm/control?user='.$from.'&type='.$type);
    }

    /** A reason is required whenever records change hands. */
    private function reason(string $field = 'note'): string
    {
        $note = trim((string) Request::input($field));
        if ($note === '') {
            throw new ValidationException([$field => __('Write the reason for moving them.')]);
        }

        return mb_substr($note, 0, 250);
    }

    /** Hand the ticked records to another person. */
    public function reassign(): never
    {
        $this->gate('edit');
        $type = (string) Request::input('type');
        $ids  = array_map('intval', (array) Request::input('ids', []));
        $to   = (int) Request::input('to');
        $from = (int) Request::input('from');
        if (! isset(Reassign::types()[$type]) || ! $to) {
            throw new ValidationException(['to' => __('Choose who should get them.')]);
        }
        if (! $ids) {
            Session::flash('error', __('Tick at least one record first.'));
            $this->back($from, $type);
        }
        [$moved, $skipped] = Reassign::apply($this->user(), $type, $ids, $to, $from ?: null, $this->reason(), (bool) Request::input('move_department'));
        Session::flash('success', __(':n moved.', ['n' => $moved]).($skipped ? ' '.__(':n skipped (not yours to change, or already theirs).', ['n' => $skipped]) : ''));
        $this->back($from, $type);
    }

    /** Somebody left: everything they have (all types) goes to one person. */
    public function handover(): never
    {
        $this->gate('edit');
        $from = (int) Request::input('from');
        $to   = (int) Request::input('to');
        if (! $from || ! $to || $from === $to) {
            throw new ValidationException(['to' => __('Choose who should get them.')]);
        }
        $openOnly = ! Request::input('include_closed');
        $note     = $this->reason();
        $moved    = $skipped = 0;
        foreach (array_keys(Reassign::types()) as $type) {
            $ids = array_column(Reassign::recordsOf($this->user(), $type, $from, $openOnly, 5000), 'id');
            if ($ids) {
                [$m, $s] = Reassign::apply($this->user(), $type, $ids, $to, $from, $note, (bool) Request::input('move_department'));
                $moved += $m; $skipped += $s;
            }
        }
        Session::flash('success', __(':n moved.', ['n' => $moved]).($skipped ? ' '.__(':n skipped (not yours to change, or already theirs).', ['n' => $skipped]) : ''));
        $this->back($from, (string) Request::input('type', 'opportunity'));
    }

    /** Move the ticked records to one stage / status, e.g. close every stale opportunity. */
    public function status(): never
    {
        $this->gate('edit');
        $u      = $this->user();
        $type   = (string) Request::input('type');
        $ids    = array_unique(array_map('intval', (array) Request::input('ids', [])));
        $status = (string) Request::input('status');
        $note   = trim((string) Request::input('status_note'));
        $from   = (int) Request::input('from');
        $t      = Reassign::types()[$type] ?? null;
        if (! $t || ! $t['status']) {
            abort(404);
        }
        if (! isset($t['statuses'][$status])) {
            throw new ValidationException(['status' => __('Pick a status.')]);
        }
        if (! $ids) {
            Session::flash('error', __('Tick at least one record first.'));
            $this->back($from, $type);
        }
        $opp = null;
        if ($type === 'opportunity') {
            $opp = new OpportunityController();
            $opp->checkMove($status, $note);          // the stage must be allowed for this department, and a reason is given when needed
        }
        $col = substr($t['status'], 2);
        $moved = $skipped = 0;
        $byOwner = [];
        foreach ($ids as $id) {
            $row = DB::first("SELECT * FROM {$t['table']} WHERE id = ? AND deleted_at IS NULL", [$id], 'crm');
            if (! $row || ! Reassign::inScope($u, $row) || (string) $row[$col] === $status) {
                $skipped++;
                continue;
            }
            $label = (string) $row[$t['label']];
            if ($opp) {
                $opp->changeStage($row, $status, $note, false);
            } else {
                DB::exec("UPDATE {$t['table']} SET {$col} = ?, updated_by = ?, updated_at = now() WHERE id = ?", [$status, $u->id, $id], 'crm');
                Activity::log('updated', $type, $id, $label, ['Changed :type ":label" to :status', ['type' => $type, 'label' => $label, 'status' => $t['statuses'][$status]]],
                    ['changes' => ['status' => ['from' => $t['statuses'][$row[$col]] ?? $row[$col], 'to' => $t['statuses'][$status]]]], (int) $row['owner_id'], notify: false, module: 'crm');
            }
            $moved++;
            if (! empty($row['owner_id']) && (int) $row['owner_id'] !== $u->id) {
                $byOwner[(int) $row['owner_id']] = ($byOwner[(int) $row['owner_id']] ?? 0) + 1;
            }
        }
        foreach ($byOwner as $owner => $n) {                    // one message per owner, not one per record
            Notifier::deliver('crm', 'updated', $owner,
                ['key' => ':name changed :n of your :type to :status', 'params' => ['name' => $u->name, 'n' => $n, 'type' => $t['name'], 'status' => $t['statuses'][$status]]],
                ['key' => ':note', 'params' => ['note' => $note !== '' ? $note : '—']], url('/crm/'.Access::ENTITIES[$type]['path'].'?scope=mine'), $u->id);
        }
        Session::flash('success', __(':n changed.', ['n' => $moved]).($skipped ? ' '.__(':n skipped.', ['n' => $skipped]) : ''));
        $this->back($from, $type);
    }

    /** One record, from its own page: change its owner. */
    public function owner(string $kind, int $id): never
    {
        $this->gate('edit');
        $type = Access::typeForPath($kind) ?? abort(404);
        $to   = (int) Request::input('to');
        $row  = DB::first('SELECT * FROM '.Access::ENTITIES[$type]['table'].' WHERE id = ? AND deleted_at IS NULL', [$id], 'crm') ?? abort(404);
        [$moved] = Reassign::apply($this->user(), $type, [$id], $to, null, trim((string) Request::input('note')) ?: null, (bool) Request::input('move_department'));
        Session::flash($moved ? 'success' : 'error', $moved ? __('Owner changed. The previous owner is kept in the history.') : __('Nothing changed.'));
        redirect('/crm/'.Access::ENTITIES[$type]['path'].'/'.$id);
    }
}
