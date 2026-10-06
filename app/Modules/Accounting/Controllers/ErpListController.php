<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Core\Http\Controllers\ResourceController;
use App\Core\Support\Csv;

/**
 * Read-only lists of data copied from the ERP (same table screen as everywhere: search, sort, columns, page size, export).
 * Nothing here can be added, edited or deleted — the ERP is the source.
 */
abstract class ErpListController extends ResourceController
{
    protected string $conn = 'accounting';
    protected string $module = 'accounting';
    protected bool $softDelete = false;
    protected string $icon = 'calc';
    protected int $perPage = 10;

    /** column key => [label, type] for the detail page */
    abstract protected function detail(): array;

    /** ERP table key (config/erp.php) this list can refresh one record of, or null */
    protected ?string $syncKey = null;

    protected function rowLinks(array $row): array
    {
        return $this->syncKey && $this->user()->isAdmin()
            ? [['url' => $this->base.'/'.$row['id'].'/sync', 'label' => __('Sync from ERP'), 'icon' => 'bolt', 'post' => true]] : [];
    }

    /** Read this one record from the ERP again and update it (even when the table's Overwrite is off). */
    public function syncRow(int $id): never
    {
        $this->user()->isAdmin() || abort(403);
        $this->syncKey || abort(404);
        try {
            $s = new \App\Modules\Accounting\Erp\Syncer();
            $r = $this->syncOne($s, $id);
            \App\Core\Support\Session::flash('success', __('Read from the ERP: :n row(s) updated.', ['n' => $r]));
        } catch (\Throwable $e) {
            \App\Modules\Accounting\Support\Log::exception('sync', $e, $this->syncKey.' #'.$id.' sync failed');
            \App\Core\Support\Session::flash('error', $e->getMessage());
        }
        redirect($this->base);
    }

    protected function syncOne(\App\Modules\Accounting\Erp\Syncer $s, int $id): int
    {
        return $s->run($this->syncKey, 'one', (string) $id, null, null, true)['saved'];
    }

    protected function scopeSql(): array { return ['sql' => 'TRUE', 'params' => []]; }

    protected function canModify(array $row, string $action): bool { return false; }

    protected function meta(): array { return parent::meta() + ['readonly' => true]; }

    protected function fields(?array $row): array
    {
        $out = [];
        foreach ($this->detail() as $k => [$label, $type]) { $out[$k] = ['label' => __($label), 'type' => $type]; }

        return $out;
    }

    /** CSV of whatever the list shows now (filters and search kept). */
    public function export(): never
    {
        $this->authorize($this->resource, 'export');
        $rows = $this->query(false)['rows'];
        $cols = array_keys($this->detail());
        Csv::download($this->plural.'-'.date('Ymd-His').'.csv', array_map(fn ($k) => $this->detail()[$k][0], $cols),
            array_map(fn ($r) => array_map(fn ($k) => $r[$k] ?? '', $cols), $rows));
    }

    protected static function money(mixed $v, ?string $cur = null): string
    {
        return $v === null || $v === '' ? '<span class="text-graphite-400">—</span>' : '<span class="tabular-nums">'.e(number_format((float) $v, 2).($cur ? ' '.$cur : '')).'</span>';
    }

    protected static function date(?string $v): string { return $v ? '<span class="tabular-nums text-steel">'.e(format_date($v, 'd M Y')).'</span>' : '<span class="text-graphite-400">—</span>'; }
}
