<?php
declare(strict_types=1);

namespace App\Modules\Management\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Everbetter;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\ValidationException;

/**
 * Management → Everbetter: who presents on this quarter's day, every request (acknowledge, then record the result after
 * the presentation), all projects, and the presentation day of each quarter.
 */
class EverbetterController extends Controller
{
    public function index(): string
    {
        $this->authorize('management', 'view');
        $u = $this->user();
        [$y, $q] = Everbetter::quarterOf();
        $year = (int) Request::query('year', $y);
        $year = $year >= 2020 && $year <= 2100 ? $year : $y;
        $status = (string) Request::query('status', 'open');
        $session = Everbetter::session($y, $q);

        $w = ["p.deleted_at IS NULL"]; $pp = [];
        if ($status === 'open') { $w[] = "x.status <> 'withdrawn' AND x.result IS NULL"; }
        elseif ($status === 'submitted') { $w[] = "x.status = 'submitted'"; }
        elseif ($status === 'decided') { $w[] = 'x.result IS NOT NULL'; }
        if ($sid = (int) Request::query('session', 0)) { $w[] = 'x.session_id = ?'; $pp[] = $sid; }
        $requests = DB::select('SELECT x.*, s.present_on, s.year, s.quarter, p.title, p.owner_name, p.start_date, p.end_date, p.status AS project_status, d.name AS department_name, u.avatar
            FROM everbetter_presentations x JOIN everbetter_sessions s ON s.id = x.session_id JOIN everbetter_projects p ON p.id = x.project_id
            LEFT JOIN departments d ON d.id = p.department_id LEFT JOIN users u ON u.id = x.requested_by
            WHERE '.implode(' AND ', $w).' ORDER BY s.present_on, x.requested_at LIMIT 300', $pp);
        $thisQuarter = $session ? DB::select("SELECT x.*, p.title, p.owner_name, u.avatar FROM everbetter_presentations x JOIN everbetter_projects p ON p.id = x.project_id
            LEFT JOIN users u ON u.id = x.requested_by WHERE x.session_id = ? AND x.status <> 'withdrawn' AND p.deleted_at IS NULL ORDER BY x.kind, x.requested_at", [$session['id']]) : [];

        return view('management::everbetter', [
            'title' => __('Everbetter'), 'session' => $session, 'y' => $y, 'q' => $q, 'thisQuarter' => $thisQuarter, 'requests' => $requests, 'status' => $status,
            'projects' => Everbetter::projects($u, ['q' => Request::query('q', ''), 'status' => Request::query('pstatus', '')]),
            'year' => $year, 'sessions' => array_column(DB::select('SELECT * FROM everbetter_sessions WHERE year = ?', [$year]), null, 'quarter'),
            'allSessions' => array_column(DB::select("SELECT id, to_char(present_on, 'DD Mon YYYY') || ' (Q' || quarter || '/' || year || ')' AS label FROM everbetter_sessions ORDER BY present_on DESC LIMIT 12"), 'label', 'id'),
            'counts' => DB::first("SELECT count(*) FILTER (WHERE x.status = 'submitted') AS waiting, count(*) FILTER (WHERE x.status = 'acknowledged' AND x.result IS NULL) AS accepted
                FROM everbetter_presentations x JOIN everbetter_projects p ON p.id = x.project_id WHERE p.deleted_at IS NULL"),
        ]);
    }

    public function acknowledge(int $id): never
    {
        $this->authorize('management', 'view');
        $x = Everbetter::acknowledge($this->user(), $id);
        Session::flash('success', __('Accepted. :name has been told.', ['name' => $x['requested_name']]));
        back();
    }

    public function decide(int $id): never
    {
        $this->authorize('management', 'view');
        $x = Everbetter::decide($this->user(), $id, (string) Request::input('result'), (string) Request::input('note', ''));
        Session::flash('success', __('Result saved for ":title".', ['title' => $x['title']]));
        back();
    }

    /** The presentation day of each quarter of one year. */
    public function sessions(): never
    {
        $this->authorize('management', 'view');
        $year = (int) Request::input('year');
        if ($year < 2020 || $year > 2100) {
            throw new ValidationException(['year' => __('Choose a year.')]);
        }
        $saved = 0;
        foreach ([1, 2, 3, 4] as $q) {
            $day = trim((string) Request::input('day_'.$q, ''));
            $place = mb_substr(trim((string) Request::input('place_'.$q, '')), 0, 200) ?: null;
            $old = Everbetter::session($year, $q);
            if ($day === '') {
                if ($old && ! DB::scalar('SELECT 1 FROM everbetter_presentations WHERE session_id = ? LIMIT 1', [$old['id']])) {
                    DB::exec('DELETE FROM everbetter_sessions WHERE id = ?', [$old['id']]);
                }
                continue;
            }
            $from = sprintf('%04d-%02d-01', $year, ($q - 1) * 3 + 1);
            $to = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $q * 3)));
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $day < $from || $day > $to) {
                throw new ValidationException(['day_'.$q => __('The Q:q day must fall between :from and :to.', ['q' => $q, 'from' => format_date($from), 'to' => format_date($to)])]);
            }
            DB::exec('INSERT INTO everbetter_sessions (year, quarter, present_on, place, created_by) VALUES (?, ?, ?, ?, ?)
                      ON CONFLICT (year, quarter) DO UPDATE SET present_on = EXCLUDED.present_on, place = EXCLUDED.place, updated_at = now()', [$year, $q, $day, $place, $this->user()->id]);
            $saved++;
        }
        Activity::log('updated', 'everbetter', null, 'Everbetter', ['Set the Everbetter presentation days of :year', ['year' => $year]], [], null, notify: false, module: 'management');
        Session::flash('success', __('Presentation days of :year saved.', ['year' => $year]));
        redirect('/management/everbetter?year='.$year.'#days');
    }
}
