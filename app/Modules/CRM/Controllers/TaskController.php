<?php
declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\ValidationException;
use App\Modules\CRM\Support\Access;
use App\Modules\CRM\Support\Catalog;
use App\Modules\CRM\Support\Responsible;
use App\Modules\CRM\Support\Ui;

/** A task inside a project, with its own dates, files, comments and several responsible people. */
class TaskController extends CrmController
{
    protected array $remote = ['project_id' => 'projects'];

    protected string $resource = 'crm_tasks';
    protected string $table = 'tasks';
    protected string $type = 'task';
    protected string $entity = 'task';
    protected string $codePrefix = 'TK';
    protected string $singular = 'task';
    protected string $plural = 'tasks';
    protected string $base = '/crm/tasks';
    protected string $icon = 'tasks';
    protected string $orderBy = "(t.status IN ('DONE','CANCELLED')), t.end_date NULLS LAST, t.id DESC";
    protected bool $comments = true;
    protected bool $files = true;

    protected function select(): string
    {
        return 'SELECT t.*, p.name AS project_name, p.code AS project_code FROM tasks t JOIN projects p ON p.id = t.project_id AND p.deleted_at IS NULL';
    }

    protected function label(array $row): string { return (string) $row['name']; }

    protected function searchable(): array { return ['t.name', 't.code', 't.description', 'p.name']; }

    protected function filters(): array
    {
        return [
            'status' => ['label' => __('All statuses'), 'options' => Catalog::tr(Catalog::TASK_STATUSES), 'column' => 't.status'],
            'resp'   => ['label' => __('Everyone'), 'options' => ['me' => __('Assigned to me')],
                         'sql' => 'EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id = ?)', 'bind' => fn () => $this->user()->id],
            'when'   => ['label' => __('Any time'), 'options' => ['overdue' => __('Overdue')], 'sql' => "t.status NOT IN ('DONE','CANCELLED') AND t.end_date < CURRENT_DATE AND ? = 'overdue'"],
        ] + $this->commonFilters();
    }

    protected function fields(?array $row): array
    {
        $f = [
            '_task'    => ['section' => __('Task')],
            'project_id' => $this->remoteField('project_id', $row, ['label' => __('Project'), 'rules' => 'required', 'span' => 2,
                             'display' => fn ($r) => $r['project_name'] ?? null, 'href' => fn ($r) => '/crm/projects/'.$r['project_id']]),
            'name'     => ['label' => __('Name'), 'rules' => 'required|max:200', 'span' => 2, 'example' => 'Order the sensors'],
            'status'   => ['label' => __('Status'), 'type' => 'select', 'options' => Catalog::tr(Catalog::TASK_STATUSES), 'rules' => 'required', 'default' => 'TODO', 'search' => false],
            'code'     => ['label' => __('Code'), 'rules' => 'nullable|max:30', 'help' => __('Leave empty to number it automatically.')],
            'start_date' => ['label' => __('Starts'), 'type' => 'date', 'rules' => 'nullable|date'],
            'end_date'   => ['label' => __('Ends'), 'type' => 'date', 'rules' => 'nullable|date'],

            '_people'  => ['section' => __('Responsible')],
            'assignees' => ['label' => __('People responsible'), 'type' => 'custom', 'partial' => 'crm/fields/people', 'span' => 2, 'table' => false, 'import' => false, 'hide_show' => true, 'rules' => 'nullable',
                           'people' => Access::people(), 'selected' => $row ? Responsible::ids('task', (int) $row['id']) : [$this->user()->id],
                           'help' => __('Everyone ticked here sees the task and can update it.')],

            '_more'    => ['section' => __('Details and files')],
            'description' => ['label' => __('Details'), 'type' => 'richtext', 'span' => 2, 'rules' => 'nullable'],
            'files'    => ['label' => __('Attachments'), 'type' => 'custom', 'partial' => 'crm/fields/files', 'span' => 2, 'table' => false, 'import' => false, 'hide_show' => true, 'rules' => 'nullable'],
        ];
        if ($row) {                                // a task stays in its project
            unset($f['project_id']);
        }

        return $f;
    }

    protected function hydrateMore(array $rows): array
    {
        $ids    = Responsible::idsFor('task', array_column($rows, 'id'));
        $people = Access::people();
        foreach ($rows as &$r) {
            $r['assignee_ids']   = $ids[(int) $r['id']] ?? [];
            $r['assignee_names'] = array_values(array_filter(array_map(fn ($u) => $people[$u]['name'] ?? null, $r['assignee_ids'])));
        }

        return $rows;
    }

    protected function columns(): array
    {
        return [
            'name'    => ['label' => __('Task'), 'primary' => true, 'sort' => 't.name', 'render' => fn ($r) => Ui::person($r['name'], $r['code'], null, '/crm/tasks/'.$r['id'])],
            'project' => ['label' => __('Project'), 'sort' => 'p.name', 'render' => fn ($r) => Ui::link('/crm/projects/'.$r['project_id'], $r['project_name'])],
            'people'  => ['label' => __('Responsible'), 'render' => fn ($r) => Ui::avatars($r['assignee_names'] ?? [])],
            'period'  => ['label' => __('Period'), 'sort' => 't.end_date', 'render' => function ($r) {
                if (! $r['start_date'] && ! $r['end_date']) { return Ui::dash(); }
                $late = ! in_array($r['status'], ['DONE', 'CANCELLED'], true) && $r['end_date'] && strtotime((string) $r['end_date'].' 23:59:59') < time();

                return '<span class="tabular-nums '.($late ? 'font-medium text-signal-700' : 'text-steel').'">'.e(format_date($r['start_date'], 'd M').' → '.format_date($r['end_date'], 'd M Y')).'</span>';
            }],
            'status'  => ['label' => __('Status'), 'sort' => 't.status', 'render' => fn ($r) => Ui::badge(__(Catalog::TASK_STATUSES[$r['status']] ?? $r['status']), Catalog::TASK_TONES[$r['status']] ?? 'neutral')],
            'created' => $this->createdColumn(),
        ];
    }

    protected function canModify(array $row, string $action): bool
    {
        if ($action !== 'delete') {
            return Access::canChange($this->user(), 'task', $row);
        }
        $project = DB::first('SELECT * FROM projects WHERE id = ?', [(int) $row['project_id']], 'crm');

        return Access::isManager($this->user(), $row) || ($project && Access::isManager($this->user(), $project));
    }

    protected function prepareMore(array $data, ?array $existing): array
    {
        unset($data['access']);
        $start = $data['start_date'] ?? $existing['start_date'] ?? null;
        $end   = $data['end_date'] ?? $existing['end_date'] ?? null;
        if ($start && $end && strtotime((string) $end) < strtotime((string) $start)) {
            throw new ValidationException(['end_date' => __('The end cannot be before the start.')]);
        }
        if (! $existing) {
            $project = DB::first('SELECT * FROM projects WHERE id = ? AND deleted_at IS NULL', [(int) ($data['project_id'] ?? 0)], 'crm');
            if (! $project || ! ProjectController::canAddTasks($this->user(), $project)) {
                throw new ValidationException(['project_id' => __('You cannot add tasks to this project.')]);
            }
            $data['department_id'] = $project['department_id'];   // a task belongs to its project's department
        } else {
            unset($data['project_id']);
        }

        return $data;
    }

    protected function afterSave(int $id, array $data, ?array $existing): void
    {
        Responsible::sync('task', $id, array_map('intval', (array) ($data['assignees'] ?? [])), $this->user()->id, (string) ($data['name'] ?? $existing['name']));
    }

    protected function subtitleFor(array $row): string
    {
        return implode(' · ', array_filter([$row['code'], $row['project_name'], $row['start_date'] || $row['end_date'] ? format_date($row['start_date'], 'd M Y').' → '.format_date($row['end_date'], 'd M Y') : null]));
    }

    protected function badges(array $row): array
    {
        $b = [Ui::badge(__(Catalog::TASK_STATUSES[$row['status']] ?? $row['status']), Catalog::TASK_TONES[$row['status']] ?? 'neutral')];
        if (! in_array($row['status'], ['DONE', 'CANCELLED'], true) && $row['end_date'] && strtotime((string) $row['end_date'].' 23:59:59') < time()) {
            $b[] = Ui::badge(__('Overdue'), 'danger');
        }

        return $b;
    }

    protected function rowLinks(array $row): array
    {
        return can('crm_projects', 'view') ? [['url' => '/crm/projects/'.$row['project_id'], 'label' => __('Project').': '.$row['project_name'], 'icon' => 'folder']] : [];
    }

    protected function showSide(array $row): string
    {
        $ids    = Responsible::ids('task', (int) $row['id']);
        $people = Access::people();

        return partial('crm/panels/responsible', [
            'title' => __('Responsible'), 'people' => array_map(fn ($i) => $people[$i] ?? ['name' => __('Former member'), 'department' => null], $ids),
            'history' => Responsible::history('task', (int) $row['id']),
        ]);
    }

    /** One click status change from the project page. */
    public function setStatus(int $id): never
    {
        $row    = $this->findForChange($id, 'edit');
        $status = (string) Request::input('status');
        if (! isset(Catalog::TASK_STATUSES[$status])) {
            throw new ValidationException(['status' => __('Unknown status.')]);
        }
        DB::exec('UPDATE tasks SET status = ?, updated_by = ?, updated_at = now() WHERE id = ?', [$status, $this->user()->id, $id], 'crm');
        Activity::log('updated', 'task', $id, $row['name'], ['Marked task ":label" as :status', ['label' => $row['name'], 'status' => Catalog::TASK_STATUSES[$status]]],
            ['changes' => ['status' => ['from' => __(Catalog::TASK_STATUSES[$row['status']]), 'to' => __(Catalog::TASK_STATUSES[$status])]]], $this->owner($row), module: 'crm');
        Session::flash('success', __('Marked as :status.', ['status' => mb_strtolower(__(Catalog::TASK_STATUSES[$status]))]));
        back();
    }
}
