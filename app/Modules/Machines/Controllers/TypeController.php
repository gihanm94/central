<?php
declare(strict_types=1);

namespace App\Modules\Machines\Controllers;

use App\Core\Support\DB;
use App\Core\Support\ValidationException;
use App\Modules\Machines\Support\Mx;

/** Machine groups and their types (e.g. "02 Press" → "01 Hydraulic press"). A machine picks one type. */
class TypeController extends MachinesController
{
    protected string $resource = 'machines_types';
    protected string $table = 'machine_type';
    protected string $type = 'machine_type';
    protected string $singular = 'machine type';
    protected string $plural = 'machine types';
    protected string $base = '/machines/types';
    protected string $icon = 'gear';
    protected string $orderBy = 't.machine_group_id, t.machine_type_id';

    protected function label(array $row): string { return $row['machine_group_name'].' / '.$row['machine_type_name']; }

    protected function searchable(): array { return ['t.machine_group_name', 't.machine_type_name', 't.machine_group_id', 't.machine_type_id']; }

    protected function filters(): array
    {
        return [
            'group'  => ['label' => __('All groups'), 'options' => $this->groups(), 'column' => 't.machine_group_id'],
            'status' => ['label' => __('All statuses'), 'options' => ['ACTIVE' => __('Active'), 'INACTIVE' => __('Inactive')], 'column' => 't.status'],
        ];
    }

    /** [group id => group name] */
    private function groups(): array
    {
        return array_column(DB::select('SELECT DISTINCT machine_group_id, machine_group_name FROM machine_type WHERE deleted_at IS NULL ORDER BY 1', [], 'machines'), 'machine_group_name', 'machine_group_id');
    }

    protected function fields(?array $row): array
    {
        $groups = ['__new' => __('+ New group')] + array_map(fn ($n) => $n, $this->groups());
        $isNew  = ! $row;

        return [
            'group'              => ['label' => __('Group'), 'type' => 'select', 'options' => $groups, 'rules' => $isNew ? 'required' : 'nullable', 'table' => false, 'import' => false, 'readonly' => ! $isNew,
                                     'default' => $row['machine_group_id'] ?? '', 'help' => $isNew ? __('Pick a group, or create a new one.') : null, 'hide_show' => true, 'display' => fn ($r) => $r['machine_group_name']],
            'machine_group_name' => ['label' => __('Group name'), 'rules' => 'nullable|max:160', 'show_when' => $isNew ? 'group=__new' : null, 'example' => 'Press', 'help' => $isNew ? __('Needed only for a new group.') : __('Renames the whole group.')],
            'machine_type_name'  => ['label' => __('Type name'), 'rules' => 'required|max:160', 'example' => 'Hydraulic press'],
            'status'             => ['label' => __('Status'), 'type' => 'select', 'options' => ['ACTIVE' => __('Active'), 'INACTIVE' => __('Inactive')], 'rules' => 'required', 'default' => 'ACTIVE', 'search' => false],
        ];
    }

    protected function columns(): array
    {
        return [
            'group'  => ['label' => __('Group'), 'primary' => true, 'sort' => 't.machine_group_id', 'render' => fn ($r) => '<span class="tabular-nums text-steel">'.e($r['machine_group_id']).'</span> '.e($r['machine_group_name'])],
            'type'   => ['label' => __('Type'), 'sort' => 't.machine_type_id', 'render' => fn ($r) => '<span class="tabular-nums text-steel">'.e($r['machine_type_id']).'</span> '.e($r['machine_type_name'])],
            'status' => ['label' => __('Status'), 'sort' => 't.status', 'render' => fn ($r) => Mx::pill($r['status'] === 'ACTIVE' ? 'ACTIVE' : 'INACTIVE', $r['status'] === 'ACTIVE' ? 'ok' : 'neutral')],
            'count'  => ['label' => __('Machines'), 'render' => fn ($r) => (string) DB::scalar('SELECT count(*) FROM machine WHERE deleted_at IS NULL AND machine_group_id = ? AND machine_type_id = ?', [$r['machine_group_id'], $r['machine_type_id']], 'machines')],
        ];
    }

    protected function prepare(array $data, ?array $existing): array
    {
        $name = trim((string) ($data['machine_type_name'] ?? ''));
        if (! $existing) {
            $group = (string) ($data['group'] ?? '');
            if ($group === '__new') {
                $gname = trim((string) ($data['machine_group_name'] ?? ''));
                if ($gname === '') {
                    throw new ValidationException(['machine_group_name' => __('Give the new group a name.')]);
                }
                if (DB::scalar('SELECT 1 FROM machine_type WHERE lower(machine_group_name) = lower(?) AND deleted_at IS NULL', [$gname], 'machines')) {
                    throw new ValidationException(['machine_group_name' => __('This group name already exists. Pick it from the list.')]);
                }
                $next = (int) DB::scalar("SELECT COALESCE(MAX(machine_group_id::int), 0) FROM machine_type WHERE machine_group_id ~ '^[0-9]+$'", [], 'machines') + 1;
                $data['machine_group_id']   = str_pad((string) $next, 2, '0', STR_PAD_LEFT);
                $data['machine_group_name'] = $gname;
                $data['machine_type_id']    = '01';
            } else {
                $g = DB::first('SELECT machine_group_id, machine_group_name FROM machine_type WHERE machine_group_id = ? AND deleted_at IS NULL LIMIT 1', [$group], 'machines')
                    ?? throw new ValidationException(['group' => __('This group does not exist.')]);
                $data['machine_group_id']   = $g['machine_group_id'];
                $data['machine_group_name'] = $g['machine_group_name'];
                $nextT = (int) DB::scalar("SELECT COALESCE(MAX(machine_type_id::int), 0) FROM machine_type WHERE machine_group_id = ? AND machine_type_id ~ '^[0-9]+$'", [$group], 'machines') + 1;
                $data['machine_type_id'] = str_pad((string) $nextT, 2, '0', STR_PAD_LEFT);
            }
            if (DB::scalar('SELECT 1 FROM machine_type WHERE machine_group_id = ? AND lower(machine_type_name) = lower(?) AND deleted_at IS NULL', [$data['machine_group_id'], $name], 'machines')) {
                throw new ValidationException(['machine_type_name' => __('This type already exists in the group.')]);
            }
        } else {
            if (DB::scalar('SELECT 1 FROM machine_type WHERE machine_group_id = ? AND lower(machine_type_name) = lower(?) AND id <> ? AND deleted_at IS NULL', [$existing['machine_group_id'], $name, $existing['id']], 'machines')) {
                throw new ValidationException(['machine_type_name' => __('This type already exists in the group.')]);
            }
            if (isset($data['machine_group_name']) && trim($data['machine_group_name']) === '') {
                unset($data['machine_group_name']);
            }
        }
        unset($data['group']);

        return $this->stamp($data, $existing);
    }

    protected function saved(int $id, array $data, ?array $existing): void
    {
        // renaming a group renames every row of it
        if ($existing && ! empty($data['machine_group_name']) && $data['machine_group_name'] !== $existing['machine_group_name']) {
            DB::exec('UPDATE machine_type SET machine_group_name = ? WHERE machine_group_id = ?', [$data['machine_group_name'], $existing['machine_group_id']], 'machines');
        }
    }

    protected function beforeDelete(array $row): void
    {
        if (DB::scalar('SELECT 1 FROM machine WHERE deleted_at IS NULL AND machine_group_id = ? AND machine_type_id = ?', [$row['machine_group_id'], $row['machine_type_id']], 'machines')) {
            back('error', __('Machines still use this type. Set it to Inactive instead of deleting it.'));
        }
    }
}
