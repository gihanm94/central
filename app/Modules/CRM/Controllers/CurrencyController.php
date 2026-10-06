<?php
declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Core\Support\DB;
use App\Core\Support\ValidationException;
use App\Modules\CRM\Support\Ui;

/** Currencies and the rate that turns them into the base currency on the dashboard. */
class CurrencyController extends LookupController
{
    protected string $resource = 'crm_settings';
    protected string $table = 'currencies';
    protected string $type = 'currency';
    protected string $singular = 'currency';
    protected string $plural = 'currencies';
    protected string $base = '/crm/settings/currencies';
    protected string $icon = 'calc';
    protected string $orderBy = 't.is_base DESC, t.code';

    protected function fields(?array $row): array
    {
        $base = $row && filter_var($row['is_base'], FILTER_VALIDATE_BOOL);

        return [
            'code'         => ['label' => __('Code'), 'rules' => 'required|min:3|max:3', 'readonly' => (bool) $row, 'help' => __('Three letters, e.g. USD.'), 'example' => 'USD'],
            'name'         => ['label' => __('Name'), 'rules' => 'required|max:60', 'example' => 'US dollar'],
            'rate_to_base' => ['label' => __('Rate to base currency'), 'type' => 'number', 'rules' => 'required|numeric', 'default' => 1, 'readonly' => $base,
                               'help' => __('How many base-currency units one unit of this currency is worth. Used for dashboard totals.'), 'example' => '35'],
            'is_base'      => ['label' => __('Base currency'), 'type' => 'checkbox', 'readonly' => $base, 'help' => __('Dashboard totals are shown in this currency.')],
        ];
    }

    protected function uniqueColumn(): ?string { return 'code'; }

    protected function prepare(array $data, ?array $existing): array
    {
        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
            if (! preg_match('/^[A-Z]{3}$/', $data['code'])) {
                throw new ValidationException(['code' => __('Use three letters, e.g. USD.')]);
            }
        }
        if (isset($data['rate_to_base']) && (float) $data['rate_to_base'] <= 0) {
            throw new ValidationException(['rate_to_base' => __('The rate must be more than zero.')]);
        }
        if (! empty($data['is_base'])) {
            $data['rate_to_base'] = 1;
        }

        return parent::prepare($data, $existing);
    }

    protected function saved(int $id, array $data, ?array $existing): void
    {
        if (! empty($data['is_base'])) {
            DB::exec('UPDATE currencies SET is_base = FALSE WHERE id <> ?', [$id], 'crm');
        }
    }

    protected function beforeDelete(array $row): void
    {
        if (filter_var($row['is_base'], FILTER_VALIDATE_BOOL)) {
            back('error', __("The base currency can't be deleted. Make another currency the base first."));
        }
        if (DB::scalar('SELECT 1 FROM opportunities WHERE currency = ? LIMIT 1', [$row['code']], 'crm') || DB::scalar('SELECT 1 FROM products WHERE currency = ? LIMIT 1', [$row['code']], 'crm')) {
            back('error', __('This currency is used by opportunities or products, so it can\'t be deleted.'));
        }
    }

    protected function columns(): array
    {
        return [
            'code' => ['label' => __('Currency'), 'primary' => true, 'sort' => 't.code', 'render' => fn ($r) => '<span class="font-medium">'.e($r['code']).'</span> <span class="text-steel">'.e($r['name']).'</span>'.(filter_var($r['is_base'], FILTER_VALIDATE_BOOL) ? ' '.Ui::badge(__('Base'), 'success') : '')],
            'rate' => ['label' => __('Rate to base'), 'render' => fn ($r) => '<span class="tabular-nums">'.e(rtrim(rtrim(number_format((float) $r['rate_to_base'], 6), '0'), '.')).'</span>'],
        ];
    }

    protected function searchable(): array { return ['t.code', 't.name']; }
}
