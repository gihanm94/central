<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\Activity;
use App\Core\Support\Csv;
use App\Core\Support\Html;
use App\Core\Support\DB;
use App\Core\Support\Permission;
use App\Core\Support\Request;
use App\Core\Support\Session;
use App\Core\Support\ValidationException;
use App\Core\Support\Validator;

/**
 * Generic list / create / edit / delete / import / export / download screen,
 * driven by fields() and columns(). Every action checks the permission
 * (resource.action) and the data scope (which rows the user may touch).
 *
 * Field definition keys:
 *   label, type (text|email|password|textarea|select|date|number|checkbox),
 *   rules, options [value => label], span (1|2), help, readonly, default,
 *   table (false = not a column of $table, handled in saved()),
 *   import (false = not in CSV import/export)
 */
abstract class ResourceController extends Controller
{
    protected string $resource;
    protected string $table;
    protected string $type;
    protected string $singular;
    protected string $plural;
    protected string $base;
    protected array $scopeCols = ['owner' => 'user_id', 'department' => 'department_id', 'team' => 'team_id'];
    protected bool $softDelete = true;
    protected string $orderBy = 't.id DESC';
    protected int $perPage = 10;
    protected bool $wizard = false;            // sections of the form become steps
    protected string $icon = 'grid';
    protected string $module = 'core';
    protected string $conn = 'core';          // database connection (see config db.databases)

    abstract protected function fields(?array $row): array;

    abstract protected function columns(): array;

    protected function select(): string { return "SELECT t.* FROM {$this->table} t"; }
    protected function searchable(): array { return []; }
    protected function filters(): array { return []; }
    protected function scopeSql(): array { return Permission::scope($this->user(), 't', $this->scopeCols); }
    protected function label(array $row): string { return (string) ($row['name'] ?? $row['title'] ?? '#'.$row['id']); }
    protected function owner(array $row): ?int { return null; }
    protected function prepare(array $data, ?array $existing): array { return $data; }
    protected function saved(int $id, array $data, ?array $existing): void {}
    protected function canModify(array $row, string $action): bool { return true; }
    protected function rowLinks(array $row): array { return []; }
    protected function showExtra(array $row): string { return ''; }
    protected function intro(): string { return ''; }
    /** Summary cards above the list: [['label', 'value', 'sub', 'tone', 'icon', 'href']] — empty for most screens. */
    protected function stats(): array { return []; }
    /** HTML put in the list toolbar (before Add) and above the table — used by screens with their own buttons / tabs. */
    protected function headerActions(): string { return ''; }
    protected function topExtra(): string { return ''; }
    /** Add values that live in another database (owner names …) to rows read from $table. */
    protected function hydrate(array $rows): array { return $rows; }

    /* ------------------------------------------------------------- queries */

    protected function query(bool $paginate = true): array
    {
        $scope  = $this->scopeSql();
        $where  = [$scope['sql']];
        $params = $scope['params'];

        if ($this->softDelete) {
            $where[] = 't.deleted_at IS NULL';
        }
        if (($q = Request::query('q')) && $this->searchable()) {
            $where[] = '('.implode(' OR ', array_map(fn ($c) => "{$c}::text ILIKE ?", $this->searchable())).')';
            array_push($params, ...array_fill(0, count($this->searchable()), '%'.$q.'%'));
        }
        foreach ($this->filters() as $name => $f) {
            $v = Request::query($name);
            if ($v !== null && $v !== '' && array_key_exists($v, $f['options'])) {
                $where[]  = $f['sql'] ?? "{$f['column']} = ?";
                if (! isset($f['sql']) || str_contains($f['sql'], '?')) {
                    $params[] = isset($f['bind']) ? ($f['bind'])($v) : $v;
                }
            }
        }

        $sql   = $this->select().' WHERE '.implode(' AND ', $where);
        $total = (int) DB::scalar("SELECT COUNT(*) FROM ({$sql}) x", $params, $this->conn);
        [$sortKey, $sortDir] = $this->sorting();
        $sql  .= ' ORDER BY '.($sortKey ? $this->columns()[$sortKey]['sort'].' '.$sortDir.' NULLS LAST, t.id DESC' : $this->orderBy);

        if ($paginate) {
            $page = max(1, (int) Request::query('page', 1));
            $size = $this->pageSize();
            $sql .= ' LIMIT '.$size.' OFFSET '.(($page - 1) * $size);
        }

        return ['rows' => $this->hydrate(DB::select($sql, $params, $this->conn)), 'total' => $total];
    }

    /** Column the list is sorted by (only columns that define 'sort' => SQL expression can be). */
    protected function sorting(): array
    {
        $key = (string) Request::query('sort', '');
        $col = $this->columns()[$key] ?? null;
        if (! $col || empty($col['sort'])) {
            return [null, 'ASC'];
        }

        return [$key, strtolower((string) Request::query('dir', 'asc')) === 'desc' ? 'DESC' : 'ASC'];
    }

    /** Rows per page: ?per_page=…, else what this person chose before, else 10. */
    protected function pageSize(): int
    {
        $ok = [10, 20, 50, 100];
        $q  = (int) Request::query('per_page', 0);
        if (in_array($q, $ok, true)) {
            return $q;
        }
        $saved = (int) (TablePrefController::load($this->user()->id, $this->resource)['per_page'] ?? 0);

        return in_array($saved, $ok, true) ? $saved : $this->perPage;
    }

    protected function find(int $id): array
    {
        $scope = $this->scopeSql();
        $row   = DB::first($this->select().' WHERE t.id = ? AND '.$scope['sql'].($this->softDelete ? ' AND t.deleted_at IS NULL' : ''), [$id, ...$scope['params']], $this->conn);
        $row   = $row ? $this->hydrate([$row])[0] : null;

        return $row ?? abort(404, __(':Item not found, or it is outside what you can see.', ['Item' => __(ucfirst($this->singular))]));
    }

    protected function findForChange(int $id, string $action): array
    {
        $this->authorize($this->resource, $action);
        $row = $this->find($id);
        if (! $this->canModify($row, $action)) {
            abort(403, __("You can't :action this :item.", ['action' => __($action), 'item' => __($this->singular)]));
        }

        return $row;
    }

    /* -------------------------------------------------------------- pages */

    public function index(): string
    {
        $this->authorize($this->resource, 'view');
        ['rows' => $rows, 'total' => $total] = $this->query();
        foreach ($rows as $i => $r) {
            $rows[$i]['_label'] = $this->label($r);       // used by the delete dialog
        }
        $asked = (int) Request::query('per_page', 0);
        if (in_array($asked, [10, 20, 50, 100], true)) {
            TablePrefController::rememberPageSize($this->user()->id, $this->resource, $asked);
        }

        $rowActions = [];
        foreach ($rows as $row) {
            $rowActions[$row['id']] = [
                'edit'     => can($this->resource, 'edit') && $this->canModify($row, 'edit'),
                'delete'   => can($this->resource, 'delete') && $this->canModify($row, 'delete'),
                'download' => can($this->resource, 'download'),
                'links'    => $this->rowLinks($row),
            ];
        }

        return view('resource/index', [
            'title' => __(ucfirst($this->plural)), 'c' => $this->meta(), 'rows' => $rows, 'total' => $total,
            'columns' => $this->columns(), 'filters' => $this->filters(), 'rowActions' => $rowActions,
            'perPage' => $this->pageSize(), 'page' => max(1, (int) Request::query('page', 1)),
            'hidden' => TablePrefController::load($this->user()->id, $this->resource)['hidden'],
            'sort' => $this->sorting()[0], 'dir' => strtolower($this->sorting()[1]),
            'canDelete' => can($this->resource, 'delete'),
            'canSearch' => (bool) $this->searchable(), 'intro' => $this->intro(), 'stats' => $this->stats(), 'headerActions' => $this->headerActions(), 'topExtra' => $this->topExtra(),
        ]);
    }

    public function create(): string
    {
        $this->authorize($this->resource, 'create');

        return view('resource/form', ['title' => __('New :item', ['item' => __($this->singular)]), 'c' => $this->meta(), 'row' => null, 'fields' => $this->fields(null), 'embed' => $this->embedded()]);
    }

    public function store(): never
    {
        $this->authorize($this->resource, 'create');
        $fields = $this->fields(null);
        $data   = $this->prepare($this->validateFields($fields, Request::all()), null);
        $id     = (int) DB::insert($this->table, $this->tableData($fields, $data), $this->conn);
        $this->saved($id, $data, null);

        $row = $this->find($id);
        Activity::log('created', $this->type, $id, $this->label($row), ['Created :type ":label"', ['type' => $this->singular, 'label' => $this->label($row)]],
            ['attributes' => $this->displayValues($fields, $row), '_url' => url($this->base.'/'.$id)], $this->owner($row), module: $this->module);

        Session::flash('success', __(':Item created.', ['Item' => __(ucfirst($this->singular))]));
        $this->finish($id);
    }

    public function show(int $id): string
    {
        $this->authorize($this->resource, 'view');
        $row = $this->find($id);

        return view('resource/show', [
            'title' => $this->label($row), 'c' => $this->meta(), 'row' => $row, 'fields' => $this->fields($row),
            'canEdit'   => can($this->resource, 'edit') && $this->canModify($row, 'edit'),
            'canDelete' => can($this->resource, 'delete') && $this->canModify($row, 'delete'),
            'canDownload' => can($this->resource, 'download'),
            'extra' => $this->showExtra($row), 'links' => $this->rowLinks($row),
        ]);
    }

    public function edit(int $id): string
    {
        $row = $this->findForChange($id, 'edit');

        return view('resource/form', ['title' => __('Edit :name', ['name' => $this->label($row)]), 'c' => $this->meta(), 'row' => $row, 'fields' => $this->fields($row), 'embed' => $this->embedded()]);
    }

    public function update(int $id): never
    {
        $row    = $this->findForChange($id, 'edit');
        $fields = $this->fields($row);
        $data   = $this->prepare($this->validateFields($fields, Request::all(), $row), $row);
        $table  = $this->tableData($fields, $data);

        if ($table) {
            $table['updated_at'] = now();
            DB::update($this->table, $table, ['id' => $id], $this->conn);
        }
        $this->saved($id, $data, $row);

        $after   = $this->find($id);
        $changes = Activity::diff($this->displayValues($fields, $row), $this->displayValues($fields, $after), array_keys($this->real($fields)));
        if (isset($data['password'])) {
            $changes['password'] = ['from' => '•••', 'to' => 'changed'];
        }
        if ($changes) {
            Activity::log('updated', $this->type, $id, $this->label($after), ['Updated :type ":label"', ['type' => $this->singular, 'label' => $this->label($after)]],
                ['changes' => $changes, '_url' => url($this->base.'/'.$id)], $this->owner($after), module: $this->module);
        }

        Session::flash('success', $changes ? __(':Item saved.', ['Item' => __(ucfirst($this->singular))]) : __('Nothing changed.'));
        $this->finish($id);
    }

    /** The same form is also shown inside a side sheet (?embed=1): only the form, no page around it. */
    protected function embedded(): bool { return ! empty($_GET['embed']); }

    /** After saving: the sheet gets JSON (and reloads the page behind it), a normal form goes to the record. */
    protected function finish(int $id): never
    {
        if (Request::isJson()) {
            json_response(['ok' => true, 'id' => $id, 'url' => url($this->base.'/'.$id)]);
        }
        redirect($this->base.'/'.$id);
    }

    public function destroy(int $id): never
    {
        $row = $this->findForChange($id, 'delete');
        $this->requireTypedConfirmation();
        $this->beforeDelete($row);

        $this->softDelete
            ? DB::exec("UPDATE {$this->table} SET deleted_at = now() WHERE id = ?", [$id], $this->conn)
            : DB::exec("DELETE FROM {$this->table} WHERE id = ?", [$id], $this->conn);

        Activity::log('deleted', $this->type, $id, $this->label($row), ['Deleted :type ":label"', ['type' => $this->singular, 'label' => $this->label($row)]], [], $this->owner($row), module: $this->module);
        Session::flash('success', __(':Item deleted.', ['Item' => __(ucfirst($this->singular))]));
        redirect($this->base);
    }

    protected function beforeDelete(array $row): void {}

    /** Deleting always needs the word DELETE typed in the dialog. */
    protected function requireTypedConfirmation(): void
    {
        if (Request::input('confirm') !== 'DELETE') {
            back('error', __('Type DELETE to confirm.'));
        }
    }

    /** Delete the ticked rows of a list (each one still checked against the person's rights). */
    public function bulkDestroy(): never
    {
        $this->authorize($this->resource, 'delete');
        $this->requireTypedConfirmation();
        $ids  = array_values(array_unique(array_filter(array_map('intval', (array) Request::input('ids', [])))));
        $done = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            try {
                $row = $this->find($id);
            } catch (\App\Core\Support\HttpException) {
                $skipped++;
                continue;
            }
            if (! $this->canModify($row, 'delete')) {
                $skipped++;
                continue;
            }
            $this->beforeDelete($row);
            $this->softDelete
                ? DB::exec("UPDATE {$this->table} SET deleted_at = now() WHERE id = ?", [$id], $this->conn)
                : DB::exec("DELETE FROM {$this->table} WHERE id = ?", [$id], $this->conn);
            Activity::log('deleted', $this->type, $id, $this->label($row), ['Deleted :type ":label"', ['type' => $this->singular, 'label' => $this->label($row)]], [], $this->owner($row), module: $this->module);
            $done++;
        }
        Session::flash($done ? 'success' : 'error', __(':n deleted.', ['n' => $done]).($skipped ? ' '.__(':n skipped (not yours to delete).', ['n' => $skipped]) : ''));
        redirect($this->base);
    }

    /* ---------------------------------------------- import / export / download */

    public function export(): never
    {
        $this->authorize($this->resource, 'export');
        $fields = array_filter($this->real($this->fields(null)), fn ($f) => ($f['import'] ?? true) && ($f['type'] ?? 'text') !== 'password');
        $rows   = $this->query(false)['rows'];

        Activity::log('export', $this->type, null, ucfirst($this->plural), ['Exported :count :type', ['count' => count($rows), 'type' => $this->plural]],
            ['count' => count($rows), 'filters' => array_filter($_GET)], module: $this->module);

        Csv::download($this->plural.'-'.date('Ymd-His').'.csv', ['id', ...array_keys($fields)],
            array_map(fn ($r) => [$r['id'], ...array_values($this->displayValues($fields, $r))], $rows));
    }

    public function template(): never
    {
        $this->authorize($this->resource, 'import');
        $fields = array_filter($this->real($this->fields(null)), fn ($f) => ($f['import'] ?? true) && empty($f['readonly']));
        Activity::log('download', $this->type, null, 'Import template', ['Downloaded the :type import template', ['type' => $this->plural]], [], null, null, false, $this->module);

        Csv::download($this->plural.'-import-template.csv', array_keys($fields), [
            array_map(fn ($f) => isset($f['options']) ? (string) (is_array($x = reset($f['options'])) ? $x['label'] : $x) : ($f['example'] ?? ''), $fields),
        ]);
    }

    public function import(): never
    {
        $this->authorize($this->resource, 'import');
        $file = Request::file('file') ?? throw new ValidationException(['file' => __('Choose a CSV file to import.')]);
        $rows = Csv::read($file);

        $fields  = $this->real($this->fields(null));
        $created = 0;
        $errors  = [];

        foreach ($rows as $i => $raw) {
            $line = $i + 2; // + header row
            try {
                // selects accept either the stored value or the visible label
                foreach ($fields as $name => $f) {
                    if (isset($f['options'], $raw[$name]) && $raw[$name] !== '' && ! array_key_exists($raw[$name], $f['options'])) {
                        $match = array_search(mb_strtolower(trim($raw[$name])), array_map('mb_strtolower', array_map(fn ($o) => (string) (is_array($o) ? $o['label'] : $o), $f['options'])), true);
                        if ($match !== false) {
                            $raw[$name] = (string) $match;
                        }
                    }
                }
                $raw  = $this->importDefaults($raw);
                $data = $this->prepare($this->validateFields($fields, $raw), null);
                $id   = (int) DB::insert($this->table, $this->tableData($fields, $data), $this->conn);
                $this->saved($id, $data, null);
                $created++;
            } catch (ValidationException $e) {
                $errors[] = __('Row :n', ['n' => $line]).': '.implode(' ', $e->errors);
            } catch (\PDOException $e) {
                $errors[] = __('Row :n', ['n' => $line]).': '.__('could not be saved').' ('.str_limit($e->getMessage(), 90).')';
            }
        }

        Activity::log('import', $this->type, null, ucfirst($this->plural),
            ['Imported :created of :total :type', ['created' => $created, 'total' => count($rows), 'type' => $this->plural]],
            ['created' => $created, 'failed' => count($errors), 'errors' => array_slice($errors, 0, 20), 'file' => $file['name']], module: $this->module);

        Session::flash($errors ? 'error' : 'success', __('Imported :created of :total rows.', ['created' => $created, 'total' => count($rows)]).($errors ? ' '.__('Problems').': '.implode(' | ', array_slice($errors, 0, 5)).(count($errors) > 5 ? ' …' : '') : ''));
        redirect($this->base);
    }

    protected function importDefaults(array $raw): array { return $raw; }

    public function download(int $id): never
    {
        $this->authorize($this->resource, 'download');
        $row    = $this->find($id);
        $fields = array_filter($this->real($this->fields($row)), fn ($f) => ($f['type'] ?? 'text') !== 'password');

        Activity::log('download', $this->type, $id, $this->label($row), ['Downloaded :type ":label"', ['type' => $this->singular, 'label' => $this->label($row)]], [], $this->owner($row), module: $this->module);

        $values = $this->displayValues($fields, $row);
        $lines = [['ID', (string) $id]];
        foreach ($fields as $name => $f) {
            $lines[] = [$f['label'], (string) ($values[$name] ?? '')];
        }
        $lines[] = [__('Downloaded by'), $this->user()->name.' · '.format_date(now(), 'd M Y H:i')];

        Csv::download($this->type.'-'.$id.'.csv', [__('Field'), __('Value')], $lines);
    }

    /* ------------------------------------------------------------- helpers */

    protected function meta(): array
    {
        return [
            'resource' => $this->resource, 'base' => $this->base, 'singular' => $this->singular,
            'plural' => $this->plural, 'icon' => $this->icon, 'wizard' => $this->wizard,
        ];
    }

    /** Drop form section headings (entries with 'section'). */
    protected function real(array $fields): array
    {
        return array_filter($fields, fn ($f) => ! isset($f['section']));
    }

    protected function validateFields(array $fields, array $input, ?array $existing = null): array
    {
        $fields = $this->real($fields);
        $rules  = [];
        $labels = [];
        foreach ($fields as $name => $f) {
            if (! empty($f['readonly'])) {
                continue;
            }
            $r = $f['rules'] ?? 'nullable';
            if (($f['type'] ?? '') === 'checkbox') {
                $r = 'boolean';
            } elseif (isset($f['options']) && ! str_contains($r, 'in:')) {
                $r .= '|in:'.implode(',', array_map('strval', array_keys($f['options'])));
            }
            $rules[$name]  = $r;
            $labels[$name] = locale() === 'en' ? mb_strtolower($f['label']) : $f['label'];
        }

        $clean = Validator::validate($input, $rules, $labels);
        foreach ($fields as $name => $f) {
            if (($f['type'] ?? '') === 'richtext' && array_key_exists($name, $clean)) {
                $clean[$name] = Html::clean($clean[$name]);
            }
        }

        return $clean;
    }

    protected function tableData(array $fields, array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (! isset($fields[$k]) || ($fields[$k]['table'] ?? true)) {
                $out[$k] = $v === '' ? null : $v;
            }
        }

        return $out;
    }

    /** Human-readable values (select labels, yes/no) for logs, CSV and the detail page. */
    protected function displayValues(array $fields, array $row): array
    {
        $fields = $this->real($fields);
        $out    = [];
        foreach ($fields as $name => $f) {
            $v = $row[$name] ?? null;
            if (isset($f['display'])) {
                $v = ($f['display'])($row);
            } elseif (($f['type'] ?? '') === 'checkbox') {
                $v = filter_var($v, FILTER_VALIDATE_BOOL) ? __('Yes') : __('No');
            } elseif (isset($f['options']) && $v !== null && $v !== '') {
                $v = $f['options'][$v] ?? $f['all_options'][$v] ?? $v;
                $v = is_array($v) ? $v['label'] : $v;
            } elseif (($f['type'] ?? '') === 'richtext') {
                $v = Html::text((string) $v);
            } elseif (($f['type'] ?? '') === 'password') {
                continue;
            }
            $out[$name] = $v;
        }

        return $out;
    }
}
