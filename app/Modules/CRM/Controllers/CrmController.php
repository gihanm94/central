<?php
declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Core\Http\Controllers\ResourceController;
use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\ValidationException;
use App\Modules\CRM\Support\Access;
use App\Modules\CRM\Support\Attachments;
use App\Modules\CRM\Support\Ui;

/**
 * Base of the CRM screens. Same list / form / import / export / download as the core screens,
 * but rows live in crm_db, are limited by Access (own department + owner + shares),
 * and the detail page has comments, files, sharing and related records.
 */
abstract class CrmController extends ResourceController
{
    protected string $conn = 'crm';
    protected string $module = 'crm';
    protected string $entity;          // lead | contact | opportunity | campaign | activity
    protected string $codePrefix = 'CRM';
    protected bool $comments = false;
    protected bool $files = false;
    protected bool $wizard = true;

    protected function scopeSql(): array { return Access::visible($this->user(), 't', $this->entity); }

    protected function owner(array $row): ?int { return ! empty($row['owner_id']) ? (int) $row['owner_id'] : null; }

    protected function canModify(array $row, string $action): bool
    {
        $u = $this->user();

        return $action === 'delete' ? Access::isManager($u, $row) : Access::canChange($u, $this->entity, $row);
    }

    /** Owner and department names live in user_db, so they are added after the query. */
    protected function hydrate(array $rows): array
    {
        if (! $rows) {
            return $rows;
        }
        $users = Access::names('users', array_merge(array_column($rows, 'owner_id'), array_column($rows, 'created_by')));
        $deps  = Access::names('departments', array_column($rows, 'department_id'));
        foreach ($rows as &$r) {
            $r['owner_name']      = $users[$r['owner_id'] ?? 0] ?? null;
            $r['creator_name']    = $users[$r['created_by'] ?? 0] ?? null;
            $r['department_name'] = $deps[$r['department_id'] ?? 0] ?? null;
        }
        unset($r);

        return $this->hydrateMore($rows);
    }

    protected function hydrateMore(array $rows): array { return $rows; }

    /* ---------------------------------------------------------- shared form parts */

    /**
     * Owner and department are never typed in: a new record belongs to the person who creates it and to their department.
     * The only thing a person can add is access for other people / departments (a last "Access" step when creating;
     * on an existing record it is managed from the detail page).
     */
    protected function ownershipFields(?array $row): array
    {
        if ($row) {
            return [];
        }
        $u       = $this->user();
        $targets = [];
        foreach (Access::people() as $id => $p) {
            if ($id !== $u->id) {
                $targets['user:'.$id] = $p['name'].($p['department'] ? ' · '.$p['department'] : '');
            }
        }
        foreach (Access::departments() as $id => $name) {
            $targets['department:'.$id] = __('Department').': '.$name;
        }

        return [
            '_access' => ['section' => __('Access')],
            'access'  => ['label' => __('Share with'), 'type' => 'custom', 'partial' => 'crm/fields/access', 'span' => 2, 'table' => false, 'import' => false, 'hide_show' => true,
                          'rules' => 'nullable', 'targets' => $targets, 'dept' => $u->department_name],
        ];
    }

    /** Fields that point at another CRM record and are picked with a searchable, endless dropdown: column => kind. */
    protected array $remote = [];

    /** One row for the dropdown's button (logo, name, second line). */
    protected function remoteItem(string $kind, int $id): ?array
    {
        $r = match ($kind) {
            'leads'         => DB::first('SELECT name_en AS label, name_th AS sub, image AS img FROM leads WHERE id = ?', [$id], 'crm'),
            'contacts'      => DB::first("SELECT trim(coalesce(c.salutation || ' ', '') || c.name_en) AS label, concat_ws(' · ', l.name_en, c.job_title) AS sub, c.avatar AS img FROM contacts c LEFT JOIN leads l ON l.id = c.lead_id WHERE c.id = ?", [$id], 'crm'),
            default         => DB::first("SELECT o.name AS label, concat_ws(' · ', o.code, l.name_en) AS sub, NULL AS img FROM opportunities o LEFT JOIN leads l ON l.id = o.lead_id WHERE o.id = ?", [$id], 'crm'),
        };

        return $r ? ['label' => $r['label'], 'sub' => $r['sub'], 'img' => upload_url($r['img'])] : null;
    }

    /** Definition of a "pick a lead / contact / opportunity" field; $def adds label, rules, span … */
    protected function remoteField(string $col, ?array $row, array $def): array
    {
        $kind = $this->remote[$col];
        $id   = (int) (old($col) ?: ($row[$col] ?? $this->prefill($col)) ?: 0);

        return $def + ['type' => 'select', 'remote' => $kind, 'current' => $id ? $this->remoteItem($kind, $id) : null, 'default' => $this->prefill($col)];
    }

    /** A picked record must be one this person may see (an unchanged existing value is left alone). */
    protected function checkRemote(array $data, ?array $existing): void
    {
        $tables = ['leads' => ['leads', 'lead'], 'contacts' => ['contacts', 'contact'], 'opportunities' => ['opportunities', 'opportunity']];
        foreach ($this->remote as $col => $kind) {
            $v = $data[$col] ?? null;
            if ($v === null || $v === '' || ($existing && (string) ($existing[$col] ?? '') === (string) $v)) {
                continue;
            }
            [$table, $type] = $tables[$kind];
            $s = Access::visible($this->user(), 't', $type);
            if (! ctype_digit((string) $v) || ! DB::scalar("SELECT 1 FROM {$table} t WHERE t.id = ? AND t.deleted_at IS NULL AND {$s['sql']}", [(int) $v, ...$s['params']], 'crm')) {
                throw new ValidationException([$col => __('Pick one from the list.')]);
            }
        }
    }

    /** Active currencies for dropdowns: [code => code · name] */
    protected function currencyOptions(): array
    {
        return array_column(DB::select("SELECT code, code || ' · ' || name AS label FROM currencies ORDER BY is_base DESC, code", [], 'crm'), 'label', 'code');
    }

    /** Swap a row value for a prefill from the URL (?lead_id=12) on new records. */
    protected function prefill(string $key): mixed
    {
        $v = Request::query($key);

        return ctype_digit((string) $v) ? (int) $v : '';
    }

    /** Last column of every list: who created the record and when. */
    protected function createdColumn(): array
    {
        return ['label' => __('Created by'), 'sort' => 't.created_at', 'render' => fn ($r) => Ui::person((string) ($r['creator_name'] ?? '—'), format_date($r['created_at'], 'M j, Y'), null, null, 'rounded-full')];
    }

    /** "My records" for everyone, plus a department filter for whole-company roles. */
    protected function commonFilters(): array
    {
        $f = ['scope' => ['label' => __('All records'), 'options' => ['mine' => __('My records')], 'sql' => 't.owner_id = ?', 'bind' => fn () => $this->user()->id]];
        if (Access::seesAll($this->user())) {
            $f['department'] = ['label' => __('All departments'), 'options' => Access::departments(), 'column' => 't.department_id'];
        }

        return $f;
    }

    /** Rows of another CRM table the signed-in person may see, e.g. the contacts of a lead. */
    protected function visibleRows(string $type, string $select, string $where, array $params, string $order, int $limit = 8): array
    {
        $table = Access::ENTITIES[$type]['table'];
        $s     = Access::visible($this->user(), 't', $type);
        $rows  = DB::select("SELECT {$select} FROM {$table} t WHERE t.deleted_at IS NULL AND ({$where}) AND {$s['sql']} ORDER BY {$order} LIMIT {$limit}", [...$params, ...$s['params']], 'crm');

        return $rows;
    }

    /** A related-records card for the detail page. $items: [[href, title, meta, badge-html]] */
    protected function relatedPanel(string $title, array $items, ?string $createUrl, string $empty, ?string $createLabel = null, ?string $moreUrl = null): string
    {
        return partial('crm/panels/related', compact('title', 'items', 'createUrl', 'empty', 'createLabel', 'moreUrl'));
    }

    /* -------------------------------------------------------------- save hooks */

    protected function prepare(array $data, ?array $existing): array
    {
        $u = $this->user();

        if (! empty($data['code'])) {
            $taken = DB::scalar("SELECT 1 FROM {$this->table} WHERE lower(code) = lower(?)".($existing ? ' AND id <> ?' : ''), $existing ? [$data['code'], $existing['id']] : [$data['code']], 'crm');
            if ($taken) {
                throw new ValidationException(['code' => __('This code is already used.')]);
            }
        }

        // A record belongs to whoever creates it, and to their department. Forms never change that.
        unset($data['owner_id'], $data['department_id']);
        if (! $existing) {
            $data['created_by']    = $u->id;
            $data['owner_id']      = $u->id;
            $data['department_id'] = $u->department_id;
        }
        $data['updated_by'] = $u->id;

        // Extra access chosen while creating
        if (! $existing) {
            $people = Access::people();
            $deps   = Access::departments();
            $shares = [];
            foreach ((array) ($data['access'] ?? []) as $row) {
                [$type, $id] = array_pad(explode(':', (string) ($row['target'] ?? ''), 2), 2, '');
                $id = (int) $id;
                if (($type === 'user' && isset($people[$id]) && $id !== $u->id) || ($type === 'department' && isset($deps[$id]))) {
                    $shares[$type.':'.$id] = ['type' => $type, 'id' => $id, 'access' => ($row['level'] ?? '') === 'edit' ? 'edit' : 'view'];
                }
            }
            $data['access'] = array_values($shares);
        }

        if ($this->files) {
            foreach (Attachments::incoming('files') as $file) {
                Attachments::check($file);
            }
        }

        $this->checkRemote($data, $existing);

        return $this->prepareMore($data, $existing);
    }

    protected function prepareMore(array $data, ?array $existing): array { return $data; }

    /** CSV import has no owner column: rows belong to the person importing them. */
    protected function importDefaults(array $raw): array
    {
        $raw['owner_id'] = $raw['owner_id'] ?? $this->user()->id;
        // a lead / contact / opportunity may be given by name in the CSV
        $names = ['leads' => ['leads', 'name_en', 'lead'], 'contacts' => ['contacts', 'name_en', 'contact'], 'opportunities' => ['opportunities', 'name', 'opportunity']];
        foreach ($this->remote as $col => $kind) {
            $v = trim((string) ($raw[$col] ?? ''));
            if ($v === '' || ctype_digit($v)) {
                continue;
            }
            [$table, $nameCol, $type] = $names[$kind];
            $s  = Access::visible($this->user(), 't', $type);
            $id = DB::scalar("SELECT t.id FROM {$table} t WHERE t.deleted_at IS NULL AND lower(t.{$nameCol}) = lower(?) AND {$s['sql']} ORDER BY t.id LIMIT 1", [$v, ...$s['params']], 'crm');
            $raw[$col] = $id !== null ? (string) $id : '';
        }

        return $raw;
    }

    protected function saved(int $id, array $data, ?array $existing): void
    {
        if (! $existing) {
            DB::exec("UPDATE {$this->table} SET code = ? WHERE id = ? AND (code IS NULL OR code = '')", [$this->codePrefix.'-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id], 'crm');
        }
        if ($this->files) {
            foreach (Attachments::incoming('files') as $file) {
                Attachments::store($file, $this->entity, $id, $this->user()->id);
            }
        }
        foreach ((array) ($data['access'] ?? []) as $share) {
            if (! $existing) {
                Access::addShare($this->entity, $id, $share['type'], $share['id'], $share['access'], $this->user()->id);
            }
        }
        $this->afterSave($id, $data, $existing);
    }

    protected function afterSave(int $id, array $data, ?array $existing): void {}

    /* ------------------------------------------------- used by RecordController */

    public function entityType(): string { return $this->entity; }
    public function hasComments(): bool { return $this->comments; }
    public function hasFiles(): bool { return $this->files; }

    /** The record, if the signed-in person may see it (404 otherwise). */
    public function openRecord(int $id): array
    {
        $this->authorize($this->resource, 'view');

        return $this->find($id);
    }

    public function allows(array $row, string $action): bool
    {
        return can($this->resource, $action) && $this->canModify($row, $action);
    }

    public function recordLabel(array $row): string { return $this->label($row); }

    /* -------------------------------------------------------------- detail page */

    protected function showTop(array $row): string { return ''; }
    protected function showMain(array $row): string { return ''; }
    protected function showSide(array $row): string { return ''; }
    protected function badges(array $row): array { return []; }
    protected function subtitleFor(array $row): string { return ''; }
    protected function headerMedia(array $row): string { return ''; }

    public function show(int $id): string
    {
        $this->authorize($this->resource, 'view');
        $row = $this->find($id);
        $u   = $this->user();

        $main = $this->showMain($row);
        if ($this->comments) {
            $main .= partial('crm/panels/comments', ['c' => $this->meta(), 'row' => $row, 'items' => \App\Modules\CRM\Controllers\RecordController::commentsFor($this->entity, $id), 'canPost' => true]);
        }
        $side = partial('crm/panels/record', ['row' => $row, 'type' => $this->entity]);
        $side .= partial('crm/panels/sharing', [
            'c' => $this->meta(), 'row' => $row, 'shares' => Access::shares($this->entity, $id),
            'canManage' => Access::isManager($u, $row), 'people' => Access::people(), 'departments' => Access::departments(),
        ]);
        if ($this->files) {
            $side .= partial('crm/panels/files', [
                'c' => $this->meta(), 'row' => $row, 'items' => Attachments::forRecord($this->entity, $id),
                'canUpload' => can($this->resource, 'edit') && $this->canModify($row, 'edit'), 'canManage' => Access::isManager($u, $row),
            ]);
        }
        $side .= $this->showSide($row);

        return view('crm/show', [
            'title' => $this->label($row), 'c' => $this->meta(), 'row' => $row, 'fields' => $this->fields($row),
            'canEdit'     => can($this->resource, 'edit') && $this->canModify($row, 'edit'),
            'canDelete'   => can($this->resource, 'delete') && $this->canModify($row, 'delete'),
            'canDownload' => can($this->resource, 'download'),
            'links' => $this->rowLinks($row), 'badges' => $this->badges($row), 'subtitle' => $this->subtitleFor($row), 'media' => $this->headerMedia($row),
            'top' => $this->showTop($row), 'main' => $main, 'side' => $side,
        ]);
    }

    protected function meta(): array
    {
        return parent::meta() + ['entity' => $this->entity, 'code' => null];
    }
}
