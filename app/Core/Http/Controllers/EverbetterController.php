<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Everbetter;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\ValidationException;

/**
 * User Management → Everbetter: my improvement projects (as owner or member), their presentations to Management,
 * and the "Present" request (Project Initiation, Closure or Extend time) for a quarter's presentation day.
 */
class EverbetterController extends Controller
{
    public function index(): string
    {
        $this->authorize('everbetter', 'view');
        $u = $this->user();
        [$y, $q] = Everbetter::quarterOf();

        return view('everbetter/index', ['title' => __('Everbetter'), 'projects' => Everbetter::projects($u, [], true),
            'session' => Everbetter::session($y, $q), 'next' => Everbetter::upcomingSessions()[0] ?? null]);
    }

    private function people(): array
    {
        return array_map(fn ($r) => ['label' => $r['name'], 'sub' => $r['department'] ?? '', 'img' => $r['avatar'] ? upload_url($r['avatar']) : null],
            array_column(DB::select('SELECT u.id, u.name, u.avatar, d.name AS department FROM users u LEFT JOIN departments d ON d.id = u.department_id
                                     WHERE u.deleted_at IS NULL AND u.is_active ORDER BY u.name'), null, 'id'));
    }

    public function create(): string
    {
        $this->authorize('everbetter', 'create');

        return view('everbetter/form', ['title' => __('New Everbetter project'), 'p' => null, 'people' => $this->people(), 'memberIds' => [$this->user()->id]]);
    }

    private function formData(): array
    {
        $d = ['title' => trim((string) Request::input('title')), 'description' => trim((string) Request::input('description')) ?: null,
            'start_date' => (string) Request::input('start_date'), 'end_date' => (string) Request::input('end_date')];
        $err = [];
        if ($d['title'] === '' || mb_strlen($d['title']) > 200) { $err['title'] = __('Write the project title (up to 200 characters).'); }
        foreach (['start_date', 'end_date'] as $k) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d[$k]) || ! strtotime($d[$k])) { $err[$k] = __('Choose a date.'); }
        }
        if (! $err && $d['end_date'] < $d['start_date']) { $err['end_date'] = __('The end date cannot be before the start date.'); }
        if ($err) { throw new ValidationException($err); }

        return $d;
    }

    public function store(): never
    {
        $this->authorize('everbetter', 'create');
        $u = $this->user();
        $d = $this->formData();
        $id = (int) DB::insert('everbetter_projects', $d + ['owner_id' => $u->id, 'owner_name' => $u->name, 'department_id' => $u->department_id]);
        Everbetter::setMembers($id, array_merge([$u->id], (array) Request::input('members', [])));
        Activity::log('created', 'everbetter', $id, $d['title'], ['Created the Everbetter project ":label"', ['label' => $d['title']]], [], null, notify: false);
        Session::flash('success', __('Project created. Ask to present it when you are ready.'));
        redirect('/everbetter/'.$id);
    }

    private function project(int $id): array
    {
        return Everbetter::find($this->user(), $id) ?? abort(404, __('This project is not available.'));
    }

    public function show(int $id): string
    {
        $this->authorize('everbetter', 'view');
        $u = $this->user();
        $p = $this->project($id);

        return view('everbetter/show', ['title' => $p['title'], 'p' => $p, 'presentations' => Everbetter::presentations($id), 'open' => Everbetter::open($id),
            'sessions' => Everbetter::upcomingSessions(), 'canEdit' => Everbetter::canEdit($u, $p), 'allowed' => Everbetter::allowedKinds($p)]);
    }

    public function edit(int $id): string
    {
        $this->authorize('everbetter', 'edit');
        $p = $this->project($id);
        Everbetter::canEdit($this->user(), $p) || abort(403);

        return view('everbetter/form', ['title' => __('Edit project'), 'p' => $p, 'people' => $this->people(), 'memberIds' => array_map('intval', array_column($p['members'], 'id'))]);
    }

    public function update(int $id): never
    {
        $this->authorize('everbetter', 'edit');
        $p = $this->project($id);
        Everbetter::canEdit($this->user(), $p) || abort(403);
        $d = $this->formData();
        if ($p['status'] !== 'draft' && $p['status'] !== 'rejected') {   // approved: the dates change only through Extend time
            unset($d['start_date'], $d['end_date']);
        }
        DB::update('everbetter_projects', $d + ['updated_at' => now()], ['id' => $id]);
        Everbetter::setMembers($id, array_merge([(int) $p['owner_id']], (array) Request::input('members', [])));
        Activity::log('updated', 'everbetter', $id, $d['title'], ['Edited the Everbetter project ":label"', ['label' => $d['title']]], [], null, notify: false);
        Session::flash('success', __('Project saved.'));
        redirect('/everbetter/'.$id);
    }

    public function present(int $id): never
    {
        $this->authorize('everbetter', 'view');
        $u = $this->user();
        $p = $this->project($id);
        Everbetter::canEdit($u, $p) || abort(403);
        Everbetter::request($u, $p, (int) Request::input('session_id'), (string) Request::input('kind'), Request::input('new_end_date') ?: null, (string) Request::input('note', ''));
        Session::flash('success', __('Request sent. Management has been told and will accept it.'));
        redirect('/everbetter/'.$id);
    }

    public function withdraw(int $id, int $rid): never
    {
        $this->authorize('everbetter', 'view');
        $u = $this->user();
        $p = $this->project($id);
        Everbetter::canEdit($u, $p) || abort(403);
        $x = Everbetter::presentation($rid);
        if (! $x || (int) $x['project_id'] !== $id) { abort(404); }
        Everbetter::withdraw($u, $x);
        Session::flash('success', __('Request withdrawn.'));
        redirect('/everbetter/'.$id);
    }
}
