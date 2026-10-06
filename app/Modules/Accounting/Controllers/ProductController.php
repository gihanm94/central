<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\CRM\Support\Ui;

/** Products (parts) copied from the ERP. */
class ProductController extends ErpListController
{
    protected ?string $syncKey = 'product';
    protected string $resource = 'accounting_products';
    protected string $table = 'erp_products';
    protected string $type = 'product';
    protected string $singular = 'product';
    protected string $plural = 'products';
    protected string $base = '/accounting/products';
    protected string $icon = 'box';
    protected string $orderBy = 't.id DESC';

    protected function label(array $row): string { return (string) ($row['part_number'] ?? '#'.$row['id']); }
    protected function searchable(): array { return ['t.part_number', 't.description', 't.alias']; }

    protected function detail(): array
    {
        return ['id' => ['ID', 'text'], 'part_number' => ['Part number', 'text'], 'description' => ['Description', 'text'], 'alias' => ['Alias', 'text'],
            'standard_price' => ['Standard price', 'number'], 'part_code_id' => ['Part code', 'text'], 'part_template_id' => ['Part template', 'text']];
    }

    protected function columns(): array
    {
        return [
            'part'  => ['label' => __('Part'), 'primary' => true, 'sort' => 't.part_number', 'render' => fn ($r) => Ui::person((string) $r['part_number'], (string) $r['alias'], null, '/accounting/products/'.$r['id'])],
            'desc'  => ['label' => __('Description'), 'sort' => 't.description', 'render' => fn ($r) => '<span class="block max-w-md truncate" title="'.e($r['description']).'">'.e($r['description'] ?: '—').'</span>'],
            'price' => ['label' => __('Standard price'), 'sort' => 't.standard_price', 'render' => fn ($r) => self::money($r['standard_price'])],
            'code'  => ['label' => __('Part code'), 'render' => fn ($r) => e($r['part_code_id'] ?: '—')],
        ];
    }
}
