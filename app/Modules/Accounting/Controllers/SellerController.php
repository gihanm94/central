<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Core\Support\DB;
use App\Core\Support\Ui;

/**
 * Sellers (persons) copied from the ERP. The ERP columns are read-only here; e-mail, user and Lark id are filled in by hand
 * and no sync ever writes to them (even with Overwrite on).
 */
class SellerController extends ErpListController
{
    protected ?string $syncKey = 'seller';
    protected string $resource = 'accounting_sellers';
    protected string $table = 'erp_sellers';
    protected string $type = 'seller';
    protected string $singular = 'seller';
    protected string $plural = 'sellers';
    protected string $base = '/accounting/sellers';
    protected string $icon = 'users';
    protected string $orderBy = 't.id DESC';

    protected function select(): string
    {
        return "SELECT t.*, trim(coalesce(t.first_name, '') || ' ' || coalesce(t.last_name, '')) AS name, coalesce(t.email, t.email_address) AS mail_shown,
                       d.name AS department
                FROM erp_sellers t LEFT JOIN erp_departments d ON d.id = t.department_id";
    }

    /** the system account lives in the user database, so its name is looked up here */
    protected function hydrate(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($r) => (int) ($r['user_id'] ?? 0), $rows))));
        $names = $ids ? array_column(DB::select('SELECT id, name FROM users WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).')', $ids, 'core'), 'name', 'id') : [];
        $deps = self::departments();
        foreach ($rows as &$r) {
            $r['user_name'] = $names[(int) ($r['user_id'] ?? 0)] ?? null;
            $r['app_department'] = $deps[(string) ($r['app_department_id'] ?? '')] ?? null;
        }

        return $rows;
    }

    /** the e-mail, the person's account and the Lark id are the only things edited here */
    protected function canModify(array $row, string $action): bool { return $action === 'edit'; }

    protected function label(array $row): string { return (string) ($row['name'] ?: '#'.$row['id']); }
    protected function searchable(): array { return ["trim(coalesce(t.first_name, '') || ' ' || coalesce(t.last_name, ''))", 't.employee_number', 't.email', 't.email_address', 't.lark_id', 't.phone_number']; }

    /** @return array<string,string> departments of this app (core database) */
    private static function departments(): array
    {
        return array_column(DB::select('SELECT id::text AS id, name FROM departments WHERE deleted_at IS NULL ORDER BY name', [], 'core'), 'name', 'id');
    }

    /** A seller got a department: its Inets that have none yet take it (newer ones get it when generated);
        the department's name is kept on the seller and the seller's invoices take both. */
    protected function saved(int $id, array $data, ?array $existing): void
    {
        $name = ! empty($data['app_department_id']) ? (self::departments()[(string) $data['app_department_id']] ?? null) : null;
        DB::exec('UPDATE erp_sellers SET app_department_name = ? WHERE id = ?', [$name, $id], 'accounting');
        \App\Modules\Accounting\Support\SellerStamp::quietly();
        if (! empty($data['app_department_id'])) {
            DB::exec('UPDATE inets i SET app_department_id = ? FROM erp_orders o WHERE o.id = i.order_id AND o.seller_id = ? AND i.app_department_id IS NULL',
                [(int) $data['app_department_id'], $id], 'accounting');
        }
    }

    private function users(): array
    {
        $out = ['' => '—'];
        foreach (DB::select('SELECT id, name FROM users WHERE deleted_at IS NULL ORDER BY name', [], 'core') as $u) { $out[(string) $u['id']] = (string) $u['name']; }

        return $out;
    }

    protected function fields(?array $row): array
    {
        return [
            'sec_erp'         => ['section' => __('From the ERP (read only)')],
            'name'            => ['label' => __('Name'), 'type' => 'text', 'readonly' => true, 'table' => false],
            'employee_number' => ['label' => __('Employee number'), 'type' => 'text', 'readonly' => true],
            'phone_number'    => ['label' => __('Phone'), 'type' => 'text', 'readonly' => true],
            'email_address'   => ['label' => __('E-mail in the ERP'), 'type' => 'text', 'readonly' => true],
            'department'      => ['label' => __('Department'), 'type' => 'text', 'readonly' => true, 'table' => false],
            'sec_manual'      => ['section' => __('Filled in here')],
            'email'           => ['label' => __('E-mail'), 'type' => 'email', 'rules' => 'nullable|email|max:190', 'help' => __('Used to reach the seller. Never overwritten by a sync.')],
            'user_id'         => ['label' => __('User (system account)'), 'type' => 'select', 'options' => $this->users(), 'rules' => 'nullable'],
            'app_department_id' => ['label' => __('Department (this app)'), 'type' => 'select', 'options' => ['' => '—'] + self::departments(), 'rules' => 'nullable',
                'help' => __('Inets of this seller get this department when they are generated; people of that department see the stamped ones under ERP → Invoices. Invoices of this seller (by employee number) show it too. Never overwritten by a sync.')],
            'lark_id'         => ['label' => __('Lark ID'), 'type' => 'text', 'rules' => 'nullable|max:120'],
        ];
    }

    protected function detail(): array
    {
        return ['name' => ['Name', 'text'], 'employee_number' => ['Employee number', 'text'], 'phone_number' => ['Phone', 'text'], 'email_address' => ['E-mail in the ERP', 'text'],
            'department' => ['Department', 'text'], 'email' => ['E-mail', 'text'], 'user_name' => ['User', 'text'], 'app_department' => ['Department (this app)', 'text'], 'lark_id' => ['Lark ID', 'text']];
    }

    protected function columns(): array
    {
        return [
            'name'  => ['label' => __('Seller'), 'primary' => true, 'sort' => 'name', 'render' => fn ($r) => Ui::person((string) $r['name'], (string) $r['employee_number'], null, '/accounting/sellers/'.$r['id'])],
            'dept'  => ['label' => __('Department'), 'render' => fn ($r) => e($r['department'] ?: '—')],
            'app_dept' => ['label' => __('Department (this app)'), 'render' => fn ($r) => $r['app_department'] ? e($r['app_department']) : '<span class="text-graphite-400">—</span>'],
            'email' => ['label' => __('E-mail'), 'sort' => 'mail_shown', 'render' => fn ($r) => e($r['mail_shown'] ?: '—')],
            'user'  => ['label' => __('User'), 'render' => fn ($r) => $r['user_name'] ? e($r['user_name']) : '<span class="text-graphite-400">—</span>'],
            'lark'  => ['label' => __('Lark ID'), 'render' => fn ($r) => e($r['lark_id'] ?: '—')],
            'phone' => ['label' => __('Phone'), 'render' => fn ($r) => e($r['phone_number'] ?: '—')],
        ];
    }
}
