<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\Activity;
use App\Core\Support\Csv;
use App\Core\Support\DB;
use App\Core\Support\KpiSubmissions as K;
use App\Core\Support\Mailer;
use App\Core\Support\Notifier;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\ValidationException;

/** User Management → KPI submissions: monthly KPI / OKR results, grouped by month, approved by the chosen approver. */
class KpiSubmissionController extends Controller
{
    private const VIEWS = ['all', 'mine', 'approve'];

    /** @return array{year:int, view:string, q:string} */
    private function filters(): array
    {
        $year = (int) Request::query('year', date('Y'));
        $view = (string) Request::query('view', 'all');

        return ['year' => $year >= 2000 && $year <= 2100 ? $year : (int) date('Y'), 'view' => in_array($view, self::VIEWS, true) ? $view : 'all',
            'q' => mb_substr(trim((string) Request::query('q', '')), 0, 100)];
    }

    /** @return list<array> the visible rows of one year, newest month first */
    private function rows(array $f): array
    {
        $u = $this->user();
        $v = K::visibleSql($u);
        $where = ['s.deleted_at IS NULL', 's.period_year = ?', $v['sql']];
        $params = [$f['year'], ...$v['params']];
        if ($f['view'] === 'mine') {
            $where[] = 's.user_id = ?';
            $params[] = $u->id;
        } elseif ($f['view'] === 'approve') {
            $where[] = 's.approver_id = ? AND s.approved_at IS NULL';
            $params[] = $u->id;
        }
        if ($f['q'] !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']).'%';
            $where[] = '(s.task_title ILIKE ? OR su.name ILIKE ? OR s.note ILIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $rows = DB::select('SELECT s.*, su.name AS submitter_name, su.avatar AS submitter_avatar, ap.name AS approver_name, d.name AS department_name
              FROM kpi_submissions s
              JOIN users su ON su.id = s.user_id
         LEFT JOIN users ap ON ap.id = s.approver_id
         LEFT JOIN departments d ON d.id = s.department_id
             WHERE '.implode(' AND ', $where).'
          ORDER BY su.name, s.user_id, s.period_month DESC, s.submitted_at, s.id LIMIT 5000', $params);
        $files = [];
        if ($rows) {
            foreach (DB::select('SELECT id, submission_id, name, size FROM kpi_submission_files WHERE submission_id IN ('.implode(',', array_map('intval', array_column($rows, 'id'))).') ORDER BY id') as $file) {
                $files[(int) $file['submission_id']][] = $file;
            }
        }
        foreach ($rows as &$r) {
            $r['files'] = $files[(int) $r['id']] ?? [];
        }

        return $rows;
    }

    public function index(): string
    {
        $this->authorize('kpi_submissions', 'view');
        $f = $this->filters();
        // Submitted by → month (newest first), like the grouped sheet people used before
        $groups = [];
        foreach ($this->rows($f) as $r) {
            $g = &$groups[(int) $r['user_id']];
            $g ??= ['name' => $r['submitter_name'], 'department' => $r['department_name'], 'months' => []];
            $g['months'][(int) $r['period_month']][] = $r;
            unset($g);
        }
        foreach ($groups as &$g) {
            krsort($g['months']);
        }
        unset($g);
        $years = array_map('intval', array_column(DB::select('SELECT DISTINCT period_year FROM kpi_submissions WHERE deleted_at IS NULL'), 'period_year'));
        $years = array_values(array_unique([...$years, (int) date('Y'), $f['year']]));
        rsort($years);
        $u = $this->user();
        $pending = (int) DB::scalar('SELECT count(*) FROM kpi_submissions WHERE deleted_at IS NULL AND approved_at IS NULL AND approver_id = ?', [$u->id]);

        return view('kpi/index', ['title' => __('KPI submissions'), 'f' => $f, 'groups' => $groups, 'years' => $years, 'pending' => $pending, 'me' => $u]);
    }

    public function create(): string
    {
        $this->authorize('kpi_submissions', 'create');
        $u = $this->user();
        $periods = K::periods();
        // Default: last month until the 7th (that is what is due now), this month after.
        $default = (int) date('j') <= K::DUE_DAY ? $periods[1] : $periods[0];
        $y = (int) Request::query('year', old('year', $default[0]));
        $m = (int) Request::query('month', old('month', $default[1]));
        if (! in_array([$y, $m], $periods, true)) {
            [$y, $m] = $default;
        }
        $approvers = K::approvers($u);
        $manager = DB::scalar('SELECT manager_id FROM users WHERE id = ?', [$u->id]);
        $last = DB::scalar('SELECT approver_id FROM kpi_submissions WHERE user_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', [$u->id]);
        $approver = (string) Request::query('approver', old('approver_id', $last ?: $manager ?: ''));

        return view('kpi/create', ['title' => __('Submit KPIs'), 'year' => $y, 'month' => $m, 'periods' => $periods,
            'tasks' => K::available($u, $y, $m), 'approvers' => $approvers, 'approver' => isset($approvers[(int) $approver]) ? $approver : '',
            'due' => K::dueDate($y, $m), 'now' => K::timeliness($y, $m, new \DateTimeImmutable('today'))]);
    }

    public function store(): never
    {
        $this->authorize('kpi_submissions', 'create');
        $u = $this->user();
        $in = $this->validate([
            'year' => 'required|integer', 'month' => 'required|integer', 'kpi_id' => 'required|integer', 'approver_id' => 'required|integer',
            'finished' => 'required|numeric', 'total' => 'required|numeric', 'note' => 'nullable|max:2000', 'related_link' => 'nullable|max:500',
        ], ['kpi_id' => __('KPI / OKR'), 'approver_id' => __('Approver'), 'finished' => __('Finished'), 'total' => __('Total tasks'), 'related_link' => __('Related link')]);
        $y = (int) $in['year'];
        $m = (int) $in['month'];
        $errors = [];
        if (! in_array([$y, $m], K::periods(), true)) {
            $errors['month'] = __('Choose this month or one of the last eleven.');
        }
        $tasks = K::available($u, $y, $m);
        $task = $tasks[(int) $in['kpi_id']] ?? null;
        if (! $task) {
            $errors['kpi_id'] = __('This KPI is not yours or was already submitted for this month.');
        }
        $approvers = K::approvers($u);
        if (! isset($approvers[(int) $in['approver_id']])) {
            $errors['approver_id'] = __('Choose an approver from the list.');
        }
        foreach (['finished', 'total'] as $k) {
            if ((float) $in[$k] < 0 || (float) $in[$k] > 999999999) {
                $errors[$k] = __('Enter a number from 0 up.');
            }
        }
        $link = trim((string) ($in['related_link'] ?? ''));
        if ($link !== '' && (! filter_var($link, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $link))) {
            $errors['related_link'] = __('Enter a full link starting with https://');
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $files = K::uploads(Request::file('files'));

        $t = K::timeliness($y, $m, new \DateTimeImmutable('today'));
        try {
            $id = (int) DB::transaction(fn () => DB::insert('kpi_submissions', [
                'user_id' => $u->id, 'department_id' => $u->department_id, 'team_id' => $u->team_id,
                'kpi_id' => (int) $in['kpi_id'], 'task_title' => $task['title'], 'task_kind' => $task['kind'], 'criteria' => $task['criteria'],
                'period_year' => $y, 'period_month' => $m, 'approver_id' => (int) $in['approver_id'],
                'finished' => $in['finished'], 'total' => $in['total'], 'note' => trim((string) ($in['note'] ?? '')) ?: null, 'related_link' => $link ?: null,
                'days_late' => $t['days_late'], 'percentage' => $t['percentage'],
            ]));
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') {                // someone (another tab) submitted the same KPI a moment ago
                throw new ValidationException(['kpi_id' => __('This KPI was already submitted for this month.')]);
            }
            throw $e;
        }
        K::store($id, $files);

        $period = format_date(sprintf('%04d-%02d-01', $y, $m), 'F Y');
        Activity::log('created', 'kpi_submission', $id, $task['title'], ['Submitted ":label" for :period', ['label' => $task['title'], 'period' => $period]],
            ['percentage' => $t['percentage']], (int) $in['approver_id'], notify: false);
        $this->tellApprover((int) $in['approver_id'], $id, $task['title'], $period, $in, $t);

        $msg = __('":task" submitted for :period.', ['task' => $task['title'], 'period' => $period]);
        if (Request::input('next') === 'continue') {
            Session::flash('success', $msg);
            redirect('/kpi-submissions/create?'.http_build_query(['year' => $y, 'month' => $m, 'approver' => (int) $in['approver_id']]));
        }
        Session::flash('success', $msg);
        redirect('/kpi-submissions?'.http_build_query(['year' => $y, 'view' => 'mine']).'#u'.$u->id.'-m'.$m);
    }

    /** Bell and e-mail to the approver for every submission. */
    private function tellApprover(int $approverId, int $id, string $title, string $period, array $in, array $t): void
    {
        $u = $this->user();
        $url = url('/kpi-submissions', ['year' => (int) $in['year'], 'view' => 'approve']).'#u'.$u->id.'-m'.(int) $in['month'];
        Notifier::bell($approverId, 'core', 'kpi_submission', __(':name submitted a KPI for :period', ['name' => $u->name, 'period' => $period]), $title, $url, $u->id);
        $a = DB::first('SELECT name, email FROM users WHERE id = ? AND deleted_at IS NULL AND is_active', [$approverId]);
        if (! $a) {
            return;
        }
        try {
            Mailer::notify($a['email'], $a['name'], __('KPI to approve: :task (:period)', ['task' => $title, 'period' => $period]), [
                __(':name submitted a KPI result and chose you as approver.', ['name' => $u->name]),
                __('KPI / OKR').': '.$title,
                __('Month').': '.$period,
                __('Finished').': '.number_clean($in['finished']).' / '.__('Total tasks').': '.number_clean($in['total']),
                __('Submitted').': '.($t['days_late'] ? __(':n days late (:p%)', ['n' => $t['days_late'], 'p' => $t['percentage']]) : __('On time (100%)')),
            ], ['label' => __('Review and approve'), 'url' => $url], __('Please approve by day :d of the next month.', ['d' => K::APPROVE_DAY]));
        } catch (\Throwable $e) {
            error_log('[kpi] approver e-mail for submission '.$id.' failed: '.$e->getMessage());
        }
    }

    public function approve(int $id): never
    {
        $row = K::find($this->user(), $id) ?? abort(404);
        K::canApprove($this->user(), $row) || abort(403, __('Only the chosen approver can approve this.'));
        $this->approveRows([$row]);
        back('success', __('Approved.'));
    }

    /** Approve every submission of one month waiting for me. */
    public function approveMonth(): never
    {
        $u = $this->user();
        $person = (int) Request::input('user_id', 0);
        $rows = DB::select('SELECT * FROM kpi_submissions WHERE deleted_at IS NULL AND approved_at IS NULL AND approver_id = ? AND period_year = ? AND period_month = ?'
            .($person ? ' AND user_id = ?' : ''), [$u->id, (int) Request::input('year'), (int) Request::input('month'), ...($person ? [$person] : [])]);
        $this->approveRows($rows);
        back('success', __(':n submissions approved.', ['n' => count($rows)]));
    }

    private function approveRows(array $rows): void
    {
        $u = $this->user();
        foreach ($rows as $row) {
            if ($row['approved_at']) {
                continue;
            }
            DB::exec('UPDATE kpi_submissions SET approved_at = now(), approved_by = ? WHERE id = ? AND approved_at IS NULL', [$u->id, (int) $row['id']]);
            $period = format_date(sprintf('%04d-%02d-01', $row['period_year'], $row['period_month']), 'F Y');
            Activity::log('approved', 'kpi_submission', (int) $row['id'], $row['task_title'], ['Approved ":label" for :period', ['label' => $row['task_title'], 'period' => $period]], [], (int) $row['user_id'], notify: false);
            Notifier::bell((int) $row['user_id'], 'core', 'kpi_submission', __('KPI approved: :task (:period)', ['task' => $row['task_title'], 'period' => $period]), null,
                url('/kpi-submissions', ['year' => (int) $row['period_year'], 'view' => 'mine']).'#u'.(int) $row['user_id'].'-m'.(int) $row['period_month'], $u->id);
        }
    }

    public function destroy(int $id): never
    {
        $u = $this->user();
        $row = K::find($u, $id) ?? abort(404);
        K::canDelete($u, $row) || abort(403, __('Only the approver can delete a submission.'));
        DB::exec('UPDATE kpi_submissions SET deleted_at = now(), deleted_by = ? WHERE id = ? AND deleted_at IS NULL', [$u->id, $id]);
        $period = format_date(sprintf('%04d-%02d-01', $row['period_year'], $row['period_month']), 'F Y');
        Activity::log('deleted', 'kpi_submission', $id, $row['task_title'], ['Deleted the submission ":label" for :period', ['label' => $row['task_title'], 'period' => $period]], [], (int) $row['user_id'], notify: false);
        if ((int) $row['user_id'] !== $u->id) {
            Notifier::bell((int) $row['user_id'], 'core', 'kpi_submission', __('Your KPI submission was deleted: :task (:period)', ['task' => $row['task_title'], 'period' => $period]),
                __('You can submit it again.'), url('/kpi-submissions/create', ['year' => (int) $row['period_year'], 'month' => (int) $row['period_month']]), $u->id);
        }
        back('success', __('Submission deleted. The KPI can be submitted again for that month.'));
    }

    public function file(int $id, int $file): never
    {
        $this->authorize('kpi_submissions', 'view');
        K::find($this->user(), $id) ?? abort(404);
        $f = DB::first('SELECT * FROM kpi_submission_files WHERE id = ? AND submission_id = ?', [$file, $id]) ?? abort(404);
        $abs = K::path((string) $f['path']) ?? abort(404);
        session_write_close();
        $name = (string) $f['name'];
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.addcslashes(preg_replace('/[^\x20-\x7e]/', '_', $name), '"\\').'"; filename*=UTF-8\'\''.rawurlencode($name));
        header('Content-Length: '.filesize($abs));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($abs);
        exit;
    }

    public function export(): never
    {
        $this->authorize('kpi_submissions', 'export');
        $f = $this->filters();
        Activity::log('export', 'kpi_submission', null, null, ['Exported KPI submissions for :year', ['year' => $f['year']]], [], notify: false);
        Csv::download('kpi-submissions-'.$f['year'].'.csv',
            ['Year', 'Month', 'Status', 'Days late', 'Percentage', 'Submitted by', 'Department', 'Type', 'KPI / OKR', 'Approver', 'Approved', 'Approved at', 'Finished', 'Total tasks', 'Criteria', 'Note', 'Related link', 'Submitted at'],
            (function () use ($f) {
                foreach ($this->rows($f) as $r) {
                    yield [$r['period_year'], $r['period_month'], $r['days_late'] ? 'Late' : 'On time', $r['days_late'], $r['percentage'], $r['submitter_name'], $r['department_name'],
                        strtoupper($r['task_kind']), $r['task_title'], $r['approver_name'], $r['approved_at'] ? 'Yes' : 'No', $r['approved_at'], $r['finished'], $r['total'], $r['criteria'],
                        $r['note'], $r['related_link'], $r['submitted_at']];
                }
            })());
    }
}
