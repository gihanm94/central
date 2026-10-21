<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Core\Support\DB;
use App\Core\Support\Ui;

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
                         count(*) AS line_count, max(i.seller) AS seller, max(i.seller_id) AS seller_id, max(i.seller_name) AS seller_name,
                         max(i.app_department_id) AS app_department_id, max(i.app_department_name) AS app_department_name,
                         max(i.department_code) AS department_code, max(i.department_name) AS department_name,
                         sum(coalesce(i.invoiced_quantity, 0) * coalesce(i.price, 0) * (1 - coalesce(i.discount, 0) / 100)) AS amount,
                         bool_or(coalesce(o.is_credit, false) OR i.invoice_number::text LIKE '4%') AS is_credit
                  FROM erp_invoices i LEFT JOIN erp_customers c ON c.id = i.customer_id LEFT JOIN erp_orders o ON o.id = i.customer_order_id
                  WHERE i.invoice_number IS NOT NULL GROUP BY i.invoice_number) t";
    }

    protected function label(array $row): string { return (string) $row['invoice_number']; }
    protected function searchable(): array { return ['t.invoice_number', 't.customer_code', 't.customer_name', 't.customer_order_number', 't.seller', 't.seller_name', 't.department_code', 't.department_name', 't.app_department_name']; }

    protected function filters(): array
    {
        $deps = array_column(DB::select('SELECT DISTINCT app_department_id::text AS id, app_department_name AS name FROM erp_invoices WHERE app_department_id IS NOT NULL ORDER BY 2', [], $this->conn), 'name', 'id');
        $codes = [];
        foreach (DB::select("SELECT department_code AS code, max(department_name) AS name FROM erp_invoices WHERE coalesce(department_code, '') <> '' GROUP BY 1 ORDER BY 1", [], $this->conn) as $d) {
            $codes[$d['code']] = $d['code'].($d['name'] ? ' · '.$d['name'] : '');
        }

        return [
            'credit'     => ['label' => __('Invoices and credit notes'), 'options' => ['0' => __('Invoices'), '1' => __('Credit notes')], 'column' => 't.is_credit', 'bind' => fn ($v) => $v === '1' ? 'true' : 'false'],
            'erp_dept'   => ['label' => __('ERP department'), 'options' => $codes + ['none' => __('No department')],
                             'sql' => "t.department_code IS NOT DISTINCT FROM nullif(?, 'none')"],                // 'none' → invoices without one
            'department' => ['label' => __('Department (this app)'), 'options' => $deps + ['none' => __('No department')],
                             'sql' => "t.app_department_id::text IS NOT DISTINCT FROM nullif(?, 'none')"],
        ];
    }

    /** Invoices (and sellers) synced before seller and department were stamped on them get them the first time the list is opened. */
    public function index(): string
    {
        if (DB::scalar("SELECT 1 FROM erp_sellers s JOIN erp_departments d ON d.id = s.department_id WHERE s.department_code IS DISTINCT FROM nullif(trim(d.code), '') LIMIT 1", [], $this->conn)
            || DB::scalar("SELECT 1 FROM erp_invoices i JOIN erp_sellers s ON upper(trim(i.seller)) = upper(trim(s.employee_number)) AND trim(coalesce(s.employee_number, '')) <> ''
                           WHERE i.seller_id IS NULL OR i.department_code IS DISTINCT FROM s.department_code LIMIT 1", [], $this->conn)) {
            \App\Modules\Accounting\Support\SellerStamp::quietly();
        }

        return parent::index();
    }

    protected function detail(): array
    {
        return ['invoice_number' => ['Invoice number', 'text'], 'invoice_date' => ['Invoice date', 'date'], 'customer_code' => ['Customer code', 'text'], 'customer_name' => ['Customer', 'text'],
            'customer_order_number' => ['Order number', 'text'], 'seller_name' => ['Seller', 'text'], 'seller' => ['Employee number', 'text'], 'department_code' => ['ERP department code', 'text'], 'department_name' => ['ERP department', 'text'], 'app_department_name' => ['Department (this app)', 'text'], 'currency_code' => ['Currency', 'text'], 'exchange_rate' => ['Exchange rate', 'number'],
            'line_count' => ['Lines', 'number'], 'amount' => ['Amount (before VAT)', 'number'], 'is_credit' => ['Credit note', 'checkbox']];
    }

    protected function columns(): array
    {
        return [
            'invoice'  => ['label' => __('Invoice'), 'primary' => true, 'sort' => 't.invoice_number', 'render' => fn ($r) => Ui::person((string) $r['invoice_number'], (string) $r['customer_order_number'], null, '/accounting/invoices/'.$r['id'])],
            'date'     => ['label' => __('Date'), 'sort' => 't.invoice_date', 'render' => fn ($r) => self::date($r['invoice_date'])],
            'customer' => ['label' => __('Customer'), 'sort' => 't.customer_name', 'render' => fn ($r) => '<span class="block max-w-[14rem] truncate">'.e($r['customer_name'] ?: '—').'</span><span class="block text-xs text-steel">'.e($r['customer_code']).'</span>'],
            'seller'   => ['label' => __('Seller'), 'sort' => 't.seller_name', 'render' => fn ($r) => $r['seller_name']
                ? '<a href="'.url('/accounting/sellers/'.(int) $r['seller_id']).'" class="hover:text-signal-700">'.e($r['seller_name']).'</a><span class="block text-xs text-steel">'.e($r['seller']).'</span>'
                : e($r['seller'] ?: '—')],
            'department' => ['label' => __('Department'), 'sort' => 't.department_code', 'render' => fn ($r) => $r['department_code']
                ? Ui::badge((string) $r['department_code'], 'neutral').($r['department_name'] ? ' <span class="text-xs text-steel">'.e($r['department_name']).'</span>' : '')
                : '<span class="text-graphite-400">—</span>'],
            'app_department' => ['label' => __('Department (this app)'), 'sort' => 't.app_department_name', 'render' => fn ($r) => $r['app_department_name'] ? e($r['app_department_name']) : '<span class="text-graphite-400">—</span>'],
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
