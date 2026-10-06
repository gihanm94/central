<?php
declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Core\Support\DB;
use App\Core\Support\ValidationException;
use App\Modules\CRM\Support\Access;
use App\Modules\CRM\Support\Catalog;
use App\Modules\CRM\Support\Responsible;
use App\Modules\CRM\Support\Ui;

/** A piece of work with dates, several responsible people, files, comments and a list of tasks. */
class ProjectController extends CrmController
{
    protected string $resource = 'crm_projects';
    protected string $table = 'projects';
    protected string $type = 'project';
    protected string $entity = 'project';
    protected string $codePrefix = 'PJ';
    protected string $singular = 'project';
    protected string $plural = 'projects';
    protected string $base = '/crm/projects';
    protected string $icon = 'folder';
    protected string $orderBy = "CASE t.status WHEN 'ACTIVE' THEN 0 WHEN 'PLANNING' THEN 1 WHEN 'ON_HOLD' THEN 2 ELSE 3 END, t.end_date NULLS LAST, t.id DESC";
    protected bool $comments = true;
    protected bool $files = true;

    protected function select(): string
    {
        return "SELECT t.*,
                  (SELECT count(*) FROM tasks k WHERE k.project_id = t.id AND k.deleted_at IS NULL) AS task_total,
                  (SELECT count(*) FROM tasks k WHERE k.project_id = t.id AND k.deleted_at IS NULL AND k.status = 'DONE') AS task_done
                FROM projects t";
    }

    protected function label(array $row): string { return (string) $row['name']; }

    protected function searchable(): array { return ['t.name', 't.code', 't.description']; }

    protected function filters(): array
    {
        return [
            'status' => ['label' => __('All statuses'), 'options' => Catalog::tr(Catalog::PROJECT_STATUSES), 'column' => 't.status'],
            'resp'   => ['label' => __('Everyone'), 'options' => ['me' => __('I am responsible')],
                         'sql' => 'EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = t.id AND pm.user_id = ?)', 'bind' => fn () => $this->user()->id],
        ] + $this->commonFilters();
    }

    protected function fields(?array $row): array
    {
        return [
            '_project' => ['section' => __('Project')],
            'name'     => ['label' => __('Name'), 'rules' => 'required|max:200', 'span' => 2, 'example' => 'Factory line upgrade'],
            'status'   => ['label' => __('Status'), 'type' => 'select', 'options' => Catalog::tr(Catalog::PROJECT_STATUSES), 'rules' => 'required', 'default' => 'PLANNING', 'search' => false],
            'code'     => ['label' => __('Code'), 'rules' => 'nullable|max:30', 'help' => __('Leave empty to number it automatically.')],
            'start_date' => ['label' => __('Starts'), 'type' => 'date', 'rules' => 'nullable|date'],
            'end_date'   => ['label' => __('Ends'), 'type' => 'date', 'rules' => 'nullable|date'],

            '_people'  => ['section' => __('Responsible')],
            'members'  => ['label' => __('People responsible'), 'type' => 'custom', 'partial' => 'crm/fields/people', 'span' => 2, 'table' => false, 'import' => false, 'hide_show' => true, 'rules' => 'nullable',
                           'people' => Access::people(), 'selected' => $row ? Responsible::ids('project', (int) $row['id']) : [$this->user()->id],
                           'help' => __('Everyone ticked here sees the project and its tasks and can update them.')],

            '_more'    => ['section' => __('Details and files')],
            'description' => ['label' => __('Details'), 'type' => 'richtext', 'span' => 2, 'rules' => 'nullable'],
            'files'    => ['label' => __('Attachments'), 'type' => 'custom', 'partial' => 'crm/fields/files', 'span' => 2, 'table' => false, 'import' => false, 'hide_show' => true, 'rules' => 'nullable'],
        ] + $this->ownershipFields($row);
    }

    protected function hydrateMore(array $rows): array
    {
        $ids    = Responsible::idsFor('project', array_column($rows, 'id'));
        $people = Access::people();
        foreach ($rows as &$r) {
            $r['member_ids']   = $ids[(int) $r['id']] ?? [];
            $r['member_names'] = array_values(array_filter(array_map(fn ($u) => $people[$u]['name'] ?? null, $r['member_ids'])));
        }

        return $rows;
    }

    protected function columns(): array
    {
        return [
            'name'   => ['label' => __('Project'), 'primary' => true, 'sort' => 't.name', 'render' => fn ($r) => Ui::person($r['name'], $r['code'], null, '/crm/projects/'.$r['id'])],
            'status' => ['label' => __('Status'), 'sort' => 't.status', 'render' => fn ($r) => Ui::badge(__(Catalog::PROJECT_STATUSES[$r['status']] ?? $r['status']), Catalog::PROJECT_TONES[$r['status']] ?? 'neutral')],
            'period' => ['label' => __('Period'), 'sort' => 't.start_date', 'render' => fn ($r) => $r['start_date'] || $r['end_date'] ? '<span class="tabular-nums text-steel">'.e(format_date($r['start_date'], 'd M').' → '.format_date($r['end_date'], 'd M Y')).'</span>' : Ui::dash()],
            'people' => ['label' => __('Responsible'), 'render' => fn ($r) => Ui::avatars($r['member_names'] ?? [])],
            'tasks'  => ['label' => __('Tasks'), 'sort' => 'task_total', 'render' => fn ($r) => $r['task_total'] > 0
                ? partial('crm/meter', ['value' => $r['task_done'] / $r['task_total'] * 100, 'label' => $r['task_done'].'/'.$r['task_total']]) : Ui::dash()],
            'created' => $this->createdColumn(),
        ];
    }

    protected function prepareMore(array $data, ?array $existing): array
    {
        $start = $data['start_date'] ?? $existing['start_date'] ?? null;
        $end   = $data['end_date'] ?? $existing['end_date'] ?? null;
        if ($start && $end && strtotime((string) $end) < strtotime((string) $start)) {
            throw new ValidationException(['end_date' => __('The end cannot be before the start.')]);
        }

        return $data;
    }

    protected function afterSave(int $id, array $data, ?array $existing): void
    {
        $ids = array_map('intval', (array) ($data['members'] ?? []));
        if (! $existing) {
            $ids[] = $this->user()->id;          // whoever creates it is responsible for it
        }
        Responsible::sync('project', $id, $ids, $this->user()->id, (string) ($data['name'] ?? $existing['name']));
    }

    protected function beforeDelete(array $row): void
    {
        // tasks go with the project (ON DELETE CASCADE only fires on a hard delete)
        DB::exec('UPDATE tasks SET deleted_at = now() WHERE project_id = ? AND deleted_at IS NULL', [(int) $row['id']], 'crm');
    }

    protected function rowLinks(array $row): array
    {
        return [];
    }

    protected function badges(array $row): array
    {
        $b = [Ui::badge(__(Catalog::PROJECT_STATUSES[$row['status']] ?? $row['status']), Catalog::PROJECT_TONES[$row['status']] ?? 'neutral')];
        if ($row['end_date'] && ! in_array($row['status'], ['DONE', 'CANCELLED'], true) && strtotime((string) $row['end_date'].' 23:59:59') < time()) {
            $b[] = Ui::badge(__('Overdue'), 'danger');
        }

        return $b;
    }

    protected function subtitleFor(array $row): string
    {
        return implode(' · ', array_filter([$row['code'], $row['start_date'] || $row['end_date'] ? format_date($row['start_date'], 'd M Y').' → '.format_date($row['end_date'], 'd M Y') : null]));
    }

    private function tasksOf(array $row): array
    {
        $s = Access::visible($this->user(), 't', 'task');
        $tasks = DB::select("SELECT t.* FROM tasks t WHERE t.project_id = ? AND t.deleted_at IS NULL AND {$s['sql']} ORDER BY (t.status IN ('DONE','CANCELLED')), t.end_date NULLS LAST, t.id", [(int) $row['id'], ...$s['params']], 'crm');
        $ids   = Responsible::idsFor('task', array_column($tasks, 'id'));
        $names = Access::people();
        foreach ($tasks as &$k) {
            $k['assignee_ids']   = $ids[(int) $k['id']] ?? [];
            $k['assignee_names'] = array_values(array_filter(array_map(fn ($u) => $names[$u]['name'] ?? null, $k['assignee_ids'])));
        }

        return $tasks;
    }

    public static function canAddTasks(\App\Core\Auth\CurrentUser $u, array $project): bool
    {
        return can('crm_tasks', 'create') && (Access::canChange($u, 'project', $project) || ($u->department_id && (int) $project['department_id'] === $u->department_id));
    }

    protected function showTop(array $row): string
    {
        $tasks = $this->tasksOf($row);
        $done  = count(array_filter($tasks, fn ($t) => $t['status'] === 'DONE'));
        $open  = array_filter($tasks, fn ($t) => ! in_array($t['status'], ['DONE', 'CANCELLED'], true));
        $late  = count(array_filter($open, fn ($t) => $t['end_date'] && strtotime((string) $t['end_date'].' 23:59:59') < time()));

        return partial('crm/panels/project-summary', ['row' => $row, 'total' => count($tasks), 'done' => $done, 'late' => $late]);
    }

    protected function showMain(array $row): string
    {
        $u = $this->user();

        return partial('crm/panels/project-tasks', ['project' => $row, 'tasks' => $this->tasksOf($row), 'canAdd' => self::canAddTasks($u, $row), 'canEdit' => can('crm_tasks', 'edit')]);
    }

    protected function showSide(array $row): string
    {
        $ids    = Responsible::ids('project', (int) $row['id']);
        $people = Access::people();

        return partial('crm/panels/responsible', [
            'title' => __('Responsible'), 'people' => array_map(fn ($i) => $people[$i] ?? ['name' => __('Former member'), 'department' => null], $ids),
            'history' => Responsible::history('project', (int) $row['id']),
        ]);
    }
}
