<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Core\Http\Controllers\ResourceController;
use App\Core\Support\DB;
use App\Core\Support\Request;
use App\Core\Support\ValidationException;

/**
 * Accounting → Management → Account report: one amount per account, department, month and type.
 * Filled mainly by import (CSV or Excel); a line that is already there is skipped, never added twice.
 * BU managers view, add and import; Management → Account report draws the charts from it.
 */
class AccountReportController extends ResourceController
{
    protected string $resource = 'accounting_reports';
    protected string $table = 'account_reports';
    protected string $type = 'account_report';
    protected string $singular = 'account line';
    protected string $plural = 'account report';
    protected string $base = '/accounting/account-report';
    protected string $icon = 'chart';
    protected string $module = 'accounting';
    protected string $conn = 'accounting';
    protected string $orderBy = 't.year DESC, t.month DESC, t.department, t.account';
    protected int $perPage = 20;

    public const KINDS = ['income' => 'Income', 'expense' => 'Expenses'];
    public const TYPES = [
        'central_cost' => 'Central cost', 'cogs' => 'Cost of goods sold', 'deposit' => 'Deposit', 'domestic_revenue' => 'Domestic revenue',
        'international_revenue' => 'International revenue', 'operating_expenses' => 'Operating expenses', 'revenue' => 'Revenue',
    ];

    /** Lines are company figures, not owned by anyone: everyone with the permission sees all of them. */
    protected function scopeSql(): array { return ['sql' => 'TRUE', 'params' => []]; }

    /** BU managers import too (the role's import box decides), not only administrators. */
    protected function mayImport(): bool { return can($this->resource, 'import'); }

    protected function meta(): array { return parent::meta() + ['selectable' => can($this->resource, 'delete')]; }

    protected function label(array $row): string { return $row['account'].' · '.$row['name']; }

    public static function kinds(): array { return array_map('__', self::KINDS); }
    public static function types(): array { return array_map('__', self::TYPES); }

    private static function months(): array
    {
        $out = [];
        for ($m = 1; $m <= 12; $m++) { $out[(string) $m] = __(date('F', mktime(0, 0, 0, $m, 1))); }

        return $out;
    }

    /** Department names of the app (User Management → Departments). */
    private static function appDepartments(): array
    {
        return array_column(DB::select('SELECT name FROM departments WHERE deleted_at IS NULL ORDER BY name', [], 'core'), 'name');
    }

    protected function fields(?array $row): array
    {
        $fields = [
            'account'    => ['label' => __('Account'), 'rules' => 'required|max:60', 'example' => '410100'],
            'name'       => ['label' => __('Name'), 'rules' => 'required|max:200', 'example' => 'Sales – local customers'],
            'amount'     => ['label' => __('Amount'), 'type' => 'number', 'rules' => 'required|numeric', 'example' => '125000.00', 'display' => fn ($r) => number_format((float) ($r['amount'] ?? 0), 2)],
            'department' => ['label' => __('Department'), 'rules' => 'required|max:120', 'example' => self::appDepartments()[0] ?? 'Sales', 'help' => __('The department name as in User Management → Departments.')],
            'kind'       => ['label' => __('Income / Expenses'), 'type' => 'select', 'options' => self::kinds(), 'rules' => 'required'],
            'year'       => ['label' => __('Year'), 'rules' => 'required|integer|max:4', 'display' => fn ($r) => (string) (int) ($r['year'] ?? 0), 'default' => date('Y'), 'example' => date('Y')],
            'month'      => ['label' => __('Month'), 'type' => 'select', 'options' => self::months(), 'rules' => 'required', 'default' => (string) (int) date('n')],
            'type'       => ['label' => __('Type'), 'type' => 'select', 'options' => self::types(), 'rules' => 'required', 'span' => 2],
        ];
        if ($row) {
            $fields['created_by_name'] = ['label' => __('Created by'), 'readonly' => true, 'import' => false, 'table' => false, 'display' => fn ($r) => trim(($r['created_by_name'] ?? '—').' · '.format_date($r['created_at'] ?? null, 'd M Y H:i'))];
            $fields['updated_by_name'] = ['label' => __('Updated by'), 'readonly' => true, 'import' => false, 'table' => false, 'display' => fn ($r) => trim(($r['updated_by_name'] ?? '—').' · '.format_date($r['updated_at'] ?? null, 'd M Y H:i'))];
        }

        return $fields;
    }

    protected function columns(): array
    {
        $kindTone = ['income' => 'bg-emerald-50 text-emerald-800', 'expense' => 'bg-signal-50 text-signal-700'];
        $months   = self::months();

        return [
            'account'    => ['label' => __('Account'), 'primary' => true, 'sort' => 't.account', 'render' => fn ($r) => '<a href="'.url($this->base.'/'.$r['id']).'" class="font-medium tabular-nums hover:text-signal-700">'.e($r['account']).'</a>'],
            'name'       => ['label' => __('Name'), 'sort' => 't.name', 'render' => fn ($r) => e($r['name'])],
            'amount'     => ['label' => __('Amount'), 'sort' => 't.amount', 'render' => fn ($r) => '<span class="block text-right font-medium tabular-nums '.($r['kind'] === 'expense' ? 'text-signal-700' : 'text-emerald-700').'">'.number_format((float) $r['amount'], 2).'</span>'],
            'department' => ['label' => __('Department'), 'sort' => 't.department', 'render' => fn ($r) => e($r['department'])],
            'kind'       => ['label' => __('Income / Expenses'), 'sort' => 't.kind', 'render' => fn ($r) => '<span class="badge '.($kindTone[$r['kind']] ?? '').'">'.e(self::kinds()[$r['kind']] ?? $r['kind']).'</span>'],
            'year'       => ['label' => __('Year'), 'sort' => 't.year', 'render' => fn ($r) => '<span class="tabular-nums">'.(int) $r['year'].'</span>'],
            'month'      => ['label' => __('Month'), 'sort' => 't.month', 'render' => fn ($r) => e($months[(string) (int) $r['month']] ?? $r['month'])],
            'type'       => ['label' => __('Type'), 'sort' => 't.type', 'render' => fn ($r) => e(self::types()[$r['type']] ?? $r['type'])],
            'created'    => ['label' => __('Created by'), 'sort' => 't.created_at', 'render' => fn ($r) => e($r['created_by_name'] ?? '—').'<span class="block text-xs text-steel">'.e(format_date($r['created_at'], 'd M Y H:i')).'</span>'],
            'updated'    => ['label' => __('Updated by'), 'sort' => 't.updated_at', 'render' => fn ($r) => e($r['updated_by_name'] ?? '—').'<span class="block text-xs text-steel">'.e(format_date($r['updated_at'], 'd M Y H:i')).'</span>'],
        ];
    }

    protected function searchable(): array { return ['t.account', 't.name', 't.department']; }

    /** Years and departments that have lines (newest year first). */
    private function years(): array
    {
        $ys = array_map('strval', array_column(DB::select('SELECT DISTINCT year FROM account_reports WHERE deleted_at IS NULL ORDER BY year DESC', [], 'accounting'), 'year'));

        return array_combine($ys, $ys) ?: [];
    }

    private function departments(): array
    {
        $ds = array_column(DB::select('SELECT DISTINCT department FROM account_reports WHERE deleted_at IS NULL ORDER BY department', [], 'accounting'), 'department');

        return array_combine($ds, $ds) ?: [];
    }

    protected function filters(): array
    {
        return [
            'year'       => ['label' => __('Year'), 'column' => 't.year', 'options' => $this->years()],
            'department' => ['label' => __('Department'), 'column' => 't.department', 'options' => $this->departments()],
            'kind'       => ['label' => __('Income / Expenses'), 'column' => 't.kind', 'options' => self::kinds()],
            'type'       => ['label' => __('Type'), 'column' => 't.type', 'options' => self::types()],
        ];
    }

    /** Year and department picked at the top of the page (only values that exist). */
    private function picked(): array
    {
        $y = (string) Request::query('year', '');
        $d = (string) Request::query('department', '');

        return [array_key_exists($y, $this->years()) ? $y : '', array_key_exists($d, $this->departments()) ? $d : ''];
    }

    /** Year and department filter right at the top of the page, the totals for them below it. */
    protected function topExtra(): string
    {
        [$y, $d] = $this->picked();
        $keep = '';
        foreach (['q', 'kind', 'type', 'sort', 'dir', 'per_page'] as $k) {
            if (($v = Request::query($k)) !== null && $v !== '') { $keep .= '<input type="hidden" name="'.$k.'" value="'.e((string) $v).'">'; }
        }

        return '<form method="GET" class="mt-4 flex flex-wrap items-end gap-3 rounded-xl bg-white p-3 shadow-sm ring-1 ring-graphite-900/8" aria-label="'.e(__('Year and department')).'">'.$keep
            .'<div class="w-36"><label class="label !mb-1 text-xs text-steel">'.e(__('Year')).'</label>'.select_field('year', ['' => __('All years')] + $this->years(), $y, ['placeholder' => __('All years'), 'submit' => true, 'aria' => __('Year'), 'search' => false]).'</div>'
            .'<div class="w-60"><label class="label !mb-1 text-xs text-steel">'.e(__('Department')).'</label>'.select_field('department', ['' => __('All departments')] + $this->departments(), $d, ['placeholder' => __('All departments'), 'submit' => true, 'aria' => __('Department')]).'</div>'
            .($y !== '' || $d !== '' ? '<a href="'.e(url($this->base)).'" class="btn-ghost">'.e(__('Clear')).'</a>' : '')
            .'</form>'.partial('crm/stats', ['cards' => $this->totals()]);
    }

    /** Income, expenses and the difference for the year / department picked. */
    private function totals(): array
    {
        [$y, $d] = $this->picked();
        $where  = ['deleted_at IS NULL'];
        $params = [];
        if ($y !== '') { $where[] = 'year = ?'; $params[] = (int) $y; }
        if ($d !== '') { $where[] = 'department = ?'; $params[] = $d; }
        $t = DB::first("SELECT COALESCE(SUM(amount) FILTER (WHERE kind = 'income'), 0) AS income, COALESCE(SUM(amount) FILTER (WHERE kind = 'expense'), 0) AS expense, COUNT(*) AS n
                          FROM account_reports WHERE ".implode(' AND ', $where), $params, 'accounting');
        $net  = (float) $t['income'] - (float) $t['expense'];
        $sub  = ($y !== '' ? $y : __('All years')).' · '.($d !== '' ? $d : __('All departments'));

        return [
            ['label' => __('Total income'), 'value' => number_format((float) $t['income'], 2), 'sub' => $sub, 'tone' => 'success', 'icon' => 'chart', 'href' => null],
            ['label' => __('Total expenses'), 'value' => number_format((float) $t['expense'], 2), 'sub' => $sub, 'tone' => 'danger', 'icon' => 'calc', 'href' => null],
            ['label' => $net >= 0 ? __('Net profit') : __('Net loss'), 'value' => number_format($net, 2), 'sub' => (float) $t['income'] > 0 ? __('Margin :p%', ['p' => number_format($net / (float) $t['income'] * 100, 1)]) : '', 'tone' => $net >= 0 ? 'info' : 'warn', 'icon' => 'bolt', 'href' => null],
            ['label' => __('Lines'), 'value' => number_format((int) $t['n']), 'sub' => __('Imported or added'), 'tone' => 'neutral', 'icon' => 'doc', 'href' => null],
        ];
    }

    /** The same account, department, year, month, income / expenses and type is one line. */
    protected function duplicate(array $data, ?array $existing): bool
    {
        return (bool) DB::scalar('SELECT 1 FROM account_reports WHERE deleted_at IS NULL AND account = ? AND department = ? AND year = ? AND month = ? AND kind = ? AND type = ? AND id <> ?',
            [trim((string) $data['account']), $this->appDepartment(trim((string) $data['department'])), (int) $data['year'], (int) $data['month'], $data['kind'], $data['type'], (int) ($existing['id'] ?? 0)], 'accounting');
    }

    protected function prepare(array $data, ?array $existing): array
    {
        foreach (['account', 'name', 'department'] as $k) { $data[$k] = trim((string) $data[$k]); }
        $data['department'] = $this->appDepartment($data['department']);
        $data['year']   = (int) $data['year'];
        $data['month']  = (int) $data['month'];
        $data['amount'] = round((float) $data['amount'], 2);
        if ($data['year'] < 2000 || $data['year'] > 2100) {
            throw new ValidationException(['year' => __('Enter a year between 2000 and 2100.')]);
        }
        if ($this->duplicate($data, $existing)) {
            throw new ValidationException(['account' => __('This line is already in the report (same account, department, year, month, income / expenses and type).')]);
        }
        $u = $this->user();
        if (! $existing) {
            $data['created_by'] = $u->id;
            $data['created_by_name'] = $u->name;
        }
        $data['updated_by'] = $u->id;
        $data['updated_by_name'] = $u->name;

        return $data;
    }

    /** "sales" → "Sales" when the app has that department, so the same department is never two names. */
    private function appDepartment(string $name): string
    {
        foreach (self::appDepartments() as $d) {
            if (mb_strtolower($d) === mb_strtolower($name)) { return $d; }
        }

        return $name;
    }

    /** Import accepts what accountants type: 1,250.00 / (500) / Jan / Expenses / "Cost of goods solds" … */
    protected function importDefaults(array $raw): array
    {
        $key = fn ($v) => trim((string) preg_replace('/[^a-z0-9ก-๙]+/u', '_', mb_strtolower(trim((string) $v))), '_');

        // column names as people write them: "Income/Expenses", "Department name", "Account no" …
        $columns = ['income_expenses' => 'kind', 'income_expense' => 'kind', 'department_name' => 'department', 'account_no' => 'account', 'account_number' => 'account',
                    'account_name' => 'name', 'created_by' => null, 'updated_by' => null];
        foreach ($raw as $col => $v) {
            $c = $key($col);
            $to = array_key_exists($c, $columns) ? $columns[$c] : $c;
            if ($to !== $col) {
                unset($raw[$col]);
                if ($to !== null && ! isset($raw[$to])) { $raw[$to] = $v; }
            }
        }

        if (isset($raw['amount'])) {
            $a = str_replace([',', ' ', '฿', 'THB', 'thb'], '', trim((string) $raw['amount']));
            if (preg_match('/^\((.*)\)$/', $a, $m)) { $a = '-'.$m[1]; }
            $raw['amount'] = $a;
        }
        if (isset($raw['kind'])) {
            $k = $key($raw['kind']);
            $raw['kind'] = match (true) {
                in_array($k, ['income', 'incomes', 'revenue', 'in', 'i', 'รายได้', 'รายรับ'], true) => 'income',
                in_array($k, ['expense', 'expenses', 'cost', 'out', 'e', 'ค่าใช้จ่าย', 'รายจ่าย'], true) => 'expense',
                default => $raw['kind'],
            };
        }
        if (isset($raw['type'])) {
            $k = $key($raw['type']);
            $alias = ['cost_of_goods_sold' => 'cogs', 'cost_of_goods_solds' => 'cogs', 'cost_of_good_sold' => 'cogs', 'operating_expense' => 'operating_expenses', 'opex' => 'operating_expenses',
                      'central_costs' => 'central_cost', 'domestic' => 'domestic_revenue', 'international' => 'international_revenue', 'deposits' => 'deposit'];
            $types = array_map(fn ($l) => $key($l), self::types());
            $raw['type'] = isset(self::TYPES[$k]) ? $k : ($alias[$k] ?? (array_search($k, $types, true) ?: $raw['type']));
        }
        if (isset($raw['month'])) {
            $m = trim((string) $raw['month']);
            if (ctype_digit($m)) {
                $raw['month'] = (string) (int) $m;
            } elseif (($t = strtotime('1 '.$m.' 2000')) !== false) {
                $raw['month'] = (string) (int) date('n', $t);
            } elseif (($i = array_search(mb_strtolower($m), array_map('mb_strtolower', self::months()), true)) !== false) {
                $raw['month'] = (string) $i;
            }
        }
        if (isset($raw['year'])) { $raw['year'] = trim((string) $raw['year']); }

        return $raw;
    }

    protected function intro(): string { return __('Import a CSV or Excel file; lines already in the report are skipped.'); }
}
