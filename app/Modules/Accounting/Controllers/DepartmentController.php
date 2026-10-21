<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Core\Support\DB;
use App\Core\Support\Ui;

/**
 * Departments copied from the ERP, with their sellers and this year's invoiced amount (invoice lines whose seller,
 * by employee number, belongs to the department). The app department linked to it is set in User Management → Departments.
 */
class DepartmentController extends ErpListController
{
    protected ?string $syncKey = 'department';
    protected string $resource = 'accounting_departments';
    protected string $table = 'erp_departments';
    protected string $type = 'erp_department';
    protected string $singular = 'department';
    protected string $plural = 'departments';
    protected string $base = '/accounting/departments';
    protected string $icon = 'building';
    protected string $orderBy = 't.code, t.id';

    protected function select(): string
    {
        return "SELECT t.*, p.code AS parent_code, p.name AS parent_name,
                       (SELECT count(*) FROM erp_sellers s WHERE s.department_id = t.id) AS sellers,
                       (SELECT count(DISTINCT i.invoice_number) FROM erp_invoices i JOIN erp_sellers s ON s.id = i.seller_id
                         WHERE s.department_id = t.id AND i.invoice_date >= date_trunc('year', now())) AS invoices,
                       (SELECT coalesce(sum(coalesce(i.invoiced_quantity, 0) * coalesce(i.price, 0) * (1 - coalesce(i.discount, 0) / 100) * coalesce(nullif(i.exchange_rate, 0), 1)), 0)
                          FROM erp_invoices i JOIN erp_sellers s ON s.id = i.seller_id
                         WHERE s.department_id = t.id AND i.invoice_date >= date_trunc('year', now())) AS amount
                  FROM erp_departments t LEFT JOIN erp_departments p ON p.id = t.department_id";
    }

    /** The app department linked to each ERP department lives in the core database. */
    protected function hydrate(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($r) => (int) $r['id'], $rows))));
        $app = $ids ? array_column(DB::select('SELECT erp_department_id, name FROM departments WHERE deleted_at IS NULL AND erp_department_id IN ('.implode(',', $ids).')', [], 'core'), 'name', 'erp_department_id') : [];
        foreach ($rows as &$r) { $r['app_department'] = $app[(int) $r['id']] ?? null; }

        return $rows;
    }

    protected function label(array $row): string { return trim(($row['code'] ?? '').' '.($row['name'] ?: $row['description'] ?: '#'.$row['id'])); }
    protected function searchable(): array { return ['t.code', 't.name', 't.description']; }

    protected function detail(): array
    {
        return ['id' => ['ID', 'text'], 'code' => ['Code', 'text'], 'name' => ['Name', 'text'], 'description' => ['Description', 'text'], 'parent_name' => ['Parent department', 'text'],
            'app_department' => ['Department (this app)', 'text'], 'sellers' => ['Sellers', 'number'], 'invoices' => ['Invoices this year', 'number'], 'amount' => ['Invoiced this year (THB, before VAT)', 'number']];
    }

    protected function columns(): array
    {
        return [
            'dept'     => ['label' => __('Department'), 'primary' => true, 'sort' => 't.code', 'render' => fn ($r) => Ui::person((string) ($r['name'] ?: $r['description'] ?: '#'.$r['id']), (string) $r['code'], null, '/accounting/departments/'.$r['id'])],
            'desc'     => ['label' => __('Description'), 'sort' => 't.description', 'render' => fn ($r) => '<span class="block max-w-xs truncate">'.e($r['description'] ?: '—').'</span>'],
            'parent'   => ['label' => __('Parent department'), 'render' => fn ($r) => e(trim(($r['parent_code'] ?? '').' '.($r['parent_name'] ?? '')) ?: '—')],
            'app'      => ['label' => __('Department (this app)'), 'render' => fn ($r) => $r['app_department'] ? e($r['app_department']) : '<span class="text-graphite-400">—</span>'],
            'sellers'  => ['label' => __('Sellers'), 'sort' => 'sellers', 'render' => fn ($r) => '<span class="tabular-nums">'.(int) $r['sellers'].'</span>'],
            'invoices' => ['label' => __('Invoices this year'), 'sort' => 'invoices', 'render' => fn ($r) => '<span class="tabular-nums">'.(int) $r['invoices'].'</span>'],
            'amount'   => ['label' => __('Invoiced this year'), 'sort' => 'amount', 'render' => fn ($r) => self::money($r['amount'])],
        ];
    }
}
