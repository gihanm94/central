<?php
declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Core\Http\Controllers\ResourceController;
use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\ValidationException;
use App\Modules\CRM\Support\Access;
use App\Modules\CRM\Support\Attachments;

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

    /** Owner (and, for whole-company roles, department) fields appended to every form. */
    protected function ownershipFields(?array $row): array
    {
        $u       = $this->user();
        $manager = ! $row || Access::isManager($u, $row);
        $people  = Access::assignable($u);
        $f = ['_ownership' => ['section' => __('Ownership')]];
        $f['owner_id'] = ['label' => __('Owner'), 'type' => 'select', 'options' => $people, 'rules' => 'required', 'default' => $u->id, 'readonly' => ! $manager, 'import' => false,
            'all_options' => $row && $row['owner_id'] ? [$row['owner_id'] => $row['owner_name'] ?? '#'.$row['owner_id']] : [],
            'help' => __('The owner and everyone in the record\'s department can see it. You can share it with others after saving.')];
        if (Access::seesAll($u)) {
            $f['department_id'] = ['label' => __('Department'), 'type' => 'select', 'options' => Access::departments(), 'rules' => 'nullable', 'import' => false,
                'help' => __('Leave empty to use the owner\'s department.')];
        }

        return $f;
    }

    protected function leadOptions(?array $row = null, string $col = 'lead_id'): array
    {
        $s    = Access::visible($this->user(), 't', 'lead');
        $rows = DB::select("SELECT t.id, t.name_en FROM leads t WHERE t.deleted_at IS NULL AND {$s['sql']} ORDER BY t.name_en", $s['params'], 'crm');
        $opts = array_column($rows, 'name_en', 'id');
        if ($row && ! empty($row[$col]) && ! isset($opts[$row[$col]])) {
            $opts[$row[$col]] = (string) DB::scalar('SELECT name_en FROM leads WHERE id = ?', [$row[$col]], 'crm');
        }

        return $opts;
    }

    protected function contactOptions(?array $row = null): array
    {
        $s    = Access::visible($this->user(), 't', 'contact');
        $rows = DB::select("SELECT t.id, t.name_en, l.name_en AS lead FROM contacts t LEFT JOIN leads l ON l.id = t.lead_id WHERE t.deleted_at IS NULL AND {$s['sql']} ORDER BY t.name_en", $s['params'], 'crm');
        $opts = [];
        foreach ($rows as $r) {
            $opts[$r['id']] = $r['name_en'].($r['lead'] ? ' — '.$r['lead'] : '');
        }
        if ($row && ! empty($row['contact_id']) && ! isset($opts[$row['contact_id']])) {
            $opts[$row['contact_id']] = (string) DB::scalar('SELECT name_en FROM contacts WHERE id = ?', [$row['contact_id']], 'crm');
        }

        return $opts;
    }

    protected function opportunityOptions(?array $row = null): array
    {
        $s    = Access::visible($this->user(), 't', 'opportunity');
        $rows = DB::select("SELECT t.id, t.name FROM opportunities t WHERE t.deleted_at IS NULL AND {$s['sql']} ORDER BY t.id DESC", $s['params'], 'crm');
        $opts = array_column($rows, 'name', 'id');
        if ($row && ! empty($row['opportunity_id']) && ! isset($opts[$row['opportunity_id']])) {
            $opts[$row['opportunity_id']] = (string) DB::scalar('SELECT name FROM opportunities WHERE id = ?', [$row['opportunity_id']], 'crm');
        }

        return $opts;
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

        if (! $existing) {
            $data['created_by'] = $u->id;
            $data['owner_id']   = ! empty($data['owner_id']) ? (int) $data['owner_id'] : $u->id;
        }
        $data['updated_by'] = $u->id;

        $ownerId = (int) ($data['owner_id'] ?? $existing['owner_id'] ?? $u->id);
        if (Access::seesAll($u)) {
            if (empty($data['department_id'])) {
                $data['department_id'] = ($existing['department_id'] ?? null)
                    ?: (Access::people()[$ownerId]['department_id'] ?? null);
            }
        } else {
            unset($data['department_id']);
            if (! $existing) {
                $data['department_id'] = $u->department_id;
            }
        }

        if ($this->files) {
            foreach (Attachments::incoming('files') as $file) {
                Attachments::check($file);
            }
        }

        return $this->prepareMore($data, $existing);
    }

    protected function prepareMore(array $data, ?array $existing): array { return $data; }

    /** CSV import has no owner column: rows belong to the person importing them. */
    protected function importDefaults(array $raw): array
    {
        $raw['owner_id'] = $raw['owner_id'] ?? $this->user()->id;

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
