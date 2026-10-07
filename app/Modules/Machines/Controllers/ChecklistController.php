<?php
declare(strict_types=1);

namespace App\Modules\Machines\Controllers;

use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Notifier;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\Upload;
use App\Core\Support\ValidationException;
use App\Modules\Machines\Support\Checklists;
use App\Modules\Machines\Support\Mx;

/**
 * Checklists. Anybody who uses a machine scans its QR code and does the GENERAL check.
 * The responsible person also does a periodic re-check (weekly or monthly) that goes to the supervisor and then the manager for approval.
 */
class ChecklistController extends MachinesController
{
    protected string $resource = 'machines_checklists';
    protected string $table = 'checklist_record';
    protected string $type = 'machine_checklist';
    protected string $singular = 'checklist';
    protected string $plural = 'checklists';
    protected string $base = '/machines/checklists';
    protected string $icon = 'check';
    protected bool $softDelete = false;
    protected string $orderBy = 't.created_at DESC, t.id DESC';

    protected function select(): string
    {
        return 'SELECT t.*, m.id AS machine_id, m.image AS machine_image FROM checklist_record t LEFT JOIN machine m ON m.machine_code = t.machine_code';
    }

    /** Records of machines the person sees, plus everything they did or must approve. */
    protected function scopeSql(): array
    {
        $u = $this->user();
        if ($u->scope() === 'all') {
            return ['sql' => 'TRUE', 'params' => []];
        }
        $s = Mx::scope($u, 'm');

        return ['sql' => "(t.created_by = ? OR t.supervisor = ? OR t.manager = ? OR (m.id IS NOT NULL AND {$s['sql']}))", 'params' => [$u->id, $u->id, $u->id, ...$s['params']]];
    }

    protected function hydrate(array $rows): array { return $this->withPeople($rows, ['supervisor', 'manager']); }

    protected function label(array $row): string { return $row['machine_code'].' · '.format_date($row['created_at'], 'd M Y H:i'); }

    protected function owner(array $row): ?int { return $row['created_by'] ? (int) $row['created_by'] : null; }

    protected function canModify(array $row, string $action): bool { return $this->user()->isAdmin(); }

    protected function searchable(): array { return ['t.machine_code', 't.machine_name', 't.user_name', 't.machine_note']; }

    protected function filters(): array
    {
        $me = $this->user()->id;

        return [
            'view'   => ['label' => __('All checklists'), 'options' => ['mine' => __('Done by me'), 'approve' => __('Waiting for my approval')],
                         'sql' => "(CASE ? WHEN 'mine' THEN t.created_by = {$me} ELSE ((t.checklist_status = 'PENDING SUPERVISOR' AND t.supervisor = {$me}) OR (t.checklist_status = 'PENDING MANAGER' AND t.manager = {$me})) END)"],
            'status' => ['label' => __('All statuses'), 'options' => $this->statuses(), 'column' => 't.checklist_status'],
            'kind'   => ['label' => __('All kinds'), 'options' => ['general' => __('General check'), 'recheck' => __('Re-check (approval)'), 'maintenance' => __('Maintenance')],
                         'sql' => "(CASE ? WHEN 'maintenance' THEN t.check_type = 'MAINTENANCE' WHEN 'recheck' THEN t.recheck ELSE (t.check_type = 'GENERAL' AND NOT t.recheck) END)"],
            'mstat'  => ['label' => __('Any machine status'), 'options' => array_map(fn ($l) => __($l), Mx::STATUSES), 'column' => 't.machine_status'],
            'from'   => ['label' => __('Any date'), 'options' => ['today' => __('Today'), 'week' => __('Last 7 days'), 'month' => __('Last 30 days')],
                         'sql' => "t.created_at >= (CASE ? WHEN 'today' THEN CURRENT_DATE WHEN 'week' THEN CURRENT_DATE - 6 ELSE CURRENT_DATE - 29 END)"],
        ];
    }

    private function statuses(): array
    {
        return ['COMPLETED' => __('Completed'), 'PENDING SUPERVISOR' => __('Pending supervisor'), 'PENDING MANAGER' => __('Pending manager'),
                'PENDING SUPERVISOR-OVERDUE' => __('Supervisor overdue'), 'PENDING MANAGER-OVERDUE' => __('Manager overdue')];
    }

    protected function meta(): array { return parent::meta() + ['readonly' => true]; }     // no generic Add / Import: checks are made by scanning

    protected function fields(?array $row): array { return []; }      // checks are not typed into a generic form

    protected function columns(): array
    {
        return [
            'date'    => ['label' => __('Date'), 'primary' => true, 'sort' => 't.created_at', 'render' => fn ($r) => '<span class="tabular-nums">'.e(format_date($r['created_at'], 'd M Y')).'</span><span class="block text-xs text-steel">'.e(format_date($r['created_at'], 'H:i')).'</span>'],
            'machine' => ['label' => __('Machine'), 'sort' => 't.machine_code', 'render' => fn ($r) => '<span class="font-medium tabular-nums">'.e($r['machine_code']).'</span><span class="block text-xs text-steel">'.e($r['machine_name']).'</span>'],
            'kind'    => ['label' => __('Kind'), 'render' => fn ($r) => $r['check_type'] === 'MAINTENANCE' ? Mx::pill(__('Maintenance'), 'info') : ($r['recheck'] ? Mx::pill(__('Re-check'), 'warn') : Mx::pill(__('General'), 'neutral'))],
            'by'      => ['label' => __('Checked by'), 'sort' => 't.user_name', 'render' => fn ($r) => e($r['user_name'] ?? '—')],
            'mstatus' => ['label' => __('Machine'), 'render' => fn ($r) => Mx::pill($r['machine_status'])],
            'status'  => ['label' => __('Approval'), 'sort' => 't.checklist_status', 'render' => fn ($r) => Mx::pill($r['checklist_status']).(str_starts_with((string) $r['checklist_status'], 'PENDING') ? '<span class="mt-0.5 block text-xs text-steel">'.e(__('waiting for :n', ['n' => ($r['checklist_status'] === 'PENDING SUPERVISOR' ? ($r['supervisor_name'] ?? '') : ($r['manager_name'] ?? ''))])).'</span>' : '')],
        ];
    }

    protected function headerActions(): string
    {
        return can($this->resource, 'create') ? '<a href="'.e(url('/machines/scan')).'" class="btn-dark">'.icon('qr', 'size-4').' '.e(__('Scan QR')).'</a>' : '';
    }

    protected function topExtra(): string
    {
        $me = $this->user()->id;
        $n  = (int) DB::scalar("SELECT count(*) FROM checklist_record WHERE (checklist_status = 'PENDING SUPERVISOR' AND supervisor = ?) OR (checklist_status = 'PENDING MANAGER' AND manager = ?)", [$me, $me], 'machines');
        if (! $n) {
            return '';
        }

        return '<a href="'.e(url('/machines/checklists', ['view' => 'approve'])).'" class="mt-4 flex items-center gap-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-600/20 hover:bg-amber-100">'
            .icon('clock', 'size-5').'<span><strong>'.e(__(':n waiting for your approval', ['n' => $n])).'</strong> · '.e(__('Open them')).'</span></a>';
    }

    public function export(): never
    {
        $this->authorize($this->resource, 'export');
        $rows = $this->query(false)['rows'];
        Activity::log('export', $this->type, null, 'Checklists', ['Exported :count :type', ['count' => count($rows), 'type' => $this->plural]], ['count' => count($rows)], module: 'machines');
        \App\Core\Support\Csv::download('checklists-'.date('Ymd-His').'.csv', ['id', 'date', 'machine_code', 'machine_name', 'kind', 'checked_by', 'machine_status', 'approval_status', 'supervisor', 'manager', 'note'],
            array_map(fn ($r) => [$r['id'], $r['created_at'], $r['machine_code'], $r['machine_name'], $r['check_type'] === 'MAINTENANCE' ? 'maintenance' : ($r['recheck'] ? 'recheck' : 'general'), $r['user_name'], $r['machine_status'], $r['checklist_status'], $r['supervisor_name'] ?? '', $r['manager_name'] ?? '', $r['machine_note']], $rows));
    }

    /* ----------------------------------------------------------------- do a check */

    /** Pick a machine (search or scan) or, with ?machine=CODE, the check form. */
    public function create(): string
    {
        $this->authorize($this->resource, 'create');
        $code = trim((string) Request::query('machine', ''));
        if ($code === '') {
            redirect('/machines/scan');
        }
        $m = Mx::machine($code);
        if (! $m || ! Mx::isActive($m['machine_status'])) {
            Session::flash('error', __('This machine was not found or is no longer in use.'));
            redirect('/machines/scan');
        }
        $plan = Checklists::plan($m, $this->user());

        return view('machines/check-new', ['title' => __('Check :code', ['code' => $m['machine_code']]), 'm' => $m, 'plan' => $plan, 'people' => Mx::names([$m['responsible_person_id'], $m['supervisor_id'], $m['manager_id']])]);
    }

    public function store(): never
    {
        $this->authorize($this->resource, 'create');
        $u = $this->user();
        $m = Mx::machine((string) Request::input('machine_code')) ?? throw new ValidationException(['machine_code' => __('Machine not found.')]);
        if (! Mx::isActive($m['machine_status'])) {
            throw new ValidationException(['machine_code' => __('This machine is no longer in use.')]);
        }
        $status = (string) Request::input('machine_status');
        if (! Mx::isActive($status)) {
            throw new ValidationException(['machine_status' => __('Choose the machine status: operational or under maintenance.')]);
        }
        $plan    = Checklists::plan($m, $u);
        $answers = Checklists::answers($plan['items'], (array) Request::input('answers', []));

        $image = ($file = Request::file('image')) ? Upload::image($file, 'machines') : null;
        $now   = now();
        $rec = [
            'check_type' => 'GENERAL', 'recheck' => $plan['recheck'], 'machine_code' => $m['machine_code'], 'machine_name' => $m['machine_name'], 'machine_status' => $status,
            'machine_checklist' => json_encode($answers, JSON_UNESCAPED_UNICODE), 'machine_note' => trim((string) Request::input('machine_note')) ?: null, 'image' => $image,
            'user_id' => $u->email, 'user_name' => $u->name, 'created_by' => $u->id, 'updated_by' => $u->id,
            'supervisor' => $m['supervisor_id'], 'manager' => $m['manager_id'],
            'checklist_status' => $plan['recheck'] ? ($m['supervisor_id'] ? 'PENDING SUPERVISOR' : 'PENDING MANAGER') : 'COMPLETED',
            'reason_not_checked' => null, 'job_detail' => null,
        ];
        $id = (int) DB::transaction(function () use ($rec, $m, $plan, $status, $answers) {
            $id = (int) DB::insert('checklist_record', $rec, 'machines');
            if ($plan['recheck']) {
                $ids = array_values(array_filter(array_map(fn ($a) => $a['reset_time'] !== Mx::GENERAL ? (int) $a['id'] : 0, $answers)));
                if ($ids) {
                    DB::exec('UPDATE machine_checklist SET check_status = TRUE, updated_at = now() WHERE id IN ('.implode(',', $ids).')', [], 'machines');
                }
                DB::exec('UPDATE machine SET check_status = ?, machine_status = ?, updated_at = now() WHERE id = ?', [$rec['checklist_status'], $status, $m['id']], 'machines');
            } else {
                DB::exec('UPDATE machine SET machine_status = ?, updated_at = now() WHERE id = ?', [$status, $m['id']], 'machines');
            }

            return $id;
        }, 'machines');

        Activity::log('created', $this->type, $id, $m['machine_code'], ['Checked machine :code', ['code' => $m['machine_code']]], ['_url' => url('/machines/checklists/'.$id), 'attributes' => ['machine_status' => $status, 'kind' => $plan['recheck'] ? 'recheck' : 'general']],
            $m['responsible_person_id'] ? (int) $m['responsible_person_id'] : null, module: 'machines');

        $bad = array_filter($answers, fn ($a) => ($a['answer'] ?? '') === 'NG');
        Checklists::notifyAfterCheck($m, $rec, $id, count($bad), $u);

        Session::flash('success', $plan['recheck'] ? __('Saved. It is now waiting for approval.') : __('Checklist saved. Thank you!'));
        if (Request::isJson()) {
            json_response(['ok' => true, 'id' => $id, 'url' => url('/machines/checklists/'.$id)]);
        }
        redirect('/machines/checklists/'.$id);
    }

    /* ------------------------------------------------------------------ detail */

    public function show(int $id): string
    {
        $this->authorize($this->resource, 'view');
        $r = $this->find($id);
        $u = $this->user();
        $answers = json_decode((string) $r['machine_checklist'], true) ?: [];

        return view('machines/check-show', [
            'title' => $this->label($r), 'r' => $r, 'answers' => $answers, 'c' => $this->meta(),
            'machine' => Mx::machine($r['machine_code']),
            'canApprove' => Checklists::canApprove($r, $u), 'canDelete' => can($this->resource, 'delete') && $this->canModify($r, 'delete'),
            'chain' => Checklists::chain($r), 'by' => Mx::names([$r['created_by']])[(int) $r['created_by']] ?? $r['user_name'],
        ]);
    }

    /** Supervisor, then manager, approves a re-check. */
    public function approve(int $id): never
    {
        $this->authorize($this->resource, 'view');
        $r = $this->find($id);
        $u = $this->user();
        if (! Checklists::canApprove($r, $u)) {
            back('error', __('This checklist is not waiting for you.'));
        }
        $note = trim((string) Request::input('note'));
        DB::transaction(function () use ($r, $u, $note) {
            if ($r['checklist_status'] === 'PENDING SUPERVISOR') {
                $next = $r['manager'] ? 'PENDING MANAGER' : 'COMPLETED';
                DB::exec('UPDATE checklist_record SET checklist_status = ?, date_supervisor_checked = now(), updated_by = ?, updated_at = now(), reason_not_checked = COALESCE(NULLIF(?, \'\'), reason_not_checked) WHERE id = ?', [$next, $u->id, $note, $r['id']], 'machines');
            } else {
                $next = 'COMPLETED';
                DB::exec('UPDATE checklist_record SET checklist_status = ?, date_manager_checked = now(), updated_by = ?, updated_at = now(), reason_not_checked = COALESCE(NULLIF(?, \'\'), reason_not_checked) WHERE id = ?', [$next, $u->id, $note, $r['id']], 'machines');
            }
            // the machine follows the latest re-check while it is still waiting for approval
            DB::exec("UPDATE machine SET check_status = ?, updated_at = now() WHERE machine_code = ? AND check_status IN ('PENDING SUPERVISOR', 'PENDING MANAGER') AND NOT EXISTS (SELECT 1 FROM checklist_record c WHERE c.machine_code = machine.machine_code AND c.recheck AND c.id > ?)", [$next, $r['machine_code'], $r['id']], 'machines');

            return $next;
        }, 'machines');
        $after = DB::first('SELECT checklist_status FROM checklist_record WHERE id = ?', [$id], 'machines');
        Activity::log('updated', $this->type, $id, $r['machine_code'], ['Approved the check of :code', ['code' => $r['machine_code']]], ['changes' => ['status' => ['from' => $r['checklist_status'], 'to' => $after['checklist_status']]], '_url' => url('/machines/checklists/'.$id)],
            $r['created_by'] ? (int) $r['created_by'] : null, module: 'machines');
        Checklists::notifyApproval($r, $after['checklist_status'], $u);

        Session::flash('success', $after['checklist_status'] === 'COMPLETED' ? __('Approved. The checklist is complete.') : __('Approved. It now goes to the manager.'));
        redirect('/machines/checklists/'.$id);
    }
}
