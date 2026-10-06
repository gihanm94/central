<?php
declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Core\Support\DB;
use App\Modules\CRM\Support\Ui;

/** Registered products. Anything not listed here can still be typed on an opportunity as an "other" product. */
class ProductController extends LookupController
{
    protected string $resource = 'crm_settings';
    protected string $table = 'products';
    protected string $type = 'product';
    protected string $singular = 'product';
    protected string $plural = 'products';
    protected string $base = '/crm/settings/products';
    protected string $icon = 'box';
    protected string $orderBy = 't.name';

    protected function fields(?array $row): array
    {
        return [
            'name'        => ['label' => __('Name'), 'rules' => 'required|max:200', 'span' => 2, 'example' => 'Servo filling machine'],
            'code'        => ['label' => __('Code'), 'rules' => 'nullable|max:40', 'example' => 'SFM-100'],
            'unit'        => ['label' => __('Unit'), 'rules' => 'nullable|max:30', 'example' => 'set'],
            'unit_price'  => ['label' => __('Unit price'), 'type' => 'number', 'rules' => 'nullable|numeric', 'example' => '250000'],
            'currency'    => ['label' => __('Currency'), 'type' => 'select', 'rules' => 'required', 'default' => 'THB',
                              'options' => array_column(DB::select('SELECT code, code AS l FROM currencies ORDER BY is_base DESC, code', [], 'crm'), 'l', 'code')],
            'description' => ['label' => __('Description'), 'type' => 'textarea', 'span' => 2, 'rules' => 'nullable|max:2000'],
            'is_active'   => ['label' => __('Active'), 'type' => 'checkbox', 'default' => true, 'help' => __('Inactive products no longer appear in the opportunity form.')],
        ];
    }

    protected function uniqueColumn(): ?string { return 'code'; }

    protected function columns(): array
    {
        return [
            'name'   => ['label' => __('Product'), 'primary' => true, 'render' => fn ($r) => '<span class="font-medium">'.e($r['name']).'</span><span class="block text-xs text-steel">'.e($r['code'] ?? '').'</span>'],
            'price'  => ['label' => __('Unit price'), 'render' => fn ($r) => '<span class="tabular-nums">'.Ui::money($r['unit_price'], $r['currency']).'</span>'.($r['unit'] ? '<span class="text-xs text-steel"> / '.e($r['unit']).'</span>' : '')],
            'active' => ['label' => __('Status'), 'render' => fn ($r) => filter_var($r['is_active'], FILTER_VALIDATE_BOOL) ? Ui::badge(__('Active'), 'success') : Ui::badge(__('Inactive'))],
        ];
    }

    protected function searchable(): array { return ['t.name', 't.code']; }
}
