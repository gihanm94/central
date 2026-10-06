<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\CRM\Support\Ui;

/** Customer orders copied from the ERP. */
class OrderController extends ErpListController
{
    protected string $resource = 'accounting_orders';
    protected string $table = 'erp_orders';
    protected string $type = 'order';
    protected string $singular = 'order';
    protected string $plural = 'orders';
    protected string $base = '/accounting/orders';
    protected string $icon = 'cart';
    protected string $orderBy = 't.id DESC';

    protected function select(): string
    {
        return "SELECT t.*, c.name AS customer_name, c.code AS customer_code, nullif(trim(concat_ws(' ', s.first_name, s.last_name)), '') AS seller_name,
                       (SELECT count(*) FROM erp_order_rows r WHERE r.parent_order_id = t.id) AS row_count,
                       (SELECT sum(coalesce(r.ordered_quantity, 0) * coalesce(r.price, 0) * (1 - coalesce(r.discount, 0) / 100)) FROM erp_order_rows r WHERE r.parent_order_id = t.id) AS total
                FROM erp_orders t LEFT JOIN erp_customers c ON c.id = t.customer_id LEFT JOIN erp_sellers s ON s.id = t.seller_id";
    }

    protected function label(array $row): string { return (string) ($row['order_number'] ?? '#'.$row['id']); }
    protected function searchable(): array { return ['t.order_number', 't.business_contact_order_number', 't.vat_number', 'c.name', 'c.code']; }

    protected function filters(): array
    {
        return ['credit' => ['label' => __('Orders and credits'), 'options' => ['0' => __('Orders'), '1' => __('Credit orders')], 'column' => 't.is_credit', 'bind' => fn ($v) => $v === '1' ? 'true' : 'false']];
    }

    protected function detail(): array
    {
        return ['id' => ['ID', 'text'], 'order_number' => ['Order number', 'text'], 'business_contact_order_number' => ['Customer PO', 'text'], 'order_date' => ['Order date', 'date'],
            'customer_code' => ['Customer code', 'text'], 'customer_name' => ['Customer', 'text'], 'vat_number' => ['VAT number', 'text'], 'seller_name' => ['Seller', 'text'],
            'is_credit' => ['Credit order', 'checkbox'], 'status' => ['Status', 'number'], 'row_count' => ['Rows', 'number'], 'total' => ['Total (before VAT)', 'number']];
    }

    protected function columns(): array
    {
        return [
            'order'    => ['label' => __('Order'), 'primary' => true, 'sort' => 't.order_number', 'render' => fn ($r) => Ui::person((string) $r['order_number'], (string) $r['business_contact_order_number'], null, '/accounting/orders/'.$r['id'])],
            'date'     => ['label' => __('Date'), 'sort' => 't.order_date', 'render' => fn ($r) => self::date($r['order_date'])],
            'customer' => ['label' => __('Customer'), 'sort' => 'c.name', 'render' => fn ($r) => '<span class="block max-w-[14rem] truncate">'.e($r['customer_name'] ?: '—').'</span><span class="block text-xs text-steel">'.e($r['customer_code']).'</span>'],
            'vat'      => ['label' => __('VAT number'), 'render' => fn ($r) => e($r['vat_number'] ?: '—')],
            'seller'   => ['label' => __('Seller'), 'render' => fn ($r) => e($r['seller_name'] ?: '—')],
            'rows'     => ['label' => __('Rows'), 'sort' => 'row_count', 'render' => fn ($r) => '<span class="tabular-nums">'.(int) $r['row_count'].'</span>'],
            'total'    => ['label' => __('Total'), 'sort' => 'total', 'render' => fn ($r) => self::money($r['total'])],
            'type'     => ['label' => __('Type'), 'render' => fn ($r) => filter_var($r['is_credit'], FILTER_VALIDATE_BOOL) ? Ui::badge(__('Credit'), 'warn') : Ui::badge(__('Order'), 'neutral')],
        ];
    }
}
