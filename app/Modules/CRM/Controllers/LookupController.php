<?php
declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Core\Http\Controllers\ResourceController;
use App\Core\Support\DB;
use App\Core\Support\ValidationException;

/** Industries, lead sources, products and currencies: simple lists the whole CRM draws from. Managed with the crm_settings permission. */
abstract class LookupController extends ResourceController
{
    protected string $conn = 'crm';
    protected string $module = 'crm';
    protected bool $softDelete = false;
    protected string $orderBy = 't.id';

    protected function scopeSql(): array { return ['sql' => 'TRUE', 'params' => []]; }

    /** Tabs shown above every settings screen — only the ones this person may open. */
    public static function tabs(): array
    {
        $tabs = [];
        if (can('crm_settings', 'view')) {
            foreach ([['industries', 'Industries', 'building'], ['lead-sources', 'Lead sources', 'target'], ['products', 'Products', 'box'], ['currencies', 'Currencies', 'calc']] as [$p, $l, $i]) {
                $tabs[] = ['/crm/settings/'.$p, __($l), $i];
            }
        }
        if (can('crm_stages', 'view')) { $tabs[] = ['/crm/settings/stages', __('Stages'), 'columns']; }
        if (can('crm_targets', 'view')) { $tabs[] = ['/crm/settings/targets', __('Sales targets'), 'chart']; }

        return $tabs;
    }

    protected function meta(): array { return parent::meta() + ['tabs' => self::tabs()]; }

    protected function label(array $row): string { return (string) ($row['name'] ?? $row['code'] ?? '#'.$row['id']); }

    protected function prepare(array $data, ?array $existing): array
    {
        $col = $this->uniqueColumn();
        if ($col && ! empty($data[$col])) {
            $taken = DB::scalar("SELECT 1 FROM {$this->table} WHERE lower({$col}) = lower(?)".($existing ? ' AND id <> ?' : ''), $existing ? [$data[$col], $existing['id']] : [$data[$col]], 'crm');
            if ($taken) {
                throw new ValidationException([$col => __('This :field is already used.', ['field' => mb_strtolower($this->fields(null)[$col]['label'])])]);
            }
        }
        $data['updated_by'] = $this->user()->id;
        if (! $existing) {
            $data['created_by'] = $this->user()->id;
        }

        return $data;
    }

    protected function uniqueColumn(): ?string { return 'name'; }
}
