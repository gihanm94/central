<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\DB;
use App\Core\Support\Options;

class TeamController extends ResourceController
{
    protected string $resource = 'teams';
    protected string $table = 'teams';
    protected string $type = 'team';
    protected string $singular = 'team';
    protected string $plural = 'teams';
    protected string $base = '/teams';
    protected string $icon = 'team';
    protected string $orderBy = 'd.name, t.name';

    protected function scopeSql(): array
    {
        $u = $this->user();

        return match ($u->scope()) {
            'all'        => ['sql' => 'TRUE', 'params' => []],
            'department' => ['sql' => 't.department_id = ?', 'params' => [$u->department_id]],
            default      => ['sql' => 't.id = ?', 'params' => [$u->team_id ?? 0]],
        };
    }

    protected function select(): string
    {
        return 'SELECT t.*, d.name AS department_name, l.name AS lead_name,
                       (SELECT COUNT(*) FROM users u WHERE u.team_id = t.id AND u.deleted_at IS NULL AND u.is_active) AS members_count
                  FROM teams t JOIN departments d ON d.id = t.department_id LEFT JOIN users l ON l.id = t.lead_id';
    }

    protected function fields(?array $row): array
    {
        return [
            'name'          => ['label' => __('Team name'), 'rules' => 'required|max:120', 'example' => 'Maintenance'],
            'department_id' => ['label' => __('Department'), 'type' => 'select', 'options' => Options::departments($this->user()), 'rules' => 'required'],
            'lead_id'       => ['label' => __('Team lead (manager)'), 'type' => 'select', 'options' => Options::users($this->user()), 'rules' => 'nullable'],
            'description'   => ['label' => __('Description'), 'type' => 'textarea', 'span' => 2, 'rules' => 'nullable|max:1000'],
        ];
    }

    protected function columns(): array
    {
        return [
            'name'       => ['label' => __('Team'), 'primary' => true, 'render' => fn ($r) => '<a href="'.url('/teams/'.$r['id']).'" class="font-medium hover:text-signal-700">'.e($r['name']).'</a>'],
            'department' => ['label' => __('Department'), 'render' => fn ($r) => e($r['department_name'])],
            'lead'       => ['label' => __('Lead'), 'render' => fn ($r) => e($r['lead_name'] ?? '—')],
            'members'    => ['label' => __('Members'), 'render' => fn ($r) => '<span class="tabular-nums">'.(int) $r['members_count'].'</span>'],
        ];
    }

    protected function searchable(): array { return ['t.name', 'd.name']; }

    protected function owner(array $row): ?int { return $row['lead_id'] ? (int) $row['lead_id'] : null; }

    protected function beforeDelete(array $row): void
    {
        DB::exec('UPDATE users SET team_id = NULL WHERE team_id = ?', [$row['id']]);
    }

    protected function showExtra(array $row): string
    {
        $members = DB::select('SELECT u.id, u.name, u.avatar, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.team_id = ? AND u.deleted_at IS NULL ORDER BY r.level DESC, u.name', [$row['id']]);

        return partial('partials/department-extra', ['teams' => [], 'members' => $members]);
    }
}
