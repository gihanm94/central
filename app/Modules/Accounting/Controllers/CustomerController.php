<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\CRM\Support\Ui;

/** Customers copied from the ERP. */
class CustomerController extends ErpListController
{
    protected string $resource = 'accounting_customers';
    protected string $table = 'erp_customers';
    protected string $type = 'customer';
    protected string $singular = 'customer';
    protected string $plural = 'customers';
    protected string $base = '/accounting/customers';
    protected string $icon = 'users';
    protected string $orderBy = 't.id DESC';

    protected function select(): string
    {
        return "SELECT t.*, concat_ws(' ', a.addressee, a.field1, a.field2, a.field3, a.locality, a.region, a.postal_code) AS address,
                       nullif(trim(concat_ws(' ', s.first_name, s.last_name)), '') AS seller_name,
                       (SELECT count(DISTINCT i.invoice_number) FROM erp_invoices i WHERE i.customer_id = t.id) AS invoice_count
                FROM erp_customers t LEFT JOIN erp_addresses a ON a.id = t.mailing_address_id LEFT JOIN erp_sellers s ON s.id = t.seller_id";
    }

    protected function label(array $row): string { return (string) ($row['name'] ?? '#'.$row['id']); }
    protected function searchable(): array { return ['t.name', 't.code', 't.alternative_name', 't.vat_number', 't.corporation_identification_number']; }

    protected function detail(): array
    {
        return ['id' => ['ID', 'text'], 'code' => ['Code', 'text'], 'name' => ['Name', 'text'], 'alternative_name' => ['Alternative name', 'text'], 'vat_number' => ['VAT number', 'text'],
            'corporation_identification_number' => ['Corporation ID', 'text'], 'address' => ['Address', 'text'], 'seller_name' => ['Seller', 'text'], 'credit_limit' => ['Credit limit', 'number'],
            'is_private_customer' => ['Private customer', 'checkbox'], 'invoice_count' => ['Invoices', 'number']];
    }

    protected function columns(): array
    {
        return [
            'name'    => ['label' => __('Customer'), 'primary' => true, 'sort' => 't.name', 'render' => fn ($r) => Ui::person((string) $r['name'], (string) $r['code'], null, '/accounting/customers/'.$r['id'])],
            'vat'     => ['label' => __('VAT number'), 'sort' => 't.vat_number', 'render' => fn ($r) => e($r['vat_number'] ?: '—')],
            'address' => ['label' => __('Address'), 'render' => fn ($r) => '<span class="block max-w-xs truncate text-steel" title="'.e($r['address']).'">'.e($r['address'] ?: '—').'</span>'],
            'seller'  => ['label' => __('Seller'), 'sort' => 'seller_name', 'render' => fn ($r) => e($r['seller_name'] ?: '—')],
            'invoices' => ['label' => __('Invoices'), 'sort' => 'invoice_count', 'render' => fn ($r) => '<span class="tabular-nums">'.(int) $r['invoice_count'].'</span>'],
            'credit'  => ['label' => __('Credit limit'), 'sort' => 't.credit_limit', 'render' => fn ($r) => self::money($r['credit_limit'])],
        ];
    }
}
