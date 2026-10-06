<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\CRM\Support\Ui;

/** Invoices (one row per invoice number, added up from the ERP invoice log). */
class InvoiceController extends ErpListController
{
    protected ?string $syncKey = 'invoice';
    protected string $resource = 'accounting_invoices';
    protected string $table = 'erp_invoices';
    protected string $type = 'invoice';
    protected string $singular = 'invoice';
    protected string $plural = 'invoices';
    protected string $base = '/accounting/invoices';
    protected string $icon = 'doc';
    protected string $orderBy = 't.id DESC';

    /** id = invoice number */
    protected function select(): string
    {
        return "SELECT t.* FROM (
                  SELECT i.invoice_number AS id, i.invoice_number, max(i.invoice_date) AS invoice_date, max(i.customer_id) AS customer_id, max(i.customer_code) AS customer_code,
                         max(c.name) AS customer_name, max(i.customer_order_number) AS customer_order_number, max(i.currency_code) AS currency_code, max(i.exchange_rate) AS exchange_rate,
                         count(*) AS line_count, max(i.seller) AS seller,
                         sum(coalesce(i.invoiced_quantity, 0) * coalesce(i.price, 0) * (1 - coalesce(i.discount, 0) / 100)) AS amount,
                         bool_or(coalesce(o.is_credit, false) OR i.invoice_number::text LIKE '4%') AS is_credit
                  FROM erp_invoices i LEFT JOIN erp_customers c ON c.id = i.customer_id LEFT JOIN erp_orders o ON o.id = i.customer_order_id
                  WHERE i.invoice_number IS NOT NULL GROUP BY i.invoice_number) t";
    }

    protected function label(array $row): string { return (string) $row['invoice_number']; }
    protected function searchable(): array { return ['t.invoice_number', 't.customer_code', 't.customer_name', 't.customer_order_number']; }

    protected function filters(): array
    {
        return ['credit' => ['label' => __('Invoices and credit notes'), 'options' => ['0' => __('Invoices'), '1' => __('Credit notes')], 'column' => 't.is_credit', 'bind' => fn ($v) => $v === '1' ? 'true' : 'false']];
    }

    protected function detail(): array
    {
        return ['invoice_number' => ['Invoice number', 'text'], 'invoice_date' => ['Invoice date', 'date'], 'customer_code' => ['Customer code', 'text'], 'customer_name' => ['Customer', 'text'],
            'customer_order_number' => ['Order number', 'text'], 'seller' => ['Seller', 'text'], 'currency_code' => ['Currency', 'text'], 'exchange_rate' => ['Exchange rate', 'number'],
            'line_count' => ['Lines', 'number'], 'amount' => ['Amount (before VAT)', 'number'], 'is_credit' => ['Credit note', 'checkbox']];
    }

    protected function columns(): array
    {
        return [
            'invoice'  => ['label' => __('Invoice'), 'primary' => true, 'sort' => 't.invoice_number', 'render' => fn ($r) => Ui::person((string) $r['invoice_number'], (string) $r['customer_order_number'], null, '/accounting/invoices/'.$r['id'])],
            'date'     => ['label' => __('Date'), 'sort' => 't.invoice_date', 'render' => fn ($r) => self::date($r['invoice_date'])],
            'customer' => ['label' => __('Customer'), 'sort' => 't.customer_name', 'render' => fn ($r) => '<span class="block max-w-[14rem] truncate">'.e($r['customer_name'] ?: '—').'</span><span class="block text-xs text-steel">'.e($r['customer_code']).'</span>'],
            'seller'   => ['label' => __('Seller'), 'render' => fn ($r) => e($r['seller'] ?: '—')],
            'lines'    => ['label' => __('Lines'), 'sort' => 't.line_count', 'render' => fn ($r) => '<span class="tabular-nums">'.(int) $r['line_count'].'</span>'],
            'amount'   => ['label' => __('Amount'), 'sort' => 't.amount', 'render' => fn ($r) => self::money($r['amount'], $r['currency_code'] ?: null)],
            'type'     => ['label' => __('Type'), 'render' => fn ($r) => filter_var($r['is_credit'], FILTER_VALIDATE_BOOL) ? Ui::badge(__('Credit note'), 'warn') : Ui::badge(__('Invoice'), 'neutral')],
        ];
    }

    /** the id of this list is the invoice number */
    protected function syncOne(\App\Modules\Accounting\Erp\Syncer $s, int $id): int
    {
        return $s->run('invoice', 'all', null, null, 'InvoiceNumber eq '.$id, true)['saved'];
    }
}
