<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Core\Support\DB;
use App\Modules\CRM\Support\Ui;

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
        foreach ($rows as &$r) { $r['user_name'] = $names[(int) ($r['user_id'] ?? 0)] ?? null; }

        return $rows;
    }

    /** the e-mail, the person's account and the Lark id are the only things edited here */
    protected function canModify(array $row, string $action): bool { return $action === 'edit'; }

    protected function label(array $row): string { return (string) ($row['name'] ?: '#'.$row['id']); }
    protected function searchable(): array { return ["trim(coalesce(t.first_name, '') || ' ' || coalesce(t.last_name, ''))", 't.employee_number', 't.email', 't.email_address', 't.lark_id', 't.phone_number']; }

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
            'lark_id'         => ['label' => __('Lark ID'), 'type' => 'text', 'rules' => 'nullable|max:120'],
        ];
    }

    protected function detail(): array
    {
        return ['name' => ['Name', 'text'], 'employee_number' => ['Employee number', 'text'], 'phone_number' => ['Phone', 'text'], 'email_address' => ['E-mail in the ERP', 'text'],
            'department' => ['Department', 'text'], 'email' => ['E-mail', 'text'], 'user_name' => ['User', 'text'], 'lark_id' => ['Lark ID', 'text']];
    }

    protected function columns(): array
    {
        return [
            'name'  => ['label' => __('Seller'), 'primary' => true, 'sort' => 'name', 'render' => fn ($r) => Ui::person((string) $r['name'], (string) $r['employee_number'], null, '/accounting/sellers/'.$r['id'])],
            'dept'  => ['label' => __('Department'), 'render' => fn ($r) => e($r['department'] ?: '—')],
            'email' => ['label' => __('E-mail'), 'sort' => 'mail_shown', 'render' => fn ($r) => e($r['mail_shown'] ?: '—')],
            'user'  => ['label' => __('User'), 'render' => fn ($r) => $r['user_name'] ? e($r['user_name']) : '<span class="text-graphite-400">—</span>'],
            'lark'  => ['label' => __('Lark ID'), 'render' => fn ($r) => e($r['lark_id'] ?: '—')],
            'phone' => ['label' => __('Phone'), 'render' => fn ($r) => e($r['phone_number'] ?: '—')],
        ];
    }
}
