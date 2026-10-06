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

/**
 * "Team": a BU manager (own department) or an admin (everybody) picks a person, sees every record that person
 * has, and hands them to somebody else or moves many of them to a new stage / status in one click.
 * Nothing is lost: the record stays as it is and the previous owner is kept in its history.
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

    public function index(): string
    {
        $this->gate();
        $u       = $this->user();
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

        return view('crm/team', [
            'title' => __('Team'), 'members' => $options, 'from' => $from, 'person' => $members[$from] ?? null, 'type' => $type, 'types' => $types, 'openOnly' => $open,
            'counts' => $from ? Reassign::counts($u, $from) : [], 'rows' => $from ? Reassign::recordsOf($u, $type, $from, $open) : [],
            'targets' => $targets, 'canEdit' => can('crm_reassign', 'edit'), 'wholeCompany' => Access::seesAll($u),
        ]);
    }

    private function back(int $from, string $type): never
    {
        redirect('/crm/team?user='.$from.'&type='.$type);
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
        [$moved, $skipped] = Reassign::apply($this->user(), $type, $ids, $to, $from ?: null, trim((string) Request::input('note')) ?: null, (bool) Request::input('move_department'));
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
        $note     = trim((string) Request::input('note')) ?: __('Handed over');
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
