<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\DB;
use App\Core\Support\ValidationException;

class DepartmentController extends ResourceController
{
    protected string $resource = 'departments';
    protected string $table = 'departments';
    protected string $type = 'department';
    protected string $singular = 'department';
    protected string $plural = 'departments';
    protected string $base = '/departments';
    protected string $icon = 'building';
    protected string $orderBy = 't.name';

    protected function scopeSql(): array
    {
        $u = $this->user();

        return $u->scope() === 'all' ? ['sql' => 'TRUE', 'params' => []] : ['sql' => 't.id = ?', 'params' => [$u->department_id ?? 0]];
    }

    protected function select(): string
    {
        return 'SELECT t.*, h.name AS head_name,
                       (SELECT COUNT(*) FROM users u WHERE u.department_id = t.id AND u.deleted_at IS NULL AND u.is_active) AS members_count,
                       (SELECT COUNT(*) FROM teams tm WHERE tm.department_id = t.id AND tm.deleted_at IS NULL) AS teams_count
                  FROM departments t LEFT JOIN users h ON h.id = t.head_id';
    }

    protected function fields(?array $row): array
    {
        $heads = array_column(DB::select("SELECT u.id, u.name || ' (' || r.name || ')' AS name FROM users u JOIN roles r ON r.id = u.role_id
                                           WHERE u.deleted_at IS NULL AND u.is_active AND r.level >= 40 ORDER BY u.name"), 'name', 'id');

        return [
            'name'        => ['label' => __('Name'), 'rules' => 'required|max:120', 'example' => 'Operations'],
            'code'        => ['label' => __('Short code'), 'rules' => 'required|max:20|unique:departments,code'.($row ? ','.$row['id'] : ''), 'help' => __('e.g. OPS, FIN'), 'example' => 'OPS'],
            'color'       => ['label' => __('Colour'), 'type' => 'color', 'rules' => 'nullable|max:9', 'default' => \App\Core\Support\DeptColor::of($row ? (int) $row['id'] : (int) DB::scalar('SELECT COALESCE(MAX(id), 0) + 1 FROM departments')), 'help' => __('Used in the control room charts.')],
            'head_id'     => ['label' => __('Head (BU manager)'), 'type' => 'select', 'options' => $heads, 'rules' => 'nullable'],
            'is_active'   => ['label' => __('Active'), 'type' => 'checkbox', 'default' => true],
            'description' => ['label' => __('Description'), 'type' => 'textarea', 'span' => 2, 'rules' => 'nullable|max:1000'],
        ];
    }

    protected function columns(): array
    {
        return [
            'name'    => ['label' => __('Department'), 'primary' => true, 'render' => fn ($r) => '<span class="mr-2 inline-block size-2.5 rounded-full align-middle" style="background:'.e(\App\Core\Support\DeptColor::of((int) $r['id'], $r['color'])).'"></span><a href="'.url('/departments/'.$r['id']).'" class="font-medium hover:text-signal-700">'.e($r['name']).'</a> <span class="ml-1 text-xs text-steel">'.e($r['code']).'</span>'],
            'head'    => ['label' => __('Head'), 'render' => fn ($r) => e($r['head_name'] ?? '—')],
            'members' => ['label' => __('Members'), 'render' => fn ($r) => '<span class="tabular-nums">'.(int) $r['members_count'].'</span>'],
            'teams'   => ['label' => __('Teams'), 'render' => fn ($r) => '<span class="tabular-nums">'.(int) $r['teams_count'].'</span>'],
            'status'  => ['label' => __('Status'), 'render' => fn ($r) => filter_var($r['is_active'], FILTER_VALIDATE_BOOL) ? '<span class="badge bg-emerald-50 text-emerald-800">'.__('Active').'</span>' : '<span class="badge bg-graphite-900/6 text-steel">'.__('Inactive').'</span>'],
        ];
    }

    protected function searchable(): array { return ['t.name', 't.code']; }

    protected function owner(array $row): ?int { return $row['head_id'] ? (int) $row['head_id'] : null; }

    protected function prepare(array $data, ?array $existing): array
    {
        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }

        return $data;
    }

    protected function beforeDelete(array $row): void
    {
        if ((int) $row['members_count'] > 0) {
            throw new ValidationException(['department' => __('Move the :n members out of this department first.', ['n' => $row['members_count']])]);
        }
    }

    protected function showExtra(array $row): string
    {
        $teams   = DB::select('SELECT t.id, t.name, l.name AS lead_name FROM teams t LEFT JOIN users l ON l.id = t.lead_id WHERE t.department_id = ? AND t.deleted_at IS NULL ORDER BY t.name', [$row['id']]);
        $members = DB::select('SELECT u.id, u.name, u.avatar, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.department_id = ? AND u.deleted_at IS NULL ORDER BY r.level DESC, u.name LIMIT 50', [$row['id']]);

        return partial('partials/department-extra', compact('teams', 'members'));
    }
}
