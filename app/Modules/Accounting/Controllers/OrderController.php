<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\Ui;

/** Customer orders copied from the ERP. "Invoiced" = at least one invoice line points to the order (by id or order number). */
class OrderController extends ErpListController
{
    /** order kinds counted at the top: the letters an order number starts with (SO6910-001 → SO) */
    private const KINDS = ['SO', 'SA', 'TD'];
    private const PREFIX = "upper(substring(t.order_number FROM '^[A-Za-z]+'))";
    private const INVOICED = '(EXISTS (SELECT 1 FROM erp_invoices i WHERE i.customer_order_id = t.id)
                               OR EXISTS (SELECT 1 FROM erp_invoices i WHERE i.customer_order_number = t.order_number AND t.order_number IS NOT NULL))';

    protected ?string $syncKey = 'order';
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
        return "SELECT t.*, ".self::INVOICED." AS is_invoiced, ".self::PREFIX." AS kind,
                       (SELECT count(DISTINCT i.invoice_number) FROM erp_invoices i WHERE i.customer_order_id = t.id OR (i.customer_order_number = t.order_number AND t.order_number IS NOT NULL)) AS invoice_count,
                       c.name AS customer_name, c.code AS customer_code, nullif(trim(concat_ws(' ', s.first_name, s.last_name)), '') AS seller_name,
                       (SELECT count(*) FROM erp_order_rows r WHERE r.parent_order_id = t.id) AS row_count,
                       (SELECT sum(coalesce(r.ordered_quantity, 0) * coalesce(r.price, 0) * (1 - coalesce(r.discount, 0) / 100)) FROM erp_order_rows r WHERE r.parent_order_id = t.id) AS total
                FROM erp_orders t LEFT JOIN erp_customers c ON c.id = t.customer_id LEFT JOIN erp_sellers s ON s.id = t.seller_id";
    }

    protected function label(array $row): string { return (string) ($row['order_number'] ?? '#'.$row['id']); }
    protected function searchable(): array { return ['t.order_number', 't.business_contact_order_number', 't.vat_number', 'c.name', 'c.code']; }

    protected function filters(): array
    {
        $kinds = array_column(DB::select("SELECT DISTINCT upper(substring(order_number FROM '^[A-Za-z]+')) AS k FROM erp_orders WHERE order_number ~ '^[A-Za-z]' ORDER BY 1", [], $this->conn), 'k');

        return [
            'credit'   => ['label' => __('Orders and credits'), 'options' => ['0' => __('Orders'), '1' => __('Credit orders')], 'column' => 't.is_credit', 'bind' => fn ($v) => $v === '1' ? 'true' : 'false'],
            'invoiced' => ['label' => __('Invoiced'), 'options' => ['1' => __('Yes'), '0' => __('No')], 'sql' => self::INVOICED.' = (?::int = 1)'],
            'kind'     => ['label' => __('Order type'), 'options' => array_combine($kinds, $kinds) ?: [], 'sql' => self::PREFIX.' = ?'],
        ];
    }

    /** SO / SA / TD orders and orders without an invoice (all orders; a card filters the list). */
    protected function stats(): array
    {
        $n = DB::first('SELECT '.implode(', ', array_map(fn ($k) => 'count(*) FILTER (WHERE '.self::PREFIX." = '{$k}') AS ".strtolower($k), self::KINDS))
            .', count(*) FILTER (WHERE NOT '.self::INVOICED.') AS no_invoice, count(*) AS total FROM erp_orders t', [], $this->conn);
        $tone = ['SO' => 'info', 'SA' => 'violet', 'TD' => 'success'];
        $on = fn (string $f, string $v) => (string) Request::query($f, '') === $v;
        $cards = [];
        foreach (self::KINDS as $k) {
            $cards[] = ['label' => __(':kind orders', ['kind' => $k]), 'value' => number_format((int) $n[strtolower($k)]), 'sub' => $on('kind', $k) ? __('Showing these') : __('Click to show'),
                'tone' => $tone[$k], 'icon' => 'cart', 'href' => $on('kind', $k) ? '/accounting/orders' : '/accounting/orders?kind='.$k];
        }
        $cards[] = ['label' => __('No invoice'), 'value' => number_format((int) $n['no_invoice']), 'sub' => __('of :n orders', ['n' => number_format((int) $n['total'])]),
            'tone' => (int) $n['no_invoice'] ? 'warn' : 'neutral', 'icon' => 'doc', 'href' => $on('invoiced', '0') ? '/accounting/orders' : '/accounting/orders?invoiced=0'];

        return $cards;
    }

    protected function detail(): array
    {
        return ['id' => ['ID', 'text'], 'order_number' => ['Order number', 'text'], 'business_contact_order_number' => ['Customer PO', 'text'], 'order_date' => ['Order date', 'date'],
            'customer_code' => ['Customer code', 'text'], 'customer_name' => ['Customer', 'text'], 'vat_number' => ['VAT number', 'text'], 'seller_name' => ['Seller', 'text'],
            'is_credit' => ['Credit order', 'checkbox'], 'is_invoiced' => ['Invoiced', 'checkbox'], 'invoice_count' => ['Invoices', 'number'], 'status' => ['Status', 'number'], 'row_count' => ['Rows', 'number'], 'total' => ['Total (before VAT)', 'number']];
    }

    protected function columns(): array
    {
        return [
            'order'    => ['label' => __('Order'), 'primary' => true, 'sort' => 't.order_number', 'render' => fn ($r) => Ui::person((string) $r['order_number'], (string) $r['business_contact_order_number'], null, '/accounting/orders/'.$r['id'])],
            'invoiced' => ['label' => __('Invoiced'), 'sort' => 'is_invoiced', 'render' => fn ($r) => filter_var($r['is_invoiced'], FILTER_VALIDATE_BOOL)
                ? Ui::badge(__('Yes'), 'success').((int) $r['invoice_count'] > 1 ? ' <span class="text-xs text-steel">×'.(int) $r['invoice_count'].'</span>' : '')
                : Ui::badge(__('No'), 'warn')],
            'date'     => ['label' => __('Date'), 'sort' => 't.order_date', 'render' => fn ($r) => self::date($r['order_date'])],
            'customer' => ['label' => __('Customer'), 'sort' => 'c.name', 'render' => fn ($r) => '<span class="block max-w-[14rem] truncate">'.e($r['customer_name'] ?: '—').'</span><span class="block text-xs text-steel">'.e($r['customer_code']).'</span>'],
            'vat'      => ['label' => __('VAT number'), 'render' => fn ($r) => e($r['vat_number'] ?: '—')],
            'seller'   => ['label' => __('Seller'), 'render' => fn ($r) => e($r['seller_name'] ?: '—')],
            'rows'     => ['label' => __('Rows'), 'sort' => 'row_count', 'render' => fn ($r) => '<span class="tabular-nums">'.(int) $r['row_count'].'</span>'],
            'total'    => ['label' => __('Total'), 'sort' => 'total', 'render' => fn ($r) => self::money($r['total'])],
            'type'     => ['label' => __('Type'), 'render' => fn ($r) => filter_var($r['is_credit'], FILTER_VALIDATE_BOOL) ? Ui::badge(__('Credit'), 'warn') : Ui::badge(__('Order'), 'neutral')],
        ];
    }

    protected function syncOne(\App\Modules\Accounting\Erp\Syncer $s, int $id): int
    {
        $n = $s->run('order', 'one', (string) $id, null, null, true)['saved'];

        return $n + $s->run('order_row', 'all', null, null, "ParentOrderId eq '".$id."'", true)['saved'];
    }
}
