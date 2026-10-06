<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\Activity;
use App\Core\Support\DB;
use App\Core\Support\Options;
use App\Core\Support\Request;
use App\Core\Support\Session;

class TaskController extends ResourceController
{
    public const STATUSES   = ['todo' => 'To do', 'in_progress' => 'In progress', 'review' => 'In review', 'done' => 'Done'];
    public const PRIORITIES = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'];

    public static function statuses(): array { return array_map(fn ($l) => __($l), self::STATUSES); }
    public static function priorities(): array { return array_map(fn ($l) => __($l), self::PRIORITIES); }

    protected string $resource = 'tasks';
    protected string $table = 'tasks';
    protected string $type = 'task';
    protected string $singular = 'task';
    protected string $plural = 'tasks';
    protected string $base = '/tasks';
    protected string $icon = 'check';
    protected array $scopeCols = ['owner' => ['assigned_to', 'created_by'], 'department' => 'department_id', 'team' => 'team_id'];
    protected string $orderBy = "CASE t.status WHEN 'done' THEN 1 ELSE 0 END, t.due_date NULLS LAST, t.id DESC";

    protected function select(): string
    {
        return 'SELECT t.*, a.name AS assignee_name, c.name AS creator_name, d.name AS department_name
                  FROM tasks t
             LEFT JOIN users a ON a.id = t.assigned_to
             LEFT JOIN users c ON c.id = t.created_by
             LEFT JOIN departments d ON d.id = t.department_id';
    }

    protected function fields(?array $row): array
    {
        // People who can only update tasks (Members) change status and notes, nothing else.
        $limited = ! can('tasks', 'create');
        $users   = Options::users($this->user());

        return [
            'title'       => ['label' => __('Title'), 'rules' => 'required|max:200', 'span' => 2, 'readonly' => $limited, 'example' => 'Inspect press guards'],
            'assigned_to' => ['label' => __('Assigned to'), 'type' => 'select', 'options' => $users, 'rules' => 'required', 'readonly' => $limited,
                              'all_options' => $row ? [$row['assigned_to'] => $row['assignee_name']] : []],
            'priority'    => ['label' => __('Priority'), 'type' => 'select', 'options' => self::priorities(), 'rules' => 'required', 'default' => 'medium', 'readonly' => $limited],
            'status'      => ['label' => __('Status'), 'type' => 'select', 'options' => self::statuses(), 'rules' => 'required', 'default' => 'todo'],
            'due_date'    => ['label' => __('Due date'), 'type' => 'date', 'rules' => 'nullable|date', 'readonly' => $limited],
            'description' => ['label' => __('Notes'), 'type' => 'textarea', 'span' => 2, 'rules' => 'nullable|max:5000'],
        ];
    }

    protected function columns(): array
    {
        return [
            'title'    => ['label' => __('Task'), 'primary' => true, 'render' => function ($r) {
                $overdue = $r['status'] !== 'done' && $r['due_date'] && strtotime($r['due_date']) < strtotime('today');

                return '<a href="'.url('/tasks/'.$r['id']).'" class="font-medium hover:text-signal-700">'.e($r['title']).'</a>'
                    .($overdue ? ' <span class="badge ml-1 bg-signal-50 text-signal-800">'.__('Overdue').'</span>' : '');
            }],
            'assignee' => ['label' => __('Assigned to'), 'render' => fn ($r) => e($r['assignee_name'] ?? '—')],
            'priority' => ['label' => __('Priority'), 'render' => fn ($r) => partial('partials/priority', ['p' => $r['priority']])],
            'status'   => ['label' => __('Status'), 'render' => fn ($r) => partial('partials/status', ['s' => $r['status'], 'labels' => self::statuses()])],
            'due'      => ['label' => __('Due'), 'render' => fn ($r) => '<span class="tabular-nums text-steel">'.format_date($r['due_date'], 'd M').'</span>'],
        ];
    }

    protected function searchable(): array { return ['t.title', 'a.name']; }

    protected function filters(): array
    {
        return [
            'status'   => ['label' => __('All statuses'), 'options' => self::statuses(), 'column' => 't.status'],
            'priority' => ['label' => __('All priorities'), 'options' => self::priorities(), 'column' => 't.priority'],
        ];
    }

    protected function owner(array $row): ?int { return $row['assigned_to'] ? (int) $row['assigned_to'] : null; }

    protected function prepare(array $data, ?array $existing): array
    {
        if (! $existing) {
            $data['created_by'] = $this->user()->id;
        }
        if (! empty($data['assigned_to'])) {
            $a = DB::first('SELECT department_id, team_id FROM users WHERE id = ?', [$data['assigned_to']]);
            $data['department_id'] = $a['department_id'] ?? null;
            $data['team_id']       = $a['team_id'] ?? null;
        }
        if (isset($data['status']) && ($existing['status'] ?? null) !== $data['status']) {
            $data['completed_at'] = $data['status'] === 'done' ? now() : null;
        }

        return $data;
    }

    /** One-click status change from the list (anyone who can edit tasks). */
    public function status(int $id): never
    {
        $row    = $this->findForChange($id, 'edit');
        $status = (string) Request::input('status');
        if (! isset(self::STATUSES[$status])) {
            abort(422, __('Unknown status.'));
        }
        DB::exec('UPDATE tasks SET status = ?, completed_at = ?, updated_at = now() WHERE id = ?', [$status, $status === 'done' ? now() : null, $id]);
        Activity::log('updated', 'task', $id, $row['title'], ['Moved task ":label" to :status', ['label' => $row['title'], 'status' => self::STATUSES[$status]]],
            ['changes' => ['status' => ['from' => __(self::STATUSES[$row['status']]), 'to' => __(self::STATUSES[$status])]]], $this->owner($row));

        back('success', __('Task moved to :status.', ['status' => __(self::STATUSES[$status])]));
    }
}
