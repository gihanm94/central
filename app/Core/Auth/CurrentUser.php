<?php
declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Support\Permission;

/** The signed-in person, with role and permission helpers. */
final class CurrentUser
{
    public int $id;
    public string $name;
    public string $email;
    public int $role_id;
    public string $role_slug;
    public string $role_name;
    public int $role_level;
    public ?int $department_id;
    public ?int $team_id;
    public ?string $department_name;
    public ?string $team_name;
    public ?string $job_title;
    public ?string $avatar;
    public string $role_scope;
    public ?string $data_scope;
    public int $session_version;
    public ?string $last_login_at;
    public ?string $locale;
    public ?string $default_module;
    public ?string $default_dashboard;
    public array $notify_prefs;
    public int $ui_scale = 100;

    public function __construct(array $row)
    {
        $this->id              = (int) $row['id'];
        $this->name            = $row['name'];
        $this->email           = $row['email'];
        $this->role_id         = (int) $row['role_id'];
        $this->role_slug       = $row['role_slug'];
        $this->role_name       = $row['role_name'];
        $this->role_level      = (int) $row['role_level'];
        $this->role_scope      = $row['role_scope'];
        $this->data_scope      = $row['data_scope'];
        $this->department_id   = $row['department_id'] !== null ? (int) $row['department_id'] : null;
        $this->team_id         = $row['team_id'] !== null ? (int) $row['team_id'] : null;
        $this->department_name = $row['department_name'] ?? null;
        $this->team_name       = $row['team_name'] ?? null;
        $this->job_title       = $row['job_title'] ?? null;
        $this->avatar          = $row['avatar'];
        $this->session_version = (int) $row['session_version'];
        $this->last_login_at   = $row['last_login_at'];
        $this->locale          = $row['locale'] ?? null;
        $this->default_module  = $row['default_module'] ?? null;
        $this->default_dashboard = $row['default_dashboard'] ?? null;
        $this->notify_prefs    = json_decode((string) ($row['notify_prefs'] ?? ''), true) ?: [];
        $this->ui_scale        = max(70, min(150, (int) ($row['ui_scale'] ?? 100) ?: 100));
    }

    public function isAdmin(): bool { return $this->role_slug === 'admin'; }

    public function hasRole(string ...$slugs): bool { return in_array($this->role_slug, $slugs, true); }

    /** Effective data scope; falls back to "own" if not attached to a department/team. */
    public function scope(): string
    {
        if ($this->isAdmin()) {
            return 'all';
        }
        $scope = $this->data_scope ?: $this->role_scope;

        return match (true) {
            $scope === 'department' && ! $this->department_id => 'own',
            $scope === 'team' && ! $this->team_id             => 'own',
            default                                            => $scope,
        };
    }

    public function can(string $resource, string $action = 'view'): bool
    {
        return $this->isAdmin() || (Permission::matrix($this->id, $this->role_id)[$resource][$action] ?? false);
    }

    /** Where to land after sign-in: the default module, then its default dashboard. */
    public function homeUrl(): string
    {
        $modules = config('modules');
        $module  = $this->default_module && ($modules[$this->default_module]['enabled'] ?? false) ? $this->default_module : 'core';

        return $modules[$module]['home'];
    }

    public function firstName(): string { return explode(' ', trim($this->name))[0]; }
    public function initials(): string { return initials($this->name); }
    public function avatarUrl(): ?string { return upload_url($this->avatar); }
}
